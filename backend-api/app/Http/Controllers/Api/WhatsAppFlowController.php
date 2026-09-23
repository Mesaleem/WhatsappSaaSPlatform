<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppFlowVersion;
use App\Services\Access\JourneyNodeAuthorizer;
use App\Services\WhatsApp\JourneyVersionService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
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

        $flows = WhatsAppFlow::forAccount($account->id)->latest()->get();

        return response()->json(['data' => $flows]);
    }

    /** GET /api/whatsapp/flows/{id} — full graph_data, for opening the canvas editor. */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        abort_if(! $flow, 404, 'Flow not found.');

        return response()->json(['data' => $flow]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $data = $this->validateFlow($request);
        $this->assertNodesEntitled($request, $account, $data['graph_data']['nodes']);

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

        return response()->json(['message' => 'Flow created.', 'data' => $flow->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        abort_if(! $flow, 404, 'Flow not found.');

        $data = $this->validateFlow($request);
        $this->assertNodesEntitled($request, $account, $data['graph_data']['nodes']);

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

        return response()->json(['message' => 'Flow updated.', 'data' => $flow->fresh()]);
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
        abort_if(! $flow, 404, 'Flow not found.');

        $flow->delete();

        return response()->json(['message' => 'Flow deleted.']);
    }

    /** POST /api/whatsapp/flows/{id}/toggle — mirrors ChatbotRuleController::toggle(). */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        abort_if(! $flow, 404, 'Flow not found.');

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
        abort_if(! $flow, 404, 'Flow not found.');

        $sessions = WhatsAppFlowSession::query()
            ->where('flow_id', $flow->id)
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['data' => $sessions]);
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
        abort_if(! $flow, 404, 'Flow not found.');

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
        abort_if(! $flow, 404, 'Flow not found.');

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

        $this->assertNodesEntitled($request, $account, $version->nodes());

        $versions->publish($flow, $version);

        return response()->json(['message' => "Version {$version->version} published.", 'data' => $flow->fresh()]);
    }

    private function flowFor(Request $request, int $id): WhatsAppFlow
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        abort_if(! $flow, 404, 'Flow not found.');

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

    private function validateFlow(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trigger_type' => ['required', 'string', Rule::in(WhatsAppFlow::TRIGGER_TYPES)],
            'trigger_value' => ['nullable', 'string', 'max:255'],
            'graph_data' => ['required', 'array'],
            'graph_data.nodes' => ['required', 'array', 'min:1'],
            'graph_data.edges' => ['required', 'array'],
            'is_active' => ['nullable', 'boolean'],
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
    private function assertNodesEntitled(Request $request, Account $account, array $nodes): void
    {
        // Same Super-Admin bypass EnsureModuleEnabledMiddleware and
        // SubscriptionGuardMiddleware already give, for the same reason:
        // a Super Admin is never blocked by a toggle they control, even
        // while acting on a client's behalf via ?account_id=.
        if ($request->attributes->get('is_super_admin')) {
            return;
        }

        $denials = $this->nodeAuthorizer->denialsForNodes($account, $nodes);

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

    private function account(Request $request): Account
    {
        return $this->requireAccount(
            $request,
            'Select a client from the header to manage their WhatsApp journeys.'
        );
    }
}
