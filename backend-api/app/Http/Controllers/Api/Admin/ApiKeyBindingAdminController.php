<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ApiKeyChangeRequest;
use App\Models\ApiKeySecurityEvent;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use App\Services\ApiAccess\ApiKeyCooldownActiveException;
use App\Services\ApiAccess\InstallationAllowanceExceededException;

/**
 * Super Admin control of Public API authorized-server bindings (route group: role:super_admin). Everything here
 * works on api_keys by id and shows only safe fields: never the plaintext key, the key hash, the installation
 * credential or its hash.
 */
class ApiKeyBindingAdminController extends Controller
{
    public function __construct(
        private readonly ApiKeyBindingService $bindings,
        private readonly InstallationAllowanceResolver $allowance,
    ) {
    }

    /** GET /api/admin/api-access?status=&account_id= - every key with its binding summary. */
    public function index(Request $request): JsonResponse
    {
        $query = ApiKey::with('account:id,company_name')->latest('id');
        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->query('account_id'));
        }
        $keys = $query->limit(500)->get();

        // Phase 4 Task 12 — account-scoped installation usage/allowance,
        // computed once per distinct account on this page rather than
        // once per key row, and read from the exact same authoritative
        // seams every enforcement path already uses
        // (countLiveInstallations()/InstallationAllowanceResolver) — no
        // second counting implementation.
        $usageByAccount = [];
        foreach ($keys->pluck('account_id')->unique() as $accountId) {
            $account = Account::find($accountId);
            if ($account === null) {
                continue;
            }
            $live = $this->bindings->countLiveInstallations($accountId);
            $resolved = $this->allowance->resolveForAccount($account);
            $usageByAccount[$accountId] = [
                'live' => $live,
                'allowance' => $resolved['allowance'],
                'at_or_over_allowance' => $live >= $resolved['allowance'],
            ];
        }

        $rows = $keys->map(function (ApiKey $k) use ($usageByAccount) {
            return [
                'id' => $k->id,
                'name' => $k->name,
                'key_prefix' => $k->key_prefix,
                'account_id' => $k->account_id,
                'account_name' => $k->account?->company_name,
                'revoked_at' => $k->revoked_at?->toIso8601String(),
                'last_used_at' => $k->last_used_at?->toIso8601String(),
                'server_binding' => $this->bindings->summary($k),
                'installation_usage' => $usageByAccount[$k->account_id] ?? null,
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

    /**
     * DELETE /api/admin/api-access/{keyId} - Phase 4 Task 12. Super
     * Admin equivalent of ApiKeyController::destroy() (which only the
     * owning tenant could reach before this) - same delegation to
     * ApiKeyBindingService::destroyKey(), so the key's revoke +
     * binding-release + cooldown-start sequence is identical regardless
     * of who triggers it.
     */
    public function destroy(Request $request, int $keyId): JsonResponse
    {
        $key = ApiKey::findOrFail($keyId);
        $key = $this->bindings->destroyKey($key, $request->user());

        return response()->json(['message' => 'API key destroyed.', 'data' => $this->bindings->summary($key->fresh())]);
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
        } catch (InstallationAllowanceExceededException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        } catch (ApiKeyCooldownActiveException $e) {
            // Phase 4 Task 9 — this key is still in cooldown from an
            // earlier operation; rebind() routes through the same
            // allowance-enforced seam that now also checks cooldown.
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
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

    /**
     * POST /api/admin/api-access/{keyId}/cooldown - Phase 4 Task 9,
     * Super Admin-only. Gated by the same role:super_admin route
     * middleware as every other action in this controller (see the
     * class docblock and routes/api.php) - no separate authorization
     * check is added here, exactly like revoke()/rebind() above.
     *
     * 'until' is optional: omit it (or send a past/current timestamp)
     * to clear the cooldown outright; send a future timestamp to set
     * or shorten it. 'reason' is always required.
     */
    public function overrideCooldown(Request $request, int $keyId): JsonResponse
    {
        $data = $request->validate([
            'until' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $key = ApiKey::findOrFail($keyId);
        $until = $data['until'] !== null ? \Illuminate\Support\Carbon::parse($data['until']) : now();

        try {
            $this->bindings->overrideCooldown($key, $until, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Cooldown updated.', 'data' => $this->bindings->summary($key->fresh())]);
    }

    /** GET /api/admin/api-access/{keyId}/events - the last 100 safe security events for the key. */
    public function events(int $keyId): JsonResponse
    {
        $key = ApiKey::findOrFail($keyId);
        $events = ApiKeySecurityEvent::where('api_key_id', $key->id)->latest('id')->limit(100)->get(['id', 'event', 'ip', 'binding_id', 'context', 'created_at']);

        return response()->json(['data' => $events]);
    }

    /**
     * GET /api/admin/api-access/legacy-retirement-gate - Phase 4 Task 14.
     * The one authoritative, platform-wide check for whether the legacy
     * Account.authorized_server_ip mechanism can safely be retired:
     * retirement is only correct once count === 0. The listed keys are
     * exactly what a Super Admin needs to reconcile each one (bind it,
     * or deliberately leave it) - never a credential, hash or secret.
     */
    public function legacyRetirementGate(): JsonResponse
    {
        $count = $this->bindings->countLegacyIpDependentKeys();

        return response()->json([
            'legacy_ip_dependent_key_count' => $count,
            'retirement_safe' => $count === 0,
            'keys' => $this->bindings->legacyIpDependentKeys(),
        ]);
    }

    private function decide(callable $action, string $message): JsonResponse
    {
        try {
            $result = $action();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InstallationAllowanceExceededException $e) {
            // Phase 4 Task 6 — approve() can now throw this when a
            // one-for-one replacement would exceed the account's
            // allowance (e.g. the allowance was lowered since the old
            // binding was created). The whole approve() transaction
            // rolled back, so the old binding is still live/unchanged.
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        } catch (ApiKeyCooldownActiveException $e) {
            // Phase 4 Task 9 — approve() routes through the same
            // allowance-enforced seam, which now also checks cooldown;
            // the whole approve() transaction rolled back the same way.
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        }

        return response()->json(['message' => $message, 'data' => $result->toSafeArray()]);
    }
}
