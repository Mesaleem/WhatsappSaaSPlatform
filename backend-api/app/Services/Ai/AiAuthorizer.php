<?php

namespace App\Services\Ai;

use App\Models\Account;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Access\EntitlementAuditLogger;
use App\Services\Credits\CreditEntitlementService;
use Illuminate\Http\Request;

/**
 * Phase 8 AI foundation — the reusable AI authorization layer. It adds NO
 * new authorization model: every check is an existing predicate, in the
 * order the route chain already applies them
 * (auth → tenant.isolation → subscription → module → permission → capability):
 *
 *   1. authenticated, active user            (manual paths only)
 *   2. target account — same rules as TenantIsolationMiddleware:
 *        tenant user  → own account only (another id = cross-tenant)
 *        agent        → own account or an own sub-client
 *        Super Admin  → a SELECTED client is required (no global AI);
 *   3. account administratively active       (Account::isAdministrativelyActive)
 *   4. subscription current (not expired)    — the rule the AI side already
 *      uses (CreditEntitlementService): an exhausted MESSAGE quota does not
 *      switch AI off;
 *   5. the calling feature's module          (Account::hasModuleEnabled)
 *   6. the calling feature's permission      ($user->can — Spatie + Gate::before)
 *   7. the `ai` capability on the TARGET     (AccessControlService::canTenant)
 *
 * The Super Admin is NOT exempted from 3–5 and 7: like CRM
 * (EnsureCrmTargetAccount), an AI operation is authorized against the
 * target account's own entitlements. (Their permission bypass is the
 * existing Gate::before rule, unchanged.)
 *
 * Automated paths (journey runtime, jobs — no user) use forAccount(): the
 * same account checks 3–5 and 7, nothing skipped because of where the call
 * came from. The module/permission are the CALLING feature's (e.g.
 * 'chatbot' + 'manage-chatbot' for the Journey builder); AI access never
 * rides on a feature's own gate alone — `ai` is always checked too.
 *
 * Every decision is recorded through the existing EntitlementAuditLogger
 * (P5-8 trail, action "ai.authorize").
 */
class AiAuthorizer
{
    public const CAPABILITY = CreditEntitlementService::CAPABILITY; // 'ai' — one slug for AI everywhere

    public function __construct(
        private readonly AccessControlService $access,
        private readonly EntitlementAuditLogger $audit,
    ) {
    }

    /**
     * An HTTP request that already passed auth:sanctum + tenant.isolation:
     * the target is the account TenantIsolationMiddleware resolved (never a
     * body value).
     *
     * @throws AiException
     */
    public function forRequest(Request $request, string $module, ?string $permission, string $source = 'manual'): AiAuthorization
    {
        $target = $request->attributes->get('account_id');

        return $this->forUser($request->user(), $target === null ? null : (int) $target, $module, $permission, $source);
    }

    /** @throws AiException */
    public function forUser(?User $actor, ?int $targetAccountId, string $module, ?string $permission, string $source = 'manual'): AiAuthorization
    {
        if (! $actor || $actor->is_active === false) {
            throw AiException::unauthenticated();
        }

        $account = $this->resolveTarget($actor, $targetAccountId, $module, $permission, $source);

        $this->assertAccount($account, $module, $source, $actor, $permission, function () use ($actor, $permission, $account, $module, $source) {
            if ($permission !== null && ! $actor->can($permission)) {
                $this->deny($account, 'missing_permission', AiException::permissionDenied(), $module, $permission, $source, $actor);
            }
        });

        return new AiAuthorization($account, (int) $actor->id, $module, $permission, $source);
    }

    /**
     * No user (scheduler, queue, journey runtime). The account is the one
     * the automated path already resolved from its own data.
     *
     * @throws AiException
     */
    public function forAccount(Account $account, string $module, string $source = 'automation'): AiAuthorization
    {
        $account = Account::query()->findOrFail($account->id); // fresh state, never a stale instance

        $this->assertAccount($account, $module, $source, null, null, null);

        return new AiAuthorization($account, null, $module, null, $source);
    }

    private function resolveTarget(User $actor, ?int $targetAccountId, string $module, ?string $permission, string $source): Account
    {
        if ($actor->isSuperAdmin()) {
            if ($targetAccountId === null) {
                $this->deny(null, 'unauthorized_target_account', AiException::targetAccountRequired(), $module, $permission, $source, $actor);
            }

            return Account::query()->find($targetAccountId)
                ?? $this->deny(null, 'unauthorized_target_account', AiException::targetAccountForbidden(), $module, $permission, $source, $actor, $targetAccountId);
        }

        $own = $actor->account_id === null ? null : (int) $actor->account_id;

        if ($own === null) {
            $this->deny(null, 'unauthorized_target_account', AiException::targetAccountForbidden(), $module, $permission, $source, $actor, $targetAccountId);
        }

        $targetAccountId ??= $own;
        $target = Account::query()->find($targetAccountId);

        $allowed = $target !== null && (
            (int) $target->id === $own
            // Agent: an own sub-client, exactly as TenantIsolationMiddleware allows.
            || ($actor->account?->account_type === 'agent' && (int) $target->agent_id === $own)
        );

        if (! $allowed) {
            $this->deny($actor->account, $target === null ? 'unauthorized_target_account' : 'cross_tenant', AiException::targetAccountForbidden(), $module, $permission, $source, $actor, $targetAccountId);
        }

        return $target;
    }

    /** Account-level checks, in route-chain order; $permissionCheck runs between module and capability. */
    private function assertAccount(Account $account, string $module, string $source, ?User $actor, ?string $permission, ?callable $permissionCheck): void
    {
        if (! $account->isAdministrativelyActive()) {
            $this->deny($account, 'account_suspended', AiException::accountSuspended(), $module, $permission, $source, $actor);
        }

        $subscription = $account->currentSubscription;
        if ($subscription === null || ($subscription->expires_at !== null && ! $subscription->expires_at->isFuture())) {
            $this->deny($account, 'no_active_subscription', AiException::subscriptionInactive(), $module, $permission, $source, $actor);
        }

        if (! $account->hasModuleEnabled($module)) {
            $this->deny($account, 'module_disabled', AiException::moduleDisabled(), $module, $permission, $source, $actor);
        }

        if ($permissionCheck !== null) {
            $permissionCheck();
        }

        if (! $this->access->canTenant($account, self::CAPABILITY)) {
            $this->deny($account, 'capability_not_entitled', AiException::capabilityUnavailable(), $module, $permission, $source, $actor);
        }

        $this->audit->record($account, true, $this->auditFields('entitled', $module, $permission, $source, $actor, $account->id));
    }

    /** @return never */
    private function deny(?Account $account, string $category, AiException $e, string $module, ?string $permission, string $source, ?User $actor, ?int $targetAccountId = null): never
    {
        $this->audit->record($account, false, $this->auditFields($category, $module, $permission, $source, $actor, $targetAccountId ?? $account?->id) + [
            'error_code' => $e->errorCode,
            'http_status' => $e->httpStatus,
        ]);

        throw $e;
    }

    private function auditFields(string $category, string $module, ?string $permission, string $source, ?User $actor, ?int $targetAccountId): array
    {
        return [
            'action' => 'ai.authorize',
            'resource_type' => 'ai',
            'category' => $category,
            'capability' => self::CAPABILITY,
            'module' => $module,
            'permission' => $permission,
            'source' => $source,
            'actor_account_id' => $actor?->account_id === null ? null : (int) $actor->account_id,
            'target_account_id' => $targetAccountId,
        ];
    }
}
