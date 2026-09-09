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

        $apiKey = ApiKey::create([
            'account_id' => $account->id,
            'name' => $data['name'],
            'key_prefix' => substr($plainTextKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainTextKey),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'API key created. Copy it now — it will not be shown again.',
            'plain_text_key' => $plainTextKey,
            'api_key' => $apiKey,
        ], 201);
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
