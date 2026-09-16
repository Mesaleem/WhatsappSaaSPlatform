<?php

namespace App\Services\Chatbot;

use App\Models\Account;
use App\Models\ChatbotLog;
use App\Models\ChatbotRule;
use App\Models\MessageDispatchLog;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Module 10 — the pattern matcher and auto-reply dispatcher. Called from
 * both MetaWebhookController (real inbound Meta messages) and the new
 * internal Baileys inbound-message endpoint (Internal\WhatsAppInboundController)
 * with the SAME signature, so there is exactly one place matching/reply
 * logic lives regardless of which engine the message arrived on.
 *
 * `response_payload` shapes by `response_type` (all built by the tenant
 * via ChatbotRuleController, validated there):
 *   text:        {"text": "..."}
 *   media:       {"media_type": "image"|"document"|"video"|"audio", "url": "...", "caption"?: "...", "filename"?: "..."}
 *   interactive: {"body": "...", "interactive_type": "button"|"list",
 *                 // interactive_type = "button":
 *                 "buttons"?: [{"id": "...", "title": "..."}, ...]  (max 3, Meta's own limit),
 *                 // interactive_type = "list":
 *                 "button_text"?: "...", "sections"?: [{"title": "...", "rows": [{"id","title","description"?}]}]}
 *
 * Only Meta-shaped `driverMetaData` is built here (passed through
 * unmodified to WhatsAppDriverInterface::sendMessage's $metaData param) —
 * BaileysDriver forwards whatever it's given to a qr-engine-service
 * endpoint that does not exist yet (a gap disclosed since Module 5), so
 * media/interactive replies on the 'qr' engine will not actually render
 * anything richer than what that future endpoint chooses to support.
 */
class ChatbotEngineService
{
    /** Meta's own hard limit on quick-reply buttons per interactive message. */
    private const MAX_INTERACTIVE_BUTTONS = 3;

    /** Meta's own hard limit on a quick-reply button's title length. */
    private const MAX_BUTTON_TITLE_LENGTH = 20;

    /**
     * Module 5 (No-Code WhatsApp Journey Builder) addition: $referral is
     * an optional trailing param (backward-compatible with both existing
     * call sites — Internal\WhatsAppInboundController never passes it,
     * MetaWebhookController passes it only when the inbound message
     * carried a Click-to-WhatsApp referral). WhatsAppJourneyEngine is
     * tried FIRST, before any chatbot_rules matching — an active or
     * newly-triggered journey session owns the conversation; only when
     * it declines (no session, no flow trigger matched — the common
     * case for every tenant with zero WhatsAppFlow rows) does this fall
     * through to the pre-existing process() below, completely unchanged.
     * See WhatsAppJourneyEngine's class docblock for the full contract.
     */
    public function handleInboundMessage(int $accountId, string $senderPhone, string $incomingMessage, ?array $referral = null): ?ChatbotLog
    {
        try {
            if (app(WhatsAppJourneyEngine::class)->handleInboundMessage($accountId, $senderPhone, $incomingMessage, $referral)) {
                // Consumed by a Journey — no ChatbotLog row is created for
                // it (flows do not yet have their own log table, a
                // disclosed scope gap — see WhatsAppJourneyEngine::send()).
                return null;
            }

            return $this->process($accountId, $senderPhone, $incomingMessage);
        } catch (Throwable $e) {
            // Both call sites (Meta webhook, Baileys internal endpoint) MUST
            // return a fast 2xx to their caller regardless of what happens
            // in here — an uncaught exception bubbling up would either make
            // Meta consider the webhook broken (and eventually disable it)
            // or fail an internal call qr-engine-service is waiting on.
            Log::error('ChatbotEngineService: unexpected failure processing an inbound message.', [
                'account_id' => $accountId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function process(int $accountId, string $senderPhone, string $incomingMessage): ?ChatbotLog
    {
        $account = Account::with('currentSubscription')->find($accountId);

        if (! $account) {
            Log::warning("ChatbotEngineService: account #{$accountId} not found — inbound message dropped.");

            return null;
        }

        $rule = $this->findMatchingRule($accountId, $incomingMessage);

        if (! $rule) {
            // No active rule matched AND no active fallback rule exists —
            // this is the ordinary, expected "chatbot has nothing to say"
            // case, not a failure.
            return $this->log($accountId, null, $senderPhone, $incomingMessage, null, 'ignored');
        }

        if (! $account->hasActiveSubscription()) {
            MessageDispatchLog::record($accountId, 'chatbot', $senderPhone, success: false, errorReason: 'No active subscription.', referenceType: 'chatbot_rule', referenceId: $rule->id);

            return $this->log($accountId, $rule->id, $senderPhone, $incomingMessage, null, 'failed');
        }

        $subscription = $account->currentSubscription;
        $quotaExhausted = $subscription->billing_model !== 'unlimited'
            && $subscription->total_allocated_messages !== null
            && $subscription->used_messages >= $subscription->total_allocated_messages;

        if ($quotaExhausted) {
            MessageDispatchLog::record($accountId, 'chatbot', $senderPhone, success: false, errorReason: 'No active subscription or quota exhausted.', referenceType: 'chatbot_rule', referenceId: $rule->id);

            return $this->log($accountId, $rule->id, $senderPhone, $incomingMessage, null, 'failed');
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            MessageDispatchLog::record($accountId, 'chatbot', $senderPhone, success: false, errorReason: $e->getMessage(), referenceType: 'chatbot_rule', referenceId: $rule->id);

            return $this->log($accountId, $rule->id, $senderPhone, $incomingMessage, null, 'failed');
        }

        [$replyText, $driverMetaData] = $this->buildReply($rule);

        $result = $driver->sendMessage($senderPhone, $replyText, $driverMetaData);

        if (empty($result['success'])) {
            MessageDispatchLog::record($accountId, 'chatbot', $senderPhone, success: false, errorReason: $result['error'] ?? 'The WhatsApp engine rejected the message.', referenceType: 'chatbot_rule', referenceId: $rule->id, messagePreview: $replyText);

            return $this->log($accountId, $rule->id, $senderPhone, $incomingMessage, null, 'failed');
        }

        // Chatbot auto-replies consume the SAME message quota as payment
        // alerts — without this, the quota system Module 3-9 built would
        // be trivially bypassed by flooding a tenant's chatbot with
        // keyword triggers. Same lock discipline as
        // ProcessPaymentAlertJob's success path.
        DB::transaction(function () use ($subscription) {
            $locked = $subscription->newQuery()->lockForUpdate()->find($subscription->id);
            $locked?->increment('used_messages');
            $locked?->refreshStatus();
        });

        // [Bug fix, disclosed]: same has_media/media_url root cause as
        // TemplateMessageDispatcher/DirectMessageDispatcher -- see
        // MessageDispatchLog::record()'s own docblock. A 'media'-type
        // chatbot rule (buildMediaReply() above) actually attaches a
        // file; previously this was never reflected in the log. The
        // URL lives in the rule's own response_payload (see this
        // class's docblock for that shape), not in $driverMetaData --
        // buildMediaReply() re-shapes it into Meta's own {type,
        // <type>:{link,...}} contract before this point.
        MessageDispatchLog::record($accountId, 'chatbot', $senderPhone, success: true, referenceType: 'chatbot_rule', referenceId: $rule->id, messagePreview: $replyText, gatewayMessageId: $result['message_id'] ?? null, hasMedia: $rule->response_type === 'media', mediaUrl: $rule->response_type === 'media' ? ($rule->response_payload['url'] ?? null) : null);

        return $this->log($accountId, $rule->id, $senderPhone, $incomingMessage, $replyText, 'replied');
    }

    /**
     * Evaluates active rules in priority order (lower priority number
     * first) and returns the first non-fallback match. A 'fallback'-type
     * rule never wins this scan directly — every ordinary rule gets a
     * chance first, regardless of where the fallback row's own priority
     * number would otherwise place it in the list — and is returned only
     * if nothing else matched.
     */
    private function findMatchingRule(int $accountId, string $incomingMessage): ?ChatbotRule
    {
        $rules = ChatbotRule::activeInEvaluationOrder($accountId)->get();
        $normalizedIncoming = mb_strtolower(trim($incomingMessage));

        $fallback = null;

        foreach ($rules as $rule) {
            if ($rule->match_type === 'fallback') {
                $fallback ??= $rule;

                continue;
            }

            if ($this->matches($rule, $incomingMessage, $normalizedIncoming)) {
                return $rule;
            }
        }

        return $fallback;
    }

    private function matches(ChatbotRule $rule, string $rawIncoming, string $normalizedIncoming): bool
    {
        $keywords = Collection::make($rule->keywords ?? [])
            ->map(fn ($kw) => (string) $kw)
            ->filter(fn ($kw) => $kw !== '');

        return match ($rule->match_type) {
            'exact' => $keywords->contains(fn (string $kw) => mb_strtolower(trim($kw)) === $normalizedIncoming),
            'contains' => $keywords->contains(fn (string $kw) => str_contains($normalizedIncoming, mb_strtolower($kw))),
            'starts_with' => $keywords->contains(fn (string $kw) => str_starts_with($normalizedIncoming, mb_strtolower($kw))),
            // Matched against the RAW (non-lowercased) message, deliberately —
            // an admin-authored PCRE pattern controls its own case
            // sensitivity via its own flags (e.g. "/hi/i"), so silently
            // lowercasing the subject first would surprise a pattern that
            // relies on case. @-suppressed: a malformed pattern (which
            // ChatbotRuleController::validateResponsePayload already tries
            // to reject at save time) must not throw a warning into a
            // webhook request; it's simply treated as "did not match".
            'regex' => $keywords->contains(fn (string $pattern) => @preg_match($pattern, $rawIncoming) === 1),
            default => false,
        };
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} [replyText, driverMetaData]
     */
    private function buildReply(ChatbotRule $rule): array
    {
        $payload = $rule->response_payload ?? [];

        return match ($rule->response_type) {
            'text' => [(string) ($payload['text'] ?? ''), []],
            'media' => $this->buildMediaReply($payload),
            'interactive' => $this->buildInteractiveReply($payload),
            default => ['', []],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildMediaReply(array $payload): array
    {
        $mediaType = in_array($payload['media_type'] ?? null, ['image', 'document', 'video', 'audio'], true)
            ? $payload['media_type']
            : 'document';

        $mediaObject = array_filter([
            'link' => $payload['url'] ?? null,
            'caption' => $payload['caption'] ?? null,
            // 'filename' is only meaningful for 'document' — Meta ignores
            // it for other types, so it's harmless to include regardless.
            'filename' => $payload['filename'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return [(string) ($payload['caption'] ?? ''), ['type' => $mediaType, $mediaType => $mediaObject]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildInteractiveReply(array $payload): array
    {
        $body = (string) ($payload['body'] ?? '');
        $isList = ($payload['interactive_type'] ?? 'button') === 'list';

        if ($isList) {
            $sections = Collection::make($payload['sections'] ?? [])
                ->map(fn ($section) => [
                    'title' => (string) ($section['title'] ?? ''),
                    'rows' => Collection::make($section['rows'] ?? [])
                        ->map(fn ($row) => array_filter([
                            'id' => (string) ($row['id'] ?? ''),
                            'title' => (string) ($row['title'] ?? ''),
                            'description' => $row['description'] ?? null,
                        ], fn ($v) => $v !== null && $v !== ''))
                        ->values()->all(),
                ])
                ->values()->all();

            $interactive = [
                'type' => 'list',
                'body' => ['text' => $body],
                'action' => [
                    'button' => (string) ($payload['button_text'] ?? 'Menu'),
                    'sections' => $sections,
                ],
            ];
        } else {
            $buttons = Collection::make($payload['buttons'] ?? [])
                ->take(self::MAX_INTERACTIVE_BUTTONS)
                ->map(fn ($btn) => [
                    'type' => 'reply',
                    'reply' => [
                        'id' => (string) ($btn['id'] ?? ''),
                        'title' => mb_substr((string) ($btn['title'] ?? ''), 0, self::MAX_BUTTON_TITLE_LENGTH),
                    ],
                ])
                ->values()->all();

            $interactive = [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => ['buttons' => $buttons],
            ];
        }

        return [$body, ['type' => 'interactive', 'interactive' => $interactive]];
    }

    private function log(
        int $accountId,
        ?int $ruleId,
        string $senderPhone,
        string $incomingMessage,
        ?string $replySent,
        string $status,
    ): ChatbotLog {
        return ChatbotLog::create([
            'account_id' => $accountId,
            'chatbot_rule_id' => $ruleId,
            'sender_phone' => $senderPhone,
            'incoming_message' => $incomingMessage,
            'reply_sent' => $replySent,
            'status' => $status,
        ]);
    }
}
