<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiKeyChangeRequest;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\ApiKeyCooldownActiveException;
use InvalidArgumentException;
use App\Services\ApiAccess\InstallationAllowanceExceededException;
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

    public function __construct(private readonly ApiKeyBindingService $bindings)
    {
    }

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

            return response()->json(['data' => $this->withBinding($keys), 'scope' => 'global', 'server_binding_warning' => config('api_binding.warning')]);
        }

        $keys = ApiKey::forAccount($account->id)->latest('id')->get();

        return response()->json(['data' => $this->withBinding($keys), 'scope' => 'account', 'server_binding_warning' => config('api_binding.warning')]);
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
            // The buyer must acknowledge that the key is licensed to ONE authorized server before it is minted.
            'acknowledge_server_binding' => ['accepted'],
            'server_label' => ['nullable', 'string', 'max:100'],
            'ip_policy' => ['nullable', 'string', 'in:SINGLE_IP,IP_ALLOWLIST'],
            'authorized_ips' => ['nullable', 'array', 'max:20'],
            'authorized_ips.*' => ['string', 'max:64'],
        ], [
            'acknowledge_server_binding.accepted' => (string) config('api_binding.warning'),
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

        // Validate the binding input BEFORE anything is written, so a bad IP never leaves a half-created key.
        try {
            $this->bindings->normalizePolicy($data['ip_policy'] ?? null, $data['authorized_ips'] ?? []);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['authorized_ips' => [$e->getMessage()]]], 422);
        }

        try {
            [$apiKey, $credential] = \Illuminate\Support\Facades\DB::transaction(function () use ($account, $data, $plainTextKey, $plainTextSecret, $request) {
                $apiKey = ApiKey::create([
                    'account_id' => $account->id,
                    'name' => $data['name'],
                    'key_prefix' => substr($plainTextKey, 0, 20),
                    'key_hash' => ApiKey::hashKey($plainTextKey),
                    'secret_prefix' => substr($plainTextSecret, 0, 20),
                    'secret_hash' => ApiKey::hashSecret($plainTextSecret),
                    'expires_at' => $data['expires_at'] ?? null,
                ]);
                $provisioned = $this->bindings->provision($apiKey, [
                    'label' => $data['server_label'] ?? null,
                    'ip_policy' => $data['ip_policy'] ?? null,
                    'authorized_ips' => $data['authorized_ips'] ?? [],
                ], $request->user());
                $this->bindings->record(ApiKeyBindingService::EV_KEY_CREATED, $apiKey, $provisioned['binding'], null, [], actor: $request->user());

                return [$apiKey, $provisioned['credential']];
            });
        } catch (InstallationAllowanceExceededException $e) {
            // Phase 4 Task 6 — the transaction above rolled back
            // entirely (Laravel's DB::transaction() rolls back on any
            // thrown exception before rethrowing), so no ApiKey row and
            // no binding were created — "do not partially mutate the
            // request/key state" per this task's own requirement.
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        } catch (ApiKeyCooldownActiveException $e) {
            // Phase 4 Task 9 — same rollback guarantee as above: no
            // ApiKey row and no binding were created.
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        }

        return response()->json([
            'message' => 'API key created. Copy the key, secret and installation credential now — none will be shown again.',
            'plain_text_key' => $plainTextKey,
            'plain_text_secret' => $plainTextSecret,
            'installation_credential' => $credential,
            'installation_header' => config('api_binding.installation_header'),
            'warning' => config('api_binding.warning'),
            'api_key' => array_merge($apiKey->toArray(), ['server_binding' => $this->bindings->summary($apiKey)]),
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

        // Phase 4 Task 11 — audit gap closed: this write previously
        // recorded no event at all, unlike every other credential-
        // adjacent transition in this service (EV_CREDENTIAL_ISSUED for
        // the installation credential). No payload beyond the standard
        // key/account/binding/actor identifiers record() always
        // attaches — the plaintext secret itself is never logged.
        $this->bindings->record(ApiKeyBindingService::EV_SECRET_REGENERATED, $apiKey, $apiKey->liveBinding(), null, [], actor: $request->user());

        return response()->json([
            'message' => 'API secret (re)generated. Copy it now — it will not be shown again.',
            'plain_text_secret' => $plainTextSecret,
            'api_key' => $apiKey->fresh(),
        ]);
    }

    /**
     * DELETE /api/developer/api-keys/{id} — revokes (soft) rather than
     * deletes; already-revoked is a no-op.
     *
     * Phase 4 Task 6: destroying/revoking a key must also release its
     * live installation slot in the SAME transaction, not just mark the
     * key revoked and leave the binding occupying a slot forever. This
     * now delegates entirely to ApiKeyBindingService::destroyKey(),
     * which locks Account -> ApiKey -> binding (same order as the
     * creation seam), revokes the key (idempotently) and revokes its
     * live binding (if any) via the existing revokeBinding() lifecycle
     * method — never a physical delete, never a second binding created.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its API keys (pass ?account_id=).');

        $apiKey = ApiKey::forAccount($account->id)->find($id);
        abort_if(! $apiKey, 404, 'API key not found.');

        $apiKey = $this->bindings->destroyKey($apiKey, $request->user());

        return response()->json(['message' => 'API key revoked.', 'api_key' => $apiKey]);
    }

    /** GET /api/developer/api-keys/{id}/server-binding */
    public function serverBinding(Request $request, int $id): JsonResponse
    {
        $apiKey = $this->ownedKey($request, $id);

        return response()->json(['data' => $this->bindings->summary($apiKey), 'warning' => config('api_binding.warning')]);
    }

    /** POST /api/developer/api-keys/{id}/server-binding - first-time registration for a key that has never been bound (legacy keys). */
    public function registerServer(Request $request, int $id): JsonResponse
    {
        $apiKey = $this->ownedKey($request, $id);
        $data = $request->validate([
            'acknowledge_server_binding' => ['accepted'],
            'server_label' => ['nullable', 'string', 'max:100'],
            'ip_policy' => ['nullable', 'string', 'in:SINGLE_IP,IP_ALLOWLIST'],
            'authorized_ips' => ['nullable', 'array', 'max:20'],
            'authorized_ips.*' => ['string', 'max:64'],
        ], ['acknowledge_server_binding.accepted' => (string) config('api_binding.warning')]);

        if ($apiKey->isRevoked()) {
            return response()->json(['message' => 'This API key has been revoked.'], 422);
        }
        if ($apiKey->bindings()->exists()) {
            return response()->json(['message' => 'This key already has an authorized server. Request a server change instead.'], 422);
        }

        try {
            $provisioned = $this->bindings->provision($apiKey, [
                'label' => $data['server_label'] ?? null,
                'ip_policy' => $data['ip_policy'] ?? null,
                'authorized_ips' => $data['authorized_ips'] ?? [],
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['authorized_ips' => [$e->getMessage()]]], 422);
        } catch (InstallationAllowanceExceededException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        } catch (ApiKeyCooldownActiveException $e) {
            // Phase 4 Task 9
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        }

        return response()->json([
            'message' => 'Authorized server registered. Copy the installation credential now — it will not be shown again.',
            'installation_credential' => $provisioned['credential'],
            'installation_header' => config('api_binding.installation_header'),
            'data' => $this->bindings->summary($apiKey),
        ], 201);
    }

    /** POST /api/developer/api-keys/{id}/installation-credential - issues the credential once, for a binding created by an approval/rebind (or never used). */
    public function installationCredential(Request $request, int $id): JsonResponse
    {
        $apiKey = $this->ownedKey($request, $id);
        $binding = $apiKey->liveBinding();
        if (! $binding) {
            return response()->json(['message' => 'There is no authorized server to issue a credential for.'], 422);
        }
        try {
            $credential = $this->bindings->issueCredential($apiKey, $binding, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Installation credential issued. Copy it now — it will not be shown again.',
            'installation_credential' => $credential,
            'installation_header' => config('api_binding.installation_header'),
        ]);
    }

    /** POST /api/developer/api-keys/{id}/server-change-requests - the only way a buyer can ask to move to another server. Changes nothing until a Super Admin approves. */
    public function requestServerChange(Request $request, int $id): JsonResponse
    {
        $apiKey = $this->ownedKey($request, $id);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'requested_label' => ['nullable', 'string', 'max:100'],
            'ip_policy' => ['nullable', 'string', 'in:SINGLE_IP,IP_ALLOWLIST'],
            'requested_ips' => ['required', 'array', 'min:1', 'max:20'],
            'requested_ips.*' => ['string', 'max:64'],
        ]);

        try {
            $changeRequest = $this->bindings->requestChange($apiKey, $request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ApiKeyCooldownActiveException $e) {
            // Phase 4 Task 9
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        }

        return response()->json([
            'message' => 'Server change requested. The current server stays authorized until a Super Admin approves the change.',
            'data' => $changeRequest->toSafeArray(),
        ], 201);
    }

    private function ownedKey(Request $request, int $id): ApiKey
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its API keys (pass ?account_id=).');
        $apiKey = ApiKey::forAccount($account->id)->find($id);
        abort_if(! $apiKey, 404, 'API key not found.');

        return $apiKey;
    }

    /** @param \Illuminate\Support\Collection<int, ApiKey> $keys */
    private function withBinding($keys): array
    {
        return $keys->map(fn (ApiKey $k) => array_merge($k->toArray(), ['server_binding' => $this->bindings->summary($k)]))->all();
    }
}
