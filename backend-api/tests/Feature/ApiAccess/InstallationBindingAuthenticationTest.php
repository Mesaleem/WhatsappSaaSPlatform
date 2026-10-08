<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Models\ApiKeySecurityEvent;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 7 — binding-first authentication + legacy deadline
 * enforcement. Covers the task's full required list (#1–30).
 *
 * Most scenarios call ApiKeyBindingService::gate() directly against a
 * hand-built Request (Illuminate\Http\Request::create(), with REMOTE_ADDR
 * and headers set explicitly) — the same object AuthenticateApiKey/
 * ApiAuthMiddleware pass it, but without the surrounding subscription/
 * capability middleware noise, so each test isolates the decision tree
 * itself. Revocation-ordering (#22–23), no-route-bypass (#28), and the
 * trusted-IP-source proof (#29–30) go through the real HTTP route/
 * middleware stack instead, since those specifically need to prove the
 * MIDDLEWARE's own ordering/wiring, not just the seam's internal logic.
 */
class InstallationBindingAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'X-Client-Installation';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function account(): Account
    {
        return Account::factory()->create();
    }

    private function makeKey(Account $account, string $name = 'Key'): ApiKey
    {
        $plain = 'wasaas_live_'.Str::random(40);

        return ApiKey::create([
            'account_id' => $account->id,
            'name' => $name,
            'key_prefix' => substr($plain, 0, 20),
            'key_hash' => ApiKey::hashKey($plain),
        ]);
    }

    /** @return array{0: ApiKey, 1: string credential} a key with a live, credentialed, ACTIVE binding. */
    private function boundKey(Account $account, string $ip = '203.0.113.10'): array
    {
        $key = $this->makeKey($account);
        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => [$ip]]);

        return [$key->fresh(), $provisioned['credential']];
    }

    /** Builds the exact Request object gate() receives: REMOTE_ADDR + optional installation header. */
    private function requestFrom(string $ip, ?string $credential = null, array $extraServer = []): Request
    {
        $server = array_merge(['REMOTE_ADDR' => $ip], $extraServer);
        $request = Request::create('/api/v1/probe', 'GET', [], [], [], $server);
        if ($credential !== null) {
            $request->headers->set(self::HEADER, $credential);
        }

        return $request;
    }

    // =====================================================================
    // Bound key (#1–8)
    // =====================================================================

    /** 1. bound key + correct installation credential -> authenticated. */
    public function test_bound_key_with_correct_credential_authenticates(): void
    {
        $account = $this->account();
        [$key, $credential] = $this->boundKey($account);

        $result = $this->service()->gate($this->requestFrom('203.0.113.10', $credential), $key);

        $this->assertNull($result);
    }

    /** 2. bound key + wrong credential -> denied. */
    public function test_bound_key_with_wrong_credential_is_denied(): void
    {
        $account = $this->account();
        [$key] = $this->boundKey($account);

        $result = $this->service()->gate($this->requestFrom('203.0.113.10', 'wasaas_inst_totally-wrong'), $key);

        $this->assertNotNull($result);
        $this->assertSame(403, $result->getStatusCode());
        $this->assertSame(ApiKeyBindingService::CREDENTIAL_INVALID, $result->getData(true)['code']);
    }

    /** 3. bound key + missing credential -> denied. */
    public function test_bound_key_with_missing_credential_is_denied(): void
    {
        $account = $this->account();
        [$key] = $this->boundKey($account);

        $result = $this->service()->gate($this->requestFrom('203.0.113.10', null), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::CREDENTIAL_REQUIRED, $result->getData(true)['code']);
    }

    /** 4. bound key + invalid (garbage) credential -> denied. Same code path/assertion shape as #2, distinct scenario per the task's own numbered list. */
    public function test_bound_key_with_invalid_credential_is_denied(): void
    {
        $account = $this->account();
        [$key] = $this->boundKey($account);

        $result = $this->service()->gate($this->requestFrom('203.0.113.10', str_repeat('x', 64)), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::CREDENTIAL_INVALID, $result->getData(true)['code']);
    }

    /** 5. bound key, authorized_server_ip matches, but credential missing -> still denied (account IP is never consulted once bound). */
    public function test_bound_key_with_matching_account_ip_but_missing_credential_is_still_denied(): void
    {
        $account = $this->account();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        [$key] = $this->boundKey($account, '203.0.113.10');

        $result = $this->service()->gate($this->requestFrom('203.0.113.10', null), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::CREDENTIAL_REQUIRED, $result->getData(true)['code']);
    }

    /** 6. bound key: the binding's own registered IP differs from the account IP -- the correct credential still authenticates; account IP is never authoritative once bound. */
    public function test_bound_key_authenticates_by_credential_even_when_binding_ip_differs_from_account_ip(): void
    {
        $account = $this->account();
        $account->forceFill(['authorized_server_ip' => '198.51.100.1'])->save(); // deliberately different
        [$key, $credential] = $this->boundKey($account, '203.0.113.10'); // binding's own registered IP

        // Request arrives from yet another address entirely -- irrelevant once bound, only the credential matters.
        $result = $this->service()->gate($this->requestFrom('198.51.100.99', $credential), $key);

        $this->assertNull($result);
    }

    /** 7. bound key, invalid credential, request IP matches the account IP -- still denied; account IP cannot rescue a bad credential. */
    public function test_bound_key_with_invalid_credential_and_matching_account_ip_is_still_denied(): void
    {
        $account = $this->account();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        [$key] = $this->boundKey($account, '203.0.113.10');

        $result = $this->service()->gate($this->requestFrom('203.0.113.10', 'wasaas_inst_wrong'), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::CREDENTIAL_INVALID, $result->getData(true)['code']);
    }

    /** 8. Key A's credential cannot authenticate Key B's binding. */
    public function test_key_as_credential_cannot_authenticate_key_b(): void
    {
        $account = $this->account();
        [$keyA, $credentialA] = $this->boundKey($account, '203.0.113.10');
        [$keyB, $credentialB] = $this->boundKey($account, '203.0.113.11');

        $result = $this->service()->gate($this->requestFrom('203.0.113.11', $credentialA), $keyB);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::CREDENTIAL_INVALID, $result->getData(true)['code']);
    }

    // =====================================================================
    // Credential pending (#9–10)
    // =====================================================================

    /** 9. A live binding with installation_hash = NULL (credential_pending) is denied. */
    public function test_credential_pending_binding_is_denied(): void
    {
        $account = $this->account();
        $key = $this->makeKey($account);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $admin = $this->admin();
        // rebind() always mints credential: null -> this IS credential_pending.
        $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.20']]);
        $key = $key->fresh();
        $this->assertFalse($key->liveBinding()->hasCredential());

        $result = $this->service()->gate($this->requestFrom('203.0.113.20', 'anything-at-all'), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::CREDENTIAL_INVALID, $result->getData(true)['code']);
    }

    /** 10. A credential-pending binding never falls back to the account IP, even when the account has one set and the request IP matches it. */
    public function test_credential_pending_binding_never_falls_back_to_account_ip(): void
    {
        $account = $this->account();
        $account->forceFill(['authorized_server_ip' => '203.0.113.20'])->save();
        $key = $this->makeKey($account);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $admin = $this->admin();
        $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.20']]);
        $key = $key->fresh();

        // Request IP matches the ACCOUNT's authorized_server_ip exactly, and no credential is sent at all.
        $result = $this->service()->gate($this->requestFrom('203.0.113.20', null), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::CREDENTIAL_REQUIRED, $result->getData(true)['code'], 'must be refused for the bound-key reason, never silently pass via the legacy IP path.');
    }

    // =====================================================================
    // Legacy unbound key (#11–16)
    // =====================================================================

    private function legacyKey(Account $account, string $accountIp, ?Carbon $deadline): ApiKey
    {
        $account->forceFill(['authorized_server_ip' => $accountIp])->save();
        $key = $this->makeKey($account);
        $key->forceFill(['legacy_binding_grace_expires_at' => $deadline])->save();

        return $key->fresh();
    }

    /** 11. legacy key, valid (future) deadline, matching account IP -> authenticated. */
    public function test_legacy_key_with_valid_deadline_and_matching_ip_authenticates(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', now()->addDays(5));

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNull($result);
    }

    /** 12. legacy key, valid deadline, wrong IP -> denied. */
    public function test_legacy_key_with_valid_deadline_and_wrong_ip_is_denied(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', now()->addDays(5));

        $result = $this->service()->gate($this->requestFrom('198.51.100.7'), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::DENIED, $result->getData(true)['code']);
    }

    /** 13. legacy key, NULL deadline -> INSTALLATION_BINDING_REQUIRED (mapped to the existing BINDING_REQUIRED code). */
    public function test_legacy_key_with_null_deadline_is_denied_as_binding_required(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', null);

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $result->getData(true)['code']);
    }

    /** 14. legacy key, expired deadline -> INSTALLATION_BINDING_REQUIRED. */
    public function test_legacy_key_with_expired_deadline_is_denied_as_binding_required(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', now()->subDay());

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $result->getData(true)['code']);
    }

    /** 15. legacy key at the exact deadline boundary -> authenticated (policy is now <= deadline). */
    public function test_legacy_key_at_exact_deadline_boundary_authenticates(): void
    {
        $account = $this->account();
        $deadline = now()->addDay();
        $key = $this->legacyKey($account, '203.0.113.10', $deadline);
        Carbon::setTestNow($deadline); // now === deadline exactly

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNull($result);
    }

    /** 16. A brand-new unbound key with no legacy state at all (account has no IP) -> INSTALLATION_BINDING_REQUIRED, no open-ended bypass. */
    public function test_new_unbound_key_with_no_legacy_state_is_denied_as_binding_required(): void
    {
        $account = $this->account(); // authorized_server_ip stays null
        $key = $this->makeKey($account);

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $result->getData(true)['code']);
    }

    // =====================================================================
    // Per-key deadline extension (#17–21)
    // =====================================================================

    /** 17. An extension for the SAME key extends access past the base deadline. */
    public function test_extension_for_same_key_extends_access(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', now()->addDay());
        $this->service()->extendLegacyDeadline($key, now()->addDays(10), $this->admin());

        Carbon::setTestNow(now()->addDays(5)); // past the base deadline, within the extension

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key->fresh());

        $this->assertNull($result);
    }

    /** 18. An extension for a DIFFERENT key must never extend THIS key's access. */
    public function test_extension_for_another_key_does_not_extend_this_key(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', now()->addDay());
        $otherKey = $this->legacyKey($account, '203.0.113.10', now()->addDay());

        $this->service()->extendLegacyDeadline($otherKey, now()->addDays(10), $this->admin());

        Carbon::setTestNow(now()->addDays(5)); // past both base deadlines

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key->fresh());

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $result->getData(true)['code']);
    }

    /** 19. An extension EARLIER than the base deadline cannot shorten it -- the base remains effective. */
    public function test_earlier_extension_cannot_shorten_the_base_deadline(): void
    {
        $account = $this->account();
        $base = now()->addDays(10);
        $key = $this->legacyKey($account, '203.0.113.10', $base);
        $this->service()->extendLegacyDeadline($key, now()->addDay(), $this->admin()); // earlier than base

        Carbon::setTestNow(now()->addDays(5)); // after the (earlier) extension, before the base

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key->fresh());

        $this->assertNull($result, 'the base deadline (10 days) must remain effective, not the earlier extension (1 day).');
    }

    /** 20. A malformed/invalid extension record must not silently grant access. */
    public function test_malformed_extension_does_not_grant_access(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', now()->addDay());

        ApiKeySecurityEvent::create([
            'account_id' => $account->id,
            'api_key_id' => $key->id,
            'event' => ApiKeyBindingService::EV_LEGACY_DEADLINE_EXTENDED,
            'context' => ['extended_until' => 'not-a-real-date'],
            'created_at' => now(),
        ]);

        Carbon::setTestNow(now()->addDays(5)); // past the base deadline

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key->fresh());

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $result->getData(true)['code']);
    }

    /** 21. Multiple extensions for the same key -- the LATEST valid one is used. */
    public function test_multiple_extensions_use_the_latest_valid_one(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', now()->addDay());

        $this->service()->extendLegacyDeadline($key, now()->addDays(3), $this->admin());
        Carbon::setTestNow(now()->addMinute()); // ensure a distinct, later created_at
        $this->service()->extendLegacyDeadline($key, now()->addDays(20), $this->admin());

        Carbon::setTestNow(now()->addDays(10)); // past the first extension, within the second (latest)

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key->fresh());

        $this->assertNull($result, 'the latest extension (20 days from its own creation time) must be the one in effect.');
    }

    // =====================================================================
    // Revocation (#22–23) — through the real middleware stack.
    // =====================================================================

    private function clientWithActiveSubscription(): array
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create([
            'engine_type' => 'qr', 'status' => 'active', 'expires_at' => now()->addMonth(),
        ]);
        $plain = 'wasaas_live_'.Str::random(40);
        $key = ApiKey::create([
            'account_id' => $account->id, 'name' => 'Key',
            'key_prefix' => substr($plain, 0, 20), 'key_hash' => ApiKey::hashKey($plain),
        ]);

        return [$account, $key, $plain];
    }

    /** 22. A revoked key cannot authenticate even if its (legacy) account IP matches. */
    public function test_revoked_key_cannot_authenticate_via_legacy_ip(): void
    {
        [$account, $key, $plain] = $this->clientWithActiveSubscription();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $key->forceFill(['legacy_binding_grace_expires_at' => now()->addDays(5), 'revoked_at' => now()])->save();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['X-API-KEY' => $plain, 'Idempotency-Key' => 'rev-'.Str::random(8)])
            ->deleteJson('/api/v1/scheduled-messages/999999');

        $response->assertStatus(401);
    }

    /** 23. A revoked key cannot authenticate via a live binding's credential either. */
    public function test_revoked_key_cannot_authenticate_via_binding_credential(): void
    {
        [$account, $key, $plain] = $this->clientWithActiveSubscription();
        $provisioned = $this->service()->provision($key->fresh(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $key->fresh()->forceFill(['revoked_at' => now()])->save();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders([
                'X-API-KEY' => $plain,
                self::HEADER => $provisioned['credential'],
                'Idempotency-Key' => 'rev2-'.Str::random(8),
            ])
            ->deleteJson('/api/v1/scheduled-messages/999999');

        $response->assertStatus(401);
    }

    // =====================================================================
    // Successful-use recording (#24–26)
    // =====================================================================

    /** 24. Successful bound authentication updates last_success_at. */
    public function test_successful_bound_authentication_updates_last_success_at(): void
    {
        $account = $this->account();
        [$key, $credential] = $this->boundKey($account);
        $this->assertNull($key->liveBinding()->last_success_at);

        $this->service()->gate($this->requestFrom('203.0.113.10', $credential), $key);

        $this->assertNotNull($key->fresh()->liveBinding()->last_success_at);
    }

    /** 25. Successful bound authentication records the request IP. */
    public function test_successful_bound_authentication_records_request_ip(): void
    {
        $account = $this->account();
        [$key, $credential] = $this->boundKey($account, '203.0.113.10');

        $this->service()->gate($this->requestFrom('203.0.113.10', $credential), $key);

        $this->assertSame('203.0.113.10', $key->fresh()->liveBinding()->last_success_ip);
    }

    /** 26. A failed credential verification must NOT update last_success_*. */
    public function test_failed_credential_verification_does_not_update_last_success(): void
    {
        $account = $this->account();
        [$key] = $this->boundKey($account);

        $this->service()->gate($this->requestFrom('203.0.113.10', 'wrong-credential'), $key);

        $fresh = $key->fresh()->liveBinding();
        $this->assertNull($fresh->last_success_at);
        $this->assertNull($fresh->last_success_ip);
    }

    // =====================================================================
    // Regression / no-bypass (#27–28)
    // =====================================================================

    /** 27. The pre-existing (Task 4) credential-verification unit tests remain valid -- not re-asserted here (see ApiKeyBindingCredentialTest), only confirming gate() now actually calls into them. */
    public function test_gate_delegates_to_the_existing_task_4_verification_primitive(): void
    {
        $account = $this->account();
        [$key, $credential] = $this->boundKey($account);
        $binding = $key->liveBinding();

        // Same primitive, called directly, must agree with gate()'s own verdict.
        $this->assertTrue($binding->verifyCredential($credential));
        $this->assertNull($this->service()->gate($this->requestFrom('203.0.113.10', $credential), $key));
    }

    /** 28. No route bypasses the central binding-aware gate -- both external auth middlewares still delegate to the same ApiKeyBindingService::gate(). */
    public function test_both_external_auth_middlewares_still_delegate_to_the_one_gate(): void
    {
        $source1 = file_get_contents(app_path('Http/Middleware/AuthenticateApiKey.php'));
        $source2 = file_get_contents(app_path('Http/Middleware/ApiAuthMiddleware.php'));

        $this->assertStringContainsString('ApiKeyBindingService::class)->gate(', $source1);
        $this->assertStringContainsString('ApiKeyBindingService::class)->gate(', $source2);
    }

    // =====================================================================
    // Proxy / trusted IP source (#29–30)
    // =====================================================================

    /** 29. Authentication uses the framework's IP abstraction ($request->ip()), not a raw header. */
    public function test_authentication_uses_the_framework_ip_abstraction(): void
    {
        $request = $this->requestFrom('203.0.113.10', null, ['HTTP_X_FORWARDED_FOR' => '9.9.9.9']);

        // No trusted proxies are configured in this repo (bootstrap/app.php) -> ip() must be the real socket address.
        $this->assertSame('203.0.113.10', $request->ip());
    }

    /** 30. A raw X-Forwarded-For/X-Real-IP supplied by the client cannot override the authentication IP end-to-end. */
    public function test_client_supplied_forwarded_for_cannot_override_the_authentication_ip(): void
    {
        $account = $this->account();
        // Account is authorized for the SPOOFED address, not the real one -- if the spoof worked, this would authenticate.
        $key = $this->legacyKey($account, '9.9.9.9', now()->addDays(5));

        $result = $this->service()->gate(
            $this->requestFrom('203.0.113.10', null, ['HTTP_X_FORWARDED_FOR' => '9.9.9.9']),
            $key
        );

        $this->assertNotNull($result, 'the real REMOTE_ADDR (203.0.113.10) must be evaluated, never the spoofed X-Forwarded-For (9.9.9.9).');
    }

    // =====================================================================
    // Task 7 FIX — the removed unbound-key override must stay removed.
    // =====================================================================

    /** Fix-regression 1. unbound key + authorized_server_ip = NULL -> INSTALLATION_BINDING_REQUIRED. */
    public function test_fix_unbound_key_with_null_account_ip_is_binding_required(): void
    {
        $account = $this->account(); // authorized_server_ip stays null
        $key = $this->makeKey($account);

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $result->getData(true)['code']);
    }

    /**
     * Fix-regression 2. The removed `allow_legacy_unbound` override must
     * STILL be refused with INSTALLATION_BINDING_REQUIRED, however the
     * flag is set -- proving it is read nowhere in gate() any more, not
     * merely defaulted off. Covers the config flag directly (true) and
     * the legacy test-suite convenience trait that used to flip it on.
     */
    public function test_fix_legacy_override_flag_does_not_authenticate_an_unbound_key(): void
    {
        $account = $this->account(); // authorized_server_ip stays null
        $key = $this->makeKey($account);

        config(['api_binding.allow_legacy_unbound' => true]);
        $resultViaConfig = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNotNull($resultViaConfig, 'setting the config flag directly must not authenticate an unbound key.');
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $resultViaConfig->getData(true)['code']);

        (new class {
            use \Tests\Concerns\AllowsUnboundApiKeys;

            public function apply(): void
            {
                $this->setUpAllowsUnboundApiKeys();
            }
        })->apply();

        $resultViaTrait = $this->service()->gate($this->requestFrom('198.51.100.1'), $key);

        $this->assertNotNull($resultViaTrait, 'the AllowsUnboundApiKeys test trait must not authenticate an unbound key either.');
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $resultViaTrait->getData(true)['code']);
    }

    /** Fix-regression 3. unbound legacy key + no deadline + matching account IP -> still INSTALLATION_BINDING_REQUIRED (never an implicit pass). */
    public function test_fix_legacy_key_with_no_deadline_and_matching_ip_is_still_binding_required(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', null); // account IP set, deadline NULL

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $result->getData(true)['code']);
    }

    /** Fix-regression 4. unbound legacy key + expired deadline + matching account IP -> still INSTALLATION_BINDING_REQUIRED. */
    public function test_fix_legacy_key_with_expired_deadline_and_matching_ip_is_still_binding_required(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', now()->subMinute());

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $result->getData(true)['code']);
    }

    /** Fix-regression 5. unbound legacy key + valid deadline + matching IP -> allowed (the one legitimate path left). */
    public function test_fix_legacy_key_with_valid_deadline_and_matching_ip_is_allowed(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', now()->addDays(5));

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key);

        $this->assertNull($result);
    }

    /** Fix-regression 6. base deadline NULL + an extension event present -> still INSTALLATION_BINDING_REQUIRED (an extension cannot manufacture eligibility -- the preserved interpretation from the original Task 7 report). */
    public function test_fix_null_base_deadline_with_extension_present_is_still_binding_required(): void
    {
        $account = $this->account();
        $key = $this->legacyKey($account, '203.0.113.10', null);
        $this->service()->extendLegacyDeadline($key, now()->addDays(30), $this->admin());

        $result = $this->service()->gate($this->requestFrom('203.0.113.10'), $key->fresh());

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::BINDING_REQUIRED, $result->getData(true)['code']);
    }

    /**
     * Fix-regression 7. A BOUND key never uses the legacy override/account-IP path at all, even with the
     * override flag on and the account IP matching the request -- Case A short-circuits before Case B is
     * ever reached, so this isn't just "the override is off", it's "bound keys never consult it".
     */
    public function test_fix_bound_key_never_uses_the_legacy_override_or_account_ip_path(): void
    {
        config(['api_binding.allow_legacy_unbound' => true]);
        $account = $this->account();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        [$key] = $this->boundKey($account, '203.0.113.10');

        // No credential sent at all -- if the legacy/override path were consulted, the matching account IP
        // would authenticate it. It must not: a bound key always needs the credential, full stop.
        $result = $this->service()->gate($this->requestFrom('203.0.113.10', null), $key);

        $this->assertNotNull($result);
        $this->assertSame(ApiKeyBindingService::CREDENTIAL_REQUIRED, $result->getData(true)['code']);
    }
}
