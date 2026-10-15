<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Billing\PlanLimitException;
use App\Services\Billing\PlanLimits;
use App\Services\Messaging\MessageQuotaService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan restructure (owner, 2026-10-15), steps 2 and 3: template / journey /
 * monthly broadcast limits, and the Meta marketing-message cap (only
 * MARKETING templates are refused when it is reached).
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function clientOn(string $slug): Account
    {
        $account = Account::factory()->create(['account_type' => 'client']);
        $plan = Plan::where('slug', $slug)->firstOrFail();
        $invoice = new Invoice([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $slug, 'plan_label' => $plan->label,
            'amount' => $plan->price, 'tax_amount' => 0, 'total_amount' => $plan->price, 'currency' => 'INR',
            'payment_gateway' => 'manual', 'status' => 'pending',
        ]);
        $invoice->capturePlanTerms($plan)->save();
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return $account->fresh();
    }

    private function limits(): PlanLimits
    {
        return app(PlanLimits::class);
    }

    private function template(Account $account, string $status = 'approved', string $category = 'UTILITY'): MessageTemplate
    {
        return MessageTemplate::query()->forceCreate([
            'account_id' => $account->id, 'title' => 'T'.uniqid(), 'template_code' => 'T'.uniqid(), 'template_body' => 'Hi',
            'status' => $status, 'category' => $category,
        ]);
    }

    // ---- templates / journeys ------------------------------------------------------------

    public function test_the_template_limit_counts_live_templates_only(): void
    {
        $account = $this->clientOn('meta_growth'); // 10 templates
        foreach (range(1, 9) as $i) {
            $this->template($account);
        }
        $this->template($account, 'rejected');
        $this->limits()->assertCanAddTemplate($account); // 9 live + 1 rejected: one more fits

        $this->template($account, 'pending_admin_review');
        $this->expectException(PlanLimitException::class);
        $this->limits()->assertCanAddTemplate($account->fresh());
    }

    public function test_the_journey_limit_and_a_plan_without_journeys(): void
    {
        $account = $this->clientOn('meta_growth'); // 3 journeys
        foreach (range(1, 3) as $i) {
            WhatsAppFlow::query()->forceCreate(['account_id' => $account->id, 'name' => "J{$i}", 'trigger_type' => 'keyword', 'trigger_value' => "k{$i}", 'graph_data' => ['nodes' => [], 'edges' => []]]);
        }

        try {
            $this->limits()->assertCanAddFlow($account);
            $this->fail('A fourth journey must be refused.');
        } catch (PlanLimitException $e) {
            $this->assertSame(['max_flows', 3], [$e->limit, $e->allowed]);
            $this->assertSame(403, $e->render()->getStatusCode());
            $this->assertSame('PLAN_LIMIT_REACHED', $e->render()->getData(true)['error_code']);
        }

        $crm = $this->clientOn('crm'); // 0 journeys
        $this->expectExceptionMessage('does not include journeys');
        $this->limits()->assertCanAddFlow($crm);
    }

    public function test_a_super_admin_and_an_unlimited_plan_are_not_limited(): void
    {
        $account = $this->clientOn('crm');
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');

        $this->limits()->assertCanAddFlow($account, $superAdmin);
        $this->limits()->consumeBroadcast($account, 'test', $superAdmin);

        $starter = $this->clientOn('starter'); // QR: templates, journeys and broadcasts unlimited
        $this->limits()->assertCanAddTemplate($starter);
        foreach (range(1, 30) as $i) {
            $this->limits()->consumeBroadcast($starter, 'test');
        }
        $this->assertSame(0, DB::table('plan_usage_events')->where('account_id', $account->id)->count());
    }

    // ---- broadcasts ------------------------------------------------------------------------

    public function test_broadcasts_are_counted_per_month_of_the_term(): void
    {
        $account = $this->clientOn('meta_pro'); // 20 a month
        foreach (range(1, 20) as $i) {
            $this->limits()->consumeBroadcast($account, 'message_batch:file');
        }

        try {
            $this->limits()->consumeBroadcast($account, 'message_batch:file');
            $this->fail('The 21st broadcast this month must be refused.');
        } catch (PlanLimitException $e) {
            $this->assertSame('max_broadcasts_per_month', $e->limit);
        }
        $this->assertSame(['limit' => 20, 'used' => 20], array_intersect_key($this->limits()->broadcastUsage($account), ['limit' => 0, 'used' => 0]));

        // The next 30-day slice of the term starts the count again.
        $this->travel(31)->days();
        $this->limits()->consumeBroadcast($account->fresh(), 'message_batch:file');
        $this->assertSame(1, $this->limits()->broadcastUsage($account->fresh())['used']);
    }

    public function test_a_plan_without_broadcasts_refuses_the_first_one(): void
    {
        // Meta Growth has 10 a month since the 2026-10-14 QA fixes; the CRM plan has none.
        $account = $this->clientOn('crm');

        $this->expectExceptionMessage('does not include broadcasts');
        $this->limits()->consumeBroadcast($account, 'template_bulk');
    }

    public function test_billing_shows_each_limit_and_its_use(): void
    {
        $account = $this->clientOn('meta_pro');
        $this->limits()->consumeBroadcast($account, 'contact_group');
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        $this->actingAs($user)->getJson('/api/billing/plans')->assertOk()
            ->assertJsonPath('plan_usage.max_broadcasts_per_month.limit', 20)
            ->assertJsonPath('plan_usage.max_broadcasts_per_month.used', 1)
            ->assertJsonPath('plan_usage.max_users.limit', 5)
            ->assertJsonPath('plan_usage.marketing_message_limit.limit', 4000);
    }

    // ---- marketing cap ---------------------------------------------------------------------

    public function test_marketing_messages_are_capped_and_others_are_not(): void
    {
        $account = $this->clientOn('meta_growth');
        $subscription = $account->currentSubscription;
        $subscription->forceFill(['marketing_message_limit' => 3])->save();
        $quota = app(MessageQuotaService::class);

        $this->assertTrue($quota->reserveMarketing($subscription->fresh(), 2));
        $this->assertFalse($quota->reserveMarketing($subscription->fresh(), 2), 'only 1 left');
        $this->assertTrue($quota->reserveMarketing($subscription->fresh(), 1));
        $this->assertFalse($quota->reserveMarketing($subscription->fresh(), 1));
        $this->assertSame(3, $subscription->fresh()->used_marketing_messages);

        // A send that failed gives its marketing message back.
        $quota->releaseMarketing($subscription->fresh(), 1);
        $this->assertTrue($quota->reserveMarketing($subscription->fresh(), 1));

        $this->assertTrue(MessageQuotaService::isMarketing('marketing'));
        $this->assertFalse(MessageQuotaService::isMarketing('UTILITY'));
        $this->assertFalse(MessageQuotaService::isMarketing(null));
        $this->assertStringContainsString('Utility, OTP and reply messages still go out', MessageQuotaService::marketingLimitMessage($subscription));
    }

    public function test_the_marketing_count_starts_again_each_month_of_the_term(): void
    {
        $account = $this->clientOn('meta_growth');
        $subscription = $account->currentSubscription;
        $subscription->forceFill(['marketing_message_limit' => 1])->save();
        $quota = app(MessageQuotaService::class);

        $this->assertTrue($quota->reserveMarketing($subscription->fresh(), 1));
        $this->assertFalse($quota->reserveMarketing($subscription->fresh(), 1));

        $this->travel(31)->days();
        $this->assertSame(0, PlanLimits::marketingUsed($subscription->fresh()));
        $this->assertTrue($quota->reserveMarketing($subscription->fresh(), 1));
    }

    public function test_a_plan_without_a_marketing_cap_counts_nothing(): void
    {
        $account = $this->clientOn('starter');
        $quota = app(MessageQuotaService::class);

        $this->assertTrue($quota->reserveMarketing($account->currentSubscription, 500));
        $this->assertSame(0, $account->currentSubscription->fresh()->used_marketing_messages);
    }
}
