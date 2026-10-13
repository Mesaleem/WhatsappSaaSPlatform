<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM Sales Phases 2–3 — two new permissions, on top of manage-crm:
 *   manage-crm-documents  products, proposals, quotations, orders, invoices, sending them
 *   manage-crm-payments   recording / reversing payments, gateway settings, reminders
 * Granted to super_admin and admin (the tenant owner), the roles that hold billing today.
 * Not to social_marketer: financial records are a separate trust level. The CRM
 * capability (`crm`) and the plans are unchanged.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['manage-crm-documents', 'manage-crm-payments'];

    private const GRANT_TO = ['super_admin', 'admin'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::firstOrCreate(['name' => $name]);

            foreach (self::GRANT_TO as $roleName) {
                $role = Role::where('name', $roleName)->first();
                if (! $role) {
                    Log::info("add_crm_documents_and_payments_permissions: role '{$roleName}' does not exist here — skipped.");

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
        Permission::whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
