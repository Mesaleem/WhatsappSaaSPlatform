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
}
