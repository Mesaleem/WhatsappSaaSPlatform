<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CommentAutomationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * CommentRulesPage's CRUD backend. Tenant resolution follows the same
 * ResolvesTenantAccount::requireAccount() convention as every other
 * tenant-scoped controller in this module (never a client-supplied
 * account_id) — see AdCampaignController's docblock for the full
 * reasoning, unchanged here.
 */
class CommentAutomationRuleController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/social/comment-rules */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $rules = CommentAutomationRule::query()->forAccount($account->id)->latest()->get();

        return response()->json(['data' => $rules->map(fn (CommentAutomationRule $r) => $this->present($r))]);
    }

    /** POST /api/social/comment-rules */
    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'public_reply_template' => ['required', 'string', 'max:2000'],
            'private_dm_template' => ['required', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rule = CommentAutomationRule::create([
            ...$data,
            'account_id' => $account->id,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Rule created.', 'data' => $this->present($rule)], 201);
    }

    /** PUT /api/social/comment-rules/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $rule = CommentAutomationRule::query()->forAccount($account->id)->findOrFail($id);

        $data = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'],
            'public_reply_template' => ['sometimes', 'string', 'max:2000'],
            'private_dm_template' => ['sometimes', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rule->update($data);

        return response()->json(['message' => 'Rule updated.', 'data' => $this->present($rule)]);
    }

    /** DELETE /api/social/comment-rules/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $rule = CommentAutomationRule::query()->forAccount($account->id)->findOrFail($id);
        $rule->delete();

        return response()->json(['message' => 'Rule deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CommentAutomationRule $rule): array
    {
        return [
            'id' => $rule->id,
            'keyword' => $rule->keyword,
            'public_reply_template' => $rule->public_reply_template,
            'private_dm_template' => $rule->private_dm_template,
            'is_active' => $rule->is_active,
            'created_at' => $rule->created_at?->toIso8601String(),
        ];
    }
}
