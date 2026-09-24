<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AgentCommission;
use App\Models\AgentCommissionRule;
use App\Models\Invoice;
use App\Models\PaymentGatewaySetting;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 5 fix P5-4 — an order is fulfilled with the plan terms that were
 * in force when it was CREATED, captured on the invoice from the database
 * plan, not with whatever the plan says at payment time.
 *
 * Driven end to end through the real routes: POST /api/billing/create-order
 * (Razorpay order API faked at the HTTP boundary), plan changes through the
 * Super Admin plan-management API, and payment through the signed Razorpay
 * webhook or POST /api/billing/verify-payment.
 */
class InvoicePlanTermsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'rzp_test_secret_p54';

    private const WEBHOOK_SECRET = 'rzp_webhook_secret_p54';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        PaymentGatewaySetting::create([
            'gateway' => 'razorpay',
            'mode' => 'test',
            'is_enabled' => true,
            'test_key_id' => 'rzp_test_key_p54',
            'test_key_secret' => self::KEY_SECRET,
            'test_webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        Http::fake([
            'api.razorpay.com/v1/orders' => fn () => Http::response(['id' => 'order_'.uniqid(), 'status' => 'created'], 200),
        ]);
    }

    // ------------------------------------------------------------------ fixtures

    /** @return array{account: Account, user: User} */
    private function tenant(array $accountAttributes = []): array
    {
        $account = Account::factory()->create($accountAttributes);
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account, 'user' => $user];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function order(User $user, string $planKey, array $extra = []): Invoice
    {
        $response = $this->actingAs($user)->postJson('/api/billing/create-order', ['plan_key' => $planKey, 'gateway' => 'razorpay'] + $extra);
        $response->assertCreated();

        return Invoice::findOrFail($response->json('invoice_id'));
    }

    private function editPlan(string $slug, array $changes): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson("/api/admin/plans-management/{$slug}", $changes)
            ->assertOk();
    }

    private function payByWebhook(Invoice $invoice, string $paymentId = 'pay_p54'): void
    {
        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => $paymentId, 'order_id' => $invoice->gateway_order_id, 'status' => 'captured']]],
        ]);

        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body)->assertOk();
    }

    private function payByVerify(User $user, Invoice $invoice, string $paymentId = 'pay_p54_verify')
    {
        return $this->actingAs($user)->postJson('/api/billing/verify-payment', [
            'invoice_id' => $invoice->id,
            'razorpay_order_id' => $invoice->gateway_order_id,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => hash_hmac('sha256', "{$invoice->gateway_order_id}|{$paymentId}", self::KEY_SECRET),
        ]);
    }

    /** @return array{engine_type: string, billing_model: string, rate_per_message: mixed, total_allocated_messages: ?int, duration_days: int} */
    private function termsOf(string $slug): array
    {
        $plan = Plan::where('slug', $slug)->firstOrFail();

        return [
            'engine_type' => $plan->engine_type,
            'billing_model' => $plan->billing_model,
            'rate_per_message' => $plan->rate_per_message,
            'total_allocated_messages' => $plan->total_allocated_messages,
            'duration_days' => (int) $plan->duration_days,
        ];
    }

    /** @return array<int, string> */
    private function heldBy(Account $account): array
    {
        return AccountEntitlement::where('account_id', $account->id)->active()->with('capability')->get()
            ->pluck('capability.slug')->sort()->values()->all();
    }

    public static function paymentPaths(): array
    {
        return ['webhook' => ['webhook'], 'verify-payment' => ['verify']];
    }

    private function pay(string $path, User $user, Invoice $invoice): void
    {
        if ($path === 'webhook') {
            $this->payByWebhook($invoice);

            return;
        }

        $this->payByVerify($user, $invoice)->assertOk();
    }

    // ==================================================================
    // Capture at order time
    // ==================================================================

    public function test_create_order_captures_the_database_plan_terms_on_the_invoice(): void
    {
        ['user' => $user] = $this->tenant();

        $invoice = $this->order($user, 'growth');

        $this->assertNotNull($invoice->plan_terms_captured_at);
        $this->assertSame($this->termsOf('growth'), $invoice->purchasedPlanTerms());
        $this->assertSame((float) Plan::where('slug', 'growth')->value('price'), (float) $invoice->amount);
    }

    // ==================================================================
    // A. Plan changed after the order
    // ==================================================================

    #[DataProvider('paymentPaths')]
    public function test_a_plan_changed_after_the_order_is_fulfilled_with_the_ordered_terms(string $path): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant();
        $ordered = $this->termsOf('growth');
        $this->assertSame(['qr', 'flat_quota', 2500, 30], [$ordered['engine_type'], $ordered['billing_model'], $ordered['total_allocated_messages'], $ordered['duration_days']]);

        $invoice = $this->order($user, 'growth');

        // Day 2: the admin changes every fulfilment term of the plan.
        $this->editPlan('growth', ['engine_type' => 'meta', 'total_allocated_messages' => 777, 'duration_days' => 15, 'price' => 1]);
        $this->assertSame(['meta', 777, 15], [Plan::where('slug', 'growth')->value('engine_type'), (int) Plan::where('slug', 'growth')->value('total_allocated_messages'), (int) Plan::where('slug', 'growth')->value('duration_days')]);

        $this->travelTo(now()->startOfMinute());
        $this->pay($path, $user, $invoice);

        $subscription = $account->fresh()->currentSubscription;
        $this->assertSame('qr', $subscription->engine_type, 'ordered engine, not the new one');
        $this->assertSame('flat_quota', $subscription->billing_model);
        $this->assertSame(2500, (int) $subscription->total_allocated_messages, 'ordered quota, not 777');
        $this->assertSame(now()->addDays(30)->toDateTimeString(), $subscription->expires_at->toDateTimeString(), 'ordered 30 days, not 15');
        $this->assertSame((float) $invoice->total_amount, (float) $subscription->price_paid, 'the price the order was created at');
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_billing_model_and_rate_are_the_ordered_ones_too(): void
    {
        $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'metered', 'label' => 'Metered', 'price' => 1000, 'duration_days' => 60,
            'engine_type' => 'qr', 'billing_model' => 'per_message', 'rate_per_message' => 0.25,
            'total_allocated_messages' => 4000, 'capabilities' => ['whatsapp_send'],
        ])->assertCreated();
        ['account' => $account, 'user' => $user] = $this->tenant();

        $invoice = $this->order($user, 'metered');
        $this->editPlan('metered', ['billing_model' => 'unlimited', 'rate_per_message' => 9, 'total_allocated_messages' => null, 'duration_days' => 5]);
        $this->payByWebhook($invoice);

        $subscription = $account->fresh()->currentSubscription;
        $this->assertSame('per_message', $subscription->billing_model);
        $this->assertSame(0.25, (float) $subscription->rate_per_message);
        $this->assertSame(4000, (int) $subscription->total_allocated_messages, 'not reset to unlimited');
        $this->assertSame(60, (int) round(now()->diffInDays($subscription->expires_at)));
    }

    public function test_an_unlimited_order_stays_unlimited_after_the_plan_becomes_capped(): void
    {
        $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'infinite', 'label' => 'Infinite', 'price' => 5000, 'duration_days' => 30,
            'engine_type' => 'qr', 'billing_model' => 'unlimited', 'capabilities' => ['whatsapp_send'],
        ])->assertCreated();
        ['account' => $account, 'user' => $user] = $this->tenant();

        $invoice = $this->order($user, 'infinite');
        $this->assertNull($invoice->plan_total_allocated_messages);
        $this->editPlan('infinite', ['billing_model' => 'flat_quota', 'total_allocated_messages' => 10]);
        $this->payByWebhook($invoice);

        $subscription = $account->fresh()->currentSubscription;
        $this->assertSame('unlimited', $subscription->billing_model);
        $this->assertNull($subscription->total_allocated_messages);
    }

    public function test_a_new_order_after_the_change_gets_the_new_terms(): void
    {
        ['user' => $user] = $this->tenant();

        $this->editPlan('growth', ['total_allocated_messages' => 777, 'duration_days' => 15]);
        $invoice = $this->order($user, 'growth');

        $this->assertSame(777, $invoice->plan_total_allocated_messages);
        $this->assertSame(15, $invoice->plan_duration_days);
    }

    // ==================================================================
    // B / C. Deactivated / retired plan
    // ==================================================================

    #[DataProvider('paymentPaths')]
    public function test_an_order_for_a_plan_deactivated_afterwards_is_still_fulfilled(string $path): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'starter');

        $this->editPlan('starter', ['is_active' => false]);
        $this->actingAs($user)->postJson('/api/billing/create-order', ['plan_key' => 'starter', 'gateway' => 'razorpay'])->assertStatus(422);

        $this->pay($path, $user, $invoice);

        $subscription = $account->fresh()->currentSubscription;
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(500, (int) $subscription->total_allocated_messages);
        $this->assertSame('qr', $subscription->engine_type);
    }

    public function test_an_order_for_a_plan_retired_and_modified_afterwards_uses_the_captured_terms(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'business');
        $ordered = $this->termsOf('business');

        $this->editPlan('business', ['is_active' => false, 'engine_type' => 'qr', 'total_allocated_messages' => 1, 'duration_days' => 1]);
        $this->payByWebhook($invoice);

        $subscription = $account->fresh()->currentSubscription;
        $this->assertSame($ordered['engine_type'], $subscription->engine_type);
        $this->assertSame('meta', $subscription->engine_type);
        $this->assertSame($ordered['total_allocated_messages'], (int) $subscription->total_allocated_messages);
        $this->assertSame(30, (int) round(now()->diffInDays($subscription->expires_at)));
    }

    public function test_captured_terms_fulfil_even_without_the_plan_row(): void
    {
        // Plan deletion is not an application feature; this proves only that
        // fulfilment of a captured order no longer depends on the plan row.
        ['account' => $account, 'user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'starter');
        Plan::where('slug', 'starter')->delete();

        $this->payByWebhook($invoice);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(500, (int) $account->fresh()->currentSubscription->total_allocated_messages);
    }

    // ==================================================================
    // Entitlement reconciliation still receives the purchase
    // ==================================================================

    public function test_reconciliation_runs_on_the_ordered_engine(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'growth');

        // Moving the plan to meta must not make the purchase a meta purchase:
        // whatsapp_groups (qr-only) must still be granted for a qr order.
        $this->editPlan('growth', ['engine_type' => 'meta']);
        $this->payByWebhook($invoice);

        $this->assertSame('qr', $account->fresh()->currentSubscription->engine_type);
        $this->assertContains('whatsapp_groups', $this->heldBy($account->fresh()));
        $this->assertContains('crm', $this->heldBy($account->fresh()));
    }

    // ==================================================================
    // D. Tampering
    // ==================================================================

    public function test_forged_commercial_fields_in_the_order_request_are_ignored(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant();

        $invoice = $this->order($user, 'starter', [
            'amount' => 1, 'price' => 1, 'total_amount' => 1,
            'total_allocated_messages' => 999999, 'quota' => 999999,
            'engine_type' => 'meta', 'plan_engine_type' => 'meta',
            'duration_days' => 3650, 'plan_duration_days' => 3650,
            'billing_model' => 'unlimited', 'plan_billing_model' => 'unlimited',
            'rate_per_message' => 0, 'plan_rate_per_message' => 0,
            'plan_total_allocated_messages' => 999999, 'plan_terms_captured_at' => '2000-01-01',
            'capabilities' => ['ads', 'commerce'], 'account_id' => 999,
        ]);

        $this->assertSame($this->termsOf('starter'), $invoice->purchasedPlanTerms());
        $this->assertSame((float) Plan::where('slug', 'starter')->value('price'), (float) $invoice->amount);
        $this->assertSame($account->id, $invoice->account_id);

        $this->payByWebhook($invoice);
        $subscription = $account->fresh()->currentSubscription;
        $this->assertSame([500, 'qr', 'flat_quota'], [(int) $subscription->total_allocated_messages, $subscription->engine_type, $subscription->billing_model]);
        $this->assertNotContains('ads', $this->heldBy($account->fresh()));
    }

    public function test_snapshot_columns_cannot_be_mass_assigned(): void
    {
        ['account' => $account] = $this->tenant();

        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-X', 'plan_key' => 'starter', 'plan_label' => 'Starter',
            'amount' => 1, 'tax_amount' => 0, 'total_amount' => 1, 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'status' => 'pending',
            'plan_engine_type' => 'meta', 'plan_total_allocated_messages' => 999999, 'plan_duration_days' => 3650,
            'plan_billing_model' => 'unlimited', 'plan_terms_captured_at' => now(),
        ]);

        $fresh = $invoice->fresh();
        $this->assertNull($fresh->plan_terms_captured_at);
        $this->assertNull($fresh->plan_engine_type);
        $this->assertNull($fresh->purchasedPlanTerms());
    }

    public function test_captured_terms_are_immutable_after_creation(): void
    {
        ['user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'starter');

        $this->expectException(LogicException::class);
        $invoice->forceFill(['plan_total_allocated_messages' => 999999])->save();
    }

    public function test_terms_cannot_be_captured_onto_an_existing_invoice(): void
    {
        ['user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'starter');

        $this->expectException(LogicException::class);
        $invoice->capturePlanTerms(Plan::where('slug', 'business')->firstOrFail());
    }

    public function test_ordinary_invoice_updates_still_work_after_capture(): void
    {
        ['user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'starter');

        // What createOrder / fulfilment themselves write afterwards.
        $invoice->forceFill(['gateway_raw_response' => ['x' => 1], 'status' => 'pending'])->save();

        $this->assertSame($this->termsOf('starter'), $invoice->fresh()->purchasedPlanTerms());
    }

    public function test_the_snapshot_is_not_exposed_in_invoice_responses(): void
    {
        ['user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'starter');

        $response = $this->payByVerify($user, $invoice)->assertOk();

        foreach (Invoice::PLAN_TERM_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $response->json('invoice'));
        }
    }

    // ==================================================================
    // E. Duplicate payment
    // ==================================================================

    public function test_a_duplicate_payment_applies_the_snapshot_once(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'growth');

        $this->payByWebhook($invoice, 'pay_one');
        $afterFirst = $account->fresh()->currentSubscription->only(['total_allocated_messages', 'price_paid', 'expires_at']);

        $this->payByWebhook($invoice, 'pay_one');
        $this->payByVerify($user, $invoice, 'pay_one')->assertOk()->assertJsonPath('message', 'This invoice was already confirmed.');
        $this->assertFalse(app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_two'));

        $this->assertEquals($afterFirst, $account->fresh()->currentSubscription->only(['total_allocated_messages', 'price_paid', 'expires_at']));
        $this->assertSame(2500, (int) $afterFirst['total_allocated_messages']);
    }

    public function test_a_wrong_signature_still_fulfils_nothing(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant();
        $invoice = $this->order($user, 'growth');

        $this->actingAs($user)->postJson('/api/billing/verify-payment', [
            'invoice_id' => $invoice->id,
            'razorpay_order_id' => $invoice->gateway_order_id,
            'razorpay_payment_id' => 'pay_forged',
            'razorpay_signature' => 'deadbeef',
        ])->assertStatus(422);

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertNull($account->fresh()->currentSubscription);
    }

    // ==================================================================
    // F. Agent customer
    // ==================================================================

    public function test_an_agent_sub_client_order_is_fulfilled_from_its_snapshot_and_pays_commission(): void
    {
        $agent = Account::factory()->create(['account_type' => 'agent']);
        AgentCommissionRule::create(['agent_account_id' => $agent->id, 'type' => 'percentage', 'value' => 10]);
        ['account' => $client, 'user' => $user] = $this->tenant(['agent_id' => $agent->id]);

        $invoice = $this->order($user, 'growth');
        $this->editPlan('growth', ['total_allocated_messages' => 1, 'duration_days' => 1, 'engine_type' => 'meta']);
        $this->payByWebhook($invoice);

        $subscription = $client->fresh()->currentSubscription;
        $this->assertSame([2500, 'qr'], [(int) $subscription->total_allocated_messages, $subscription->engine_type]);
        $commission = AgentCommission::where('invoice_id', $invoice->id)->sole();
        $this->assertSame($agent->id, $commission->agent_account_id);
        $this->assertSame(round((float) $invoice->total_amount * 0.10, 2), (float) $commission->amount);
    }

    // ==================================================================
    // G. Existing plans / historical invoices
    // ==================================================================

    public function test_every_seeded_plan_still_checks_out_and_fulfils(): void
    {
        foreach (['starter', 'growth', 'business'] as $slug) {
            ['account' => $account, 'user' => $user] = $this->tenant();
            $terms = $this->termsOf($slug);

            $invoice = $this->order($user, $slug);
            $this->payByWebhook($invoice, "pay_{$slug}");

            $subscription = $account->fresh()->currentSubscription;
            $this->assertSame('active', $subscription->status, $slug);
            $this->assertSame($terms['engine_type'], $subscription->engine_type, $slug);
            $this->assertSame($terms['total_allocated_messages'], $subscription->total_allocated_messages, $slug);
        }
    }

    public function test_an_invoice_without_captured_terms_is_fulfilled_as_before(): void
    {
        // An order placed before P5-4: nothing is invented for it; it is
        // fulfilled from the plan row exactly as the old code did.
        ['account' => $account] = $this->tenant();
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-LEGACY', 'plan_key' => 'starter', 'plan_label' => 'Starter',
            'amount' => 499, 'tax_amount' => 0, 'total_amount' => 499, 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_legacy', 'status' => 'pending',
        ]);
        $this->assertNull($invoice->fresh()->purchasedPlanTerms());

        $this->assertTrue(app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_legacy'));

        $this->assertSame(500, (int) $account->fresh()->currentSubscription->total_allocated_messages);
    }

    public function test_a_legacy_invoice_with_no_plan_row_is_still_refused_as_before(): void
    {
        ['account' => $account] = $this->tenant();
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-TOPUP', 'plan_key' => 'quota_topup', 'plan_label' => 'Top-up',
            'amount' => 10, 'tax_amount' => 0, 'total_amount' => 10, 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'status' => 'pending',
        ]);

        $this->assertFalse(app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_x'));
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    public function test_fulfilment_reads_no_mutable_plan_field_for_a_captured_invoice(): void
    {
        $source = '';
        foreach (token_get_all(file_get_contents(app_path('Services/Billing/InvoiceCreditService.php'))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $source .= is_array($token) ? $token[1] : $token;
        }
        $fulfilment = substr($source, strpos($source, 'public function markPaidAndCreditQuota'));
        $fulfilment = substr($fulfilment, 0, strpos($fulfilment, 'private function reconcilePlanEntitlements'));

        // The only plan read left is inside the no-captured-terms branch.
        $this->assertSame(1, substr_count($fulfilment, 'findForFulfilment('));
        $this->assertStringContainsString('if ($terms === null)', $fulfilment);
        foreach (["\$terms['engine_type']", "\$terms['billing_model']", "\$terms['rate_per_message']", "\$terms['total_allocated_messages']", "\$terms['duration_days']"] as $read) {
            $this->assertStringContainsString($read, $fulfilment);
        }
    }
}
