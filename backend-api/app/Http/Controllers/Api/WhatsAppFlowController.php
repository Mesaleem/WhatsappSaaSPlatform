<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
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

        $flow = WhatsAppFlow::create([
            'account_id' => $account->id,
            'name' => $data['name'],
            'trigger_type' => $data['trigger_type'],
            'trigger_value' => $data['trigger_value'] ?? null,
            'graph_data' => $data['graph_data'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Flow created.', 'data' => $flow], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $flow = WhatsAppFlow::forAccount($account->id)->find($id);
        abort_if(! $flow, 404, 'Flow not found.');

        $data = $this->validateFlow($request);

        $flow->fill([
            'name' => $data['name'],
            'trigger_type' => $data['trigger_type'],
            'trigger_value' => $data['trigger_value'] ?? null,
            'graph_data' => $data['graph_data'],
            'is_active' => $data['is_active'] ?? $flow->is_active,
        ])->save();

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
            }

            if (empty($node['id'])) {
                $errors["graph_data.nodes.{$i}.id"] = ['Every node requires an id.'];
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function account(Request $request): Account
    {
        return $this->requireAccount(
            $request,
            'Select a client from the header to manage their WhatsApp journeys.'
        );
    }
}
