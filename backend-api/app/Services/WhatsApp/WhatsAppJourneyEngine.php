<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\JourneyExecutionEvent as Ev;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\Subscription;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppFlowVersion;
use App\Support\PhoneNumberNormalizer;
use App\Support\WhatsAppMediaPayloadBuilder;
use App\Services\Access\JourneyNodeAuthorizer;
use App\Services\Access\JourneyRuntimeEntitlement;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Messaging\InboundEventGate;
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
 *               edge optionally carrying {condition: {operator, value?}}
 *               (operator defaults to "equals"; the full operator list
 *               and comparison rules are JourneyConditionEvaluator's —
 *               Phase 7 Task 4); the first edge whose condition matches
 *               is followed, else the edge marked {is_default: true} (if
 *               several, the LAST in edge order — legacy rule, refused at
 *               save time since Task 4), else execution treats this as a
 *               dead end and the session completes with no save_lead. An
 *               edge with neither is skipped. Evaluated synchronously,
 *               execution continues in the same request.
 *   conditional: {conditions: [{variable, operator, value?}, ...],
 *                match?: "all"|"any" (default "all")} — Phase 7 Task 4.
 *               No send. "all" = AND, "any" = OR over the rules, each
 *               evaluated by JourneyConditionEvaluator against
 *               context_data. Follows the ONE outgoing edge whose
 *               sourceHandle is "true" or "false" accordingly; no edge on
 *               that handle is a dead end (session completes); more than
 *               one is ambiguous and fails the session.
 *   A condition that cannot be evaluated safely (unknown operator,
 *   non-numeric value for a numeric operator, missing variable name,
 *   malformed rule list, ambiguous branch) FAILS the session
 *   (status 'failed', last_error set, current_node_id = that node) and
 *   nothing further is sent — never a guessed branch.
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
 *   text:       {text: string} — Phase 7 Task 6. One text message;
 *               `{{ variable }}` filled from collected answers
 *               (JourneyActionConfig::renderText). Then the first outgoing
 *               edge, or a successful end.
 *   image / video / document / audio:
 *               {mediaUrl: http(s) URL, caption?: string (not audio),
 *                filename?: string (document)} — Phase 7 Task 6. One media
 *               message built by WhatsAppMediaPayloadBuilder for the
 *               account's engine (QR or Meta), exactly as
 *               DirectMessageDispatcher sends media; the dispatch log
 *               records has_media + media_url. Then the first outgoing
 *               edge, or a successful end.
 *               Both re-check the node's own entitlement at run time
 *               (JourneyNodeAuthorizer: whatsapp_send capability, provider)
 *               and FAIL the session at the node when it is denied.
 *
 * COMPLETION / TERMINAL CONTRACT (Phase 7 Task 6):
 *   'completed' is written ONLY by complete(), and only for a valid end: an
 *     action node (message/text/media/answered question/delay) with no
 *     outgoing edge, a condition/conditional branch with nothing connected
 *     (no default), or save_lead (terminal by contract).
 *   'expired' (expire(), always with last_error): step limit, a
 *     non-executable palette node, the flow deactivated / account gone while
 *     waiting for a reply, or a reply reaching a non-question node.
 *   'failed' (failSession()): malformed node, broken edge, denied node
 *     entitlement, retries exhausted.
 *   Every status change of a running session goes through transition(): the
 *     row is re-read under lock and written only while still 'active' /
 *     'waiting', so completed/failed/expired/cancelled never change again
 *     and blocked changes only through restoreBlocked() (entitlement
 *     restored). A completed session is never resumed (only 'waiting' rows
 *     are claimed; replies only reach 'active' rows).
 *
 * ACTION EXECUTION CONTRACT (Phase 7 Task 5) — every node, on both the
 * immediate path (inbound message / manual test) and the resumed path
 * (journeys:resume-due), runs through advance() and nothing else:
 *   - graph = the session's PINNED version (graphFor()); account, flow and
 *     phone come from the session row; a flow of another account is refused;
 *   - before each node: cancellation + runtime entitlement (resumed runs),
 *     the 25-step limit, then a CHECKPOINT (current_node_id = this node,
 *     session saved — a just-captured answer is persisted here);
 *   - action configuration is checked by JourneyActionConfig, conditions by
 *     JourneyConditionEvaluator; delay by delaySeconds();
 *   - sends only through send() → WhatsAppEngineFactory → driver, with the
 *     subscription/quota gate and MessageQuotaService::consume().
 * Outcomes:
 *   MALFORMED (bad config, edge to a missing node, bad condition) → 'failed'
 *     at the node, last_error, nothing further; never retried.
 *   TRANSIENT (a send that did not go out; save_lead capture or CRM write
 *     failing while the account is CRM-entitled; any exception) → retried
 *     from the failed node with the Task 1 backoff (immediate path: parked
 *     'waiting' by runImmediate(); resumed path: retryOrFail()), then
 *     'failed' after MAX_RESUME_ATTEMPTS resumed attempts.
 *   NOT EXECUTABLE (palette node without an engine branch) → 'expired',
 *     last_error (unchanged outcome).
 *   NODE ENTITLEMENT DENIED (text/media: JourneyNodeAuthorizer::
 *     runtimeDenialFor — capability/provider only) → 'failed'.
 *   SEND REFUSED BY THE ACCOUNT'S SENDING STATE (Phase 7 Task 8,
 *     JourneySendGate, same for every send): quota exhausted or plan
 *     expired → quota_failure, RETRIED like any transient failure;
 *     suspended account or no subscription → entitlement_blocked, 'failed'
 *     at once (no retry can fix it).
 *   ENTITLEMENT LOST → 'blocked' with all state kept (Task 1.6).
 * CRASH WINDOWS (Phase 7 Task 9). Order for one send node: checkpoint
 * (guarded UPDATE) → node_started → send gate (read) → provider call →
 * quota consume → dispatch log → node_succeeded → next checkpoint.
 *   - crash before the checkpoint: nothing happened; recovery (immediate
 *     path: recoverInterruptedRuns() after the run lease; resumed path:
 *     claim-lease expiry) resumes from the previous checkpoint;
 *   - crash after the provider accepted, before the next checkpoint: the
 *     node is run again on recovery (at-least-once); if it died before the
 *     consume/dispatch log, that first delivery is also unmetered/unlogged;
 *   - save_lead: re-running is idempotent (lead + CRM link keyed by
 *     journey:{flow}:{session}); only its completion message can repeat;
 *   - execution history is written after the fact it records: a crash in
 *     between loses that one row, never the state.
 * Every recoverable case ends in a durable state: resumed, then completed
 * or 'failed' after MAX_RESUME_ATTEMPTS.
 *
 * Exactly-once delivery is NOT guaranteed at the provider boundary: if the
 * provider accepted a message but the driver reported failure (e.g. a
 * timeout), or the process died after the provider accepted it but before
 * the next checkpoint was saved, the retry sends that one message again.
 * Every other step is idempotent or checkpointed.
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

    /** Phase 7 Task 7 — nodes whose execution has an external side effect (node_started is recorded). */
    private const SIDE_EFFECT_NODE_TYPES = ['message', 'question', 'save_lead'];

    /** Phase 7 Task 1 — resume attempts per wait before the session is marked 'failed'. */
    public const MAX_RESUME_ATTEMPTS = 3;

    /** Phase 7 Task 1 — first retry backoff; doubles per attempt (60s, 120s, …). */
    public const RETRY_BACKOFF_SECONDS = 60;

    public function __construct(
        // Phase 7 Task 4 — the one condition contract (pure, no I/O).
        private readonly JourneyConditionEvaluator $conditions = new JourneyConditionEvaluator(),
    ) {
    }

    /** Phase 7 Task 1 — why the most recent send() did not go out (read by requireSent()). */
    private ?string $lastSendError = null;

    /**
     * Phase 7 Task 7 — execution-history context of the CURRENT entry point
     * (set by every public method before it runs anything): what started
     * this run and, for an inbound message, its inbound_message_events id.
     */
    private string $source = 'system';

    private ?int $inboundEventId = null;

    /** Phase 7 Task 7 — type of the node being executed, for events. */
    private ?string $nodeType = null;

    /** Phase 7 Task 7 — the dispatch-log row of the most recent send attempt. */
    private ?int $lastDispatchLogId = null;

    /** Phase 7 Task 7 — failure category of the most recent send that did not go out. */
    private string $lastSendCategory = 'provider_failure';

    /** Phase 7 Task 8 — whether the most recent refused send may be retried (JourneySendGate). */
    private bool $lastSendRetryable = true;

    /** Phase 7 Task 7 — ids written by the most recent save_lead. */
    private array $lastLeadRefs = [];

    /**
     * @return bool true if this message was consumed by an active/newly-
     *         started journey (caller should NOT also run chatbot_rules
     *         matching for it), false if no journey applies (caller
     *         should fall through to its existing chatbot_rules logic).
     */
    public function handleInboundMessage(int $accountId, string $senderPhone, string $incomingMessage, ?array $referral = null, ?int $inboundEventId = null): bool
    {
        $this->beginTrace('inbound', $inboundEventId);

        try {
            $session = WhatsAppFlowSession::findActive($accountId, $senderPhone);

            // Phase 7 Task 1.6 — a journey this account is no longer entitled
            // to may not run. Checked only when a journey would otherwise act
            // (an open session here, a matched trigger in tryStartSession()),
            // so tenants without journeys pay no extra query.
            if ($session && ! $this->runtimeAllowed($accountId)) {
                // Phase 7 Task 10 — an INTERRUPTED run (active + run lease,
                // Task 9) keeps a timer, so on restoration it goes back to
                // 'waiting' and resumes from its checkpoint; only a session
                // awaiting a reply is blocked without one (Task 1.6).
                $this->blockSession($session, clearTimer: $session->wait_until === null);

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
        $this->beginTrace('test');

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

        // Phase 7 Task 9 — serialized with inbound traffic for the same
        // conversation (the InboundEventGate lease), so a test run and a
        // customer message can never both start a session for one phone.
        $gate = app(InboundEventGate::class)->run($account->id, $phoneNumber, 'test', null, function () use ($account, $flow, $version, $triggerNode, $firstEdge, $phoneNumber) {
            // Phase 7 Task 9 — a test run starts clean: EVERY open session of
            // this phone (awaiting a reply, parked on a delay or blocked) is
            // replaced — it used to be only an 'active' one, so a waiting
            // session kept running beside the test. One guarded write each.
            WhatsAppFlowSession::query()->forAccount($account->id)->where('phone_number', $phoneNumber)
                ->whereIn('status', WhatsAppFlowSession::OPEN_STATUSES)->get()
                ->each(function (WhatsAppFlowSession $existing) {
                    if (WhatsAppFlowSession::query()->whereKey($existing->id)->whereIn('status', WhatsAppFlowSession::OPEN_STATUSES)
                        ->update(['status' => WhatsAppFlowSession::STATUS_EXPIRED, 'wait_until' => null, 'last_error' => 'Replaced by a manual test run of this phone number.']) === 1) {
                        $this->trace($existing, Ev::SESSION_EXPIRED, ['error_category' => 'cancelled', 'error_message' => 'Replaced by a manual test run of this phone number.']);
                    }
                });

            $session = WhatsAppFlowSession::create([
                'account_id' => $account->id,
                'flow_id' => $flow->id,
                'flow_version_id' => $version->id,
                'phone_number' => $phoneNumber,
                'status' => WhatsAppFlowSession::STATUS_ACTIVE,
                'context_data' => [],
                'last_interaction_at' => now(),
                'wait_until' => $this->runLease(),
                // Phase 7 Task 9 — the trigger is the first checkpoint, so a
                // run interrupted before its first node resumes from here.
                'current_node_id' => (string) $triggerNode['id'],
            ]);

            $this->trace($session, Ev::SESSION_STARTED, ['node_id' => (string) $triggerNode['id'], 'node_type' => 'trigger', 'result' => 'manual_test']);

            $this->runImmediate($account, $flow, $session, (string) $firstEdge['target']);

            return true;
        });

        if (! $gate['handled']) {
            throw new RuntimeException('This phone number is in the middle of another conversation right now — try the test again in a moment.');
        }
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
        $this->beginTrace('scheduler');
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
            return $this->failSession($session, 'The flow does not belong to this session\'s account.', 'internal_error', atNode: false);
        }

        if (! $flow->is_active) {
            // Phase 7 Task 9 — guarded like every other write: a session
            // cancelled meanwhile keeps its terminal state untouched.
            WhatsAppFlowSession::query()->whereKey($session->id)->where('status', WhatsAppFlowSession::STATUS_WAITING)
                ->update(['wait_until' => $now, 'attempts' => max(0, $session->attempts - 1)]);

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
            return $this->failSession($session, "Node '{$session->current_node_id}' is no longer in the flow.", 'missing_node');
        }

        // Phase 7 Task 7 — the claim that is about to run (attempt = claim number).
        $this->trace($session, Ev::SESSION_RESUMED, [
            'node_type' => $node['type'] ?? null,
            'result' => $session->last_error !== null ? 'retry' : 'delay_due',
        ]);

        $startNodeId = (string) $node['id'];

        // A delay (or, for a recovered run interrupted before its first node,
        // the trigger — Phase 7 Task 9) continues from its outgoing edge.
        if (in_array($node['type'] ?? null, ['delay', 'trigger'], true)) {
            $edge = Collection::make($graph->outgoingEdges($node['id']))->first();

            if (! $edge) {
                $this->complete($session);

                return $this->settle($session);
            }

            $startNodeId = (string) $edge['target'];
        }

        try {
            $this->advance($account, $flow, $session, $startNodeId, resumed: true);
        } catch (Throwable $e) {
            return $this->retryOrFail($session, $e);
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
        $this->beginTrace('api');

        $cancelled = WhatsAppFlowSession::query()
            ->whereKey($session->id)
            ->whereIn('status', WhatsAppFlowSession::OPEN_STATUSES)
            ->update(['status' => WhatsAppFlowSession::STATUS_CANCELLED, 'wait_until' => null]) === 1;

        if ($cancelled) {
            $this->trace($session, Ev::SESSION_CANCELLED, ['error_category' => 'cancelled', 'result' => (string) $session->status]);
        }

        return $cancelled;
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
        $this->beginTrace('scheduler');
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
     * Phase 7 Task 9 — recover runs INTERRUPTED on the immediate path.
     *
     * An inbound/test run executes in the request that received the
     * message. Such a session is 'active' with wait_until = a run lease
     * (runLease(), written when the run starts; every way a run can end —
     * question pause, delay, retry, completion, failure, expiry, block —
     * clears or replaces it). If the process dies mid-run (timeout, OOM,
     * deploy restart) the session used to stay 'active' at its checkpoint
     * forever, owned by nothing. Now, once the lease has run out, the
     * scheduler parks it as 'waiting', due now, at that checkpoint — the
     * same state a failed immediate step gets — and the normal resume
     * (claim, backoff, MAX_RESUME_ATTEMPTS) continues it from the node that
     * was interrupted. That node may run twice (at-least-once, as for any
     * crash between a provider call and the next checkpoint).
     *
     * A session awaiting a reply (question pause) has wait_until NULL and is
     * never touched: waiting for a customer is legitimate, however long.
     * Bounded: at most $limit sessions per call; one guarded UPDATE each.
     *
     * @return int sessions recovered
     */
    public function recoverInterruptedRuns(int $limit = 200): int
    {
        $this->beginTrace('scheduler');
        $now = now();
        $message = 'Recovered: the run was interrupted (the process stopped) at this node; it resumes from here.';

        $candidates = WhatsAppFlowSession::query()
            ->where('status', WhatsAppFlowSession::STATUS_ACTIVE)
            ->whereNotNull('wait_until')
            ->where('wait_until', '<=', $now)
            ->orderBy('wait_until')
            ->limit($limit)
            ->get();

        $recovered = 0;

        foreach ($candidates as $session) {
            $written = WhatsAppFlowSession::query()->whereKey($session->id)
                ->where('status', WhatsAppFlowSession::STATUS_ACTIVE)
                ->whereNotNull('wait_until')
                ->where('wait_until', '<=', $now)
                ->update(['status' => WhatsAppFlowSession::STATUS_WAITING, 'wait_until' => $now, 'attempts' => 0, 'last_error' => $message]);

            if ($written !== 1) {
                continue;
            }

            $recovered++;
            $session->forceFill(['status' => WhatsAppFlowSession::STATUS_WAITING, 'wait_until' => $now, 'attempts' => 0, 'last_error' => $message])->syncOriginal();
            $this->trace($session, Ev::NODE_FAILED, ['error_category' => 'internal_error', 'error_message' => $message]);
            $this->trace($session, Ev::NODE_RETRY_SCHEDULED, ['error_category' => 'internal_error', 'error_message' => $message, 'scheduled_for' => $now]);
            Log::warning("WhatsAppJourneyEngine: session #{$session->id} was interrupted mid-run — parked for resume.", ['current_node_id' => $session->current_node_id]);
        }

        return $recovered;
    }

    /** Phase 7 Task 9 — how long an immediate run may take before it counts as interrupted. */
    private function runLease(): \Illuminate\Support\Carbon
    {
        return now()->addSeconds(self::RESUME_LEASE_SECONDS);
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
        if (! $this->transition($session, [
            'status' => WhatsAppFlowSession::STATUS_BLOCKED,
            'wait_until' => $clearTimer ? null : now(),
            'last_error' => JourneyRuntimeEntitlement::reason(),
        ])) {
            return;
        }

        Log::info("WhatsAppJourneyEngine: session #{$session->id} blocked — account #{$session->account_id} is not entitled to Journey automation.");
        $this->trace($session, Ev::SESSION_BLOCKED, ['error_category' => 'entitlement_blocked', 'error_message' => (string) $session->last_error]);
    }

    /** One blocked session back to its open status (conditional: never revives anything else). */
    private function unblock(WhatsAppFlowSession $session): bool
    {
        return $this->restoreBlocked(WhatsAppFlowSession::query()->whereKey($session->id)) > 0;
    }

    private function restoreBlocked(\Illuminate\Database\Eloquent\Builder $scope): int
    {
        // Phase 7 Task 9 — one guarded UPDATE per session (was two bulk
        // UPDATEs + a re-read): exactly the caller that restored a session
        // records its event, even with several restorers racing (proven on
        // MariaDB by tests/Probes/journey_concurrency_probe.php). Where it
        // goes back to is the Task 1.6 timer invariant, unchanged.
        $blocked = (clone $scope)->where('status', WhatsAppFlowSession::STATUS_BLOCKED)
            ->get(['id', 'account_id', 'flow_id', 'flow_version_id', 'current_node_id', 'status', 'attempts', 'wait_until']);

        $restored = 0;

        foreach ($blocked as $session) {
            $to = $session->wait_until !== null ? WhatsAppFlowSession::STATUS_WAITING : WhatsAppFlowSession::STATUS_ACTIVE;

            $written = WhatsAppFlowSession::query()->whereKey($session->id)
                ->where('status', WhatsAppFlowSession::STATUS_BLOCKED)
                ->when($to === WhatsAppFlowSession::STATUS_WAITING, fn ($q) => $q->whereNotNull('wait_until'), fn ($q) => $q->whereNull('wait_until'))
                ->update(['status' => $to, 'last_error' => null]);

            if ($written === 1) {
                $restored++;
                $session->forceFill(['status' => $to])->syncOriginal();
                $this->trace($session, Ev::SESSION_RESTORED, ['result' => $to]);
            }
        }

        return $restored;
    }

    /**
     * After a resumed run: a session that left 'waiting' carries no timer
     * state. Phase 7 Task 6: an EXPIRED session keeps its last_error (the
     * step-limit / non-executable reason) — it used to be wiped here.
     */
    private function settle(WhatsAppFlowSession $session): string
    {
        if (in_array($session->status, [WhatsAppFlowSession::STATUS_COMPLETED, WhatsAppFlowSession::STATUS_ACTIVE], true)) {
            $session->forceFill(['wait_until' => null, 'attempts' => 0, 'last_error' => null])->save();
        } elseif ($session->status === WhatsAppFlowSession::STATUS_EXPIRED) {
            $session->forceFill(['wait_until' => null, 'attempts' => 0])->save();
        }

        return $session->status;
    }

    private function retryOrFail(WhatsAppFlowSession $session, Throwable $failure): string
    {
        $error = $failure->getMessage();
        $category = JourneyExecutionRecorder::categoryOf($failure);
        $session->refresh();
        $this->trace($session, Ev::NODE_FAILED, ['node_type' => $this->nodeType, 'error_category' => $category, 'error_message' => $error, 'details' => ['dispatch_log_id' => $this->lastDispatchLogId]]);

        if ($session->status === WhatsAppFlowSession::STATUS_CANCELLED) {
            return WhatsAppFlowSession::STATUS_CANCELLED;
        }

        if ($session->attempts >= self::MAX_RESUME_ATTEMPTS || ($failure instanceof JourneyStepFailed && ! $failure->retryable)) {
            return $this->failSession($session, $error, $category, atNode: false);
        }

        if (! $this->transition($session, [
            'status' => WhatsAppFlowSession::STATUS_WAITING,
            'wait_until' => now()->addSeconds(self::RETRY_BACKOFF_SECONDS * (2 ** max(0, $session->attempts - 1))),
            'last_error' => mb_substr($error, 0, 500),
        ])) {
            return $session->status;
        }

        $this->trace($session, Ev::NODE_RETRY_SCHEDULED, ['node_type' => $this->nodeType, 'error_category' => $category, 'error_message' => $error, 'scheduled_for' => $session->wait_until]);

        Log::warning("WhatsAppJourneyEngine: resumed session #{$session->id} failed a step — retry scheduled.", ['attempt' => $session->attempts, 'error' => $error]);

        return 'retrying';
    }

    /**
     * Phase 7 Task 7: $category is the normalised failure category for the
     * execution history; $atNode records the node_failed event too (false
     * when it is already recorded, or the failure is not a node's).
     */
    private function failSession(WhatsAppFlowSession $session, string $error, string $category = 'internal_error', bool $atNode = true): string
    {
        if (! $this->transition($session, [
            'status' => WhatsAppFlowSession::STATUS_FAILED,
            'wait_until' => null,
            'last_error' => mb_substr($error, 0, 500),
        ])) {
            return $session->status;
        }

        if ($atNode) {
            $this->trace($session, Ev::NODE_FAILED, ['node_type' => $this->nodeType, 'error_category' => $category, 'error_message' => $error]);
        }

        $this->trace($session, Ev::SESSION_FAILED, ['node_type' => $this->nodeType, 'error_category' => $category, 'error_message' => $error]);

        Log::warning("WhatsAppJourneyEngine: session #{$session->id} marked failed.", ['error' => $error]);

        return WhatsAppFlowSession::STATUS_FAILED;
    }

    /**
     * Phase 7 Task 6 — THE way a running session changes status. One
     * conditional UPDATE (compare-and-set: "… WHERE id = ? AND status IN
     * (active, waiting)"), atomic on its own and lock-free, so a session
     * that meanwhile ended (completed / failed / expired / cancelled) or was
     * blocked is never revived, overwritten or "completed" by a run still in
     * flight. Everything unsaved on the model (e.g. an answer just captured)
     * is written in the same statement. On refusal nothing is written and
     * the in-memory status is synced to the stored one.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $from  the statuses the write is allowed from
     */
    private function transition(WhatsAppFlowSession $session, array $attributes, array $from = [WhatsAppFlowSession::STATUS_ACTIVE, WhatsAppFlowSession::STATUS_WAITING]): bool
    {
        $before = $session->getAttributes();
        $session->forceFill($attributes);
        $changes = $session->getDirty();

        $written = WhatsAppFlowSession::query()
            ->whereKey($session->id)
            ->whereIn('status', $from)
            ->update($changes === [] ? ['status' => $session->status] : $changes);

        // MySQL/MariaDB report 0 affected rows for a matched row whose values
        // did not change; a stored status still in $from is that case.
        $stored = $written === 1 ? null : WhatsAppFlowSession::query()->whereKey($session->id)->value('status');

        if ($written === 1 || in_array($stored, $from, true)) {
            $session->syncOriginal();

            return true;
        }

        $session->setRawAttributes($before);
        $session->forceFill(['status' => $stored])->syncOriginalAttribute('status');

        return false;
    }

    /**
     * Phase 7 Task 6 — SUCCESSFUL completion: the run reached a valid end
     * (a node with no outgoing connection, a branch with nothing connected,
     * or save_lead). current_node_id keeps the node it ended on; no timer,
     * no retry counter, no error.
     */
    private function complete(WhatsAppFlowSession $session): bool
    {
        $done = $this->transition($session, [
            'status' => WhatsAppFlowSession::STATUS_COMPLETED,
            'wait_until' => null,
            'attempts' => 0,
            'last_error' => null,
        ]);

        if ($done) {
            $this->trace($session, Ev::SESSION_COMPLETED, ['node_type' => $this->nodeType]);
        }

        return $done;
    }

    /** Phase 7 Task 6 — ended WITHOUT success (step limit, non-executable node, stale reply); always with a reason. */
    private function expire(WhatsAppFlowSession $session, string $reason, string $category = 'internal_error'): bool
    {
        $done = $this->transition($session, [
            'status' => WhatsAppFlowSession::STATUS_EXPIRED,
            'wait_until' => null,
            'last_error' => mb_substr($reason, 0, 500),
        ]);

        if ($done) {
            $this->trace($session, Ev::SESSION_EXPIRED, ['node_type' => $this->nodeType, 'error_category' => $category, 'error_message' => $reason]);
        }

        return $done;
    }

    /** @param array<string, mixed> $details */
    private function nodeSucceeded(WhatsAppFlowSession $session, string $result, array $details = []): void
    {
        $this->trace($session, Ev::NODE_SUCCEEDED, [
            'node_type' => $this->nodeType,
            'result' => $result,
            'details' => $details + ['dispatch_log_id' => $result === 'sent' || $result === 'prompted' || $result === 'saved' ? $this->lastDispatchLogId : null],
        ]);
    }

    /** Phase 7 Task 7 — start the execution-history context of one entry point. */
    private function beginTrace(string $source, ?int $inboundEventId = null): void
    {
        $this->source = $source;
        $this->inboundEventId = $inboundEventId;
        $this->nodeType = null;
        $this->lastDispatchLogId = null;
        $this->lastLeadRefs = [];
    }

    /** @param array<string, mixed> $attributes */
    private function trace(WhatsAppFlowSession $session, string $event, array $attributes = []): void
    {
        app(JourneyExecutionRecorder::class)->record($session, $event, $attributes + [
            'source' => $this->source,
            'inbound_event_id' => $this->inboundEventId,
        ]);
    }

    private function wasCancelled(WhatsAppFlowSession $session): bool
    {
        if (WhatsAppFlowSession::query()->whereKey($session->id)->value('status') !== WhatsAppFlowSession::STATUS_CANCELLED) {
            return false;
        }

        $session->forceFill(['status' => WhatsAppFlowSession::STATUS_CANCELLED])->syncOriginal();

        return true;
    }

    /**
     * A send that did not go out is a retryable step failure on BOTH paths
     * (Phase 7 Task 5; it used to be ignored on the immediate path, so the
     * journey carried on as if the customer had received it). $resumed is
     * kept for call-site symmetry only.
     */
    private function requireSent(bool $resumed, bool $sent, string $nodeId): void
    {
        if (! $sent) {
            throw new JourneyStepFailed("Node '{$nodeId}': message not sent — ".($this->lastSendError ?? 'unknown error').'.', $this->lastSendCategory, $this->lastSendRetryable);
        }
    }

    /**
     * Phase 7 Task 5 — the immediate (inbound / manual test) path's step
     * failure handling, reusing the Task 1 retry model rather than adding a
     * second one. A step that failed transiently (a send that did not go
     * out, a capture/CRM write that failed, any unexpected exception) used
     * to be ignored — or, for an exception, left the session open while the
     * chatbot answered instead. Now the session is parked exactly like a
     * failed resumed step: status 'waiting' at the failed node (the
     * checkpoint advance() already saved), wait_until = now + first backoff,
     * last_error set. journeys:resume-due then retries it from that node
     * through resumeDueSession() — same claim, same backoff, same
     * MAX_RESUME_ATTEMPTS, then 'failed'. Nothing downstream of the failed
     * node has run. The inbound message stays consumed by the journey.
     */
    private function runImmediate(Account $account, WhatsAppFlow $flow, WhatsAppFlowSession $session, string $nodeId): void
    {
        try {
            $this->advance($account, $flow, $session, $nodeId);
        } catch (Throwable $e) {
            $this->scheduleRetry($session, $e);
        }
    }

    private function scheduleRetry(WhatsAppFlowSession $session, Throwable|string $failure): void
    {
        $failure = is_string($failure) ? new JourneyStepFailed($failure) : $failure;
        $error = $failure->getMessage();
        $category = JourneyExecutionRecorder::categoryOf($failure);
        $session->refresh();
        $this->trace($session, Ev::NODE_FAILED, ['node_type' => $this->nodeType, 'error_category' => $category, 'error_message' => $error, 'details' => ['dispatch_log_id' => $this->lastDispatchLogId]]);

        // Phase 7 Task 8 — a refusal no retry can fix fails at once.
        if ($failure instanceof JourneyStepFailed && ! $failure->retryable) {
            $this->failSession($session, $error, $category, atNode: false);

            return;
        }

        // Only a run that was still going: a session that already ended,
        // parked on a delay or was blocked keeps that state. Phase 7 Task 6:
        // one compare-and-set write (transition()), so a cancellation landing
        // in between is never turned back into 'waiting'.
        if (! $this->transition($session, [
            'status' => WhatsAppFlowSession::STATUS_WAITING,
            'wait_until' => now()->addSeconds(self::RETRY_BACKOFF_SECONDS),
            'attempts' => 0,
            'last_error' => mb_substr($error, 0, 500),
        ], [WhatsAppFlowSession::STATUS_ACTIVE])) {
            return;
        }

        $this->trace($session, Ev::NODE_RETRY_SCHEDULED, ['node_type' => $this->nodeType, 'error_category' => $category, 'error_message' => $error, 'scheduled_for' => $session->wait_until]);

        Log::warning("WhatsAppJourneyEngine: session #{$session->id} step failed on the immediate path — retry scheduled.", [
            'current_node_id' => $session->current_node_id,
            'error' => $error,
        ]);
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
        // Phase 7 Task 10 — an 'active' session still holding a run lease is
        // an immediate run that was interrupted (Task 9): it is not awaiting
        // a reply. Leave it to recoverInterruptedRuns() (it resumes from its
        // checkpoint) and let the chatbot answer this message — it used to
        // expire the run, silently defeating the Task 9 recovery. We hold the
        // conversation lease here, so no run of this phone is in progress.
        if ($session->wait_until !== null) {
            return false;
        }

        $flow = $session->flow;

        if (! $flow || ! $flow->is_active) {
            $this->expire($session, 'The journey was deactivated or removed while waiting for a reply.', 'flow_unavailable');

            return true;
        }

        $account = Account::with('currentSubscription')->find($session->account_id);

        if (! $account) {
            $this->expire($session, 'The account no longer exists.', 'flow_unavailable');

            return true;
        }

        // Phase 7 Task 5 — same ownership guard as resumeDueSession(): a
        // session may only ever run a journey of its own account.
        if ((int) $flow->account_id !== (int) $session->account_id) {
            $this->failSession($session, 'The flow does not belong to this session\'s account.', 'internal_error', atNode: false);

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
            $this->nodeType = $node['type'] ?? null;
            $this->expire($session, "A reply arrived while the session was stopped at node '{$session->current_node_id}', which is not a question (an interrupted run).", 'internal_error');

            return true;
        }

        $data = $node['data'] ?? [];
        $trimmed = trim($incomingMessage);

        $validationError = $this->validateAnswer($trimmed, $data['validation'] ?? null);

        $this->nodeType = 'question';

        if ($validationError !== null) {
            $subscription = $account->currentSubscription;
            $this->sendText($account, $subscription, $session->phone_number, $validationError."\n\n".(string) ($data['prompt_text'] ?? ''), $flow->id);
            $this->trace($session, Ev::REPLY_RECEIVED, ['node_type' => 'question', 'result' => 'invalid', 'details' => ['dispatch_log_id' => $this->lastDispatchLogId]]);

            return true;
        }

        $variableName = (string) ($data['variable_name'] ?? '');

        if ($variableName !== '') {
            $session->setVariable($variableName, $trimmed);
        }

        $this->trace($session, Ev::REPLY_RECEIVED, ['node_type' => 'question', 'result' => 'answered']);

        $nextEdge = Collection::make($graph->outgoingEdges($node['id']))->first();
        $session->last_interaction_at = now();

        if (! $nextEdge) {
            $this->complete($session);

            return true;
        }

        // Phase 7 Task 9 — run lease, written with the first checkpoint.
        $session->wait_until = $this->runLease();
        $this->runImmediate($account, $flow, $session, (string) $nextEdge['target']);

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
            // Phase 7 Task 9 — run lease; see recoverInterruptedRuns(). The
            // trigger is the first checkpoint, so a run interrupted before
            // its first node resumes from here.
            'wait_until' => $this->runLease(),
            'current_node_id' => (string) $triggerNode['id'],
        ]);

        $this->trace($session, Ev::SESSION_STARTED, ['node_id' => (string) $triggerNode['id'], 'node_type' => 'trigger', 'result' => (string) $flow->trigger_type]);

        $this->runImmediate($account, $flow, $session, (string) $firstEdge['target']);

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
                // Phase 7 Task 4 — same expiry as before, now with a reason.
                $session->forceFill(['current_node_id' => $nodeId]);
                $this->nodeType = $graph->findNode($nodeId)['type'] ?? null;
                $this->expire($session, 'Stopped after '.self::MAX_ADVANCE_STEPS." steps in one run (likely a loop) at node '{$nodeId}'.", 'execution_limit');

                return;
            }

            $node = $graph->findNode($nodeId);

            // Phase 7 Task 5 — an edge into a node the pinned version does not
            // contain is a broken graph, not a finished journey: fail (it used
            // to complete silently).
            if (! $node) {
                $session->forceFill(['current_node_id' => $nodeId]);
                $this->nodeType = null;
                $this->failSession($session, "Node '{$nodeId}' is not in this journey version.", 'missing_node');

                return;
            }

            $type = $node['type'] ?? null;
            $data = $node['data'] ?? [];
            $this->nodeType = is_string($type) ? $type : null;

            // Phase 7 Task 5 — CHECKPOINT before every node, on both paths:
            // current_node_id is always the node about to run (and, with it,
            // any answer just captured is persisted before anything
            // downstream happens). A failure — handled, retried or a dead
            // worker — therefore always resumes at exactly this node, and
            // never replays one that already completed.
            //
            // Phase 7 Task 9 — the checkpoint is a guarded write (transition()):
            // if the session stopped running meanwhile (cancelled, blocked,
            // replaced by a test run, ended by another worker), nothing more
            // runs — on the immediate path too, at no extra query.
            if (! $this->transition($session, ['current_node_id' => $nodeId])) {
                return;
            }

            // Phase 7 Task 5 — an action whose configuration breaks the
            // contract is never run on a guess (JourneyActionConfig).
            $configError = JourneyActionConfig::error((string) $type, $data);

            if ($configError !== null) {
                $this->failSession($session, "Node '{$nodeId}' ({$type}): {$configError}", 'invalid_configuration');

                return;
            }

            // Phase 7 Task 7 — a node with a side effect (a send or a lead
            // write) is recorded BEFORE it runs, so a worker that dies
            // mid-node leaves "started, never finished" in the history. The
            // palette send nodes record it in their branch, after their own
            // run-time checks (entitlement, rendered text).
            if (in_array($type, self::SIDE_EFFECT_NODE_TYPES, true)) {
                $this->trace($session, Ev::NODE_STARTED, ['node_type' => $type]);
            }

            if ($type === 'message') {
                $sent = $this->sendText($account, $subscription, $session->phone_number, (string) ($data['text'] ?? ''), $flow->id);
                $this->requireSent($resumed, $sent, $node['id']);
                $this->nodeSucceeded($session, 'sent');

                $next = Collection::make($graph->outgoingEdges($node['id']))->first();

                if (! $next) {
                    $this->complete($session);

                    return;
                }

                // Phase 7 Task 1/5 — the next node is checkpointed before it
                // runs (top of the loop), so a retry restarts AFTER the last
                // message that went out, never sending it twice.
                $nodeId = (string) $next['target'];

                continue;
            }

            if ($type === 'delay') {
                $seconds = $this->delaySeconds($data);

                if ($seconds === null) {
                    $this->failSession($session, "Delay node '{$node['id']}' has an invalid amount/unit.", 'invalid_configuration');

                    return;
                }

                $parked = $this->transition($session, [
                    'status' => WhatsAppFlowSession::STATUS_WAITING,
                    'current_node_id' => $node['id'],
                    'wait_until' => now()->addSeconds($seconds),
                    'attempts' => 0,
                    'last_error' => null,
                    'last_interaction_at' => now(),
                ]);

                if ($parked) {
                    $this->trace($session, Ev::SESSION_WAITING, ['node_type' => 'delay', 'result' => 'delay', 'scheduled_for' => $session->wait_until]);
                }

                return;
            }

            if ($type === 'question') {
                $sent = $this->sendQuestion($account, $subscription, $session->phone_number, $data, $flow->id);
                $this->requireSent($resumed, $sent, $node['id']);
                $this->nodeSucceeded($session, 'prompted');

                $this->transition($session, [
                    'current_node_id' => $node['id'],
                    'status' => WhatsAppFlowSession::STATUS_ACTIVE,
                    'wait_until' => null,
                    'last_interaction_at' => now(),
                ]);

                return;
            }

            if ($type === 'condition' || $type === 'conditional') {
                // Phase 7 Task 4 — both branching nodes go through the one
                // evaluator, against the session's own context, on the
                // pinned version's edges. A malformed definition fails the
                // session; it is never guessed past.
                try {
                    $target = $type === 'condition'
                        ? $this->resolveConditionTarget($graph->outgoingEdges($node['id']), $data, $session)
                        : $this->resolveConditionalTarget($graph->outgoingEdges($node['id']), $data, $session);
                } catch (InvalidJourneyCondition $e) {
                    $session->forceFill(['current_node_id' => $node['id']]);
                    $this->failSession($session, "Condition node '{$node['id']}': ".$e->getMessage(), 'invalid_configuration');

                    return;
                }

                if ($target === null) {
                    // A valid dead end: no branch matched and nothing is
                    // connected as the default / to the chosen handle.
                    $this->nodeSucceeded($session, 'dead_end');
                    $this->complete($session);

                    return;
                }

                $this->nodeSucceeded($session, 'next:'.$target, ['next_node_id' => $target]);
                $nodeId = $target;

                continue;
            }

            if ($type === 'save_lead') {
                $this->lastDispatchLogId = null;
                $this->upsertLead($account, $flow, $session, $data);

                $completionMessage = trim((string) ($data['completion_message'] ?? ''));

                if ($completionMessage !== '') {
                    $sent = $this->sendText($account, $subscription, $session->phone_number, $completionMessage, $flow->id);
                    // A retry re-runs upsertLead(), which is idempotent (provider_lead_id + CRM link).
                    $this->requireSent($resumed, $sent, $node['id']);
                }

                $this->nodeSucceeded($session, 'saved', $this->lastLeadRefs);

                // Terminal by contract: save_lead's outgoing connections never run.
                $this->complete($session);

                return;
            }

            // Phase 7 Task 6 — the palette's plain send nodes: Text and the
            // four media types both WhatsApp engines already send through the
            // unified driver (WhatsAppMediaPayloadBuilder, as
            // DirectMessageDispatcher). Same contract as `message`: config
            // checked above, node entitlement re-checked here at run time,
            // one quota-guarded send, checkpoint, first outgoing edge or a
            // successful end.
            if (in_array($type, JourneyActionConfig::PALETTE_SEND_TYPES, true)) {
                // Phase 7 Task 8 — capability/provider only; the account's
                // sending state is the send gate's (retryable quota vs
                // permanent entitlement), exactly as for every other send.
                $denial = app(JourneyNodeAuthorizer::class)->runtimeDenialFor($account, (string) $type);

                if ($denial !== null) {
                    $this->failSession($session, "Node '{$nodeId}' ({$type}): {$denial}", 'entitlement_blocked');

                    return;
                }

                if ($type === 'text') {
                    $text = JourneyActionConfig::renderText((string) $data['text'], $session->context_data ?? []);

                    if (trim($text) === '') {
                        $this->failSession($session, "Node '{$nodeId}' (text): the message is empty once its variables are filled in.", 'invalid_configuration');

                        return;
                    }

                    $this->trace($session, Ev::NODE_STARTED, ['node_type' => $type]);
                    $sent = $this->sendText($account, $subscription, $session->phone_number, $text, $flow->id);
                } else {
                    $this->trace($session, Ev::NODE_STARTED, ['node_type' => $type]);
                    $sent = $this->sendMedia($account, $subscription, $session->phone_number, (string) $type, $data, $flow->id);
                }

                $this->requireSent($resumed, $sent, $node['id']);
                $this->nodeSucceeded($session, 'sent');

                $next = Collection::make($graph->outgoingEdges($node['id']))->first();

                if (! $next) {
                    $this->complete($session);

                    return;
                }

                $nodeId = (string) $next['target'];

                continue;
            }

            Log::warning("WhatsAppJourneyEngine: session #{$session->id} — unknown node type '{$type}'. Marking expired.");
            // Unchanged outcome (expired); Phase 7 Task 5 records why.
            $this->expire($session, "Node '{$nodeId}' has type '{$type}', which the engine cannot execute.", 'unsupported_node');

            return;
        }
    }

    /**
     * Legacy `condition` node (branch carried on each edge). Behaviour of
     * saved flows is preserved: edges in stored order, first match wins,
     * else the default edge, else null (dead end). Phase 7 Task 4: the
     * comparison is JourneyConditionEvaluator's; an unknown operator or an
     * unusable value now fails the node instead of silently not matching.
     *
     * @param array<int, array<string, mixed>> $edges
     * @param array<string, mixed> $data
     * @throws InvalidJourneyCondition
     */
    private function resolveConditionTarget(array $edges, array $data, WhatsAppFlowSession $session): ?string
    {
        $variable = $data['variable'] ?? null;

        if (! is_string($variable) || trim($variable) === '') {
            throw new InvalidJourneyCondition('No variable is configured to branch on.');
        }

        $actual = $session->getVariable($variable);
        $defaultTarget = null;

        foreach ($edges as $edge) {
            if (! empty($edge['is_default'])) {
                $defaultTarget = (string) $edge['target'];

                continue;
            }

            $condition = $edge['condition'] ?? null;

            if ($condition === null) {
                continue;
            }

            if (! is_array($condition)) {
                throw new InvalidJourneyCondition("Edge '".($edge['id'] ?? '?')."' has a malformed condition.");
            }

            if ($this->conditions->evaluate((string) ($condition['operator'] ?? 'equals'), $actual, $condition['value'] ?? null)) {
                return (string) $edge['target'];
            }
        }

        return $defaultTarget;
    }

    /**
     * `conditional` node: rules combined by `match` (all = AND, any = OR),
     * then exactly the edge on the resulting "true"/"false" handle.
     *
     * @param array<int, array<string, mixed>> $edges
     * @param array<string, mixed> $data
     * @throws InvalidJourneyCondition
     */
    private function resolveConditionalTarget(array $edges, array $data, WhatsAppFlowSession $session): ?string
    {
        $result = $this->conditions->evaluateAll($data['conditions'] ?? null, $data['match'] ?? null, $session->context_data ?? []);
        $handle = $result ? 'true' : 'false';

        $branch = array_values(array_filter($edges, fn (array $edge) => ($edge['sourceHandle'] ?? null) === $handle));

        if (count($branch) > 1) {
            throw new InvalidJourneyCondition("More than one connection leaves the '{$handle}' branch.");
        }

        return $branch === [] ? null : (string) $branch[0]['target'];
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
        /*
         * Phase 7 Task 5 — a CRM promotion that FAILED (the account is
         * entitled, yet no CRM lead exists) must not let the journey carry on
         * as if the lead were saved: it is a retryable step failure. The
         * retry is safe — the capture row is keyed on
         * journey:{flow}:{session} and promotion returns the existing CRM
         * lead once one exists. An account NOT entitled to the CRM is not a
         * failure: the capture is kept and recorded as `not_entitled`
         * (Task 10 contract, unchanged), and the journey continues.
         */
        $crmLead = app(CaptureLeadLinker::class)->linkQuietly($lead);
        $this->lastLeadRefs = ['lead_id' => (int) $lead->id, 'crm_lead_id' => $crmLead ? (int) $crmLead->id : null];

        if ($crmLead === null && app(CaptureLeadLinker::class)->accountMayUseCrm($lead)) {
            throw new JourneyStepFailed('Node save_lead: the lead was captured but could not be written to the CRM (recorded in crm_capture_link_failures).', 'crm_failure');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendQuestion(Account $account, ?Subscription $subscription, string $phone, array $data, ?int $flowId = null): bool
    {
        $promptText = (string) ($data['prompt_text'] ?? '');
        $inputType = $data['input_type'] ?? 'text';

        if ($inputType === 'text' || empty($data['options'])) {
            return $this->sendText($account, $subscription, $phone, $promptText, $flowId);
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

    /**
     * Phase 7 Task 6 — one media message (image/video/document/audio) via
     * the same payload builder DirectMessageDispatcher uses, so each engine
     * gets its own shape (QR: media_url fields; Meta: {type: {link}}).
     *
     * @param array<string, mixed> $data
     */
    private function sendMedia(Account $account, ?Subscription $subscription, string $phone, string $mediaType, array $data, ?int $flowId): bool
    {
        $content = [
            'media_type' => $mediaType,
            'url' => trim((string) $data['mediaUrl']),
            'caption' => $mediaType === 'audio' ? null : (($data['caption'] ?? null) ?: null),
            'filename' => $mediaType === 'document' ? (($data['filename'] ?? null) ?: null) : null,
        ];

        [$driverMessage, $metaData] = WhatsAppMediaPayloadBuilder::build($subscription?->engine_type, $content);

        return $this->send($account, $subscription, $phone, $driverMessage, $metaData, $flowId, $content['caption'] ?? "[{$mediaType}]", $content['url']);
    }

    /** @return bool false only when a send was attempted and did not go out (an empty text is "nothing to send", true). */
    private function sendText(Account $account, ?Subscription $subscription, string $phone, string $text, ?int $flowId = null): bool
    {
        if (trim($text) === '') {
            $this->lastDispatchLogId = null;

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
    private function send(Account $account, ?Subscription $subscription, string $phone, string $text, array $metaData, ?int $flowId = null, ?string $preview = null, ?string $mediaUrl = null): bool
    {
        // Phase 7 Task 6 — media sends log their attachment like every other media path.
        $preview ??= $text;
        $hasMedia = $mediaUrl !== null;

        $this->lastSendError = null;
        $this->lastDispatchLogId = null;
        $this->lastSendCategory = 'provider_failure';
        $this->lastSendRetryable = true;

        // Phase 7 Task 8 — one gate + one classification for every Journey
        // send (JourneySendGate): a temporarily exhausted/expired plan is a
        // RETRYABLE quota_failure; a suspended account or a missing
        // subscription is a PERMANENT entitlement_blocked. Same checks as
        // before (account status, subscription status, hasQuotaFor(1)), so
        // nothing that used to send is refused and nothing refused sends.
        // The dispatch-log reason keeps its pre-Task-8 wording.
        $refusal = app(JourneySendGate::class)->refusal($account, $subscription);

        if ($refusal !== null) {
            $this->lastSendError = $refusal['reason'];
            $this->lastSendCategory = $refusal['category'];
            $this->lastSendRetryable = $refusal['retryable'];
            Log::warning("WhatsAppJourneyEngine: account #{$account->id} cannot send ({$refusal['reason']}) — send skipped.");
            $this->lastDispatchLogId = (int) MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: $account->hasActiveSubscription() ? 'Quota exhausted.' : 'No active subscription.', referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $preview, hasMedia: $hasMedia, mediaUrl: $mediaUrl)->id;

            return false;
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            $this->lastSendError = $e->getMessage();
            Log::warning("WhatsAppJourneyEngine: account #{$account->id} has no usable WhatsApp engine — send skipped.", [
                'exception' => $e->getMessage(),
            ]);
            $this->lastDispatchLogId = (int) MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: $e->getMessage(), referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $preview, hasMedia: $hasMedia, mediaUrl: $mediaUrl)->id;

            return false;
        }

        $result = $driver->sendMessage($phone, $text, $metaData);

        if (empty($result['success'])) {
            $this->lastSendError = (string) ($result['error'] ?? 'The WhatsApp engine rejected the message');
            Log::warning("WhatsAppJourneyEngine: send failed for account #{$account->id}.", ['error' => $result['error'] ?? null]);
            $this->lastDispatchLogId = (int) MessageDispatchLog::record($account->id, 'journey', $phone, success: false, errorReason: $result['error'] ?? 'The WhatsApp engine rejected the message.', referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $preview, hasMedia: $hasMedia, mediaUrl: $mediaUrl)->id;

            return false;
        }

        // Phase 5 Task 3 -- same lock-then-increment block as the other
        // four consume-after sites, now shared. Still strictly
        // check -> send -> consume: a journey node that has already gone
        // out is recorded, never re-gated.
        app(MessageQuotaService::class)->consume($subscription, 1);

        $this->lastDispatchLogId = (int) MessageDispatchLog::record($account->id, 'journey', $phone, success: true, referenceType: 'whatsapp_flow', referenceId: $flowId, messagePreview: $preview, hasMedia: $hasMedia, mediaUrl: $mediaUrl, gatewayMessageId: $result['message_id'] ?? null)->id;

        return true;
    }
}
