<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The capabilities table was missing rows the Phase1FoundationSeeder defines (for example
 * whatsapp_groups, which blocks enabling Native WhatsApp Groups). This adds only the rows that
 * are absent, with the seeder's own labels and categories. It grants nothing to any account:
 * plan bundles and entitlements are unchanged. Idempotent; the down step does nothing, so a
 * rollback never removes a capability that a plan may now reference.
 */
return new class extends Migration
{
    private const CAPABILITIES = [
        'whatsapp_send' => ['WhatsApp Messaging', 'whatsapp'],
        'whatsapp_groups' => ['WhatsApp Groups', 'whatsapp'],
        'crm' => ['CRM', 'growth'],
        'journey_automation' => ['Journey Automation', 'growth'],
        'ads' => ['Ads', 'growth'],
        'social' => ['Social Media', 'growth'],
        'ai' => ['AI', 'growth'],
        'commerce' => ['Commerce Catalog', 'growth'],
        'payments' => ['Payments', 'growth'],
        'external_api' => ['External API Calls', 'platform'],
        'custom_code' => ['Custom Code', 'platform'],
        'email' => ['Email', 'platform'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::CAPABILITIES as $slug => [$label, $category]) {
            if (DB::table('capabilities')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('capabilities')->insert([
                'slug' => $slug,
                'label' => $label,
                'category' => $category,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: see the docblock.
    }
};
