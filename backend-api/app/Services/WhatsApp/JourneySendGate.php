<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\Subscription;
use App\Services\Messaging\MessageQuotaService;

/**
 * Phase 7 Task 8 — the ONE place a Journey-generated send is checked
 * against the account's sending state, and the one classification of a
 * refusal. Every Journey send (message, text, image/video/document/audio,
 * question prompt, save_lead completion message, validation re-prompt)
 * goes through WhatsAppJourneyEngine::send(), which calls this before the
 * driver. It composes the existing checks, it does not re-implement them:
 *
 *   Account::isAdministrativelyActive()          account status
 *   Subscription::refreshStatus()/computeStatus() active | expired | exhausted
 *   MessageQuotaService::hasQuotaFor($sub, 1)    the quota gate
 *
 * The subscription is re-read from the database on every check (one
 * indexed query), so usage consumed earlier in the same run — or by any
 * other sender — is seen before the next send.
 *
 * CONTRACT (category, retryable):
 *   account suspended / not active   entitlement_blocked  permanent → failed
 *   no current subscription          entitlement_blocked  permanent → failed
 *   subscription expired (by date)   quota_failure        retryable (renewal)
 *   quota exhausted                  quota_failure        retryable (top-up)
 *   otherwise                        null — the send may go ahead
 *
 * Retryable refusals use the existing Task 1/5 retry machinery (same
 * backoff, same MAX_RESUME_ATTEMPTS, then 'failed' with quota_failure).
 * Capability/provider entitlement of a NODE (whatsapp_send for palette
 * nodes) is JourneyNodeAuthorizer::runtimeDenialFor()'s question, and the
 * Journey entitlement (journey_automation + chatbot module) is
 * JourneyRuntimeEntitlement's — neither is decided here.
 */
final class JourneySendGate
{
    public const RETRYABLE = true;

    public const PERMANENT = false;

    public function __construct(private readonly MessageQuotaService $quota) {}

    /**
     * @return array{category: string, retryable: bool, reason: string}|null
     */
    public function refusal(Account $account, ?Subscription $subscription): ?array
    {
        if (! $account->isAdministrativelyActive()) {
            return ['category' => 'entitlement_blocked', 'retryable' => self::PERMANENT, 'reason' => 'The account is not active (suspended)'];
        }

        // Re-read the row: a run keeps one Subscription instance for all its
        // sends, and MessageQuotaService::consume() increments a separately
        // locked copy — without this, a multi-send run judged every send by
        // the usage it saw when it started and could overshoot the cap.
        $current = $subscription?->exists ? $subscription->newQuery()->whereKey($subscription->getKey())->first() : null;

        if (! $current) {
            return ['category' => 'entitlement_blocked', 'retryable' => self::PERMANENT, 'reason' => 'The account has no subscription'];
        }

        $subscription->setRawAttributes($current->getAttributes(), true);

        $status = $subscription->refreshStatus();

        if ($status === 'expired') {
            return ['category' => 'quota_failure', 'retryable' => self::RETRYABLE, 'reason' => 'The subscription has expired'];
        }

        if ($status !== 'active' || ! $this->quota->hasQuotaFor($subscription, 1)) {
            return ['category' => 'quota_failure', 'retryable' => self::RETRYABLE, 'reason' => 'Quota exhausted'];
        }

        return null;
    }
}
