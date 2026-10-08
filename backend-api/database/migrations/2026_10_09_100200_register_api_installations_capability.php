<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 Task 2 — makes `php artisan migrate` alone leave a deployed
 * database with the `api_installations` capability and its `none`
 * provider-support row, mirroring 2026_10_02_100100's
 * register_industry_modules_foundation idiom: data-only (no schema
 * change — `plan_entitlements.usage_limit` already exists), idempotent
 * via updateOrInsert, and safe for a database Phase1FoundationSeeder
 * will never be re-run against. Phase1FoundationSeeder carries the same
 * two rows for a fresh install.
 *
 * NOTHING IS GRANTED to any plan or account by this migration — no
 * plan_entitlements or account_entitlements row is touched. Bundling
 * this capability into an existing plan (with a concrete, non-negative
 * usage_limit — required by Task 2's requirement 7 whenever that plan
 * also sells external_api) is a deliberate administrative action,
 * through PlanManagementController::update(), not something a
 * migration should silently decide on an administrator's behalf.
 *
 * See InstallationAllowanceResolver for how this capability's
 * usage_limit is read; see Phase1FoundationSeeder's CAPABILITIES /
 * PROVIDER_CAPABILITIES for the fresh-install equivalent of this same
 * data. Phase1FoundationSeeder's PLAN_CAPABILITIES deliberately does
 * NOT bundle this capability into any seeded plan — no product-owner-
 * confirmed per-tier usage_limit exists yet (see that constant's own
 * docblock) — so this migration grants nothing to any plan either.
 */
return new class extends Migration
{
    private const SLUG = 'api_installations';

    private const LABEL = 'API Installations';

    public function up(): void
    {
        $now = now();

        DB::table('capabilities')->updateOrInsert(
            ['slug' => self::SLUG],
            ['label' => self::LABEL, 'category' => 'platform', 'updated_at' => $now],
        );
        DB::table('capabilities')->where('slug', self::SLUG)->whereNull('created_at')->update(['created_at' => $now]);

        $capabilityId = DB::table('capabilities')->where('slug', self::SLUG)->value('id');
        $noneId = DB::table('providers')->where('slug', 'none')->value('id');

        if ($noneId && $capabilityId) {
            DB::table('provider_capabilities')->updateOrInsert(
                ['provider_id' => $noneId, 'capability_id' => $capabilityId],
                [
                    'supported' => true,
                    'reason' => 'Installation-count limiting is a platform capability and needs no WhatsApp engine.',
                    'updated_at' => $now,
                ],
            );
            DB::table('provider_capabilities')->where('provider_id', $noneId)->where('capability_id', $capabilityId)->whereNull('created_at')->update(['created_at' => $now]);
        }

        Cache::forget('provider_capability:none:'.self::SLUG);
    }

    public function down(): void
    {
        $capabilityId = DB::table('capabilities')->where('slug', self::SLUG)->value('id');

        if ($capabilityId) {
            // Entitlements / plan bundles reference this row; remove them first.
            DB::table('account_entitlements')->where('capability_id', $capabilityId)->delete();
            DB::table('plan_entitlements')->where('capability_id', $capabilityId)->delete();
            DB::table('provider_capabilities')->where('capability_id', $capabilityId)->delete();
            DB::table('capabilities')->where('id', $capabilityId)->delete();
        }

        Cache::forget('provider_capability:none:'.self::SLUG);
    }
};
