<?php

namespace App\Services\Comments;

use App\Models\CommentAutomationEvent;
use App\Models\CommentAutomationRule;
use App\Models\SocialAccount;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;
use App\Services\SocialAuth\Exceptions\SocialConnectionException;
use App\Services\SocialAuth\SocialConnectionService;
use App\Services\SocialAuth\WebhookAssetResolver;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * Ad Comment Auto-Responder: matches an incoming Facebook Page / Instagram
 * comment against the tenant's keyword rules and, on a match, posts a
 * public reply plus a private message to the commenter.
 *
 * DISCLOSED — NOT INTEGRATION-TESTED, same disclaimer as MetaAdsService:
 * no live Meta Page/Business account is reachable from this environment.
 * Request shapes below follow Meta's published webhook/Graph API
 * contracts as accurately as documentation allows but are [Hypothesis]
 * until verified live; items I verified directly against this repo's own
 * code are marked [Fact].
 *
 * DISCLOSED LIMITATION — "WhatsApp message to the commenter" (part of
 * the spec's literal wording for step 2) is NOT IMPLEMENTED and cannot
 * be: a Facebook/Instagram comment's webhook payload carries only the
 * commenter's Page-Scoped ID (PSID) / IG-Scoped ID, never a phone
 * number, and Graph API exposes no endpoint that resolves one from the
 * other — there is no phone number to send a WhatsApp message to. What
 * IS implemented, and what Meta's own platform actually provides for
 * exactly this "DM the commenter" use case, is the Private Replies API
 * (POST /{comment_id}/private_replies) — a real Messenger/Instagram DM
 * opened directly from the comment via its existing PSID, with no phone
 * number needed. This fulfills "Direct Private Message" from the spec's
 * step 2 title; "/ WhatsApp message" in the same line is the disclosed
 * gap. Fabricating a WhatsApp send with no real phone number would not
 * work and is not implemented.
 */
class CommentAutomationService
{
    private const API_VERSION = 'v19.0';

    /**
     * @param array<string, mixed> $entry One element of the webhook
     *        payload's top-level `entry` array (see SocialWebhookController).
     *        Mirrors MetaLeadWebhookHandler::handle()'s exact per-entry
     *        contract so both handlers can be dispatched the same way,
     *        each filtering to the change `field` values it cares about.
     */
    public function handle(array $entry): void
    {
        $ownerId = isset($entry['id']) ? (string) $entry['id'] : null;

        if (! $ownerId) {
            return;
        }

        foreach ($entry['changes'] ?? [] as $change) {
            $field = $change['field'] ?? null;
            $value = $change['value'] ?? [];

            // [Hypothesis]: Facebook Page comments arrive on the 'feed'
            // field with item=comment, verb=add for a NEW comment (edits/
            // removes/likes share the same field with a different verb —
            // only 'add' should ever trigger an auto-reply, or every edit
            // to an already-replied-to comment would re-trigger it, were
            // it not for the comment_id dedup guard anyway).
            if ($field === 'feed' && ($value['item'] ?? null) === 'comment' && ($value['verb'] ?? null) === 'add') {
                $this->handleFeedComment($ownerId, $value);

                continue;
            }

            // [Hypothesis]: Instagram comment webhooks arrive on a
            // 'comments' field (some Graph API versions/docs label the
            // subscription itself 'instagram' — both are accepted here
            // since this app cannot verify which this account's actual
            // subscription uses without a live Page/IG account).
            if ($field === 'comments' || $field === 'instagram') {
                $this->handleInstagramComment($ownerId, $value);
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function handleFeedComment(string $pageId, array $value): void
    {
        $commentId = $value['comment_id'] ?? null;
        $message = (string) ($value['message'] ?? '');

        if (! $commentId) {
            Log::warning('CommentAutomationService: feed comment change missing comment_id.', ['value' => $value]);

            return;
        }

        // Phase 9 Task 2.2 — deterministic owner or nothing (WebhookAssetResolver).
        $resolution = app(WebhookAssetResolver::class)->resolve('meta', 'facebook_page', $pageId, 'comment', ['comment_id' => (string) $commentId]);
        $socialAccount = $resolution['account'];

        if ($resolution['status'] === WebhookAssetResolver::AMBIGUOUS) {
            return;
        }

        if (! $socialAccount) {
            Log::warning("CommentAutomationService: no tenant has Page {$pageId} connected — comment ignored.");

            return;
        }

        $this->processComment(
            $socialAccount,
            'facebook',
            (string) $commentId,
            isset($value['post_id']) ? (string) $value['post_id'] : null,
            isset($value['from']['id']) ? (string) $value['from']['id'] : (isset($value['sender_id']) ? (string) $value['sender_id'] : null),
            $message
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private function handleInstagramComment(string $igAccountId, array $value): void
    {
        $commentId = $value['id'] ?? null;
        $message = (string) ($value['text'] ?? '');

        if (! $commentId) {
            Log::warning('CommentAutomationService: Instagram comment change missing id.', ['value' => $value]);

            return;
        }

        // Phase 9 Task 2.2 — deterministic owner or nothing (WebhookAssetResolver).
        $resolution = app(WebhookAssetResolver::class)->resolve('meta', 'instagram', $igAccountId, 'instagram_comment', ['comment_id' => (string) $commentId]);
        $socialAccount = $resolution['account'];

        if ($resolution['status'] === WebhookAssetResolver::AMBIGUOUS) {
            return;
        }

        if (! $socialAccount) {
            Log::warning("CommentAutomationService: no tenant has Instagram account {$igAccountId} connected — comment ignored.");

            return;
        }

        $this->processComment(
            $socialAccount,
            'instagram',
            (string) $commentId,
            isset($value['media']['id']) ? (string) $value['media']['id'] : null,
            isset($value['from']['id']) ? (string) $value['from']['id'] : null,
            $message
        );
    }

    private function processComment(
        SocialAccount $socialAccount,
        string $platform,
        string $commentId,
        ?string $postId,
        ?string $commenterId,
        string $message
    ): void {
        // Technical redelivery guard — same reasoning and same
        // before-any-external-call placement as MetaLeadWebhookHandler's
        // provider_lead_id check.
        if (CommentAutomationEvent::where('comment_id', $commentId)->exists()) {
            return;
        }

        $rule = $this->findMatchingRule($socialAccount->account_id, $message);

        if (! $rule) {
            // No configured rule wants this comment — nothing to log,
            // nothing to dedup against; a later rule change is free to
            // match a similar future comment.
            return;
        }

        $accessToken = $socialAccount->access_token;

        if (! $accessToken) {
            Log::warning("CommentAutomationService: SocialAccount#{$socialAccount->id} has no access token — comment {$commentId} not actioned.");

            return;
        }

        // Phase 9 Task 2.1 — $socialAccount is the connection that owns this
        // webhook entry (resolved from the signed payload's Page / Instagram
        // id). A connection known to be expired/revoked is not used: like a
        // missing token, the comment is left un-actioned (logged with its
        // safe code). Webhook context: nothing is returned to a user.
        try {
            app(SocialConnectionService::class)->assertUsable($socialAccount, 'comments.reply');
        } catch (SocialConnectionException $e) {
            Log::warning("CommentAutomationService: SocialAccount#{$socialAccount->id} needs reconnecting — comment {$commentId} not actioned.", [
                'error_code' => $e->errorCode(),
            ]);

            return;
        }

        $event = CommentAutomationEvent::create([
            'account_id' => $socialAccount->account_id,
            'comment_automation_rule_id' => $rule->id,
            'platform' => $platform,
            'comment_id' => $commentId,
            'post_id' => $postId,
            'commenter_id' => $commenterId,
        ]);

        $connectionFailure = $this->postPublicReply($event, $socialAccount, $commentId, $rule->public_reply_template);

        if ($connectionFailure) {
            // The same token cannot send the private reply either.
            $event->forceFill(['private_message_error' => $connectionFailure->getMessage()])->save();

            return;
        }

        $this->sendPrivateReply($event, $socialAccount, $commentId, $rule->private_dm_template);
    }

    /**
     * Keyword match precedence, most-specific first: every ACTIVE
     * non-wildcard rule is checked (case-insensitive substring of the
     * comment's message) before the wildcard ('*') rule is considered as
     * a catch-all fallback. [Hypothesis] — a reasonable, disclosed
     * reading of the spec's "e.g. price, cost, ..., or wildcard *";
     * among multiple non-wildcard matches, the tenant's oldest-created
     * rule wins (insertion order), since the spec gives no priority
     * field to order by.
     */
    private function findMatchingRule(int $accountId, string $message): ?CommentAutomationRule
    {
        $rules = CommentAutomationRule::query()->forAccount($accountId)->active()->oldest()->get();
        $haystack = strtolower($message);

        $wildcard = null;

        foreach ($rules as $rule) {
            if ($rule->isWildcard()) {
                $wildcard ??= $rule;

                continue;
            }

            if (str_contains($haystack, strtolower($rule->keyword))) {
                return $rule;
            }
        }

        return $wildcard;
    }

    /**
     * @return SocialConnectionException|null set when Meta reported the connection expired/revoked (persisted)
     */
    private function postPublicReply(CommentAutomationEvent $event, SocialAccount $socialAccount, string $commentId, string $message): ?SocialConnectionException
    {
        try {
            $response = Http::asForm()->withToken($socialAccount->access_token)->timeout(15)->post(
                'https://graph.facebook.com/'.self::API_VERSION."/{$commentId}/comments",
                ['message' => $message]
            );
        } catch (Throwable $e) {
            $event->forceFill(['public_reply_error' => $e->getMessage()])->save();

            return null;
        }

        if ($response->failed()) {
            if ($connectionFailure = $this->connectionFailure($socialAccount, $response, 'Meta rejected the public reply.')) {
                $event->forceFill(['public_reply_error' => $connectionFailure->getMessage()])->save();

                return $connectionFailure;
            }

            $event->forceFill(['public_reply_error' => $response->json('error.message') ?? 'Meta rejected the public reply.'])->save();

            return null;
        }

        $event->forceFill(['public_replied_at' => now()])->save();

        return null;
    }

    /**
     * [Hypothesis]: POST /{comment_id}/private_replies is Meta's
     * documented "Send Private Replies" endpoint for both Facebook Page
     * comments and Instagram comments — opens/continues a Messenger (or
     * Instagram Direct) thread with the commenter using the PSID Meta
     * already resolved for this comment, no phone number required. Not
     * verified against a live call — see class docblock.
     */
    private function sendPrivateReply(CommentAutomationEvent $event, SocialAccount $socialAccount, string $commentId, string $message): void
    {
        try {
            $response = Http::asForm()->withToken($socialAccount->access_token)->timeout(15)->post(
                'https://graph.facebook.com/'.self::API_VERSION."/{$commentId}/private_replies",
                ['message' => $message]
            );
        } catch (Throwable $e) {
            $event->forceFill(['private_message_error' => $e->getMessage()])->save();

            return;
        }

        if ($response->failed()) {
            $connectionFailure = $this->connectionFailure($socialAccount, $response, 'Meta rejected the private reply.');
            $event->forceFill(['private_message_error' => $connectionFailure?->getMessage() ?? $response->json('error.message') ?? 'Meta rejected the private reply.'])->save();

            return;
        }

        $event->forceFill(['private_message_sent_at' => now()])->save();
    }

    /**
     * Phase 9 Task 2.1 — asks SocialConnectionService (and through it the
     * provider driver) whether a failed reply means the connection itself is
     * expired/revoked; if so it is persisted and the safe exception returned.
     * Any other failure → null (the event keeps Meta's message, as before).
     */
    private function connectionFailure(SocialAccount $socialAccount, HttpResponse $response, string $fallback): ?SocialConnectionException
    {
        $result = app(SocialConnectionService::class)->observe($socialAccount, ProviderRequestFailed::fromResponse($response, $fallback), 'comments.reply');

        return $result ? SocialConnectionException::for($socialAccount, $result->status, $result->reason) : null;
    }
}
