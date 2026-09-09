<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ChatbotRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 10, requirement 1 — tenant Chatbot Rule Builder. Every rule is
 * scoped to the caller's own account_id (never accepted from the
 * client), matching the tenant-isolation convention every controller in
 * this codebase follows (ApiKeyController, WebhookSubscriptionController,
 * etc.). Account resolution goes through ResolvesTenantAccount (the
 * account_id already resolved by TenantIsolationMiddleware) rather than
 * $request->user()->account_id directly — fixes a bug where a Super
 * Admin who selected a client via the header switcher (?account_id=)
 * still got "Super Admin has no tenant account..." on every action,
 * because this controller was reading the wrong source.
 */
class ChatbotRuleController extends Controller
{
    use ResolvesTenantAccount;

    /**
     * GET /api/chatbot/rules — returned in the same order the engine
     * evaluates them (lower priority first) so the UI list doubles as a
     * literal read of the evaluation order, rather than requiring the
     * admin to mentally re-sort a "created_at desc" list.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $rules = ChatbotRule::forAccount($account->id)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $data = $this->validateRule($request);

        $rule = ChatbotRule::create([
            'account_id' => $account->id,
            'name' => $data['name'],
            'match_type' => $data['match_type'],
            'keywords' => $data['keywords'] ?? [],
            'response_type' => $data['response_type'],
            'response_payload' => $data['response_payload'],
            'priority' => $data['priority'] ?? 100,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Chatbot rule created.', 'data' => $rule], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $rule = ChatbotRule::forAccount($account->id)->find($id);
        abort_if(! $rule, 404, 'Chatbot rule not found.');

        $data = $this->validateRule($request, $rule);

        $rule->fill([
            'name' => $data['name'],
            'match_type' => $data['match_type'],
            'keywords' => $data['keywords'] ?? [],
            'response_type' => $data['response_type'],
            'response_payload' => $data['response_payload'],
            'priority' => $data['priority'] ?? $rule->priority,
            'is_active' => $data['is_active'] ?? $rule->is_active,
        ])->save();

        return response()->json(['message' => 'Chatbot rule updated.', 'data' => $rule->fresh()]);
    }

    /**
     * DELETE /api/chatbot/rules/{id} — a hard delete. Unlike API keys
     * (soft-revoked, since a revoked key is still meaningful audit
     * history), a deleted rule has no equivalent "it still matters"
     * requirement in the spec, and chatbot_logs.chatbot_rule_id is
     * nullOnDelete precisely so removing a rule never destroys the log
     * history of replies it once sent.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $rule = ChatbotRule::forAccount($account->id)->find($id);
        abort_if(! $rule, 404, 'Chatbot rule not found.');

        $rule->delete();

        return response()->json(['message' => 'Chatbot rule deleted.']);
    }

    /** POST /api/chatbot/rules/{id}/toggle — flips is_active without requiring the full update payload. */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);

        $rule = ChatbotRule::forAccount($account->id)->find($id);
        abort_if(! $rule, 404, 'Chatbot rule not found.');

        $rule->forceFill(['is_active' => ! $rule->is_active])->save();

        return response()->json(['message' => 'Chatbot rule status updated.', 'data' => $rule->fresh()]);
    }

    /**
     * Shared create/update validation. `keywords` is required for every
     * match_type EXCEPT 'fallback' (a fallback rule has nothing to
     * match against by definition — it fires only when nothing else
     * did). `response_payload`'s required inner keys depend on
     * response_type, and for match_type='regex' every keyword must be a
     * syntactically valid PCRE pattern — checked here at save time
     * (rather than only at match time in ChatbotEngineService, which
     * @-suppresses failures) so an admin gets an immediate, actionable
     * validation error instead of a silently-never-matching rule.
     */
    private function validateRule(Request $request, ?ChatbotRule $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'match_type' => ['required', 'string', Rule::in(ChatbotRule::MATCH_TYPES)],
            'keywords' => ['required_unless:match_type,fallback', 'array'],
            'keywords.*' => ['string'],
            'response_type' => ['required', 'string', Rule::in(ChatbotRule::RESPONSE_TYPES)],
            'response_payload' => ['required', 'array'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (($data['match_type'] === 'regex') && ! empty($data['keywords'])) {
            foreach ($data['keywords'] as $pattern) {
                if (@preg_match($pattern, '') === false) {
                    throw ValidationException::withMessages([
                        'keywords' => ["\"{$pattern}\" is not a valid regular expression pattern."],
                    ]);
                }
            }
        }

        $this->validateResponsePayload($data['response_type'], $data['response_payload']);

        return $data;
    }

    /**
     * Confirms response_payload carries the minimum fields
     * ChatbotEngineService::buildReply() actually reads for the given
     * response_type — see that service's class docblock for the full
     * documented shape of each type. This intentionally does not
     * validate every optional field (e.g. interactive button count is
     * silently capped by the engine at send time, not rejected here),
     * only the fields whose absence would make the reply meaningless.
     */
    private function validateResponsePayload(string $responseType, array $payload): void
    {
        $errors = match ($responseType) {
            'text' => empty(trim((string) ($payload['text'] ?? '')))
                ? ['response_payload.text' => ['A text reply requires non-empty "text".']]
                : [],
            'media' => array_filter([
                'response_payload.media_type' => in_array($payload['media_type'] ?? null, ['image', 'document', 'video', 'audio'], true)
                    ? null
                    : ['"media_type" must be one of: image, document, video, audio.'],
                'response_payload.url' => empty(trim((string) ($payload['url'] ?? '')))
                    ? ['A media reply requires a non-empty "url".']
                    : null,
            ]),
            'interactive' => array_filter([
                'response_payload.body' => empty(trim((string) ($payload['body'] ?? '')))
                    ? ['An interactive reply requires non-empty "body".']
                    : null,
                'response_payload.interactive_type' => in_array($payload['interactive_type'] ?? 'button', ['button', 'list'], true)
                    ? null
                    : ['"interactive_type" must be "button" or "list".'],
                'response_payload.buttons' => (($payload['interactive_type'] ?? 'button') === 'button' && empty($payload['buttons']))
                    ? ['A button-type interactive reply requires at least one entry in "buttons".']
                    : null,
                'response_payload.sections' => (($payload['interactive_type'] ?? 'button') === 'list' && empty($payload['sections']))
                    ? ['A list-type interactive reply requires at least one entry in "sections".']
                    : null,
            ]),
            default => [],
        };

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function account(Request $request): Account
    {
        return $this->requireAccount(
            $request,
            'Select a client from the header to manage their chatbot.'
        );
    }
}
