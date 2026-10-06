<?php

namespace App\Services\ApiAccess;

use App\Models\Account;
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
 * sha256 hash) in `X-Client-Installation`, AND by connecting from the bound IP policy. The credential is a bearer
 * secret, so on its own it can be copied - that is why the IP policy is a second, independent factor: a friend using
 * the copied key and credential from another server is refused by the IP policy, and a different IP never creates a
 * second installation (activation happens once per binding, under a row lock). A client-chosen "server id" string is
 * never trusted. The client IP is Request::ip(), which honours the trusted-proxy configuration.
 *
 * Enforcement order inside gate(): access disabled -> binding exists/live -> installation credential -> IP policy ->
 * (first use) activation. Legacy keys (no binding row at all) are refused with API_SERVER_BINDING_REQUIRED unless the temporary
 * api_binding.allow_legacy_unbound override is on.
 * Every denial is the same response (403 API_CLIENT_NOT_AUTHORIZED) so a caller cannot tell which factor failed or
 * learn anything about the authorized server; the security event records which one.
 */
class ApiKeyBindingService
{
    public const DENIED = 'API_CLIENT_NOT_AUTHORIZED';
    public const DISABLED = 'API_ACCESS_DISABLED';
    public const BINDING_REQUIRED = 'API_SERVER_BINDING_REQUIRED';

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

    /** The only context keys an event may carry. */
    private const SAFE_CONTEXT = ['label', 'ip_policy', 'request_id', 'reason', 'new_binding_id', 'old_binding_id', 'requested_ip', 'current_ip', 'client'];

    private const CREDENTIAL_PREFIX = 'wasaas_inst_';

    // ---- request enforcement -----------------------------------------------------------------------------------

    /**
     * Request gate for every Developer API key: the key must be live, and the request must come from the account's
     * registered server IP (see ServerIpBindingService). The IP is the connecting socket address, never a header.
     * A denial is generic, so a caller cannot tell which check failed or read the registered IP.
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

        $account = Account::query()->find($key->account_id);

        // Temporary emergency override (config/api_binding.php, default off). Test suites that exercise
        // unrelated API contracts turn it on; production never does.
        if ($account && $account->authorized_server_ip === null && config('api_binding.allow_legacy_unbound', false)) {
            $this->record(self::EV_LEGACY_OVERRIDE_USED, $key, null, $ip, [], dedupe: true);

            return null;
        }

        if (! $account || $account->authorized_server_ip === null) {
            $this->record(self::EV_BINDING_REQUIRED, $key, null, $ip, [], dedupe: true);

            return self::denial(self::BINDING_REQUIRED, 'Set your server IP under Profile → API key before you use this key.');
        }

        if (! app(ServerIpBindingService::class)->allows($account, $ip)) {
            $this->record(self::EV_UNAUTH_IP, $key, null, $ip, ['ip_policy' => 'SINGLE_IP'], dedupe: true);

            // The flag tells the caller what to do, for example after a shared host changed the outbound IP.
            return self::denial(self::DENIED, null, [
                'action' => 'verify_server_ip',
                'help' => 'If your server IP has changed (for example, your shared hosting provider moved your site), verify the new IP in your WapHub dashboard under Profile → API key, or contact support.',
            ]);
        }

        return null;
    }

    /** @param array<string, mixed> $extra additional fields for the caller; never secrets or the registered IP */
    public static function denial(string $code = self::DENIED, ?string $message = null, array $extra = []): JsonResponse
    {
        $message ??= 'This API key is not authorized for this server.';

        return response()->json(['success' => false, 'status' => false, 'code' => $code, 'error_code' => $code, 'message' => $message] + $extra, 403);
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

        $binding = $this->createLive($key, [
            'label' => $this->label($opts['label'] ?? null),
            'ip_policy' => $policy,
            'authorized_ips' => $ips,
            'credential' => $credential,
        ], $actor);

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

    public function requestChange(ApiKey $key, User $actor, array $input): ApiKeyChangeRequest
    {
        $binding = $key->liveBinding();
        if (! $binding || $binding->status !== ApiKeyBinding::STATUS_ACTIVE) {
            throw new InvalidArgumentException('A server change can only be requested for a key with an activated authorized server.');
        }
        [$policy, $ips] = $this->normalizePolicy($input['ip_policy'] ?? null, (array) ($input['requested_ips'] ?? []));
        if ($ips === []) {
            throw new InvalidArgumentException('Provide the IP address of the new server.');
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        try {
            $request = ApiKeyChangeRequest::create([
                'account_id' => $key->account_id,
                'api_key_id' => $key->id,
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

        $this->record(self::EV_CHANGE_REQUESTED, $key, $binding, null, ['request_id' => $request->id, 'requested_ip' => $ips[0]], actor: $actor);

        return $request;
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
            if ($old) {
                $this->revokeBinding($old, $admin, 'server_change_approved');
            }
            $new = $this->createLive($key, [
                'label' => $locked->requested_label,
                'ip_policy' => $locked->requested_ip_policy,
                'authorized_ips' => $locked->requested_ips ?? [],
                'credential' => null,
            ], $admin);
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

    public function revoke(ApiKey $key, User $admin, ?string $reason = null): bool
    {
        $binding = $key->liveBinding();
        if (! $binding) {
            return false;
        }
        $this->revokeBinding($binding, $admin, $this->note($reason) ?? 'revoked_by_super_admin');

        return true;
    }

    /** Super Admin binds (or re-binds) a key to a new server. The buyer then mints the credential. */
    public function rebind(ApiKey $key, User $admin, array $opts): ApiKeyBinding
    {
        [$policy, $ips] = $this->normalizePolicy($opts['ip_policy'] ?? null, (array) ($opts['authorized_ips'] ?? []), allowNone: true);

        return DB::transaction(function () use ($key, $admin, $opts, $policy, $ips) {
            $old = $key->liveBinding();
            if ($old) {
                $this->revokeBinding($old, $admin, 'rebound_by_super_admin');
            }
            $new = $this->createLive($key, ['label' => $this->label($opts['label'] ?? null), 'ip_policy' => $policy, 'authorized_ips' => $ips, 'credential' => null], $admin);
            $this->record(self::EV_REBOUND, $key, $new, null, ['old_binding_id' => $old?->id, 'new_binding_id' => $new->id, 'label' => $new->label, 'ip_policy' => $policy], actor: $admin);

            return $new;
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

        return [
            'status' => $status,
            'enforced' => $latest !== null || ! config('api_binding.allow_legacy_unbound', false),
            // true => this key cannot call /api/v1/* until its owner registers an authorized server.
            'binding_required' => $latest === null && ! config('api_binding.allow_legacy_unbound', false),
            'binding' => $latest?->toSafeArray(),
            'access_disabled' => $key->isAccessDisabled(),
            'access_disabled_reason' => $key->access_disabled_reason,
            'credential_pending' => $live !== null && ! $live->hasCredential(),
            'pending_change_request' => $pending?->toSafeArray(),
            'last_change_request' => $last?->toSafeArray(),
        ];
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

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? '');
        $driver = (int) ($e->errorInfo[1] ?? 0);

        return $state === '23000' || $driver === 1062 || $driver === 19 || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
