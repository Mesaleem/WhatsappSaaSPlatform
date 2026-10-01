<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 Task 4 — registers the generic `billing_collections` capability (and its provider row) on a
 * deployed database, so `php artisan migrate` alone leaves it assignable in Plan Management / as an account
 * entitlement (the seeder carries the same rows for fresh installs). Idempotent. Nothing is granted to any
 * plan or account: Education fees need BOTH `industry_education` and this capability.
 */
return new class extends Migration
{
    private const SLUG = 'billing_collections';

    public function up(): void
    {
        $now = now();
        DB::table('capabilities')->updateOrInsert(['slug' => self::SLUG], ['label' => 'Billing & Collections', 'category' => 'platform', 'updated_at' => $now]);
        DB::table('capabilities')->where('slug', self::SLUG)->whereNull('created_at')->update(['created_at' => $now]);

        $noneId = DB::table('providers')->where('slug', 'none')->value('id');
        $capabilityId = DB::table('capabilities')->where('slug', self::SLUG)->value('id');
        if ($noneId && $capabilityId) {
            DB::table('provider_capabilities')->updateOrInsert(
                ['provider_id' => $noneId, 'capability_id' => $capabilityId],
                ['supported' => true, 'reason' => 'Billing & Collections is a platform capability: no WhatsApp engine is required to be entitled to it.', 'updated_at' => $now],
            );
            DB::table('provider_capabilities')->where('provider_id', $noneId)->where('capability_id', $capabilityId)->whereNull('created_at')->update(['created_at' => $now]);
        }
        Cache::forget('provider_capability:none:'.self::SLUG);
    }

    public function down(): void
    {
        $id = DB::table('capabilities')->where('slug', self::SLUG)->value('id');
        if ($id) {
            DB::table('account_entitlements')->where('capability_id', $id)->delete();
            DB::table('plan_entitlements')->where('capability_id', $id)->delete();
            DB::table('provider_capabilities')->where('capability_id', $id)->delete();
            DB::table('capabilities')->where('id', $id)->delete();
        }
        Cache::forget('provider_capability:none:'.self::SLUG);
    }
};
