<?php

namespace App\Services\Access;

use App\Models\Account;
use App\Models\Capability;
use App\Models\Provider;
use App\Models\User;

/**
 * Phase 1 Foundation — the single centralized access-control service.
 * Composes Permission (Spatie), Entitlement/Capability (account_entitlements),
 * and Provider Capability into one place, rather than the three
 * independent ad hoc implementations this codebase currently has for
 * "which Agent does this caller belong to" (AccountController,
 * QuotaRequestController, TenantIsolationMiddleware's unused
 * agent_scope_id). See the Phase 1 plan, Step 7.
 *
 * Deliberately a plain service class, not a rules engine/DSL — this
 * platform's entire RBAC surface (28 permissions x 5 default roles x 19
 * modules) does not justify more than this.
 *
 * Does NOT duplicate AuthServiceProvider's Gate::before() Super-Admin
 * bypass — canAction() delegates straight to $user->can(), which
 * already goes through Gate::before(). can()/canTenant() check
 * capabilities that Gate never sees at all (a new dimension, not a
 * Spatie permission), so they check isSuperAdmin() directly, the same
 * way TeamController/EnsureModuleEnabledMiddleware already do elsewhere
 * in this codebase.
 */
class AccessControlService
{
    public function __construct(private readonly ProviderCapabilityService $providerCapabilities)
    {
    }

    /**
     * Does this USER have this capability — composing their account's
     * entitlement with the ordinary Spatie permission system where one
     * applies. Super Admin always true (same bypass every other check
     * in this codebase gives them).
     */
    public function can(User $user, string $capability): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->account) {
            return false;
        }

        return $this->canTenant($user->account, $capability);
    }

    /**
     * Does this TENANT ACCOUNT hold this capability at all, independent
     * of which user is asking. No $user context needed — used by
     * write-time entitlement checks for a single capability.
     */
    public function canTenant(Account $account, string $capability): bool
    {
        return $account->entitlements()
            // Phase 5 Task 9 — a deliberately revoked entitlement still
            // has a row (so a plan backfill cannot silently reverse the
            // administrator's decision), but it grants nothing.
            ->active()
            ->whereHas('capability', fn ($q) => $q->where('slug', $capability))
            ->exists();
    }

    /**
     * { capability_slug => bool } for every seeded Capability, for this
     * user — the capability-map endpoint's single-query equivalent of
     * calling can() once per capability. Same boolean semantics as
     * can(): Super Admin holds every capability; a user with no account
     * holds none; otherwise a capability is granted iff the account
     * holds an account_entitlements row for it. One LEFT JOIN query
     * (or, for Super Admin / no-account, one plain Capability query),
     * never one query per capability.
     *
     * @return array<string, bool>
     */
    public function capabilityMap(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return Capability::pluck('slug')
                ->mapWithKeys(fn (string $slug) => [$slug => true])
                ->all();
        }

        if (! $user->account) {
            return Capability::pluck('slug')
                ->mapWithKeys(fn (string $slug) => [$slug => false])
                ->all();
        }

        return $this->capabilityMapForAccount($user->account);
    }

    /**
     * The tenant-account half of capabilityMap() above — a single LEFT
     * JOIN against account_entitlements rather than one exists() query
     * per capability. Never hard-codes a capability slug: driven
     * entirely by whatever rows exist in `capabilities` and
     * `account_entitlements`, exactly like canTenant() above.
     *
     * @return array<string, bool>
     */
    public function capabilityMapForAccount(Account $account): array
    {
        return Capability::query()
            ->select('capabilities.slug')
            ->selectRaw('CASE WHEN account_entitlements.id IS NOT NULL THEN 1 ELSE 0 END AS granted')
            ->leftJoin('account_entitlements', function ($join) use ($account) {
                $join->on('account_entitlements.capability_id', '=', 'capabilities.id')
                    ->where('account_entitlements.account_id', $account->id)
                    // Phase 5 Task 9 — same rule as canTenant() above, in
                    // the join rather than a second query, so the map and
                    // the single-capability check can never disagree.
                    ->whereNull('account_entitlements.revoked_at');
            })
            ->get()
            ->mapWithKeys(fn ($row) => [$row->slug => (bool) $row->granted])
            ->all();
    }

    /**
     * Is this capability even possible on this provider at all — a
     * platform-wide fact, independent of any tenant. Thin wrapper over
     * ProviderCapabilityService so callers reasoning about providers
     * use one facade for both tenant and provider dimensions.
     */
    public function canProvider(Provider|string $provider, string $capability): bool
    {
        return $this->providerCapabilities->supports($provider, $capability);
    }

    /**
     * Does this user hold this Spatie PERMISSION (ordinary RBAC action
     * check) — a named alias so call sites that only ever needed a
     * permission check have an obvious, minimal-surface method, without
     * forcing them to reason about Capabilities they don't need.
     * Delegates to $user->can(), so AuthServiceProvider's existing
     * Gate::before() Super-Admin bypass applies exactly as it already
     * does everywhere else in this codebase.
     */
    public function canAction(User $user, string $action): bool
    {
        return $user->can($action);
    }
}
