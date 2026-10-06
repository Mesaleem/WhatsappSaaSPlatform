<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ApiKey;
use App\Services\ApiAccess\ServerIpBindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The account's Developer API key and its one authorized server IP, for the Profile → API key screen.
 *
 * One simple, always-present key per account, stored in the same api_keys table as the Developer Portal's keys.
 * The server IP lives on the account (accounts.authorized_server_ip), so it can be set before a key exists and
 * applies to every key of the account. Requests are allowed only from that IP (ApiKeyBindingService::gate()).
 */
class ClientApiKeyController extends Controller
{
    use ResolvesTenantAccount;

    private const PRIMARY_KEY_NAME = 'Client API Key';
    private const KEY_PREFIX = 'wasaas_live_';

    public function __construct(private readonly ServerIpBindingService $serverIp)
    {
    }

    /** GET /api/account/api-key — metadata only, never the plaintext. Includes the server IP state. */
    public function show(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');
        $key = $this->primaryKeyFor($account->id);

        return response()->json([
            'data' => $key ? $this->present($key) : null,
            'server_ip' => $this->serverIp->status($account),
        ]);
    }

    /**
     * PUT /api/account/api-key/server-ip — saves the one IP that may send with this account's keys.
     * The first save is free; the rules for any later change are in ServerIpBindingService.
     */
    public function setServerIp(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        $data = $request->validate([
            'authorized_server_ip' => ['required', 'string', 'max:45'],
        ]);

        $result = $this->serverIp->setIp($account, $data['authorized_server_ip']);

        if (! $result['ok']) {
            return response()->json(['success' => false, 'status' => false, 'error' => $result['code'], 'message' => $result['message']] + $result['extra'], $result['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Server IP saved. API requests are now accepted only from this address.',
            'server_ip' => $result['extra']['server_ip'],
        ]);
    }

    /**
     * POST /api/account/api-key/regenerate — revokes the current primary key (kept for the audit trail) and issues a
     * new one under the same name. The plaintext is returned once.
     */
    public function regenerate(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        $request->validate(
            ['acknowledge_ip_restriction' => ['accepted']],
            ['acknowledge_ip_restriction.accepted' => 'Please confirm that this key works only from your registered server IP.'],
        );

        // A key is useless without its server IP, so refuse to create one before the IP is saved.
        if ($account->authorized_server_ip === null) {
            return response()->json([
                'success' => false,
                'status' => false,
                'error_code' => 'SERVER_IP_REQUIRED',
                'message' => 'Enter and save your server IP address before you generate an API key.',
            ], 422);
        }

        $existing = $this->primaryKeyFor($account->id);
        if ($existing) {
            $existing->forceFill(['revoked_at' => now()])->save();
        }

        $plainTextKey = self::KEY_PREFIX.Str::random(40);

        $key = ApiKey::create([
            'account_id' => $account->id,
            'name' => self::PRIMARY_KEY_NAME,
            'key_prefix' => substr($plainTextKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainTextKey),
        ]);

        return response()->json([
            'message' => 'Client API key generated. Copy it now, it will not be shown again.',
            'plain_text_key' => $plainTextKey,
            'data' => $this->present($key),
            'server_ip' => $this->serverIp->status($account),
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

    /** @return array<string, mixed> */
    private function present(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'key_prefix' => $key->key_prefix,
            'created_at' => $key->created_at,
            'last_used_at' => $key->last_used_at,
        ];
    }
}
