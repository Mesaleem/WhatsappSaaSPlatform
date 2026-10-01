<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 11 Task 2 — the two Education permissions, so `php artisan migrate` alone leaves a deployed
 * database usable (RolePermissionSeeder carries the same rows for fresh installs). Idempotent.
 * Granted to super_admin and admin only — the same roles that hold `view-industry-modules`. The
 * Education capability itself is NOT granted to any plan or account here.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['view-education', 'manage-education'];

    private const GRANT_TO = ['super_admin', 'admin'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::firstOrCreate(['name' => $name]);
            foreach (self::GRANT_TO as $roleName) {
                $role = Role::where('name', $roleName)->first();
                if (! $role) {
                    Log::info("register_education_permissions: role '{$roleName}' does not exist on this deployment — skipped.");

                    continue;
                }
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
