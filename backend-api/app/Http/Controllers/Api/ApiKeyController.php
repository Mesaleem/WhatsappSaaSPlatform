<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Module 9, requirement 1 — tenant Developer Portal API key management.
 * Every key is scoped to the caller's own account_id (never accepted from
 * the client) — external API calls authenticated with a given key can
 * only ever act on that same account, enforced in AuthenticateApiKey.
 *
 * UI Standardization — Graceful Super Admin Fallback: index() now uses
 * ResolvesTenantAccount (previously read $request->user()->account_id
 * directly, which is always null for Super Admin and made every Super
 * Admin visit 422 regardless of the Header's client selector) and
 * returns a platform-wide aggregated table when no client is selected.
 * store()/destroy() still requireAccount() — creating or revoking a key
 * inherently needs exactly one tenant.
 */
class ApiKeyController extends Controller
{
    use ResolvesTenantAccount;

    private const KEY_PREFIX = 'wasaas_live_';
    /** Developer API Platform for WhatsApp Group Creation & Unified Messaging — distinct prefix from KEY_PREFIX so a plaintext key and its plaintext secret are never confusable at a glance. */
    private const SECRET_PREFIX = 'wasaas_secret_';

    /** GET /api/developer/api-keys — every key (active and revoked) so the UI can show full history/status. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);

        if (! $account) {
            $keys = ApiKey::whereNotNull('account_id')
                ->with('account:id,company_name')
                ->latest('id')
                ->get();

            return response()->json(['data' => $keys, 'scope' => 'global']);
        }

        $keys = ApiKey::forAccount($account->id)->latest('id')->get();

        return response()->json(['data' => $keys, 'scope' => 'account']);
    }

    /**
     * POST /api/developer/api-keys — generates a new key. The PLAINTEXT
     * key is returned ONLY in this response's `plain_text_key` field and
     * is never persisted or retrievable again afterward — only its
     * SHA-256 hash (key_hash) is stored, matching the spec's explicit
     * "view plain-text keys ONLY ONCE upon creation" requirement.
     */
    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to create an API key for (pass ?account_id=).');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        // 40 random bytes of entropy (hex-encoded -> 80 chars) after the
        // fixed prefix — comfortably beyond brute-force range, consistent
        // with this codebase's other generated-secret lengths (Module 5's
        // meta_webhook_verify_token, Module 8's invoice number suffix).
        $plainTextKey = self::KEY_PREFIX.Str::random(40);

        // Developer API Platform for WhatsApp Group Creation & Unified
        // Messaging — every NEW key is issued with its dual-factor secret
        // already provisioned (a brand-new key has no existing
        // single-factor integration to preserve, unlike the backfill case
        // regenerateSecret() below handles), so a tenant integrating for
        // the first time gets both credentials in this one response and
        // never needs the separate regenerate-secret step. Same entropy
        // convention as the key itself, distinct prefix constant so the
        // two plaintext values are never visually confusable in the
        // one-time reveal.
        $plainTextSecret = self::SECRET_PREFIX.Str::random(40);

        $apiKey = ApiKey::create([
            'account_id' => $account->id,
            'name' => $data['name'],
            'key_prefix' => substr($plainTextKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainTextKey),
            'secret_prefix' => substr($plainTextSecret, 0, 20),
            'secret_hash' => ApiKey::hashSecret($plainTextSecret),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'API key created. Copy the key and secret now — neither will be shown again.',
            'plain_text_key' => $plainTextKey,
            'plain_text_secret' => $plainTextSecret,
            'api_key' => $apiKey,
        ], 201);
    }

    /**
     * POST /api/developer/api-keys/{id}/regenerate-secret — Developer API
     * Platform for WhatsApp Group Creation & Unified Messaging.
     *
     * Backfills (or rotates) ONLY the dual-factor secret half of an
     * existing key, leaving its key_hash/plain_text_key completely
     * untouched — every existing integration authenticated by that key
     * alone (the pre-existing /v1/messages/send-payment-alert,
     * /v1/messages/send-template, /v1/send-message routes, all still on
     * the single-factor auth.apikey middleware) keeps working through
     * this call with zero disruption. This is the ONLY way a key created
     * before this feature (secret_hash null) can ever call the new
     * dual-factor /api/v1/whatsapp/* endpoints — see this pair's
     * creating migration's docblock.
     *
     * Same one-time-reveal contract as store(): the new plaintext secret
     * is returned ONLY in this response and can never be retrieved again
     * afterward.
     */
    public function regenerateSecret(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its API keys (pass ?account_id=).');

        $apiKey = ApiKey::forAccount($account->id)->find($id);
        abort_if(! $apiKey, 404, 'API key not found.');

        if ($apiKey->isRevoked()) {
            return response()->json([
                'message' => 'This API key has been revoked and cannot be given a new secret. Create a new key instead.',
            ], 422);
        }

        $plainTextSecret = self::SECRET_PREFIX.Str::random(40);

        $apiKey->forceFill([
            'secret_prefix' => substr($plainTextSecret, 0, 20),
            'secret_hash' => ApiKey::hashSecret($plainTextSecret),
        ])->save();

        return response()->json([
            'message' => 'API secret (re)generated. Copy it now — it will not be shown again.',
            'plain_text_secret' => $plainTextSecret,
            'api_key' => $apiKey->fresh(),
        ]);
    }

    /** DELETE /api/developer/api-keys/{id} — revokes (soft) rather than deletes; already-revoked is a no-op. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its API keys (pass ?account_id=).');

        $apiKey = ApiKey::forAccount($account->id)->find($id);
        abort_if(! $apiKey, 404, 'API key not found.');

        if (! $apiKey->isRevoked()) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json(['message' => 'API key revoked.', 'api_key' => $apiKey->fresh()]);
    }
}
