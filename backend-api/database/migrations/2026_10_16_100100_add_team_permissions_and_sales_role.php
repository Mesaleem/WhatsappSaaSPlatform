<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * QA fixes (2026-10-14) — team permissions.
 *
 *   manage-whatsapp-setup  connect / disconnect WhatsApp and change numbers (was open
 *                          to every team member, e.g. a plain 'user' could log the
 *                          client's WhatsApp out). super_admin + admin.
 *   view-all-crm           see every lead / deal / task of the account. Without it a
 *                          CRM user sees only what is assigned to them (own leads).
 *                          super_admin + admin + social_marketer (unchanged access).
 *   'sales' role           a salesperson: CRM, quotes / invoices, payments — own leads
 *                          only. Assignable by a Client Admin from the Team page.
 */
return new class extends Migration
{
    private const GRANTS = [
        'manage-whatsapp-setup' => ['super_admin', 'admin', 'agent'],
        'view-all-crm' => ['super_admin', 'admin', 'social_marketer'],
    ];

    private const SALES_PERMISSIONS = [
        'manage-crm',
        'manage-crm-documents',
        'manage-crm-payments',
        'view-analytics',
        'view-audit-logs',
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::firstOrCreate(['name' => $name]);
            foreach ($roles as $roleName) {
                $role = Role::where('name', $roleName)->first();
                if ($role && ! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        $permissions = collect(self::SALES_PERMISSIONS)
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name]));
        $sales = Role::firstOrCreate(['name' => 'sales']);
        $sales->syncPermissions($permissions->all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'sales')->delete();
        Permission::whereIn('name', array_keys(self::GRANTS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
