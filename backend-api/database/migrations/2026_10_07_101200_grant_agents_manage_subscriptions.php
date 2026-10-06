<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * An agent collects payments for its own clients (manual, and online on their behalf), so
 * the agent role needs manage-subscriptions. Granted to the existing role here; new installs
 * get it from RolePermissionSeeder. Idempotent. The agent still reaches only its own clients:
 * the controllers scope every payment to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->where('name', 'agent')->first();
        if ($role === null) {
            return;
        }

        $permission = Permission::firstOrCreate(['name' => 'manage-subscriptions']);
        $role->givePermissionTo($permission);
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'agent')->first();
        $permission = Permission::query()->where('name', 'manage-subscriptions')->first();
        if ($role !== null && $permission !== null) {
            $role->revokePermissionTo($permission);
        }
    }
};
