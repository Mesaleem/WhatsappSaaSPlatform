<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\PaymentAlert;
use App\Models\SocialProviderConfig;
use App\Models\WhatsAppSession;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\PhoneNumberNormalizer;
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
     * PARTIAL FIX (disclosed): 'failed' status callbacks are now also
     * correlated back to the message_dispatch_logs row that produced the
     * WAMID (via the new gateway_message_id column — see
     * correlateFailedStatus() below) and that row's status/error_reason
     * are updated, so a post-send rejection from Meta (e.g. a template
     * blocked, a recipient who opted out, an expired 24h session window)
     * now surfaces as 'failed' in Analytics/Message Logs instead of only
     * existing in this application's own text logs. 'sent'/'delivered'/
     * 'read' status callbacks are UNCHANGED — still Log::info only, not
     * persisted — because message_dispatch_logs.status only distinguishes
     * queued/sent/failed at send time; a delivered/read RECEIPT is a
     * different kind of fact (it would need its own timestamp column(s),
     * e.g. delivered_at/read_at, without corrupting that existing
     * send-time status) and that schema decision was judged too large to
     * make unprompted here. 'delivered' still separately correlates to
     * payment_alerts.gateway_message_id exactly as before, unaffected by
     * this change.
     *
     * HARDENED (Meta Webhook HMAC-SHA256 Hardening, refactored to be
     * DB-backed): every request is now passed through
     * assertValidSignature() below BEFORE any payload parsing/processing
     * — see that method's docblock for the exact verification and bypass
     * rules, and for the [Inference] this refactor relies on (that the
     * WhatsApp Cloud API webhook and the OAuth app credential share one
     * Meta App Secret). NOTE: this verification covers ONLY this endpoint
     * (/api/webhooks/meta, WhatsApp Cloud API). SocialWebhookController's
     * separate leadgen/comment webhook (/api/social/webhook/{provider})
     * now ALSO verifies X-Hub-Signature-256 for provider === 'meta', via
     * its own assertValidSignature($request, $provider) adapted from this
     * method — see that controller's handle() docblock. It reads the SAME
     * social_provider_configs.client_secret row this method does, so no
     * new schema was needed to close that gap.
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
        $this->assertValidSignature($request);

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

                    if (($status['status'] ?? null) === 'failed' && ! empty($status['id'])) {
                        $this->correlateFailedStatus($status['id'], $status['errors'] ?? null);
                    }
                }

                $messages = $value['messages'] ?? [];

                if (! empty($messages)) {
                    $this->handleInboundMessages($value['metadata']['phone_number_id'] ?? null, $messages, $value['contacts'] ?? []);
                }
            }
        }

        // Meta requires a fast 200 response regardless of processing
        // outcome; failures here are logged, never surfaced as non-200s,
        // to avoid Meta disabling the webhook after repeated failures.
        return response()->json(['received' => true]);
    }

    /**
     * Meta Webhook HMAC-SHA256 Hardening — DB-backed refactor.
     *
     * Verifies the X-Hub-Signature-256 header Meta signs every webhook
     * delivery with, computed over the RAW (unparsed) request body. No
     * .env/config value is read for the secret any more — it is fetched
     * dynamically from social_provider_configs (provider='meta'),
     * .client_secret, via SocialProviderConfig::findByProviderCached()
     * (the same cached-lookup, cache-invalidated-on-save/delete helper
     * SocialAuthController's OAuth flow already relies on — Cache::
     * remember(3600s), so this adds no extra query per webhook delivery
     * once warm). hash_equals() is used for the comparison to avoid a
     * timing side channel on the byte-by-byte match.
     *
     * [Inference, load-bearing — flagged]: this reuses the SAME
     * client_secret column SocialAuthController uses as the Meta App's
     * OAuth client_secret, rather than a distinct field. Meta's platform
     * model has exactly one "App Secret" per Meta App (App Dashboard >
     * Settings > Basic), and that one value is documented to serve BOTH
     * as the OAuth client secret AND as the HMAC key for X-Hub-
     * Signature-256 on every webhook subscription registered under that
     * App — so this is correct as long as this platform's WhatsApp Cloud
     * API integration and its Marketing/Lead-Ads OAuth integration are
     * registered under the SAME Meta App. If they are ever split across
     * two different Meta Apps, this column would hold the wrong App's
     * secret for this endpoint and a dedicated field would be needed —
     * nothing in this codebase's schema currently distinguishes that
     * case, so it could not be verified from code alone.
     *
     * Bypass: app()->environment('local') only — local/dev convenience,
     * so a developer's own Meta App Secret does not need to be seeded
     * just to exercise this endpoint with hand-crafted test payloads. No
     * config-based off-toggle exists any more (this task removed it);
     * every other environment always verifies.
     *
     * When not bypassed:
     *   - A missing X-Hub-Signature-256 header is rejected.
     *   - An empty/unconfigured client_secret for provider='meta' (no
     *     row, or a row with a null client_secret) is treated as a
     *     misconfiguration and rejected (fail CLOSED, not open) — same
     *     reasoning as before this refactor: verifying "on" with nothing
     *     to verify against would silently accept any payload.
     *   - A signature that does not match is rejected.
     * Every rejection path logs a Log::warning() with the requester IP
     * and whether the header was present/well-formed (never the computed
     * or expected signature value, and never the secret itself), then
     * aborts with HTTP 403 — unchanged from the prior version, kept for
     * consistency with this controller's verify() method.
     */
    private function assertValidSignature(Request $request): void
    {
        if (app()->environment('local')) {
            return;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if ($header === '') {
            Log::warning('Meta webhook rejected: missing X-Hub-Signature-256 header.', [
                'ip' => $request->ip(),
            ]);

            abort(403, 'Missing webhook signature.');
        }

        $appSecret = (string) (SocialProviderConfig::findByProviderCached('meta')?->client_secret ?? '');

        if ($appSecret === '') {
            Log::warning('Meta webhook rejected: signature verification could not run — no Meta App Secret configured (social_provider_configs, provider=meta, client_secret is empty).', [
                'ip' => $request->ip(),
            ]);

            abort(403, 'Webhook signature verification is misconfigured.');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! hash_equals($expected, $header)) {
            Log::warning('Meta webhook rejected: X-Hub-Signature-256 signature mismatch.', [
                'ip' => $request->ip(),
                'header_present' => true,
                'header_length' => strlen($header),
            ]);

            abort(403, 'Invalid webhook signature.');
        }
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
     * Correlates a 'failed' Meta status callback back to the
     * message_dispatch_logs row that produced this WAMID (chatbot/
     * template/journey sends — see MessageDispatchLog::record()'s new
     * $gatewayMessageId parameter) and updates that row to status =
     * 'failed' with Meta's own error detail, so it surfaces correctly in
     * Analytics/Message Logs instead of staying 'sent' forever despite
     * Meta having rejected it after the fact.
     *
     * Deliberately does NOT touch payment_alerts here — that table has
     * its own, pre-existing gateway_message_id/status handling
     * (untouched by this method) and fireDeliveredWebhook() above already
     * has sole responsibility for it.
     *
     * Silently returns if no row matches (not every WAMID belongs to a
     * message_dispatch_logs row — e.g. it may belong to payment_alerts
     * instead, or predate this column existing) or if the row is already
     * 'failed' (idempotent against Meta's at-least-once webhook
     * redelivery).
     */
    private function correlateFailedStatus(string $wamid, ?array $errors): void
    {
        $log = MessageDispatchLog::where('gateway_message_id', $wamid)->first();

        if (! $log || $log->status === 'failed') {
            return;
        }

        $log->update([
            'status' => 'failed',
            'error_reason' => $errors[0]['title'] ?? $errors[0]['message'] ?? 'Meta reported this message as failed.',
        ]);
    }

    /**
     * Step 4 (Click-to-WhatsApp Ads) addition: $contacts is the sibling
     * value.contacts[] array from the same webhook payload (WhatsApp
     * Cloud API sends the sender's profile name there, keyed by wa_id) —
     * used only to enrich a captured CTWA lead's lead_name; unrelated to
     * chatbot routing below.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $contacts
     */
    private function handleInboundMessages(?string $phoneNumberId, array $messages, array $contacts = []): void
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

            // Step 4 (Click-to-WhatsApp Ads): a message carrying a
            // `referral` object is the first inbound message after a
            // user tapped a CLICK_TO_WHATSAPP ad — [Hypothesis],
            // documented WhatsApp Cloud API behavior (message.referral,
            // not nested under `context`). Captured BEFORE the
            // text-only/interactive gate below since referral can in
            // principle accompany any inbound message type, and
            // regardless of type this must still fall through to the
            // Chatbot/Journey engine afterward exactly as before.
            $referral = $message['referral'] ?? null;

            if (! empty($referral)) {
                $this->captureCtwaLead($accountId, $from, $message, $contacts);
            }

            // Module 5 (No-Code WhatsApp Journey Builder) widening: a
            // 'question' node with input_type buttons/list sends an
            // interactive message, so the customer's ANSWER arrives as
            // type='interactive', not 'text'. Previously this whole type
            // was dropped before reaching ChatbotEngineService — now the
            // button/list reply's title (or id, if no title) is flattened
            // into a plain string and passed through the SAME
            // handleInboundMessage(string $incomingMessage) signature
            // chatbot_rules already used for plain text, so
            // WhatsAppJourneyEngine (and, unaffected, chatbot_rules
            // matching for ordinary text) needs no separate code path.
            // Every OTHER non-text type (image, audio, location, ...) is
            // still dropped exactly as before.
            $body = match ($type) {
                'text' => (string) ($message['text']['body'] ?? ''),
                'interactive' => (string) (
                    $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? $message['interactive']['button_reply']['id']
                    ?? $message['interactive']['list_reply']['id']
                    ?? ''
                ),
                default => null,
            };

            if ($body === null) {
                Log::debug('Meta webhook: unsupported inbound message type not matched against chatbot rules/journeys.', [
                    'account_id' => $accountId,
                    'type' => $type,
                ]);

                continue;
            }

            if (trim($body) === '') {
                continue;
            }

            app(ChatbotEngineService::class)->handleInboundMessage($accountId, $from, $body, $referral);
        }
    }

    /**
     * Step 4 (Click-to-WhatsApp Ads) — Inbound Referral Lead Capture.
     * Creates/upserts a Lead row (provider = 'whatsapp_ctwa') BEFORE
     * control passes to the Chatbot engine, per this step's explicit
     * instruction. Upserted on provider_lead_id = the inbound message's
     * WAMID (message.id) for the same redelivery-safety guarantee
     * MetaLeadWebhookHandler relies on for Lead Ads leads (leads.
     * provider_lead_id is a unique index) — a Meta webhook retry of the
     * SAME message therefore updates the same row instead of creating a
     * duplicate. Deliberately does NOT also run
     * Lead::isDuplicatePhoneWithin24Hours() — that guard exists to
     * collapse repeat FORM submissions from the same person; a CTWA
     * referral is an individual ad-click attribution event, and
     * intentionally allowed to recur (e.g. the same person clicking a
     * different ad, or messaging again days later).
     *
     * @param array<string, mixed> $message
     * @param array<int, array<string, mixed>> $contacts
     */
    private function captureCtwaLead(int $accountId, string $from, array $message, array $contacts): void
    {
        $referral = $message['referral'] ?? [];
        $wamid = $message['id'] ?? null;

        if (! $wamid) {
            Log::warning('Meta webhook: CTWA referral message had no WAMID — cannot upsert Lead safely, skipped.', [
                'account_id' => $accountId,
            ]);

            return;
        }

        $normalizedPhone = PhoneNumberNormalizer::normalize($from);

        $leadName = null;
        foreach ($contacts as $contact) {
            if (($contact['wa_id'] ?? null) === $from) {
                $leadName = $contact['profile']['name'] ?? null;
                break;
            }
        }

        Lead::updateOrCreate(
            ['provider_lead_id' => (string) $wamid],
            [
                'account_id' => $accountId,
                'provider' => 'whatsapp_ctwa',
                'ad_id' => $referral['source_id'] ?? null,
                'lead_name' => $leadName,
                'lead_phone' => $normalizedPhone !== '' ? $normalizedPhone : $from,
                'raw_field_data' => $referral,
            ]
        );
    }
}
