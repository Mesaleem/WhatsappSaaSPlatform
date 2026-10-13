<?php

use App\Services\Crm\PlatformCrmAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner rule (2026-10-13): Super Admin has no restriction in any module. With no
 * client selected, the Super Admin works on its own "Platform (Super Admin)" account
 * (ResolvesTenantAccount::superAdminOwnAccount()). This makes sure that account exists
 * and has its never-expiring 'unlimited' subscription (PlatformCrmAccount::ensure()),
 * so its sends, journeys and groups pass every plan and quota check.
 *
 * Same guard as 2026_09_23_130000: only on an installed platform (a Super Admin user
 * exists). Idempotent; data only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles') || ! Schema::hasTable('subscriptions')) {
            return;
        }

        $hasSuperAdmin = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'super_admin')
            ->exists();

        if (! $hasSuperAdmin) {
            return;
        }

        app(PlatformCrmAccount::class)->ensure();
    }

    public function down(): void
    {
        // The subscription is left in place: removing it would block the Super Admin's own sends.
    }
};
