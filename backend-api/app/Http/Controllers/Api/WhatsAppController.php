<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\WhatsAppSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppController extends Controller
{
    use ResolvesTenantAccount;

    /**
     * GET /api/whatsapp/status — the account's last known connection status.
     * This is the source of truth for a page load / refresh; live updates
     * while the QR modal is open come from qr-engine-service's Socket.IO
     * stream instead, not this endpoint.
     */
    public function status(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'A WhatsApp session requires a selected tenant account (pass ?account_id=).');

        $session = WhatsAppSession::firstWhere('account_id', $account->id);

        return response()->json([
            'status' => $session->status ?? 'disconnected',
            'last_connected_at' => $session?->last_connected_at,
        ]);
    }

    /**
     * GET /api/admin/whatsapp/devices — Super Admin WhatsApp Device
     * Integration: one table of every tenant account's device status,
     * so a Super Admin doesn't have to select each account one at a time
     * just to see who's connected. Link/Disconnect/Reconnect from this
     * page are the SAME per-tenant endpoints below (status/start-session/
     * logout) — each already honors Super Admin's ?account_id= override
     * via ResolvesTenantAccount (see that trait's docblock), so this page
     * needed only a listing endpoint, not a parallel device-management
     * system.
     *
     * DISCLOSED INTERPRETATION: the spec's "link, view status, disconnect,
     * or reconnect system WhatsApp devices" is read as "every tenant's
     * device, managed from the Super Admin Portal" (plural "devices"),
     * not a single separate platform-owned device with no tenant. A
     * platform-owned device would need whatsapp_sessions.account_id to
     * become nullable (breaking its current UNIQUE tenant-per-row FK
     * invariant) and a sentinel value threaded through qr-engine-service's
     * Number()-coerced, `exists:accounts,id`-validated status callback —
     * real schema and cross-service risk for a capability the spec did
     * not unambiguously ask for. This reading reuses 100% of the existing,
     * already-battle-tested per-tenant flow instead.
     */
    public function adminIndex(): JsonResponse
    {
        $sessions = WhatsAppSession::query()->get()->keyBy('account_id');

        $devices = Account::query()
            ->orderBy('company_name')
            ->get(['id', 'company_name'])
            ->map(function (Account $account) use ($sessions) {
                $session = $sessions->get($account->id);

                return [
                    'account_id' => $account->id,
                    'company_name' => $account->company_name,
                    'status' => $session->status ?? 'disconnected',
                    'last_connected_at' => $session?->last_connected_at,
                ];
            })
            ->values();

        return response()->json(['data' => $devices]);
    }

    /**
     * GET /api/admin/whatsapp/self-device — the Super Admin's OWN WhatsApp
     * test connection (Account::platformDevice(), lazily created on first
     * call). MessageTemplateController::test() fires every test-send
     * through this exact same session. Deliberately NOT one of the
     * generic per-tenant routes below with a ?account_id= override — this
     * account is invisible to ordinary Account queries (see
     * Account::booted()'s exclude_platform_device global scope), so
     * TenantIsolationMiddleware's `Account::whereKey($id)->exists()` check
     * would 404 it; these three self-device endpoints instead resolve
     * Account::platformDevice() directly and sit in the same
     * permission:manage-accounts admin group as /admin/whatsapp/devices,
     * entirely outside tenant.isolation.
     */
    public function selfDeviceStatus(): JsonResponse
    {
        $account = Account::platformDevice();
        $session = WhatsAppSession::firstWhere('account_id', $account->id);

        return response()->json([
            'account_id' => $account->id,
            'status' => $session->status ?? 'disconnected',
            'last_connected_at' => $session?->last_connected_at,
        ]);
    }

    /** POST /api/admin/whatsapp/self-device/start-session — see selfDeviceStatus()'s docblock. */
    public function selfDeviceStartSession(): JsonResponse
    {
        return $this->forwardToQrEngine('start-session', Account::platformDevice()->id);
    }

    /** POST /api/admin/whatsapp/self-device/logout — see selfDeviceStatus()'s docblock. */
    public function selfDeviceLogout(): JsonResponse
    {
        return $this->forwardToQrEngine('logout', Account::platformDevice()->id);
    }

    /**
     * POST /api/whatsapp/start-session — asks qr-engine-service to open (or
     * resume) this account's Baileys session. The QR code itself streams to
     * the browser over Socket.IO, not in this response.
     */
    public function startSession(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'A WhatsApp session requires a selected tenant account (pass ?account_id=).');

        return $this->forwardToQrEngine('start-session', $account->id);
    }

    /**
     * POST /api/whatsapp/logout — ends the session and clears its auth files.
     */
    public function logout(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'A WhatsApp session requires a selected tenant account (pass ?account_id=).');

        return $this->forwardToQrEngine('logout', $account->id);
    }

    /**
     * [Bug fix, disclosed]: previously this returned qr-engine-service's
     * raw HTTP status verbatim (`$response->status()`) to the browser. If
     * qr-engine-service's requireInternalSecret() middleware rejects the
     * X-Internal-Secret header (e.g. INTERNAL_API_SECRET differs between
     * backend-api's .env and qr-engine-service's .env — both are
     * git-ignored, machine-local files that must be kept identical by
     * hand; see qr-engine-service/.env.example), it replies 401 — and
     * that bare 401 reached the browser as the response to a perfectly
     * authenticated Laravel request. frontend-app's axios interceptor
     * (axiosInstance.ts) treats ANY 401 from ANY endpoint as "this
     * user's own session token is invalid" and force-logs them out
     * (AuthContext's AUTH_EVENT_UNAUTHORIZED handler) — so an internal
     * service-to-service credential mismatch masqueraded as the user's
     * own WhatsApp Cloud API / Sanctum session being invalid, logging
     * out a perfectly-authenticated user the instant they clicked
     * Connect WhatsApp. Root cause confirmed by reading server.js's
     * requireInternalSecret() and axiosInstance.ts's response
     * interceptor together.
     *
     * Fix: an upstream 401/403 (an authentication/authorization failure
     * between OUR OWN two backend services) is remapped to 502 Bad
     * Gateway before it ever reaches the browser — a 502 is not
     * special-cased by the frontend interceptor, so it surfaces as an
     * ordinary "failed to start" error/toast instead of a global logout.
     * Every other upstream failure status (422 bad payload, 500, etc.)
     * still passes through unchanged, exactly as before this fix — only
     * 401/403 are remapped, since those are the two statuses the
     * frontend's global interceptor treats as "log the user out."
     */
    /**
     * [Timeout hardening, disclosed]: connectTimeout() and timeout() are
     * set SEPARATELY (previously only a single ->timeout(10) covered
     * both). connectTimeout() bounds only the TCP handshake — the phase
     * that actually hangs when the target host/port is unreachable or
     * mis-resolved (e.g. the 'localhost' -> ::1 vs 127.0.0.1 ambiguity
     * this same fix's config/services.php change addresses) — while
     * timeout() bounds the request end-to-end including qr-engine-
     * service's own response time. Both at 5s per spec; previously a
     * hung TCP connect could silently eat the full 10s before the
     * generic timeout ever kicked in.
     */
    private function forwardToQrEngine(string $path, int $accountId): JsonResponse
    {
        $baseUrl = rtrim((string) config('services.qr_engine.url'), '/');
        $secret = config('services.qr_engine.internal_secret');

        // [Local-dev fallback, disclosed]: config('services.qr_engine.internal_secret')
        // now silently resolves to a shared placeholder when INTERNAL_API_SECRET is
        // unset AND APP_ENV=local (see config/services.php). Logged here — not in the
        // config file itself, to avoid a warning on every config resolution — as a
        // soft warning, not a rejection: the request still proceeds.
        if (! env('INTERNAL_API_SECRET') && app()->environment('local')) {
            Log::warning('INTERNAL_API_SECRET is not set in backend-api/.env — using the local-dev fallback shared secret (must match qr-engine-service\'s own fallback). Set a real INTERNAL_API_SECRET before this runs anywhere but your own machine.');
        }

        try {
            $response = Http::withHeaders(['X-Internal-Secret' => $secret])
                ->connectTimeout(5)
                ->timeout(5)
                ->post("{$baseUrl}/api/qr/{$path}", ['account_id' => $accountId]);
        } catch (Throwable $e) {
            Log::error('qr-engine-service is unreachable — connection or request timed out or failed outright.', [
                'path' => $path,
                'account_id' => $accountId,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The WhatsApp engine service is unreachable. Please try again shortly.',
            ], 502);
        }

        if ($response->failed()) {
            $status = $response->status();

            if ($status === 401 || $status === 403) {
                Log::error('qr-engine-service rejected our internal request (401/403) — likely an INTERNAL_API_SECRET mismatch between backend-api/.env and qr-engine-service/.env on this machine. Remapped to 502 so this never masquerades as the calling user\'s own session being invalid.', [
                    'path' => $path,
                    'account_id' => $accountId,
                    'upstream_status' => $status,
                ]);

                return response()->json([
                    'message' => 'The WhatsApp engine service rejected the request (internal configuration error).',
                ], 502);
            }

            return response()->json([
                'message' => $response->json('message') ?? 'The WhatsApp engine service rejected the request.',
            ], $status);
        }

        return response()->json($response->json());
    }
}
