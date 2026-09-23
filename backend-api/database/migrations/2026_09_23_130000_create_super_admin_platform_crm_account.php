<?php

use App\Services\Crm\PlatformCrmAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — the Super Admin's own CRM account (data migration, no schema change).
 *
 * Creates the single account_type = 'super_admin' account
 * ("Platform (Super Admin)") and grants it the `crm` capability, so a Super
 * Admin with no client selected can keep their own CRM leads. See
 * App\Services\Crm\PlatformCrmAccount and EnsureCrmTargetAccount.
 *
 * Runs ONLY where a Super Admin user already exists (an installed
 * platform). On a fresh install (roles are seeded after migrations) it is
 * a no-op; run `php artisan crm:platform-account` after seeding instead.
 * Idempotent. users.account_id is NOT changed — Super Admin keeps
 * "no tenant of its own" everywhere outside the CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
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

    /**
     * Removes the platform account only while it holds no CRM data (its
     * leads/contacts would otherwise be deleted by the cascading foreign
     * keys). With data present it is left in place.
     */
    public function down(): void
    {
        $account = app(PlatformCrmAccount::class)->find();

        if (! $account) {
            return;
        }

        $hasData = DB::table('crm_leads')->where('account_id', $account->id)->exists()
            || DB::table('contacts')->where('account_id', $account->id)->exists();

        if ($hasData) {
            return;
        }

        DB::table('account_entitlements')->where('account_id', $account->id)->delete();
        $account->delete();
    }
};
