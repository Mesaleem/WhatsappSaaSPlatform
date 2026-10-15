<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan restructure (owner, 2026-10-15), step 1: the new line-up (Starter,
 * CRM, Meta Growth, Meta Pro; Growth/Business retired), plan limits copied
 * to the subscription, menu modules that follow the plan (no Route Master
 * checklist), the plan's user limit, and custom (private) plans.
 */
class PlanRestructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_15_100000_restructure_plans_and_limits.php');
    }

    /** A client account that has paid for $plan (offline invoice, the real crediting path). */
    private function clientOn(string $plan, array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes + ['account_type' => 'client']);
        $this->pay($account, $plan);

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

        return $invoice;
    }

    private function admin(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    // ---- the line-up ----------------------------------------------------------------------------

    public function test_the_migration_sets_up_the_new_line_up_on_an_installation(): void
    {
        Plan::whereIn('slug', ['crm', 'meta_growth', 'meta_pro'])->delete();
        $growthClient = $this->clientOn('growth', ['allowed_modules' => ['dashboard', 'send_alert']]);
        // As before this release: no client's menu followed its plan yet, and its checklist was in place
        // (since the 2026-10-14 QA fixes, paying for a plan clears it — restored here to model the old data).
        DB::table('accounts')->update(['modules_from_plan' => false, 'legacy_allowed_modules' => null]);
        DB::table('accounts')->where('id', $growthClient->id)->update(['allowed_modules' => json_encode(['dashboard', 'send_alert'])]);
        $trial = Account::factory()->create(['account_type' => 'client', 'allowed_modules' => null]);

        $this->migration()->applyLineUp();
        $this->migration()->applyLineUp(); // idempotent

        $this->assertFalse((bool) Plan::where('slug', 'growth')->value('is_active'));
        $this->assertFalse((bool) Plan::where('slug', 'business')->value('is_active'));
        $this->assertSame(2, Plan::where('slug', 'starter')->value('max_users'));

        $crm = Plan::with('capabilities')->where('slug', 'crm')->sole();
        $this->assertSame(['none', 899.0, ['crm'], 3], [$crm->engine_type, (float) $crm->price, $crm->capabilities->pluck('slug')->all(), $crm->max_users]);

        $growth = Plan::with('capabilities')->where('slug', 'meta_growth')->sole();
        $this->assertSame([4999.0, 5000, 2500, 10, 3, 0], [(float) $growth->price, $growth->total_allocated_messages, $growth->marketing_message_limit, $growth->max_meta_templates, $growth->max_flows, $growth->max_broadcasts_per_month]);
        $this->assertEqualsCanonicalizing(['whatsapp_send', 'journey_automation', 'external_api'], $growth->capabilities->pluck('slug')->all());

        $pro = Plan::with('capabilities')->where('slug', 'meta_pro')->sole();
        $this->assertSame([7999.0, 10000, 4000, 30, 10, 20, 5], [(float) $pro->price, $pro->total_allocated_messages, $pro->marketing_message_limit, $pro->max_meta_templates, $pro->max_flows, $pro->max_broadcasts_per_month, $pro->max_users]);
        $this->assertEqualsCanonicalizing(['whatsapp_send', 'journey_automation', 'crm', 'ads', 'social', 'external_api'], $pro->capabilities->pluck('slug')->all());

        // A paying client's menu now follows its plan; the old checklist is kept aside.
        $growthClient->refresh();
        $this->assertTrue($growthClient->modules_from_plan);
        $this->assertNull($growthClient->allowed_modules);
        $this->assertNotNull($growthClient->legacy_allowed_modules);
        // A trial (no paid plan) is untouched.
        $this->assertFalse($trial->fresh()->modules_from_plan);
    }

    public function test_a_retired_plan_is_not_sold_but_its_clients_are_still_credited(): void
    {
        Plan::whereIn('slug', ['growth', 'business'])->update(['is_active' => false]);
        $client = $this->clientOn('starter');

        $this->actingAs($this->admin($client))->getJson('/api/billing/plans')->assertOk()
            ->assertJsonMissing(['key' => 'growth'])->assertJsonFragment(['key' => 'meta_pro']);
        $this->actingAs($this->admin($client))->postJson('/api/billing/manual-checkout', ['plan_key' => 'business'])->assertStatus(422);

        // An order placed before the retirement is still fulfilled.
        $other = Account::factory()->create(['account_type' => 'client']);
        $this->pay($other, 'growth');
        $this->assertSame('active', $other->fresh()->currentSubscription->status);
    }

    public function test_a_client_on_a_retired_plan_buying_a_cheaper_plan_waits_for_the_end(): void
    {
        $client = $this->clientOn('growth');
        Plan::where('slug', 'growth')->update(['is_active' => false]);

        $this->actingAs($this->admin($client))->postJson('/api/billing/manual-checkout', ['plan_key' => 'starter'])
            ->assertStatus(409)->assertJsonPath('error_code', 'downgrade_after_expiry');
        $this->actingAs($this->admin($client))->postJson('/api/billing/manual-checkout', ['plan_key' => 'meta_pro'])->assertCreated();
    }

    // ---- limits & modules -------------------------------------------------------------------------

    public function test_a_paid_plan_copies_its_limits_to_the_subscription(): void
    {
        $client = $this->clientOn('meta_pro');
        $sub = $client->currentSubscription;

        $this->assertSame([5, 30, 10, 20, 4000, 0], [$sub->max_users, $sub->max_meta_templates, $sub->max_flows, $sub->max_broadcasts_per_month, $sub->marketing_message_limit, $sub->used_marketing_messages]);

        // A later plan replaces the limits (not stacked) — the plan row's change after ordering does not leak in.
        $invoice = new Invoice(['account_id' => $client->id, 'invoice_number' => 'INV-x', 'plan_key' => 'meta_growth', 'plan_label' => 'Meta Growth', 'amount' => 1, 'tax_amount' => 0, 'total_amount' => 1, 'currency' => 'INR', 'payment_gateway' => 'manual', 'status' => 'pending']);
        $invoice->capturePlanTerms(Plan::where('slug', 'meta_growth')->sole())->save();
        Plan::where('slug', 'meta_growth')->update(['max_flows' => 99]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_y');

        // Meta Growth: 10 broadcasts a month (owner, 2026-10-14 QA fixes).
        $this->assertSame([3, 10, 3, 10, 2500], [$client->fresh()->currentSubscription->max_users, $client->fresh()->currentSubscription->max_meta_templates, $client->fresh()->currentSubscription->max_flows, $client->fresh()->currentSubscription->max_broadcasts_per_month, $client->fresh()->currentSubscription->marketing_message_limit]);
    }

    public function test_the_menu_follows_the_plan_and_a_module_outside_it_is_a_plan_refusal(): void
    {
        $crm = $this->clientOn('crm');

        $this->assertTrue($crm->modules_from_plan);
        $modules = $crm->effectiveModules();
        $this->assertContains('lead_crm', $modules);
        $this->assertNotContains('send_alert', $modules);
        $this->assertNotContains('whatsapp_setup', $modules);
        $this->assertNotContains('meta_ads', $modules);

        $user = $this->admin($crm);
        $this->actingAs($user)->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('user.account.modules_from_plan', true)
            ->assertJsonPath('user.account.user_limit', 3);

        // WhatsApp setup is not in a CRM-only plan: refused as "not in your plan", not "disabled by the Super Admin".
        $this->actingAs($user)->getJson('/api/whatsapp/status')
            ->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
    }

    public function test_upgrading_opens_the_new_plans_modules_automatically(): void
    {
        $client = $this->clientOn('starter');
        $this->assertNotContains('lead_crm', $client->effectiveModules());

        $this->pay($client, 'meta_pro');

        $modules = $client->fresh()->effectiveModules();
        foreach (['lead_crm', 'meta_ads', 'social_accounts', 'chatbot', 'send_alert', 'developer_api'] as $module) {
            $this->assertContains($module, $modules);
        }
    }

    public function test_an_explicit_switch_off_still_narrows_the_plan(): void
    {
        $client = $this->clientOn('meta_pro');
        $client->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['meta_ads']))])->save();

        $this->assertSame('disabled', $client->fresh()->moduleDenial('meta_ads'));
        $this->assertNull($client->fresh()->moduleDenial('lead_crm'));
    }

    public function test_a_trial_without_a_paid_plan_keeps_every_module(): void
    {
        $trial = Account::factory()->create(['account_type' => 'client', 'allowed_modules' => null]);

        $this->assertFalse($trial->fresh()->modules_from_plan);
        $this->assertContains('meta_ads', $trial->fresh()->effectiveModules());
    }

    // ---- users ---------------------------------------------------------------------------------

    public function test_the_plan_limits_team_users_and_an_account_override_wins(): void
    {
        $client = $this->clientOn('crm'); // 3 users
        $owner = $this->admin($client);
        User::factory()->count(2)->create(['account_id' => $client->id]);

        $this->actingAs($owner)->postJson('/api/team/users', ['name' => 'Ravi', 'email' => 'ravi@acme.test', 'password' => 'secret-pass', 'role_names' => ['user']])
            ->assertForbidden()->assertJsonPath('error_code', 'PLAN_LIMIT_REACHED');

        $client->forceFill(['max_users_limit' => 4])->save();
        $this->actingAs($owner)->postJson('/api/team/users', ['name' => 'Ravi', 'email' => 'ravi@acme.test', 'password' => 'secret-pass', 'role_names' => ['user']])
            ->assertCreated();
    }

    // ---- custom plans --------------------------------------------------------------------------

    public function test_a_custom_plan_is_seen_and_bought_only_by_its_client(): void
    {
        $mine = $this->clientOn('starter');
        $other = $this->clientOn('starter');
        Plan::create(['slug' => 'custom-acme', 'label' => 'Acme custom', 'price' => 2500, 'duration_days' => 30, 'engine_type' => 'qr', 'billing_model' => 'unlimited', 'is_active' => true, 'is_public' => false, 'account_id' => $mine->id]);

        $this->getJson('/api/site/plans')->assertJsonMissing(['key' => 'custom-acme']);
        $this->actingAs($this->admin($other))->getJson('/api/billing/plans')->assertJsonMissing(['key' => 'custom-acme']);
        $this->actingAs($this->admin($other))->postJson('/api/billing/manual-checkout', ['plan_key' => 'custom-acme'])->assertStatus(422);

        $this->actingAs($this->admin($mine))->getJson('/api/billing/plans')->assertJsonFragment(['key' => 'custom-acme', 'is_custom' => true]);
    }

    public function test_the_super_admin_builds_and_invoices_a_custom_plan_for_one_client(): void
    {
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $client = $this->clientOn('starter');
        Plan::where('slug', 'starter')->update(['is_active' => true]);

        $this->actingAs($superAdmin)->postJson('/api/admin/plans-management', [
            'slug' => 'custom-acme', 'label' => 'Acme CRM + journeys', 'price' => 1500, 'duration_days' => 30,
            'engine_type' => 'none', 'billing_model' => 'unlimited', 'account_id' => $client->id, 'is_public' => true,
            'capabilities' => ['crm', 'journey_automation'],
            'max_users' => 4, 'max_flows' => 2, 'max_broadcasts_per_month' => 0,
        ])->assertCreated();

        $plan = Plan::where('slug', 'custom-acme')->sole();
        $this->assertSame([$client->id, false, 4, 2, 0, null], [$plan->account_id, $plan->is_public, $plan->max_users, $plan->max_flows, $plan->max_broadcasts_per_month, $plan->max_meta_templates]);

        // Listed for the Super Admin with its client; limits editable later.
        $this->actingAs($superAdmin)->getJson('/api/admin/plans-management')->assertOk()
            ->assertJsonFragment(['slug' => 'custom-acme', 'is_public' => false])
            ->assertJsonFragment(['account' => ['id' => $client->id, 'company_name' => $client->company_name]]);
        $this->actingAs($superAdmin)->putJson('/api/admin/plans-management/custom-acme', ['max_flows' => 5])->assertOk();
        $this->assertSame(5, $plan->fresh()->max_flows);

        // The Super Admin issues the client's invoice for it; paying it applies the plan.
        $client->currentSubscription->forceFill(['expires_at' => now()->subDay(), 'status' => 'expired'])->save();
        $invoiceId = $this->actingAs($superAdmin)->postJson('/api/billing/manual-checkout?account_id='.$client->id, ['plan_key' => 'custom-acme'])
            ->assertCreated()->json('invoice.id');
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoiceId, 'pay_custom');

        $client->refresh();
        $this->assertSame(['none', 4, 5], [$client->currentSubscription->engine_type, $client->userLimit(), $client->currentSubscription->max_flows]);
        $modules = $client->effectiveModules();
        $this->assertContains('lead_crm', $modules);
        $this->assertContains('chatbot', $modules);
        $this->assertNotContains('meta_ads', $modules);
    }

    public function test_plan_management_validates_the_new_fields(): void
    {
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $agent = Account::factory()->create(['account_type' => 'agent']);

        $this->actingAs($superAdmin)->postJson('/api/admin/plans-management', [
            'slug' => 'bad', 'label' => 'Bad', 'price' => 1, 'duration_days' => 30, 'engine_type' => 'none', 'billing_model' => 'unlimited',
            'max_users' => 0, 'max_flows' => -1, 'account_id' => $agent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['max_users', 'max_flows', 'account_id']);
    }

    public function test_the_crm_plan_grants_crm_without_a_whatsapp_engine(): void
    {
        $client = $this->clientOn('crm');

        $this->assertSame('none', $client->currentSubscription->engine_type);
        $this->assertTrue(DB::table('account_entitlements')->where('account_id', $client->id)->whereNull('revoked_at')
            ->where('capability_id', DB::table('capabilities')->where('slug', 'crm')->value('id'))->exists());
    }
}
