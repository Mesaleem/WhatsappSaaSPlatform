<?php

namespace App\Services\Industry;

use App\Models\Account;
use App\Models\User;
use App\Services\Access\DenialScope;
use App\Services\Social\SocialTargetGate;

/**
 * Phase 11 Task 1 — the one place that decides whether a TARGET account (and, when given, a user) may
 * use an industry or one of its modules. Reuses SocialTargetGate::denialFor() for the target checks every
 * Social/Ads surface already shares (suspended → subscription for writes → module → capability, with the
 * owner's Super Admin plan/subscription bypass), then adds the industry-specific ones. There is no
 * role bypass beyond that, and nothing here looks at where a record came from.
 *
 * Order: unknown industry / module → target checks → industry assigned → module available → permission.
 * `$read` = true keeps reads working on an expired subscription (writes need an active one), exactly
 * like the Ads reads.
 */
class IndustryAuthorizer
{
    public function __construct(
        private readonly IndustryRegistry $registry,
        private readonly IndustryResolver $resolver,
        private readonly SocialTargetGate $gate,
    ) {
    }

    /**
     * @return array{code: string, message: string, status: int}|null null = allowed
     */
    public function denial(Account $account, string $industry, ?string $module = null, bool $read = true, ?User $user = null, ?bool $superAdmin = null): ?array
    {
        // Phase 12 Task 4 — one scope per decision: the industry check and the required-capability check
        // share one fresh target load, discarded when this call returns.
        return $this->denialWithin(new DenialScope(), $account, $industry, $module, $read, $user, $superAdmin);
    }

    /**
     * Same decision as denial(), sharing a caller-owned scope across several READ-ONLY calls in one pass
     * (IndustryModuleResolver::contextFor). The public denial() signature stays as it was.
     *
     * @return array{code: string, message: string, status: int}|null
     */
    public function denialWithin(DenialScope $scope, Account $account, string $industry, ?string $module = null, bool $read = true, ?User $user = null, ?bool $superAdmin = null): ?array
    {

        if (! $this->registry->exists($industry)) {
            return ['code' => 'INDUSTRY_UNKNOWN', 'message' => 'This industry does not exist.', 'status' => 404];
        }

        $definition = $module !== null ? $this->registry->module($industry, $module) : null;
        if ($module !== null && $definition === null) {
            return ['code' => 'INDUSTRY_MODULE_UNKNOWN', 'message' => 'This industry module does not exist.', 'status' => 404];
        }

        $label = (string) $this->registry->find($industry)['label'];
        $target = $this->gate->denialFor(
            $account,
            $this->registry->accountModule(),
            (string) $this->registry->capability($industry),
            "use the {$label} modules",
            [
                'MODULE_DISABLED' => 'Industry modules are switched off for this account.',
                'CAPABILITY_NOT_ENTITLED' => "Your current plan does not include the {$label} modules. Please upgrade your subscription to unlock them.",
            ],
            $superAdmin,
            $read,
            $scope,
        );
        if ($target !== null) {
            return $target + ['status' => 403];
        }

        // A module built on a shared core (e.g. Billing & Collections) also needs that core's own capability —
        // entitled separately from the industry's, never implied by it.
        $required = $definition['requires_capability'] ?? null;
        if ($required !== null) {
            $shared = $this->gate->denialFor(
                $account,
                $this->registry->accountModule(),
                (string) $required,
                "use {$label} ".strtolower((string) ($definition['label'] ?? 'modules')),
                ['CAPABILITY_NOT_ENTITLED' => 'Your current plan does not include Billing & Collections. Please upgrade your subscription to unlock it.'],
                $superAdmin,
                $read,
                $scope,
            );
            if ($shared !== null) {
                return $shared + ['status' => 403];
            }
        }

        if (! $scope->remember("has:{$account->id}:{$industry}", fn () => $this->resolver->has($account, $industry))) {
            return ['code' => 'INDUSTRY_NOT_ASSIGNED', 'message' => "The {$label} industry is not set up for this account.", 'status' => 403];
        }

        if ($definition !== null && ! (bool) ($definition['available'] ?? false)) {
            return ['code' => 'INDUSTRY_MODULE_UNAVAILABLE', 'message' => 'This industry module is not available yet.', 'status' => 403];
        }

        // Reads need `permission` (default: the generic view permission); writes need `write_permission`,
        // falling back to `permission` — so a module with no separate write permission behaves as before.
        $permission = (string) ($read
            ? ($definition['permission'] ?? $this->registry->viewPermission())
            : ($definition['write_permission'] ?? $definition['permission'] ?? $this->registry->viewPermission()));
        if ($user !== null && ! $this->userCan($user, $permission)) {
            return ['code' => 'PERMISSION_DENIED', 'message' => 'You do not have permission to use this industry module.', 'status' => 403];
        }

        return null;
    }

    public function allows(Account $account, string $industry, ?string $module = null, bool $read = true, ?User $user = null, ?bool $superAdmin = null): bool
    {
        return $this->denial($account, $industry, $module, $read, $user, $superAdmin) === null;
    }

    /** Fail closed: a permission the registry names but the database lacks denies, it never 500s. */
    private function userCan(User $user, string $permission): bool
    {
        try {
            return $user->can($permission);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return false;
        }
    }
}
