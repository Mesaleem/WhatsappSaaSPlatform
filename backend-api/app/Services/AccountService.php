<?php

namespace App\Services;

use App\Models\Account;

/**
 * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2) — Hierarchical
 * Module Delegation Engine. Small, focused on purpose: the ONE rule that
 * must hold whenever a Sub-Client's `allowed_modules` is being written —
 * kept out of AccountController so both store() (creation) and
 * updatePermissions() (post-creation edits) call the exact same logic
 * rather than two hand-maintained copies of the same intersection.
 */
class AccountService
{
    /**
     * Resolves the `allowed_modules` value that should actually be
     * PERSISTED for a Sub-Client, given who is asking.
     *
     * Super Admin ($callerAgentAccount === null — see
     * AccountController::callerAgentScopeId(), which returns null for
     * Super Admin) is the platform's ultimate authority: whatever is
     * requested is trusted and returned as-is, including null ("every
     * module enabled" — Account::hasModuleEnabled()'s existing
     * zero-regression convention, unchanged by this phase).
     *
     * An Agent can never grant a Sub-Client a module slug the Agent
     * itself doesn't currently hold: $requestedModules is intersected
     * against the Agent's OWN effective allowed_modules (the Agent's own
     * null also reads as "every module, no cap", same convention, one
     * level up). The result for an Agent caller is always a concrete
     * array, never null — this is deliberate: a stored null on the
     * Sub-Client would mean "always match whatever the Agent has NEXT",
     * silently re-opening the door the moment Super Admin grants the
     * Agent something new, rather than recording what was actually
     * granted at the time of this request. (Account::effectiveModules()
     * re-intersects against the Agent's CURRENT modules on every read
     * regardless, so a module the Agent later LOSES still cascades down
     * immediately either way — only a newly GAINED Agent module doesn't
     * auto-propagate to a Sub-Client without this endpoint being called
     * again, which matches "explicitly delegate," not "auto-inherit
     * every future grant.")
     *
     * @param  Account|null  $callerAgentAccount  The caller's OWN account,
     *                                             only when that account
     *                                             is Agent-type; null for
     *                                             Super Admin (or any
     *                                             other caller this
     *                                             controller doesn't yet
     *                                             let reach these actions
     *                                             — see
     *                                             TenantIsolationMiddleware's
     *                                             docblock on that gap).
     * @param  list<string>|null  $requestedModules
     * @return list<string>|null
     */
    public function resolveDelegatedModules(?Account $callerAgentAccount, ?array $requestedModules): ?array
    {
        if ($callerAgentAccount === null) {
            return $requestedModules;
        }

        $agentEffectiveModules = $callerAgentAccount->allowed_modules ?? Account::MODULES;
        $requested = $requestedModules ?? Account::MODULES;

        return array_values(array_intersect($requested, $agentEffectiveModules));
    }
}
