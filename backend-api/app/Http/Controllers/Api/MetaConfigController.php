<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\WhatsAppSession;
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
        $session->save();

        return response()->json([
            'message' => 'Meta credentials saved.',
            'meta_phone_number_id' => $session->meta_phone_number_id,
            'meta_waba_id' => $session->meta_waba_id,
            'meta_access_token_masked' => $this->maskToken($session->meta_access_token),
            'meta_webhook_verify_token' => $session->meta_webhook_verify_token,
            'webhook_url' => url('/api/webhooks/meta'),
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
