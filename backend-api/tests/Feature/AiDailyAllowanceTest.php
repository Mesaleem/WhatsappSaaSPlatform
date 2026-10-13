<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiOperation;
use App\Models\Invoice;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\Billing\AiDailyAllowance;
use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\CreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Free daily AI credits (owner rule, 2026-10-13): the Super Admin sets N per day for clients and
 * for agents; they are used before the wallet and expire at the end of the day (never N+N).
 *
 * Pricing here: 1000 tokens per credit. A request with maxTokens 4000 holds 5; the fake answer
 * uses 2000 tokens = 2 credits.
 */
class AiDailyAllowanceTest extends TestCase
{
    use RefreshDatabase;

    private bool $providerDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake();

        config([
            'ai.default' => 'fake', 'ai.enabled' => ['fake'], 'ai.providers.fake' => ['model' => 'fake-model'],
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1,
            'ai.credits.tokens_per_credit_overrides' => [], 'ai.credits.missing_usage' => 'minimum',
        ]);

        $test = $this;
        app(AiManager::class)->extend('fake', fn () => new class($test) implements AiProvider {
            public function __construct(private readonly AiDailyAllowanceTest $test) {}
            public function name(): string { return 'fake'; }
            public function generateText(AiRequest $request): AiResponse { return $this->test->fakeAnswer(); }
            public function generateStructured(AiRequest $request): AiResponse { return $this->test->fakeAnswer()->withData(['ok' => true]); }
        });
    }

    /** @internal called by the fake provider */
    public function fakeAnswer(): AiResponse
    {
        if ($this->providerDown) {
            throw AiException::providerFailed('fake', new \RuntimeException('down'));
        }

        return new AiResponse('fake', 'fake-model', 'an answer', null, 1500, 500, 'stop');
    }

    private function tenant(array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
        $plan = PlanCatalog::find('growth');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth',
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null, 'status' => 'pending',
            'paid_at' => null, 'gateway_raw_response' => null,
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        app(CreditService::class)->grant($account, 100, 'test:grant:'.uniqid());

        return $account->fresh();
    }

    private function useAi(Account $account): int
    {
        $auth = app(AiAuthorizer::class)->forAccount($account, 'chatbot', 'automation');

        return app(MeteredAiService::class)->generateText($auth, new AiRequest(prompt: 'Summarize this lead.', maxTokens: 4000))->creditsCharged;
    }

    private function wallet(Account $account): int
    {
        return app(CreditService::class)->balance($account)['available'];
    }

    public function test_free_daily_credits_are_used_before_the_wallet(): void
    {
        PlatformSetting::put(PlatformSetting::AI_FREE_DAILY_CLIENT, '10');
        $account = $this->tenant();
        $before = $this->wallet($account);

        $this->assertSame(2, $this->useAi($account));

        $this->assertSame($before, $this->wallet($account), 'the wallet is untouched');
        $summary = app(AiDailyAllowance::class)->summary($account);
        $this->assertSame([10, 2, 8], [$summary['per_day'], $summary['used'], $summary['remaining']]);
        $operation = AiOperation::firstOrFail();
        $this->assertSame(AiOperation::STATUS_SETTLED, $operation->status);
        $this->assertNull($operation->reservation_id);
    }

    public function test_when_today_is_used_up_the_wallet_pays(): void
    {
        PlatformSetting::put(PlatformSetting::AI_FREE_DAILY_CLIENT, '6');
        $account = $this->tenant();
        $before = $this->wallet($account);

        $this->useAi($account); // free: 2 used, 4 left -- the next hold of 5 does not fit
        $this->useAi($account);

        $this->assertSame($before - 2, $this->wallet($account));
        $this->assertSame(2, app(AiDailyAllowance::class)->summary($account)['used']);
    }

    public function test_unused_credits_expire_and_never_add_up(): void
    {
        PlatformSetting::put(PlatformSetting::AI_FREE_DAILY_CLIENT, '10');
        $account = $this->tenant();
        $this->useAi($account);

        $this->travel(1)->days();

        $summary = app(AiDailyAllowance::class)->summary($account);
        $this->assertSame(10, $summary['remaining'], 'a new day starts at N, not N + what was left');
        $this->assertSame(0, $summary['used']);
    }

    public function test_a_failed_call_gives_the_free_hold_back(): void
    {
        PlatformSetting::put(PlatformSetting::AI_FREE_DAILY_CLIENT, '10');
        $account = $this->tenant();
        $this->providerDown = true;

        try {
            $this->useAi($account);
            $this->fail('Expected the provider failure.');
        } catch (AiException) {
        }

        $this->assertSame(0, app(AiDailyAllowance::class)->summary($account)['used']);
    }

    public function test_agents_get_the_agent_amount_and_clients_the_client_amount(): void
    {
        PlatformSetting::put(PlatformSetting::AI_FREE_DAILY_CLIENT, '3');
        PlatformSetting::put(PlatformSetting::AI_FREE_DAILY_AGENT, '20');
        $daily = app(AiDailyAllowance::class);

        $this->assertSame(3, $daily->perDayFor(Account::factory()->create()));
        $this->assertSame(20, $daily->perDayFor(Account::factory()->agent()->create()));
    }

    public function test_only_the_super_admin_sets_the_amounts_and_users_see_their_own_credits(): void
    {
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();
        $client = User::factory()->create(['account_id' => $account->id]);
        $client->assignRole('admin');

        $this->actingAs($client)->putJson('/api/admin/ai/free-daily-credits', ['client_per_day' => 99, 'agent_per_day' => 99])->assertForbidden();

        $this->actingAs($superAdmin)->putJson('/api/admin/ai/free-daily-credits', ['client_per_day' => 7, 'agent_per_day' => 25])->assertOk();
        $this->actingAs($superAdmin)->getJson('/api/admin/ai/free-daily-credits')
            ->assertJsonPath('data.client_per_day', 7)->assertJsonPath('data.agent_per_day', 25);
        $this->actingAs($superAdmin)->getJson('/api/ai/credit-status')->assertJsonPath('data.unlimited', true);

        $this->actingAs($client)->getJson('/api/ai/credit-status')
            ->assertOk()
            ->assertJsonPath('data.unlimited', false)
            ->assertJsonPath('data.daily.per_day', 7)
            ->assertJsonPath('data.daily.remaining', 7)
            ->assertJsonPath('data.wallet_available', $this->wallet($account));
    }
}
