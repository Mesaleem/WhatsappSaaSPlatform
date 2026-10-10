<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use App\Models\Plan;
use App\Models\Invoice;
use App\Models\Capability;
use App\Models\AccountEntitlement;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 3 — binding/installation enforcement seam.
 *
 * Audit found every binding-creating/replacing path (provision(),
 * approve(), rebind()) already converges on the single private
 * createLive() constructor, and the schema's own
 * `akb_one_live_binding_per_key` unique index already guarantees at
 * most one live row per key. This task added no new write-path
 * architecture — only ApiKeyBinding::LIVE_STATUSES (the single
 * authoritative "live" definition), the live()/forAccount() query
 * scopes, and ApiKeyBindingService::countLiveInstallations() (the
 * account-scoped read-side seam Task 6 will consume). This file proves
 * that seam against every scenario the task specifies, using the real
 * production service methods to create/revoke/rebind bindings rather
 * than hand-rolled rows, so the "existing provisioning/change paths
 * still produce the expected binding states" requirement is covered in
 * the same pass.
 *
 * Does NOT test quota enforcement, account locking, or gate() — those
 * are Task 6 / Task 7.
 */
class ApiKeyLiveInstallationCountTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    /**
     * Phase 4 Task 6 (installation-allowance enforcement) postdates this
     * file. provision()'s allowance check is per-ACCOUNT, so a test
     * provisioning more than one live/pending binding on the SAME account
     * needs a concrete limit above DEFAULT_ALLOWANCE (1) — mirrors
     * AdminVisibilityTest::accountWithAllowance().
     */
    private function grantInstallationAllowance(Account $account, int $limit): void
    {
        $capability = Capability::firstOrCreate(
            ['slug' => InstallationAllowanceResolver::CAPABILITY],
            ['label' => 'API Installations', 'category' => 'platform']
        );

        AccountEntitlement::firstOrCreate([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
        ], [
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $plan = Plan::create([
            'slug' => 'installation-allowance-'.Str::random(8),
            'label' => 'Installation Allowance Test Plan',
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

    /** 1. No bindings at all -> 0. */
    public function test_an_account_with_no_bindings_at_all_counts_zero(): void
    {
        $account = Account::factory()->create();
        $this->makeKey($account); // key exists, never bound

        $this->assertSame(0, $this->service()->countLiveInstallations($account->id));
    }

    /** "key with no binding -> zero", stated as its own case per the required test list. */
    public function test_a_key_with_no_binding_row_contributes_zero(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);

        $this->assertNull($key->liveBinding());
        $this->assertSame(0, $this->service()->countLiveInstallations($account->id));
    }

    /** 2. One PENDING binding (declared with no IPs yet) -> 1. */
    public function test_one_pending_binding_counts_one(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);

        // provision() with no authorized_ips at all and a policy that
        // needs them -> createLive() marks it PENDING (declared = false).
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => []]);

        $this->assertSame(ApiKeyBinding::STATUS_PENDING, $key->liveBinding()->status);
        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }

    /** 3. One ACTIVE binding (IP declared up front) -> 1. */
    public function test_one_active_binding_counts_one(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);

        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);

        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $key->liveBinding()->status);
        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }

    /** 4. A revoked binding is not counted. */
    public function test_a_revoked_binding_is_not_counted(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);

        $this->assertTrue($this->service()->revoke($key->fresh(), $this->admin(), 'test revoke'));

        $this->assertSame(0, $this->service()->countLiveInstallations($account->id));
        $this->assertDatabaseHas('api_key_bindings', ['api_key_id' => $key->id, 'status' => ApiKeyBinding::STATUS_REVOKED]);
    }

    /** 5/7. Multiple keys, mixed active/pending bindings -> correct account total. */
    public function test_multiple_keys_with_active_and_pending_bindings_sum_to_the_correct_account_total(): void
    {
        $account = Account::factory()->create();
        // Two keys end up live/pending on the SAME account — needs
        // allowance >= 2 (Task 6 postdates this file).
        $this->grantInstallationAllowance($account, 2);

        $active = $this->makeKey($account, 'Active key');
        $this->service()->provision($active, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);

        $pending = $this->makeKey($account, 'Pending key');
        $this->service()->provision($pending, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => []]);

        $unbound = $this->makeKey($account, 'Unbound key'); // never provisioned

        $this->assertSame(2, $this->service()->countLiveInstallations($account->id));
        $this->assertNull($unbound->liveBinding());
    }

    /**
     * 6/8. A historical revoked binding PLUS a current live binding for
     * the SAME key must be counted once, not twice. Exercised through
     * the real rebind() path (Super Admin rebind revokes the old
     * binding and creates a new live one for the same api_key_id in one
     * transaction) rather than a hand-rolled row, so this also confirms
     * rebind() still produces the expected states.
     */
    public function test_a_key_with_a_historical_revoked_binding_and_a_current_live_binding_is_counted_once(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        $admin = $this->admin();

        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.20']]);

        // Two rows now exist for this one key: one revoked, one live.
        $this->assertSame(2, ApiKeyBinding::where('api_key_id', $key->id)->count());
        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->where('status', ApiKeyBinding::STATUS_REVOKED)->count());
        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->live()->count());

        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }

    /** Same scenario again, but via approve() of a buyer-requested server change — the OTHER binding-replacement path. */
    public function test_an_approved_server_change_request_still_counts_the_key_once(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        $admin = $this->admin();
        $buyer = User::factory()->create(['account_id' => $account->id]);

        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        // requestChange() requires the live binding to already be ACTIVE;
        // provision() with a declared IP already activates it.
        $changeRequest = $this->service()->requestChange($key->fresh(), $buyer, [
            'ip_policy' => 'SINGLE_IP',
            'requested_ips' => ['203.0.113.30'],
            'reason' => 'server moved',
        ]);
        $this->service()->approve($changeRequest, $admin);

        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
        $this->assertSame(2, ApiKeyBinding::where('api_key_id', $key->id)->count());
    }

    /** 7. Another account's bindings must never be counted. */
    public function test_another_accounts_bindings_are_not_counted(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        $keyA = $this->makeKey($accountA);
        $this->service()->provision($keyA, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);

        $keyB = $this->makeKey($accountB);
        $this->service()->provision($keyB, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.11']]);

        $this->assertSame(1, $this->service()->countLiveInstallations($accountA->id));
        $this->assertSame(1, $this->service()->countLiveInstallations($accountB->id));
    }

    /**
     * 8. access_disabled is an authorization toggle on the KEY
     * (api_keys.access_disabled_at), never written to api_key_bindings
     * — confirmed by reading setAccessDisabled() and the table's own
     * migration. A disabled key's still-live binding must keep
     * occupying its slot: disabling is not the same as releasing the
     * installation.
     */
    public function test_disabling_a_keys_access_does_not_change_the_live_installation_count(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        $admin = $this->admin();
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);

        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));

        $this->service()->setAccessDisabled($key->fresh(), $admin, true, 'test disable');

        $this->assertTrue($key->fresh()->isAccessDisabled());
        $this->assertSame(
            1,
            $this->service()->countLiveInstallations($account->id),
            'access_disabled must not affect the live-installation count — it is an authorization toggle, not a binding state.'
        );
        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $key->fresh()->liveBinding()->status, 'the binding itself must be untouched by disabling the key.');
    }
}
