<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentCommission;
use App\Models\AgentCommissionPayout;
use App\Models\AgentCommissionRule;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\AgentPayoutService;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent Commission Payout Ledger — internal settlement batches on top of
 * the existing AgentCommission rows (Agent Commission Foundation / Task
 * D refund-reversal / Task E reporting hardening). Commissions are
 * generated through the real InvoiceCreditService::markPaidAndCreditQuota()
 * path, the same convention AgentCommissionTest already uses, so every
 * commission exercised here carries the exact snapshot/idempotency
 * guarantees that service already provides.
 */
class AgentCommissionPayoutTest extends TestCase
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

    private function payoutService(): AgentPayoutService
    {
        return app(AgentPayoutService::class);
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

    private function makeInvoice(Account $account, string $planKey = 'business'): Invoice
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
            'status' => 'pending',
            'paid_at' => null,
            'gateway_raw_response' => null,
        ]);
    }

    /** Creates one real, confirmed AgentCommission for $agentAccount via the actual payment-success path. */
    private function makeConfirmedCommission(Account $agentAccount): AgentCommission
    {
        $customer = Account::factory()->client($agentAccount)->create();
        $invoice = $this->makeInvoice($customer);
        $this->creditService()->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return AgentCommission::where('invoice_id', $invoice->id)->firstOrFail();
    }

    // 1. Super Admin can create a payout for an Agent.
    public function test_super_admin_can_create_payout_for_an_agent(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);

        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('agent_commission_payouts', [
            'agent_account_id' => $agentAccount->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('agent_commission_payout_items', [
            'agent_commission_id' => $commission->id,
        ]);
    }

    // 2. Only confirmed commissions are included.
    public function test_only_confirmed_commissions_are_included(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $confirmed = $this->makeConfirmedCommission($agentAccount);
        $reversed = $this->makeConfirmedCommission($agentAccount);
        $this->creditService()->reverseAgentCommission($reversed->invoice_id, 'rfnd_1');

        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$confirmed->id, $reversed->id],
        ]);

        $response->assertCreated();
        $this->assertSame(1, DB::table('agent_commission_payout_items')->where('agent_commission_payout_id', $response->json('id'))->count());
        $this->assertDatabaseHas('agent_commission_payout_items', ['agent_commission_id' => $confirmed->id]);
        $this->assertDatabaseMissing('agent_commission_payout_items', ['agent_commission_id' => $reversed->id]);
    }

    // 3. Reversed commissions are excluded from the payable amount.
    public function test_reversed_commissions_are_excluded_from_payable_amount(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $confirmed = $this->makeConfirmedCommission($agentAccount);
        $reversed = $this->makeConfirmedCommission($agentAccount);
        $this->creditService()->reverseAgentCommission($reversed->invoice_id, 'rfnd_1');

        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$confirmed->id, $reversed->id],
        ]);

        $response->assertCreated();
        $this->assertSame((string) $confirmed->amount, (string) $response->json('gross_amount'));
        $this->assertSame((string) $confirmed->amount, (string) $response->json('net_amount'));
    }

    // 4. Already-paid commission cannot be included in another payout.
    public function test_already_paid_commission_cannot_be_included_in_another_payout(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);

        $superAdmin = $this->makeSuperAdmin();

        $first = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ]);
        $first->assertCreated();
        $this->payoutService()->updateStatus($first->json('id'), 'paid', 'ref_1');

        $second = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ]);

        $second->assertStatus(422);
        $this->assertSame(1, DB::table('agent_commission_payout_items')->where('agent_commission_id', $commission->id)->count());
    }

    // 5. Payout amount is snapshotted correctly.
    public function test_payout_amount_is_snapshotted_correctly(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);

        $superAdmin = $this->makeSuperAdmin();
        $response = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ]);
        $response->assertCreated();

        $originalAmount = (string) $commission->amount;

        // A future change to the commission row (hypothetical correction)
        // must never retroactively alter an already-recorded payout item.
        $commission->forceFill(['amount' => 99999.99])->save();

        $this->assertDatabaseHas('agent_commission_payout_items', [
            'agent_commission_id' => $commission->id,
            'amount_snapshot' => $originalAmount,
        ]);
    }

    // 6. Marking payout paid records paid timestamp/reference.
    public function test_marking_payout_paid_records_timestamp_and_reference(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);

        $superAdmin = $this->makeSuperAdmin();
        $payoutId = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ])->json('id');

        $response = $this->actingAs($superAdmin)->patchJson("/api/billing/payouts/{$payoutId}/status", [
            'status' => 'paid',
            'payout_reference' => 'txn_ref_123',
        ]);

        $response->assertOk();
        $payout = AgentCommissionPayout::findOrFail($payoutId);
        $this->assertSame('paid', $payout->status);
        $this->assertSame('txn_ref_123', $payout->payout_reference);
        $this->assertNotNull($payout->paid_at);
    }

    // 7. Failed/cancelled payout does not corrupt commission history.
    public function test_failed_or_cancelled_payout_does_not_corrupt_commission_history(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);
        $originalAmount = (string) $commission->amount;

        $superAdmin = $this->makeSuperAdmin();
        $payoutId = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ])->json('id');

        $this->actingAs($superAdmin)->patchJson("/api/billing/payouts/{$payoutId}/status", ['status' => 'failed'])->assertOk();

        $commission->refresh();
        $this->assertSame('confirmed', $commission->status);
        $this->assertSame($originalAmount, (string) $commission->amount);

        // Released back to the eligible pool: a fresh payout can now
        // successfully claim it.
        $retry = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ]);
        $retry->assertCreated();
    }

    // Phase 2 Audit -- 'cancelled' is the other terminal-but-releasing
    // status alongside 'failed' above; exercised separately since the
    // audit's minimum test list calls it out as its own case.
    public function test_cancelled_payout_releases_commission_for_reclaim(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);
        $originalAmount = (string) $commission->amount;

        $superAdmin = $this->makeSuperAdmin();
        $payoutId = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ])->json('id');

        $this->actingAs($superAdmin)->patchJson("/api/billing/payouts/{$payoutId}/status", ['status' => 'cancelled'])->assertOk();

        $commission->refresh();
        $this->assertSame('confirmed', $commission->status);
        $this->assertSame($originalAmount, (string) $commission->amount);

        // Released back to the eligible pool: a fresh payout can now
        // successfully claim it.
        $retry = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ]);
        $retry->assertCreated();
    }

    // Phase 2 Audit -- a 'paid' payout is terminal: it must never be
    // moved to any other status once reached (audit item 7).
    public function test_paid_payout_cannot_be_transitioned_to_another_status(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);

        $superAdmin = $this->makeSuperAdmin();
        $payoutId = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ])->json('id');

        $this->actingAs($superAdmin)->patchJson("/api/billing/payouts/{$payoutId}/status", [
            'status' => 'paid',
            'payout_reference' => 'txn_ref_1',
        ])->assertOk();

        $attempt = $this->actingAs($superAdmin)->patchJson("/api/billing/payouts/{$payoutId}/status", ['status' => 'cancelled']);
        $attempt->assertStatus(422);

        $payout = AgentCommissionPayout::findOrFail($payoutId);
        $this->assertSame('paid', $payout->status);
        $this->assertSame('txn_ref_1', $payout->payout_reference);
    }

    // Phase 2 Audit -- the 'processing' state must lock a commission
    // exactly like 'pending'/'paid' do (AgentPayoutService::
    // lockEligibleCommissions() excludes all three); previously only
    // 'pending' and 'paid' had dedicated coverage.
    public function test_commission_locked_by_a_processing_payout_cannot_be_claimed_by_another_payout(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);

        $superAdmin = $this->makeSuperAdmin();
        $firstId = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ])->json('id');
        $this->actingAs($superAdmin)->patchJson("/api/billing/payouts/{$firstId}/status", ['status' => 'processing'])->assertOk();

        $second = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ]);

        $second->assertStatus(422);
        $this->assertSame(1, DB::table('agent_commission_payout_items')->where('agent_commission_id', $commission->id)->count());
    }

    // 8. Agent can view only own payouts.
    public function test_agent_can_view_only_own_payouts(): void
    {
        $agentA = Account::factory()->agent()->create();
        $agentB = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentA->id, 'type' => 'percentage', 'value' => 10]);
        AgentCommissionRule::create(['agent_account_id' => $agentB->id, 'type' => 'percentage', 'value' => 10]);
        $commissionA = $this->makeConfirmedCommission($agentA);
        $commissionB = $this->makeConfirmedCommission($agentB);

        $superAdmin = $this->makeSuperAdmin();
        $this->actingAs($superAdmin)->postJson('/api/billing/payouts', ['agent_id' => $agentA->id, 'commission_ids' => [$commissionA->id]])->assertCreated();
        $this->actingAs($superAdmin)->postJson('/api/billing/payouts', ['agent_id' => $agentB->id, 'commission_ids' => [$commissionB->id]])->assertCreated();

        $agentUserA = $this->makeAgentAdmin($agentA);
        $response = $this->actingAs($agentUserA)->getJson('/api/billing/payouts');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($agentA->id, $rows[0]['agent_account_id']);
    }

    // 9. Agent cannot view another Agent's payout.
    public function test_agent_cannot_view_another_agents_payout(): void
    {
        $agentA = Account::factory()->agent()->create();
        $agentB = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentB->id, 'type' => 'percentage', 'value' => 10]);
        $commissionB = $this->makeConfirmedCommission($agentB);

        $superAdmin = $this->makeSuperAdmin();
        $this->actingAs($superAdmin)->postJson('/api/billing/payouts', ['agent_id' => $agentB->id, 'commission_ids' => [$commissionB->id]])->assertCreated();

        $agentUserA = $this->makeAgentAdmin($agentA);
        $response = $this->actingAs($agentUserA)->getJson('/api/billing/payouts');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(0, $rows);
    }

    // 10. Super Admin can view all payouts.
    public function test_super_admin_can_view_all_payouts(): void
    {
        $agentA = Account::factory()->agent()->create();
        $agentB = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentA->id, 'type' => 'percentage', 'value' => 10]);
        AgentCommissionRule::create(['agent_account_id' => $agentB->id, 'type' => 'percentage', 'value' => 10]);
        $commissionA = $this->makeConfirmedCommission($agentA);
        $commissionB = $this->makeConfirmedCommission($agentB);

        $superAdmin = $this->makeSuperAdmin();
        $this->actingAs($superAdmin)->postJson('/api/billing/payouts', ['agent_id' => $agentA->id, 'commission_ids' => [$commissionA->id]])->assertCreated();
        $this->actingAs($superAdmin)->postJson('/api/billing/payouts', ['agent_id' => $agentB->id, 'commission_ids' => [$commissionB->id]])->assertCreated();

        $response = $this->actingAs($superAdmin)->getJson('/api/billing/payouts');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(2, $rows);
    }

    // 11. Duplicate payout cannot settle the same commission twice (still-pending case).
    public function test_duplicate_payout_cannot_settle_the_same_commission_twice(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);

        $superAdmin = $this->makeSuperAdmin();
        $first = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ]);
        $first->assertCreated();

        // Still 'pending' -- a second batch must not be able to also
        // claim the same commission while the first is unresolved.
        $second = $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ]);

        $second->assertStatus(422);
        $this->assertSame(1, DB::table('agent_commission_payout_items')->where('agent_commission_id', $commission->id)->count());
    }

    // 12. Existing commission creation/reversal/reporting flow remains
    // unaffected by the new payout ledger (regression guard; the full
    // suite lives in AgentCommissionTest.php and is not touched by this task).
    public function test_existing_commission_lifecycle_and_reporting_are_unaffected_by_payouts(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $commission = $this->makeConfirmedCommission($agentAccount);

        $superAdmin = $this->makeSuperAdmin();
        $this->actingAs($superAdmin)->postJson('/api/billing/payouts', [
            'agent_id' => $agentAccount->id,
            'commission_ids' => [$commission->id],
        ])->assertCreated();

        // Creating a payout must not touch the commission row itself.
        $commission->refresh();
        $this->assertSame('confirmed', $commission->status);

        // Reversal still works normally on a commission that has since
        // been claimed by a (still-pending) payout -- the payout ledger
        // does not block or interfere with the existing reversal flow.
        $reversed = $this->creditService()->reverseAgentCommission($commission->invoice_id, 'rfnd_1');
        $this->assertTrue($reversed);
        $commission->refresh();
        $this->assertSame('reversed', $commission->status);

        // The commissions reporting endpoint (Task E) still reflects it correctly.
        $agentUser = $this->makeAgentAdmin($agentAccount);
        $response = $this->actingAs($agentUser)->getJson('/api/billing/commissions?status=reversed');
        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($commission->id, $rows[0]['id']);
    }

    // Bonus (Performance requirement, not separately numbered above): no
    // N+1 regression on GET /api/billing/payouts as row count grows.
    public function test_payouts_endpoint_does_not_n_plus_one_regardless_of_row_count(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agentAccount->id, 'type' => 'percentage', 'value' => 10]);
        $agentUser = $this->makeAgentAdmin($agentAccount);
        $superAdmin = $this->makeSuperAdmin();

        $commission1 = $this->makeConfirmedCommission($agentAccount);
        $this->actingAs($superAdmin)->postJson('/api/billing/payouts', ['agent_id' => $agentAccount->id, 'commission_ids' => [$commission1->id]])->assertCreated();

        // Warm up any one-time, request-independent caches (e.g. Spatie
        // Permission's role/permission cache) before measuring, so the
        // baseline capture below isn't inflated by first-request-only
        // overhead that the later "scaled" measurement below won't repeat.
        $this->actingAs($agentUser)->getJson('/api/billing/payouts')->assertOk();

        DB::enableQueryLog();
        $this->actingAs($agentUser)->getJson('/api/billing/payouts')->assertOk();
        $baselineCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 0; $i < 4; $i++) {
            $commission = $this->makeConfirmedCommission($agentAccount);
            $this->actingAs($superAdmin)->postJson('/api/billing/payouts', ['agent_id' => $agentAccount->id, 'commission_ids' => [$commission->id]])->assertCreated();
        }

        // enableQueryLog() does not clear the existing log (only
        // flushQueryLog() does) -- see AgentCommissionTest's own fixed
        // N+1 test for the same gotcha.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($agentUser)->getJson('/api/billing/payouts')->assertOk();
        $scaledCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($baselineCount, $scaledCount, 'GET /api/billing/payouts must not run additional queries per payout row (N+1).');
    }
}
