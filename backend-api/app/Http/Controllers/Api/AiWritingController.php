<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\MessageTemplate;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\MessageCopywriterService;
use App\Services\Ai\TemplateCopywriterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Basic AI writing for every client (owner, 2026-10-14: "AI token de rahe hain to kahin use
 * ho"). Uses the client's AI credits — the free daily allowance first, then the wallet — and
 * needs an active plan, the page's module and permission, but not the plan's `ai` capability
 * (AiAuthorizer::forUser(..., requireCapability: false)).
 *
 *  POST /api/alerts/message-templates/ai-generate  — a template draft for "Request a Template"
 *  POST /api/alerts/ai-write                        — a message for Send Notification (No template)
 *  POST /api/crm/leads/{id}/ai-follow-up            — a follow-up message for a CRM lead
 *
 * Every answer is a draft: nothing is saved or sent here.
 */
class AiWritingController extends Controller
{
    use ResolvesTenantAccount;

    /** An identical request inside this window is the same operation, charged once. */
    private const IMPLICIT_KEY_WINDOW_SECONDS = 10;

    public function templateDraft(Request $request, AiAuthorizer $authorizer, TemplateCopywriterService $writer): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'purpose' => ['required', 'string', 'min:5', 'max:1000'],
            'category' => ['sometimes', 'nullable', 'string', Rule::in(MessageTemplate::META_CATEGORIES)],
            'tone' => ['nullable', 'string', 'max:100'],
            'variable_keys' => ['sometimes', 'array', 'max:10'],
            'variable_keys.*' => ['string', 'max:100', 'regex:/^[a-zA-Z0-9_]+$/'],
        ], ['purpose.required' => 'Tell the AI what the message is for, for example "payment reminder with the due amount".']);

        $authorization = $authorizer->forUser($request->user(), $account->id, 'send_alert', 'send-messages', 'templates', requireCapability: false);

        return response()->json(['data' => $writer->generate(
            $authorization,
            $this->operationKey($request, 'tpl', [$account->id, $data]),
            $data['purpose'],
            $data['category'] ?? 'UTILITY',
            $data['tone'] ?? null,
            $data['variable_keys'] ?? [],
        )]);
    }

    public function message(Request $request, AiAuthorizer $authorizer, MessageCopywriterService $writer): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'purpose' => ['required_without:draft', 'nullable', 'string', 'max:1000'],
            'draft' => ['required_without:purpose', 'nullable', 'string', 'max:4096'],
            'tone' => ['nullable', 'string', 'max:100'],
            'language' => ['sometimes', Rule::in(MessageCopywriterService::LANGUAGES)],
        ], [
            'purpose.required_without' => 'Write what the message is for, or type a draft for the AI to improve.',
            'draft.required_without' => 'Write what the message is for, or type a draft for the AI to improve.',
        ]);

        $authorization = $authorizer->forUser($request->user(), $account->id, 'send_alert', 'send-messages', 'alerts', requireCapability: false);

        return response()->json(['data' => $writer->message(
            $authorization,
            $this->operationKey($request, 'msg', [$account->id, $data]),
            $data['purpose'] ?? null,
            $data['draft'] ?? null,
            $data['tone'] ?? null,
            $data['language'] ?? 'english',
        )]);
    }

    public function leadFollowUp(Request $request, int $id, AiAuthorizer $authorizer, MessageCopywriterService $writer): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'goal' => ['nullable', 'string', 'max:500'],
            'tone' => ['nullable', 'string', 'max:100'],
            'language' => ['sometimes', Rule::in(MessageCopywriterService::LANGUAGES)],
        ]);

        $lead = CrmLead::query()->forAccount($account->id)->with('contact:id,name')->find($id);
        abort_if(! $lead, 404, 'Lead not found.');

        $authorization = $authorizer->forUser($request->user(), $account->id, 'lead_crm', 'manage-crm', 'crm', requireCapability: false);

        return response()->json(['data' => $writer->followUp(
            $authorization,
            $this->operationKey($request, 'lead', [$account->id, $lead->id, $data]),
            $lead,
            $data['goal'] ?? null,
            $data['tone'] ?? null,
            $data['language'] ?? 'english',
            $account->company_name,
        )]);
    }

    /** The Idempotency-Key header when given, else the caller + exact input for a short window. */
    private function operationKey(Request $request, string $kind, array $input): string
    {
        $userId = (int) $request->user()->id;
        $header = trim((string) $request->header('Idempotency-Key', ''));

        if ($header !== '') {
            if (mb_strlen($header) > 100 || preg_match('/^[A-Za-z0-9._:\-]+$/', $header) !== 1) {
                throw ValidationException::withMessages(['Idempotency-Key' => 'The Idempotency-Key header must be 1-100 letters, digits, ".", "_", ":" or "-".']);
            }

            return "ai_write:{$kind}:u{$userId}:{$header}";
        }

        return "ai_write:{$kind}:u{$userId}:auto:".sha1((string) json_encode($input)).':'.intdiv(now()->getTimestamp(), self::IMPLICIT_KEY_WINDOW_SECONDS);
    }
}
