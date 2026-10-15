<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plan restructure (owner, 2026-10-15).
     *
     * 1. Plan limits — new per-plan limits, captured on the invoice at order
     *    time (like the message quota) and copied to the subscription at
     *    payment, where they are enforced. NULL = unlimited.
     *      max_users                 team users (all users of the account)
     *      max_meta_templates        Meta message templates
     *      max_flows                 journeys / WhatsApp Flows
     *      max_broadcasts_per_month  bulk sends started per plan period
     *      marketing_message_limit   Meta MARKETING-category messages per period
     *    subscriptions.used_marketing_messages counts the last one.
     *
     * 2. Custom plans — `plans.account_id` makes a plan private to one client
     *    (built by the Super Admin); `plans.is_public` hides a plan from the
     *    website and the Billing page without retiring it.
     *
     * 3. New plan line-up (inserted only when the slug is missing; prices
     *    have no GST): Starter ₹599 (QR, unchanged + 2 users), CRM ₹899 (CRM
     *    only, no WhatsApp), Meta Growth ₹4,999, Meta Pro ₹7,999. Growth and
     *    Business are retired: hidden from new purchases, while clients
     *    already on them keep running to the end of their term.
     *
     * 4. provider_capabilities: `none` + `crm` is supported (CRM needs no
     *    WhatsApp engine) — without it the CRM plan's grant would be skipped.
     *
     * 5. Menu modules follow the plan: `accounts.modules_from_plan` = true
     *    makes Account::effectiveModules() derive a client's modules from the
     *    capabilities it holds (PlanModules) instead of the per-client
     *    allowed_modules checklist (Route Master). Switched on at every plan
     *    payment, and here for every client that has already paid for a plan.
     *    Trial / legacy clients without a paid plan keep their checklist.
     */
    private const LIMIT_COLUMNS = ['max_users', 'max_meta_templates', 'max_flows', 'max_broadcasts_per_month', 'marketing_message_limit'];

    /** slug => [plan columns, capability => usage_limit] */
    private const NEW_PLANS = [
        'crm' => [
            [
                'label' => 'CRM', 'price' => 899.00, 'duration_days' => 30,
                'description' => 'CRM only: leads, contacts, pipeline, deals, companies and tasks. No WhatsApp sending.',
                'engine_type' => 'none', 'billing_model' => 'unlimited', 'rate_per_message' => null,
                'total_allocated_messages' => null, 'included_credits' => 0, 'is_active' => true,
                'max_users' => 3, 'max_meta_templates' => 0, 'max_flows' => 0, 'max_broadcasts_per_month' => 0, 'marketing_message_limit' => 0,
            ],
            ['crm' => null],
        ],
        'meta_growth' => [
            [
                'label' => 'Meta Growth', 'price' => 4999.00, 'duration_days' => 30,
                'description' => '5,000 messages a month on the official Meta WhatsApp API (up to 2,500 marketing), journey builder, WhatsApp Flows and Meta templates.',
                'engine_type' => 'meta', 'billing_model' => 'flat_quota', 'rate_per_message' => null,
                'total_allocated_messages' => 5000, 'included_credits' => 0, 'is_active' => true,
                'max_users' => 3, 'max_meta_templates' => 10, 'max_flows' => 3, 'max_broadcasts_per_month' => 0, 'marketing_message_limit' => 2500,
            ],
            ['whatsapp_send' => 5000, 'journey_automation' => null, 'external_api' => null],
        ],
        'meta_pro' => [
            [
                'label' => 'Meta Pro', 'price' => 7999.00, 'duration_days' => 30,
                'description' => '10,000 messages a month on the official Meta WhatsApp API (up to 4,000 marketing), CRM, Meta Ads, journey builder, WhatsApp Flows, templates and broadcasts.',
                'engine_type' => 'meta', 'billing_model' => 'flat_quota', 'rate_per_message' => null,
                'total_allocated_messages' => 10000, 'included_credits' => 0, 'is_active' => true,
                'max_users' => 5, 'max_meta_templates' => 30, 'max_flows' => 10, 'max_broadcasts_per_month' => 20, 'marketing_message_limit' => 4000,
            ],
            ['whatsapp_send' => 10000, 'journey_automation' => null, 'crm' => null, 'ads' => null, 'social' => null, 'external_api' => null],
        ],
    ];

    private const RETIRED = ['growth', 'business'];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            foreach (self::LIMIT_COLUMNS as $column) {
                $table->unsignedInteger($column)->nullable();
            }
            $table->boolean('is_public')->default(true);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            foreach (self::LIMIT_COLUMNS as $column) {
                $table->unsignedInteger('plan_'.$column)->nullable();
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            foreach (self::LIMIT_COLUMNS as $column) {
                $table->unsignedInteger($column)->nullable();
            }
            $table->unsignedInteger('used_marketing_messages')->default(0);
            // Start of the 30-day slice used_marketing_messages counts (a new slice starts it at 0).
            $table->timestamp('marketing_counted_from')->nullable();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('modules_from_plan')->default(false);
            // The Route Master checklist a client had before its menu followed the plan (kept for rollback).
            $table->json('legacy_allowed_modules')->nullable();
        });

        $this->applyLineUp();
    }

    /**
     * The data half of up(): provider row, existing clients' modules, the new
     * plans, Starter's user limit, retiring Growth/Business. Idempotent; public
     * so a test can run it on an installation that already has the columns.
     */
    public function applyLineUp(): void
    {
        $this->allowCrmWithoutEngine();

        // Existing clients that paid for a plan: their menu follows that plan from now on. Their old
        // checklist is cleared (it would otherwise keep narrowing the plan, e.g. block CRM after an
        // upgrade) and kept in legacy_allowed_modules.
        $planSlugs = DB::table('plans')->pluck('slug')->all();
        if ($planSlugs !== []) {
            $paying = DB::table('accounts')
                ->where('account_type', 'client')
                ->where('modules_from_plan', false)
                ->whereIn('id', DB::table('invoices')->where('status', 'paid')->whereIn('plan_key', $planSlugs)->select('account_id'));
            (clone $paying)->update(['legacy_allowed_modules' => DB::raw('allowed_modules')]);
            (clone $paying)->update(['modules_from_plan' => true, 'allowed_modules' => null]);
        }

        // A fresh database (no accounts yet): Phase1FoundationSeeder creates the plans there.
        if (! DB::table('accounts')->exists() || ! DB::table('capabilities')->exists()) {
            return;
        }

        $now = now();
        $capabilityIds = DB::table('capabilities')->pluck('id', 'slug');

        foreach (self::NEW_PLANS as $slug => [$columns, $bundle]) {
            if (DB::table('plans')->where('slug', $slug)->exists()) {
                continue;
            }
            $planId = DB::table('plans')->insertGetId(['slug' => $slug] + $columns + ['created_at' => $now, 'updated_at' => $now]);
            foreach ($bundle as $capability => $usageLimit) {
                if (isset($capabilityIds[$capability])) {
                    DB::table('plan_entitlements')->insert([
                        'plan_id' => $planId, 'capability_id' => $capabilityIds[$capability], 'usage_limit' => $usageLimit,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        // Starter keeps its terms and gains a 2-user limit (applies from each client's next purchase).
        DB::table('plans')->where('slug', 'starter')->whereNull('max_users')->update(['max_users' => 2, 'updated_at' => $now]);

        DB::table('plans')->whereIn('slug', self::RETIRED)->update(['is_active' => false, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('slug', self::RETIRED)->update(['is_active' => true, 'updated_at' => now()]);

        // New plans are removed only while nothing was bought on them.
        foreach (array_keys(self::NEW_PLANS) as $slug) {
            if (! DB::table('invoices')->where('plan_key', $slug)->exists()) {
                DB::table('plans')->where('slug', $slug)->delete();
            }
        }

        DB::table('accounts')->where('modules_from_plan', true)->whereNotNull('legacy_allowed_modules')
            ->update(['allowed_modules' => DB::raw('legacy_allowed_modules')]);

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['modules_from_plan', 'legacy_allowed_modules']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([...self::LIMIT_COLUMNS, 'used_marketing_messages', 'marketing_counted_from']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(array_map(fn ($c) => 'plan_'.$c, self::LIMIT_COLUMNS));
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn([...self::LIMIT_COLUMNS, 'is_public']);
        });
    }

    private function allowCrmWithoutEngine(): void
    {
        $providerId = DB::table('providers')->where('slug', 'none')->value('id');
        $capabilityId = DB::table('capabilities')->where('slug', 'crm')->value('id');

        if ($providerId === null || $capabilityId === null) {
            return; // fresh database: the seeder writes the row
        }

        DB::table('provider_capabilities')->updateOrInsert(
            ['provider_id' => $providerId, 'capability_id' => $capabilityId],
            ['supported' => true, 'reason' => 'CRM is engine-agnostic: the CRM plan sells it without any WhatsApp engine.', 'updated_at' => now(), 'created_at' => now()],
        );
    }
};
