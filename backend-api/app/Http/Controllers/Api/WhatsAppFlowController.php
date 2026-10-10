<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\InboundMessageEvent;
use App\Models\JourneyExecutionEvent;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppFlowVersion;
use App\Models\WhatsAppNumber;
use App\Services\Access\EntitlementAuditLogger;
use App\Services\Access\JourneyNodeAuthorizer;
use App\Services\WhatsApp\JourneyActionConfig;
use App\Services\WhatsApp\JourneyConditionEvaluator;
use App\Services\WhatsApp\JourneyPublishValidator;
use App\Services\WhatsApp\JourneyVersionService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\JourneySecrets;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Module 5 — No-Code WhatsApp Journey Builder. CRUD for JourneyBuilderPage.tsx's
 * canvas, plus session visibility and a live "Test Trigger" action. Same
 * ResolvesTenantAccount / never-trust-client-account_id security model
 * as ChatbotRuleController — see that controller's docblock.
 */
class WhatsAppFlowController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly JourneyNodeAuthorizer $nodeAuthorizer)
    {
    }

    /** Hard WhatsApp limit, mirrored by MAX_REPLY_BUTTONS in the frontend node registry. */
    private const MAX_REPLY_BUTTONS = 3;

    /** Mirrors DELAY_UNITS in the frontend node registry. */
    private const DELAY_UNITS = ['seconds', 'minutes', 'hours', 'days'];

    /** Mirrors the `conditional` node's declared source handles. */
    private const CONDITIONAL_HANDLES = ['true', 'false'];

    /** GET /api/whatsapp/flows */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $flows = WhatsAppFlow::forAccount($account->id)->with('whatsappNumbers:id,phone_number')->latest()->get();

        return response()->json(['data' => $flows]);
    }

    /** GET /api/whatsapp/flows/{id} — full graph_data, for opening the canvas editor. */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->with('whatsappNumbers:id,phone_number')->find($id);
        $this->abortIfMissingFlow($request, $account, $flow, $id);

        return response()->json(['data' => $flow]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $data = $this->validateFlow($request);
        $this->assertNodesEntitled($request, $account, $data['graph_data']['nodes'], $this->saveAction($request), null);
        $this->assertKnowledgeBasesOwned($account, $data['graph_data']['nodes']);
        $this->assertAgentsOwned($request, $account, $data['graph_data']['nodes']);
        $this->assertApiConnectionsOwned($account, $data['graph_data']['nodes']);
        $this->assertSubJourneysOwned($account, $data['graph_data']['nodes'], null);
        $this->assertWhatsAppNumbersOwned($account, $data['whatsapp_number_ids']);

        // P5-7 — a journey that is published (the default) must be executable.
        if ($this->publishFlag($request)) {
            $this->assertPublishable($data['graph_data'], $account, 'journey.publish', null);
        }

        $flow = new WhatsAppFlow([
            'account_id' => $account->id,
            'name' => $data['name'],
            'trigger_type' => $data['trigger_type'],
            'trigger_value' => $data['trigger_value'] ?? null,
            'graph_data' => $data['graph_data'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        // Phase 7 Task 2 — saving snapshots version 1; `publish: false` keeps it a draft.
        $flow->publishOnSave = $this->publishFlag($request);
        $flow->save();
        $flow->whatsappNumbers()->sync($data['whatsapp_number_ids']);

        return response()->json(['message' => 'Flow created.', 'data' => $flow->fresh()->load('whatsappNumbers')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        $this->abortIfMissingFlow($request, $account, $flow, $id);

        $data = $this->validateFlow($request, $flow);
        $this->assertNodesEntitled($request, $account, $data['graph_data']['nodes'], $this->saveAction($request), $flow->id);
        $this->assertKnowledgeBasesOwned($account, $data['graph_data']['nodes']);
        $this->assertAgentsOwned($request, $account, $data['graph_data']['nodes']);
        $this->assertApiConnectionsOwned($account, $data['graph_data']['nodes']);
        $this->assertSubJourneysOwned($account, $data['graph_data']['nodes'], $flow->id);
        $this->assertWhatsAppNumbersOwned($account, $data['whatsapp_number_ids']);

        // P5-7 — publishing (the default) requires an executable graph; a
        // draft save (`publish: false`) that switches the journey ON requires
        // the version it would then run (the published one) to be executable.
        if ($this->publishFlag($request)) {
            $this->assertPublishable($data['graph_data'], $account, 'journey.publish', $flow->id);
        } elseif (($data['is_active'] ?? false) && ! $flow->is_active) {
            $this->assertPublishedVersionRunnable($flow, $account);
        }

        $flow->fill([
            'name' => $data['name'],
            'trigger_type' => $data['trigger_type'],
            'trigger_value' => $data['trigger_value'] ?? null,
            'graph_data' => $data['graph_data'],
            'is_active' => $data['is_active'] ?? $flow->is_active,
        ]);
        // Phase 7 Task 2 — a changed graph becomes a NEW immutable version
        // (published unless `publish: false`); running sessions keep theirs.
        $flow->publishOnSave = $this->publishFlag($request);
        $flow->save();
        $flow->whatsappNumbers()->sync($data['whatsapp_number_ids']);

        return response()->json(['message' => 'Flow updated.', 'data' => $flow->fresh()->load('whatsappNumbers')]);
    }

    /**
     * DELETE /api/whatsapp/flows/{id} — hard delete. whatsapp_flow_sessions.flow_id
     * is cascadeOnDelete (see that migration's docblock), so a flow's
     * in-flight/historical sessions are deleted with it.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        $this->abortIfMissingFlow($request, $account, $flow, $id);

        $flow->delete();

        return response()->json(['message' => 'Flow deleted.']);
    }

    /** POST /api/whatsapp/flows/{id}/toggle — mirrors ChatbotRuleController::toggle(). */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        $this->abortIfMissingFlow($request, $account, $flow, $id);

        // P5-7 — switching a journey ON makes its published version live.
        if (! $flow->is_active) {
            $this->assertPublishedVersionRunnable($flow, $account);
        }

        $flow->forceFill(['is_active' => ! $flow->is_active])->save();

        return response()->json(['message' => 'Flow status updated.', 'data' => $flow->fresh()]);
    }

    /**
     * GET /api/whatsapp/flows/{id}/sessions — "Session management":
     * read-only visibility into who is/was walking this flow, most
     * recent first. No write actions on a session are exposed — an
     * admin can observe or (via destroy() above, cascading) discard a
     * flow's sessions, but not hand-edit one mid-conversation.
     */
    public function sessions(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        $this->abortIfMissingFlow($request, $account, $flow, $id);

        $sessions = WhatsAppFlowSession::query()
            ->where('flow_id', $flow->id)
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['data' => $sessions]);
    }

    /** Phase 7 Task 7 — execution history returned by sessionDetail() (most recent N, oldest first). */
    private const SESSION_HISTORY_LIMIT = 200;

    /**
     * GET /api/whatsapp/flows/{id}/sessions/{sessionId} — Phase 7 Task 7.
     * Read-only operational view of ONE run: its current state, why it is
     * in that state (last_error + normalised failure category), its retry
     * position (attempt / max / next attempt), and its execution history
     * (journey_execution_events, newest SESSION_HISTORY_LIMIT rows, oldest
     * first) with each inbound message's provider event key (WAMID / QR
     * message id) for correlation.
     *
     * Same gates as the sessions list (module.guard:chatbot +
     * capability.guard:journey_automation + manage-chatbot|whatsapp.view).
     * Scoped to the RESOLVED tenant: the flow, the session and every
     * history row are filtered by that account — another tenant's id is a
     * 404, never a leak. Three queries, no N+1.
     */
    public function sessionDetail(Request $request, int $id, int $sessionId): JsonResponse
    {
        $flow = $this->flowFor($request, $id);

        $session = WhatsAppFlowSession::query()
            ->forAccount($flow->account_id)
            ->where('flow_id', $flow->id)
            ->find($sessionId);
        abort_if(! $session, 404, 'Session not found.');

        $events = JourneyExecutionEvent::query()
            ->forAccount($flow->account_id)
            ->where('session_id', $session->id)
            ->orderByDesc('id')
            ->limit(self::SESSION_HISTORY_LIMIT)
            ->get(['id', 'flow_version_id', 'inbound_event_id', 'source', 'event', 'node_id', 'node_type', 'result', 'attempt', 'error_category', 'error_message', 'scheduled_for', 'details', 'created_at'])
            ->reverse()
            ->values();

        $inboundIds = $events->pluck('inbound_event_id')->filter()->unique()->values();
        $inboundKeys = $inboundIds->isEmpty() ? collect() : InboundMessageEvent::query()
            ->where('account_id', $flow->account_id)
            ->whereIn('id', $inboundIds)
            ->get(['id', 'provider', 'event_key'])
            ->keyBy('id');

        $lastFailure = $events->last(fn (JourneyExecutionEvent $e) => $e->error_category !== null);
        $retrying = $session->status === WhatsAppFlowSession::STATUS_WAITING && $session->last_error !== null;

        return response()->json(['data' => [
            'session' => $session->toArray() + [
                'error_category' => in_array($session->status, [WhatsAppFlowSession::STATUS_ACTIVE, WhatsAppFlowSession::STATUS_COMPLETED], true) ? null : $lastFailure?->error_category,
                'retry' => [
                    'retrying' => $retrying,
                    'failed_node_id' => $retrying ? $session->current_node_id : null,
                    'attempt' => (int) $session->attempts,
                    'max_attempts' => WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS,
                    'next_attempt_at' => $retrying ? $session->wait_until?->toIso8601String() : null,
                ],
            ],
            'history' => $events->map(fn (JourneyExecutionEvent $e) => $e->toArray() + [
                'inbound_event' => $e->inbound_event_id !== null && $inboundKeys->has($e->inbound_event_id)
                    ? $inboundKeys[$e->inbound_event_id]->only(['provider', 'event_key'])
                    : null,
            ])->all(),
            'history_limit' => self::SESSION_HISTORY_LIMIT,
        ]]);
    }

    /**
     * POST /api/whatsapp/flows/{id}/test — "Test Trigger". Sends REAL
     * WhatsApp messages to the given phone number by running this exact
     * flow end to end (or until it pauses at a Question node) — see
     * WhatsAppJourneyEngine::testFlow()'s docblock. NOT a dry run/
     * simulation; the frontend should warn the tenant before calling
     * this, same as MetaConfigController::testConnection()'s live-send
     * precedent elsewhere in this codebase.
     */
    public function test(Request $request, int $id, WhatsAppJourneyEngine $engine): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        $this->abortIfMissingFlow($request, $account, $flow, $id);

        $data = $request->validate([
            'phone_number' => ['required', 'string', 'max:32'],
        ]);

        $normalizedPhone = PhoneNumberNormalizer::normalize($data['phone_number']);

        if ($normalizedPhone === '') {
            return response()->json(['message' => "'{$data['phone_number']}' is not a valid phone number."], 422);
        }

        try {
            $engine->testFlow($account, $flow, $normalizedPhone);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Test started — check WhatsApp on {$normalizedPhone}."]);
    }

    /**
     * Structural validation mirrors ChatbotRuleController::validateRule()'s
     * discipline: catch a graph that would silently never run (no
     * trigger node, or a node type outside WhatsAppFlow::NODE_TYPES) at
     * SAVE time rather than only surfacing as a runtime log warning from
     * WhatsAppJourneyEngine. Does NOT validate every node's own `data`
     * shape (e.g. a question node missing variable_name) — the canvas
     * UI is expected to keep each node's own fields consistent; this is
     * a structural safety net, not full schema validation.
     *
     * @return array<string, mixed>
     */
    /**
     * POST /api/whatsapp/flows/{id}/sessions/{sessionId}/cancel — Phase 7
     * Task 1. Cancels one OPEN session (awaiting a reply, or waiting on a
     * delay) of one of this account's flows. Same scoping as sessions():
     * the flow is looked up under the resolved tenant first, then the
     * session under that flow AND account, so another tenant's (or another
     * sub-client's) session is a 404, never a cancel. 409 when the session
     * has already ended (completed/expired/failed/cancelled) — nothing is
     * changed then.
     */
    public function cancelSession(Request $request, int $id, int $sessionId, WhatsAppJourneyEngine $engine): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        $this->abortIfMissingFlow($request, $account, $flow, $id);

        $session = WhatsAppFlowSession::query()
            ->forAccount($account->id)
            ->where('flow_id', $flow->id)
            ->find($sessionId);
        abort_if(! $session, 404, 'Session not found.');

        if (! $engine->cancelSession($session)) {
            return response()->json(['message' => 'This session has already ended.', 'data' => $session->fresh()], 409);
        }

        return response()->json(['message' => 'Session cancelled.', 'data' => $session->fresh()]);
    }

    /**
     * GET /api/whatsapp/flows/{id}/versions — Phase 7 Task 2. Newest first,
     * without the graphs; `is_published` marks the one new sessions use.
     */
    public function versions(Request $request, int $id): JsonResponse
    {
        $flow = $this->flowFor($request, $id);

        $versions = WhatsAppFlowVersion::query()
            ->forAccount($flow->account_id)
            ->where('flow_id', $flow->id)
            ->orderByDesc('version')
            ->get(['id', 'flow_id', 'version', 'created_by_user_id', 'created_at'])
            ->map(fn (WhatsAppFlowVersion $v) => $v->toArray() + ['is_published' => $v->id === $flow->published_version_id]);

        return response()->json(['data' => $versions]);
    }

    /** GET /api/whatsapp/flows/{id}/versions/{versionId} — one version with its graph. */
    public function showVersion(Request $request, int $id, int $versionId): JsonResponse
    {
        $flow = $this->flowFor($request, $id);
        $version = $this->versionFor($flow, $versionId);

        return response()->json(['data' => $version->toArray() + ['is_published' => $version->id === $flow->published_version_id]]);
    }

    /**
     * POST /api/whatsapp/flows/{id}/versions/{versionId}/publish — make an
     * existing version the one NEW sessions start on (also how an older
     * version is rolled back to). Sessions already running are untouched.
     * The version's nodes are re-checked against the account's CURRENT
     * entitlements first, exactly as a save is.
     */
    public function publishVersion(Request $request, int $id, int $versionId, JourneyVersionService $versions): JsonResponse
    {
        $account = $this->account($request);
        $flow = $this->flowFor($request, $id);
        $version = $this->versionFor($flow, $versionId);

        $this->assertNodesEntitled($request, $account, $version->nodes(), 'journey.publish', $flow->id);
        $this->assertPublishable($version->graph_data ?? [], $account, 'journey.publish', $flow->id);

        $versions->publish($flow, $version);

        return response()->json(['message' => "Version {$version->version} published.", 'data' => $flow->fresh()]);
    }

    private function flowFor(Request $request, int $id): WhatsAppFlow
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        $this->abortIfMissingFlow($request, $account, $flow, $id);

        return $flow;
    }

    private function versionFor(WhatsAppFlow $flow, int $versionId): WhatsAppFlowVersion
    {
        $version = WhatsAppFlowVersion::query()
            ->forAccount($flow->account_id)
            ->where('flow_id', $flow->id)
            ->find($versionId);
        abort_if(! $version, 404, 'Version not found.');

        return $version;
    }

    /** Optional `publish` flag on create/update (default true = pre-versioning behaviour). */
    private function publishFlag(Request $request): bool
    {
        $request->validate(['publish' => ['sometimes', 'boolean']]);

        return $request->boolean('publish', true);
    }

    /**
     * Phase 8 Task 10 — a `rag` node may only name a knowledge base of the
     * journey's OWN (resolved target) account. Another account's id is
     * answered exactly like a missing one (no existence leak). The engine
     * checks again at run time (KnowledgeRetriever resolves the id inside
     * the session's account), so a knowledge base deleted later fails the
     * node rather than reaching anyone else's data.
     *
     * @param  array<int, mixed>  $nodes
     */
    private function assertKnowledgeBasesOwned(Account $account, array $nodes): void
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            $id = is_array($node) && ($node['type'] ?? null) === 'rag' && is_array($node['data'] ?? null) ? ($node['data']['knowledgeBaseId'] ?? null) : null;

            if ($id === null || $id === '') {
                continue;
            }

            if (! JourneyActionConfig::positiveId($id) || ! \App\Models\KnowledgeBase::query()->forAccount((int) $account->id)->whereKey((int) $id)->exists()) {
                $errors["graph_data.nodes.{$i}.data.knowledgeBaseId"] = ['Knowledge base not found.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Phase 8 Task 11 — an `agent` node's registeredAgentId must name an AI
     * agent of the journey's OWN (target) account; another account's id is
     * reported exactly like a missing one. The saving user must also be
     * allowed to use every tool of that agent's current version (a journey
     * must not become a way to run a tool its author may not grant). The
     * engine resolves the agent again at run time inside the session's
     * account, so this is a save-time guard, not the only one.
     *
     * @param  array<int, mixed>  $nodes
     */
    private function assertAgentsOwned(Request $request, Account $account, array $nodes): void
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            $id = is_array($node) && ($node['type'] ?? null) === 'agent' && is_array($node['data'] ?? null) ? ($node['data']['registeredAgentId'] ?? null) : null;

            if ($id === null || $id === '') {
                continue;
            }

            $agent = JourneyActionConfig::positiveId($id) ? \App\Models\AiAgent::query()->forAccount((int) $account->id)->find((int) $id) : null;

            if ($agent === null) {
                $errors["graph_data.nodes.{$i}.data.registeredAgentId"] = ['AI agent not found.'];

                continue;
            }

            foreach ($agent->currentVersion()?->toolNames() ?? [] as $toolName) {
                $permission = app(\App\Services\Ai\Agents\Tools\ToolRegistry::class)->get($toolName)?->permission();

                if ($permission !== null && ! $request->user()?->can($permission)) {
                    $errors["graph_data.nodes.{$i}.data.registeredAgentId"] = ["You are not permitted to use this AI agent's tool '{$toolName}'."];

                    break;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Phase 8 Task 15 — an `api` node's `apiConnectionId` must name a
     * JourneyApiConnection of the journey's OWN (target) account;
     * another account's id is reported exactly like a missing one — the
     * same no-existence-leak posture as assertKnowledgeBasesOwned() and
     * assertAgentsOwned(). The `api` node is not runtime-executable yet
     * (JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES), so there is no
     * "again at run time" check to add here — this save-time guard is
     * the only one, and will stay the only one until an engine for this
     * node type exists.
     *
     * @param  array<int, mixed>  $nodes
     */
    private function assertApiConnectionsOwned(Account $account, array $nodes): void
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            $id = is_array($node) && ($node['type'] ?? null) === 'api' && is_array($node['data'] ?? null) ? ($node['data']['apiConnectionId'] ?? null) : null;

            if ($id === null || $id === '') {
                continue;
            }

            if (! JourneyActionConfig::positiveId($id) || ! \App\Models\JourneyApiConnection::query()->forAccount((int) $account->id)->whereKey((int) $id)->exists()) {
                $errors["graph_data.nodes.{$i}.data.apiConnectionId"] = ['API connection not found.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Task 25 — a 'journey' node's Static-mode `journeyId` must name a
     * WhatsAppFlow of the journey's OWN (target) account; another
     * account's id is reported exactly like a missing one, the same
     * no-existence-leak posture as assertKnowledgeBasesOwned()/
     * assertAgentsOwned()/assertApiConnectionsOwned() above. A journey
     * may not point at itself (there is no runtime yet to detect that
     * loop, so it is refused here instead of being saved as a dead
     * end). Dynamic-mode nodes (`journeyIdTemplate`) name no id here —
     * they are resolved, if ever, at run time — so this check is a
     * no-op for them. Like assertApiConnectionsOwned(), this node is
     * not runtime-executable yet (JourneyNodeCatalog::
     * RUNTIME_EXECUTABLE_TYPES), so this save-time guard is the only
     * one, and will stay the only one until an engine for this node
     * type exists.
     *
     * @param  array<int, mixed>  $nodes
     */
    private function assertSubJourneysOwned(Account $account, array $nodes, ?int $excludeFlowId): void
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'journey' || ! is_array($node['data'] ?? null)) {
                continue;
            }

            $data = $node['data'];

            if (($data['mode'] ?? 'static') !== 'static') {
                continue;
            }

            $id = $data['journeyId'] ?? null;

            if ($id === null || $id === '') {
                continue;
            }

            if (! JourneyActionConfig::positiveId($id)) {
                $errors["graph_data.nodes.{$i}.data.journeyId"] = ['Journey not found.'];

                continue;
            }

            if ($excludeFlowId !== null && (int) $id === $excludeFlowId) {
                $errors["graph_data.nodes.{$i}.data.journeyId"] = ['A journey cannot run itself.'];

                continue;
            }

            if (! WhatsAppFlow::query()->forAccount((int) $account->id)->whereKey((int) $id)->exists()) {
                $errors["graph_data.nodes.{$i}.data.journeyId"] = ['Journey not found.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Channel-binding gate, ownership half: every id the client sent must
     * actually be one of THIS account's WhatsAppNumber slots. Same
     * never-trust-client-ids pattern as assertApiConnectionsOwned() above
     * — a cross-tenant id is rejected, not silently dropped.
     *
     * @param  array<int, mixed>  $whatsappNumberIds
     */
    private function assertWhatsAppNumbersOwned(Account $account, array $whatsappNumberIds): void
    {
        $owned = WhatsAppNumber::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $whatsappNumberIds)
            ->pluck('id')
            ->all();

        $missing = array_diff($whatsappNumberIds, $owned);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'whatsapp_number_ids' => ['One or more selected channels are not on this account.'],
            ]);
        }
    }

    private function validateFlow(Request $request, ?WhatsAppFlow $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trigger_type' => ['required', 'string', Rule::in(WhatsAppFlow::TRIGGER_TYPES)],
            'trigger_value' => ['nullable', 'string', 'max:255'],
            'graph_data' => ['required', 'array'],
            'graph_data.nodes' => ['required', 'array', 'min:1'],
            'graph_data.edges' => ['required', 'array'],
            'is_active' => ['nullable', 'boolean'],
            // Channel-binding gate (Connexxa parity) — see
            // WhatsAppFlow::MAX_CHANNELS and the whatsapp_flow_numbers
            // migration's docblock. Required on both create and update:
            // a journey can never end up with zero channels, matching
            // ConnexxaIQ, which will not let a journey be built at all
            // without first picking one.
            'whatsapp_number_ids' => ['required', 'array', 'min:1', 'max:'.WhatsAppFlow::MAX_CHANNELS],
            'whatsapp_number_ids.*' => ['integer'],
        ]);

        $nodes = $data['graph_data']['nodes'];
        $errors = [];

        $triggerNodes = array_filter($nodes, fn ($n) => ($n['type'] ?? null) === 'trigger');

        if (count($triggerNodes) !== 1) {
            $errors['graph_data.nodes'] = ['A flow must have exactly one Trigger node.'];
        }

        foreach ($nodes as $i => $node) {
            $type = $node['type'] ?? null;

            if (! in_array($type, WhatsAppFlow::NODE_TYPES, true)) {
                $errors["graph_data.nodes.{$i}.type"] = ["Unknown node type '{$type}'."];

                // No point config-checking a node whose type is not ours.
                continue;
            }

            if (empty($node['id'])) {
                $errors["graph_data.nodes.{$i}.id"] = ['Every node requires an id.'];
            }

            foreach ($this->nodeConfigErrors($i, $type, $node) as $key => $messages) {
                $errors[$key] = $messages;
            }
        }

        // Phase 5 -- branch identity must be explicit on the wire, not
        // inferred from canvas geometry. See nodeConfigErrors()' docblock.
        foreach ($this->branchErrors($nodes, $data['graph_data']['edges'] ?? []) as $key => $messages) {
            $errors[$key] = $messages;
        }

        // Phase 8 Task 16 — same "branch identity must be explicit"
        // requirement, for the classifier node's fixed branch slots.
        // Kept as its own method rather than folded into branchErrors()
        // above, so the conditional node's existing, already-tested
        // behaviour is not touched by this change at all.
        foreach ($this->classifierBranchErrors($nodes, $data['graph_data']['edges'] ?? []) as $key => $messages) {
            $errors[$key] = $messages;
        }

        // Connexxa-parity multi-group Conditional (IF/ELSE-IF/ELSE) — same
        // "branch identity must be explicit" requirement as above, for a
        // conditional node's data.groups branch slots. Sibling to
        // classifierBranchErrors() rather than a branchErrors() change, so
        // the legacy true/false conditional node's validation is untouched.
        foreach ($this->conditionalGroupBranchErrors($nodes, $data['graph_data']['edges'] ?? []) as $key => $messages) {
            $errors[$key] = $messages;
        }

        // Task 24 — Connexxa parity: same "branch identity must be
        // explicit" requirement, for a 'list'/'reply_button' node's
        // per-option handle slots.
        foreach ($this->interactiveOptionBranchErrors($nodes, $data['graph_data']['edges'] ?? []) as $key => $messages) {
            $errors[$key] = $messages;
        }

        // Phase 7 Task 4 — condition definitions the engine would refuse.
        foreach ($this->conditionErrors($nodes, $data['graph_data']['edges'] ?? []) as $key => $messages) {
            $errors[$key] = array_merge($errors[$key] ?? [], $messages);
        }

        // P5-6 — credentials: never in an `api` URL (it cannot be masked in
        // part), and a masked value sent back must correspond to a secret
        // this journey already stores. No message contains a value.
        foreach (JourneySecrets::urlCredentialErrors($nodes) + JourneySecrets::unresolvedMaskErrors($nodes, $existing?->graph_data) as $key => $messages) {
            $errors[$key] = array_merge($errors[$key] ?? [], $messages);
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * Phase 5 -- Journey / Automation: server-side structural checks on a
     * node's stored configuration.
     *
     * SCOPE, CHOSEN DELIBERATELY: this does NOT re-implement all 27
     * per-field schemas. A second, hand-maintained copy of the frontend
     * registry is precisely the drift this phase has spent five tasks
     * removing, and the node types here are persistable-but-not-yet-
     * executable -- a half-configured node is a draft, not a security
     * problem. What IS enforced here is the small set the brief calls out
     * as must-hold invariants, the ones a malformed or hand-crafted
     * request could otherwise persist silently:
     *
     *   - `data` must be an object, not a scalar or a list;
     *   - a reply_button node may carry at most 3 buttons (a hard
     *     WhatsApp limit, not a preference);
     *   - a delay must be a positive amount in a supported unit.
     *
     * Everything else stays where it can stay honest: field-level
     * validation in the builder UI (which the operator sees while
     * typing), and full config validation in the execution task that
     * will actually have to run the node.
     *
     * A later execution task must ALSO revalidate
     * tenant -> entitlement -> capability -> provider -> node type ->
     * configuration before running anything. Nothing the browser sends,
     * including a node's provider/capability metadata, is trusted here.
     *
     * @param array<string, mixed> $node
     * @return array<string, array<int, string>>
     */
    private function nodeConfigErrors(int $index, string $type, array $node): array
    {
        $errors = [];
        $data = $node['data'] ?? [];

        if (! is_array($data)) {
            return ["graph_data.nodes.{$index}.data" => ['Node configuration must be an object.']];
        }

        if ($type === 'reply_button') {
            $buttons = $data['buttons'] ?? [];

            if (! is_array($buttons)) {
                $errors["graph_data.nodes.{$index}.data.buttons"] = ['Buttons must be a list.'];
            } elseif (count($buttons) > self::MAX_REPLY_BUTTONS) {
                $errors["graph_data.nodes.{$index}.data.buttons"] = [
                    'WhatsApp allows at most '.self::MAX_REPLY_BUTTONS.' reply buttons.',
                ];
            }
        }

        // Phase 7 Task 5 — action nodes: refuse values that could never run
        // (wrong types, unknown input/validation types). Empty fields are a
        // savable draft; the engine fails the node if it is reached as-is.
        $actionError = JourneyActionConfig::error($type, $data, draft: true);

        if ($actionError !== null) {
            $errors["graph_data.nodes.{$index}.data"] = [$actionError];
        }

        if ($type === 'delay') {
            $amount = $data['amount'] ?? null;

            if (! is_numeric($amount) || (float) $amount <= 0) {
                $errors["graph_data.nodes.{$index}.data.amount"] = ['Delay amount must be greater than 0.'];
            }

            if (! in_array($data['unit'] ?? null, self::DELAY_UNITS, true)) {
                $errors["graph_data.nodes.{$index}.data.unit"] = [
                    'Delay unit must be one of: '.implode(', ', self::DELAY_UNITS).'.',
                ];
            }
        }

        return $errors;
    }

    /**
     * Phase 7 Task 4 — refuse condition definitions that could never be
     * evaluated safely, using the SAME rules the engine applies at run time
     * (JourneyConditionEvaluator), so a save can't produce a journey that
     * fails on its first branch:
     *
     *   - `conditional`: match must be all|any; conditions a list of
     *     objects; every stated operator known; numeric operators need a
     *     numeric value. An empty list / empty variable is still a
     *     savable draft (the engine fails it if it is ever reached).
     *   - legacy `condition`: every edge condition must use a known
     *     operator with a usable value, and at most ONE edge may be the
     *     default branch (several used to mean "the last one").
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $edges
     * @return array<string, array<int, string>>
     */
    private function conditionErrors(array $nodes, array $edges): array
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            $type = $node['type'] ?? null;
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            if ($type === 'conditional') {
                // Multi-group mode (Connexxa parity): a non-empty data.groups
                // is validated by conditionGroupsErrors() instead of the
                // legacy single-list check below — the two are mutually
                // exclusive on the wire, same switch the engine itself uses
                // in WhatsAppJourneyEngine::resolveConditionalTarget().
                if (! empty($data['groups'])) {
                    $problems = JourneyConditionEvaluator::conditionGroupsErrors($data['groups'], requireRules: false);

                    if ($problems !== []) {
                        $errors["graph_data.nodes.{$i}.data.groups"] = $problems;
                    }
                } else {
                    $problems = JourneyConditionEvaluator::conditionListErrors($data['conditions'] ?? [], $data['match'] ?? null, requireRules: false);

                    if ($problems !== []) {
                        $errors["graph_data.nodes.{$i}.data.conditions"] = $problems;
                    }
                }
            }

            if ($type !== 'condition' || empty($node['id'])) {
                continue;
            }

            $defaults = 0;

            foreach ($edges as $j => $edge) {
                if (($edge['source'] ?? null) !== $node['id']) {
                    continue;
                }

                if (! empty($edge['is_default'])) {
                    $defaults++;

                    continue;
                }

                if (! array_key_exists('condition', $edge) || $edge['condition'] === null) {
                    continue;
                }

                $condition = $edge['condition'];
                $problem = is_array($condition)
                    ? JourneyConditionEvaluator::definitionError($condition['operator'] ?? 'equals', $condition['value'] ?? null)
                    : 'A branch condition must be an object.';

                if ($problem !== null) {
                    $errors["graph_data.edges.{$j}.condition"] = [$problem];
                }
            }

            if ($defaults > 1) {
                $errors["graph_data.nodes.{$i}.default_branch"] = ['A Condition node can have only one default ("else") branch.'];
            }
        }

        return $errors;
    }

    /**
     * A branching node's outgoing edges must name the branch they leave
     * from. `conditional` declares 'true' and 'false'; an edge without
     * one of those is ambiguous, and guessing at execution time is
     * exactly the failure mode explicit handles exist to prevent.
     *
     * The LEGACY 'condition' node is untouched: its branches live on the
     * edge itself (edge.condition / edge.is_default) and always have, so
     * every saved flow keeps validating.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $edges
     * @return array<string, array<int, string>>
     */
    private function branchErrors(array $nodes, array $edges): array
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            if (($node['type'] ?? null) !== 'conditional' || empty($node['id'])) {
                continue;
            }

            // Connexxa-parity multi-group mode governs its own branch
            // identity in conditionalGroupBranchErrors() below — this
            // legacy true/false check must not also run against it, or a
            // groups-mode node would be required to satisfy BOTH handle
            // vocabularies at once.
            if (! empty($node['data']['groups'])) {
                continue;
            }

            foreach ($edges as $edge) {
                if (($edge['source'] ?? null) !== $node['id']) {
                    continue;
                }

                if (! in_array($edge['sourceHandle'] ?? null, self::CONDITIONAL_HANDLES, true)) {
                    $errors["graph_data.nodes.{$i}.sourceHandle"] = [
                        'Every connection leaving a Conditional node must declare sourceHandle "true" or "false".',
                    ];

                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Connexxa-parity multi-group Conditional (IF/ELSE-IF/ELSE) — every
     * edge leaving a 'conditional' node that is in multi-group mode
     * (non-empty data.groups) must declare sourceHandle naming one of
     * that node's actual group handles (JourneyActionConfig::
     * CONDITIONAL_GROUP_HANDLES, truncated to the node's own group count)
     * or JourneyActionConfig::CONDITIONAL_ELSE_HANDLE. Mirrors
     * classifierBranchErrors()'s precedent; kept as a sibling to
     * branchErrors() rather than folded into it, so the legacy true/false
     * conditional node's validation (above) is untouched — see that
     * method's new skip-guard for the other half of this split.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     * @return array<string, array<int, string>>
     */
    private function conditionalGroupBranchErrors(array $nodes, array $edges): array
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            if (($node['type'] ?? null) !== 'conditional' || empty($node['id'])) {
                continue;
            }

            $groups = $node['data']['groups'] ?? null;

            if (empty($groups) || ! is_array($groups)) {
                continue;
            }

            $allowedHandles = array_merge(
                array_slice(JourneyActionConfig::CONDITIONAL_GROUP_HANDLES, 0, count($groups)),
                [JourneyActionConfig::CONDITIONAL_ELSE_HANDLE],
            );

            foreach ($edges as $edge) {
                if (($edge['source'] ?? null) !== $node['id']) {
                    continue;
                }

                if (! in_array($edge['sourceHandle'] ?? null, $allowedHandles, true)) {
                    $errors["graph_data.nodes.{$i}.sourceHandle"] = [
                        'Every connection leaving a Conditional node\'s branch must declare which branch it is.',
                    ];

                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Phase 8 Task 16 — every edge leaving a 'classifier' node must
     * declare sourceHandle naming one of JourneyActionConfig::
     * CLASSIFIER_BRANCH_HANDLES, exactly like branchErrors() already
     * requires for 'conditional' (see that method's docblock for why
     * explicit branch identity matters). A sibling method, not a change
     * to branchErrors() itself — see this task's call site above.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     * @return array<string, array<int, string>>
     */
    private function classifierBranchErrors(array $nodes, array $edges): array
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            if (($node['type'] ?? null) !== 'classifier' || empty($node['id'])) {
                continue;
            }

            foreach ($edges as $edge) {
                if (($edge['source'] ?? null) !== $node['id']) {
                    continue;
                }

                if (! in_array($edge['sourceHandle'] ?? null, JourneyActionConfig::CLASSIFIER_BRANCH_HANDLES, true)) {
                    $errors["graph_data.nodes.{$i}.sourceHandle"] = [
                        'Every connection leaving a Classifier node must declare which branch it is.',
                    ];

                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Task 24 — Connexxa parity: every edge leaving a 'list' node must
     * declare sourceHandle naming one of JourneyActionConfig::
     * LIST_ROW_HANDLES, truncated to that node's own row count (rows
     * flattened across all its sections, in document order — the SAME
     * order WhatsAppJourneyEngine::flattenListRows()/sendListNode() use,
     * so position i always means the same row on both sides); every
     * edge leaving a 'reply_button' node must declare one of
     * REPLY_BUTTON_HANDLES, truncated to its own button count. Mirrors
     * classifierBranchErrors()'s precedent; kept as its own method
     * rather than folded into branchErrors() (which only ever governed
     * 'conditional') or classifierBranchErrors() (only 'classifier').
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     * @return array<string, array<int, string>>
     */
    private function interactiveOptionBranchErrors(array $nodes, array $edges): array
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            $type = $node['type'] ?? null;

            if (($type !== 'list' && $type !== 'reply_button') || empty($node['id'])) {
                continue;
            }

            $nodeData = is_array($node['data'] ?? null) ? $node['data'] : [];

            if ($type === 'list') {
                $optionCount = 0;

                foreach (is_array($nodeData['sections'] ?? null) ? $nodeData['sections'] : [] as $section) {
                    $optionCount += is_array($section) && is_array($section['rows'] ?? null) ? count($section['rows']) : 0;
                }

                $allowedHandles = array_slice(JourneyActionConfig::LIST_ROW_HANDLES, 0, $optionCount);
                $label = 'List';
            } else {
                $optionCount = is_array($nodeData['buttons'] ?? null) ? count($nodeData['buttons']) : 0;
                $allowedHandles = array_slice(JourneyActionConfig::REPLY_BUTTON_HANDLES, 0, $optionCount);
                $label = 'Reply Buttons';
            }

            foreach ($edges as $edge) {
                if (($edge['source'] ?? null) !== $node['id']) {
                    continue;
                }

                if (! in_array($edge['sourceHandle'] ?? null, $allowedHandles, true)) {
                    $errors["graph_data.nodes.{$i}.sourceHandle"] = [
                        "Every connection leaving a {$label} node must declare which option it is.",
                    ];

                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Phase 5 Task 7 — Journey Node Capability & Provider Entitlement.
     *
     * Runs AFTER validateFlow(), deliberately, so the project's existing
     * error contract is preserved exactly:
     *
     *   malformed / unknown node / bad configuration -> 422 (ValidationException)
     *   authenticated but not entitled               -> 403 (this method)
     *
     * A 403 here means the journey is NOT persisted — this is called
     * before the create/fill, never after.
     *
     * The 403 body mirrors EnsureModuleEnabledMiddleware's shape
     * ({success, message, error_code}) rather than introducing a second
     * authorization error contract. `nodes` is added so the builder can
     * mark the offending palette entries; it carries node TYPES and
     * capability slugs only — never a credential, an account id, or any
     * other tenant data.
     *
     * $account comes from requireAccount() (TenantIsolationMiddleware's
     * resolved attribute). No provider, engine_type, plan or account
     * identifier is read from the request body at any point.
     *
     * @param array<int, array<string, mixed>> $nodes
     */
    private function assertNodesEntitled(Request $request, Account $account, array $nodes, string $action = 'journey.save', ?int $flowId = null): void
    {
        $audit = app(EntitlementAuditLogger::class);

        // Same Super-Admin bypass EnsureModuleEnabledMiddleware and
        // SubscriptionGuardMiddleware already give, for the same reason:
        // a Super Admin is never blocked by a toggle they control, even
        // while acting on a client's behalf via ?account_id=.
        if ($request->attributes->get('is_super_admin')) {
            // P5-8 — the bypass itself is an audited decision (one row).
            $audit->record($account, true, [
                'action' => $action, 'resource_type' => 'journey', 'resource_id' => $flowId, 'source' => 'api',
                'module' => 'chatbot', 'category' => 'super_admin_bypass',
            ]);

            return;
        }

        // P5-8 — one audited decision per distinct GOVERNED node type (the
        // same decide() the enforcement below uses; legacy types check
        // nothing and record nothing). denials == the denied subset, so the
        // enforced outcome is unchanged.
        $decisions = $this->nodeAuthorizer->decisionsForNodes($account, $nodes);
        $denials = [];

        foreach ($decisions as $type => $decision) {
            $audit->record($account, $decision['allowed'], [
                'action' => $action, 'resource_type' => 'journey', 'resource_id' => $flowId, 'source' => 'api',
                'module' => 'chatbot', 'node_type' => $type, 'category' => $decision['category'],
                'capability' => $decision['capability'], 'capabilities' => $decision['capabilities'],
                'provider' => $decision['provider'], 'providers' => $decision['providers'],
                'reason' => $decision['reason'], 'error_code' => $decision['allowed'] ? null : 'JOURNEY_NODE_NOT_ENTITLED',
            ]);

            if (! $decision['allowed']) {
                $denials[$type] = $decision['reason'];
            }
        }

        if ($denials === []) {
            return;
        }

        abort(response()->json([
            'success' => false,
            'message' => implode(' ', $denials),
            'error_code' => 'JOURNEY_NODE_NOT_ENTITLED',
            'nodes' => $denials,
        ], 403));
    }

    /** P5-8 — the audited action of a create/update: publishing (the default) or a draft save. */
    private function saveAction(Request $request): string
    {
        return $this->publishFlag($request) ? 'journey.publish' : 'journey.save';
    }

    /**
     * P5-8 — a journey id that is not in the resolved tenant is a 404, as
     * before. When that id DOES exist under another account, the attempt is
     * a cross-tenant access and is audited — on the ACTOR's resolved
     * account, naming the other account only inside the payload. The
     * response is identical either way (no existence oracle).
     */
    private function abortIfMissingFlow(Request $request, Account $account, ?WhatsAppFlow $flow, int $id): void
    {
        if ($flow) {
            return;
        }

        $owner = WhatsAppFlow::query()->whereKey($id)->value('account_id');

        if ($owner !== null && (int) $owner !== (int) $account->id) {
            app(EntitlementAuditLogger::class)->record($account, false, [
                'action' => 'journey.'.($request->route()?->getActionMethod() ?? 'access'),
                'resource_type' => 'journey', 'resource_id' => $id, 'source' => 'api',
                'category' => 'cross_tenant', 'target_account_id' => (int) $owner, 'http_status' => 404,
            ]);
        }

        abort(404, 'Flow not found.');
    }

    /**
     * P5-7 — 422 JOURNEY_NOT_PUBLISHABLE unless the runtime can execute this
     * graph (JourneyPublishValidator). Applies to every caller, the Super
     * Admin included: managing a tenant's journey does not make an
     * unsupported node executable.
     *
     * @param  array<string, mixed>  $graph
     */
    private function assertPublishable(array $graph, ?Account $account = null, string $action = 'journey.publish', ?int $flowId = null): void
    {
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $errors = JourneyPublishValidator::errors($nodes, is_array($graph['edges'] ?? null) ? $graph['edges'] : []);

        if ($errors === []) {
            return;
        }

        // P5-8 — audited as what it is: a publishability refusal, NOT a
        // missing entitlement. `unsupported_node` when a node cannot run,
        // otherwise `invalid_configuration`.
        $unsupported = collect($nodes)->pluck('type')->first(fn ($type) => ! \App\Support\JourneyNodeCatalog::isRuntimeExecutable($type));
        app(EntitlementAuditLogger::class)->record($account, false, [
            'action' => $action, 'resource_type' => 'journey', 'resource_id' => $flowId, 'source' => 'api',
            'module' => 'chatbot', 'category' => $unsupported !== null ? 'unsupported_node' : 'invalid_configuration',
            'node_type' => is_string($unsupported) ? $unsupported : null,
            'error_code' => 'JOURNEY_NOT_PUBLISHABLE', 'http_status' => 422,
        ]);

        abort(response()->json([
            'message' => 'This journey cannot be published: '.collect($errors)->flatten()->first(),
            'error_code' => 'JOURNEY_NOT_PUBLISHABLE',
            'errors' => $errors,
        ], 422));
    }

    /** P5-7 — activation check: the version new sessions would start on must be executable. */
    private function assertPublishedVersionRunnable(WhatsAppFlow $flow, ?Account $account = null): void
    {
        $published = $flow->publishedVersion;

        if ($published) {
            $this->assertPublishable($published->graph_data ?? [], $account, 'journey.activate', $flow->id);
        }
    }

    private function account(Request $request): Account
    {
        return $this->requireAccount(
            $request,
            'Select a client from the header to manage their WhatsApp journeys.'
        );
    }
}
