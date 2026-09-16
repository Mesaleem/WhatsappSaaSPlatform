<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\SendTemplateByCodeRequest;
use App\Models\MessageTemplate;
use App\Services\Groups\GroupMessageDispatcher;
use App\Services\Templates\TemplateMessageDispatcher;
use Illuminate\Http\JsonResponse;

/**
 * Dynamic Templates & Variables System — external Developer API.
 * POST /api/v1/messages/send-template, authenticated by AuthenticateApiKey
 * (X-API-KEY or Bearer <client_api_key>), exactly like
 * ExternalAlertController::sendPaymentAlert(). Shares
 * TemplateMessageDispatcher with the internal Send Alert dynamic form
 * (MessageTemplateController::send()) so both entry points enforce the
 * exact same approval/quota/variable rules.
 */
class TemplateMessageController extends Controller
{
    /**
     * POST /api/v1/messages/send-template
     *
     * Developer API: unique `template_code` -- identifies the template
     * by its human-readable template_code instead of the raw DB
     * `template_id`, scoped strictly to this API key's own account_id
     * (or a global/null-account_id system template). A template_code
     * that doesn't resolve under that scope -- wrong code, or a real
     * code that belongs to a different tenant -- gets the exact same
     * generic 404 either way, so this response can never be used to
     * enumerate another tenant's template codes.
     */
    public function send(SendTemplateByCodeRequest $request): JsonResponse
    {
        $accountId = $request->attributes->get('api_account_id');
        abort_if(! $accountId, 401, 'Unauthenticated.');

        $apiKey = $request->attributes->get('api_key');
        $data = $request->validated();

        $template = MessageTemplate::query()
            ->where('template_code', $data['template_code'])
            ->where(function ($query) use ($accountId) {
                $query->where('account_id', $accountId)
                    ->orWhereNull('account_id');
            })
            ->first();

        if (! $template) {
            return response()->json(['status' => false, 'message' => 'Invalid template_code for this account'], 404);
        }

        $result = TemplateMessageDispatcher::dispatch(
            (int) $accountId,
            $template->id,
            $data['recipient_phone'],
            $data['variables'] ?? [],
            source: 'api',
            apiKeyId: $apiKey?->id,
            mediaUrl: $data['media_url'] ?? null,
        );

        return match ($result['status']) {
            'sent' => response()->json(['status' => true, 'message' => 'Message sent.']),
            'missing_variables' => response()->json(['status' => false, 'message' => $result['message'], 'missing' => $result['missing']], 422),
            'not_found' => response()->json(['status' => false, 'message' => $result['message']], 404),
            'disconnected' => response()->json(['status' => false, 'message' => $result['message'], 'error_code' => 'WHATSAPP_DISCONNECTED'], 422),
            'quota_exhausted' => response()->json(['status' => false, 'message' => $result['message']], 403),
            default => response()->json(['status' => false, 'message' => $result['message'] ?? 'Could not send this message.'], 422),
        };
    }

    /**
     * Group Messaging Phase 4 — POST /api/v1/send-message. The
     * recipient_type-aware sibling of send() above: 'individual' delegates
     * straight to the exact same TemplateMessageDispatcher this
     * controller's send() action already uses (nothing duplicated or
     * forked for a single recipient), 'group' delegates to
     * GroupMessageDispatcher, the new atomic-quota-reservation +
     * ProcessGroupDispatchJob path. This one action owns only request
     * parsing and result-to-HTTP-response mapping; every actual business
     * rule lives in one of those two dispatchers.
     */
    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        $accountId = $request->attributes->get('api_account_id');
        abort_if(! $accountId, 401, 'Unauthenticated.');

        $apiKey = $request->attributes->get('api_key');
        $data = $request->validated();

        return $data['recipient_type'] === 'group'
            ? $this->sendToGroup((int) $accountId, $apiKey?->id, $data)
            : $this->sendToIndividual((int) $accountId, $apiKey?->id, $data);
    }

    /**
     * @param array{template_id: int, recipient_phone: string, variables?: array<string, string>, media_url?: string} $data
     */
    private function sendToIndividual(int $accountId, ?int $apiKeyId, array $data): JsonResponse
    {
        $result = TemplateMessageDispatcher::dispatch(
            $accountId,
            $data['template_id'],
            $data['recipient_phone'],
            $data['variables'] ?? [],
            source: 'api',
            apiKeyId: $apiKeyId,
            mediaUrl: $data['media_url'] ?? null,
        );

        // [Disclosed]: this endpoint's own response contract (per the
        // Phase 4 spec: 401/402/403/422 + a 200 success envelope carrying
        // dispatch_id/queued_recipients_count) is deliberately distinct
        // from send()'s older contract above — e.g. quota_exhausted is
        // 402 here (INSUFFICIENT_QUOTA) vs 403 on the pre-existing
        // /v1/messages/send-template endpoint, and success now includes
        // dispatch_id/queued_recipients_count instead of a bare
        // {status:true}. The older endpoint is left completely
        // unchanged (see send() above) — this is a new, separate
        // endpoint's contract, not a breaking change to an existing one.
        // queued_recipients_count is always 1 here even though an
        // individual send is synchronous, not queued: kept for a
        // consistent success envelope shape with the group path, per the
        // spec's literal field name.
        return match ($result['status']) {
            'sent' => response()->json([
                'success' => true,
                'dispatch_id' => $result['dispatch_log_id'] ?? null,
                'queued_recipients_count' => 1,
            ]),
            'missing_variables' => response()->json(['success' => false, 'error_code' => 'INVALID_VARIABLES', 'message' => $result['message'], 'missing' => $result['missing']], 422),
            'not_found' => response()->json(['success' => false, 'error_code' => 'TEMPLATE_NOT_APPROVED', 'message' => $result['message']], 404),
            'disconnected' => response()->json(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED', 'message' => $result['message']], 422),
            'quota_exhausted' => response()->json(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA', 'message' => $result['message']], 402),
            default => response()->json(['success' => false, 'error_code' => 'SEND_FAILED', 'message' => $result['message'] ?? 'Could not send this message.'], 422),
        };
    }

    /**
     * @param array{template_id: int, group_id: int, variables?: array<string, string>} $data
     */
    private function sendToGroup(int $accountId, ?int $apiKeyId, array $data): JsonResponse
    {
        $result = GroupMessageDispatcher::dispatch(
            $accountId,
            $data['group_id'],
            $data['template_id'],
            $data['variables'] ?? [],
            source: 'api',
            apiKeyId: $apiKeyId,
        );

        return match ($result['status']) {
            'queued' => response()->json([
                'success' => true,
                'dispatch_id' => $result['dispatch_id'],
                'queued_recipients_count' => $result['queued_recipients_count'],
            ]),
            'group_access_denied' => response()->json(['success' => false, 'error_code' => 'GROUP_ACCESS_DENIED', 'message' => $result['message']], 403),
            'empty_group' => response()->json(['success' => false, 'error_code' => 'EMPTY_GROUP', 'message' => $result['message']], 422),
            'template_not_approved' => response()->json(['success' => false, 'error_code' => 'TEMPLATE_NOT_APPROVED', 'message' => $result['message']], 422),
            'not_found' => response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => $result['message']], 404),
            'disconnected' => response()->json(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED', 'message' => $result['message']], 422),
            // [Disclosed]: 'quota_exhausted' (no active subscription at
            // all) and 'insufficient_quota' (has a subscription, but N
            // exceeds what's left) are two different conditions inside
            // GroupMessageDispatcher, but the Phase 4 spec's own
            // enumerated error list has one bucket for both ("402
            // insufficient quota") — folded together here rather than
            // inventing an unrequested third error_code.
            'quota_exhausted' => response()->json(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA', 'message' => $result['message']], 402),
            'insufficient_quota' => response()->json([
                'success' => false,
                'error_code' => 'INSUFFICIENT_QUOTA',
                'message' => "This group dispatch requires {$result['required']} credits, but your account only has {$result['remaining']} remaining credits.",
            ], 402),
            default => response()->json(['success' => false, 'error_code' => 'SEND_FAILED', 'message' => $result['message'] ?? 'Could not queue this group dispatch.'], 422),
        };
    }
}
