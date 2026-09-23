<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentCommission;
use App\Models\AgentCommissionRule;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent Commission Foundation — mirrors PlanEntitlementAutoGrantTest's own
 * approach: InvoiceCreditService::markPaidAndCreditQuota() is the single
 * confirmed "payment became successful" integration point (reached from
 * both PaymentGatewayController::verifyPayment() and
 * PaymentWebhookController), so commission generation is exercised at
 * that same service boundary rather than through the HTTP payment layer.
 * Read-endpoint (GET /api/billing/commissions) and rule-configuration
 * (PUT /api/admin/accounts/{id}/commission-rule) tests go through the
 * real HTTP layer since that IS the surface being hardened there.
 */
class AgentCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function creditService(): InvoiceCreditService
    {
        return app(InvoiceCreditService::class);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function makeAgentAdmin(Account $agentAccount): User
    {
        $user = User::factory()->create(['account_id' => $agentAccount->id]);
        $user->assignRole(['admin', 'agent']);

        return $user;
    }

    private function makeInvoice(Account $account, string $planKey = 'business', string $status = 'pending'): Invoice
    {
        $plan = PlanCatalog::find($planKey);

        return Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $planKey,
            'plan_label' => $plan['label'] ?? ucfirst($planKey),
            'amount' => $plan['price'] ?? 0,
            'tax_amount' => 0,
            'total_amount' => $plan['price'] ?? 0,
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(),
            'gateway_payment_id' => null,
            'status' => $status,
            'paid_at' => null,
            'gateway_raw_response' => null,
        ]);
    }

    // 1. Agent commission rule can be configured.
    public function test_agent_commission_rule_can_be_configured(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->putJson(
            "/api/admin/accounts/{$agentAccount->id}/commission-rule",
            ['type' => 'percentage', 'value' => 10]
        );

        $response->assertOk();
        $this->assertDatabaseHas('agent_commission_rules', [
            'agent_account_id' => $agentAccount->id,
            'type' => 'percentage',
            'value' => 10.00,
        ]);
    }

    // 2. Successful customer payment creates commission.
    public function test_successful_customer_payment_creates_commission(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');

        $result = $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertTrue($result);
        $this->assertDatabaseHas('agent_commissions', [
            'agent_account_id' => $agentAccount->id,
            'customer_account_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'status' => 'confirmed',
        ]);
    }

    // 3. Commission amount is calculated correctly from the configured rule.
    public function test_commission_amount_is_calculated_correctly_from_the_rule(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business'); // total_amount = 7999.00

        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $commission = AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('799.90', (string) $commission->amount);

        // Fixed-amount rule, separately: amount is the flat value, not a
        // percentage of the invoice.
        $agentAccountFixed = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccountFixed->id, 'type' => 'fixed', 'value' => 250]);
        $customerFixed = Account::factory()->client($agentAccountFixed)->create();
        $invoiceFixed = $this->makeInvoice($customerFixed, 'business');

        $this->creditService()->markPaidAndCreditQuota($invoiceFixed->id, 'pay_2');

        $commissionFixed = AgentCommission::where('invoice_id', $invoiceFixed->id)->firstOrFail();
        $this->assertSame('250.00', (string) $commissionFixed->amount);
    }

    // 4. Commission stores a historical snapshot of the applied rule/value.
    public function test_commission_stores_a_historical_snapshot_of_the_applied_rule(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $rule = AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 15]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');

        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $commission = AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('percentage', $commission->rule_type);
        $this->assertSame('15.00', (string) $commission->rule_value);
        $this->assertSame($rule->id, $commission->agent_commission_rule_id);
        $this->assertSame('7999.00', (string) $commission->base_amount);
    }

    // 5. Duplicate payment/webhook does not duplicate commission.
    public function test_duplicate_payment_webhook_does_not_duplicate_commission(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');

        $first = $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');
        $second = $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, AgentCommission::where('invoice_id', $invoice->id)->count());
    }

    // 6. Failed payment creates no commission.
    public function test_failed_payment_creates_no_commission(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $this->makeInvoice($customer, 'business', 'failed');

        $this->assertDatabaseMissing('agent_commissions', ['customer_account_id' => $customer->id]);
    }

    // 7. Pending payment creates no commission.
    public function test_pending_payment_creates_no_commission(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $this->makeInvoice($customer, 'business', 'pending');

        $this->assertDatabaseMissing('agent_commissions', ['customer_account_id' => $customer->id]);
    }

    // 8. Direct customer (no Agent) creates no Agent commission.
    public function test_direct_customer_creates_no_agent_commission(): void
    {
        $directCustomer = Account::factory()->create(); // agent_id === null by default
        $invoice = $this->makeInvoice($directCustomer, 'business');

        $result = $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertTrue($result);
        $this->assertDatabaseMissing('agent_commissions', ['customer_account_id' => $directCustomer->id]);
    }

    // 9. Agent A cannot view Agent B's commission.
    public function test_agent_a_cannot_view_agent_bs_commission(): void
    {
        $agentA = Account::factory()->agent()->create();
        $agentB = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentB->id, 'type' => 'percentage', 'value' => 10]);
        $customerOfB = Account::factory()->client($agentB)->create();
        $invoice = $this->makeInvoice($customerOfB, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $agentUserA = $this->makeAgentAdmin($agentA);

        $response = $this->actingAs($agentUserA)->getJson('/api/billing/commissions');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(0, $rows);
    }

    // 10. Agent can view its own commission.
    public function test_agent_can_view_its_own_commission(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->getJson('/api/billing/commissions');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($customer->id, $rows[0]['customer_account_id']);
    }

    // 11. Super Admin can view all commissions.
    public function test_super_admin_can_view_all_commissions(): void
    {
        $agentA = Account::factory()->agent()->create();
        $agentB = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentA->id, 'type' => 'percentage', 'value' => 10]);
        AgentCommissionRule::create(['agent_account_id' => $agentB->id, 'type' => 'percentage', 'value' => 10]);
        $customerA = Account::factory()->client($agentA)->create();
        $customerB = Account::factory()->client($agentB)->create();
        $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customerA, 'business')->id, 'pay_1');
        $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customerB, 'business')->id, 'pay_2');

        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->getJson('/api/billing/commissions');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(2, $rows);
    }

    // 12. Changing the commission rule does not alter historical commission records.
    public function test_changing_the_commission_rule_does_not_alter_historical_commission_records(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $superAdmin = $this->makeSuperAdmin();

        $this->actingAs($superAdmin)->putJson(
            "/api/admin/accounts/{$agentAccount->id}/commission-rule",
            ['type' => 'percentage', 'value' => 10]
        )->assertOk();

        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $commission = AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('799.90', (string) $commission->amount);
        $this->assertSame('10.00', (string) $commission->rule_value);

        // Rule changes going forward — the already-written commission row
        // must remain exactly as it was.
        $this->actingAs($superAdmin)->putJson(
            "/api/admin/accounts/{$agentAccount->id}/commission-rule",
            ['type' => 'percentage', 'value' => 25]
        )->assertOk();

        $commission->refresh();
        $this->assertSame('799.90', (string) $commission->amount);
        $this->assertSame('10.00', (string) $commission->rule_value);

        // A new purchase after the change uses the NEW rule.
        $invoice2 = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice2->id, 'pay_2');
        $commission2 = AgentCommission::where('invoice_id', $invoice2->id)->firstOrFail();
        $this->assertSame('1999.75', (string) $commission2->amount);
    }

    // ---------------------------------------------------------------
    // Refund/reversal lifecycle (Task D). InvoiceCreditService::
    // reverseAgentCommission() is exercised directly, at the same
    // service-method boundary as markPaidAndCreditQuota() above, per
    // this class's own docblock convention — the actual HTTP webhook
    // endpoint is untested anywhere in this codebase, so gateway event
    // *classification* (is_refund) is verified at the driver level
    // instead (see test D7 below).
    // ---------------------------------------------------------------

    // D1. Paid invoice with commission -> refund -> commission becomes 'reversed'.
    public function test_refunding_a_paid_invoice_with_commission_marks_it_reversed(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $result = $this->creditService()->reverseAgentCommission($invoice->id, 'rfnd_1');

        $this->assertTrue($result);
        $commission = AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('reversed', $commission->status);
        $this->assertNotNull($commission->reversed_at);
        $this->assertSame('rfnd_1', $commission->reversal_reference);
    }

    // D2. Original commission amount remains unchanged after reversal.
    public function test_reversal_leaves_the_original_commission_amount_unchanged(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business'); // total_amount = 7999.00
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->creditService()->reverseAgentCommission($invoice->id, 'rfnd_1');

        $commission = AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('799.90', (string) $commission->amount);
        $this->assertSame('7999.00', (string) $commission->base_amount);
    }

    // D3. Commission rule snapshot remains unchanged after reversal.
    public function test_reversal_leaves_the_rule_snapshot_unchanged(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $rule = AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->creditService()->reverseAgentCommission($invoice->id, 'rfnd_1');

        $commission = AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('percentage', $commission->rule_type);
        $this->assertSame('10.00', (string) $commission->rule_value);
        $this->assertSame($rule->id, $commission->agent_commission_rule_id);
    }

    // D4. Duplicate refund webhook does not create duplicate reversal/state changes.
    public function test_duplicate_refund_event_does_not_double_reverse_commission(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $first = $this->creditService()->reverseAgentCommission($invoice->id, 'rfnd_1');
        $second = $this->creditService()->reverseAgentCommission($invoice->id, 'rfnd_1');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, AgentCommission::where('invoice_id', $invoice->id)->where('status', 'reversed')->count());
    }

    // D5. Invoice without an Agent commission -> refund causes no commission record.
    public function test_reversing_an_invoice_with_no_commission_is_a_noop(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        // Deliberately no AgentCommissionRule configured, so
        // markPaidAndCreditQuota() skips commission creation entirely (see
        // grantAgentCommission()'s own "no rule" branch) -- there is
        // nothing for reverseAgentCommission() to act on.
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $result = $this->creditService()->reverseAgentCommission($invoice->id, 'rfnd_1');

        $this->assertFalse($result);
        $this->assertDatabaseMissing('agent_commissions', ['invoice_id' => $invoice->id]);
    }

    // D6. Direct customer refund -> no Agent commission (unaffected).
    public function test_reversing_a_direct_customers_invoice_creates_no_agent_commission(): void
    {
        $directCustomer = Account::factory()->create(); // agent_id === null by default
        $invoice = $this->makeInvoice($directCustomer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $result = $this->creditService()->reverseAgentCommission($invoice->id, 'rfnd_1');

        $this->assertFalse($result);
        $this->assertDatabaseMissing('agent_commissions', ['customer_account_id' => $directCustomer->id]);
    }

    // D7. Non-refund webhook event must not reverse commission: verifies the
    // exact gateway-event classification PaymentWebhookController::handle()
    // gates reverseAgentCommission() on (is_refund), and that a commission
    // stays 'confirmed' when no such reversal call ever happens.
    public function test_a_non_refund_event_never_triggers_reversal(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $driver = new \App\Services\Payment\RazorpayGatewayDriver('key_id', 'key_secret');
        $successEvent = $driver->parseWebhookEvent([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'order_id' => 'order_1', 'status' => 'captured']]],
        ]);
        $this->assertFalse($successEvent['is_refund']);

        $refundEvent = $driver->parseWebhookEvent([
            'event' => 'refund.processed',
            'payload' => [
                'payment' => ['entity' => ['id' => 'pay_1', 'order_id' => 'order_1', 'status' => 'captured']],
                'refund' => ['entity' => ['id' => 'rfnd_1']],
            ],
        ]);
        $this->assertTrue($refundEvent['is_refund']);

        $commission = AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('confirmed', $commission->status);
    }

    // D8. Agent can see a reversed commission only within its own scope.
    public function test_agent_can_see_reversed_commission_only_within_its_own_scope(): void
    {
        $agentA = Account::factory()->agent()->create();
        $agentB = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentA->id, 'type' => 'percentage', 'value' => 10]);
        AgentCommissionRule::create(['agent_account_id' => $agentB->id, 'type' => 'percentage', 'value' => 10]);
        $customerA = Account::factory()->client($agentA)->create();
        $customerB = Account::factory()->client($agentB)->create();
        $invoiceA = $this->makeInvoice($customerA, 'business');
        $invoiceB = $this->makeInvoice($customerB, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoiceA->id, 'pay_1');
        $this->creditService()->markPaidAndCreditQuota($invoiceB->id, 'pay_2');
        $this->creditService()->reverseAgentCommission($invoiceA->id, 'rfnd_1');
        $this->creditService()->reverseAgentCommission($invoiceB->id, 'rfnd_2');

        $agentUserA = $this->makeAgentAdmin($agentA);

        $response = $this->actingAs($agentUserA)->getJson('/api/billing/commissions');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($customerA->id, $rows[0]['customer_account_id']);
        $this->assertSame('reversed', $rows[0]['status']);
    }

    // D9. Super Admin can see reversed commissions.
    public function test_super_admin_can_see_reversed_commissions(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');
        $this->creditService()->reverseAgentCommission($invoice->id, 'rfnd_1');

        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->getJson('/api/billing/commissions');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('reversed', $rows[0]['status']);
    }

    // D10. Existing successful commission creation still passes (regression
    // check: the new nullable reversed_at/reversal_reference columns default
    // to null and do not interfere with normal commission creation).
    public function test_existing_successful_commission_creation_still_works(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');

        $result = $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertTrue($result);
        $commission = AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('confirmed', $commission->status);
        $this->assertNull($commission->reversed_at);
        $this->assertNull($commission->reversal_reference);
    }

    // ---------------------------------------------------------------
    // Ledger/reporting hardening (Task E) — GET /api/billing/commissions'
    // filters, pagination, and Super-Admin-only ?agent_id=. Reversed-
    // commission visibility (item 9) is already covered above by
    // test_agent_can_see_reversed_commission_only_within_its_own_scope
    // and test_super_admin_can_see_reversed_commissions; "Agent sees only
    // own commissions" (item 1) is already covered by
    // test_agent_can_view_its_own_commission above.
    // ---------------------------------------------------------------

    // E2. Agent cannot access another Agent's commissions via ?agent_id=.
    public function test_agent_cannot_access_another_agent_via_agent_id_filter(): void
    {
        $agentA = Account::factory()->agent()->create();
        $agentB = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentA->id, 'type' => 'percentage', 'value' => 10]);
        AgentCommissionRule::create(['agent_account_id' => $agentB->id, 'type' => 'percentage', 'value' => 10]);
        $customerB = Account::factory()->client($agentB)->create();
        $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customerB, 'business')->id, 'pay_1');

        $agentUserA = $this->makeAgentAdmin($agentA);

        // Agent A tries to widen its view onto Agent B's commissions via
        // ?agent_id= -- silently ignored, the exact same precedent as
        // AccountController::index()'s own ?agent_id= filter for a
        // non-Super-Admin caller.
        $response = $this->actingAs($agentUserA)->getJson("/api/billing/commissions?agent_id={$agentB->id}");

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(0, $rows);
    }

    // E3. Agent filters its own commissions by status.
    public function test_agent_filters_own_commissions_by_status(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer1 = Account::factory()->client($agentAccount)->create();
        $customer2 = Account::factory()->client($agentAccount)->create();
        $invoice1 = $this->makeInvoice($customer1, 'business');
        $invoice2 = $this->makeInvoice($customer2, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice1->id, 'pay_1');
        $this->creditService()->markPaidAndCreditQuota($invoice2->id, 'pay_2');
        $this->creditService()->reverseAgentCommission($invoice2->id, 'rfnd_1');

        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->getJson('/api/billing/commissions?status=reversed');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($invoice2->id, $rows[0]['invoice_id']);
        $this->assertSame('reversed', $rows[0]['status']);
    }

    // E4. Agent filters its own commissions by date range (created_at).
    public function test_agent_filters_own_commissions_by_date_range(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $commission = AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
        $commission->forceFill(['created_at' => now()->subDays(10)])->save();

        $agentUser = $this->makeAgentAdmin($agentAccount);

        // A range that excludes the 10-day-old row entirely.
        $excluding = $this->actingAs($agentUser)->getJson(
            '/api/billing/commissions?from='.now()->subDays(2)->toDateString().'&to='.now()->toDateString()
        );
        $excluding->assertOk();
        $rowsExcluding = $excluding->json('data.data') ?? $excluding->json('data');
        $this->assertCount(0, $rowsExcluding);

        // A range that includes it.
        $including = $this->actingAs($agentUser)->getJson(
            '/api/billing/commissions?from='.now()->subDays(15)->toDateString().'&to='.now()->toDateString()
        );
        $including->assertOk();
        $rowsIncluding = $including->json('data.data') ?? $including->json('data');
        $this->assertCount(1, $rowsIncluding);
    }

    // E5. Agent filters its own commissions by customer.
    public function test_agent_filters_own_commissions_by_customer(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer1 = Account::factory()->client($agentAccount)->create();
        $customer2 = Account::factory()->client($agentAccount)->create();
        $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customer1, 'business')->id, 'pay_1');
        $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customer2, 'business')->id, 'pay_2');

        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->getJson("/api/billing/commissions?customer_id={$customer1->id}");

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($customer1->id, $rows[0]['customer_account_id']);
    }

    // E6. Pagination works.
    public function test_commissions_endpoint_paginates_results(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        for ($i = 0; $i < 3; $i++) {
            $customer = Account::factory()->client($agentAccount)->create();
            $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customer, 'business')->id, "pay_{$i}");
        }

        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->getJson('/api/billing/commissions?per_page=2');

        $response->assertOk();
        $this->assertSame(2, $response->json('per_page'));
        $this->assertSame(3, $response->json('total'));
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(2, $rows);
    }

    // E7. Super Admin filters commissions by Agent.
    public function test_super_admin_filters_commissions_by_agent(): void
    {
        $agentA = Account::factory()->agent()->create();
        $agentB = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentA->id, 'type' => 'percentage', 'value' => 10]);
        AgentCommissionRule::create(['agent_account_id' => $agentB->id, 'type' => 'percentage', 'value' => 10]);
        $customerA = Account::factory()->client($agentA)->create();
        $customerB = Account::factory()->client($agentB)->create();
        $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customerA, 'business')->id, 'pay_1');
        $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customerB, 'business')->id, 'pay_2');

        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->getJson("/api/billing/commissions?agent_id={$agentA->id}");

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($agentA->id, $rows[0]['agent_account_id']);
    }

    // E8. Super Admin filters commissions by customer, status, and date.
    public function test_super_admin_filters_commissions_by_customer_status_and_date(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $customer1 = Account::factory()->client($agentAccount)->create();
        $customer2 = Account::factory()->client($agentAccount)->create();
        $invoice1 = $this->makeInvoice($customer1, 'business');
        $invoice2 = $this->makeInvoice($customer2, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice1->id, 'pay_1');
        $this->creditService()->markPaidAndCreditQuota($invoice2->id, 'pay_2');
        $this->creditService()->reverseAgentCommission($invoice2->id, 'rfnd_1');

        $superAdmin = $this->makeSuperAdmin();

        $byCustomer = $this->actingAs($superAdmin)->getJson("/api/billing/commissions?customer_id={$customer1->id}");
        $byCustomer->assertOk();
        $rowsByCustomer = $byCustomer->json('data.data') ?? $byCustomer->json('data');
        $this->assertCount(1, $rowsByCustomer);
        $this->assertSame($customer1->id, $rowsByCustomer[0]['customer_account_id']);

        $byStatus = $this->actingAs($superAdmin)->getJson('/api/billing/commissions?status=reversed');
        $byStatus->assertOk();
        $rowsByStatus = $byStatus->json('data.data') ?? $byStatus->json('data');
        $this->assertCount(1, $rowsByStatus);
        $this->assertSame($invoice2->id, $rowsByStatus[0]['invoice_id']);

        // A "from" set in the future excludes every row that already exists.
        $byDate = $this->actingAs($superAdmin)->getJson('/api/billing/commissions?from='.now()->addDay()->toDateString());
        $byDate->assertOk();
        $rowsByDate = $byDate->json('data.data') ?? $byDate->json('data');
        $this->assertCount(0, $rowsByDate);
    }

    // E10. No N+1 regression: query count must not scale with row count.
    public function test_commissions_endpoint_does_not_n_plus_one_regardless_of_row_count(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $customer1 = Account::factory()->client($agentAccount)->create();
        $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customer1, 'business')->id, 'pay_1');

        // Warm up any one-time, request-independent caches (e.g. Spatie
        // Permission's role/permission cache) before measuring, so the
        // baseline capture below isn't inflated by first-request-only
        // overhead that the later "scaled" measurement below won't repeat.
        $this->actingAs($agentUser)->getJson('/api/billing/commissions')->assertOk();

        DB::enableQueryLog();
        $this->actingAs($agentUser)->getJson('/api/billing/commissions')->assertOk();
        $baselineCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Add several more rows for DIFFERENT customers -- if the endpoint
        // were N+1 on agentAccount/customerAccount/invoice, the query
        // count would grow with the row count instead of staying flat.
        for ($i = 0; $i < 5; $i++) {
            $customer = Account::factory()->client($agentAccount)->create();
            $this->creditService()->markPaidAndCreditQuota($this->makeInvoice($customer, 'business')->id, "pay_extra_{$i}");
        }

        // enableQueryLog() does not clear the existing log (only
        // flushQueryLog() does) -- without this the array from the
        // baseline measurement above stays in place and scaledCount
        // would be baseline + this call's own queries, not this call's
        // queries alone.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($agentUser)->getJson('/api/billing/commissions')->assertOk();
        $scaledCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($baselineCount, $scaledCount, 'GET /api/billing/commissions must not run additional queries per commission row (N+1).');
    }
}
