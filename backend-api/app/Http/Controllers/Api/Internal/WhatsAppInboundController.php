<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\Chatbot\ChatbotEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 10 — the Baileys-side counterpart to MetaWebhookController's
 * inbound-message handling. Where Meta pushes inbound messages directly
 * to our public /api/webhooks/meta endpoint, Baileys sessions run inside
 * qr-engine-service (a separate Node.js process — see the platform's
 * Structural Context), which has no direct database access of its own.
 * This endpoint is the receiving side of that gap: qr-engine-service is
 * expected to POST here whenever its Baileys socket emits an inbound
 * message event, using the SAME account_id it already reports to
 * /api/internal/whatsapp-status.
 *
 * KNOWN GAP (disclosed): qr-engine-service does not yet have an inbound
 * message listener wired to actually call this endpoint — building that
 * listener is Node.js/Baileys work inside qr-engine-service, which is
 * explicitly OUT of this module's Structural Context (the same
 * disclosed boundary drawn for BaileysDriver::sendMessage() since
 * Module 5, and for the Baileys delivery-status callback in Module 9).
 * This controller exists so that, once that listener is added, there is
 * already a correct, secured, tested receiving endpoint for it to call.
 */
class WhatsAppInboundController extends Controller
{
    /**
     * POST /api/internal/whatsapp-inbound — gated by VerifyInternalSecret,
     * not auth:sanctum (qr-engine-service has no Laravel session).
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'sender_phone' => ['required', 'string', 'max:32'],
            'message' => ['required', 'string'],
        ]);

        $log = app(ChatbotEngineService::class)->handleInboundMessage(
            (int) $data['account_id'],
            $data['sender_phone'],
            $data['message'],
        );

        return response()->json(['received' => true, 'chatbot_log' => $log]);
    }
}
