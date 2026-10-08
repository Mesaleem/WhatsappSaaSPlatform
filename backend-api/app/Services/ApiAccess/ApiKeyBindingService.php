<?php

namespace App\Services\ApiAccess;

use App\Models\Account;
use App\Services\ApiAccess\ApiKeyCooldownActiveException;
use App\Services\ApiAccess\InstallationAllowanceExceededException;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Models\ApiKeyChangeRequest;
use App\Models\ApiKeySecurityEvent;
use App\Models\User;
use App\Support\IpMatcher;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Public API authorized-server binding - the single place that decides whether a request carrying a VALID API key
 * may use it from THIS server. Called from the two shared API authentication middlewares (AuthenticateApiKey,
 * ApiAuthMiddleware) so every /api/v1 route is covered and no controller repeats the check.
 *
 * Mechanism: when a key is created (or a server change is approved) a binding is registered. The server proves it
 * is the authorized one by presenting the INSTALLATION CREDENTIAL (random, 40 chars, shown once, stored only as a
 * sha256 hash) in `X-Client-Installation`. A client-chosen "server id" string is never trusted. The client IP is
 * Request::ip(), which honours the trusted-proxy configuration (none is configured in this repo today — see
 * bootstrap/app.php; gate() never reads X-Forwarded-For/X-Real-IP itself).
 *
 * PHASE 4 TASK 7 UPDATE — the actual enforcement order inside gate() is now binding-first, not account-IP-first:
 *
 *   access disabled -> key->liveBinding() exists?
 *     yes (Case A, gateBoundKey()):   the binding is AUTHORITATIVE. Only the installation credential is checked
 *                                      against THIS binding's installation_hash — Account.authorized_server_ip is
 *                                      never read in this branch, not even as a fallback for a missing/invalid/
 *                                      credential_pending credential. (An earlier revision of this docblock
 *                                      described the binding's own ip_policy/authorized_ips as a second factor
 *                                      here; no code ever enforced that at request time, and Task 7's own spec
 *                                      does not ask for it either — correcting this docblock rather than
 *                                      inventing that check now.)
 *     no  (Case B, gateUnboundLegacyKey()): the TEMPORARY legacy-IP migration path, gated by THIS key's own
 *                                      Task 5 grace deadline (api_keys.legacy_binding_grace_expires_at, extendable
 *                                      per-key via extendLegacyDeadline() — never account-wide, never dynamic).
 *                                      Unconditionally refused with API_SERVER_BINDING_REQUIRED if the account
 *                                      has no IP at all, or the effective deadline is NULL/expired — there is
 *                                      NO override of any kind (Task 7 FIX: the former
 *                                      api_binding.allow_legacy_unbound emergency override has been removed
 *                                      from this method entirely; it is never read here, under any config value).
 *
 * Every denial stays generic (no binding IDs, hashes, or "which factor failed" ever leaked), but is no longer a
 * single response code — see gate()'s own docblock for the exact code mapping (CREDENTIAL_REQUIRED/
 * CREDENTIAL_INVALID/BINDING_REQUIRED/DENIED); the security event recorded still shows which branch fired.
 *
 * PHASE 4 TASK 3 UPDATE: every binding row this class creates already
 * went through exactly one private constructor, createLive() — called
 * only from provision(), approve() and rebind() (confirmed by grep: no
 * other file ever calls `ApiKeyBinding::create()`), and the schema's
 * own `akb_one_live_binding_per_key` unique index already guaranteed at
 * most one live row per key. Task 3 adds nothing to that architecture;
 * it only exposes the missing read-side primitive — countLiveInstallations()
 * below — so Task 6 has one authoritative place to count an account's
 * occupied installation slots instead of re-deriving the status list
 * itself. See ApiKeyBinding::LIVE_STATUSES for the single definition of
 * "live" both this method and isLive()/liveBinding() now share.
 */
class ApiKeyBindingService
{
    public const DENIED = 'API_CLIENT_NOT_AUTHORIZED';
    public const DISABLED = 'API_ACCESS_DISABLED';
    /**
     * Phase 4 Task 7 — this is the project's existing, already-tested
     * error code for "no live binding and no usable legacy path" (see
     * tests/Feature/ServerIpBindingTest.php's existing assertions on it).
     * The task spec's own vocabulary calls this condition
     * INSTALLATION_BINDING_REQUIRED; this class keeps the established
     * literal string instead of introducing a second, differently-spelled
     * code for the exact same condition — see this class's own docblock
     * mapping table below gate().
     */
    public const BINDING_REQUIRED = 'API_SERVER_BINDING_REQUIRED';
    /** Phase 4 Task 7 — bound key, no X-Client-Installation header presented at all. */
    public const CREDENTIAL_REQUIRED = 'INSTALLATION_CREDENTIAL_REQUIRED';
    /** Phase 4 Task 7 — bound key, a credential WAS presented but did not verify against this binding (wrong, or binding is credential_pending). */
    public const CREDENTIAL_INVALID = 'INSTALLATION_CREDENTIAL_INVALID';

    public const EV_KEY_CREATED = 'api_key_created';
    public const EV_SERVER_REGISTERED = 'server_registered';
    public const EV_AUTH_OK = 'authorized_server_authenticated';
    public const EV_UNAUTH_SERVER = 'unauthorized_server_attempt';
    public const EV_UNAUTH_IP = 'unauthorized_ip_attempt';
    public const EV_BINDING_REQUIRED = 'server_binding_required';
    public const EV_LEGACY_OVERRIDE_USED = 'legacy_unbound_override_used';
    public const EV_ACCESS_DISABLED_ATTEMPT = 'disabled_key_attempt';
    public const EV_CHANGE_REQUESTED = 'server_change_requested';
    public const EV_CHANGE_APPROVED = 'server_change_approved';
    public const EV_CHANGE_REJECTED = 'server_change_rejected';
    public const EV_BINDING_REVOKED = 'server_binding_revoked';
    public const EV_REBOUND = 'server_rebound';
    public const EV_CREDENTIAL_ISSUED = 'installation_credential_issued';
    public const EV_ACCESS_DISABLED = 'api_access_disabled';
    public const EV_ACCESS_ENABLED = 'api_access_enabled';
    public const EV_LEGACY_BACKFILL_BOUND = 'legacy_backfill_bound';
    public const EV_KEY_DESTROYED = 'api_key_destroyed';
    /** Phase 4 Task 7 — bound key, request had no X-Client-Installation header. */
    public const EV_CREDENTIAL_MISSING = 'installation_credential_missing';
    /** Phase 4 Task 7 — bound key, presented credential did not verify (wrong, or credential_pending). */
    public const EV_CREDENTIAL_INVALID = 'installation_credential_invalid';
    /** Phase 4 Task 7 — unbound legacy-IP-dependent key whose effective deadline (base + extension) has passed, or was never set. Distinct from EV_BINDING_REQUIRED (no account IP / not legacy-dependent at all), for audit clarity — both map to the same BINDING_REQUIRED response code. */
    public const EV_LEGACY_DEADLINE_EXPIRED = 'legacy_deadline_expired';
    /** Phase 4 Task 7 — the per-key deadline-extension audit record (see extendLegacyDeadline()/effectiveLegacyDeadline()). */
    public const EV_LEGACY_DEADLINE_EXTENDED = 'legacy_deadline_extended';

    // Phase 4 Task 9
    public const EV_COOLDOWN_OVERRIDDEN = 'cooldown_overridden';

    // Phase 4 Task 11 — audit coverage gaps found and closed: a
    // cooldown STARTING (by revoke or by a completed transfer) and a
    // cooldown DENYING an attempt were both silent before this task;
    // every other state-changing/denial path in this class already
    // records something, so these two were the exceptions, not new
    // behavior being introduced.
    public const EV_COOLDOWN_STARTED = 'cooldown_started';

    public const EV_COOLDOWN_DENIED = 'cooldown_denied';

    // Phase 4 Task 11 — regenerateSecret() (ApiKeyController) changes
    // this key's secret_hash with no event at all before this task,
    // unlike every other credential-adjacent transition in this class
    // (EV_CREDENTIAL_ISSUED, EV_CREDENTIAL_MISSING/INVALID). Fired from
    // the controller via this service's own record() helper — same
    // pattern EV_KEY_CREATED already uses from store().
    public const EV_SECRET_REGENERATED = 'api_secret_regenerated';

    /** The only context keys an event may carry. */
    private const SAFE_CONTEXT = ['label', 'ip_policy', 'request_id', 'reason', 'new_binding_id', 'old_binding_id', 'requested_ip', 'current_ip', 'client', 'extended_until', 'cooldown_until', 'old_cooldown_until', 'trigger'];

    private const CREDENTIAL_PREFIX = 'wasaas_inst_';

    // Phase 4 Task 9 — the one, flat, per-key cooldown duration. Same
    // value for both triggers (explicit revoke and completed
    // transfer/rebind) — see setCooldownAfter...() call sites below.
    public const COOLDOWN_DAYS = 14;

    // ---- request enforcement -----------------------------------------------------------------------------------

    /**
     * Phase 4 Task 7 — binding-first request gate for every Developer
     * API key. Rewritten from the pre-Task-7 version (which only ever
     * checked Account.authorized_server_ip, with no awareness of
     * ApiKeyBinding at all) into the finalized decision tree:
     *
     *   access_disabled?            -> DISABLED, stop.
     *   key->liveBinding() exists?  -> Case A (gateBoundKey()): the
     *                                   binding is AUTHORITATIVE. The
     *                                   account's authorized_server_ip is
     *                                   never read in this branch, even
     *                                   if it happens to match the
     *                                   request IP, and a missing/invalid/
     *                                   credential_pending binding never
     *                                   falls back to it either.
     *   else                        -> Case B (gateUnboundLegacyKey()):
     *                                   the TEMPORARY migration-only
     *                                   legacy-IP path, gated by this
     *                                   key's own (Task 5) grace deadline
     *                                   — never calculated dynamically,
     *                                   never account-wide.
     *
     * Every denial stays the project's existing generic-response
     * convention (no binding IDs, hashes, or "which factor failed" ever
     * leaked) — see denial() and each branch below.
     *
     * ERROR-CODE MAPPING (task spec's vocabulary -> this class's actual
     * constant/response code):
     *   INSTALLATION_CREDENTIAL_REQUIRED -> self::CREDENTIAL_REQUIRED (new, same string)
     *   INSTALLATION_CREDENTIAL_INVALID  -> self::CREDENTIAL_INVALID  (new, same string)
     *   INSTALLATION_BINDING_REQUIRED    -> self::BINDING_REQUIRED (existing 'API_SERVER_BINDING_REQUIRED',
     *                                        kept rather than duplicated — see that constant's own docblock)
     * The pre-existing IP-mismatch code (self::DENIED, 'API_CLIENT_NOT_AUTHORIZED') is reused for "legacy key,
     * valid deadline, wrong IP" — the task spec does not mandate a distinct code for that case, and this is
     * the established code for exactly that condition already (ServerIpBindingTest).
     *
     * @return JsonResponse|null null = allowed.
     */
    public function gate(Request $request, ApiKey $key): ?JsonResponse
    {
        $ip = IpMatcher::normalize($request->ip());

        if ($key->isAccessDisabled()) {
            $this->record(self::EV_ACCESS_DISABLED_ATTEMPT, $key, null, $ip, [], dedupe: true);

            return self::denial(self::DISABLED, 'API access for this key is currently disabled.');
        }

        $binding = $key->liveBinding();

        if ($binding !== null) {
            return $this->gateBoundKey($request, $key, $binding, $ip);
        }

        return $this->gateUnboundLegacyKey($key, $ip);
    }

    /**
     * Case A — the key has a live binding (pending_activation or
     * active; credential_pending is simply "live, installation_hash
     * still null" — no separate branch needed for it, see below).
     *
     * Header: config('api_binding.installation_header') (X-Client-
     * Installation), read ONLY via Request::header() — never query
     * string, body, or cookies — and header lookup is case-insensitive
     * by ordinary HTTP/Symfony HeaderBag semantics (no special-casing
     * needed here).
     *
     * credential_pending (installation_hash === null): verifyCredential()
     * already returns false unconditionally for this state (Task 4's own
     * guarantee) — so a credential_pending binding naturally falls into
     * the "invalid credential" branch below and is NEVER evaluated
     * against the account's authorized_server_ip. No extra branch is
     * needed to satisfy "must not authenticate through the legacy
     * account IP" / "must not invent a new binding status" / "must not
     * automatically mint a credential during authentication" — this
     * method never reads Account.authorized_server_ip at all once a
     * binding exists, and never creates/mints anything.
     */
    private function gateBoundKey(Request $request, ApiKey $key, ApiKeyBinding $binding, ?string $ip): ?JsonResponse
    {
        $header = (string) config('api_binding.installation_header', 'X-Client-Installation');
        $credential = trim((string) $request->header($header, ''));

        if ($credential === '') {
            $this->record(self::EV_CREDENTIAL_MISSING, $key, $binding, $ip, [], dedupe: true);

            return self::denial(self::CREDENTIAL_REQUIRED, 'The installation credential for this authorized server is required.');
        }

        if (! $binding->verifyCredential($credential)) {
            $this->record(self::EV_CREDENTIAL_INVALID, $key, $binding, $ip, [], dedupe: true);

            return self::denial(self::CREDENTIAL_INVALID, 'The installation credential for this authorized server is invalid.');
        }

        // Only after successful verification — never on a failed attempt — and never from a caller-supplied
        // header: $ip is $request->ip() (normalized), the same framework-trusted value used for the gate itself.
        $binding->recordSuccessfulUse((string) $ip);
        $this->record(self::EV_AUTH_OK, $key, $binding, $ip, [], dedupe: true);

        return null;
    }

    /**
     * Case B — the key has NO live binding. Legacy-IP-dependent means
     * the ACCOUNT has a registered authorized_server_ip; everything
     * else (including a brand-new key that never had one) is refused
     * outright with no open-ended bypass.
     *
     * Phase 4 Task 7 FIX: the former allow_legacy_unbound emergency
     * override has been REMOVED from this method. There is no
     * configuration/admin flag of any kind that authenticates a key with
     * no live binding merely because it is set. Every branch below is
     * unconditional.
     */
    private function gateUnboundLegacyKey(ApiKey $key, ?string $ip): ?JsonResponse
    {
        $account = Account::query()->find($key->account_id);

        // Phase 4 Task 7 FIX — the emergency `allow_legacy_unbound`
        // override used to short-circuit here and authenticate an
        // unbound key with no account IP at all. REMOVED: there must be
        // no configuration/admin override that authenticates a key with
        // no live binding merely because a flag is set. An unbound key
        // with no account IP is unconditionally refused below, and
        // config('api_binding.allow_legacy_unbound') is no longer read
        // anywhere in this method (or anywhere else in this class) —
        // see InstallationBindingAuthenticationTest's
        // test_legacy_override_flag_does_not_authenticate_an_unbound_key
        // for the regression proof. The config key itself and
        // tests\Concerns\AllowsUnboundApiKeys are left in place
        // (removing them is a separate, much larger fixture migration
        // across ~20 unrelated test files — see this fix's own report)
        // but both are now completely inert as far as gate() is
        // concerned.
        if (! $account || $account->authorized_server_ip === null) {
            $this->record(self::EV_BINDING_REQUIRED, $key, null, $ip, [], dedupe: true);

            return self::denial(self::BINDING_REQUIRED, 'Set your server IP under Profile → API key before you use this key, or register an authorized server.');
        }

        $effectiveDeadline = $this->effectiveLegacyDeadline($key);

        // NULL deadline (never backfilled, or backfilled with no grace
        // window at all) and an EXPIRED deadline both fail closed with
        // the exact same response code — "before or equal to the
        // deadline" is the only path that proceeds to the IP check.
        if ($effectiveDeadline === null || now()->greaterThan($effectiveDeadline)) {
            $this->record(self::EV_LEGACY_DEADLINE_EXPIRED, $key, null, $ip, [], dedupe: true);

            return self::denial(self::BINDING_REQUIRED, 'Authorize a server for this API key before you use it — the temporary migration window for this key has ended.');
        }

        if (! app(ServerIpBindingService::class)->allows($account, $ip)) {
            $this->record(self::EV_UNAUTH_IP, $key, null, $ip, ['ip_policy' => 'SINGLE_IP'], dedupe: true);

            // The flag tells the caller what to do, for example after a shared host changed the outbound IP.
            return self::denial(self::DENIED, null, [
                'action' => 'verify_server_ip',
                'help' => 'If your server IP has changed (for example, your shared hosting provider moved your site), verify the new IP in your WapHub dashboard under Profile → API key, or contact support.',
            ]);
        }

        $this->record(self::EV_AUTH_OK, $key, null, $ip, [], dedupe: true);

        return null;
    }

    /**
     * Phase 4 Task 7 — resolves THIS key's effective legacy grace
     * deadline: max(api_keys.legacy_binding_grace_expires_at, the
     * latest VALID EV_LEGACY_DEADLINE_EXTENDED event for this exact
     * api_key_id). Never reads Account.ip_registered_at, never computes
     * a deadline dynamically from it, never reads any other key's
     * events (the query is always scoped by this key's own id) — so an
     * extension on Key B can never affect Key A, by construction.
     *
     * "Valid" extension = the latest (by created_at/id) event row whose
     * context['extended_until'] is present, a string, and Carbon-
     * parseable. A malformed/missing value is treated as if that event
     * did not exist — it never silently grants access — but a malformed
     * LATEST event does NOT fall back to an older valid one; the rule
     * is "use the latest valid extension", and this implementation reads
     * that as "the most recent one; if it's malformed, there is no
     * extension to apply" rather than scanning further back, since a
     * malformed row is not expected to occur at all through this
     * class's own writer (extendLegacyDeadline()) and treating it as
     * "no extension" is the conservative, fail-closed choice either way.
     *
     * DELIBERATE INTERPRETATION (the task spec's three extension rules
     * — "no extension -> base", "earlier extension -> base wins",
     * "later extension -> extension wins" — all presuppose a base
     * deadline already exists; it does not say what an extension does
     * when the base is NULL): this implementation treats a NULL base as
     * having no deadline to extend at all, returning null regardless of
     * any extension event. Section 3's "If deadline is NULL -> fail
     * closed" is read as taking priority — an extension cannot
     * manufacture migration eligibility for a key that was never
     * Task-5-backfilled with a base deadline in the first place. Flagged
     * explicitly in the Task 7 report as an interpretation, not a
     * literal spec requirement.
     *
     * Never mutates api_keys.legacy_binding_grace_expires_at itself —
     * Task 5's base deadline is read-only here, exactly as required.
     */
    private function effectiveLegacyDeadline(ApiKey $key): ?\Illuminate\Support\Carbon
    {
        $base = $key->legacy_binding_grace_expires_at;

        if ($base === null) {
            return null;
        }

        $latest = ApiKeySecurityEvent::where('api_key_id', $key->id)
            ->where('event', self::EV_LEGACY_DEADLINE_EXTENDED)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return $base;
        }

        $raw = $latest->context['extended_until'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return $base;
        }

        try {
            $extendedUntil = \Illuminate\Support\Carbon::parse($raw);
        } catch (\Throwable) {
            return $base;
        }

        return $extendedUntil->greaterThan($base) ? $extendedUntil : $base;
    }

    /**
     * Phase 4 Task 7 — the minimum auditable representation of a
     * per-key legacy-deadline extension the task asks for (no admin UI
     * yet — that is Task 12). Reuses the existing, already-schema'd
     * ApiKeySecurityEvent (api_key_id + context, both already present
     * and already allow-listed/JSON-cast) rather than a new table/
     * source — "follow the repository's existing event convention"
     * rather than inventing a parallel one. api_key_id is always set
     * (via record()'s existing $key?->id), so this can never be
     * account-wide by construction; there is no Account-level
     * equivalent anywhere in this class.
     */
    public function extendLegacyDeadline(ApiKey $key, \DateTimeInterface $until, User $actor, ?string $reason = null): void
    {
        $this->record(self::EV_LEGACY_DEADLINE_EXTENDED, $key, null, null, [
            'extended_until' => \Illuminate\Support\Carbon::instance($until)->toIso8601String(),
            'reason' => $reason,
        ], actor: $actor);
    }

    /** @param array<string, mixed> $extra additional fields for the caller; never secrets or the registered IP */
    public static function denial(string $code = self::DENIED, ?string $message = null, array $extra = []): JsonResponse
    {
        $message ??= 'This API key is not authorized for this server.';

        return response()->json(['success' => false, 'status' => false, 'code' => $code, 'error_code' => $code, 'message' => $message] + $extra, 403);
    }

    // ---- Phase 4 Task 5: legacy API-key binding backfill ------------------------------------------------------

    /**
     * Creates exactly one live, CREDENTIAL-LESS binding for a legacy-
     * IP-dependent key, preserving the account's existing
     * authorized_server_ip as the binding's own SINGLE_IP policy and
     * registered IP. Called only by BackfillApiKeyLegacyBindings, under
     * that command's own row lock and "no live binding yet" re-check —
     * this method does not repeat those checks itself, so a precondition
     * violation surfaces to the caller (as the unique-index violation it
     * already is) rather than being silently swallowed here.
     *
     * Deliberately does NOT mint a credential (installation_hash stays
     * null — the Task 4 "credential_pending" state: a live binding the
     * owner must separately issue a credential for via
     * installationCredential()/issueCredential(), exactly like a
     * Super-Admin-created rebind()/approve() binding already works
     * today). Reuses createLive(), the single private constructor every
     * other binding-creating path already goes through — this adds no
     * second way to create a binding row.
     */
    public function createLegacyBackfillBinding(ApiKey $key, string $ip): ApiKeyBinding
    {
        // Phase 4 Task 6 — routed through the same allowance-enforced
        // seam every other creation path uses (no second allowance
        // algorithm for the backfill). Not a replacement (case A only
        // ever runs when the key has NO live binding at all), so
        // $replacing is null; the seam's own "already has a live
        // binding" guard still protects a non-replacement double-call.
        // Can now throw InstallationAllowanceExceededException — the
        // caller (BackfillApiKeyLegacyBindings) handles that explicitly.
        $binding = $this->createLiveBindingEnforced($key, [
            'label' => 'Legacy migration backfill',
            'ip_policy' => ApiKeyBinding::POLICY_SINGLE_IP,
            'authorized_ips' => [$ip],
            'credential' => null,
        ], null, replacing: null);

        $this->record(self::EV_LEGACY_BACKFILL_BOUND, $key, $binding, null, [
            'label' => $binding->label,
            'ip_policy' => ApiKeyBinding::POLICY_SINGLE_IP,
            'reason' => 'legacy_backfill',
        ], actor: null);

        return $binding;
    }

    // ---- Phase 4 Task 3: account-scoped live-installation counting --------------------------------------------

    /**
     * The reusable seam Task 6 will consume to enforce `api_installations`:
     *
     *   lock account -> resolve allowance (InstallationAllowanceResolver)
     *   -> count live installations (THIS METHOD) -> enforce count < allowance
     *   -> create/replace binding
     *
     * Deliberately NOT implemented here: no account row lock, no
     * allowance comparison, no enforcement. This method only answers
     * "how many installation slots does this account currently occupy,
     * right now" — Task 6 wraps it in the locked transaction above.
     *
     * Counts ApiKeyBinding rows only (never Account.authorized_server_ip
     * and never api_installations itself) with status IN
     * ApiKeyBinding::LIVE_STATUSES (pending_activation, active), across
     * every API key the account owns. A key with no binding row, or
     * whose only rows are revoked, contributes zero. A key is never
     * counted twice from its own history: the `akb_one_live_binding_per_key`
     * unique index on (api_key_id, active_slot) guarantees at most one
     * row per api_key_id can hold active_slot = 1 at a time, and
     * createLive()/revokeBinding() keep active_slot in lockstep with
     * status (1 while live, null once revoked) — so a key that was
     * revoked and later rebound has exactly one live row counted, not
     * two. access_disabled (ApiKey.access_disabled_at) is intentionally
     * NOT read here: it is an authorization toggle on the key, never
     * written to api_key_bindings (see setAccessDisabled() below), so a
     * disabled key's live binding still occupies its slot — disabling a
     * key does not release the installation it holds.
     */
    public function countLiveInstallations(int $accountId): int
    {
        return ApiKeyBinding::query()->forAccount($accountId)->live()->count();
    }

    // ---- Phase 4 Task 6: the single allowance-enforced creation/replacement seam ------------------------------

    /**
     * THE authoritative seam for creating or replacing a live binding.
     * Every live-binding-creating path in this class — provision()
     * (and therefore registerServer()/store()), approve(), rebind(),
     * createLegacyBackfillBinding() (and therefore Task 5's backfill
     * command) — routes through this method. Nothing outside this
     * class creates a live binding at all (confirmed by audit: the
     * private createLive() constructor this method still calls is
     * never invoked from anywhere else).
     *
     * LOCK ORDER (consistent everywhere this method is reached):
     * Account row -> this specific ApiKey row -> binding row(s). The
     * account lock is what actually serializes two concurrent
     * installation-slot mutations for the SAME account (a second call
     * for this account blocks on the Account::lockForUpdate() read
     * below until the first transaction commits); unrelated accounts
     * never contend with each other, because each only ever locks its
     * own account row.
     *
     * REPLACEMENT (approve()/rebind()): pass the binding being
     * replaced as $replacing. It is revoked HERE, inside this same
     * transaction, BEFORE the allowance count is taken — so a one-for-
     * one replacement of an account already at its full allowance
     * still succeeds (the old occupancy is already gone by the time
     * "count < allowance" is evaluated), and the final state never
     * passes through a two-slots-occupied moment: the old row's
     * active_slot is cleared in the same statement sequence that later
     * inserts the new row with active_slot = 1, inside one uncommitted
     * transaction no other connection can observe mid-way.
     *
     * ALLOWANCE: resolved live, every call, via
     * InstallationAllowanceResolver::resolveForAccount() — the exact
     * Task 2/2A semantics (absent -> 1, NULL -> 1 + warning, 0 -> deny,
     * positive -> exact, negative persisted -> defensive 0, no
     * unlimited sentinel). This method invents no second allowance
     * algorithm.
     *
     * COUNT: Task 3's countLiveInstallations() — unchanged, unreplaced.
     * Read AFTER the $replacing revoke above (if any) and AFTER both
     * locks are held, inside the same transaction, so it reflects
     * exactly the state this transaction is about to act on.
     *
     * On rejection, nothing is written: the exception is thrown before
     * any INSERT, and the whole transaction (including the $replacing
     * revoke, if one was passed) is rolled back by Laravel's
     * DB::transaction() — a rejected replacement leaves the OLD binding
     * exactly as it was, not half-revoked.
     *
     * @param  array{label: ?string, ip_policy: string, authorized_ips: list<string>, credential: ?string}  $d
     *
     * @throws InstallationAllowanceExceededException
     */
    private function createLiveBindingEnforced(ApiKey $key, array $d, ?User $actor, ?ApiKeyBinding $replacing, string $replacingReason = 'replaced'): ApiKeyBinding
    {
        return DB::transaction(function () use ($key, $d, $actor, $replacing, $replacingReason) {
            $account = Account::query()->where('id', $key->account_id)->lockForUpdate()->first();
            if (! $account) {
                throw new InvalidArgumentException('The account for this API key no longer exists.');
            }

            $lockedKey = ApiKey::query()->where('id', $key->id)->lockForUpdate()->first();
            if (! $lockedKey) {
                throw new InvalidArgumentException('This API key no longer exists.');
            }

            // Phase 4 Task 9 — read BEFORE any write this transaction
            // makes, including the cooldown write at the end of this
            // same method. A transfer/rebind can therefore never
            // observe (and never self-block on) the very cooldown it
            // is about to start; it CAN still be blocked here by a
            // cooldown an EARLIER, already-committed operation left on
            // this key (e.g. a second transfer attempted before the
            // first one's 14 days are up) — that is the intended
            // "independent rebind respects cooldown" behavior.
            if ($lockedKey->isInCooldown()) {
                // Phase 4 Task 11 — dedupe: true, same convention as
                // every other denial record in gate() (EV_UNAUTH_IP,
                // EV_ACCESS_DISABLED_ATTEMPT, …): a caller retrying
                // against an active cooldown must not flood the audit
                // log with one row per attempt.
                $this->record(self::EV_COOLDOWN_DENIED, $lockedKey, null, null, [
                    'cooldown_until' => $lockedKey->cooldown_until->toIso8601String(),
                ], dedupe: true, actor: $actor);

                throw ApiKeyCooldownActiveException::active($lockedKey, $lockedKey->cooldown_until);
            }

            if ($replacing !== null) {
                // Free the old slot FIRST, inside this same lock — see
                // this method's own docblock ("REPLACEMENT") for why.
                $this->revokeBinding($replacing, $actor, $replacingReason);
            } elseif ($lockedKey->bindings()->live()->exists()) {
                // Not a replacement, yet this key already has a live
                // binding — a caller bug (every real caller either
                // checks this first, as registerServer() does, or
                // passes $replacing), or two non-seam writers racing.
                // Either way, refuse explicitly rather than silently
                // creating a second live row for one key (the unique
                // index would refuse the INSERT anyway; this gives a
                // clear error instead of a raw constraint violation for
                // the ordinary, non-race case).
                throw new InvalidArgumentException('This API key already has a live authorized server.');
            }

            $allowance = app(InstallationAllowanceResolver::class)->resolveForAccount($account)['allowance'];
            $current = $this->countLiveInstallations($account->id);

            if ($current >= $allowance) {
                throw InstallationAllowanceExceededException::exceeded($account->id, $allowance, $current);
            }

            $binding = $this->createLive($lockedKey, $d, $actor);

            // Phase 4 Task 9 — a completed transfer/rebind (an actual
            // replacement, $replacing !== null) starts this key's
            // cooldown. A bare first-time provision/registerServer
            // (nothing replaced) is not a transfer and does not start
            // one. Written AFTER createLive() succeeds, inside the
            // same transaction, so a failed createLive() (e.g. the
            // allowance check above already threw) never leaves a
            // cooldown behind with no successful replacement to match.
            if ($replacing !== null) {
                $lockedKey->forceFill(['cooldown_until' => now()->addDays(self::COOLDOWN_DAYS)])->save();

                // Phase 4 Task 11 — the moment this key's cooldown
                // actually starts, for both rebind() and approve()
                // transfers (both reach here with $replacing !== null).
                $this->record(self::EV_COOLDOWN_STARTED, $lockedKey, $binding, null, [
                    'trigger' => 'transfer',
                    'cooldown_until' => $lockedKey->cooldown_until->toIso8601String(),
                ], actor: $actor);
            }

            return $binding;
        });
    }

    // ---- provisioning ------------------------------------------------------------------------------------------

    /**
     * Registers the authorized server for a key and mints its installation credential.
     *
     * @param  array{label?: ?string, ip_policy?: ?string, authorized_ips?: array<int, string>}  $opts
     * @return array{binding: ApiKeyBinding, credential: string}
     */
    public function provision(ApiKey $key, array $opts, ?User $actor = null, bool $allowPolicyNone = false): array
    {
        [$policy, $ips] = $this->normalizePolicy($opts['ip_policy'] ?? null, $opts['authorized_ips'] ?? [], $allowPolicyNone);
        $credential = $this->newCredential();

        // Phase 4 Task 6 — routed through the single allowance-enforced
        // seam; not a replacement, so $replacing is null.
        $binding = $this->createLiveBindingEnforced($key, [
            'label' => $this->label($opts['label'] ?? null),
            'ip_policy' => $policy,
            'authorized_ips' => $ips,
            'credential' => $credential,
        ], $actor, replacing: null);

        $this->record(self::EV_SERVER_REGISTERED, $key, $binding, null, ['label' => $binding->label, 'ip_policy' => $policy], actor: $actor);

        return ['binding' => $binding, 'credential' => $credential];
    }

    /** @return array{0: string, 1: list<string>} */
    public function normalizePolicy(?string $policy, array $ips, bool $allowNone = false): array
    {
        $policy = strtoupper((string) ($policy ?: config('api_binding.default_ip_policy', ApiKeyBinding::POLICY_SINGLE_IP)));
        if (! in_array($policy, ApiKeyBinding::POLICIES, true)) {
            throw new InvalidArgumentException('ip_policy must be SINGLE_IP or IP_ALLOWLIST.');
        }
        if ($policy === ApiKeyBinding::POLICY_NONE && ! $allowNone) {
            throw new InvalidArgumentException('Unrestricted API access is not available. Use SINGLE_IP or IP_ALLOWLIST.');
        }
        $clean = [];
        foreach ($ips as $entry) {
            $norm = IpMatcher::normalizeEntry((string) $entry);
            if ($norm === null) {
                throw new InvalidArgumentException('Authorized IPs must be valid IPv4/IPv6 addresses or CIDR ranges.');
            }
            if ($policy === ApiKeyBinding::POLICY_SINGLE_IP && str_contains($norm, '/')) {
                throw new InvalidArgumentException('SINGLE_IP takes exactly one IP address, not a range.');
            }
            $clean[] = $norm;
        }
        $clean = array_values(array_unique($clean));
        if ($policy === ApiKeyBinding::POLICY_NONE) {
            $clean = [];
        } elseif ($policy === ApiKeyBinding::POLICY_SINGLE_IP && count($clean) > 1) {
            throw new InvalidArgumentException('SINGLE_IP takes exactly one IP address.');
        } elseif ($policy === ApiKeyBinding::POLICY_ALLOWLIST && ($clean === [] || count($clean) > 20)) {
            throw new InvalidArgumentException('IP_ALLOWLIST needs between 1 and 20 IPs or ranges.');
        }

        return [$policy, $clean];
    }

    private function label(?string $label): ?string
    {
        $label = trim(strip_tags((string) $label));

        return $label === '' ? null : Str::limit($label, 100, '');
    }

    private function newCredential(): string
    {
        return self::CREDENTIAL_PREFIX.Str::random(40);
    }

    /** @param array{label: ?string, ip_policy: string, authorized_ips: list<string>, credential?: ?string} $d */
    private function createLive(ApiKey $key, array $d, ?User $actor): ApiKeyBinding
    {
        $declared = $d['authorized_ips'] !== [] || $d['ip_policy'] === ApiKeyBinding::POLICY_NONE;
        $credential = $d['credential'] ?? null;

        return ApiKeyBinding::create([
            'account_id' => $key->account_id,
            'api_key_id' => $key->id,
            'status' => $declared ? ApiKeyBinding::STATUS_ACTIVE : ApiKeyBinding::STATUS_PENDING,
            'active_slot' => 1,
            'label' => $d['label'],
            'ip_policy' => $d['ip_policy'],
            'authorized_ips' => $d['authorized_ips'],
            'installation_prefix' => $credential ? substr($credential, 0, 20) : null,
            'installation_hash' => $credential ? ApiKeyBinding::hashCredential($credential) : null,
            'registered_ip' => $d['ip_policy'] === ApiKeyBinding::POLICY_SINGLE_IP ? ($d['authorized_ips'][0] ?? null) : null,
            'registered_at' => $declared ? now() : null,
            'created_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * Mints (or re-mints) the credential of a live binding that has none yet or has never been used - the step after
     * a Super Admin approval or rebind. It never changes the IP policy or IPs, so it cannot move a server.
     *
     * @return string the plaintext credential, returned once
     */
    public function issueCredential(ApiKey $key, ApiKeyBinding $binding, ?User $actor = null): string
    {
        if ($binding->api_key_id !== $key->id || ! $binding->isLive()) {
            throw new InvalidArgumentException('There is no authorized server to issue a credential for.');
        }
        if ($binding->hasCredential() && $binding->last_success_at !== null) {
            throw new InvalidArgumentException('The installation credential is already in use and cannot be re-issued. Request a server change instead.');
        }
        $credential = $this->newCredential();
        $binding->forceFill(['installation_prefix' => substr($credential, 0, 20), 'installation_hash' => ApiKeyBinding::hashCredential($credential)])->save();
        $this->record(self::EV_CREDENTIAL_ISSUED, $key, $binding, null, [], actor: $actor);

        return $credential;
    }

    // ---- buyer: request a server change -----------------------------------------------------------------------------

    /**
     * Phase 4 Task 9 FIX — the cooldown check (and everything that
     * follows it: the live-binding read and the change-request INSERT
     * itself) is now inside a DB::transaction() with the SAME
     * Account -> ApiKey lock order as createLiveBindingEnforced()/
     * revoke()/destroyKey() above, not a bare unlocked read.
     *
     * WHY: this method's own write (ApiKeyChangeRequest::create()) is
     * not itself concurrency-sensitive in isolation (it is protected by
     * its own unique index, caught below), but the DECISION to make
     * that write at all depends on this key's live binding and cooldown
     * state — and without holding this key's row lock across that
     * whole decision, a concurrent revoke()/destroyKey()/rebind()/
     * approve() could commit (changing the binding and/or starting a
     * cooldown) in the gap between this method's read and its write,
     * leaving a pending change request on a binding that no longer
     * matches reality, or on a key that is actually in cooldown by the
     * time the request lands. Locking the SAME ApiKey row that those
     * methods lock makes the two paths mutually exclusive: whichever
     * acquires the lock first runs to completion (commit or rollback)
     * before the other's lock request is granted, so this method
     * always decides against the freshest possible state, never a
     * stale pre-lock read.
     *
     * Input-shape validation (normalizePolicy()/ips/reason) stays
     * BEFORE the lock, exactly where it already was — it is pure
     * request validation, unrelated to any other operation's state,
     * and holding a row lock while validating a string is pointless
     * lock contention. Only the checks/writes that actually read or
     * depend on this key's live state move inside the lock, in their
     * original relative order (cooldown, then live-binding, then the
     * request insert) — "without unnecessary rewrites".
     */
    public function requestChange(ApiKey $key, User $actor, array $input): ApiKeyChangeRequest
    {
        [$policy, $ips] = $this->normalizePolicy($input['ip_policy'] ?? null, (array) ($input['requested_ips'] ?? []));
        if ($ips === []) {
            throw new InvalidArgumentException('Provide the IP address of the new server.');
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        return DB::transaction(function () use ($key, $actor, $policy, $ips, $reason, $input) {
            Account::query()->where('id', $key->account_id)->lockForUpdate()->first();
            $lockedKey = ApiKey::query()->where('id', $key->id)->lockForUpdate()->first();
            if (! $lockedKey) {
                throw new InvalidArgumentException('This API key no longer exists.');
            }

            // Phase 4 Task 9 — read under the SAME lock a concurrent
            // revoke()/destroyKey()/rebind()/approve() holds while
            // writing cooldown_until, so this can never decide on a
            // value one of those is mid-way through changing.
            if ($lockedKey->isInCooldown()) {
                // Phase 4 Task 11
                $this->record(self::EV_COOLDOWN_DENIED, $lockedKey, null, null, [
                    'cooldown_until' => $lockedKey->cooldown_until->toIso8601String(),
                ], dedupe: true, actor: $actor);

                throw ApiKeyCooldownActiveException::active($lockedKey, $lockedKey->cooldown_until);
            }

            $binding = $lockedKey->liveBinding();
            if (! $binding || $binding->status !== ApiKeyBinding::STATUS_ACTIVE) {
                throw new InvalidArgumentException('A server change can only be requested for a key with an activated authorized server.');
            }

            try {
                $request = ApiKeyChangeRequest::create([
                    'account_id' => $lockedKey->account_id,
                    'api_key_id' => $lockedKey->id,
                    'current_binding_id' => $binding->id,
                    'status' => ApiKeyChangeRequest::STATUS_PENDING,
                    'pending_slot' => 1,
                    'current_label' => $binding->label,
                    'current_ip' => $binding->last_success_ip ?? $binding->registered_ip,
                    'requested_label' => $this->label($input['requested_label'] ?? null),
                    'requested_ip_policy' => $policy,
                    'requested_ips' => $ips,
                    'reason' => Str::limit(strip_tags($reason), 500, ''),
                    'requested_by_user_id' => $actor->id,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    throw new InvalidArgumentException('A server change request is already pending for this key.');
                }
                throw $e;
            }

            $this->record(self::EV_CHANGE_REQUESTED, $lockedKey, $binding, null, ['request_id' => $request->id, 'requested_ip' => $ips[0]], actor: $actor);

            return $request;
        });
    }

    // ---- Super Admin ------------------------------------------------------------------------------------------------

    /** Revokes the old binding and registers the requested one - atomically, so the old server stops working at once. */
    public function approve(ApiKeyChangeRequest $request, User $admin, ?string $note = null): ApiKeyChangeRequest
    {
        return DB::transaction(function () use ($request, $admin, $note) {
            $locked = ApiKeyChangeRequest::lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== ApiKeyChangeRequest::STATUS_PENDING) {
                throw new InvalidArgumentException('This request has already been decided.');
            }
            $key = ApiKey::findOrFail($locked->api_key_id);
            $old = $key->liveBinding();
            // Phase 4 Task 6 — the old binding is now revoked INSIDE
            // createLiveBindingEnforced()'s own lock, immediately before
            // the allowance count, rather than here beforehand: a
            // one-for-one replacement at full allowance must succeed
            // (see that method's "REPLACEMENT" docblock section).
            $new = $this->createLiveBindingEnforced($key, [
                'label' => $locked->requested_label,
                'ip_policy' => $locked->requested_ip_policy,
                'authorized_ips' => $locked->requested_ips ?? [],
                'credential' => null,
            ], $admin, replacing: $old, replacingReason: 'server_change_approved');
            $locked->forceFill([
                'status' => ApiKeyChangeRequest::STATUS_APPROVED, 'pending_slot' => null, 'decided_by_user_id' => $admin->id,
                'decided_at' => now(), 'decision_note' => $this->note($note), 'new_binding_id' => $new->id,
            ])->save();
            $this->record(self::EV_CHANGE_APPROVED, $key, $new, null, ['request_id' => $locked->id, 'old_binding_id' => $old?->id, 'new_binding_id' => $new->id], actor: $admin);

            return $locked->fresh();
        });
    }

    public function reject(ApiKeyChangeRequest $request, User $admin, ?string $note = null): ApiKeyChangeRequest
    {
        return DB::transaction(function () use ($request, $admin, $note) {
            $locked = ApiKeyChangeRequest::lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== ApiKeyChangeRequest::STATUS_PENDING) {
                throw new InvalidArgumentException('This request has already been decided.');
            }
            $locked->forceFill(['status' => ApiKeyChangeRequest::STATUS_REJECTED, 'pending_slot' => null, 'decided_by_user_id' => $admin->id, 'decided_at' => now(), 'decision_note' => $this->note($note)])->save();
            $this->record(self::EV_CHANGE_REJECTED, ApiKey::find($locked->api_key_id), ApiKeyBinding::find($locked->current_binding_id), null, ['request_id' => $locked->id], actor: $admin);

            return $locked->fresh();
        });
    }

    /**
     * Phase 4 Task 9 — now transactional with the same Account -> ApiKey
     * lock order as createLiveBindingEnforced()/destroyKey() (it had
     * no locking at all before this task), because it now also writes
     * cooldown_until and must not race a concurrent transfer/rebind
     * for the same key.
     */
    public function revoke(ApiKey $key, User $admin, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($key, $admin, $reason) {
            Account::query()->where('id', $key->account_id)->lockForUpdate()->first();
            $locked = ApiKey::query()->where('id', $key->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new InvalidArgumentException('This API key no longer exists.');
            }

            $binding = $locked->liveBinding();
            if (! $binding) {
                return false;
            }
            $this->revokeBinding($binding, $admin, $this->note($reason) ?? 'revoked_by_super_admin');

            // Phase 4 Task 9 — explicit revoke starts the same flat
            // 14-day, per-key cooldown as a completed transfer.
            $locked->forceFill(['cooldown_until' => now()->addDays(self::COOLDOWN_DAYS)])->save();

            // Phase 4 Task 11
            $this->record(self::EV_COOLDOWN_STARTED, $locked, $binding, null, [
                'trigger' => 'revoke',
                'cooldown_until' => $locked->cooldown_until->toIso8601String(),
            ], actor: $admin);

            return true;
        });
    }

    /** Super Admin binds (or re-binds) a key to a new server. The buyer then mints the credential. */
    public function rebind(ApiKey $key, User $admin, array $opts): ApiKeyBinding
    {
        [$policy, $ips] = $this->normalizePolicy($opts['ip_policy'] ?? null, (array) ($opts['authorized_ips'] ?? []), allowNone: true);

        $old = $key->liveBinding();
        // Phase 4 Task 6 — routed through the allowance-enforced seam,
        // which revokes $old (if any) inside its own lock before
        // checking the allowance, so a one-for-one rebind at full
        // allowance still succeeds.
        $new = $this->createLiveBindingEnforced(
            $key,
            ['label' => $this->label($opts['label'] ?? null), 'ip_policy' => $policy, 'authorized_ips' => $ips, 'credential' => null],
            $admin,
            replacing: $old,
            replacingReason: 'rebound_by_super_admin',
        );
        $this->record(self::EV_REBOUND, $key, $new, null, ['old_binding_id' => $old?->id, 'new_binding_id' => $new->id, 'label' => $new->label, 'ip_policy' => $policy], actor: $admin);

        return $new;
    }

    /**
     * Phase 4 Task 6 — API-key destruction/revocation. Revokes the key
     * AND releases its live binding's installation slot, atomically, in
     * ONE transaction — closing the gap flagged since Task 3: before
     * this method, ApiKeyController::destroy() only ever set
     * api_keys.revoked_at, leaving any live binding (and the slot it
     * occupies) untouched indefinitely.
     *
     * Lock order matches the seam above: Account row, then this ApiKey
     * row, then (via revokeBinding()) the binding row itself.
     *
     * Idempotent: a key that is already revoked is not re-saved (its
     * revoked_at is not disturbed a second time); a key with no live
     * binding simply has nothing to revoke. Historical binding rows are
     * never deleted — revokeBinding() only ever flips status/active_slot
     * on the one row that was live, exactly as every other revocation
     * path in this class already does.
     *
     * Phase 4 Task 9 update: now also sets cooldown_until on
     * destruction (see the inline comment below) — Task 6 only added
     * the storage primitive; this is the Task 9 wiring for it.
     */
    public function destroyKey(ApiKey $key, User $actor): ApiKey
    {
        return DB::transaction(function () use ($key, $actor) {
            Account::query()->where('id', $key->account_id)->lockForUpdate()->first();
            $locked = ApiKey::query()->where('id', $key->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new InvalidArgumentException('This API key no longer exists.');
            }

            if (! $locked->isRevoked()) {
                $locked->forceFill(['revoked_at' => now()])->save();
            }

            $live = $locked->bindings()->live()->first();
            if ($live !== null) {
                $this->revokeBinding($live, $actor, 'api_key_destroyed');
            }

            // Phase 4 Task 9 — destroying a key is also an explicit
            // revoke. A destroyed key (revoked_at set) can never reach
            // any cooldown-checked path again anyway, but setting this
            // keeps the invariant uniform ("an explicit revoke always
            // starts a cooldown") rather than special-casing the final,
            // most-terminal revoke path to skip it.
            $locked->forceFill(['cooldown_until' => now()->addDays(self::COOLDOWN_DAYS)])->save();

            // Phase 4 Task 11 — only when there was actually a live
            // binding to revoke; a key with nothing live still gets its
            // cooldown set above (uniform invariant, per that forceFill's
            // own comment), but there is no prior revoke "trigger" worth
            // reporting distinctly from EV_KEY_DESTROYED itself in that
            // case, so this is scoped to the same condition as the
            // revokeBinding() call just above it.
            if ($live !== null) {
                $this->record(self::EV_COOLDOWN_STARTED, $locked, $live, null, [
                    'trigger' => 'revoke',
                    'cooldown_until' => $locked->cooldown_until->toIso8601String(),
                ], actor: $actor);
            }

            $this->record(self::EV_KEY_DESTROYED, $locked, $live, null, ['reason' => 'api_key_destroyed'], actor: $actor);

            return $locked->fresh();
        });
    }

    // ---- Phase 4 Task 6/9: per-key cooldown — Task 6 added setCooldown()/clearCooldown()/isInCooldown() as a storage-only primitive; Task 9 is what now calls them (createLiveBindingEnforced(), requestChange(), revoke(), destroyKey(), overrideCooldown() below) ----

    /**
     * Sets this key's cooldown deadline. Per-key, never account-wide —
     * api_key_id is the one identifier that survives a transfer, which
     * is exactly why the column lives on api_keys and not on
     * api_key_bindings or Account. No policy decision here about WHEN
     * to call this (that is Task 9); this is only the write primitive.
     */
    public function setCooldown(ApiKey $key, \DateTimeInterface $until): void
    {
        $key->forceFill(['cooldown_until' => $until])->save();
    }

    /** Clears this key's cooldown. Per-key, same as setCooldown(). */
    public function clearCooldown(ApiKey $key): void
    {
        $key->forceFill(['cooldown_until' => null])->save();
    }

    /**
     * Phase 4 Task 9 — Super Admin-only explicit override of exactly
     * ONE key's cooldown: pass a future $until to shorten (or extend)
     * it, or any non-future $until (now()/a past moment) to clear it
     * outright (stored as NULL, same as clearCooldown()). Authorization
     * is enforced the same way as every other Super-Admin method in
     * this section (revoke()/rebind()/approve()/reject() above): the
     * `role:super_admin` route middleware on the admin/api-access route
     * group (routes/api.php) gates the controller action that calls
     * this; this method itself does not re-check $admin's role, exactly
     * like its siblings.
     *
     * Per-key only: locks and writes exactly this $key row; nothing
     * here ever reads or writes another ApiKey row or any Account-level
     * field, so it can never become an account-wide override.
     *
     * Deliberately does NOT reuse extendLegacyDeadline(): that method
     * extends a different column (legacy_binding_grace_expires_at) for
     * an unrelated legacy-IP-migration concept, is extend-only, and
     * takes no reason. Cooldown override must go in either direction
     * (including clearing) and always requires one.
     *
     * @throws InvalidArgumentException if $reason is empty, or the key no longer exists
     */
    public function overrideCooldown(ApiKey $key, \DateTimeInterface $until, User $admin, string $reason): void
    {
        $cleanReason = trim(strip_tags($reason));
        if ($cleanReason === '') {
            throw new InvalidArgumentException('A reason is required to override an API key cooldown.');
        }

        DB::transaction(function () use ($key, $until, $admin, $cleanReason) {
            Account::query()->where('id', $key->account_id)->lockForUpdate()->first();
            $locked = ApiKey::query()->where('id', $key->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new InvalidArgumentException('This API key no longer exists.');
            }

            $old = $locked->cooldown_until;
            $newUntil = \Illuminate\Support\Carbon::instance($until);
            $locked->forceFill(['cooldown_until' => $newUntil->isFuture() ? $newUntil : null])->save();

            $this->record(self::EV_COOLDOWN_OVERRIDDEN, $locked, $locked->liveBinding(), null, [
                'reason' => Str::limit($cleanReason, 500, ''),
                'old_cooldown_until' => $old?->toIso8601String(),
                'cooldown_until' => $locked->cooldown_until?->toIso8601String(),
            ], actor: $admin);
        });
    }

    public function setAccessDisabled(ApiKey $key, User $admin, bool $disabled, ?string $reason = null): void
    {
        $key->forceFill($disabled
            ? ['access_disabled_at' => now(), 'access_disabled_reason' => $this->note($reason)]
            : ['access_disabled_at' => null, 'access_disabled_reason' => null])->save();
        $this->record($disabled ? self::EV_ACCESS_DISABLED : self::EV_ACCESS_ENABLED, $key, $key->liveBinding(), null, ['reason' => $this->note($reason)], actor: $admin);
    }

    private function revokeBinding(ApiKeyBinding $binding, User $admin, string $reason): void
    {
        $binding->forceFill([
            'status' => ApiKeyBinding::STATUS_REVOKED, 'active_slot' => null, 'revoked_at' => now(),
            'revoked_reason' => Str::limit($reason, 191, ''), 'revoked_by_user_id' => $admin->id,
        ])->save();
        $this->record(self::EV_BINDING_REVOKED, ApiKey::find($binding->api_key_id), $binding, null, ['reason' => $binding->revoked_reason], actor: $admin);
    }

    private function note(?string $note): ?string
    {
        $note = trim(strip_tags((string) $note));

        return $note === '' ? null : Str::limit($note, 500, '');
    }

    // ---- read models -------------------------------------------------------------------------------------------------

    /** What a buyer or Super Admin sees about a key's server binding. No hash, no credential, no key. */
    public function summary(ApiKey $key): array
    {
        $live = $key->liveBinding();
        $latest = $live ?? $key->bindings()->latest('id')->first();
        $pending = ApiKeyChangeRequest::where('api_key_id', $key->id)->where('status', ApiKeyChangeRequest::STATUS_PENDING)->first();
        $last = ApiKeyChangeRequest::where('api_key_id', $key->id)->where('status', '!=', ApiKeyChangeRequest::STATUS_PENDING)->latest('id')->first();

        $status = match (true) {
            $key->isAccessDisabled() => 'disabled',
            $live !== null => $live->status,
            $latest !== null => 'revoked',
            default => 'unbound',
        };

        // Phase 4 Task 12 — admin/owner visibility additions. Queried by
        // column rather than through the $key->account relation on
        // purpose: several existing callers (ApiKeyController::index()'s
        // 'global' branch) eager-load account:id,company_name only, and
        // this avoids silently reading a never-hydrated
        // authorized_server_ip off that restricted relation.
        $authorizedServerIp = Account::query()->whereKey($key->account_id)->value('authorized_server_ip');
        $legacyIpDependent = $live === null && $authorizedServerIp !== null;

        return [
            'status' => $status,
            // Phase 4 Task 7 FIX — allow_legacy_unbound no longer exempts
            // anything from enforcement (see gate()'s own docblock), so
            // this read model must stop reporting as if it still does.
            // Enforcement is unconditional once a key has no live
            // binding; it is never "not enforced" for a reason this flag
            // could ever explain.
            'enforced' => true,
            // true => this key cannot call /api/v1/* until its owner registers an authorized server. This flag
            // intentionally stays coarse (just "do you need to act") rather than re-deriving gate()'s exact
            // deadline math here; the dashboard's server-binding panel already explains the specific reason.
            'binding_required' => $latest === null,
            'binding' => $latest?->toSafeArray(),
            'access_disabled' => $key->isAccessDisabled(),
            'access_disabled_reason' => $key->access_disabled_reason,
            'credential_pending' => $live !== null && ! $live->hasCredential(),
            'pending_change_request' => $pending?->toSafeArray(),
            'last_change_request' => $last?->toSafeArray(),
            // Phase 4 Task 12 — cooldown/legacy visibility. Never a
            // second cooldown/legacy policy: these are plain reads of
            // the same state gate()/createLiveBindingEnforced() already
            // enforce (Task 7/9), added here only so Super Admin (and
            // the owning tenant, who also receives this same summary())
            // can see them without a separate endpoint.
            'cooldown_until' => $key->cooldown_until?->toIso8601String(),
            'in_cooldown' => $key->isInCooldown(),
            // Per Task 12's own rule: a key with authorized_server_ip
            // set and no live binding stays legacy-IP-dependent even
            // past an expired deadline — never reported as "resolved".
            'legacy_ip_dependent' => $legacyIpDependent,
            'legacy_authorized_ip' => $authorizedServerIp,
            'legacy_deadline' => $legacyIpDependent ? $this->legacyDeadlineFor($key)?->toIso8601String() : null,
        ];
    }

    /**
     * Phase 4 Task 12 — read-only admin/owner exposure of the exact
     * deadline math gateUnboundLegacyKey() already enforces (Task 7).
     * Thin public wrapper around the existing private calculation; no
     * new deadline logic, so Task 7's decision tree is untouched.
     */
    public function legacyDeadlineFor(ApiKey $key): ?\Illuminate\Support\Carbon
    {
        return $this->effectiveLegacyDeadline($key);
    }

    /**
     * Phase 4 Task 14 — the one authoritative, platform-wide query for
     * "how many API keys are still legacy-IP-dependent". The unit is
     * the API KEY, never the account, exactly per this task's own
     * definition:
     *
     *   api_keys.account_id = account.id
     *   AND account.authorized_server_ip IS NOT NULL
     *   AND this key has NO live binding (pending_activation/active only)
     *
     * Deliberately does NOT filter by the key's own revoked_at (a
     * revoked key stays in this count too — the task's own rule: "do
     * not silently redefine legacy-IP-dependent state based on key
     * revocation") and does NOT consult legacy_binding_grace_expires_at
     * at all (an expired or null deadline does not remove this status
     * either — same rule Task 12's summary() already encodes via
     * $legacyIpDependent). This is the same structural predicate
     * Task 12's summary() already applies per key; this method is the
     * platform-wide aggregate of that same predicate, not a second
     * definition of it.
     *
     * The retirement condition the Task 14 spec defines is exactly:
     * countLegacyIpDependentKeys() === 0. Nothing in this codebase may
     * drop the legacy authorized_server_ip column while this is
     * nonzero.
     */
    public function countLegacyIpDependentKeys(): int
    {
        return $this->legacyIpDependentKeysQuery()->count();
    }

    /**
     * Phase 4 Task 14 — the actual unresolved keys, for Super Admin
     * reconciliation reporting. Never a credential/hash: only the safe
     * identifying fields an admin needs to go fix each one (bind it,
     * or decide it is abandoned).
     */
    public function legacyIpDependentKeys(): \Illuminate\Support\Collection
    {
        return $this->legacyIpDependentKeysQuery()
            ->with('account:id,company_name')
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'key_prefix' => $key->key_prefix,
                'account_id' => $key->account_id,
                'account_name' => $key->account?->company_name,
                'revoked_at' => $key->revoked_at?->toIso8601String(),
                'legacy_authorized_ip' => $key->account?->authorized_server_ip,
                'legacy_deadline' => $this->legacyDeadlineFor($key)?->toIso8601String(),
            ]);
    }

    private function legacyIpDependentKeysQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return ApiKey::query()
            ->whereHas('account', fn ($q) => $q->whereNotNull('authorized_server_ip'))
            ->whereDoesntHave('bindings', fn ($q) => $q->live());
    }

    // ---- security events ---------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $context */
    public function record(string $event, ?ApiKey $key, ?ApiKeyBinding $binding, ?string $ip, array $context = [], bool $dedupe = false, ?User $actor = null): void
    {
        if ($dedupe) {
            $slot = 'apikey-sec:'.$event.':'.($binding?->id ?? $key?->id ?? 0).':'.($ip ?? '-').':'.($context['reason'] ?? '');
            if (! Cache::add($slot, 1, (int) config('api_binding.event_dedupe_seconds', 600))) {
                return;
            }
        }
        ApiKeySecurityEvent::create([
            'account_id' => $key?->account_id,
            'api_key_id' => $key?->id,
            'binding_id' => $binding?->id,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'ip' => $ip,
            'context' => array_intersect_key($context, array_flip(self::SAFE_CONTEXT)) ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Phase 4 Task 5 — made public (unchanged logic) so
     * BackfillApiKeyLegacyBindings can reuse this exact check for its
     * own race-lost detection instead of re-implementing it.
     */
    public function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? '');
        $driver = (int) ($e->errorInfo[1] ?? 0);

        return $state === '23000' || $driver === 1062 || $driver === 19 || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
