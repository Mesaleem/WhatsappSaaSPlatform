<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 11 Task 1 — makes `php artisan migrate` alone leave a deployed database ready for the
 * industry foundation (the seeders carry the same rows for fresh installs): the five industry
 * capabilities, their provider rows, the `view-industry-modules` permission and the
 * `industry_modules` Route Master entry. Idempotent. Nothing is granted to any plan or account.
 */
return new class extends Migration
{
    private const CAPABILITIES = [
        'industry_education' => 'Industry: Education',
        'industry_healthcare' => 'Industry: Healthcare',
        'industry_ecommerce' => 'Industry: Ecommerce',
        'industry_real_estate' => 'Industry: Real Estate',
        'industry_financial_services' => 'Industry: Money Transfer & Financial Services',
    ];

    private const PERMISSION = 'view-industry-modules';

    private const GRANT_TO = ['super_admin', 'admin'];

    public function up(): void
    {
        $now = now();
        $noneId = DB::table('providers')->where('slug', 'none')->value('id');

        foreach (self::CAPABILITIES as $slug => $label) {
            DB::table('capabilities')->updateOrInsert(['slug' => $slug], ['label' => $label, 'category' => 'industry', 'updated_at' => $now]);
            DB::table('capabilities')->where('slug', $slug)->whereNull('created_at')->update(['created_at' => $now]);

            $capabilityId = DB::table('capabilities')->where('slug', $slug)->value('id');
            if ($noneId && $capabilityId) {
                DB::table('provider_capabilities')->updateOrInsert(
                    ['provider_id' => $noneId, 'capability_id' => $capabilityId],
                    ['supported' => true, 'reason' => 'Industry modules are a platform capability: no WhatsApp engine is required to be entitled to one.', 'updated_at' => $now],
                );
                DB::table('provider_capabilities')->where('provider_id', $noneId)->where('capability_id', $capabilityId)->whereNull('created_at')->update(['created_at' => $now]);
            }
        }

        $permission = Permission::firstOrCreate(['name' => self::PERMISSION]);
        foreach (self::GRANT_TO as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                Log::info("register_industry_modules_foundation: role '{$roleName}' does not exist on this deployment — skipped.");

                continue;
            }
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Route Master: only on a deployment that already uses it. SystemRoute::activePermissionKeys()
        // treats an EMPTY table as "no restriction" — inserting the first row would switch every
        // other module off. (A fresh install gets the row from RouteMasterSeeder.)
        if (DB::table('system_routes')->exists()) {
            DB::table('route_categories')->updateOrInsert(
                ['category_code' => 'industry'],
                ['category_name' => 'Industry Modules', 'icon_name' => 'Briefcase', 'sort_order' => 5, 'is_active' => true, 'updated_at' => $now],
            );
            $categoryId = DB::table('route_categories')->where('category_code', 'industry')->value('id');
            DB::table('route_categories')->where('id', $categoryId)->whereNull('created_at')->update(['created_at' => $now]);

            if (! DB::table('system_routes')->where('permission_key', 'industry_modules')->exists()) {
                DB::table('system_routes')->insert([
                    'category_id' => $categoryId, 'route_title' => 'Industry Modules', 'route_path' => null, 'permission_key' => 'industry_modules',
                    'is_agent_assignable' => true, 'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $this->forgetCaches();
    }

    public function down(): void
    {
        $ids = DB::table('capabilities')->whereIn('slug', array_keys(self::CAPABILITIES))->pluck('id');
        // Entitlements / plan bundles / provider rows reference these rows; remove them first.
        DB::table('account_entitlements')->whereIn('capability_id', $ids)->delete();
        DB::table('plan_entitlements')->whereIn('capability_id', $ids)->delete();
        DB::table('provider_capabilities')->whereIn('capability_id', $ids)->delete();
        DB::table('capabilities')->whereIn('id', $ids)->delete();

        Permission::where('name', self::PERMISSION)->delete();

        DB::table('system_routes')->where('permission_key', 'industry_modules')->delete();
        DB::table('route_categories')->where('category_code', 'industry')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->forgetCaches();
    }

    private function forgetCaches(): void
    {
        Cache::forget(\App\Models\SystemRoute::ACTIVE_KEYS_CACHE_KEY);
        foreach (array_keys(self::CAPABILITIES) as $slug) {
            Cache::forget("provider_capability:none:{$slug}");
        }
    }
};
