<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InboundMessageEvent;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\PaymentAlert;
use App\Models\SocialProviderConfig;
use App\Models\WhatsAppSession;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    /**
     * How long a processed webhook event stays claimed against Meta's
     * at-least-once redelivery. Comfortably longer than Meta's documented
     * retry window, short enough that the cache store never accumulates
     * these indefinitely.
     */
    private const REDELIVERY_CLAIM_TTL_SECONDS = 86400;

    private static function deliveredClaimKey(string $wamid): string
    {
        return 'meta_wh_delivered:'.sha1($wamid);
    }

    /**
     * The ONLY way this controller establishes tenancy: the verified
     * metadata.phone_number_id on the webhook payload, matched against
     * whatsapp_sessions.meta_phone_number_id (unique as of Phase 4
     * Task 1). No account/tenant identifier is ever read from the
     * payload body itself.
     */
    private function resolveAccountId(?string $phoneNumberId): ?int
    {
        if (! $phoneNumberId) {
            return null;
        }

        $accountId = WhatsAppSession::query()
            ->where('meta_phone_number_id', $phoneNumberId)
            ->value('account_id');

        return $accountId !== null ? (int) $accountId : null;
    }

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

        // [Bug fix, Phase 4 Task 4]: this was ->exists() on the SQL
        // comparison alone. whatsapp_sessions is utf8mb4_unicode_ci, so
        // MySQL compares strings CASE-INSENSITIVELY -- a verify token
        // differing from the stored one only by letter case was accepted,
        // cutting the effective entropy of a Str::random(40) token from
        // 62 to 36 symbols per character. The indexed lookup is kept to
        // narrow candidates, then hash_equals() re-checks the match
        // exactly (and in constant time, so the endpoint cannot be used
        // as a byte-by-byte oracle either).
        $matches = WhatsAppSession::query()
            ->whereNotNull('meta_webhook_verify_token')
            ->where('meta_webhook_verify_token', $token)
            ->pluck('meta_webhook_verify_token')
            ->contains(fn ($stored) => is_string($stored) && hash_equals($stored, $token));

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

                // [Hardening, Phase 4 Task 4]: the owning tenant is resolved
                // ONCE per change, from the webhook's own verified
                // metadata.phone_number_id -- never from anything in the
                // payload that names an account. Both correlations below
                // are then scoped to that account, so a status event can
                // only ever touch a row belonging to the number the event
                // is actually about (whatsapp_sessions.meta_phone_number_id
                // is unique as of Phase 4 Task 1, so this resolves to at
                // most one tenant).
                $statusAccountId = $this->resolveAccountId($value['metadata']['phone_number_id'] ?? null);

                foreach ($statuses as $status) {
                    Log::info('Meta WhatsApp status update received', [
                        'wamid' => $status['id'] ?? null,
                        'status' => $status['status'] ?? null,
                        'recipient_id' => $status['recipient_id'] ?? null,
                        'timestamp' => $status['timestamp'] ?? null,
                        'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                        'errors' => $status['errors'] ?? null,
                    ]);

                    // A status event whose phone_number_id resolves to no
                    // tenant is logged above and then dropped: with no
                    // verified owner there is no row it is entitled to
                    // modify.
                    if ($statusAccountId === null) {
                        continue;
                    }

                    if (($status['status'] ?? null) === 'delivered' && ! empty($status['id'])) {
                        $this->fireDeliveredWebhook($statusAccountId, $status['id']);
                    }

                    if (($status['status'] ?? null) === 'failed' && ! empty($status['id'])) {
                        $this->correlateFailedStatus($statusAccountId, $status['id'], $status['errors'] ?? null);
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

    private function fireDeliveredWebhook(int $accountId, string $wamid): void
    {
        $alert = PaymentAlert::where('account_id', $accountId)
            ->where('gateway_message_id', $wamid)
            ->first();

        if (! $alert) {
            // Not every WAMID belongs to a payment_alerts row (or the
            // status callback arrived before ProcessPaymentAlertJob
            // finished persisting it) — silently skip, not an error.
            //
            // [Bug fix, Phase 4 Task 9]: this lookup used to run AFTER the
            // redelivery claim below, so a 'delivered' callback that
            // overtook ProcessPaymentAlertJob's own gateway_message_id
            // write (a real race: the job persists the WAMID only after
            // the Graph call returns, and Meta's status callback is fired
            // from that same send) consumed the one-shot claim on a
            // guaranteed no-op. Meta's later, legitimate redelivery of the
            // SAME event then hit the spent claim and returned early, so
            // the tenant's 'message.delivered' webhook was never fired at
            // all -- silently, and permanently for that message.
            //
            // Returning before the claim is taken makes the miss free: the
            // claim is now spent only on a delivery that actually has a
            // row to report, which is the only case it was ever meant to
            // deduplicate.
            return;
        }

        // Meta delivers webhooks at least once, and this fired an outbound
        // 'message.delivered' webhook to the tenant on EVERY redelivery of
        // the same status event. Claimed once per WAMID so a retry is a
        // no-op for the tenant's own integration. Cache::add() is atomic
        // (it is the same claim-or-lose primitive BulkMessageCooldown
        // already relies on), and it is still taken BEFORE the only
        // side-effecting call below, so two concurrent redeliveries can
        // never both fire.
        if (! Cache::add(self::deliveredClaimKey($wamid), true, self::REDELIVERY_CLAIM_TTL_SECONDS)) {
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
    private function correlateFailedStatus(int $accountId, string $wamid, ?array $errors): void
    {
        // Scoped to the tenant the phone_number_id resolved to: an
        // unmatched or foreign WAMID can then never mutate another
        // tenant's dispatch row.
        $log = MessageDispatchLog::where('account_id', $accountId)
            ->where('gateway_message_id', $wamid)
            ->first();

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

        $accountId = $this->resolveAccountId($phoneNumberId);

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

            // [Bug fix, Phase 4 Task 4]: Meta delivers inbound-message
            // webhooks AT LEAST once. Nothing guarded this call, so a
            // redelivery of the same message ran the chatbot/journey
            // engine again -- sending the customer a duplicate auto-reply
            // and charging the tenant's quota a second time for it.
            // captureCtwaLead() above was already redelivery-safe (it
            // upserts on this same WAMID), which is precisely why this
            // omission was easy to miss.
            //
            // The claim is taken HERE rather than at the top of the loop
            // so that lead capture keeps its existing, idempotent
            // behaviour untouched, and only the side-effecting send is
            // protected. A message with no id cannot be claimed and is
            // processed as before rather than dropped.
            //
            // Phase 7 Task 3 — the claim is now DURABLE: the WAMID is the
            // event key of an inbound_message_events row whose unique index
            // decides (InboundEventGate, inside ChatbotEngineService). It
            // replaces the former 24 h Cache::add() claim, which did not
            // survive a cache flush or a cache store shared by nobody.
            $wamid = $message['id'] ?? null;

            app(ChatbotEngineService::class)->handleInboundMessage(
                $accountId,
                $from,
                $body,
                $referral,
                InboundMessageEvent::PROVIDER_META,
                is_string($wamid) && $wamid !== '' ? 'wamid:'.$wamid : null,
            );
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

        // [Security fix, Phase 4 Task 9 -- tenant isolation]: the upsert
        // below matches on provider_lead_id ALONE while carrying
        // account_id in its UPDATE payload, so an existing row for this
        // id belonging to a DIFFERENT tenant would be silently
        // re-parented to this one -- taking lead_name/lead_phone/ad_id
        // with it, i.e. moving another tenant's contact PII across the
        // boundary and removing the lead from their CRM.
        //
        // Both sibling correlations in this controller
        // (correlateFailedStatus/fireDeliveredWebhook) already constrain
        // on account_id for exactly this reason; this one did not. The
        // docblock above argued the global unique index on
        // provider_lead_id made it safe, but that uniqueness is precisely
        // what turns a would-be duplicate INSERT into a cross-tenant
        // UPDATE.
        //
        // Fixed by refusing the write rather than by scoping the match
        // key: leads.provider_lead_id is globally unique, so matching on
        // (account_id, provider_lead_id) would turn the collision into an
        // unhandled 23000 -- a 500 that Meta would retry indefinitely.
        // Making that index composite is the proper repair and needs a
        // migration, which is out of this task's scope; this check closes
        // the isolation hole with no schema change and leaves the
        // same-tenant upsert path byte-identical.
        $existing = Lead::where('provider_lead_id', (string) $wamid)->first();

        if ($existing && (int) $existing->account_id !== $accountId) {
            Log::warning('Meta webhook: CTWA referral WAMID already belongs to another account — refusing to re-parent the lead.', [
                'account_id' => $accountId,
                'wamid' => $wamid,
            ]);

            return;
        }

        $lead = Lead::updateOrCreate(
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

        /*
         * Phase 6 CRM Hardening (Issue 6) — the WhatsApp source.
         *
         * THIS IS THE CODEBASE'S ONLY INBOUND WHATSAPP LEAD-CREATION
         * EVENT, which is why `whatsapp` is wired here and nowhere else:
         * a click-to-WhatsApp referral is a real person starting a real
         * conversation with the tenant, and this method already exists
         * to record exactly that. No QR/Baileys code is touched, no
         * second messaging path is created, and this adds nothing to the
         * ordinary inbound-message path — only to the CTWA branch that
         * was already capturing a Lead.
         *
         * linkQuietly for the same reason as the other two capture
         * sites: this runs inside Meta's webhook, which retries on any
         * non-2xx.
         */
        app(CaptureLeadLinker::class)->linkQuietly($lead);
    }
}
