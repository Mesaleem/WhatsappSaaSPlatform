<?php

namespace App\Services\Access;

use App\Models\Account;

/**
 * Phase 7 Task 1.6 — may this account's journeys EXECUTE right now?
 *
 * The runtime twin of the Journey API gate. It applies the same two
 * account-level predicates the /api/whatsapp/flows middleware applies to
 * the target account, with the same code:
 *   - module.guard:chatbot                  → Account::hasModuleEnabled('chatbot')
 *   - capability.guard:journey_automation   → AccessControlService::canTenant()
 *     (active, non-revoked entitlement — plan grant, manual grant or agent
 *     delegation alike)
 *
 * Evaluated against the account that OWNS the session/flow — the only
 * tenant a background run ever acts for — and evaluated at execution
 * time, never remembered from when a flow was built or a job was queued.
 *
 * Deliberately NOT included: the per-user permission checks (there is no
 * user at runtime) and the Super Admin bypass (there is no Super Admin
 * either: a journey always runs AS its tenant). Subscription and quota
 * stay where they already are, in WhatsAppJourneyEngine::send().
 */
class JourneyRuntimeEntitlement
{
    public const CAPABILITY = 'journey_automation';

    public const MODULE = 'chatbot';

    public function __construct(private readonly AccessControlService $accessControl)
    {
    }

    public function allows(Account|int|null $account): bool
    {
        $account = $account instanceof Account ? $account : ($account ? Account::findCached((int) $account) : null);

        if (! $account) {
            return false;
        }

        return $account->hasModuleEnabled(self::MODULE)
            && $this->accessControl->canTenant($account, self::CAPABILITY);
    }

    /** Stored on a blocked session (last_error) so the reason is visible without a new column. */
    public static function reason(): string
    {
        return 'Blocked: the account is not entitled to Journey automation (journey_automation capability and chatbot module required). State kept; resumes when entitlement is restored.';
    }
}
