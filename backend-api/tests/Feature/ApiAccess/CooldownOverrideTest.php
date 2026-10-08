<?php

namespace Tests\Feature\ApiAccess;

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
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 4 Task 9 — overrideCooldown(). Covers required tests 12-16 and
 * 18 from the finalized spec.
 *
 * The service-layer tests (12-16) call ApiKeyBindingService::overrideCooldown()
 * directly, the same way TransferAtomicityTest.php exercises the
 * service layer under its own authenticated-as-admin assumption — this
 * method itself enforces no role (see its own docblock: authorization
 * is the role:super_admin route middleware's job, exactly like
 * revoke()/rebind()/approve()/reject()). Test 18 ("normal users cannot
 * invoke the override") is therefore necessarily an HTTP-level test
 * against the actual route + its role:super_admin middleware, not a
 * service-layer call.
 */
class CooldownOverrideTest extends TestCase
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

    private function capability(): Capability
    {
        return Capability::firstOrCreate(
            ['slug' => InstallationAllowanceResolver::CAPABILITY],
            ['label' => 'API Installations', 'category' => 'platform']
        );
    }

    private function grantAllowance(Account $account, int $limit): void
    {
        $capability = $this->capability();

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $plan = Plan::create([
            'slug' => 'task9-override-plan-'.Str::random(8),
            'label' => 'Task 9 Override Test Plan',
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

    private function accountWithAllowance(int $limit = 5): Account
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, $limit);

        return $account;
    }

    // ---- 12. Super Admin can explicitly shorten a cooldown ---------------------------------------------------------

    public function test_super_admin_can_shorten_a_cooldown(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account);
        $this->service()->setCooldown($key, now()->addDays(14));

        $shortUntil = now()->addDays(2);
        $this->service()->overrideCooldown($key->fresh(), $shortUntil, $this->admin(), 'customer escalation, approved early reconnect');

        $fresh = $key->fresh();
        $this->assertTrue($fresh->cooldown_until->equalTo($shortUntil));
        $this->assertTrue($fresh->isInCooldown());
    }

    // ---- 13. Super Admin can explicitly clear a cooldown -----------------------------------------------------------

    public function test_super_admin_can_explicitly_clear_a_cooldown(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account);
        $this->service()->setCooldown($key, now()->addDays(14));

        // A non-future $until clears it outright (stored as NULL, same
        // as clearCooldown()) — the spec's own overrideCooldown()
        // signature takes a non-nullable DateTimeInterface, so "clear"
        // is expressed this way rather than by a null argument.
        $this->service()->overrideCooldown($key->fresh(), now(), $this->admin(), 'customer escalation, cleared entirely');

        $fresh = $key->fresh();
        $this->assertNull($fresh->cooldown_until);
        $this->assertFalse($fresh->isInCooldown());
    }

    // ---- 14. Override requires a reason --------------------------------------------------------------------------

    public function test_override_requires_a_non_empty_reason(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account);
        $this->service()->setCooldown($key, now()->addDays(14));

        $this->expectException(InvalidArgumentException::class);
        $this->service()->overrideCooldown($key->fresh(), now(), $this->admin(), '   ');
    }

    // ---- 15. Override emits EV_COOLDOWN_OVERRIDDEN ----------------------------------------------------------------

    public function test_override_emits_cooldown_overridden_event_with_the_required_payload(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account);
        $old = now()->addDays(14);
        $this->service()->setCooldown($key, $old);
        $admin = $this->admin();
        $new = now()->addDays(3);

        $this->service()->overrideCooldown($key->fresh(), $new, $admin, 'shortened per support ticket #4821');

        $event = ApiKeySecurityEvent::where('api_key_id', $key->id)
            ->where('event', ApiKeyBindingService::EV_COOLDOWN_OVERRIDDEN)
            ->latest('id')->first();

        $this->assertNotNull($event, 'EV_COOLDOWN_OVERRIDDEN must be recorded.');
        $this->assertSame($key->id, $event->api_key_id);
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame('shortened per support ticket #4821', $event->context['reason']);
        $this->assertNotNull($event->context['old_cooldown_until']);
        $this->assertNotNull($event->context['cooldown_until']);
    }

    // ---- 16. Override affects only the selected API key ------------------------------------------------------------

    public function test_override_affects_only_the_selected_key(): void
    {
        $account = $this->accountWithAllowance();
        $keyA = $this->makeKey($account, 'Key A');
        $keyB = $this->makeKey($account, 'Key B');
        $this->service()->setCooldown($keyA, now()->addDays(14));
        $this->service()->setCooldown($keyB, now()->addDays(14));

        $this->service()->overrideCooldown($keyA->fresh(), now(), $this->admin(), 'Key A only, clearing for a support case');

        $this->assertNull(ApiKey::find($keyA->id)->cooldown_until);
        $this->assertNotNull(ApiKey::find($keyB->id)->cooldown_until, "Key B's cooldown must be untouched by Key A's override.");
    }

    // ---- 18. Normal/non-Super-Admin users cannot invoke the override (route-level, role:super_admin) -----------------

    public function test_a_non_super_admin_cannot_call_the_override_route(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account);
        $this->service()->setCooldown($key, now()->addDays(14));

        // A plain authenticated user with no super_admin role assigned
        // (same convention as AccountEntitlementTest's
        // test_a_non_super_admin_cannot_grant_entitlements).
        $buyer = User::factory()->create(['account_id' => $account->id]);

        $response = $this->actingAs($buyer)->postJson("/api/admin/api-access/{$key->id}/cooldown", [
            'reason' => 'trying to self-clear my own cooldown',
        ]);

        $response->assertForbidden();
        $this->assertNotNull(ApiKey::find($key->id)->cooldown_until, 'A non-Super-Admin request must never change the stored cooldown.');
    }
}
