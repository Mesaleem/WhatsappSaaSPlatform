<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Account;
use Illuminate\Http\Request;

/**
 * Shared tenant-account resolution for controllers behind the
 * tenant.isolation middleware. Reads the account_id ALREADY resolved by
 * TenantIsolationMiddleware (the 'account_id' request attribute) instead
 * of re-deriving it from $request->user()->account_id directly, so every
 * controller honors a Super Admin's ?account_id= selection the same way.
 */
trait ResolvesTenantAccount
{
    /**
     * Returns the effective tenant Account, or null when the caller is a
     * Super Admin who has not selected one (no ?account_id= on this
     * request). Use this in read/list actions that can meaningfully
     * return aggregated cross-tenant data when null.
     */
    protected function resolveAccount(Request $request): ?Account
    {
        $accountId = $request->attributes->get('account_id');

        if (! $accountId) {
            return null;
        }

        // findCached() is nullable/non-throwing (unlike findOrFail()), so the
        // 404-on-missing-account behavior that findOrFail() gave implicitly
        // is preserved explicitly here.
        $account = Account::findCached((int) $accountId);

        abort_if(! $account, 404);

        return $account;
    }

    /**
     * Same as resolveAccount(), but aborts 422 when no tenant is
     * resolvable. Use this in actions that inherently need exactly one
     * tenant (creating/mutating records scoped to a single account) — a
     * Super Admin must pass ?account_id= to use these.
     */
    protected function requireAccount(
        Request $request,
        string $message = 'Select a client/tenant account first (pass ?account_id=).'
    ): Account {
        $account = $this->resolveAccount($request);

        abort_if(! $account, 422, $message);

        return $account;
    }
}
