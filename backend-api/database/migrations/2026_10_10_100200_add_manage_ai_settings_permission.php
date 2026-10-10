<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Phase 8 Task 12 (continued) — gates the new AiGatewayController
     * (platform-level AI provider/model settings, admin/ai/* routes),
     * same tier as manage-billing-settings / manage-social-settings.
     * Super-Admin-only: not added to any other role's array in
     * RolePermissionSeeder::DEFAULT_ROLES, matching how those two
     * siblings are scoped.
     *
     * WHY A MIGRATION AS WELL AS THE SEEDER: see
     * add_manage_crm_permission's docblock — RolePermissionSeeder is the
     * canonical catalog but only runs when someone remembers to; this
     * permission is load-bearing (without the row, /api/admin/ai/* 403s
     * for every role, super_admin included — Gate::before() bypass in
     * AuthServiceProvider only covers authorization checks that reach
     * it, and the `permission:` middleware still needs the row to
     * exist). Shipping it as a migration means `php artisan migrate`
     * alone leaves a deployed database in a working state.
     *
     * IDEMPOTENT: firstOrCreate for the permission, givePermissionTo
     * skipped where already granted.
     */
    private const GRANT_TO = ['super_admin'];

    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'manage-ai-settings']);

        foreach (self::GRANT_TO as $roleName) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                Log::info("add_manage_ai_settings_permission: role '{$roleName}' does not exist on this deployment — skipped.");

                continue;
            }

            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage-ai-settings')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
