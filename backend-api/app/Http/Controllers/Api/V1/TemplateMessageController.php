<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendTemplateMessageRequest;
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
    /** POST /api/v1/messages/send-template */
    public function send(SendTemplateMessageRequest $request): JsonResponse
    {
        $accountId = $request->attributes->get('api_account_id');
        abort_if(! $accountId, 401, 'Unauthenticated.');

        $data = $request->validated();

        $result = TemplateMessageDispatcher::dispatch(
            (int) $accountId,
            $data['template_id'],
            $data['recipient_phone'],
            $data['variables'] ?? [],
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
}
