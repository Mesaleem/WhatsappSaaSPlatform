<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\Subscription;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppFlowVersion;
use App\Support\PhoneNumberNormalizer;
use App\Services\Access\JourneyRuntimeEntitlement;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Messaging\MessageQuotaService;
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
 *   delay:      {amount: number > 0, unit: "seconds"|"minutes"|"hours"|
 *                "days"} — Phase 7 Task 1, the temporal backbone. PARKS
 *               the session: status 'waiting', current_node_id = this
 *               node, wait_until = now + amount·unit. Nothing else runs
 *               in this request. The scheduler (journeys:resume-due →
 *               ResumeJourneySessionJob → resumeDueSession()) continues
 *               from the delay's outgoing edge once it is due. See
 *               resumeDueSession() for claim / retry / cancel semantics.
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

    /** Phase 7 Task 1 — delay units, as JourneyNodePaletteTest / WhatsAppFlowController validate them. */
    private const DELAY_UNIT_SECONDS = ['seconds' => 1, 'minutes' => 60, 'hours' => 3600, 'days' => 86400];

    /**
     * Phase 7 Task 1 — how long a claimed 'waiting' row stays invisible to
     * other workers while one executes it. If that worker dies, the row
     * becomes due again when the lease runs out and is retried. Must
     * comfortably exceed one run: at most MAX_ADVANCE_STEPS sends.
     */
    public const RESUME_LEASE_SECONDS = 600;

    /** Phase 7 Task 1 — resume attempts per wait before the session is marked 'failed'. */
    public const MAX_RESUME_ATTEMPTS = 3;

    /** Phase 7 Task 1 — first retry backoff; doubles per attempt (60s, 120s, …). */
    public const RETRY_BACKOFF_SECONDS = 60;

    /** Phase 7 Task 1 — why the most recent send() did not go out (read by requireSent()). */
    private ?string $lastSendError = null;

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

            // Phase 7 Task 1.6 — a journey this account is no longer entitled
            // to may not run. Checked only when a journey would otherwise act
            // (an open session here, a matched trigger in tryStartSession()),
            // so tenants without journeys pay no extra query.
            if ($session && ! $this->runtimeAllowed($accountId)) {
                $this->blockSession($session, clearTimer: true);

                return false;
            }

            // Entitled again: bring this phone's blocked session back first,
            // so a new message never starts a second journey beside it.
            if (! $session && ($blocked = WhatsAppFlowSession::findBlocked($accountId, $senderPhone))) {
                if (! $this->runtimeAllowed($accountId) || ! $this->unblock($blocked)) {
                    return false;
                }

                $session = WhatsAppFlowSession::findActive($accountId, $senderPhone);
            }

            if ($session) {
                return $this->continueSession($session, $incomingMessage);
            }

            // Phase 7 Task 1 — a journey parked on a delay belongs to the
            // scheduler, not to this message: let chatbot rules answer it,
            // and never start a second journey for the same phone.
            if (WhatsAppFlowSession::hasWaiting($accountId, $senderPhone)) {
                return false;
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
        // Phase 7 Task 2 — a manual test runs the journey as last SAVED (the
        // newest version, i.e. what the editor shows, published or draft),
        // pinned like any other session.
        $version = app(JourneyVersionService::class)->latest($flow);

        if (! $version) {
            throw new RuntimeException('This flow has no saved version yet — save it before testing.');
        }

        $triggerNode = $version->triggerNode();

        if (! $triggerNode) {
            throw new RuntimeException('This flow has no Trigger node yet — add one and connect it before testing.');
        }

        $firstEdge = Collection::make($version->outgoingEdges($triggerNode['id']))->first();

        if (! $firstEdge) {
            throw new RuntimeException('The Trigger node has no outgoing connection yet — connect it to the next node before testing.');
        }

        $existing = WhatsAppFlowSession::findActive($account->id, $phoneNumber);
        $existing?->forceFill(['status' => WhatsAppFlowSession::STATUS_EXPIRED])->save();

        $session = WhatsAppFlowSession::create([
            'account_id' => $account->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'phone_number' => $phoneNumber,
            'status' => WhatsAppFlowSession::STATUS_ACTIVE,
            'context_data' => [],
            'last_interaction_at' => now(),
        ]);

        $this->advance($account, $flow, $session, (string) $firstEdge['target']);
    }

    /**
     * Phase 7 Task 1 — continue ONE session parked on a delay, once due.
     * Called by ResumeJourneySessionJob; safe to call any number of times,
     * from any number of workers, for the same id.
     *
     * 1. CLAIM. One conditional UPDATE: status = 'waiting' AND wait_until
     *    <= now → push wait_until forward by RESUME_LEASE_SECONDS and
     *    count the attempt. Exactly one caller can win it; everyone else
     *    (a duplicate job, an overlapping scan, a cancelled or not-yet-due
     *    row) gets 'skipped' and touches nothing. If the winner dies
     *    mid-run, the lease expires and the row is simply due again.
     * 2. GUARDS. The flow must still exist, belong to the session's
     *    account, and be active. A deactivated flow HOLDS its waiting
     *    sessions (claim handed back, attempt not counted): they resume
     *    when the flow is switched back on.
     * 3. RUN. From the delay's outgoing edge — or, on a retry, from the
     *    checkpointed node — through the same advance() as the immediate
     *    path, in "resumed" mode: a send that does not go out throws
     *    JourneyStepFailed instead of being skipped, each successful
     *    message is checkpointed, and a cancellation stops the run.
     * 4. RETRY / FAIL. A failure is retried with backoff (60s, 120s…) up to
     *    MAX_RESUME_ATTEMPTS, then the session is 'failed' with last_error.
     *
     * Tenant scope: everything is derived from the session row itself
     * (its account, its flow, its phone). No request, no caller-supplied
     * account. Sends go through the same send() → WhatsAppEngineFactory
     * → driver contract as live traffic, with the same subscription and
     * quota gates.
     *
     * @return string skipped|held|waiting|active|completed|expired|failed|retrying|cancelled
     */
    public function resumeDueSession(int $sessionId): string
    {
        $now = now();

        $claimed = WhatsAppFlowSession::query()
            ->whereKey($sessionId)
            ->where('status', WhatsAppFlowSession::STATUS_WAITING)
            ->whereNotNull('wait_until')
            ->where('wait_until', '<=', $now)
            ->update([
                'wait_until' => $now->copy()->addSeconds(self::RESUME_LEASE_SECONDS),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed !== 1) {
            return 'skipped';
        }

        $session = WhatsAppFlowSession::find($sessionId);
        $flow = $session?->flow;

        if (! $session || ! $flow) {
            return 'skipped';
        }

        if ($flow->account_id !== $session->account_id) {
            return $this->failSession($session, 'The flow does not belong to this session\'s account.');
        }

        if (! $flow->is_active) {
            $session->forceFill(['wait_until' => $now, 'attempts' => max(0, $session->attempts - 1)])->save();

            return 'held';
        }

        $account = Account::with('currentSubscription')->find($session->account_id);

        if (! $account) {
            return 'skipped';
        }

        // Phase 7 Task 1.6 — re-checked HERE, after the claim and before any
        // step, so a job queued while the account was entitled cannot run
        // for an account that no longer is. The attempt is not counted.
        if (! $this->runtimeAllowed($account)) {
            $session->forceFill(['attempts' => max(0, $session->attempts - 1)]);
            $this->blockSession($session);

            return WhatsAppFlowSession::STATUS_BLOCKED;
        }

        // Phase 7 Task 2 — the session's PINNED version, never the live edit.
        $graph = $this->graphFor($session, $flow);
        $node = $graph->findNode($session->current_node_id);

        if (! $node) {
            return $this->failSession($session, "Node '{$session->current_node_id}' is no longer in the flow.");
        }

        $startNodeId = (string) $node['id'];

        if (($node['type'] ?? null) === 'delay') {
            $edge = Collection::make($graph->outgoingEdges($node['id']))->first();

            if (! $edge) {
                $session->forceFill(['status' => WhatsAppFlowSession::STATUS_COMPLETED])->save();

                return $this->settle($session);
            }

            $startNodeId = (string) $edge['target'];
        }

        try {
            $this->advance($account, $flow, $session, $startNodeId, resumed: true);
        } catch (Throwable $e) {
            return $this->retryOrFail($session, $e->getMessage());
        }

        return $this->settle($session);
    }

    /**
     * Phase 7 Task 1 — stop a session that is still open (awaiting a reply
     * or waiting on a delay). One conditional UPDATE, so it cannot revive
     * a session that already ended. A waiting session is never resumed
     * afterwards (the claim requires status = 'waiting'); a resumed run in
     * progress stops before its next node.
     *
     * @return bool false if the session had already ended
     */
    public function cancelSession(WhatsAppFlowSession $session): bool
    {
        return WhatsAppFlowSession::query()
            ->whereKey($session->id)
            ->whereIn('status', WhatsAppFlowSession::OPEN_STATUSES)
            ->update(['status' => WhatsAppFlowSession::STATUS_CANCELLED, 'wait_until' => null]) === 1;
    }

    /**
     * Phase 7 Task 1.6 — journeys:resume-due calls this every minute
     * BEFORE it looks for due sessions. For every account that has blocked
     * sessions and is entitled again, each blocked session goes back to
     * where it was (see WhatsAppFlowSession::STATUS_BLOCKED): a delayed one
     * to 'waiting' (already due, so it is dispatched in the same scan and
     * continues from its checkpoint), a question one to 'active'.
     *
     * @return int sessions restored
     */
    public function restoreEntitledBlockedSessions(int $accountLimit = 500): int
    {
        $restored = 0;

        $accountIds = WhatsAppFlowSession::query()
            ->where('status', WhatsAppFlowSession::STATUS_BLOCKED)
            ->distinct()
            ->limit($accountLimit)
            ->pluck('account_id');

        foreach ($accountIds as $accountId) {
            if (! $this->runtimeAllowed((int) $accountId)) {
                continue;
            }

            $restored += $this->restoreBlocked(WhatsAppFlowSession::query()->forAccount((int) $accountId));
        }

        return $restored;
    }

    /**
     * Phase 7 Task 2 — the graph a session runs on: its pinned version. A
     * session with no pin (only possible for one started by pre-versioning
     * code during a deploy) is pinned ONCE, to the journey's published
     * version, and uses that from then on. The live journey row is used
     * only if no version exists at all.
     */
    private function graphFor(WhatsAppFlowSession $session, WhatsAppFlow $flow): WhatsAppFlowVersion|WhatsAppFlow
    {
        if ($session->flow_version_id) {
            $version = $session->relationLoaded('flowVersion') ? $session->flowVersion : WhatsAppFlowVersion::find($session->flow_version_id);

            if ($version && (int) $version->flow_id === (int) $flow->id) {
                return $version;
            }
        }

        if ($flow->published_version_id && ($version = WhatsAppFlowVersion::find($flow->published_version_id))) {
            WhatsAppFlowSession::query()->whereKey($session->id)->whereNull('flow_version_id')->update(['flow_version_id' => $version->id]);
            $session->flow_version_id = $version->id;
            $session->syncOriginalAttribute('flow_version_id');

            return $version;
        }

        return $flow;
    }

    private function runtimeAllowed(Account|int $account): bool
    {
        return app(JourneyRuntimeEntitlement::class)->allows($account);
    }

    /**
     * Park a session as 'blocked', keeping all of its state. A delayed or
     * resuming session keeps a timer (wait_until = now: due the moment it
     * is restored); a question session keeps none ($clearTimer).
     */
    private function blockSession(WhatsAppFlowSession $session, bool $clearTimer = false): void
    {
        $session->forceFill([
            'status' => WhatsAppFlowSession::STATUS_BLOCKED,
            'wait_until' => $clearTimer ? null : now(),
            'last_error' => JourneyRuntimeEntitlement::reason(),
        ])->save();

        Log::info("WhatsAppJourneyEngine: session #{$session->id} blocked — account #{$session->account_id} is not entitled to Journey automation.");
    }

    /** One blocked session back to its open status (conditional: never revives anything else). */
    private function unblock(WhatsAppFlowSession $session): bool
    {
        return $this->restoreBlocked(WhatsAppFlowSession::query()->whereKey($session->id)) > 0;
    }

    private function restoreBlocked(\Illuminate\Database\Eloquent\Builder $scope): int
    {
        $waiting = (clone $scope)->where('status', WhatsAppFlowSession::STATUS_BLOCKED)->whereNotNull('wait_until')
            ->update(['status' => WhatsAppFlowSession::STATUS_WAITING, 'last_error' => null]);
        $active = (clone $scope)->where('status', WhatsAppFlowSession::STATUS_BLOCKED)->whereNull('wait_until')
            ->update(['status' => WhatsAppFlowSession::STATUS_ACTIVE, 'last_error' => null]);

        return $waiting + $active;
    }

    /** After a resumed run: a session that left 'waiting' carries no timer state. */
    private function settle(WhatsAppFlowSession $session): string
    {
        if (! in_array($session->status, [WhatsAppFlowSession::STATUS_WAITING, WhatsAppFlowSession::STATUS_CANCELLED, WhatsAppFlowSession::STATUS_FAILED, WhatsAppFlowSession::STATUS_BLOCKED], true)) {
            $session->forceFill(['wait_until' => null, 'attempts' => 0, 'last_error' => null])->save();
        }

        return $session->status;
    }

    private function retryOrFail(WhatsAppFlowSession $session, string $error): string
    {
        $session->refresh();

        if ($session->status === WhatsAppFlowSession::STATUS_CANCELLED) {
            return WhatsAppFlowSession::STATUS_CANCELLED;
        }

        if ($session->attempts >= self::MAX_RESUME_ATTEMPTS) {
            return $this->failSession($session, $error);
        }

        $session->forceFill([
            'status' => WhatsAppFlowSession::STATUS_WAITING,
            'wait_until' => now()->addSeconds(self::RETRY_BACKOFF_SECONDS * (2 ** max(0, $session->attempts - 1))),
            'last_error' => mb_substr($error, 0, 500),
        ])->save();

        Log::warning("WhatsAppJourneyEngine: resumed session #{$session->id} failed a step — retry scheduled.", ['attempt' => $session->attempts, 'error' => $error]);

        return 'retrying';
    }

    private function failSession(WhatsAppFlowSession $session, string $error): string
    {
        $session->forceFill([
            'status' => WhatsAppFlowSession::STATUS_FAILED,
            'wait_until' => null,
            'last_error' => mb_substr($error, 0, 500),
        ])->save();

        Log::warning("WhatsAppJourneyEngine: session #{$session->id} marked failed.", ['error' => $error]);

        return WhatsAppFlowSession::STATUS_FAILED;
    }

    private function wasCancelled(WhatsAppFlowSession $session): bool
    {
        if (WhatsAppFlowSession::query()->whereKey($session->id)->value('status') !== WhatsAppFlowSession::STATUS_CANCELLED) {
            return false;
        }

        $session->forceFill(['status' => WhatsAppFlowSession::STATUS_CANCELLED])->syncOriginal();

        return true;
    }

    /** Only a resumed run turns a send that did not go out into a retryable failure. */
    private function requireSent(bool $resumed, bool $sent, string $nodeId): void
    {
        if ($resumed && ! $sent) {
            throw new JourneyStepFailed("Node '{$nodeId}': message not sent — ".($this->lastSendError ?? 'unknown error').'.');
        }
    }

    /** @param array<string, mixed> $data */
    private function delaySeconds(array $data): ?int
    {
        $amount = $data['amount'] ?? null;
        $factor = self::DELAY_UNIT_SECONDS[$data['unit'] ?? ''] ?? null;

        if (! is_numeric($amount) || (float) $amount <= 0 || $factor === null) {
            return null;
        }

        return max(1, (int) ceil((float) $amount * $factor));
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

        // Phase 7 Task 2 — answer against the version the question was asked from.
        $graph = $this->graphFor($session, $flow);
        $node = $graph->findNode($session->current_node_id);

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

        $nextEdge = Collection::make($graph->outgoingEdges($node['id']))->first();
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

        // Phase 7 Task 1.6 — a trigger matched, but the account may not run
        // journeys any more: start nothing, send nothing, fall through.
        if (! $this->runtimeAllowed($accountId)) {
            return false;
        }

        $account = Account::with('currentSubscription')->find($accountId);

        if (! $account) {
            return false;
        }

        // Phase 7 Task 2 — a new session starts on, and is pinned to, the
        // journey's PUBLISHED version. Later edits/publishes never reach it.
        $version = $flow->publishedVersion;

        if (! $version) {
            return false;
        }

        $triggerNode = $version->triggerNode();

        if (! $triggerNode) {
            Log::warning("WhatsAppJourneyEngine: WhatsAppFlow #{$flow->id} has no trigger node — cannot start.");

            return false;
        }

        $firstEdge = Collection::make($version->outgoingEdges($triggerNode['id']))->first();

        if (! $firstEdge) {
            Log::warning("WhatsAppJourneyEngine: WhatsAppFlow #{$flow->id}'s trigger node has no outgoing edge — nothing to run.");

            return false;
        }

        $session = WhatsAppFlowSession::create([
            'account_id' => $accountId,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
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
                ->whereNotNull('published_version_id')
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
                ->whereNotNull('published_version_id')
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
            ->whereNotNull('published_version_id')
            ->where('trigger_type', 'default')
            ->first();
    }

    /**
     * Walks the graph from $nodeId forward, executing each node in turn,
     * until it hits a 'question' node (sends the prompt, pauses the
     * session) or a terminal state (save_lead, or a dead end). Bounded
     * by MAX_ADVANCE_STEPS — see that constant's docblock.
     */
    private function advance(Account $account, WhatsAppFlow $flow, WhatsAppFlowSession $session, string $nodeId, bool $resumed = false): void
    {
        $subscription = $account->currentSubscription;
        $steps = 0;
        // Phase 7 Task 2 — every node, edge and branch comes from the
        // session's pinned version. $flow is used only for its id/name.
        $graph = $this->graphFor($session, $flow);

        while (true) {
            // Phase 7 Task 1 — a scheduler-resumed run stops as soon as the
            // tenant cancels it (checked before every node; immediate runs
            // are unaffected and make no extra query).
            if ($resumed && $this->wasCancelled($session)) {
                return;
            }

            // Phase 7 Task 1.6 — entitlement revoked mid-run: stop before
            // this node. current_node_id is the checkpoint (the next node to
            // run), so a restored run continues here without replaying.
            if ($resumed && ! $this->runtimeAllowed($account)) {
                $session->forceFill(['current_node_id' => $nodeId]);
                $this->blockSession($session);

                return;
            }

            if (++$steps > self::MAX_ADVANCE_STEPS) {
                Log::warning("WhatsAppJourneyEngine: session #{$session->id} exceeded max advance steps — likely a cyclical graph. Marking expired.", [
                    'flow_id' => $flow->id,
                ]);
                $session->forceFill(['status' => WhatsAppFlowSession::STATUS_EXPIRED])->save();

                return;
            }

            $node = $graph->findNode($nodeId);

            if (! $node) {
                Log::warning("WhatsAppJourneyEngine: session #{$session->id} — node '{$nodeId}' not found in flow #{$flow->id}'s graph. Marking completed.");
                $session->forceFill(['status' => WhatsAppFlowSession::STATUS_COMPLETED])->save();

                return;
            }

            $type = $node['type'] ?? null;
            $data = $node['data'] ?? [];

            if ($type === 'message') {
                $sent = $this->sendText($account, $subscription, $session->phone_number, (string) ($data['text'] ?? ''), $flow->id);
                $this->requireSent($resumed, $sent, $node['id']);

                $next = Collection::make($graph->outgoingEdges($node['id']))->first();

                if (! $next) {
                    $session->forceFill(['status' => WhatsAppFlowSession::STATUS_COMPLETED])->save();

                    return;
                }

                $nodeId = (string) $next['target'];

                // Phase 7 Task 1 — checkpoint: a retry of a resumed run
                // restarts AFTER the last message that went out, so it is
                // never sent twice. Immediate runs are not checkpointed.
                if ($resumed) {
                    $session->forceFill(['current_node_id' => $nodeId])->save();
                }

                continue;
            }

            if ($type === 'delay') {
                $seconds = $this->delaySeconds($data);

                if ($seconds === null) {
                    $session->forceFill([
                        'status' => WhatsAppFlowSession::STATUS_FAILED,
                        'current_node_id' => $node['id'],
                        'wait_until' => null,
                        'last_error' => "Delay node '{$node['id']}' has an invalid amount/unit.",
                    ])->save();

                    return;
                }

                $session->forceFill([
                    'status' => WhatsAppFlowSession::STATUS_WAITING,
                    'current_node_id' => $node['id'],
                    'wait_until' => now()->addSeconds($seconds),
                    'attempts' => 0,
                    'last_error' => null,
                    'last_interaction_at' => now(),
                ])->save();

                return;
            }

            if ($type === 'question') {
                $sent = $this->sendQuestion($account, $subscription, $session->phone_number, $data, $flow->id);
                $this->requireSent($resumed, $sent, $node['id']);

                $session->forceFill([
                    'current_node_id' => $node['id'],
                    'status' => WhatsAppFlowSession::STATUS_ACTIVE,
                    'last_interaction_at' => now(),
                ])->save();

                return;
            }

            if ($type === 'condition') {
                $variableValue = $session->getVariable((string) ($data['variable'] ?? ''));
                $edges = $graph->outgoingEdges($node['id']);

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
                    $sent = $this->sendText($account, $subscription, $session->phone_number, $completionMessage, $flow->id);
                    // A retry re-runs upsertLead(), which is idempotent (provider_lead_id + CRM link).
                    $this->requireSent($resumed, $sent, $node['id']);
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

        $lead = Lead::updateOrCreate(
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
                    'flow_version_id' => $session->flow_version_id,
                    'session_id' => $session->id,
                    'variables' => $context,
                ],
            ]
        );

        /*
         * Phase 6 CRM Hardening (Issue 5) — promote this capture into
         * the CRM as a `journey` lead.
         *
         * JOURNEY EXECUTION IS UNCHANGED. This is the last statement of
         * a method that was already terminal for the save_lead node; it
         * adds no branch, no delay, no queue and no scheduling, and it
         * cannot alter which node runs next. linkQuietly means a CRM
         * failure can never abort a live journey advance mid-flow — the
         * capture row (which is what this node contractually produces)
         * is already written either way.
         *
         * Idempotent alongside the updateOrCreate above: if advance()
         * ever ran twice for the same save_lead node, the second call
         * finds crm_lead_id already set and creates nothing.
         */
        app(CaptureLeadLinker::class)->linkQuietly($lead);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendQuestion(Account $account, ?Subscription $subscription, string $phone, array $data, ?int $flowId = null): bool
    {
        $promptText = (string) ($data['prompt_text'] ?? '');
        $inputType = $data['input_type'] ?? 'text';

        if ($inputType === 'text' || empty($data['options'])) {
            return $this->sendText($account, $subscription, $phone, $promptText);
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

        return $this->send($account, $subscription, $phone, $promptText, $metaData, $flowId);
    }

    /** @return bool false only when a send was attempted and did not go out (an empty text is "nothing to send", true). */
    private function sendText(Account $account, ?Subscription $subscription, string $phone, string $text, ?int $flowId = null): bool
    {
        if (trim($text) === '') {
            return true;
        }

        return $this->send($account, $subscription, $phone, $text, [], $flowId);
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
     * Phase 7 Task 1: now RETURNS whether the message went out. Every
     * pre-existing caller ignored the (void) result and still does on the
     * immediate path; only a scheduler-resumed run acts on false.
     *
     * @param array<string, mixed> $metaData
     */
    private function send(Account $account, ?Subscription $subscription, string $phone, string $text, array $metaData, ?int $flowId = null): bool
    {
        $this->lastSendError = null;

        if (! $account->hasActiveSubscription() || ! $subscription) {
            $this->lastSendError = 'No active subscription';
            Log::warning("WhatsAppJourneyEngine: account #{$account->id} has no active subscription — send skipped.");
            MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: 'No active subscription.', referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text);

            return false;
        }

        // Phase 5 Task 3 -- was the third verbatim copy of
        // Subscription::computeStatus()'s exhaustion condition.
        // hasQuotaFor(1) is exactly equivalent; advisory and unlocked,
        // so this gate's timing and outcome are unchanged.
        if (! app(MessageQuotaService::class)->hasQuotaFor($subscription, 1)) {
            Log::warning("WhatsAppJourneyEngine: account #{$account->id} quota exhausted — send skipped.");
            $this->lastSendError = 'Quota exhausted';
            MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: 'Quota exhausted.', referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text);

            return false;
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            $this->lastSendError = $e->getMessage();
            Log::warning("WhatsAppJourneyEngine: account #{$account->id} has no usable WhatsApp engine — send skipped.", [
                'exception' => $e->getMessage(),
            ]);
            MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: $e->getMessage(), referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text);

            return false;
        }

        $result = $driver->sendMessage($phone, $text, $metaData);

        if (empty($result['success'])) {
            $this->lastSendError = (string) ($result['error'] ?? 'The WhatsApp engine rejected the message');
            Log::warning("WhatsAppJourneyEngine: send failed for account #{$account->id}.", ['error' => $result['error'] ?? null]);
            MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: $result['error'] ?? 'The WhatsApp engine rejected the message.', referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text);

            return false;
        }

        // Phase 5 Task 3 -- same lock-then-increment block as the other
        // four consume-after sites, now shared. Still strictly
        // check -> send -> consume: a journey node that has already gone
        // out is recorded, never re-gated.
        app(MessageQuotaService::class)->consume($subscription, 1);

        MessageDispatchLog::record($account->id, 'journey', $phone, success: true, referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $text, gatewayMessageId: $result['message_id'] ?? null);

        return true;
    }
}
