<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiKeyChangeRequest;
use App\Models\ApiKeySecurityEvent;
use App\Services\ApiAccess\ApiKeyBindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Super Admin control of Public API authorized-server bindings (route group: role:super_admin). Everything here
 * works on api_keys by id and shows only safe fields: never the plaintext key, the key hash, the installation
 * credential or its hash.
 */
class ApiKeyBindingAdminController extends Controller
{
    public function __construct(private readonly ApiKeyBindingService $bindings)
    {
    }

    /** GET /api/admin/api-access?status=&account_id= - every key with its binding summary. */
    public function index(Request $request): JsonResponse
    {
        $query = ApiKey::with('account:id,company_name')->latest('id');
        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->query('account_id'));
        }
        $rows = $query->limit(500)->get()->map(function (ApiKey $k) {
            return [
                'id' => $k->id,
                'name' => $k->name,
                'key_prefix' => $k->key_prefix,
                'account_id' => $k->account_id,
                'account_name' => $k->account?->company_name,
                'revoked_at' => $k->revoked_at?->toIso8601String(),
                'last_used_at' => $k->last_used_at?->toIso8601String(),
                'server_binding' => $this->bindings->summary($k),
            ];
        });
        if ($request->filled('status')) {
            $rows = $rows->filter(fn ($r) => $r['server_binding']['status'] === $request->query('status'))->values();
        }

        return response()->json(['data' => $rows->all()]);
    }

    /** GET /api/admin/api-access/change-requests?status=pending */
    public function changeRequests(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', ApiKeyChangeRequest::STATUS_PENDING);
        $rows = ApiKeyChangeRequest::with(['apiKey:id,name,key_prefix,account_id', 'account:id,company_name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('id')->limit(200)->get()
            ->map(fn (ApiKeyChangeRequest $r) => $r->toSafeArray() + [
                'account_id' => $r->account_id,
                'account_name' => $r->account?->company_name,
                'key_name' => $r->apiKey?->name,
                'key_prefix' => $r->apiKey?->key_prefix,
            ]);

        return response()->json(['data' => $rows->all()]);
    }

    public function approve(Request $request, int $requestId): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->decide(fn () => $this->bindings->approve(ApiKeyChangeRequest::findOrFail($requestId), $request->user(), $note), 'Server change approved. The previous server is revoked; the owner must now issue the installation credential for the new server.');
    }

    public function reject(Request $request, int $requestId): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->decide(fn () => $this->bindings->reject(ApiKeyChangeRequest::findOrFail($requestId), $request->user(), $note), 'Server change rejected. The current server is unchanged.');
    }

    public function revoke(Request $request, int $keyId): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $key = ApiKey::findOrFail($keyId);
        $revoked = $this->bindings->revoke($key, $request->user(), $reason);

        return response()->json(['message' => $revoked ? 'Authorized server revoked. The key can no longer be used until a server is bound.' : 'This key has no authorized server to revoke.', 'data' => $this->bindings->summary($key->fresh())]);
    }

    public function rebind(Request $request, int $keyId): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'ip_policy' => ['required', 'string', 'in:NONE,SINGLE_IP,IP_ALLOWLIST'],
            'authorized_ips' => ['nullable', 'array', 'max:20'],
            'authorized_ips.*' => ['string', 'max:64'],
        ]);
        $key = ApiKey::findOrFail($keyId);
        if ($key->isRevoked()) {
            return response()->json(['message' => 'This API key has been revoked.'], 422);
        }
        try {
            $this->bindings->rebind($key, $request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Key re-bound. The previous server is revoked; the owner must issue the installation credential for the new server.', 'data' => $this->bindings->summary($key->fresh())]);
    }

    public function disable(Request $request, int $keyId): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:191']])['reason'] ?? null;
        $key = ApiKey::findOrFail($keyId);
        $this->bindings->setAccessDisabled($key, $request->user(), true, $reason);

        return response()->json(['message' => 'API access disabled for this key.', 'data' => $this->bindings->summary($key->fresh())]);
    }

    public function enable(Request $request, int $keyId): JsonResponse
    {
        $key = ApiKey::findOrFail($keyId);
        $this->bindings->setAccessDisabled($key, $request->user(), false);

        return response()->json(['message' => 'API access re-enabled for this key.', 'data' => $this->bindings->summary($key->fresh())]);
    }

    /** GET /api/admin/api-access/{keyId}/events - the last 100 safe security events for the key. */
    public function events(int $keyId): JsonResponse
    {
        $key = ApiKey::findOrFail($keyId);
        $events = ApiKeySecurityEvent::where('api_key_id', $key->id)->latest('id')->limit(100)->get(['id', 'event', 'ip', 'binding_id', 'context', 'created_at']);

        return response()->json(['data' => $events]);
    }

    private function decide(callable $action, string $message): JsonResponse
    {
        try {
            $result = $action();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => $message, 'data' => $result->toSafeArray()]);
    }
}
