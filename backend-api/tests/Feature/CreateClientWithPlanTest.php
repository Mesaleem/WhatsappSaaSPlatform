<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\ManualPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manage Clients → Create Client with a plan (2026-10-12): the client gets the
 * plan exactly as if it had paid for it — invoice, offline payment,
 * subscription from the plan's terms, and the plan's features.
 */
class CreateClientWithPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function agent(): User
    {
        $account = Account::factory()->create(['company_name' => 'Agent Co', 'account_type' => 'agent', 'agent_id' => null]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');
        $user->assignRole('agent');

        return $user;
    }

    /** @return array<string, mixed> */
    private function client(array $extra = []): array
    {
        return $extra + [
            'company_name' => 'Acme Traders',
            'admin_name' => 'Asha',
            'admin_email' => 'asha@acme.test',
            'admin_password' => 'secret-pass',
            'module_assignment' => 'both',
        ];
    }

    public function test_plan_options_list_active_plans_with_their_price_and_modules(): void
    {
        $this->actingAs($this->superAdmin())->getJson('/api/admin/accounts/plan-options')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'starter')
            // No GST since 2026-10-14: the total is the plan price.
            ->assertJsonPath('data.0.total_amount', 599)
            ->assertJsonFragment(['slug' => 'whatsapp_send'])
            ->assertJsonPath('data.0.duration_days', 30);

        $starter = collect($this->actingAs($this->superAdmin())->getJson('/api/admin/accounts/plan-options')->json('data'))->firstWhere('key', 'starter');
        $this->assertContains('send_alert', $starter['modules']);
        $this->assertContains('developer_api', $starter['modules']); // Starter bundles external_api
        $this->assertNotContains('contact_groups', $starter['modules']); // paid add-on, never from a plan
    }

    public function test_super_admin_creates_a_client_on_a_plan_with_invoice_payment_subscription_and_features(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->postJson('/api/admin/accounts', $this->client([
            'plan_key' => 'growth',
            'payment_method' => 'upi',
            'transaction_id' => 'UPI-998877',
        ]))->assertCreated();

        $account = Account::findOrFail($response->json('id'));
        $growth = Plan::where('slug', 'growth')->firstOrFail();

        $invoice = Invoice::where('account_id', $account->id)->firstOrFail();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('growth', $invoice->plan_key);
        $this->assertSame('manual', $invoice->payment_gateway);
        $this->assertEqualsWithDelta(1999.00, (float) $invoice->total_amount, 0.01);

        $payment = ManualPayment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('upi', $payment->method);
        $this->assertSame('UPI-998877', $payment->transaction_id);
        $this->assertSame('super_admin', $payment->recorded_by_role);

        $subscription = $account->fresh()->currentSubscription;
        $this->assertSame($growth->engine_type, $subscription->engine_type);
        $this->assertSame($growth->billing_model, $subscription->billing_model);
        $this->assertSame($growth->total_allocated_messages, $subscription->total_allocated_messages);
        $this->assertTrue($subscription->expires_at->isSameDay(now()->addDays((int) $growth->duration_days)));
        $this->assertTrue($account->fresh()->hasActiveSubscription());

        // The plan's features are switched on (Growth bundles CRM).
        $held = $account->entitlements()->with('capability')->get()->pluck('capability.slug')->all();
        $this->assertContains('crm', $held);
        $this->assertContains('whatsapp_send', $held);
        $this->assertSame(1, Subscription::where('account_id', $account->id)->count());
    }

    public function test_a_discounted_price_is_recorded_as_paid(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/accounts', $this->client([
            'plan_key' => 'starter',
            'plan_total' => 500,
            'payment_method' => 'cash',
        ]))->assertCreated();

        $invoice = Invoice::where('account_id', $response->json('id'))->firstOrFail();
        $this->assertEqualsWithDelta(500.00, (float) $invoice->total_amount, 0.001);
        $this->assertEqualsWithDelta(500.00, (float) $invoice->amount + (float) $invoice->tax_amount, 0.001);
        $this->assertEqualsWithDelta(500.00, (float) $invoice->amount, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $invoice->tax_amount, 0.001);
        $this->assertStringStartsWith('NEW-CLIENT-', ManualPayment::where('invoice_id', $invoice->id)->value('transaction_id'));
    }

    public function test_an_agent_must_pick_a_plan_and_its_client_is_credited_under_the_agent(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->postJson('/api/admin/accounts', $this->client([
            'engine_type' => 'qr', 'billing_model' => 'flat_quota', 'total_allocated_messages' => 100000,
            'price_paid' => 0, 'payment_mode' => 'cash', 'starts_at' => now()->toDateString(), 'expires_at' => now()->addYear()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['plan_key']);
        $this->assertDatabaseMissing('users', ['email' => 'asha@acme.test']);

        $response = $this->actingAs($agent)->postJson('/api/admin/accounts', $this->client([
            'plan_key' => 'starter',
            'payment_method' => 'cash',
        ]))->assertCreated();

        $account = Account::findOrFail($response->json('id'));
        $this->assertSame($agent->account_id, $account->agent_id);
        $this->assertSame('agent', ManualPayment::where('account_id', $account->id)->value('recorded_by_role'));
        $this->assertTrue($account->hasActiveSubscription());
    }

    public function test_custom_terms_without_a_plan_still_work_for_the_super_admin(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/accounts', $this->client([
            'engine_type' => 'qr', 'billing_model' => 'flat_quota', 'total_allocated_messages' => 5000,
            'price_paid' => 4999, 'payment_mode' => 'cash', 'starts_at' => now()->toDateString(), 'expires_at' => now()->addMonth()->toDateString(),
        ]))->assertCreated();

        $this->assertSame(0, Invoice::where('account_id', $response->json('id'))->count());
        $this->assertSame(5000, Account::find($response->json('id'))->currentSubscription->total_allocated_messages);
    }

    public function test_an_inactive_plan_or_a_reused_transaction_id_leaves_no_half_created_client(): void
    {
        $admin = $this->superAdmin();
        Plan::where('slug', 'business')->update(['is_active' => false]);

        $this->actingAs($admin)->postJson('/api/admin/accounts', $this->client(['plan_key' => 'business', 'payment_method' => 'cash']))
            ->assertUnprocessable()->assertJsonValidationErrors(['plan_key']);

        $this->actingAs($admin)->postJson('/api/admin/accounts', $this->client(['plan_key' => 'starter', 'payment_method' => 'cash', 'transaction_id' => 'RCPT-1']))->assertCreated();
        $this->actingAs($admin)->postJson('/api/admin/accounts', $this->client(['admin_email' => 'b@acme.test', 'company_name' => 'B', 'plan_key' => 'starter', 'payment_method' => 'cash', 'transaction_id' => 'RCPT-1']))
            ->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);

        $this->assertDatabaseMissing('users', ['email' => 'b@acme.test']);
        $this->assertSame(1, Account::where('company_name', 'Acme Traders')->count());
    }
}
