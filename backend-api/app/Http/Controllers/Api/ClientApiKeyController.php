<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\ApiAccess\ApiKeyBindingService;
use InvalidArgumentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dynamic Templates & Variables System — Section 3's "Client API Key"
 * Profile/Account Settings section: one simple, always-present key per
 * account, distinct from the Developer Portal's multi-key vault
 * (ApiKeyController) even though both share the SAME api_keys table and
 * ApiKey model — deliberately NOT a second `client_api_keys` table.
 * Duplicating that table/hash/revoke machinery for a key that is
 * functionally identical (SHA-256 hash lookup, same
 * AuthenticateApiKey middleware, same account scoping) would be pure
 * duplication with a real cost: two places to audit "who can call our
 * external API", two revocation UIs that could drift. This controller
 * is just a second, simpler VIEW onto that one table: the account's
 * single ApiKey row named self::PRIMARY_KEY_NAME, auto-provisioned on
 * first view rather than requiring a Client Admin to visit the
 * Developer Portal first.
 */
class ClientApiKeyController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly ApiKeyBindingService $bindings)
    {
    }

    private const PRIMARY_KEY_NAME = 'Client API Key';
    private const KEY_PREFIX = 'wasaas_live_';

    /** GET /api/account/api-key — metadata only, never the plaintext (matches ApiKeyController's contract). */
    public function show(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');
        $key = $this->primaryKeyFor($account->id);

        if (! $key) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->present($key)]);
    }

    /**
     * POST /api/account/api-key/regenerate — revokes the current primary
     * key (if any; a revoked row is kept for audit trail, same as
     * ApiKeyController's destroy()) and issues a brand new one under the
     * same name. The plaintext is returned ONCE, same one-time-reveal
     * contract as ApiKeyController::store().
     */
    public function regenerate(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        // Same licence acknowledgement and authorized-server registration as the Developer Portal's key creation.
        $data = $request->validate([
            'acknowledge_server_binding' => ['accepted'],
            'server_label' => ['nullable', 'string', 'max:100'],
            'ip_policy' => ['nullable', 'string', 'in:SINGLE_IP,IP_ALLOWLIST'],
            'authorized_ips' => ['nullable', 'array', 'max:20'],
            'authorized_ips.*' => ['string', 'max:64'],
        ], ['acknowledge_server_binding.accepted' => (string) config('api_binding.warning')]);
        try {
            $this->bindings->normalizePolicy($data['ip_policy'] ?? null, $data['authorized_ips'] ?? []);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['authorized_ips' => [$e->getMessage()]]], 422);
        }

        $existing = $this->primaryKeyFor($account->id);
        if ($existing && ! $existing->isRevoked()) {
            $existing->forceFill(['revoked_at' => now()])->save();
        }

        $plainTextKey = self::KEY_PREFIX.Str::random(40);

        $key = ApiKey::create([
            'account_id' => $account->id,
            'name' => self::PRIMARY_KEY_NAME,
            'key_prefix' => substr($plainTextKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainTextKey),
        ]);
        $provisioned = $this->bindings->provision($key, [
            'label' => $data['server_label'] ?? null,
            'ip_policy' => $data['ip_policy'] ?? null,
            'authorized_ips' => $data['authorized_ips'] ?? [],
        ], $request->user());
        $this->bindings->record(ApiKeyBindingService::EV_KEY_CREATED, $key, $provisioned['binding'], null, [], actor: $request->user());

        return response()->json([
            'message' => 'Client API Key regenerated. Copy the key and installation credential now — neither will be shown again.',
            'plain_text_key' => $plainTextKey,
            'installation_credential' => $provisioned['credential'],
            'installation_header' => config('api_binding.installation_header'),
            'warning' => config('api_binding.warning'),
            'data' => $this->present($key),
        ]);
    }

    private function primaryKeyFor(int $accountId): ?ApiKey
    {
        return ApiKey::forAccount($accountId)
            ->where('name', self::PRIMARY_KEY_NAME)
            ->where('revoked_at', null)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'key_prefix' => $key->key_prefix,
            'created_at' => $key->created_at,
            'last_used_at' => $key->last_used_at,
            'server_binding' => $this->bindings->summary($key),
        ];
    }
}
