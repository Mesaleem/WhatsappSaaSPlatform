<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\InboundMessageEvent;
use App\Models\WhatsAppNumber;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Crm\InboundWhatsAppLeads;
use App\Services\WhatsApp\InboundReplyContext;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 10 — the Baileys-side counterpart to MetaWebhookController's
 * inbound-message handling. qr-engine-service POSTs here whenever its
 * Baileys socket receives a direct message (see its sessionManager's
 * messages.upsert listener).
 *
 * QA fixes (2026-10-14):
 *  - every message (text, and media / location / contact as a short label)
 *    is kept in the WhatsApp inbox; only text goes on to the chatbot and
 *    journeys, as before;
 *  - session_id (the number slot that RECEIVED the message) is honoured:
 *    the chatbot / journey reply goes out from that same number
 *    (InboundReplyContext), not from the account's default number;
 *  - a first message from an unknown number can open a CRM lead, when the
 *    account switched that on (CRM Sales Settings → New leads).
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
            // Phase 7 Task 3 — the Baileys message key id (msg.key.id), the
            // message's own stable WhatsApp identity: the same message
            // re-delivered keeps it, two different messages never share it.
            // Optional so an older qr-engine build keeps working (without
            // durable de-duplication — see InboundEventGate).
            'message_id' => ['nullable', 'string', 'max:128'],
            'session_id' => ['nullable', 'integer'],
            'message_type' => ['nullable', 'string', 'max:20'],
            'sender_name' => ['nullable', 'string', 'max:255'],
        ]);

        $accountId = (int) $data['account_id'];
        // The receiving slot must belong to this account; anything else is ignored.
        $numberId = isset($data['session_id'])
            ? WhatsAppNumber::query()->where('account_id', $accountId)->whereKey((int) $data['session_id'])->value('id')
            : null;
        $type = $data['message_type'] ?? 'text';

        $recorded = app(WhatsAppInbox::class)->recordInbound(
            $accountId,
            $data['sender_phone'],
            $data['message'],
            $type,
            $data['sender_name'] ?? null,
            ! empty($data['message_id']) ? 'wamsg:'.$data['message_id'] : null,
            $numberId !== null ? (int) $numberId : null,
        );
        if ($recorded !== null) {
            app(InboundWhatsAppLeads::class)->maybeCreate($accountId, $data['sender_phone'], $data['sender_name'] ?? null);
        }

        if ($type !== 'text') {
            return response()->json(['received' => true, 'chatbot_log' => null]);
        }

        $log = InboundReplyContext::run($accountId, $numberId !== null ? (int) $numberId : null, fn () => app(ChatbotEngineService::class)->handleInboundMessage(
            $accountId,
            $data['sender_phone'],
            $data['message'],
            null,
            InboundMessageEvent::PROVIDER_QR,
            ! empty($data['message_id']) ? 'wamsg:'.$data['message_id'] : null,
        ));

        return response()->json(['received' => true, 'chatbot_log' => $log]);
    }
}
