<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;

/**
 * The Super Admin's own CRM account ("Platform (Super Admin)").
 *
 * Super Admin users carry no tenant of their own (users.account_id is
 * NULL — TenantIsolationMiddleware's documented invariant, relied on by
 * billing, Meta config and audit attribution), so the Super Admin's own
 * CRM leads need an account that is NOT linked through users.account_id.
 * It is the single `accounts` row with account_type = 'super_admin' — a
 * value the account_type enum has always allowed and that nothing else in
 * the codebase reads. The client-management list filters on
 * account_type = 'client', so it never appears as a client there.
 *
 * Used only by EnsureCrmTargetAccount (the CRM route group): a Super Admin
 * with no client selected acts on this account in the CRM, and nowhere
 * else. Created by the 2026_09_23_130000 data migration or
 * `php artisan crm:platform-account` — never lazily on a request.
 */
class PlatformCrmAccount
{
    public const COMPANY_NAME = 'Platform (Super Admin)';

    public const ACCOUNT_TYPE = 'super_admin';

    public function find(): ?Account
    {
        return Account::query()->where('account_type', self::ACCOUNT_TYPE)->orderBy('id')->first();
    }

    /**
     * Idempotent: returns the existing row, or creates it. Grants the `crm`
     * capability as a manual grant when that capability exists and the
     * account holds no entitlement row for it yet — an entitlement a Super
     * Admin has since REVOKED is left revoked. allowed_modules stays NULL
     * (every module, the default for a direct account), so lead_crm is on.
     *
     * @return array{account: Account, created: bool, crm_granted: bool}
     */
    public function ensure(): array
    {
        $account = $this->find();
        $created = false;

        if (! $account) {
            $account = Account::create([
                'company_name' => self::COMPANY_NAME,
                'account_type' => self::ACCOUNT_TYPE,
                'status' => 'active',
                'allowed_modules' => null,
            ]);
            $created = true;
        }

        $crmGranted = false;
        $capability = Capability::where('slug', 'crm')->first();

        if ($capability && ! AccountEntitlement::where('account_id', $account->id)->where('capability_id', $capability->id)->exists()) {
            AccountEntitlement::create([
                'account_id' => $account->id,
                'capability_id' => $capability->id,
                'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
                'granted_by_account_id' => null,
            ]);
            $crmGranted = true;
        }

        return ['account' => $account->fresh(), 'created' => $created, 'crm_granted' => $crmGranted];
    }
}
