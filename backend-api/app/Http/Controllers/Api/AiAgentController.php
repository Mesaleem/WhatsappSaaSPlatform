<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\AiAgentVersion;
use App\Services\Ai\Agents\AgentRegistry;
use App\Services\Ai\AiAuthorization;
use App\Services\Ai\AiAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8 Task 11 — minimal AI Agent Registry API (/api/ai-agents).
 *
 * AUTHORIZATION (no new model): the route requires permission:manage-chatbot
 * (auth:sanctum + tenant.isolation + subscription.guard from its group), and
 * EVERY action first runs AiAuthorizer::forRequest($request, 'chatbot',
 * 'manage-chatbot') — target account as TenantIsolationMiddleware resolved it
 * (tenant: own; agent: own/sub-client; Super Admin: a selected client is
 * REQUIRED and gets no entitlement bypass), account active, subscription
 * current, the chatbot module, the permission, and the `ai` capability on
 * the TARGET account. Every agent id from the URL is resolved inside that
 * account only (another account's id → 404, indistinguishable from missing);
 * no request field can name an account. Tool grants are additionally checked
 * per tool (AgentRegistry). Nothing here calls a model or charges credits.
 */
class AiAgentController extends Controller
{
    public const MODULE = 'chatbot';

    public const PERMISSION = 'manage-chatbot';

    public function __construct(private readonly AiAuthorizer $authorizer, private readonly AgentRegistry $agents)
    {
    }

    private function authorizeTarget(Request $request): AiAuthorization
    {
        return $this->authorizer->forRequest($request, self::MODULE, self::PERMISSION, 'manual');
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;

        $agents = AiAgent::query()->forAccount((int) $account->id)->orderBy('name')->limit(200)->get();

        return response()->json(['data' => $agents->map(fn (AiAgent $agent) => $this->agents->present($agent))->values()]);
    }

    public function tools(Request $request): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;

        return response()->json(['data' => $this->agents->availableTools($account, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;
        $data = $request->validate($this->rules(true));

        $agent = $this->agents->create($account, $request->user(), $data);

        return response()->json(['data' => $this->agents->present($agent)], 201);
    }

    public function show(Request $request, string $agent): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;

        return response()->json(['data' => $this->agents->present($this->agents->find($account, $agent))]);
    }

    public function update(Request $request, string $agent): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;
        $model = $this->agents->find($account, $agent);
        $data = $request->validate($this->rules(false));

        $updated = $this->agents->update($account, $model, $request->user(), array_intersect_key($data, array_flip(['name', 'description', 'instructions', 'model', 'tools', 'settings'])));

        if (array_key_exists('is_enabled', $data) && (bool) $data['is_enabled'] !== $updated->is_enabled) {
            $updated = $this->agents->setEnabled($account, $updated, $request->user(), (bool) $data['is_enabled']);
        }

        return response()->json(['data' => $this->agents->present($updated)]);
    }

    public function enable(Request $request, string $agent): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;

        return response()->json(['data' => $this->agents->present($this->agents->setEnabled($account, $this->agents->find($account, $agent), $request->user(), true))]);
    }

    public function disable(Request $request, string $agent): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;

        return response()->json(['data' => $this->agents->present($this->agents->setEnabled($account, $this->agents->find($account, $agent), $request->user(), false))]);
    }

    public function versions(Request $request, string $agent): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;
        $model = $this->agents->find($account, $agent);

        $versions = AiAgentVersion::query()->forAccount((int) $account->id)->where('ai_agent_id', $model->id)
            ->orderByDesc('version')->limit(100)
            ->get(['id', 'version', 'model', 'tools', 'settings', 'created_by_user_id', 'created_at']);

        return response()->json(['data' => $versions]);
    }

    public function destroy(Request $request, string $agent): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;
        $this->agents->delete($account, $this->agents->find($account, $agent));

        return response()->json(['message' => 'AI agent deleted.']);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'instructions' => [$required, 'string', 'max:'.(int) config('ai.agents.max_instructions_chars', 8000)],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'tools' => ['sometimes', 'array', 'max:20'],
            'tools.*' => ['string', 'max:64'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.*' => ['integer'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
