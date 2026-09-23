<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Phase 6 — CRM Hardening, Issue 1. Separates the CRM's own
     * permission from `manage-social-leads`.
     *
     * WHY A MIGRATION AS WELL AS THE SEEDER: RolePermissionSeeder is the
     * canonical catalog and has been updated too, but a seeder only runs
     * when someone remembers to run it. This permission is load-bearing
     * — without the row, /api/crm/* returns 403 for every role except
     * super_admin (who bypasses via AuthServiceProvider's Gate::before())
     * the instant the route switches off manage-social-leads. Shipping
     * it as a migration means `php artisan migrate` alone leaves a
     * deployed database in a working state. Data migrations already have
     * precedent here (see the group_code backfill).
     *
     * WHICH ROLES, and why not "all of them": today, CRM access is
     * granted by `manage-social-leads`, which RolePermissionSeeder's
     * DEFAULT_ROLES gives to exactly `admin`, `social_marketer` and
     * `super_admin` (the last via its full PERMISSIONS list). Granting
     * `manage-crm` to precisely those three means this change alters the
     * PERMISSION MODEL without altering anyone's EFFECTIVE ACCESS in
     * either direction — nobody gains CRM who did not have it, nobody
     * loses it. `user` and `agent` are untouched, matching what they can
     * reach today (an Agent's primary user is additionally assigned
     * `admin` at account creation, so they keep CRM through that).
     *
     * DYNAMIC ROLES are deliberately not touched. Super Admin can create
     * roles at runtime via RoleController, and this migration must not
     * silently widen one it knows nothing about. Those roles gain CRM
     * the same way they gain any permission: a Super Admin ticks it, and
     * `manage-crm` is now in the catalog so the matrix offers it.
     *
     * IDEMPOTENT: firstOrCreate for the permission, and the role
     * attachment is skipped where it already exists, so re-running this
     * (or running the seeder afterwards) changes nothing.
     */
    private const GRANT_TO = ['super_admin', 'admin', 'social_marketer'];

    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'manage-crm']);

        foreach (self::GRANT_TO as $roleName) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                // A deployment that never seeded this role. Nothing to
                // attach to; RolePermissionSeeder will wire it up when
                // the role is eventually created.
                Log::info("add_manage_crm_permission: role '{$roleName}' does not exist on this deployment — skipped.");

                continue;
            }

            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Removing the permission row detaches it from every role through
     * Spatie's own cascading pivot, which is the exact reverse of up().
     * No other permission is affected, and `manage-social-leads` — which
     * this change deliberately left on /api/social/leads — is untouched.
     */
    public function down(): void
    {
        Permission::where('name', 'manage-crm')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
