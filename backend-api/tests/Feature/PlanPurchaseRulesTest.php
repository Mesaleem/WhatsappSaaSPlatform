<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CustomPlanRequest;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\PaymentGatewaySetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Plan purchase rules (owner, 2026-10-14): one request at a time (pay or cancel it), the
 * running plan is renewed only after it ends, a smaller plan only after it ends, a bigger
 * plan is an upgrade that ends the running plan at payment; and "Contact sales" for a
 * custom plan (customer + Super Admin informed, shown to the Super Admin).
 */
class PlanPurchaseRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        PaymentGatewaySetting::create(['gateway' => 'razorpay', 'mode' => 'test', 'is_enabled' => true, 'test_key_id' => 'k', 'test_key_secret' => 's', 'test_webhook_secret' => 'w']);
        Http::fake(['api.razorpay.com/v1/orders' => fn () => Http::response(['id' => 'order_'.uniqid(), 'status' => 'created'], 200)]);
    }

    private function client(): User
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true, 'email' => 'owner'.uniqid().'@example.com']);
        $user->assignRole('admin');

        return $user;
    }

    private function order(User $user, string $plan)
    {
        return $this->actingAs($user)->postJson('/api/billing/create-order', ['plan_key' => $plan, 'gateway' => 'razorpay']);
    }

    private function pay(int $invoiceId): void
    {
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoiceId, 'pay_'.uniqid());
    }

    private function plans(): array
    {
        $byPrice = Plan::query()->where('is_active', true)->orderBy('price')->pluck('slug')->all();
        $this->assertGreaterThanOrEqual(2, count($byPrice));

        return [$byPrice[0], $byPrice[count($byPrice) - 1]];
    }

    public function test_one_request_at_a_time_and_it_can_be_cancelled(): void
    {
        [$small, $big] = $this->plans();
        $user = $this->client();

        $id = $this->order($user, $small)->assertCreated()->json('invoice_id');
        $this->order($user, $big)->assertStatus(409)->assertJsonPath('error_code', 'pending');
        $this->actingAs($user)->postJson('/api/billing/manual-checkout', ['plan_key' => $big])->assertStatus(409)->assertJsonPath('error_code', 'request_pending');

        $this->actingAs($user)->getJson('/api/billing/plans')->assertOk()
            ->assertJsonPath('plan_status.pending_invoice.id', $id)
            ->assertJsonPath('plans.0.purchase.action', 'pending');

        $this->actingAs($user)->postJson("/api/billing/invoices/{$id}/cancel")->assertOk();
        $this->assertSame('cancelled', Invoice::find($id)->status);
        $this->order($user, $big)->assertCreated();

        $this->pay(Invoice::where('plan_key', $big)->value('id'));
        $paid = Invoice::where('plan_key', $big)->value('id');
        $this->actingAs($user)->postJson("/api/billing/invoices/{$paid}/cancel")->assertStatus(409);
    }

    public function test_a_running_plan_cannot_be_rebought_or_downgraded_but_can_be_upgraded(): void
    {
        [$small, $big] = $this->plans();
        $user = $this->client();
        $this->pay($this->order($user, $small)->assertCreated()->json('invoice_id'));

        $this->order($user, $small)->assertStatus(409)->assertJsonPath('error_code', 'renew_after_expiry');
        $actions = collect($this->actingAs($user)->getJson('/api/billing/plans')->json('plans'))->pluck('purchase.action', 'key');
        $this->assertSame('renew_after_expiry', $actions[$small]);
        $this->assertSame('upgrade', $actions[$big]);

        $id = $this->order($user, $big)->assertCreated()->json('invoice_id');
        $this->assertSame('upgrade', Invoice::find($id)->plan_change);

        // And now, with the bigger plan running, the smaller one waits for its end.
        $this->pay($id);
        $this->order($user, $small)->assertStatus(409)->assertJsonPath('error_code', 'downgrade_after_expiry');
    }

    public function test_an_upgrade_ends_the_running_plan_at_payment_and_starts_the_new_one(): void
    {
        [$small, $big] = $this->plans();
        $user = $this->client();
        $this->pay($this->order($user, $small)->json('invoice_id'));
        $this->travel(10)->days();

        $bigPlan = Plan::where('slug', $big)->firstOrFail();
        $this->pay($this->order($user, $big)->json('invoice_id'));

        $sub = Subscription::where('account_id', $user->account_id)->sole();
        $this->assertSame(now()->addDays((int) $bigPlan->duration_days)->toDateString(), $sub->expires_at->toDateString(), 'a fresh period from the upgrade, not stacked');
        $this->assertSame(now()->toDateString(), $sub->starts_at->toDateString());
        $this->assertSame($bigPlan->total_allocated_messages === null ? null : (int) $bigPlan->total_allocated_messages, $sub->total_allocated_messages, "the new plan's own quota");
        $this->assertSame(0, (int) $sub->used_messages);
    }

    public function test_after_the_plan_ends_the_same_plan_can_be_renewed(): void
    {
        [$small] = $this->plans();
        $user = $this->client();
        $this->pay($this->order($user, $small)->json('invoice_id'));
        $days = (int) Plan::where('slug', $small)->value('duration_days');
        $this->travel($days + 1)->days();
        Subscription::where('account_id', $user->account_id)->first()->refreshStatus();

        $id = $this->order($user, $small)->assertCreated()->json('invoice_id');
        $this->assertSame('renew', Invoice::find($id)->plan_change);
    }

    public function test_contact_sales_saves_the_request_and_informs_the_super_admins(): void
    {
        $admin = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $admin->assignRole('super_admin');
        $user = $this->client();

        $this->actingAs($user)->postJson('/api/billing/custom-plan-requests', ['name' => 'Rahul', 'email' => 'rahul@example.com', 'requirement' => 'Too short'])->assertStatus(422);
        // The phone number is required: missing or too short is refused.
        $this->actingAs($user)->postJson('/api/billing/custom-plan-requests', ['name' => 'Rahul', 'email' => 'rahul@example.com', 'requirement' => 'We need 5 WhatsApp numbers.'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->actingAs($user)->postJson('/api/billing/custom-plan-requests', ['name' => 'Rahul', 'email' => 'rahul@example.com', 'phone' => '12345', 'requirement' => 'We need 5 WhatsApp numbers.'])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/billing/custom-plan-requests', [
            'name' => 'Rahul', 'email' => 'rahul@example.com', 'phone' => '+91 98765 43210', 'company' => 'Sharma Travels', 'expected_messages' => 50000,
            'requirement' => 'We need 5 WhatsApp numbers and 50k messages a month.',
        ])->assertCreated()->assertJsonPath('message', fn ($m) => str_contains($m, '24–48 hours'));

        $this->assertSame(1, CustomPlanRequest::where('status', 'new')->where('account_id', $user->account_id)->count());
        $this->assertSame(1, InAppNotification::where('user_id', $admin->id)->where('title', 'like', 'Custom plan request%')->count());

        $this->actingAs($user)->getJson('/api/admin/custom-plan-requests')->assertForbidden();
        $list = $this->actingAs($admin)->getJson('/api/admin/custom-plan-requests')->assertOk()->assertJsonPath('new_count', 1);
        $id = $list->json('data.0.id');
        $this->actingAs($admin)->patchJson("/api/admin/custom-plan-requests/{$id}", ['status' => 'contacted', 'admin_note' => 'Called'])
            ->assertOk()->assertJsonPath('data.status', 'contacted');
        $this->assertNotNull(CustomPlanRequest::find($id)->contacted_at);
    }
}
