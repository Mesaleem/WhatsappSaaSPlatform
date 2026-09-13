<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\Subscription;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Module 5 — No-Code WhatsApp Journey Builder. Executes tenant-authored
 * conversational flows (WhatsAppFlow.graph_data) against inbound
 * WhatsApp messages, resuming a paused multi-turn conversation via
 * WhatsAppFlowSession across separate, stateless webhook requests.
 *
 * ENTRY POINT: called from ChatbotEngineService::handleInboundMessage()
 * — the SAME single choke point both MetaWebhookController (Meta engine)
 * and Internal\WhatsAppInboundController (Baileys/qr engine, via
 * qr-engine-service) already funnel every inbound message through — so
 * a flow built once works on either engine without a second integration
 * point. See that service's docblock for why the hook lives there
 * rather than being duplicated into both controllers.
 *
 * REGRESSION-SAFETY: for any tenant with zero WhatsAppFlow rows (the
 * common case — every existing tenant before this module), handleInboundMessage()
 * below returns false after two cheap, indexed queries (active-session
 * lookup, trigger-match lookup) — ChatbotEngineService's existing
 * chatbot_rules flow then runs completely unchanged. This module adds
 * no new external side effects unless a tenant explicitly builds a flow.
 *
 * NODE-TYPE CONTRACT (graph_data.nodes[].data shape per node.type — see
 * the whatsapp_flows migration's docblock for the surrounding graph
 * shape):
 *   trigger:    {} — no data read; a graph's single designated entry
 *               point (WhatsAppFlow::triggerNode()). Its one outgoing
 *               edge is where real execution begins.
 *   message:    {text: string} — sent immediately, no reply expected;
 *               execution continues to its one outgoing edge in the
 *               SAME webhook request (no session pause).
 *   question:   {prompt_text: string, variable_name: string,
 *                input_type: "text"|"buttons"|"list",
 *                options?: [{id: string, title: string}, ...],
 *                button_text?: string (list only),
 *                validation?: {type: "none"|"number"|"email"|"phone",
 *                              error_message?: string}}
 *               — sends the prompt (plain text or an interactive
 *               button/list message, mirroring ChatbotEngineService::
 *               buildInteractiveReply()'s exact Meta payload shape),
 *               then PAUSES the session at this node (current_node_id)
 *               until the next inbound message from this phone number.
 *               On that next message, the RAW reply text is captured
 *               verbatim into context_data[variable_name] — for
 *               buttons/list replies this is the button/row TITLE (or
 *               id, if title is unavailable), because MetaWebhookController
 *               flattens an interactive reply into a plain string
 *               BEFORE it ever reaches this engine (same "SAME signature"
 *               discipline ChatbotEngineService already established for
 *               its two callers) — this engine never sees Meta's raw
 *               interactive JSON.
 *   condition:  {variable: string} — no send; evaluates its outgoing
 *               edges IN ORDER against context_data[variable], each
 *               edge optionally carrying {condition: {operator:
 *               "equals"|"not_equals"|"contains"|"exists", value?:
 *               string}}; the first edge whose condition matches is
 *               followed, else the edge marked {is_default: true} (if
 *               any), else execution treats this as a dead end and the
 *               session completes with no save_lead. Evaluated
 *               synchronously, execution continues in the same request.
 *   save_lead:  {name_variable?: string, email_variable?: string,
 *                phone_variable?: string, completion_message?: string}
 *               — TERMINAL: upserts a `leads` row (provider =
 *               'whatsapp_journey'), sends completion_message if set,
 *               marks the session 'completed'. See upsertLead()'s
 *               docblock for the exact field mapping and dedup key.
 *
 * DISCLOSED — NOT INTEGRATION-TESTED against a live WhatsApp number
 * (same standing disclosure as MetaAdsService/OrganicPublishService):
 * every outbound send here goes through the SAME WhatsAppEngineFactory/
 * WhatsAppDriverInterface::sendMessage() contract ChatbotEngineService
 * already uses in production for keyword auto-replies, so the send
 * mechanics themselves are proven; what's new and unverified is the
 * multi-turn session/branching orchestration around those sends.
 */
class WhatsAppJourneyEngine
{
    /** Meta's own hard limit on quick-reply buttons per interactive message — mirrors ChatbotEngineService. */
    private const MAX_INTERACTIVE_BUTTONS = 3;

    private const MAX_BUTTON_TITLE_LENGTH = 20;

    /**
     * Defensive cycle guard: a malformed graph (e.g. a condition node
     * whose edges loop back on themselves with no matching branch and
     * no default) could otherwise advance() forever within one webhook
     * request. Exceeding this marks the session 'expired' and logs a
     * warning rather than hanging the request.
     */
    private const MAX_ADVANCE_STEPS = 25;

    /**
     * @return bool true if this message was consumed by an active/newly-
     *         started journey (caller should NOT also run chatbot_rules
     *         matching for it), false if no journey applies (caller
     *         should fall through to its existing chatbot_rules logic).
     */
    public function handleInboundMessage(int $accountId, string $senderPhone, string $incomingMessage, ?array $referral = null): bool
    {
        try {
            $session = WhatsAppFlowSession::findActive($accountId, $senderPhone);

            if ($session) {
                return $this->continueSession($session, $incomingMessage);
            }

            return $this->tryStartSession($accountId, $senderPhone, $incomingMessage, $referral);
        } catch (Throwable $e) {
            // Same "never let this bubble up and break the webhook's fast
            // 2xx" discipline as ChatbotEngineService::handleInboundMessage().
            Log::error('WhatsAppJourneyEngine: unexpected failure processing an inbound message.', [
                'account_id' => $accountId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * WhatsAppFlowController::test() — "Test Trigger". Runs THIS specific
     * flow against a tenant-supplied phone number for real, bypassing
     * trigger matching entirely (the point of a manual test is to
     * exercise the exact flow being edited, not to re-derive which flow
     * a message would have matched). Any existing active session for
     * that phone number under this account is expired first, so a test
     * run always starts clean rather than silently resuming stale state
     * from a previous test.
     *
     * DISCLOSED: this sends REAL WhatsApp messages through the tenant's
     * configured engine (same quota-guarded send() as live traffic) —
     * not a dry-run/simulation. WhatsAppFlowController's docblock
     * repeats this so the frontend can warn the tenant before calling it.
     *
     * @throws RuntimeException if the flow has no usable trigger node/edge.
     */
    public function testFlow(Account $account, WhatsAppFlow $flow, string $phoneNumber): void
    {
        $triggerNode = $flow->triggerNode();

        if (! $triggerNode) {
            throw new RuntimeException('This flow has no Trigger node yet — add one and connect it before testing.');
        }

        $firstEdge = Collection::make($flow->outgoingEdges($triggerNode['id']))->first();

        if (! $firstEdge) {
            throw new RuntimeException('The Trigger node has no outgoing connection yet — connect it to the next node before testing.');
        }

        $existing = WhatsAppFlowSession::findActive($account->id, $phoneNumber);
        $existing?->forceFill(['status' => WhatsAppFlowSession::STATUS_EXPIRED])->save();

        $session = WhatsAppFlowSession::create([
            'account_id' => $account->id,
            'flow_id' => $flow->id,
            'phone_number' => $phoneNumber,
            'status' => WhatsAppFlowSession::STATUS_ACTIVE,
            'context_data' => [],
            'last_interaction_at' => now(),
        ]);

        $this->advance($account, $flow, $session, (string) $firstEdge['target']);
    }

    /**
     * Resumes a paused session: the current_node_id MUST be a 'question'
     * node (the only type that ever leaves a session paused) — captures
     * the reply, validates it, and advances from there. Always returns
     * true: once a session exists, this engine owns the conversation for
     * that phone number until it completes/expires, even if the reply
     * fails validation (the tenant's customer gets a re-prompt, not a
     * silent fall-through to unrelated chatbot_rules).
     */
    private function continueSession(WhatsAppFlowSession $session, string $incomingMessage): bool
    {
        $flow = $session->flow;

        if (! $flow || ! $flow->is_active) {
            $session->forceFill(['status' => WhatsAppFlowSession::STATUS_EXPIRED])->save();

            return true;
        }

        $account = Account::with('currentSubscription')->find($session->account_id);

        if (! $account) {
            $session->forceFill(['status' => WhatsAppFlowSession::STATUS_EXPIRED])->save();

            return true;
        }

        $node = $flow->findNode($session->current_node_id);

        if (! $node || ($node['type'] ?? null) !== 'question') {
            Log::warning("WhatsAppJourneyEngine: session #{$session->id} paused at a non-question node — marking expired.", [
                'flow_id' => $flow->id,
                'current_node_id' => $session->current_node_id,
            ]);
            $session->forceFill(['status' => WhatsAppFlowSession::STATUS_EXPIRED])->save();

            return true;
        }

        $data = $node['data'] ?? [];
        $trimmed = trim($incomingMessage);

        $validationError = $this->validateAnswer($trimmed, $data['validation'] ?? null);

        if ($validationError !== null) {
            $subscription = $account->currentSubscription;
            $this->sendText($account, $subscription, $session->phone_number, $validationError."\n\n".(string) ($data['prompt_text'] ?? ''), $flow->id);

            return true;
        }

        $variableName = (string) ($data['variable_name'] ?? '');

        if ($variableName !== '') {
            $session->setVariable($variableName, $trimmed);
        }

        $nextEdge = Collection::make($flow->outgoingEdges($node['id']))->first();
        $session->last_interaction_at = now();

        if (! $nextEdge) {
            $session->forceFill(['status' => WhatsAppFlowSession::STATUS_COMPLETED])->save();

            return true;
        }

        $this->advance($account, $flow, $session, (string) $nextEdge['target']);

        return true;
    }

    /**
     * @return string|null a validation error message, or null if valid/no validation configured.
     */
    private function validateAnswer(string $value, ?array $validation): ?string
    {
        $type = $validation['type'] ?? 'none';

        $valid = match ($type) {
            'number' => is_numeric($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => PhoneNumberNormalizer::normalize($value) !== '',
            default => true,
        };

        if ($valid) {
            return null;
        }

        return (string) ($validation['error_message'] ?? 'That doesn\'t look right — please try again.');
    }

    /**
     * No active session — checks whether any active WhatsAppFlow's
     * trigger fires for this inbound message, in priority order:
     * ctwa_referral (only when $referral is present) > keyword > default.
     * This mirrors chatbot_rules' own "specific match, then fallback"
     * evaluation order (ChatbotEngineService::findMatchingRule()).
     */
    private function tryStartSession(int $accountId, string $senderPhone, string $incomingMessage, ?array $referral): bool
    {
        $flow = $this->matchFlow($accountId, $incomingMessage, $referral);

        if (! $flow) {
            return false;
        }

        $account = Account::with('currentSubscription')->find($accountId);

        if (! $account) {
            return false;
        }

        $triggerNode = $flow->triggerNode();

        if (! $triggerNode) {
            Log::warning("WhatsAppJourneyEngine: WhatsAppFlow #{$flow->id} has no trigger node — cannot start.");

            return false;
        }

        $firstEdge = Collection::make($flow->outgoingEdges($triggerNode['id']))->first();

        if (! $firstEdge) {
            Log::warning("WhatsAppJourneyEngine: WhatsAppFlow #{$flow->id}'s trigger node has no outgoing edge — nothing to run.");

            return false;
        }

        $session = WhatsAppFlowSession::create([
            'account_id' => $accountId,
            'flow_id' => $flow->id,
            'phone_number' => $senderPhone,
            'status' => WhatsAppFlowSession::STATUS_ACTIVE,
            'context_data' => [],
            'last_interaction_at' => now(),
        ]);

        $this->advance($account, $flow, $session, (string) $firstEdge['target']);

        return true;
    }

    private function matchFlow(int $accountId, string $incomingMessage, ?array $referral): ?WhatsAppFlow
    {
        if ($referral !== null) {
            $sourceAdId = $referral['source_id'] ?? null;

            $ctwaFlows = WhatsAppFlow::query()
                ->forAccount($accountId)
                ->active()
                ->where('trigger_type', 'ctwa_referral')
                ->get();

            // Prefer a flow scoped to THIS specific ad_id over a
            // catch-all "any CTWA referral" flow (empty trigger_value) —
            // see the creating migration's docblock.
            $specific = $sourceAdId
                ? $ctwaFlows->first(fn (WhatsAppFlow $f) => $f->trigger_value === (string) $sourceAdId)
                : null;

            if ($specific) {
                return $specific;
            }

            $any = $ctwaFlows->first(fn (WhatsAppFlow $f) => empty($f->trigger_value));

            if ($any) {
                return $any;
            }
        }

        $normalized = mb_strtolower(trim($incomingMessage));

        if ($normalized !== '') {
            $keywordFlows = WhatsAppFlow::query()
                ->forAccount($accountId)
                ->active()
                ->where('trigger_type', 'keyword')
                ->get();

            foreach ($keywordFlows as $flow) {
                $keywords = Collection::make(explode(',', (string) $flow->trigger_value))
                    ->map(fn ($kw) => mb_strtolower(trim($kw)))
                    ->filter(fn ($kw) => $kw !== '');

                if ($keywords->contains(fn (string $kw) => str_contains($normalized, $kw))) {
                    return $flow;
                }
            }
        }

        return WhatsAppFlow::query()
            ->forAccount($accountId)
            ->active()
            ->where('trigger_type', 'default')
            ->first();
    }

    /**
     * Walks the graph from $nodeId forward, executing each node in turn,
     * until it hits a 'question' node (sends the prompt, pauses the
     * session) or a terminal state (save_lead, or a dead end). Bounded
     * by MAX_ADVANCE_STEPS — see that constant's docblock.
     */
    private function advance(Account $account, WhatsAppFlow $flow, WhatsAppFlowSession $session, string $nodeId): void
    {
        $subscription = $account->currentSubscription;
        $steps = 0;

        while (true) {
            if (++$steps > self::MAX_ADVANCE_STEPS) {
                Log::warning("WhatsAppJourneyEngine: session #{$session->id} exceeded max advance steps — likely a cyclical graph. Marking expired.", [
                    'flow_id' => $flow->id,
                ]);
                $session->forceFill(['status' => WhatsAppFlowSession::STATUS_EXPIRED])->save();

                return;
            }

            $node = $flow->findNode($nodeId);

            if (! $node) {
                Log::warning("WhatsAppJourneyEngine: session #{$session->id} — node '{$nodeId}' not found in flow #{$flow->id}'s graph. Marking completed.");
                $session->forceFill(['status' => WhatsAppFlowSession::STATUS_COMPLETED])->save();

                return;
            }

            $type = $node['type'] ?? null;
            $data = $node['data'] ?? [];

            if ($type === 'message') {
                $this->sendText($account, $subscription, $session->phone_number, (string) ($data['text'] ?? ''), $flow->id);

                $next = Collection::make($flow->outgoingEdges($node['id']))->first();

                if (! $next) {
                    $session->forceFill(['status' => WhatsAppFlowSession::STATUS_COMPLETED])->save();

                    return;
                }

                $nodeId = (string) $next['target'];

                continue;
            }

            if ($type === 'question') {
                $this->sendQuestion($account, $subscription, $session->phone_number, $data, $flow->id);

                $session->forceFill([
                    'current_node_id' => $node['id'],
                    'status' => WhatsAppFlowSession::STATUS_ACTIVE,
                    'last_interaction_at' => now(),
                ])->save();

                return;
            }

            if ($type === 'condition') {
                $variableValue = $session->getVariable((string) ($data['variable'] ?? ''));
                $edges = $flow->outgoingEdges($node['id']);

                $target = $this->resolveConditionTarget($edges, $variableValue);

                if ($target === null) {
                    $session->forceFill(['status' => WhatsAppFlowSession::STATUS_COMPLETED])->save();

                    return;
                }

                $nodeId = $target;

                continue;
            }

            if ($type === 'save_lead') {
                $this->upsertLead($account, $flow, $session, $data);

                $completionMessage = trim((string) ($data['completion_message'] ?? ''));

                if ($completionMessage !== '') {
                    $this->sendText($account, $subscription, $session->phone_number, $completionMessage, $flow->id);
                }

                $session->forceFill(['status' => WhatsAppFlowSession::STATUS_COMPLETED])->save();

                return;
            }

            Log::warning("WhatsAppJourneyEngine: session #{$session->id} — unknown node type '{$type}'. Marking expired.");
            $session->forceFill(['status' => WhatsAppFlowSession::STATUS_EXPIRED])->save();

            return;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $edges
     */
    private function resolveConditionTarget(array $edges, mixed $variableValue): ?string
    {
        $normalizedValue = is_string($variableValue) ? mb_strtolower(trim($variableValue)) : $variableValue;
        $defaultTarget = null;

        foreach ($edges as $edge) {
            if (! empty($edge['is_default'])) {
                $defaultTarget = (string) $edge['target'];

                continue;
            }

            $condition = $edge['condition'] ?? null;

            if (! $condition) {
                continue;
            }

            $operator = $condition['operator'] ?? 'equals';
            $expected = $condition['value'] ?? null;
            $normalizedExpected = is_string($expected) ? mb_strtolower(trim($expected)) : $expected;

            $matches = match ($operator) {
                'equals' => $normalizedValue === $normalizedExpected,
                'not_equals' => $normalizedValue !== $normalizedExpected,
                'contains' => is_string($normalizedValue) && is_string($normalizedExpected) && str_contains($normalizedValue, $normalizedExpected),
                'exists' => $variableValue !== null && $variableValue !== '',
                default => false,
            };

            if ($matches) {
                return (string) $edge['target'];
            }
        }

        return $defaultTarget;
    }

    /**
     * Upserts a `leads` row for this journey. Dedup key is a synthetic
     * `journey:{flow_id}:{session_id}` provider_lead_id — unique per
     * session (leads.provider_lead_id has a unique DB index, same
     * redelivery-safety mechanism MetaLeadWebhookHandler and Step 4's
     * captureCtwaLead() both already rely on), defensive against
     * advance() ever being invoked twice for the same save_lead node
     * (it isn't under normal operation, but this makes a retry safe by
     * construction rather than by discipline alone).
     *
     * ALL collected context_data is preserved verbatim in
     * `raw_field_data` regardless of which variables were explicitly
     * mapped to name/email/phone — same "never lose data even when the
     * mapping misses something" discipline as MetaLeadWebhookHandler.
     *
     * @param array<string, mixed> $data
     */
    private function upsertLead(Account $account, WhatsAppFlow $flow, WhatsAppFlowSession $session, array $data): void
    {
        $context = $session->context_data ?? [];

        $nameVar = $data['name_variable'] ?? null;
        $emailVar = $data['email_variable'] ?? null;
        $phoneVar = $data['phone_variable'] ?? null;

        $phone = $phoneVar && ! empty($context[$phoneVar])
            ? PhoneNumberNormalizer::normalize((string) $context[$phoneVar])
            : PhoneNumberNormalizer::normalize($session->phone_number);

        Lead::updateOrCreate(
            ['provider_lead_id' => "journey:{$flow->id}:{$session->id}"],
            [
                'account_id' => $account->id,
                'provider' => 'whatsapp_journey',
                'lead_name' => $nameVar ? ($context[$nameVar] ?? null) : null,
                'lead_phone' => $phone !== '' ? $phone : $session->phone_number,
                'lead_email' => $emailVar ? ($context[$emailVar] ?? null) : null,
                'raw_field_data' => [
                    'flow_id' => $flow->id,
                    'flow_name' => $flow->name,
                    'session_id' => $session->id,
                    'variables' => $context,
                ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendQuestion(Account $account, ?Subscription $subscription, string $phone, array $data, ?int $flowId = null): void
    {
        $promptText = (string) ($data['prompt_text'] ?? '');
        $inputType = $data['input_type'] ?? 'text';

        if ($inputType === 'text' || empty($data['options'])) {
            $this->sendText($account, $subscription, $phone, $promptText);

            return;
        }

        $options = Collection::make($data['options'])
            ->map(fn ($opt) => ['id' => (string) ($opt['id'] ?? ''), 'title' => (string) ($opt['title'] ?? '')])
            ->values();

        if ($inputType === 'list') {
            $metaData = [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'list',
                    'body' => ['text' => $promptText],
                    'action' => [
                        'button' => (string) ($data['button_text'] ?? 'Menu'),
                        'sections' => [[
                            'title' => 'Options',
                            'rows' => $options->all(),
                        ]],
                    ],
                ],
            ];
        } else {
            $metaData = [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $promptText],
                    'action' => [
                        'buttons' => $options->take(self::MAX_INTERACTIVE_BUTTONS)->map(fn ($opt) => [
                            'type' => 'reply',
                            'reply' => ['id' => $opt['id'], 'title' => mb_substr($opt['title'], 0, self::MAX_BUTTON_TITLE_LENGTH)],
                        ])->values()->all(),
                    ],
                ],
            ];
        }

        $this->send($account, $subscription, $phone, $promptText, $metaData, $flowId);
    }

    private function sendText(Account $account, ?Subscription $subscription, string $phone, string $text, ?int $flowId = null): void
    {
        if (trim($text) === '') {
            return;
        }

        $this->send($account, $subscription, $phone, $text, [], $flowId);
    }

    /**
     * Quota-guarded send — same discipline as ChatbotEngineService::
     * process(): a journey's messages consume the SAME message quota as
     * chatbot auto-replies and payment alerts, so this module cannot be
     * used to bypass the subscription/quota system. Failures (no active
     * subscription, quota exhausted, no engine configured, or the send
     * itself failing) are logged and swallowed rather than thrown —
     * consistent with this codebase's "webhook processing must never
     * throw" discipline — at the cost of no tenant-visible log row for
     * a failed journey send (chatbot_rules gets ChatbotLog; flows do
     * not have an equivalent log table in this module — a disclosed
     * scope gap, not silently assumed acceptable).
     *
     * @param array<string, mixed> $metaData
     */
    private function send(Account $account, ?Subscription $subscription, string $phone, string $text, array $metaData, ?int $flowId = null): void
    {
        if (! $account->hasActiveSubscription() || ! $subscription) {
            Log::warning("WhatsAppJourneyEngine: account #{$account->id} has no active subscription — send skipped.");
            MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: 'No active subscription.', referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text);

            return;
        }

        $quotaExhausted = $subscription->billing_model !== 'unlimited'
            && $subscription->total_allocated_messages !== null
            && $subscription->used_messages >= $subscription->total_allocated_messages;

        if ($quotaExhausted) {
            Log::warning("WhatsAppJourneyEngine: account #{$account->id} quota exhausted — send skipped.");
            MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: 'Quota exhausted.', referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text);

            return;
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            Log::warning("WhatsAppJourneyEngine: account #{$account->id} has no usable WhatsApp engine — send skipped.", [
                'exception' => $e->getMessage(),
            ]);
            MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: $e->getMessage(), referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text);

            return;
        }

        $result = $driver->sendMessage($phone, $text, $metaData);

        if (empty($result['success'])) {
            Log::warning("WhatsAppJourneyEngine: send failed for account #{$account->id}.", ['error' => $result['error'] ?? null]);
            MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: $result['error'] ?? 'The WhatsApp engine rejected the message.', referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text);

            return;
        }

        DB::transaction(function () use ($subscription) {
            $locked = $subscription->newQuery()->lockForUpdate()->find($subscription->id);
            $locked?->increment('used_messages');
            $locked?->refreshStatus();
        });

        MessageDispatchLog::record($account->id, 'journey', $phone, success: true, referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text, gatewayMessageId: $result['message_id'] ?? null);
    }
}
