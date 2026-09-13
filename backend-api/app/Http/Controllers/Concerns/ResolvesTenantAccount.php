<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
     *
     * [Onboarding-friction fix, disclosed, SCOPED — read before touching]:
     * when no account is resolvable AND app()->environment('local'), this
     * falls back to the first Account row instead of aborting, so a fresh
     * clone/DB-import on a new local dev machine doesn't hard-block every
     * Super-Admin-triggered mutating action until someone remembers to
     * pick a client from the header dropdown. This fallback is:
     *   - LOCAL ONLY. staging/production (any environment other than
     *     'local') are completely unchanged — still a hard 422. This is
     *     NOT a general "auto-resolve tenant" feature; it is a local dev
     *     convenience only, matching the same bypass pattern already used
     *     in this codebase for MetaWebhookController's signature
     *     verification (local-only, off in every other environment).
     *   - Confined to requireAccount() — resolveAccount() (used by
     *     read/list actions that legitimately treat "no account" as
     *     "Super Admin global/cross-tenant view") is UNTOUCHED, so this
     *     never masks or changes the "Global View" case anywhere.
     *   - Always logged (Log::warning, with the account it silently
     *     picked and the request path) — it never fails silently, so a
     *     developer debugging "why did my local request land on the
     *     wrong tenant" has a trail.
     * DELIBERATELY NOT implemented: silently resolving to "the
     * authenticated user's own account" for a Super Admin, or falling
     * back to "the first Account" in ANY non-local environment. A Super
     * Admin has no account of their own by design (see
     * TenantIsolationMiddleware's docblock) — they administer every
     * tenant, so there is no single "their" account to infer, and
     * guessing one in production/staging would let a Super Admin's
     * mutating request (e.g. starting a WhatsApp session, connecting a
     * Social Account) silently land on an ARBITRARY client's data. That
     * is a tenant-isolation regression, not a convenience, so it was not
     * applied outside local — see the accompanying report for the
     * reasoning and the safer alternative (seed data + a documented
     * "pick a client first" onboarding step).
     */
    protected function requireAccount(
        Request $request,
        string $message = 'Select a client/tenant account first (pass ?account_id=).'
    ): Account {
        $account = $this->resolveAccount($request);

        if (! $account && app()->environment('local')) {
            $fallback = Account::query()->orderBy('id')->first();

            if ($fallback) {
                Log::warning('requireAccount(): no ?account_id= was supplied and none could be resolved from the request; falling back to the first Account row because APP_ENV=local. This fallback is disabled outside local — see ResolvesTenantAccount::requireAccount().', [
                    'fallback_account_id' => $fallback->id,
                    'uri' => $request->path(),
                ]);

                $account = $fallback;
            }
        }

        abort_if(! $account, 422, $message);

        return $account;
    }
}
