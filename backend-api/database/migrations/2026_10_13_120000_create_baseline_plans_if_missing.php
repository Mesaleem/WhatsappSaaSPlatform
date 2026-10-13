<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings the server's plan catalogue in line with the agreed plans (2026-10-13):
 * any of these plans that does not exist yet is created, with its feature bundle
 * (plan_entitlements). A plan that already exists is left exactly as it is, so a
 * price or bundle the Super Admin changed on purpose is never overwritten -- the
 * 2026_10_12_12xxxx migrations already move the original Starter/Growth rows to
 * unlimited / Rs 599 incl. GST.
 *
 * Runs only on a running installation (accounts exist); on a fresh database (and in
 * tests) it does nothing and the seeder creates the plans.
 */
return new class extends Migration
{
    /** slug => [plan columns, [capability slug => usage_limit]] */
    private const PLANS = [
        'starter' => [
            [
                'label' => 'Starter', 'price' => 507.63, 'duration_days' => 30,
                'description' => 'Unlimited messages over the QR (Baileys) engine.',
                'engine_type' => 'qr', 'billing_model' => 'unlimited', 'rate_per_message' => null,
                'total_allocated_messages' => null, 'included_credits' => 0, 'is_active' => true,
            ],
            ['whatsapp_send' => null, 'whatsapp_groups' => null, 'social' => null, 'external_api' => null],
        ],
        'growth' => [
            [
                'label' => 'Growth', 'price' => 1999.00, 'duration_days' => 30,
                'description' => 'Unlimited messages over the QR (Baileys) engine.',
                'engine_type' => 'qr', 'billing_model' => 'unlimited', 'rate_per_message' => null,
                'total_allocated_messages' => null, 'included_credits' => 0, 'is_active' => true,
            ],
            [
                'whatsapp_send' => null, 'whatsapp_groups' => null, 'crm' => null, 'journey_automation' => null,
                'social' => null, 'ai' => null, 'payments' => null, 'external_api' => null, 'email' => null,
            ],
        ],
        'business' => [
            [
                'label' => 'Business', 'price' => 7999.00, 'duration_days' => 30,
                'description' => '10,000 messages/month over the official Meta Cloud API.',
                'engine_type' => 'meta', 'billing_model' => 'flat_quota', 'rate_per_message' => null,
                'total_allocated_messages' => 10000, 'included_credits' => 0, 'is_active' => true,
            ],
            [
                'whatsapp_send' => 10000, 'crm' => null, 'journey_automation' => null, 'ads' => null, 'social' => null,
                'ai' => null, 'commerce' => null, 'payments' => null, 'external_api' => null, 'custom_code' => null, 'email' => null,
            ],
        ],
    ];

    public function up(): void
    {
        // Only on a running installation (it has accounts). A fresh install -- and every
        // test database -- has none yet: there the seeder creates the first catalogue.
        if (! DB::table('accounts')->exists() || ! DB::table('capabilities')->exists()) {
            return;
        }

        $capabilityIds = DB::table('capabilities')->pluck('id', 'slug');
        $now = now();

        foreach (self::PLANS as $slug => [$columns, $bundle]) {
            if (DB::table('plans')->where('slug', $slug)->exists()) {
                continue;
            }

            $planId = DB::table('plans')->insertGetId(['slug' => $slug] + $columns + ['created_at' => $now, 'updated_at' => $now]);

            foreach ($bundle as $capability => $usageLimit) {
                if (isset($capabilityIds[$capability])) {
                    DB::table('plan_entitlements')->insert([
                        'plan_id' => $planId,
                        'capability_id' => $capabilityIds[$capability],
                        'usage_limit' => $usageLimit,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Not reversed: plans may already be on invoices and subscriptions.
    }
};
