<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\CreditLedgerEntry;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageQuota;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\CreditEntitlementService;
use App\Services\Credits\CreditException;
use App\Services\Credits\CreditService;
use App\Services\Credits\PlanCreditAllocator;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 8 Task 2 — plan credits, allocation per billing period, AI
 * capability vs credits, backfill. Payments are fulfilled through the REAL
 * InvoiceCreditService::markPaidAndCreditQuota() (the path both the gateway
 * webhook and verify-payment use), invoices are created the way checkout
 * creates them (Invoice::capturePlanTerms()).
 */
class CreditPlanEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const PLANS = '/api/admin/plans-management';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ================================================================== fixtures

    private function setCredits(string $slug, int $credits): void
    {
        Plan::where('slug', $slug)->firstOrFail()->update(['included_credits' => $credits]);
    }

    /** An order exactly as PaymentGatewayController::createOrder() builds it. */
    private function order(Account $account, string $slug): Invoice
    {
        $plan = Plan::where('slug', $slug)->firstOrFail();
        $invoice = new Invoice([
            'account_id' => $account->id, 'invoice_number' => 'INV-T2-'.uniqid(), 'plan_key' => $slug, 'plan_label' => $plan->label,
            'amount' => $plan->price, 'tax_amount' => 0, 'total_amount' => $plan->price, 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        $invoice->capturePlanTerms($plan)->save();

        return $invoice;
    }

    private function pay(Invoice $invoice): bool
    {
        return app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
    }

    private function buy(Account $account, string $slug): Invoice
    {
        $invoice = $this->order($account, $slug);
        $this->assertTrue($this->pay($invoice));

        return $invoice->fresh();
    }

    private function balance(Account $account): array
    {
        return app(CreditService::class)->balance($account);
    }

    private function allocations(Account $account)
    {
        return CreditLedgerEntry::where('account_id', $account->id)->where('type', CreditLedgerEntry::TYPE_PLAN_ALLOCATION)->orderBy('id')->get();
    }

    private function creditStatus(Account $account): array
    {
        return app(CreditEntitlementService::class)->status($account->fresh());
    }

    private function user(?Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function aiCapability(Account $account): ?AccountEntitlement
    {
        return AccountEntitlement::where('account_id', $account->id)->where('capability_id', Capability::where('slug', 'ai')->value('id'))->first();
    }

    // ================================================================== plan configuration

    public function test_every_existing_plan_has_an_explicit_credit_allowance_matching_the_seeder_and_catalog(): void
    {
        $this->assertTrue(Schema::hasColumn('plans', 'included_credits'));

        foreach (['starter', 'growth', 'business'] as $slug) {
            $plan = Plan::where('slug', $slug)->firstOrFail();
            $this->assertSame(0, $plan->included_credits, $slug);
            $this->assertSame(PlanCatalog::find($slug)['included_credits'], $plan->included_credits, "{$slug}: catalog/seeder drift");
        }

        // Only plans that sell `ai` could ever carry credits (Starter does not).
        $this->assertFalse(Plan::where('slug', 'starter')->first()->capabilities()->where('slug', 'ai')->exists());
        $this->assertTrue(Plan::where('slug', 'growth')->first()->capabilities()->where('slug', 'ai')->exists());
        $this->assertTrue(Plan::where('slug', 'business')->first()->capabilities()->where('slug', 'ai')->exists());
    }

    public function test_reseeding_never_resets_an_allowance_an_administrator_set(): void
    {
        $this->setCredits('growth', 750);

        $this->seed(Phase1FoundationSeeder::class);

        $this->assertSame(750, Plan::where('slug', 'growth')->first()->included_credits);
    }

    public function test_the_super_admin_creates_views_and_modifies_a_plans_credit_allowance(): void
    {
        $admin = $this->user(null, 'super_admin');

        $this->actingAs($admin)->postJson(self::PLANS, [
            'slug' => 'ai-pro', 'label' => 'AI Pro', 'price' => 2999, 'duration_days' => 30, 'engine_type' => 'qr',
            'billing_model' => 'flat_quota', 'total_allocated_messages' => 3000, 'included_credits' => 1200,
            'capabilities' => ['whatsapp_send', 'ai'],
        ])->assertCreated()->assertJsonPath('data.included_credits', 1200);

        $listed = collect($this->actingAs($admin)->getJson(self::PLANS)->assertOk()->json('data'))->keyBy('slug');
        $this->assertSame([1200, 0, 0, 0], [$listed['ai-pro']['included_credits'], $listed['starter']['included_credits'], $listed['growth']['included_credits'], $listed['business']['included_credits']]);

        $this->actingAs($admin)->putJson(self::PLANS.'/ai-pro', ['included_credits' => 0])->assertOk()->assertJsonPath('data.included_credits', 0);
        $this->actingAs($admin)->putJson(self::PLANS.'/growth', ['included_credits' => 400])->assertOk()->assertJsonPath('data.included_credits', 400);
        $this->assertSame(400, Plan::where('slug', 'growth')->first()->included_credits);

        // A zero-credit plan without `ai` is fine.
        $this->actingAs($admin)->postJson(self::PLANS, [
            'slug' => 'messages-only', 'label' => 'Messages', 'price' => 99, 'duration_days' => 30, 'engine_type' => 'qr',
            'billing_model' => 'flat_quota', 'total_allocated_messages' => 100, 'capabilities' => ['whatsapp_send'],
        ])->assertCreated()->assertJsonPath('data.included_credits', 0);
    }

    public function test_credits_cannot_be_put_on_a_plan_that_does_not_sell_ai(): void
    {
        $admin = $this->user(null, 'super_admin');

        $this->actingAs($admin)->putJson(self::PLANS.'/starter', ['included_credits' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('included_credits');
        $this->actingAs($admin)->postJson(self::PLANS, [
            'slug' => 'bad', 'label' => 'Bad', 'price' => 1, 'duration_days' => 30, 'engine_type' => 'qr', 'billing_model' => 'flat_quota',
            'included_credits' => 5, 'capabilities' => ['whatsapp_send'],
        ])->assertStatus(422)->assertJsonValidationErrors('included_credits');

        // Removing `ai` from a plan that includes credits is refused too.
        $this->setCredits('growth', 300);
        $bundle = array_values(array_diff(Plan::where('slug', 'growth')->first()->capabilities()->pluck('slug')->all(), ['ai']));
        $this->actingAs($admin)->putJson(self::PLANS.'/growth', ['capabilities' => $bundle])->assertStatus(422);

        foreach ([-1, 1_000_000_001, 'many'] as $bad) {
            $this->actingAs($admin)->putJson(self::PLANS.'/growth', ['included_credits' => $bad])->assertStatus(422);
        }

        $this->assertSame([0, 300], [Plan::where('slug', 'starter')->first()->included_credits, Plan::where('slug', 'growth')->first()->included_credits]);
        $this->assertFalse(Plan::where('slug', 'bad')->exists());
    }

    public function test_only_the_super_admin_manages_plan_credits(): void
    {
        $agent = Account::factory()->agent()->create();
        $agentUser = $this->user($agent);
        $agentUser->assignRole('agent');

        foreach ([$agentUser, $this->user(Account::factory()->create())] as $user) {
            $this->actingAs($user)->putJson(self::PLANS.'/growth', ['included_credits' => 999])->assertForbidden();
            $this->actingAs($user)->getJson(self::PLANS)->assertForbidden();
        }

        $this->assertSame(0, Plan::where('slug', 'growth')->first()->included_credits);
    }

    // ================================================================== subscription lifecycle

    public function test_activation_allocates_the_captured_credits_for_the_purchased_period(): void
    {
        $this->setCredits('growth', 300);
        $account = Account::factory()->create();

        $invoice = $this->buy($account, 'growth');
        $subscription = $account->fresh()->currentSubscription;

        $this->assertSame(['balance' => 300, 'reserved' => 0, 'available' => 300], $this->balance($account));
        $entry = $this->allocations($account)->sole();
        $this->assertSame(
            ['plan_allocation', 300, 'invoice', (string) $invoice->id, "plan-allocation:{$subscription->id}:{$invoice->id}", 'system', null],
            [$entry->type, $entry->amount, $entry->reference_type, $entry->reference_id, $entry->idempotency_key, $entry->source, $entry->actor_user_id]
        );
        $this->assertSame(['growth', $subscription->id, $invoice->id], [$entry->metadata['plan'], $entry->metadata['subscription_id'], $entry->metadata['invoice_id']]);

        $period = UsageQuota::where('credit_ledger_entry_id', $entry->id)->sole();
        $this->assertSame([$account->id, 300, 0, 'plan_allocation', $subscription->id, $invoice->id], [$period->account_id, $period->allocated, $period->used, $period->source, $period->subscription_id, $period->invoice_id]);
        $this->assertSame(Capability::where('slug', 'ai')->value('id'), $period->capability_id);
        $this->assertTrue($period->period_ends_at->equalTo($subscription->expires_at));
        $this->assertSame(30, (int) round($period->period_starts_at->diffInDays($period->period_ends_at)));
    }

    public function test_the_order_keeps_the_credits_it_was_created_with(): void
    {
        $this->setCredits('growth', 300);
        $account = Account::factory()->create();
        $invoice = $this->order($account, 'growth');

        $this->setCredits('growth', 5000); // changed between order and payment
        $this->assertTrue($this->pay($invoice));

        $this->assertSame(300, $this->balance($account)['balance']);
    }

    public function test_a_zero_credit_plan_allocates_and_records_nothing(): void
    {
        $account = Account::factory()->create();

        $this->buy($account, 'growth'); // growth = 0 (seeded)
        $this->buy($account, 'starter');

        $this->assertSame(0, $this->balance($account)['balance']);
        $this->assertSame(0, CreditLedgerEntry::count());
        $this->assertSame(0, UsageQuota::count());
    }

    public function test_renewal_allocates_the_next_period_once_and_stacks_after_the_current_one(): void
    {
        $this->setCredits('growth', 300);
        $account = Account::factory()->create();

        $this->buy($account, 'growth');
        $firstEnd = $account->fresh()->currentSubscription->expires_at->copy();
        $this->travel(10)->days();
        $this->buy($account, 'growth');

        $periods = UsageQuota::where('account_id', $account->id)->orderBy('period_starts_at')->get();
        $this->assertCount(2, $periods);
        $this->assertTrue($periods[1]->period_starts_at->equalTo($firstEnd), 'the renewal period starts where the running one ends');
        $this->assertSame(600, $this->balance($account)['balance']);
        $this->assertSame(1, Subscription::where('account_id', $account->id)->count());
        $this->assertSame(300, app(PlanCreditAllocator::class)->currentPeriod($account)->allocated, 'today is still in the first period');
    }

    public function test_upgrade_and_downgrade_add_the_new_plans_allowance_and_never_remove_credits(): void
    {
        $this->setCredits('growth', 300);
        $this->setCredits('business', 2000);
        $account = Account::factory()->create();
        app(CreditService::class)->grant($account, 50, 'manual:welcome', ['reason' => 'manual']);

        $this->buy($account, 'growth');
        $this->buy($account, 'business'); // upgrade
        $this->assertSame(2350, $this->balance($account)['balance']);

        $this->buy($account, 'starter'); // downgrade to a plan without ai / credits
        $this->assertSame(2350, $this->balance($account)['balance'], 'a downgrade takes nothing back');
        $this->assertSame([300, 2000], $this->allocations($account)->pluck('amount')->all());
        $this->assertSame(1, CreditLedgerEntry::where('account_id', $account->id)->where('type', 'grant')->count(), 'the manual grant is untouched');

        // The plan reconciliation revoked `ai` (Starter does not sell it): credits without capability.
        $status = $this->creditStatus($account);
        $this->assertSame([false, 2350, false, 'ai_capability_missing'], [$status['ai_capability'], $status['available'], $status['can_use_ai_credits'], $status['reason']]);
    }

    public function test_expiry_keeps_credits_and_reactivation_allocates_a_fresh_period_from_now(): void
    {
        $this->setCredits('growth', 300);
        $account = Account::factory()->create();
        $this->buy($account, 'growth');

        $this->travel(45)->days();
        $expired = $this->creditStatus($account);
        $this->assertSame([300, false, 'subscription_inactive'], [$expired['available'], $expired['can_use_ai_credits'], $expired['reason']]);
        $this->assertNull(app(PlanCreditAllocator::class)->currentPeriod($account));

        $this->buy($account, 'growth'); // reactivation after the lapse
        $period = app(PlanCreditAllocator::class)->currentPeriod($account);
        $this->assertNotNull($period);
        $this->assertTrue($period->period_starts_at->diffInSeconds(now()) < 5, 'a lapsed subscription starts its new period now');
        $this->assertSame(600, $this->balance($account)['balance']);
        $this->assertTrue($this->creditStatus($account)['can_use_ai_credits']);
    }

    public function test_a_suspended_account_keeps_its_credits_but_cannot_use_them(): void
    {
        $this->setCredits('growth', 300);
        $account = Account::factory()->create();
        $this->buy($account, 'growth');

        $account->update(['status' => 'suspended']);
        $status = $this->creditStatus($account);

        $this->assertSame([300, false, 'account_suspended'], [$status['available'], $status['can_use_ai_credits'], $status['reason']]);
        $this->assertSame(1, CreditLedgerEntry::where('account_id', $account->id)->count());
    }

    public function test_an_exhausted_message_quota_does_not_switch_ai_credits_off(): void
    {
        $this->setCredits('growth', 300);
        $account = Account::factory()->create();
        $this->buy($account, 'growth');
        Subscription::where('account_id', $account->id)->update(['used_messages' => 999999]);

        $this->assertTrue($this->creditStatus($account)['can_use_ai_credits']);
    }

    // ================================================================== allocation idempotency

    public function test_a_duplicate_payment_callback_allocates_once(): void
    {
        $this->setCredits('growth', 300);
        $account = Account::factory()->create();
        $invoice = $this->order($account, 'growth');

        $this->assertTrue($this->pay($invoice));
        $this->assertFalse($this->pay($invoice)); // webhook after verify-payment
        $this->assertFalse($this->pay($invoice));

        $this->assertSame(300, $this->balance($account)['balance']);
        $this->assertCount(1, $this->allocations($account));
        $this->assertSame(1, UsageQuota::count());
    }

    public function test_repeating_an_allocation_replays_and_a_different_amount_for_the_same_period_is_refused(): void
    {
        $this->setCredits('growth', 300);
        $account = Account::factory()->create();
        $invoice = $this->buy($account, 'growth');
        $subscription = $account->fresh()->currentSubscription;
        $allocator = app(PlanCreditAllocator::class);

        $again = $allocator->allocate($account, $subscription, $invoice, 300, now(), now()->addDays(30), 'retry');
        $this->assertTrue($again['replayed']);
        $this->assertSame(1, UsageQuota::count());

        try {
            $allocator->allocate($account, $subscription, $invoice, 999, now(), now()->addDays(30), 'retry');
            $this->fail('a second, different allocation for the same period was accepted');
        } catch (CreditException $e) {
            $this->assertSame(CreditException::IDEMPOTENCY_CONFLICT, $e->reason);
        }

        $this->assertSame(300, $this->balance($account)['balance']);
    }

    public function test_a_failed_fulfilment_allocates_nothing_and_its_retry_allocates_once(): void
    {
        $this->setCredits('growth', 300);
        $account = Account::factory()->create();
        $invoice = $this->order($account, 'growth');

        $fail = true;
        UsageQuota::creating(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('simulated crash mid-fulfilment');
            }
        });

        try {
            $this->pay($invoice);
            $this->fail('the simulated crash did not happen');
        } catch (RuntimeException) {
        }

        $this->assertSame(['pending', 0, 0], [$invoice->fresh()->status, $this->balance($account)['balance'], CreditLedgerEntry::count()], 'the payment, the subscription and the allocation rolled back together');

        $fail = false;
        $this->assertTrue($this->pay($invoice));
        $this->assertSame(300, $this->balance($account)['balance']);
        $this->assertCount(1, $this->allocations($account));
        UsageQuota::flushEventListeners();
    }

    public function test_an_allocation_can_only_go_to_the_account_that_paid(): void
    {
        $this->setCredits('growth', 300);
        $payer = Account::factory()->create();
        $other = Account::factory()->create();
        $invoice = $this->buy($payer, 'growth');

        $this->expectException(CreditException::class);
        app(PlanCreditAllocator::class)->allocate($other, $payer->fresh()->currentSubscription, $invoice, 300, now(), now()->addDays(30));
    }

    // ================================================================== entitlement

    public function test_ai_capability_and_credits_are_two_separate_checks(): void
    {
        $this->setCredits('growth', 300);
        $entitlement = app(CreditEntitlementService::class);

        // AI capability + credits.
        $both = Account::factory()->create();
        $this->buy($both, 'growth');
        $this->assertTrue($entitlement->usable($both->fresh(), 300));
        $this->assertFalse($entitlement->usable($both->fresh(), 301));

        // AI capability without credits (a zero-credit Business plan today).
        $capabilityOnly = Account::factory()->create();
        $this->buy($capabilityOnly, 'business');
        $s = $this->creditStatus($capabilityOnly);
        $this->assertSame([true, 0, false, 'no_available_credits'], [$s['ai_capability'], $s['available'], $s['can_use_ai_credits'], $s['reason']]);

        // Credits without AI capability (manual grant on Starter).
        $creditsOnly = Account::factory()->create();
        $this->buy($creditsOnly, 'starter');
        app(CreditService::class)->grant($creditsOnly, 500, 'manual:gift');
        $s = $this->creditStatus($creditsOnly);
        $this->assertSame([false, 500, false, 'ai_capability_missing'], [$s['ai_capability'], $s['available'], $s['can_use_ai_credits'], $s['reason']]);

        // Revocation, then restoration, of the capability — credits unchanged throughout.
        $this->aiCapability($both)->update(['revoked_at' => now()]);
        $this->assertSame([false, 300], [$this->creditStatus($both)['can_use_ai_credits'], $this->creditStatus($both)['available']]);
        $this->aiCapability($both)->update(['revoked_at' => null]);
        $this->assertTrue($this->creditStatus($both)['can_use_ai_credits']);
    }

    // ================================================================== tenant / RBAC

    public function test_credits_belong_to_the_paying_client_never_to_its_agent(): void
    {
        $this->setCredits('growth', 300);
        $agent = Account::factory()->agent()->create();
        $client = Account::factory()->client($agent)->create();

        $this->buy($client, 'growth');
        $this->buy($agent, 'growth');

        $this->assertSame([300, 300], [$this->balance($client)['balance'], $this->balance($agent)['balance']]);
        $this->assertSame([$client->id], $this->allocations($client)->pluck('account_id')->unique()->values()->all());
        $this->assertSame(1, UsageQuota::where('account_id', $client->id)->count());
        $this->assertSame(1, UsageQuota::where('account_id', $agent->id)->count(), 'no pooling: each has its own period');
    }

    public function test_the_credit_summary_shows_plan_context_to_exactly_the_right_callers(): void
    {
        $this->setCredits('growth', 300);
        $agent = Account::factory()->agent()->create();
        $agentUser = $this->user($agent);
        $agentUser->assignRole('agent');
        $client = Account::factory()->client($agent)->create();
        $stranger = Account::factory()->create();
        $this->buy($client, 'growth');
        $this->buy($stranger, 'growth');
        app(CreditService::class)->grant($stranger, 77, 'manual:stranger');
        $clientUser = $this->user($client);

        // The client: its own summary, forged account ids ignored.
        $mine = $this->actingAs($clientUser)->getJson("/api/billing/credits?account_id={$stranger->id}")->assertOk();
        $mine->assertJsonPath('data.account_id', $client->id)->assertJsonPath('data.available', 300)
            ->assertJsonPath('data.plan.slug', 'growth')->assertJsonPath('data.plan.included_credits', 300)
            ->assertJsonPath('data.current_period.allocated', 300)->assertJsonPath('data.ai_capability', true)
            ->assertJsonPath('data.can_use_ai_credits', true)->assertJsonPath('data.subscription.status', 'active');
        $this->assertArrayNotHasKey('idempotency_key', $mine->json('data'));

        // The Agent: its own account, and its sub-client — never a stranger.
        $this->actingAs($agentUser)->getJson('/api/billing/credits')->assertOk()->assertJsonPath('data.account_id', $agent->id)->assertJsonPath('data.plan', null);
        $this->actingAs($agentUser)->getJson("/api/billing/credits?account_id={$client->id}")->assertOk()->assertJsonPath('data.available', 300);
        $this->actingAs($agentUser)->getJson("/api/billing/credits?account_id={$stranger->id}")->assertNotFound();
        $this->actingAs($agentUser)->getJson("/api/admin/accounts/{$stranger->id}/credits")->assertNotFound();

        // The Super Admin: the selected client.
        $this->actingAs($this->user(null, 'super_admin'))->getJson("/api/admin/accounts/{$stranger->id}/credits")->assertOk()
            ->assertJsonPath('data.available', 377)->assertJsonPath('data.plan.slug', 'growth');
        $this->actingAs($this->user(null, 'super_admin'))->getJson("/api/billing/credits?account_id={$client->id}")->assertOk()->assertJsonPath('data.account_id', $client->id);
    }

    // ================================================================== backfill

    public function test_the_backfill_is_dry_runnable_additive_and_repeat_safe(): void
    {
        // Existing subscribers who paid before any plan had credits.
        $a = Account::factory()->create();
        $this->buy($a, 'growth');
        $b = Account::factory()->create();
        $this->buy($b, 'business');
        app(CreditService::class)->grant($b, 40, 'manual:b');
        $starter = Account::factory()->create();
        $this->buy($starter, 'starter');
        $expired = Account::factory()->create();
        $this->buy($expired, 'growth');
        Subscription::where('account_id', $expired->id)->update(['expires_at' => now()->subDay()]);
        $suspended = Account::factory()->create(['status' => 'suspended']);
        $this->buy($suspended, 'growth');
        $noInvoice = Account::factory()->create();

        $this->setCredits('growth', 300);
        $this->setCredits('business', 2000);

        $this->artisan('credits:backfill-plan-allocation', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('DRY RUN');
        $this->assertSame(1, CreditLedgerEntry::count(), 'a dry run writes nothing');

        $this->artisan('credits:backfill-plan-allocation')->assertSuccessful();
        $this->assertSame([300, 2040, 0, 0, 0, 0], collect([$a, $b, $starter, $expired, $suspended, $noInvoice])->map(fn ($x) => $this->balance($x)['balance'])->all());
        $this->assertSame(1, CreditLedgerEntry::where('account_id', $b->id)->where('type', 'grant')->count(), 'manual credits preserved');
        $this->assertSame('backfill', $this->allocations($a)->sole()->metadata['origin']);

        $this->artisan('credits:backfill-plan-allocation')->assertSuccessful();
        $this->assertSame([300, 2040], [$this->balance($a)['balance'], $this->balance($b)['balance']], 'a second run grants nothing');
        $this->assertSame(2, UsageQuota::count());

        // A period the live payment path allocated is never allocated again by the backfill.
        $c = Account::factory()->create();
        $this->buy($c, 'growth');
        $this->artisan('credits:backfill-plan-allocation', ['--account' => $c->id])->assertSuccessful();
        $this->assertCount(1, $this->allocations($c));
        $this->assertSame(300, $this->balance($c)['balance']);
    }
}
