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
            // Only meaningful while connected; the webhook clears it otherwise.
            'phone_number' => ($session?->status === 'connected') ? $session?->connected_phone_number : null,
            // The default number slot -- auto-created here (not just on start-session)
            // so it is already known by the time the user opens the connect modal; the
            // Socket.IO connection it opens joins the room keyed by THIS id, which
            // must match whatever id the start-session call goes on to send
            // qr-engine-service, or the QR never reaches the browser (see
            // ensureDefaultSlot()'s own docblock).
            'number_id' => $this->ensureDefaultSlot($account)->id,
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
            // [Bug fix, disclosed]: the browser's Socket.IO connection must join the
            // same room qr-engine-service broadcasts to for this device's QR/
            // connection-update events. Since platformDeviceSlot() started sending
            // session_id (the slot's own id, not the account id) to qr-engine-service
            // on start-session, that room is keyed by this id, not account_id -- the
            // frontend needs it to pass as QRScannerModal's numberId prop, exactly
            // like it already does for an ordinary tenant's own default slot.
            'number_id' => $this->platformDeviceSlot($account)->id,
            'status' => $session->status ?? 'disconnected',
            'last_connected_at' => $session?->last_connected_at,
        ]);
    }

    /**
     * POST /api/admin/whatsapp/self-device/start-session — see selfDeviceStatus()'s
     * docblock. [Owner instruction, disclosed]: like any other account's default
     * slot, this now supports pairing-code (phone number) login too, not just QR --
     * it shares buildSessionExtras()'s own rules with startSession() below.
     */
    public function selfDeviceStartSession(Request $request): JsonResponse
    {
        $account = Account::platformDevice();
        $slot = $this->platformDeviceSlot($account);

        $data = $request->validate(['phone_number' => $this->phoneNumberRules()]);
        $typed = isset($data['phone_number']) ? preg_replace('/\D+/', '', $data['phone_number']) : null;

        $extra = $this->buildSessionExtras($slot, $typed);
        if ($extra instanceof JsonResponse) {
            return $extra;
        }

        return $this->forwardToQrEngine('start-session', $account->id, $extra);
    }

    /** POST /api/admin/whatsapp/self-device/logout — see selfDeviceStatus()'s docblock. */
    public function selfDeviceLogout(): JsonResponse
    {
        $account = Account::platformDevice();
        $slot = $this->platformDeviceSlot($account);

        return $this->forwardToQrEngine('logout', $account->id, ['session_id' => $slot->id], 20);
    }

    /**
     * [Bug fix, disclosed]: the platform device previously sent only its ACCOUNT id
     * to qr-engine-service, with no session_id at all, expecting a "legacy
     * account-keyed session" — but whatsapp_engine_auth_states.whatsapp_number_id
     * has had a hard foreign key to whatsapp_numbers since the multi-number
     * refactor, so qr-engine-service's auth-state calls (keyed by session_id when
     * given, else the account id — see server.js's sessionKeyFrom()) 404'd the
     * moment it tried to store Baileys' credentials under an id with no
     * whatsapp_numbers row. Unlike an ordinary tenant, the Super Admin never types
     * a number in advance (WhatsAppNumberService::add() is never offered here), so
     * a single placeholder slot is lazily created on first connect instead — its
     * phone_number is never validated against anything real: WhatsAppStatusController
     * ::update() adopts whatever number actually signs in for this one account
     * (is_platform_device) rather than refusing it as a "different number" mismatch,
     * the way every ordinary tenant's pre-typed slot still does.
     */
    private function platformDeviceSlot(Account $account): \App\Models\WhatsAppNumber
    {
        return \App\Models\WhatsAppNumber::query()->firstOrCreate(
            ['account_id' => $account->id],
            [
                'phone_number' => \App\Models\WhatsAppNumber::PENDING_PLACEHOLDER_PREFIX.$account->id,
                'is_included' => true,
                'is_default' => true,
                'status' => \App\Models\WhatsAppNumber::STATUS_UNLINKED,
            ],
        );
    }

    /**
     * POST /api/whatsapp/start-session — asks qr-engine-service to open (or
     * resume) this account's Baileys session. The QR code itself streams to
     * the browser over Socket.IO, not in this response.
     */
    public function startSession(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'A WhatsApp session requires a selected tenant account (pass ?account_id=).');

        // Optional phone_number switches this session to pairing-code login:
        // WhatsApp shows an 8-character code for the user to enter on the phone
        // (Linked devices -> Link with phone number). Without it, the QR flow
        // runs exactly as before.
        $data = $request->validate(['phone_number' => $this->phoneNumberRules()]);

        $slot = $this->resolveSlot($request, $account);
        if ($slot instanceof JsonResponse) {
            return $slot;
        }

        $typed = isset($data['phone_number']) ? preg_replace('/\D+/', '', $data['phone_number']) : null;
        $extra = $this->buildSessionExtras($slot, $typed);
        if ($extra instanceof JsonResponse) {
            return $extra;
        }

        return $this->forwardToQrEngine('start-session', $account->id, $extra);
    }

    /** The phone_number validation rules shared by startSession() and selfDeviceStartSession(). */
    private function phoneNumberRules(): array
    {
        return [
            'nullable',
            'string',
            'max:25',
            function (string $attribute, mixed $value, \Closure $fail): void {
                $digits = (string) preg_replace('/\D+/', '', (string) $value);

                // Exactly 10 digits = a national number without its country code.
                if (strlen($digits) === 10) {
                    $fail('This number is missing its country code. Add it in front, for example 91 for India.');

                    return;
                }

                if (! preg_match('/^[1-9][0-9]{7,14}$/', $digits)) {
                    $fail('Enter the full phone number with country code and no + or spaces, for example 919876543210.');
                }
            },
        ];
    }

    /**
     * The qr-engine-service `start-session` payload extras for one slot + typed
     * number, shared by startSession() and selfDeviceStartSession() so the
     * platform device's own slot follows the exact same rule as every other
     * account's default slot: a number slot links only to the number it was
     * added with, and a typed number that differs is refused here, before any
     * WhatsApp call -- unless this slot has no real number yet at all (the
     * default slot's placeholder, see WhatsAppNumber::PENDING_PLACEHOLDER_PREFIX's
     * own docblock), in which case there is nothing yet to compare against and
     * whatever is typed is accepted.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function buildSessionExtras(\App\Models\WhatsAppNumber $slot, ?string $typed): array|JsonResponse
    {
        if ($typed !== null && ! $slot->hasPendingPlaceholderNumber() && $typed !== $slot->phone_number) {
            return response()->json([
                'message' => 'This number is not the one added to this WhatsApp slot.',
                'error_code' => 'number_mismatch',
            ], 422);
        }

        $extra = ['session_id' => $slot->id];
        // qr-engine-service only enforces a number match when expected_phone is sent
        // at all (see sessionManager.js's `if (expectedPhone)`) -- omitted here for a
        // placeholder slot so ANY real WhatsApp account can connect to it.
        if (! $slot->hasPendingPlaceholderNumber()) {
            $extra['expected_phone'] = $slot->phone_number;
        }
        if ($typed !== null) {
            $extra['phone_number'] = $typed;
        }

        return $extra;
    }

    /**
     * POST /api/whatsapp/logout — ends the session of the requested number slot
     * (or the default one) and clears its stored login.
     */
    public function logout(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'A WhatsApp session requires a selected tenant account (pass ?account_id=).');

        $slot = $this->resolveSlot($request, $account);
        if ($slot instanceof JsonResponse) {
            return $slot;
        }

        return $this->forwardToQrEngine('logout', $account->id, ['session_id' => $slot->id], 20);
    }

    /**
     * The number slot a session request is about: the one named by `number_id`
     * (must belong to this account -- always an ADD-ON slot in practice, since
     * nothing but the default slot is ever reached implicitly), or the account's
     * default slot, auto-created if this account has none at all yet (see
     * ensureDefaultSlot()'s own docblock). An explicitly named `number_id` is never
     * auto-created -- only ADDING a number (WhatsAppNumberService::add(), a real,
     * typed, paid-for number) can bring an add-on slot into existence.
     *
     * @return \App\Models\WhatsAppNumber|JsonResponse|null
     */
    private function resolveSlot(Request $request, Account $account): \App\Models\WhatsAppNumber|JsonResponse|null
    {
        $numberId = $request->query('number_id', $request->input('number_id'));

        if ($numberId !== null && $numberId !== '') {
            $slot = \App\Models\WhatsAppNumber::query()
                ->where('account_id', $account->id)
                ->whereKey((int) $numberId)
                ->first();

            return $slot ?? response()->json(['message' => 'WhatsApp number not found.', 'error_code' => 'not_found'], 404);
        }

        return $this->ensureDefaultSlot($account);
    }

    /**
     * [Owner instruction, disclosed]: the account's default (included, free) slot no
     * longer requires its number to be typed in before the first connect -- an
     * account with no WhatsApp number at all yet gets one auto-created here, with a
     * placeholder phone_number (WhatsAppNumber::PENDING_PLACEHOLDER_PREFIX), so
     * "Connect WhatsApp" works immediately. An ADD-ON (paid extra) slot is
     * unaffected -- it only ever exists once a real number was typed and added
     * (WhatsAppNumberService::add()), never auto-created here. status() calls this
     * too (not just start-session), so the slot -- and its id -- already exists by
     * the time the connect modal opens; its Socket.IO connection and the
     * start-session call must agree on that id or the QR never reaches the browser.
     */
    private function ensureDefaultSlot(Account $account): \App\Models\WhatsAppNumber
    {
        $query = \App\Models\WhatsAppNumber::query()->where('account_id', $account->id);

        $default = (clone $query)->where('is_default', true)->first();
        if ($default !== null) {
            return $default;
        }

        if ((clone $query)->exists()) {
            // Has at least one slot, none marked default -- should not happen in
            // practice (the first slot WhatsAppNumberService::add() ever creates for
            // an account is always is_default); the oldest slot is the least-wrong
            // fallback rather than creating a second, competing "default".
            return (clone $query)->orderBy('id')->first();
        }

        return \App\Models\WhatsAppNumber::query()->create([
            'account_id' => $account->id,
            'phone_number' => \App\Models\WhatsAppNumber::PENDING_PLACEHOLDER_PREFIX.$account->id,
            'is_included' => true,
            'is_default' => true,
            'status' => \App\Models\WhatsAppNumber::STATUS_UNLINKED,
        ]);
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
    private function forwardToQrEngine(string $path, int $accountId, array $extra = [], int $timeoutSeconds = 5): JsonResponse
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
                ->timeout($timeoutSeconds)
                ->post("{$baseUrl}/api/qr/{$path}", ['account_id' => $accountId, ...$extra]);
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
