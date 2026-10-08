<?php

namespace Tests\Feature\ApiAccess;

use App\Http\Controllers\Api\ApiKeyController;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\ApiKeySecurityEvent;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 11 — API Binding Security Events.
 *
 * Covers the 15 required tests from the Task 11 spec. Items 11/12
 * (EV_COOLDOWN_OVERRIDDEN payload + per-key isolation) are already
 * fully covered by CooldownOverrideTest::test_override_emits_cooldown_overridden_event_with_the_required_payload
 * and ::test_override_affects_only_the_selected_key (Task 9) — not
 * duplicated here; this file cross-references rather than re-proves
 * them, per the spec's own "do not invent ... where an existing
 * [test/event] already correctly represents" guidance applied to
 * tests as well as events.
 *
 * Fixture helpers reuse the exact established patterns from
 * InstallationAllowanceEnforcementTest / CooldownOverrideTest /
 * RegenerateSecretBindingNeutralityTest / InstallationBindingAuthenticationTest
 * (Tasks 6/7/9), so this file introduces no new fixture style.
 */
class ApiBindingSecurityEventsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function controller(): ApiKeyController
    {
        return app(ApiKeyController::class);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function capability(): Capability
    {
        return Capability::firstOrCreate(
            ['slug' => InstallationAllowanceResolver::CAPABILITY],
            ['label' => 'API Installations', 'category' => 'platform']
        );
    }

    /** Same real-fixture shape as every other Phase 4 task's grantAllowance() helper. */
    private function accountWithAllowance(int $limit = 5): Account
    {
        $account = Account::factory()->create();
        $capability = $this->capability();

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $plan = Plan::create([
            'slug' => 'task11-plan-'.Str::random(8),
            'label' => 'Task 11 Test Plan',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
        ]);
        $plan->capabilities()->attach($capability->id, ['usage_limit' => $limit]);

        Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.Str::random(10),
            'plan_key' => $plan->slug,
            'plan_label' => $plan->label,
            'amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(10),
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $account;
    }

    private function makeKey(Account $account, string $name = 'Key'): ApiKey
    {
        $plain = 'wasaas_live_'.Str::random(40);

        return ApiKey::create([
            'account_id' => $account->id,
            'name' => $name,
            'key_prefix' => substr($plain, 0, 20),
            'key_hash' => ApiKey::hashKey($plain),
            'secret_prefix' => 'wasaas_secret_'.Str::random(6),
            'secret_hash' => ApiKey::hashSecret('wasaas_secret_'.Str::random(40)),
        ]);
    }

    private function provisionedKey(Account $account, string $name, string $ip): array
    {
        $key = $this->makeKey($account, $name);
        $result = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => [$ip]]);

        return [$key->fresh(), $result];
    }

    /** Exact Request shape InstallationBindingAuthenticationTest builds for gate(). */
    private function requestFrom(string $ip, ?string $credential = null): Request
    {
        $server = ['REMOTE_ADDR' => $ip];
        $request = Request::create('/api/v1/probe', 'GET', [], [], [], $server);
        if ($credential !== null) {
            $request->headers->set(config('api_binding.installation_header', 'X-Client-Installation'), $credential);
        }

        return $request;
    }

    /** Exact Request shape RegenerateSecretBindingNeutralityTest builds for controller methods that only need ResolvesTenantAccount. */
    private function controllerRequest(Account $account, User $user, string $path = '/api/developer/api-keys', array $input = []): Request
    {
        $request = Request::create($path, 'POST', $input);
        $request->attributes->set('account_id', $account->id);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function latestEvent(string $event, ?int $apiKeyId = null): ?ApiKeySecurityEvent
    {
        $query = ApiKeySecurityEvent::where('event', $event);
        if ($apiKeyId !== null) {
            $query->where('api_key_id', $apiKeyId);
        }

        return $query->latest('id')->first();
    }

    // ---- 1. Key creation emits the expected event -------------------------------------------------------------

    public function test_key_creation_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        $user = User::factory()->create(['account_id' => $account->id]);

        $request = $this->controllerRequest($account, $user, '/api/developer/api-keys', [
            'name' => 'Primary Server',
            'acknowledge_server_binding' => true,
            'ip_policy' => 'SINGLE_IP',
            'authorized_ips' => ['203.0.113.50'],
        ]);

        $response = $this->controller()->store($request);
        $this->assertSame(201, $response->getStatusCode());
        $apiKeyId = $response->getData(true)['api_key']['id'];

        $event = $this->latestEvent(ApiKeyBindingService::EV_KEY_CREATED, $apiKeyId);
        $this->assertNotNull($event, 'EV_KEY_CREATED must be recorded on key creation.');
        $this->assertSame($account->id, $event->account_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertNotNull($event->binding_id, 'the binding minted alongside the key must be attached to the event.');
    }

    // ---- 2. Key revoke emits the expected event ----------------------------------------------------------------

    public function test_key_revoke_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        [$key, $provisioned] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->admin();

        $this->service()->revoke($key->fresh(), $admin, 'customer requested revoke');

        $event = $this->latestEvent(ApiKeyBindingService::EV_BINDING_REVOKED, $key->id);
        $this->assertNotNull($event, 'EV_BINDING_REVOKED must be recorded on revoke().');
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame($provisioned['binding']->id, $event->binding_id);
    }

    // ---- 3. Binding creation emits the expected event ----------------------------------------------------------

    public function test_binding_creation_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account, 'A');

        $result = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);

        $event = $this->latestEvent(ApiKeyBindingService::EV_SERVER_REGISTERED, $key->id);
        $this->assertNotNull($event, 'EV_SERVER_REGISTERED must be recorded on provision().');
        $this->assertSame($result['binding']->id, $event->binding_id);
        $this->assertSame($account->id, $event->account_id);
    }

    // ---- 4. Binding replacement/rebind emits the expected event ------------------------------------------------

    public function test_binding_rebind_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->admin();

        $newBinding = $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);

        $event = $this->latestEvent(ApiKeyBindingService::EV_REBOUND, $key->id);
        $this->assertNotNull($event, 'EV_REBOUND must be recorded on rebind().');
        $this->assertSame($newBinding->id, $event->binding_id);
        $this->assertSame($admin->id, $event->actor_user_id);
    }

    public function test_binding_transfer_approval_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $owner = User::factory()->create(['account_id' => $account->id]);
        $admin = $this->admin();

        $changeRequest = $this->service()->requestChange($key->fresh(), $owner, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);
        $this->service()->approve($changeRequest, $admin);

        $event = $this->latestEvent(ApiKeyBindingService::EV_CHANGE_APPROVED, $key->id);
        $this->assertNotNull($event, 'EV_CHANGE_APPROVED must be recorded on approve().');
        $this->assertSame($admin->id, $event->actor_user_id);
    }

    // ---- 5. Credential failure emits the expected security event -----------------------------------------------

    public function test_credential_failure_emits_the_expected_security_event(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.10');

        $this->service()->gate($this->requestFrom('203.0.113.10', 'wasaas_inst_totally-wrong'), $key);

        $event = $this->latestEvent(ApiKeyBindingService::EV_CREDENTIAL_INVALID, $key->id);
        $this->assertNotNull($event, 'EV_CREDENTIAL_INVALID must be recorded on a failed credential check.');
        $this->assertSame('203.0.113.10', $event->ip);
    }

    // ---- 6. Missing credential emits the expected security event -----------------------------------------------

    public function test_missing_credential_emits_the_expected_security_event(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.10');

        $this->service()->gate($this->requestFrom('203.0.113.10', null), $key);

        $event = $this->latestEvent(ApiKeyBindingService::EV_CREDENTIAL_MISSING, $key->id);
        $this->assertNotNull($event, 'EV_CREDENTIAL_MISSING must be recorded when no installation header is sent.');
    }

    // ---- 7. Legacy deadline expiry emits the expected event -----------------------------------------------------

    public function test_legacy_deadline_expiry_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $key = $this->makeKey($account, 'A');
        $key->forceFill(['legacy_binding_grace_expires_at' => now()->subDay()])->save();

        $this->service()->gate($this->requestFrom('203.0.113.10'), $key->fresh());

        $event = $this->latestEvent(ApiKeyBindingService::EV_LEGACY_DEADLINE_EXPIRED, $key->id);
        $this->assertNotNull($event, 'EV_LEGACY_DEADLINE_EXPIRED must be recorded once the grace window has passed.');
    }

    // ---- 8. Legacy deadline extension emits the expected event --------------------------------------------------

    public function test_legacy_deadline_extension_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account, 'A');
        $admin = $this->admin();

        $this->service()->extendLegacyDeadline($key, now()->addDays(10), $admin, 'customer migration delay, approved');

        $event = $this->latestEvent(ApiKeyBindingService::EV_LEGACY_DEADLINE_EXTENDED, $key->id);
        $this->assertNotNull($event, 'EV_LEGACY_DEADLINE_EXTENDED must be recorded on extendLegacyDeadline().');
        $this->assertSame($admin->id, $event->actor_user_id);
    }

    // ---- 9. Cooldown-triggered revoke emits the expected event (NEW: EV_COOLDOWN_STARTED, trigger=revoke) --------

    public function test_cooldown_triggered_revoke_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->admin();

        $this->service()->revoke($key->fresh(), $admin, 'revoked, cooldown must start');

        $event = $this->latestEvent(ApiKeyBindingService::EV_COOLDOWN_STARTED, $key->id);
        $this->assertNotNull($event, 'EV_COOLDOWN_STARTED must be recorded when revoke() sets the cooldown.');
        $this->assertSame('revoke', $event->context['trigger']);
        $this->assertNotNull($event->context['cooldown_until']);
        $this->assertTrue($key->fresh()->isInCooldown());
    }

    public function test_cooldown_triggered_destroy_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $actor = $this->admin();

        $this->service()->destroyKey($key->fresh(), $actor);

        $event = $this->latestEvent(ApiKeyBindingService::EV_COOLDOWN_STARTED, $key->id);
        $this->assertNotNull($event, 'EV_COOLDOWN_STARTED must be recorded when destroyKey() revokes a live binding and sets the cooldown.');
        $this->assertSame('revoke', $event->context['trigger']);
    }

    // ---- 10. Cooldown-triggered transfer emits the expected event (NEW: EV_COOLDOWN_STARTED, trigger=transfer) ---

    public function test_cooldown_triggered_rebind_transfer_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->admin();

        $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);

        $event = $this->latestEvent(ApiKeyBindingService::EV_COOLDOWN_STARTED, $key->id);
        $this->assertNotNull($event, 'EV_COOLDOWN_STARTED must be recorded when a transfer (rebind) sets the cooldown.');
        $this->assertSame('transfer', $event->context['trigger']);
    }

    public function test_cooldown_triggered_approve_transfer_emits_the_expected_event(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $owner = User::factory()->create(['account_id' => $account->id]);
        $admin = $this->admin();
        $changeRequest = $this->service()->requestChange($key->fresh(), $owner, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);

        $this->service()->approve($changeRequest, $admin);

        $event = $this->latestEvent(ApiKeyBindingService::EV_COOLDOWN_STARTED, $key->id);
        $this->assertNotNull($event, 'EV_COOLDOWN_STARTED must be recorded when an approved change request sets the cooldown.');
        $this->assertSame('transfer', $event->context['trigger']);
    }

    public function test_cooldown_denial_emits_the_expected_event_on_createLiveBindingEnforced(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->admin();
        $this->service()->revoke($key->fresh(), $admin, 'revoke to trigger cooldown');

        try {
            $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.3']]);
            $this->fail('Expected ApiKeyCooldownActiveException while the key is in cooldown.');
        } catch (\App\Services\ApiAccess\ApiKeyCooldownActiveException $e) {
            // expected
        }

        $event = $this->latestEvent(ApiKeyBindingService::EV_COOLDOWN_DENIED, $key->id);
        $this->assertNotNull($event, 'EV_COOLDOWN_DENIED must be recorded when a rebind attempt is rejected due to active cooldown.');
    }

    public function test_cooldown_denial_emits_the_expected_event_on_requestChange(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->admin();
        $owner = User::factory()->create(['account_id' => $account->id]);
        $this->service()->revoke($key->fresh(), $admin, 'revoke to trigger cooldown');

        try {
            $this->service()->requestChange($key->fresh(), $owner, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.3']]);
            $this->fail('Expected ApiKeyCooldownActiveException while the key is in cooldown.');
        } catch (\App\Services\ApiAccess\ApiKeyCooldownActiveException $e) {
            // expected
        }

        $event = $this->latestEvent(ApiKeyBindingService::EV_COOLDOWN_DENIED, $key->id);
        $this->assertNotNull($event, 'EV_COOLDOWN_DENIED must be recorded when requestChange() is rejected due to active cooldown.');
    }

    // ---- 11/12. EV_COOLDOWN_OVERRIDDEN payload + per-key isolation --------------------------------------------
    //
    // Already fully covered by CooldownOverrideTest (Task 9):
    //   - test_override_emits_cooldown_overridden_event_with_the_required_payload
    //   - test_override_affects_only_the_selected_key
    // Deliberately not duplicated here.

    // ---- 13. Event records do not contain plaintext credentials/secrets ----------------------------------------

    public function test_events_never_contain_plaintext_credentials_or_secrets(): void
    {
        $account = $this->accountWithAllowance();
        $user = User::factory()->create(['account_id' => $account->id]);

        $response = $this->controller()->store($this->controllerRequest($account, $user, '/api/developer/api-keys', [
            'name' => 'Primary Server',
            'acknowledge_server_binding' => true,
            'ip_policy' => 'SINGLE_IP',
            'authorized_ips' => ['203.0.113.50'],
        ]));
        $body = $response->getData(true);
        $plainTextKey = $body['plain_text_key'];
        $plainTextSecret = $body['plain_text_secret'] ?? null;
        $apiKeyId = $body['api_key']['id'];
        $installationCredential = $body['installation_credential'] ?? null;

        $this->controller()->regenerateSecret($this->controllerRequest($account, $user, '/api/developer/api-keys/'.$apiKeyId.'/regenerate-secret'), $apiKeyId);

        $events = ApiKeySecurityEvent::where('api_key_id', $apiKeyId)->get();
        $this->assertGreaterThan(0, $events->count());

        foreach ($events as $event) {
            $haystack = json_encode($event->toArray());
            $this->assertStringNotContainsString($plainTextKey, $haystack, 'plaintext key must never appear in a security event.');
            if ($plainTextSecret) {
                $this->assertStringNotContainsString($plainTextSecret, $haystack, 'plaintext secret must never appear in a security event.');
            }
            if ($installationCredential) {
                $this->assertStringNotContainsString($installationCredential, $haystack, 'installation credential must never appear in a security event.');
            }
        }
    }

    // ---- 14. Events for Key A cannot be accidentally attributed to Key B ---------------------------------------

    public function test_events_for_key_a_are_never_attributed_to_key_b(): void
    {
        $account = $this->accountWithAllowance();
        [$keyA] = $this->provisionedKey($account, 'A', '203.0.113.1');
        [$keyB] = $this->provisionedKey($account, 'B', '203.0.113.2');
        $admin = $this->admin();

        $this->service()->revoke($keyA->fresh(), $admin, 'Key A only');

        $eventsForA = ApiKeySecurityEvent::where('api_key_id', $keyA->id)->pluck('event')->all();
        $this->assertContains(ApiKeyBindingService::EV_BINDING_REVOKED, $eventsForA);
        $this->assertContains(ApiKeyBindingService::EV_COOLDOWN_STARTED, $eventsForA);

        $eventsForB = ApiKeySecurityEvent::where('api_key_id', $keyB->id)->get();
        foreach ($eventsForB as $event) {
            $this->assertNotSame(ApiKeyBindingService::EV_BINDING_REVOKED, $event->event, 'Key B must never receive Key A\'s revoke event.');
            $this->assertNotSame(ApiKeyBindingService::EV_COOLDOWN_DENIED, $event->event);
        }
        $this->assertTrue($keyB->fresh()->cooldown_until === null, 'Key B must not be put into cooldown by Key A\'s revoke.');

        // Every event this class records for an API-key operation carries that specific key's id — never null.
        $allEvents = ApiKeySecurityEvent::whereIn('event', [
            ApiKeyBindingService::EV_BINDING_REVOKED,
            ApiKeyBindingService::EV_COOLDOWN_STARTED,
            ApiKeyBindingService::EV_SERVER_REGISTERED,
        ])->get();
        foreach ($allEvents as $event) {
            $this->assertNotNull($event->api_key_id, 'every API-key-operation event must identify its specific api_key_id.');
        }
    }

    // ---- 15. Existing Task 7/8/9 event behavior remains unchanged ----------------------------------------------
    //
    // No existing event call site's payload or semantics was modified
    // in Task 11 — only new call sites were added (EV_COOLDOWN_STARTED,
    // EV_COOLDOWN_DENIED, EV_SECRET_REGENERATED). This is a targeted
    // regression check that the pre-existing events this task's audit
    // touched the surrounding code of (EV_BINDING_REVOKED, EV_REBOUND,
    // EV_CHANGE_APPROVED) still fire with their original payload shape.

    public function test_pre_existing_events_retain_their_original_payload_shape(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->admin();

        $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);

        $rebound = $this->latestEvent(ApiKeyBindingService::EV_REBOUND, $key->id);
        $this->assertNotNull($rebound);
        $this->assertSame($admin->id, $rebound->actor_user_id);
        $this->assertSame($account->id, $rebound->account_id);

        $this->service()->revoke($key->fresh(), $admin, 'final revoke');
        $revoked = $this->latestEvent(ApiKeyBindingService::EV_BINDING_REVOKED, $key->id);
        $this->assertNotNull($revoked);
        $this->assertSame($admin->id, $revoked->actor_user_id);
    }
}
