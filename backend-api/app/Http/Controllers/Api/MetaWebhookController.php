<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAlert;
use App\Models\WhatsAppSession;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    /**
     * GET /api/webhooks/meta
     *
     * Meta's one-time subscription handshake. Called by Meta when the
     * webhook URL is registered in the App Dashboard (and whenever Meta
     * re-verifies it). PHP converts dotted query params to underscores,
     * so hub.mode/hub.verify_token/hub.challenge arrive as hub_mode/
     * hub_verify_token/hub_challenge.
     *
     * There is no account context on this request (Meta does not send one
     * at verification time), so the presented verify token is checked
     * against every configured tenant. Tokens are per-tenant random
     * strings (Str::random(40)), so a match is effectively unambiguous.
     */
    public function verify(Request $request): Response|JsonResponse
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || ! $token) {
            return response()->json(['message' => 'Invalid webhook verification request.'], 403);
        }

        $matches = WhatsAppSession::query()
            ->whereNotNull('meta_webhook_verify_token')
            ->where('meta_webhook_verify_token', $token)
            ->exists();

        if (! $matches) {
            Log::warning('Meta webhook verification failed: no matching verify token.');

            return response()->json(['message' => 'Verification token mismatch.'], 403);
        }

        // Meta requires the raw challenge string echoed back, unmodified,
        // with a 200 status and no JSON wrapping.
        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * POST /api/webhooks/meta
     *
     * Inbound event delivery: message status updates (sent/delivered/read/
     * failed) and, as of Module 10, actual inbound messages (value.
     * messages[]), routed into ChatbotEngineService.
     *
     * KNOWN GAP (disclosed): there is no messages/message-log table in
     * this codebase yet, so statuses are logged, not persisted. A future
     * module should introduce that table and replace the Log::info calls
     * below with real writes keyed on the Meta `id` (WAMID).
     *
     * KNOWN GAP (disclosed): Meta's X-Hub-Signature-256 header is not
     * verified here because no App Secret field was specified/stored by
     * this module (only Phone Number ID / WABA ID / Access Token). Adding
     * signature verification requires storing the Meta App Secret and
     * should be treated as a required hardening item before production
     * use — see the Module 5 report.
     *
     * Module 9 addition: a 'delivered' status is correlated back to the
     * payment_alerts row that produced this WAMID (payment_alerts.
     * gateway_message_id, set by ProcessPaymentAlertJob on send) and, on a
     * match, fires a 'message.delivered' outbound webhook event. This only
     * works for accounts on the Meta engine — Baileys ('qr') has no
     * equivalent delivery-status callback wired into this backend (that
     * would require a new endpoint on qr-engine-service, which is outside
     * this module's Structural Context, the same disclosed boundary
     * Module 5 already drew for BaileysDriver::sendMessage()).
     *
     * Module 10 addition: value.messages[] entries of type 'text' are now
     * handed to ChatbotEngineService::handleInboundMessage(), resolving
     * the owning tenant via WhatsAppSession.meta_phone_number_id (the only
     * correlation key Meta's payload offers — it has no notion of our
     * account_id). Non-text inbound message types (image, audio, location,
     * button/interactive replies, etc.) are logged at debug level and NOT
     * matched against chatbot rules — the spec's matcher operates on
     * incoming TEXT, and Meta's own interactive-reply payloads use a
     * different shape (`interactive.button_reply.id` /
     * `interactive.list_reply.id`) that a future module can add explicit
     * handling for.
     */
    public function handle(Request $request): JsonResponse
    {
        $entries = $request->input('entry', []);

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                $statuses = $value['statuses'] ?? [];

                foreach ($statuses as $status) {
                    Log::info('Meta WhatsApp status update received', [
                        'wamid' => $status['id'] ?? null,
                        'status' => $status['status'] ?? null,
                        'recipient_id' => $status['recipient_id'] ?? null,
                        'timestamp' => $status['timestamp'] ?? null,
                        'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                        'errors' => $status['errors'] ?? null,
                    ]);

                    if (($status['status'] ?? null) === 'delivered' && ! empty($status['id'])) {
                        $this->fireDeliveredWebhook($status['id']);
                    }
                }

                $messages = $value['messages'] ?? [];

                if (! empty($messages)) {
                    $this->handleInboundMessages($value['metadata']['phone_number_id'] ?? null, $messages);
                }
            }
        }

        // Meta requires a fast 200 response regardless of processing
        // outcome; failures here are logged, never surfaced as non-200s,
        // to avoid Meta disabling the webhook after repeated failures.
        return response()->json(['received' => true]);
    }

    private function fireDeliveredWebhook(string $wamid): void
    {
        $alert = PaymentAlert::where('gateway_message_id', $wamid)->first();

        if (! $alert) {
            // Not every WAMID belongs to a payment_alerts row (or the
            // status callback arrived before ProcessPaymentAlertJob
            // finished persisting it) — silently skip, not an error.
            return;
        }

        WebhookDispatcher::fire($alert->account_id, 'message.delivered', [
            'id' => $alert->id,
            'account_id' => $alert->account_id,
            'recipient_phone' => $alert->recipient_phone,
            'customer_name' => $alert->customer_name,
            'amount' => (string) $alert->amount,
            'payment_ref' => $alert->payment_ref,
            'status' => 'delivered',
            'delivered_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function handleInboundMessages(?string $phoneNumberId, array $messages): void
    {
        if (! $phoneNumberId) {
            Log::warning('Meta webhook delivered inbound message(s) with no phone_number_id in metadata — cannot resolve tenant.');

            return;
        }

        $accountId = WhatsAppSession::query()
            ->where('meta_phone_number_id', $phoneNumberId)
            ->value('account_id');

        if (! $accountId) {
            Log::warning("Meta webhook: no WhatsAppSession matches phone_number_id {$phoneNumberId} — inbound message(s) dropped.");

            return;
        }

        foreach ($messages as $message) {
            $from = $message['from'] ?? null;
            $type = $message['type'] ?? null;

            if (! $from) {
                continue;
            }

            if ($type !== 'text') {
                Log::debug('Meta webhook: non-text inbound message type not matched against chatbot rules.', [
                    'account_id' => $accountId,
                    'type' => $type,
                ]);

                continue;
            }

            $body = $message['text']['body'] ?? '';

            if (trim($body) === '') {
                continue;
            }

            app(ChatbotEngineService::class)->handleInboundMessage($accountId, $from, $body);
        }
    }
}
