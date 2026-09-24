<?php

namespace App\Services\Access;

use App\Models\Account;
use App\Support\JourneyNodeCatalog;

/**
 * Phase 5 Task 7 — "Can THIS account use THIS Journey node type?"
 *
 * The one place that question is answered. Composes what already
 * exists rather than starting a parallel authorization system:
 *
 *     Account::isAdministrativelyActive()   (account status)
 *     Account::hasActiveSubscription()      (subscription)
 *     AccessControlService::canTenant()     (account_entitlements)
 *     ProviderCapabilityService             (provider_capabilities)
 *     JourneyNodeCatalog                    (node -> requirement)
 *
 * TRUST BOUNDARY. Every input to every method here is either an
 * Account resolved from the authenticated request attributes
 * (TenantIsolationMiddleware -> ResolvesTenantAccount) or a node TYPE
 * STRING read from the graph. Nothing else. In particular this service
 * never reads, and callers must never pass it:
 *
 *   - a request-supplied account_id / tenant_id;
 *   - a request-supplied provider / engine_type;
 *   - a request-supplied plan name (this class contains no plan name at
 *     all — plans reach it only transitively, as entitlement rows);
 *   - the frontend registry's own capability/provider metadata.
 *
 * The provider is derived from the account's OWN current subscription,
 * which is the same source WhatsAppEngineFactory::make() dispatches on.
 * A caller who posts {"provider":"qr"} or {"engine_type":"qr"} changes
 * nothing here, because neither field is ever read.
 *
 * AUTHORIZATION, NOT EXECUTION. A node passing this check means the
 * tenant is entitled to SAVE it. It says nothing about whether a
 * runtime exists to execute it — most of the 27 have none yet, by
 * design (Task 6/7 are foundation tasks).
 */
class JourneyNodeAuthorizer
{
    public function __construct(
        private readonly AccessControlService $accessControl,
        private readonly ProviderCapabilityService $providerCapabilities,
    ) {
    }

    /**
     * Why this account may NOT use this node type, or null when it may.
     *
     * Returning the reason rather than a bare bool is deliberate: the
     * 403 body names the node and the missing capability, so a tenant
     * admin knows what to ask their Super Admin for instead of seeing an
     * opaque refusal.
     */
    public function denialFor(Account $account, string $nodeType): ?string
    {
        $requirements = JourneyNodeCatalog::requirementsFor($nodeType);

        // Unknown types are validateFlow()'s 422, not an authorization
        // failure; legacy executable types are grandfathered. Neither is
        // this service's business — see JourneyNodeCatalog's docblock.
        if ($requirements === null) {
            return null;
        }

        if ($requirements['capabilities'] === [] && $this->providerAllowed($account, $requirements['providers'])) {
            // Pure control flow on a compatible provider: nothing to check.
            return null;
        }

        if (! $account->isAdministrativelyActive()) {
            return 'This account is suspended.';
        }

        if (! $account->hasActiveSubscription()) {
            return 'This account has no active subscription.';
        }

        return $this->capabilityDenial($account, $nodeType, $requirements);
    }

    /**
     * Phase 7 Task 8 — the RUN-TIME node check (WhatsAppJourneyEngine):
     * provider + capability only. Account status and subscription state are
     * deliberately NOT checked here: at run time they are the send gate's
     * business (JourneySendGate), which tells a temporarily exhausted quota
     * (retryable) apart from a missing entitlement (permanent). Checking
     * "has an active subscription" here made an exhausted quota look like a
     * missing entitlement and failed the node permanently.
     */
    public function runtimeDenialFor(Account $account, string $nodeType): ?string
    {
        $requirements = JourneyNodeCatalog::requirementsFor($nodeType);

        return $requirements === null ? null : $this->capabilityDenial($account, $nodeType, $requirements);
    }

    /**
     * @param  array{capabilities: array<int, string>, providers: array<int, string>}  $requirements
     */
    private function capabilityDenial(Account $account, string $nodeType, array $requirements): ?string
    {
        $provider = $this->providerFor($account);

        if (! $this->providerAllowed($account, $requirements['providers'])) {
            return sprintf(
                "The '%s' node is not available on your WhatsApp provider (%s).",
                $nodeType,
                $provider
            );
        }

        foreach ($requirements['capabilities'] as $capability) {
            if (! $this->accessControl->canTenant($account, $capability)) {
                return sprintf(
                    "The '%s' node requires the '%s' capability, which this account does not hold.",
                    $nodeType,
                    $capability
                );
            }

            // The tenant holds the entitlement, but their provider may
            // still be incapable of it — a Meta-only capability granted
            // to an account that has since moved to QR, say. supportsOrNull()
            // rather than supports(): only an explicit `false` row blocks,
            // so an unstated pairing does not invent a restriction.
            if ($this->providerCapabilities->supportsOrNull($provider, $capability) === false) {
                return sprintf(
                    "The '%s' capability is not supported on your WhatsApp provider (%s).",
                    $capability,
                    $provider
                );
            }
        }

        return null;
    }

    /**
     * Every distinct node type in this graph that the account may not
     * use, as [type => reason]. One pass, deduplicated by type, so a
     * journey with twelve `api` nodes reports one denial.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, string>
     */
    public function denialsForNodes(Account $account, array $nodes): array
    {
        $denials = [];

        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;

            if (! is_string($type) || isset($denials[$type])) {
                continue;
            }

            $denial = $this->denialFor($account, $type);

            if ($denial !== null) {
                $denials[$type] = $denial;
            }
        }

        return $denials;
    }

    /**
     * The node types this account may currently use, for UX that wants
     * to show the palette's entitlement state. Not an authorization
     * decision on its own — denialFor() is still what gates a write.
     *
     * @return array<int, string>
     */
    public function allowedNodeTypes(Account $account): array
    {
        return array_values(array_filter(
            JourneyNodeCatalog::types(),
            fn (string $type) => $this->denialFor($account, $type) === null
        ));
    }

    /**
     * The account's provider, from ITS OWN current subscription — the
     * same field WhatsAppEngineFactory::make() dispatches on. An account
     * with no subscription (or one with no engine configured) resolves
     * to the platform provider, which is the honest answer: it has no
     * WhatsApp engine, so WhatsApp-bound nodes are unavailable to it
     * while platform-only nodes are not.
     */
    private function providerFor(Account $account): string
    {
        $engineType = $account->currentSubscription?->engine_type;

        return ($engineType === null || $engineType === '')
            ? JourneyNodeCatalog::PLATFORM_PROVIDER
            : $engineType;
    }

    /**
     * A node listing PLATFORM_PROVIDER needs no WhatsApp engine, so it is
     * available whatever engine the account runs — see that constant's
     * docblock for why this is not the same as "the account's provider
     * is 'none'". Otherwise the account's own engine must be listed.
     *
     * @param array<int, string> $allowed
     */
    private function providerAllowed(Account $account, array $allowed): bool
    {
        if (in_array(JourneyNodeCatalog::PLATFORM_PROVIDER, $allowed, true)) {
            return true;
        }

        return in_array($this->providerFor($account), $allowed, true);
    }
}
