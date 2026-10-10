<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\WhatsAppSession;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class MetaConfigController extends Controller
{
    private const API_VERSION = 'v18.0';

    /**
     * GET /api/whatsapp/meta-config — current config for this account.
     * The access token is NEVER returned, not even encrypted — only a
     * fixed-shape mask that confirms one is stored, plus its last 4 chars
     * (Meta tokens are long and opaque, so 4 chars alone doesn't usefully
     * expose it — this mirrors how card-number masking works).
     */
    public function show(Request $request): JsonResponse
    {
        $session = $this->account($request)->whatsAppSession;

        return response()->json([
            'configured' => (bool) $session?->hasMetaConfigured(),
            'meta_phone_number_id' => $session?->meta_phone_number_id,
            'meta_waba_id' => $session?->meta_waba_id,
            'meta_access_token_masked' => $this->maskToken($session?->meta_access_token),
            'meta_webhook_verify_token' => $session?->meta_webhook_verify_token,
            'webhook_url' => url('/api/webhooks/meta'),
            // Phase 4 Task 1 -- additive field, existing keys unchanged.
            // Set to 'connected' by store() once credentials verify
            // against the Graph API; 'disconnected' until then.
            'connection_status' => $session?->status ?? 'disconnected',
        ]);
    }

    /**
     * POST /api/whatsapp/meta-config/test-connection — validates credentials
     * against the live Meta Graph API WITHOUT saving them. Used by the
     * frontend's "Test Connection" button before Save is enabled.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meta_phone_number_id' => ['required', 'string'],
            'meta_access_token' => ['required', 'string'],
        ]);

        $result = $this->verifyWithMeta($data['meta_phone_number_id'], $data['meta_access_token']);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /api/whatsapp/meta-config — saves/updates credentials. Verifies
     * them against Meta first (same check as testConnection) so invalid
     * credentials are never persisted.
     */
    public function store(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'meta_phone_number_id' => ['required', 'string'],
            'meta_waba_id' => ['required', 'string'],
            'meta_access_token' => ['required', 'string'],
        ]);

        // Phase 4 Task 1: a Meta phone number belongs to exactly one WABA
        // and therefore to exactly one tenant here. MetaWebhookController
        // resolves the owning account purely by meta_phone_number_id, so
        // letting a second tenant claim the same number would silently
        // route that number's inbound messages to whichever row the
        // database returned first. Checked before the (slower) Graph call
        // so a conflicting attempt never even reaches Meta.
        $conflict = WhatsAppSession::query()
            ->where('meta_phone_number_id', $data['meta_phone_number_id'])
            ->where('account_id', '!=', $account->id)
            ->exists();

        if ($conflict) {
            return response()->json([
                'message' => 'This WhatsApp phone number is already connected to another account on this platform. Disconnect it there first, or use a different phone number.',
                'error_code' => 'META_NUMBER_ALREADY_CONNECTED',
            ], 409);
        }

        $verification = $this->verifyWithMeta($data['meta_phone_number_id'], $data['meta_access_token']);

        if (! $verification['success']) {
            return response()->json([
                'message' => 'Could not verify these credentials with Meta. Nothing was saved.',
                'error' => $verification['error'] ?? null,
            ], 422);
        }

        $session = WhatsAppSession::firstOrNew(['account_id' => $account->id]);
        $session->meta_phone_number_id = $data['meta_phone_number_id'];
        $session->meta_waba_id = $data['meta_waba_id'];
        $session->meta_access_token = $data['meta_access_token']; // encrypted cast on write
        $session->meta_webhook_verify_token ??= Str::random(40);

        // Connection status for the Meta provider. Until now this column
        // was only ever written by the QR engine's status callback, so a
        // fully-configured Meta account sat at the 'disconnected' default
        // forever. The credentials have just been verified against the
        // live Graph API, so 'connected' is the accurate state.
        //
        // This is inert for sending: PaymentAlertDispatcher::
        // isWhatsAppDisconnected() gates on engine_type === 'qr' before it
        // reads status at all, so no QR or Meta send path changes
        // behaviour because of this write.
        $session->status = 'connected';
        $session->last_connected_at = now();

        try {
            $session->save();
        } catch (QueryException $e) {
            // SQLSTATE 23000 -- the unique index on meta_phone_number_id
            // firing as the concurrency backstop behind the check above,
            // the same pattern payment_ref and idempotency keys use.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return response()->json([
                'message' => 'This WhatsApp phone number is already connected to another account on this platform. Disconnect it there first, or use a different phone number.',
                'error_code' => 'META_NUMBER_ALREADY_CONNECTED',
            ], 409);
        }

        return response()->json([
            'message' => 'Meta credentials saved.',
            'meta_phone_number_id' => $session->meta_phone_number_id,
            'meta_waba_id' => $session->meta_waba_id,
            'meta_access_token_masked' => $this->maskToken($session->meta_access_token),
            'meta_webhook_verify_token' => $session->meta_webhook_verify_token,
            'webhook_url' => url('/api/webhooks/meta'),
            // Phase 4 Task 2 -- additive, and the same key show() returns, so
            // the UI can render the live status straight from the save
            // response instead of having to re-fetch. Existing keys unchanged.
            'connection_status' => $session->status,
            'verified_name' => $verification['verified_name'] ?? null,
            'display_phone_number' => $verification['display_phone_number'] ?? null,
        ], 201);
    }

    /**
     * GET /api/whatsapp/meta-config/app-credentials — Phase 1: whether
     * this tenant's own Facebook App (ID/Secret/Embedded-Signup Config
     * ID) is saved yet. The secret is never returned, only whether one
     * is stored (hasMetaAppConfigured()).
     */
    public function appCredentials(Request $request): JsonResponse
    {
        $session = $this->account($request)->whatsAppSession;

        return response()->json([
            'configured' => (bool) $session?->hasMetaAppConfigured(),
            'meta_app_id' => $session?->meta_app_id,
            'meta_config_id' => $session?->meta_config_id,
        ]);
    }

    /**
     * POST /api/whatsapp/meta-config/app-credentials — Phase 1 step 1 of
     * Embedded Signup: the tenant pastes their own Facebook App's
     * credentials (created per-tenant, per the confirmed architecture —
     * see the roadmap doc's Phase 1 / Open Questions). Verified against
     * Meta's "app access token" endpoint before saving, mirroring how
     * store() never persists an unverified phone/token pair.
     */
    public function saveAppCredentials(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'meta_app_id' => ['required', 'string'],
            'meta_app_secret' => ['required', 'string'],
            'meta_config_id' => ['required', 'string'],
        ]);

        $verification = $this->verifyAppCredentials($data['meta_app_id'], $data['meta_app_secret']);

        if (! $verification['success']) {
            return response()->json([
                'message' => 'Could not verify this App ID / App Secret pair with Meta. Nothing was saved.',
                'error' => $verification['error'] ?? null,
            ], 422);
        }

        $session = WhatsAppSession::firstOrNew(['account_id' => $account->id]);
        $session->meta_app_id = $data['meta_app_id'];
        $session->meta_app_secret = $data['meta_app_secret']; // encrypted cast on write
        $session->meta_config_id = $data['meta_config_id'];
        $session->save();

        return response()->json([
            'message' => 'Meta App credentials saved.',
            'meta_app_id' => $session->meta_app_id,
            'meta_config_id' => $session->meta_config_id,
        ], 201);
    }

    /**
     * GET /api/whatsapp/meta-config/oauth/start — Phase 1 step 2: hands
     * the frontend exactly what FB.login()'s WhatsApp Embedded Signup
     * call needs (app id + config id). The App Secret never leaves the
     * backend — the frontend only ever sees this pair.
     */
    public function oauthStart(Request $request): JsonResponse
    {
        $session = $this->account($request)->whatsAppSession;

        if (! $session?->hasMetaAppConfigured()) {
            return response()->json([
                'message' => 'Save your Meta App credentials first.',
                'error_code' => 'META_APP_NOT_CONFIGURED',
            ], 422);
        }

        return response()->json([
            'meta_app_id' => $session->meta_app_id,
            'meta_config_id' => $session->meta_config_id,
        ]);
    }

    /**
     * POST /api/whatsapp/meta-config/oauth/exchange — Phase 1 step 3: the
     * frontend's FB.login() callback for WhatsApp Embedded Signup hands
     * back a short-lived `code` plus the chosen `waba_id` /
     * `phone_number_id` (Meta's SDK message event carries these
     * directly — see Meta's "Embedded Signup for WhatsApp" docs). This
     * endpoint:
     *   1. exchanges `code` for a long-lived access token (server-side,
     *      needs the App Secret — never done in the browser);
     *   2. subscribes this app to the WABA's webhook, so
     *      MetaWebhookController starts receiving this tenant's events
     *      (already-built pipeline — see Phase 2 of the roadmap doc);
     *   3. registers the phone number for the Cloud API;
     *   4. persists everything onto WhatsAppSession, reusing store()'s
     *      conflict check so a number already connected elsewhere on
     *      this platform can't be silently reclaimed.
     *
     * NOTE: steps 1-3 call live Meta endpoints per Meta's documented
     * Embedded Signup flow. They have not been exercised against a real
     * Meta App in this environment (none exists yet — this was
     * confirmed before writing this code) — budget time to verify the
     * exact request shapes against Meta's current API docs / Graph API
     * Explorer once a real App + test number exists, before relying on
     * this in production.
     */
    public function oauthExchange(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $session = $account->whatsAppSession;

        if (! $session?->hasMetaAppConfigured()) {
            return response()->json([
                'message' => 'Save your Meta App credentials first.',
                'error_code' => 'META_APP_NOT_CONFIGURED',
            ], 422);
        }

        $data = $request->validate([
            'code' => ['required', 'string'],
            'waba_id' => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
        ]);

        // Phase 1 — a number belongs to exactly one tenant here, same
        // conflict guard as store() (see that method's own docblock for
        // why: MetaWebhookController resolves tenancy purely by
        // meta_phone_number_id).
        $conflict = WhatsAppSession::query()
            ->where('meta_phone_number_id', $data['phone_number_id'])
            ->where('account_id', '!=', $account->id)
            ->exists();

        if ($conflict) {
            return response()->json([
                'message' => 'This WhatsApp phone number is already connected to another account on this platform. Disconnect it there first, or use a different phone number.',
                'error_code' => 'META_NUMBER_ALREADY_CONNECTED',
            ], 409);
        }

        $tokenResult = $this->exchangeCodeForToken($session->meta_app_id, $session->meta_app_secret, $data['code']);

        if (! $tokenResult['success']) {
            return response()->json([
                'message' => 'Could not complete sign-in with Meta.',
                'error' => $tokenResult['error'] ?? null,
            ], 422);
        }

        $accessToken = $tokenResult['access_token'];

        $subscribeResult = $this->subscribeWebhook($data['waba_id'], $accessToken);
        if (! $subscribeResult['success']) {
            return response()->json([
                'message' => 'Signed in with Meta, but could not subscribe to this WhatsApp Business Account\'s webhook events. Nothing was saved — retry once this is resolved.',
                'error' => $subscribeResult['error'] ?? null,
            ], 422);
        }

        $registerResult = $this->registerPhoneNumber($data['phone_number_id'], $accessToken);
        if (! $registerResult['success']) {
            return response()->json([
                'message' => 'Signed in with Meta, but could not register the phone number for the Cloud API. Nothing was saved — retry once this is resolved.',
                'error' => $registerResult['error'] ?? null,
            ], 422);
        }

        $session->meta_phone_number_id = $data['phone_number_id'];
        $session->meta_waba_id = $data['waba_id'];
        $session->meta_access_token = $accessToken; // encrypted cast on write
        $session->meta_webhook_verify_token ??= Str::random(40);
        $session->status = 'connected';
        $session->last_connected_at = now();
        $session->save();

        return response()->json([
            'message' => 'WhatsApp channel connected.',
            'meta_phone_number_id' => $session->meta_phone_number_id,
            'meta_waba_id' => $session->meta_waba_id,
            'connection_status' => $session->status,
        ], 201);
    }

    private function account(Request $request): Account
    {
        $accountId = $request->user()->account_id;
        abort_if(! $accountId, 422, 'Super Admin has no tenant account to configure.');

        return Account::findOrFail($accountId);
    }

    private function maskToken(?string $token): ?string
    {
        if (! $token) {
            return null;
        }

        return str_repeat('•', 20).substr($token, -4);
    }

    /**
     * A lightweight, read-only Graph API call: confirms the token is valid
     * AND belongs to this exact phone_number_id, without sending a message.
     *
     * @return array{success: bool, error?: string, verified_name?: string, display_phone_number?: string}
     */
    private function verifyWithMeta(string $phoneNumberId, string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get("https://graph.facebook.com/".self::API_VERSION."/{$phoneNumberId}", [
                    'fields' => 'verified_name,display_phone_number',
                ]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Could not reach Meta Graph API.'];
        }

        if ($response->failed()) {
            return [
                'success' => false,
                'error' => $response->json('error.message') ?? 'Meta rejected these credentials.',
            ];
        }

        return [
            'success' => true,
            'verified_name' => $response->json('verified_name'),
            'display_phone_number' => $response->json('display_phone_number'),
        ];
    }

    /**
     * App-access-token sanity check for a tenant-pasted App ID / Secret
     * pair, mirroring verifyWithMeta()'s "verify before persist" pattern.
     * Uses Meta's documented app-access-token format (`{app-id}|{app-secret}`)
     * against a lightweight read-only call.
     */
    private function verifyAppCredentials(string $appId, string $appSecret): array
    {
        try {
            $response = Http::timeout(10)
                ->get("https://graph.facebook.com/".self::API_VERSION."/{$appId}", [
                    'access_token' => "{$appId}|{$appSecret}",
                    'fields' => 'name',
                ]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Could not reach Meta Graph API.'];
        }

        if ($response->failed()) {
            return [
                'success' => false,
                'error' => $response->json('error.message') ?? 'Meta rejected this App ID / App Secret pair.',
            ];
        }

        return ['success' => true];
    }

    /**
     * Exchanges Embedded Signup's short-lived `code` for a long-lived
     * access token. Per Meta's Embedded Signup docs, the JS-SDK-issued
     * code is exchanged without a redirect_uri (unlike a classic
     * server-redirect OAuth flow).
     */
    private function exchangeCodeForToken(string $appId, string $appSecret, string $code): array
    {
        try {
            $response = Http::timeout(10)
                ->get("https://graph.facebook.com/".self::API_VERSION."/oauth/access_token", [
                    'client_id' => $appId,
                    'client_secret' => $appSecret,
                    'code' => $code,
                ]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Could not reach Meta Graph API.'];
        }

        if ($response->failed() || ! $response->json('access_token')) {
            return [
                'success' => false,
                'error' => $response->json('error.message') ?? 'Meta rejected the sign-in code.',
            ];
        }

        return ['success' => true, 'access_token' => $response->json('access_token')];
    }

    /**
     * Subscribes our app to the WABA's webhook — without this, Meta never
     * sends this tenant's inbound messages/status events to
     * MetaWebhookController (already-built pipeline, see Phase 2 of the
     * roadmap doc). Per Meta's WABA subscription endpoint.
     */
    private function subscribeWebhook(string $wabaId, string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post("https://graph.facebook.com/".self::API_VERSION."/{$wabaId}/subscribed_apps");
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Could not reach Meta Graph API.'];
        }

        if ($response->failed()) {
            return [
                'success' => false,
                'error' => $response->json('error.message') ?? 'Meta rejected the webhook subscription.',
            ];
        }

        return ['success' => true];
    }

    /**
     * Registers the phone number for the Cloud API. Per Meta's phone
     * number registration endpoint — most Embedded Signup numbers are
     * pre-registered by Meta's flow already, so a "already registered"
     * style error here is expected and treated as success, not failure.
     */
    private function registerPhoneNumber(string $phoneNumberId, string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post("https://graph.facebook.com/".self::API_VERSION."/{$phoneNumberId}/register", [
                    'messaging_product' => 'whatsapp',
                ]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Could not reach Meta Graph API.'];
        }

        if ($response->failed()) {
            $message = $response->json('error.message') ?? '';

            if (stripos($message, 'already') !== false) {
                return ['success' => true];
            }

            return ['success' => false, 'error' => $message ?: 'Meta rejected phone number registration.'];
        }

        return ['success' => true];
    }
}
