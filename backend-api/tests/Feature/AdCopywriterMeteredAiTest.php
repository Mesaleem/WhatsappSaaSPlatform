<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiOperation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\CreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 8 Task 6 — the AI Ad Copywriter (POST /api/social/ai/generate) is a
 * metered consumer of the central AI system:
 *
 *   controller → AiAuthorizer (target account, meta_ads, launch-meta-ads, ai)
 *              → CopywriterService → MeteredAiService → AiService → AiManager → provider
 *
 * Driven over real HTTP with the real OpenAI provider behind Http::fake, so
 * every assertion about vendor traffic is about what actually leaves the
 * app. Pricing: 1000 tokens / credit, minimum 1.
 */
class AdCopywriterMeteredAiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/social/ai/generate';

    private const OPENAI = 'https://api.openai.com/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config([
            'ai.default' => 'openai', 'ai.enabled' => ['openai', 'anthropic'], 'ai.retries' => 0,
            'ai.providers.openai.api_key' => 'sk-test-copywriter', 'ai.providers.openai.model' => 'gpt-test',
            'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum',
            // The old chain's Gemini sources: any of them must no longer produce a Gemini call.
            'services.gemini.key' => 'gemini-env-key',
        ]);
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(string $planKey = 'growth', array $attributes = [], int $credits = 100): Account
    {
        $account = Account::factory()->create($attributes);
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey,
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null, 'status' => 'pending',
            'paid_at' => null, 'gateway_raw_response' => null,
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        if ($credits > 0) {
            app(CreditService::class)->grant($account, $credits, 'test:grant:'.uniqid());
        }

        return $account->fresh();
    }

    private function user(?Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Sunrise Dental', 'target_industry' => 'Healthcare',
            'offer_details' => 'Free first check-up', 'target_goal' => 'PAID_LEAD_AD', 'tone' => 'Professional',
        ], $overrides);
    }

    /** @param list<array<string, string>>|null $variants */
    private function openAiAnswers(?array $variants = null, int $status = 200, int $in = 700, int $out = 600): void
    {
        $variants ??= array_map(fn ($i) => ['hook' => "Hook {$i}", 'caption' => "Caption {$i}", 'cta' => "CTA {$i}"], range(1, 5));

        Http::fake([
            'api.openai.com/*' => $status === 200
                ? Http::response(['model' => 'gpt-test', 'choices' => [['message' => ['content' => json_encode(['variants' => $variants])], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out]], 200)
                : Http::response(['error' => ['message' => 'upstream exploded: sk-test-copywriter']], $status),
            '*' => Http::response(['unexpected' => true], 500),
        ]);
    }

    private function vendorCalls(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->values()->all();
    }

    private function balance(Account $account): array
    {
        $b = app(CreditService::class)->balance($account);

        return [$b['balance'], $b['reserved']];
    }

    private function generate(User $user, array $query = [], array $headers = [], array $payload = [])
    {
        return $this->actingAs($user)->withHeaders($headers)->postJson(self::URL.($query ? '?'.http_build_query($query) : ''), $this->payload($payload));
    }

    // ==================================================================
    // Successful generation
    // ==================================================================

    public function test_generation_runs_through_the_metered_ai_path_and_keeps_the_response_contract(): void
    {
        $this->openAiAnswers();
        $account = $this->tenant();

        $response = $this->generate($this->user($account))->assertOk();

        $response->assertExactJson(['data' => [
            'provider' => 'openai',
            'variants' => array_map(fn ($i) => ['hook' => "Hook {$i}", 'caption' => "Caption {$i}", 'cta' => "CTA {$i}"], range(1, 5)),
        ]]);

        // Exactly one vendor call, through the provider layer, carrying the unchanged prompts.
        $this->assertSame([self::OPENAI], $this->vendorCalls(), 'no Gemini / other vendor traffic');
        Http::assertSent(function (HttpRequest $r) {
            $messages = collect($r['messages']);

            return $r['model'] === 'gpt-test' && ($r['response_format']['type'] ?? null) === 'json_object'
                && str_contains($messages->firstWhere('role', 'system')['content'], 'You are an expert Meta Ads copywriter')
                && str_contains($messages->last()['content'], 'Business/Product: "Sunrise Dental". Target industry: "Healthcare". Current offer or promotion: "Free first check-up". Tone: "Professional".')
                && str_contains($messages->last()['content'], 'PAID Meta Lead Ad');
        });

        // Credits: 700 + 600 tokens → 2 credits, from the tenant's own account, through the ledger.
        $this->assertSame([98, 0], $this->balance($account));
        $op = AiOperation::sole();
        $this->assertSame([$account->id, 'ads.copywriter', 'ads', 'settled', 2, 'openai'], [$op->account_id, $op->operation, $op->source, $op->status, $op->credits_charged, $op->provider]);
        $this->assertSame(1, CreditLedgerEntry::where('account_id', $account->id)->where('type', 'consumption')->count());
        $this->assertSame((int) $op->consumption_entry_id, (int) CreditLedgerEntry::where('type', 'consumption')->value('id'));
    }

    public function test_the_existing_variant_validation_is_unchanged(): void
    {
        // 6 variants, one incomplete: capped at 5 first, the incomplete one dropped.
        $this->openAiAnswers([
            ['hook' => 'A', 'caption' => 'a', 'cta' => '1'], ['hook' => 'B', 'caption' => 'b'],
            ['hook' => 'C', 'caption' => 'c', 'cta' => '3'], ['hook' => 'D', 'caption' => 'd', 'cta' => '4'],
            ['hook' => 'E', 'caption' => 'e', 'cta' => '5'], ['hook' => 'F', 'caption' => 'f', 'cta' => '6'],
        ]);

        $variants = $this->generate($this->user($this->tenant()))->assertOk()->json('data.variants');

        $this->assertSame(['A', 'C', 'D', 'E'], array_column($variants, 'hook'));
    }

    public function test_the_request_validation_is_unchanged(): void
    {
        Http::fake();
        $user = $this->user($this->tenant());

        $this->generate($user, payload: ['target_goal' => 'BILLBOARD'])->assertStatus(422)->assertJsonValidationErrors('target_goal');
        $this->generate($user, payload: ['business_name' => ''])->assertStatus(422)->assertJsonValidationErrors('business_name');
        Http::assertNothingSent();
        $this->assertSame(0, AiOperation::count());
    }

    public function test_no_prompt_or_copy_is_persisted(): void
    {
        $this->openAiAnswers();
        $this->generate($this->user($this->tenant()))->assertOk();

        $dump = json_encode([AiOperation::all()->toArray(), CreditLedgerEntry::all()->toArray(), CreditReservation::all()->toArray()]);
        foreach (['Sunrise Dental', 'Free first check-up', 'Hook 1', 'sk-test'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    // ==================================================================
    // Authorization: Ads permission + module AND the ai capability
    // ==================================================================

    public function test_missing_the_ads_permission_is_refused_before_any_ai_work(): void
    {
        Http::fake();

        $this->generate($this->user($this->tenant(), 'user'))->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, AiOperation::count());
    }

    public function test_the_ads_permission_alone_is_not_enough_without_the_ai_capability(): void
    {
        Http::fake();
        $starter = $this->tenant('starter'); // sells no `ai`, though the admin role holds launch-meta-ads

        $this->generate($this->user($starter))->assertStatus(403)->assertJsonPath('error_code', 'AI_CAPABILITY_UNAVAILABLE');

        Http::assertNothingSent();
        $this->assertSame([100, 0], $this->balance($starter));
    }

    public function test_the_meta_ads_module_is_enforced(): void
    {
        Http::fake();
        $account = $this->tenant('growth', ['allowed_modules' => ['dashboard', 'lead_crm']]);

        $this->generate($this->user($account))->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        Http::assertNothingSent();
    }

    public function test_a_social_marketer_with_the_ads_permission_is_allowed(): void
    {
        $this->openAiAnswers();

        $this->generate($this->user($this->tenant(), 'social_marketer'))->assertOk()->assertJsonPath('data.provider', 'openai');
    }

    // ==================================================================
    // Target account / who pays
    // ==================================================================

    public function test_a_super_admin_without_a_client_uses_its_own_platform_account_and_a_selected_client_pays_for_itself(): void
    {
        $this->openAiAnswers();
        $client = $this->tenant();
        $platform = $this->tenant('growth', ['account_type' => 'super_admin']);
        $superAdmin = $this->user(null, 'super_admin');

        // Owner decision (2026-09-30): no client selected = the Super Admin's own Platform account (it pays for itself).
        $this->generate($superAdmin)->assertOk();
        $this->assertSame([98, 0], $this->balance($platform));

        // A selected client pays for its own copy; the platform account is not charged for it.
        $this->generate($superAdmin, ['account_id' => $client->id])->assertOk();

        $this->assertSame([98, 0], $this->balance($client));
        $this->assertSame([98, 0], $this->balance($platform), 'the platform account is never charged for a client');
        $this->assertSame([$client->id, $superAdmin->id], [AiOperation::query()->latest('id')->first()->account_id, AiOperation::query()->latest('id')->first()->actor_user_id]);
    }

    public function test_an_agent_acting_for_its_sub_client_bills_the_sub_client_only(): void
    {
        $this->openAiAnswers();
        $agent = $this->tenant('growth', ['account_type' => 'agent']);
        $sub = $this->tenant('growth', ['agent_id' => $agent->id]);
        $stranger = $this->tenant();
        $agentUser = $this->user($agent);

        $this->generate($agentUser, ['account_id' => $sub->id])->assertOk();
        $this->assertSame([[98, 0], [100, 0]], [$this->balance($sub), $this->balance($agent)]);

        $this->generate($agentUser, ['account_id' => $stranger->id])->assertStatus(404);
        $this->assertSame([100, 0], $this->balance($stranger));
    }

    public function test_a_tenant_user_cannot_bill_another_account(): void
    {
        $this->openAiAnswers();
        $mine = $this->tenant();
        $theirs = $this->tenant();

        // A tenant's ?account_id= is ignored by tenant isolation: their own account pays.
        $this->generate($this->user($mine), ['account_id' => $theirs->id])->assertOk();

        $this->assertSame([[98, 0], [100, 0]], [$this->balance($mine), $this->balance($theirs)]);
    }

    // ==================================================================
    // Credits
    // ==================================================================

    public function test_insufficient_credits_stop_the_vendor_call(): void
    {
        $this->openAiAnswers();
        $account = $this->tenant('growth', [], 1); // the hold for this prompt is several credits
        $ledger = CreditLedgerEntry::count();

        $this->generate($this->user($account))->assertStatus(422)->assertJsonPath('error_code', 'INSUFFICIENT_CREDITS')->assertJsonPath('success', false);

        $this->assertSame([], $this->vendorCalls(), 'no vendor request');
        $this->assertSame($ledger, CreditLedgerEntry::count(), 'no partial debit');
        $this->assertSame([1, 0], $this->balance($account));
    }

    public function test_a_retry_with_the_same_idempotency_key_is_never_charged_twice(): void
    {
        $this->openAiAnswers();
        $account = $this->tenant();
        $user = $this->user($account);

        $this->generate($user, headers: ['Idempotency-Key' => 'adcopy-click-1'])->assertOk();
        $this->generate($user, headers: ['Idempotency-Key' => 'adcopy-click-1'])->assertStatus(409)->assertJsonPath('error_code', 'AI_OPERATION_DUPLICATE');

        $this->assertCount(1, $this->vendorCalls());
        $this->assertSame([98, 0], $this->balance($account));

        // A new click (new key) is a new generation.
        $this->generate($user, headers: ['Idempotency-Key' => 'adcopy-click-2'])->assertOk();
        $this->assertSame([96, 0], $this->balance($account));
    }

    public function test_a_duplicate_submission_without_a_key_is_charged_once(): void
    {
        $this->openAiAnswers();
        $this->freezeSecond();
        $account = $this->tenant();
        $user = $this->user($account);

        $this->generate($user)->assertOk();
        $this->generate($user)->assertStatus(409);
        $this->assertSame([98, 0], $this->balance($account));

        // Different input is a different operation; the same input later is too.
        $this->generate($user, payload: ['tone' => 'Urgent'])->assertOk();
        $this->travel(11)->seconds();
        $this->generate($user)->assertOk();
        $this->assertSame([94, 0], $this->balance($account));
    }

    public function test_two_users_of_one_account_never_collide(): void
    {
        $this->openAiAnswers();
        $this->freezeSecond();
        $account = $this->tenant();

        $this->generate($this->user($account), headers: ['Idempotency-Key' => 'k'])->assertOk();
        $this->generate($this->user($account), headers: ['Idempotency-Key' => 'k'])->assertOk();

        $this->assertSame([96, 0], $this->balance($account));
    }

    public function test_an_invalid_idempotency_key_is_refused(): void
    {
        Http::fake();

        $this->generate($this->user($this->tenant()), headers: ['Idempotency-Key' => str_repeat('x', 101)])->assertStatus(422);
        $this->generate($this->user($this->tenant()), headers: ['Idempotency-Key' => 'has spaces'])->assertStatus(422);
        Http::assertNothingSent();
    }

    // ==================================================================
    // Provider failure — the existing template fallback, never charged
    // ==================================================================

    public function test_a_provider_failure_still_answers_with_templates_and_charges_nothing(): void
    {
        $this->openAiAnswers(status: 500);
        $account = $this->tenant();

        $response = $this->generate($this->user($account))->assertOk();

        $this->assertSame('template', $response->json('data.provider'));
        $this->assertCount(5, $response->json('data.variants'));
        $this->assertSame('Introducing Sunrise Dental — Built for Healthcare Excellence.', $response->json('data.variants.0.hook'), 'the unchanged Professional template bank');
        $this->assertSame([100, 0], $this->balance($account), 'hold released, nothing consumed');
        $this->assertSame(['failed', 'AI_PROVIDER_FAILED'], [AiOperation::sole()->status, AiOperation::sole()->error_code]);
        $this->assertSame('released', CreditReservation::findOrFail(AiOperation::sole()->reservation_id)->status);
        $this->assertStringNotContainsString('sk-test', $response->getContent());
    }

    public function test_an_answer_without_a_usable_variant_is_not_charged(): void
    {
        $this->openAiAnswers([['hook' => 'only a hook']]);
        $account = $this->tenant();

        $this->assertSame('template', $this->generate($this->user($account))->assertOk()->json('data.provider'));
        $this->assertSame([100, 0], $this->balance($account));
        $this->assertSame('AI_MALFORMED_RESPONSE', AiOperation::sole()->error_code);
    }

    public function test_with_no_ai_provider_configured_the_template_engine_answers_uncharged(): void
    {
        Http::fake();
        config(['ai.default' => null]);
        $account = $this->tenant();

        $response = $this->generate($this->user($account), payload: ['target_goal' => 'ORGANIC_POST', 'tone' => 'Urgent'])->assertOk();

        $this->assertSame('template', $response->json('data.provider'));
        $this->assertSame('Comment Below', $response->json('data.variants.0.cta'), 'the unchanged organic CTA bank');
        Http::assertNothingSent();
        $this->assertSame(0, AiOperation::count());
        $this->assertSame([100, 0], $this->balance($account));
    }

    public function test_a_tenant_gemini_key_no_longer_causes_a_direct_gemini_call(): void
    {
        $this->openAiAnswers();
        $account = $this->tenant();
        $account->forceFill(['gemini_api_key' => 'tenant-gemini-key'])->save();

        $this->generate($this->user($account))->assertOk()->assertJsonPath('data.provider', 'openai');
        $this->assertSame([self::OPENAI], $this->vendorCalls());
    }

    // ==================================================================
    // Boundaries (static)
    // ==================================================================

    public function test_the_copywriter_knows_no_vendor_and_no_credit_ledger(): void
    {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents(app_path('Services/Ai/CopywriterService.php')));

        foreach (['Http::', 'config(', 'generativelanguage', 'api.openai.com', 'api.anthropic.com', 'Credit', 'reserve(', 'settle(', 'SocialProviderConfig', 'gemini_api_key'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "CopywriterService must not reference {$forbidden}");
        }
        $this->assertStringContainsString('MeteredAiService', $code);
        $this->assertStringNotContainsString('credits', strtolower($code), 'no copywriter-specific price');
    }
}
