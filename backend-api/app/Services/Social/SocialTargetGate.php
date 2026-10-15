<?php

namespace App\Services\Social;

use App\Models\Account;
use App\Services\Access\AccessControlService;
use App\Services\Access\DenialScope;

/**
 * Phase 9 Task 3 — the target-account checks every social write shares:
 * the account the action is FOR (the resolved tenant target, never a
 * client-supplied id) must be administratively active, hold an active
 * subscription, have the social_accounts module enabled and be entitled to
 * the `social` capability.
 *
 * Route middleware (module.guard / capability.guard) lets a Super Admin
 * through and subscription.guard checks the CALLER's own account, so on
 * their own they do not stop a Super Admin acting for a lapsed or
 * unentitled client. This gate always evaluates the TARGET, for Super
 * Admin and tenant alike — there is no role bypass here. It is used when
 * connecting a social account (SocialAuthController), when a post is
 * created/scheduled/retried, and again by the worker right before a
 * scheduled post is sent (the target may have lapsed since it was queued).
 *
 * Extracted from SocialAuthController::assertTargetMayConnect() with the
 * same order, messages and error codes.
 */
class SocialTargetGate
{
    public const MODULE = 'social_accounts';

    public const CAPABILITY = 'social';

    public function __construct(private readonly AccessControlService $access)
    {
    }

    /**
     * @return array{code: string, message: string}|null null when the target may use social features
     */
    public function denial(Account $account, string $action = 'use social accounts', ?bool $superAdmin = null): ?array
    {
        return $this->denialFor($account, self::MODULE, self::CAPABILITY, $action, [
            'MODULE_DISABLED' => 'Social accounts are switched off for this account.',
            'CAPABILITY_NOT_ENTITLED' => 'Your current plan does not include Social Media. Please upgrade your subscription to unlock it.',
        ], $superAdmin);
    }

    /**
     * Phase 10 Task 1 — the same four TARGET checks for another module /
     * capability pair (e.g. Ads: meta_ads + ads), so every Social/Ads
     * surface shares one implementation, order and set of error codes.
     *
     * Phase 12 Task 4 — `$scope` (optional) lets several checks inside ONE decision share the fresh
     * target load and the module/capability lookups. Without it every call reloads, exactly as before.
     *
     * @param  array<string, string>  $messages  optional overrides for MODULE_DISABLED / CAPABILITY_NOT_ENTITLED
     * @return array{code: string, message: string}|null
     */
    public function denialFor(Account $account, string $module, string $capability, string $action, array $messages = [], ?bool $superAdmin = null, bool $read = false, ?DenialScope $scope = null): ?array
    {
        $target = $scope ? $scope->target($account) : Account::query()->with('currentSubscription')->find($account->id);

        // Owner decision (2026-09-30): a Super Admin is not held to a client's
        // PLAN or SUBSCRIPTION — both checks are skipped for them (suspension
        // and module switches still apply). The Super Admin's
        // own Platform account has no plan or subscription at all. $superAdmin
        // null = the authenticated user (request context); background work
        // passes it explicitly (e.g. who created a scheduled post).
        $superAdmin ??= (bool) auth()->user()?->isSuperAdmin();
        $platform = $target?->isPlatformAccount() ?? false;
        // Plan restructure (2026-10-15): why the module is unavailable — '' = open, 'not_in_plan',
        // 'disabled' (a Super Admin is held only to switch-offs). The scope memoizes booleans, so the
        // reason is kept as two flags: open, and (when closed) missing only from the plan.
        $moduleDenial = '';
        if ($target !== null) {
            $sa = (int) $superAdmin;
            $open = $scope
                ? $scope->remember("mod:{$target->id}:{$module}:{$sa}", fn () => $this->moduleDenial($target, $module, $superAdmin) === '')
                : $this->moduleDenial($target, $module, $superAdmin) === '';
            if (! $open) {
                $notInPlan = $scope
                    ? $scope->remember("modplan:{$target->id}:{$module}:{$sa}", fn () => $this->moduleDenial($target, $module, $superAdmin) === 'not_in_plan')
                    : $this->moduleDenial($target, $module, $superAdmin) === 'not_in_plan';
                $moduleDenial = $notInPlan ? 'not_in_plan' : 'disabled';
            }
        }

        return match (true) {
            ! $target || ! $target->isAdministrativelyActive() => ['code' => 'CLIENT_ACCOUNT_SUSPENDED', 'message' => "This account is suspended. It cannot {$action}."],
            ! $read && ! $platform && ! $superAdmin && ! $target->hasActiveSubscription() => ['code' => 'SUBSCRIPTION_EXPIRED', 'message' => "This account's subscription is not active. Renew it to {$action}."],
            // A module the client's plan lacks is the plan refusal (below), not MODULE_DISABLED.
            $moduleDenial === 'disabled' => ['code' => 'MODULE_DISABLED', 'message' => $messages['MODULE_DISABLED'] ?? 'This feature is switched off for this account.'],
            ! $platform && ! $superAdmin && $moduleDenial === 'not_in_plan' => ['code' => 'CAPABILITY_NOT_ENTITLED', 'message' => $messages['CAPABILITY_NOT_ENTITLED'] ?? 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.'],
            ! $platform && ! $superAdmin && ! ($scope ? $scope->remember("cap:{$target->id}:{$capability}", fn () => $this->access->canTenant($target, $capability)) : $this->access->canTenant($target, $capability)) => ['code' => 'CAPABILITY_NOT_ENTITLED', 'message' => $messages['CAPABILITY_NOT_ENTITLED'] ?? 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.'],
            default => null,
        };
    }

    /** '' = open, 'not_in_plan' or 'disabled' (a Super Admin: only a switch-off counts). */
    private function moduleDenial(Account $target, string $module, bool $superAdmin): string
    {
        if ($superAdmin) {
            return $target->hasModuleSwitchedOn($module) ? '' : 'disabled';
        }

        return (string) $target->moduleDenial($module);
    }
}
