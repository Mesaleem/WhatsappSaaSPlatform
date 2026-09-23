<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan -> AccountEntitlement auto-grant: InvoiceCreditService::markPaidAndCreditQuota()
 * is the single, confirmed "payment became successful" integration point (reached from
 * both PaymentGatewayController::verifyPayment() and PaymentWebhookController's handlers).
 * These tests exercise that service directly rather than the gateway/webhook HTTP layer,
 * since the entitlement grant is implemented at that service boundary.
 */
class PlanEntitlementAutoGrantTest extends TestCase
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

    private function makeInvoice(Account $account, string $planKey, string $status = 'pending'): Invoice
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

    private function capabilityId(string $slug): int
    {
        return Capability::where('slug', $slug)->firstOrFail()->id;
    }

    // 1. Successful plan purchase grants all plan entitlements.
    public function test_successful_plan_purchase_grants_all_plan_entitlements(): void
    {
        $account = Account::factory()->create();
        $invoice = $this->makeInvoice($account, 'business');

        $result = $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertTrue($result);
        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('whatsapp_send'),
            'source' => 'plan',
        ]);
    }

    // 2. Multiple plan entitlements are all granted.
    public function test_multiple_plan_entitlements_are_all_granted(): void
    {
        $businessPlan = Plan::where('slug', 'business')->firstOrFail();
        $businessPlan->capabilities()->syncWithoutDetaching([
            $this->capabilityId('crm') => ['usage_limit' => null],
        ]);

        $account = Account::factory()->create();
        $invoice = $this->makeInvoice($account, 'business');

        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('whatsapp_send'),
            'source' => 'plan',
        ]);
        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('crm'),
            'source' => 'plan',
        ]);
    }

    // 3. Same purchase/callback executed twice does not create duplicates.
    public function test_same_callback_executed_twice_does_not_create_duplicates(): void
    {
        $account = Account::factory()->create();
        $invoice = $this->makeInvoice($account, 'business');

        $first = $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');
        $second = $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', $this->capabilityId('whatsapp_send'))
            ->count());
    }

    // 4. Failed payment does not grant entitlements. Mirrors the real
    // structural guarantee: PaymentWebhookController never calls
    // markPaidAndCreditQuota() for a non-successful event, so a failed
    // invoice is simply never credited.
    public function test_failed_payment_does_not_grant_entitlements(): void
    {
        $account = Account::factory()->create();
        $this->makeInvoice($account, 'business', 'failed');

        $this->assertDatabaseMissing('account_entitlements', [
            'account_id' => $account->id,
        ]);
    }

    // 5. Pending payment does not grant entitlements.
    public function test_pending_payment_does_not_grant_entitlements(): void
    {
        $account = Account::factory()->create();
        $this->makeInvoice($account, 'business', 'pending');

        $this->assertDatabaseMissing('account_entitlements', [
            'account_id' => $account->id,
        ]);
    }

    // 6. Existing entitlement is not duplicated (a second successful
    // purchase covering the same capability leaves a single row).
    public function test_existing_entitlement_is_not_duplicated(): void
    {
        $account = Account::factory()->create();
        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('whatsapp_send'),
            'source' => 'plan',
        ]);

        $invoice = $this->makeInvoice($account, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertSame(1, AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', $this->capabilityId('whatsapp_send'))
            ->count());
    }

    // 7. Existing manually granted entitlement is not accidentally removed
    // or reclassified by an unrelated plan purchase covering it.
    public function test_existing_manually_granted_entitlement_is_not_accidentally_removed(): void
    {
        $account = Account::factory()->create();
        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('whatsapp_send'),
            'source' => 'manual_grant',
        ]);

        $invoice = $this->makeInvoice($account, 'business');
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('whatsapp_send'),
            'source' => 'manual_grant',
        ]);
        $this->assertSame(1, AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', $this->capabilityId('whatsapp_send'))
            ->count());
    }

    // 8. Correct tenant/account receives the entitlement.
    public function test_correct_tenant_account_receives_the_entitlement(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $invoice = $this->makeInvoice($accountA, 'business');

        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $accountA->id,
            'capability_id' => $this->capabilityId('whatsapp_send'),
        ]);
        $this->assertDatabaseMissing('account_entitlements', [
            'account_id' => $accountB->id,
        ]);
    }

    // 9. A plan capability the customer's active provider cannot support
    // is silently skipped, not granted (ProviderCapabilityService reuse).
    public function test_plan_capability_unsupported_by_the_provider_is_not_granted(): void
    {
        /*
         * Phase 5 Task 9 FOLLOW-UP: 'crm' is no longer QR-incompatible,
         * so it no longer demonstrates the skip. 'journey_automation'
         * does — it stays a deliberate Meta-tier gate on QR. The
         * behaviour under test (a plan capability the account's engine
         * cannot support is silently skipped, not granted) is unchanged.
         *
         * Phase 7 Task 1.5: journey_automation became QR-supported (owner
         * decision); the example is now 'ads', still a QR refusal.
         */
        $starterPlan = Plan::where('slug', 'starter')->firstOrFail();
        $starterPlan->capabilities()->syncWithoutDetaching([
            $this->capabilityId('ads') => ['usage_limit' => null],
        ]);

        $account = Account::factory()->create();
        $invoice = $this->makeInvoice($account, 'starter');

        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_1');

        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('whatsapp_send'),
        ]);
        $this->assertDatabaseMissing('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('ads'),
        ]);
    }
}
