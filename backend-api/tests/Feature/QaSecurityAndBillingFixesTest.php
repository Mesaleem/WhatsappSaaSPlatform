<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\QuotaRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Billing\ModuleAddonService;
use App\Services\Billing\PlanChangePolicy;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * QA fixes (2026-10-14) — the critical security and money findings of the
 * plan × role QA pass, each reproduced and then fixed.
 */
class QaSecurityAndBillingFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake(['*' => Http::response(['success' => true], 200)]);
    }

    private function clientOn(string $slug, array $attrs = []): Account
    {
        $account = Account::factory()->create($attrs + ['account_type' => 'client']);
        $this->pay($account, $slug);

        return $account->fresh();
    }

    private function pay(Account $account, string $slug): Invoice
    {
        $plan = Plan::where('slug', $slug)->firstOrFail();
        $invoice = new Invoice([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $slug, 'plan_label' => $plan->label,
            'amount' => $plan->price, 'tax_amount' => 0, 'total_amount' => $plan->price, 'currency' => 'INR',
            'payment_gateway' => 'manual', 'status' => 'pending',
        ]);
        $invoice->capturePlanTerms($plan)->save();
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return $invoice->fresh();
    }

    private function member(Account $account, string|array $roles): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($roles);

        return $user;
    }

    private function agentWithClient(): array
    {
        $agentAccount = Account::factory()->create(['account_type' => 'agent', 'company_name' => 'Agent Co']);
        $agent = $this->member($agentAccount, ['admin', 'agent']);
        $client = $this->clientOn('starter', ['agent_id' => $agentAccount->id]);

        return [$agentAccount, $agent, $client];
    }

    // ---------------------------------------------------------------- A1: role escalation

    public function test_a_client_admin_cannot_make_a_team_member_an_agent(): void
    {
        $account = $this->clientOn('starter');
        $owner = $this->member($account, 'admin');
        $user = $this->member($account, 'user');

        $this->actingAs($owner)->patchJson("/api/team/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role_names' => ['agent'],
        ])->assertStatus(422);

        $this->assertFalse($user->fresh()->hasRole('agent'));
        $roles = collect($this->actingAs($owner)->getJson('/api/team/roles')->assertOk()->json('data'))->pluck('name');
        $this->assertNotContains('agent', $roles);
        $this->assertNotContains('admin', $roles);
        $this->assertContains('sales', $roles);
    }

    public function test_a_social_marketer_cannot_promote_itself_or_deactivate_an_admin(): void
    {
        $account = $this->clientOn('meta_pro');
        $this->member($account, 'admin'); // owner
        $coAdmin = $this->member($account, 'admin');
        $marketer = $this->member($account, 'social_marketer');

        // 'user' carries permissions the marketer does not hold: not assignable by it.
        $this->actingAs($marketer)->patchJson("/api/team/users/{$marketer->id}", [
            'name' => $marketer->name, 'email' => $marketer->email, 'role_names' => ['social_marketer', 'user'],
        ])->assertStatus(422);

        $this->actingAs($marketer)->patchJson("/api/team/users/{$coAdmin->id}/toggle")->assertForbidden();
        $this->assertTrue((bool) $coAdmin->fresh()->is_active);
    }

    public function test_an_agent_role_on_a_client_account_reaches_no_other_tenant(): void
    {
        $account = $this->clientOn('starter');
        $rogue = $this->member($account, 'agent'); // e.g. granted before this fix
        $this->clientOn('crm', ['company_name' => 'Somebody Else']);

        $this->actingAs($rogue)->getJson('/api/admin/accounts')->assertForbidden();
        $this->actingAs($rogue)->getJson('/api/admin/quota-requests')->assertForbidden();
    }

    // ---------------------------------------------------------------- A3: deactivation / suspension

    public function test_deactivating_a_member_ends_its_open_sessions(): void
    {
        $account = $this->clientOn('starter');
        $owner = $this->member($account, 'admin');
        $user = $this->member($account, 'user');
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->patchJson("/api/team/users/{$user->id}/toggle")->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_a_token_of_a_suspended_account_stops_working(): void
    {
        $account = $this->clientOn('starter');
        $user = $this->member($account, 'admin');
        $token = $user->createToken('t')->plainTextToken;

        $account->forceFill(['status' => 'suspended'])->save();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- A4: WhatsApp setup

    public function test_a_plain_user_cannot_disconnect_whatsapp_but_can_see_the_status(): void
    {
        $account = $this->clientOn('starter');
        $user = $this->member($account, 'user');

        $this->actingAs($user)->postJson('/api/whatsapp/logout')->assertForbidden();
        $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])->assertForbidden();
        $this->actingAs($user)->getJson('/api/whatsapp/status')->assertOk();
    }

    // ---------------------------------------------------------------- A5: agent limits

    public function test_an_agent_cannot_edit_a_subscription_directly(): void
    {
        [, $agent, $client] = $this->agentWithClient();

        $this->actingAs($agent)->putJson("/api/admin/accounts/{$client->id}/subscription", [
            'engine_type' => 'qr', 'billing_model' => 'unlimited', 'price_paid' => 0, 'payment_mode' => 'cash',
            'starts_at' => now()->toDateString(), 'expires_at' => now()->addYears(5)->toDateString(),
        ])->assertForbidden();
    }

    public function test_an_agent_sells_a_plan_at_its_price_and_cannot_set_seats(): void
    {
        [, $agent] = $this->agentWithClient();
        $starter = Plan::where('slug', 'starter')->firstOrFail();

        $res = $this->actingAs($agent)->postJson('/api/admin/accounts', [
            'company_name' => 'Free Ride Co', 'admin_name' => 'Owner', 'admin_email' => 'free@example.com', 'admin_password' => 'secret-pass-1',
            'module_assignment' => 'both', 'plan_key' => 'starter', 'plan_total' => 0, 'payment_method' => 'cash',
            'starts_at' => now()->toDateString(), 'max_users_limit' => 999,
        ])->assertCreated();

        $account = Account::query()->where('company_name', 'Free Ride Co')->firstOrFail();
        $this->assertNull($account->max_users_limit);
        $this->assertEquals((float) $starter->price, (float) Invoice::where('account_id', $account->id)->value('total_amount'));
        $this->assertNotNull($res->json('id') ?? $res->json('data.id') ?? $account->id);
    }

    // ---------------------------------------------------------------- A6: add-on modules

    public function test_a_module_add_on_never_narrows_a_plan_clients_menu(): void
    {
        $account = $this->clientOn('meta_growth');
        $before = $account->effectiveModules();
        $this->assertContains('send_alert', $before);

        $service = app(ModuleAddonService::class);
        $switchOn = new \ReflectionMethod($service, 'switchOn');
        $switchOn->setAccessible(true);
        $switchOn->invoke($service, $account, 'contact_groups');

        $account = $account->fresh();
        $this->assertNull($account->allowed_modules);
        $this->assertEqualsCanonicalizing($before, $account->effectiveModules());
    }

    // ---------------------------------------------------------------- A7: quota top-up

    public function test_a_quota_top_up_is_credited_only_when_paid_and_never_blocks_a_plan(): void
    {
        $account = $this->clientOn('meta_growth'); // flat quota 5,000
        $owner = $this->member($account, 'admin');
        $superAdmin = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($owner)->postJson('/api/quota-requests/store', ['requested_extra_messages' => 1000, 'reason' => 'Campaign'])->assertSuccessful();
        $request = QuotaRequest::query()->where('account_id', $account->id)->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->actingAs($superAdmin)->postJson("/api/admin/quota-requests/{$request->id}/approve")->assertOk();
        $this->assertSame(5000, (int) $account->fresh()->currentSubscription->total_allocated_messages);

        $invoice = Invoice::query()->whereKey($request->fresh()->invoice_id)->firstOrFail();
        $this->assertNull(app(PlanChangePolicy::class)->state($account->fresh())['pending']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->postJson("/api/billing/invoices/{$invoice->id}/cancel")->assertStatus(409);

        $this->assertTrue(app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_topup'));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(6000, (int) $account->fresh()->currentSubscription->total_allocated_messages);
    }

    // ---------------------------------------------------------------- B1 / B2: login

    public function test_an_expired_client_and_an_agent_without_a_subscription_can_log_in(): void
    {
        $account = $this->clientOn('starter');
        $owner = User::factory()->create(['account_id' => $account->id, 'is_active' => true, 'password' => 'secret-pass-1']);
        $owner->assignRole('admin');
        $account->currentSubscription->forceFill(['expires_at' => now()->subDay()])->save();

        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'secret-pass-1'])->assertOk();

        $agentAccount = Account::factory()->create(['account_type' => 'agent']);
        $agent = User::factory()->create(['account_id' => $agentAccount->id, 'is_active' => true, 'password' => 'secret-pass-1']);
        $agent->assignRole(['admin', 'agent']);
        $this->postJson('/api/auth/login', ['email' => $agent->email, 'password' => 'secret-pass-1'])->assertOk();

        // The expired client stays read-only: a change is refused.
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->postJson('/api/chatbot/rules', ['keyword' => 'hi', 'response' => 'Hello'])->assertForbidden();
    }

    public function test_a_suspended_account_still_cannot_log_in(): void
    {
        $account = $this->clientOn('starter', ['status' => 'suspended']);
        $owner = User::factory()->create(['account_id' => $account->id, 'is_active' => true, 'password' => 'secret-pass-1']);
        $owner->assignRole('admin');

        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'secret-pass-1'])->assertForbidden();
    }

    // ---------------------------------------------------------------- B3: new term

    public function test_a_plan_bought_after_the_old_one_lapsed_starts_clean(): void
    {
        $account = $this->clientOn('starter');
        $account->currentSubscription->forceFill(['used_messages' => 20000, 'expires_at' => now()->subDay(), 'starts_at' => now()->subDays(31)])->save();

        $this->pay($account->fresh(), 'meta_growth');

        $sub = $account->fresh()->currentSubscription;
        $this->assertSame(0, (int) $sub->used_messages);
        $this->assertSame(5000, (int) $sub->total_allocated_messages);
        $this->assertTrue($sub->starts_at->isToday());
        $this->assertSame('active', $sub->refreshStatus());
    }

    // ---------------------------------------------------------------- plan line-up

    public function test_the_owner_plan_decisions_are_in_the_line_up(): void
    {
        $this->assertSame(10, (int) Plan::where('slug', 'meta_growth')->value('max_broadcasts_per_month'));
        $this->assertContains('ai', Plan::where('slug', 'meta_pro')->firstOrFail()->capabilities->pluck('slug')->all());

        $trial = Plan::where('slug', Plan::TRIAL_SLUG)->firstOrFail();
        $this->assertFalse((bool) $trial->is_public);
        $this->assertEqualsCanonicalizing(
            Plan::where('slug', 'starter')->firstOrFail()->capabilities->pluck('slug')->all(),
            $trial->capabilities->pluck('slug')->all(),
        );
        $this->assertNotContains(Plan::TRIAL_SLUG, app(\App\Services\Billing\PlanRepository::class)->purchasable()->pluck('slug')->all());
    }

    public function test_a_trial_gets_starter_features_only(): void
    {
        $account = $this->clientOn(Plan::TRIAL_SLUG);
        $modules = $account->effectiveModules();

        $this->assertContains('send_alert', $modules);
        $this->assertNotContains('lead_crm', $modules);
        $this->assertNotContains('meta_ads', $modules);
        $this->assertSame(2, $account->userLimit());

        // Buying Starter from the trial is an upgrade.
        $decision = app(PlanChangePolicy::class)->decide($account, Plan::where('slug', 'starter')->firstOrFail());
        $this->assertSame('upgrade', $decision['action']);
    }
}
