<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiOperation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Ai\AiAuthorization;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiService;
use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Providers\GroqProvider;
use App\Services\Ai\Providers\HttpAiProvider;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Credits\CreditService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 8 Task 12 — Groq as a first-class provider under the existing AI
 * abstraction (AiManager -> GroqProvider), used by the existing consumers
 * (MeteredAiService, Journey AI nodes, Ad Copywriter) with no Groq-specific
 * code outside the provider. Mirrors GeminiProviderTest's structure and
 * depth (the established convention for a dedicated "add a provider" task);
 * the request/response shape mirrored is OpenAI's (api.openai.com ->
 * api.groq.com/openai/v1, Bearer auth, choices[0].message.content,
 * usage.prompt_tokens/completion_tokens) because Groq's chat-completions
 * API is OpenAI-compatible -- see GroqProvider's and AiFoundationTest's
 * test_openai_text_is_sent_and_normalized() for the shape this was checked
 * against.
 *
 * Every vendor call is faked at the HTTP boundary -- no Groq key is needed.
 * The optional live smoke test at the bottom is skipped unless
 * GROQ_LIVE_SMOKE=1 and a real GROQ_API_KEY are set in the environment.
 *
 * UNVERIFIED: written without a reachable PHP/phpunit runtime in the
 * authoring session -- run `php artisan test --filter=GroqProviderTest`
 * before trusting this file; see Phase 8 Task 12's row in PROJECT_STATE.md.
 *
 * Pricing: 1000 tokens per credit, minimum 1 (same defaults as every other
 * provider test -- Groq is not given a tokens_per_credit_overrides entry
 * here, matching production's empty default).
 */
class GroqProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'gsk-test-SECRET-never-shown';

    private const PROMPT = 'PRIVATE-PROMPT-customer Priya owes 5000';

    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config([
            'ai.default' => 'groq',
            'ai.enabled' => ['openai', 'anthropic', 'gemini', 'groq'],
            'ai.retries' => 0,
            'ai.providers.groq.api_key' => self::SECRET,
            'ai.providers.groq.model' => 'groq-test',
            // pinned, so a host's own GROQ_* environment cannot change the asserted URL
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1,
            'ai.credits.tokens_per_credit_overrides' => [], 'ai.credits.missing_usage' => 'minimum',
        ]);
        app(AiManager::class)->flush();
    }

    // ------------------------------------------------------------------ fixtures

    /** @param array<string, mixed>|null $usage */
    private function answer(string $content = 'Hello there', ?array $usage = ['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18], string $finishReason = 'stop', array $extra = []): array
    {
        return array_filter(array_merge([
            'id' => 'chatcmpl-test', 'object' => 'chat.completion', 'model' => 'groq-test-2026',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finishReason]],
            'usage' => $usage,
        ], $extra), fn ($v) => $v !== null);
    }

    /** @var array{0: mixed, 1: int}|null the next faked vendor answer (one fake per test; Http::fake stubs stack) */
    private ?array $next = null;

    private function groqReturns(mixed $body, int $status = 200): void
    {
        if ($this->next === null) {
            Http::fake(fn (HttpRequest $r) => str_contains($r->url(), 'api.groq.com')
                ? Http::response($this->next[0], $this->next[1])
                : Http::response(['unexpected' => true], 500));
        }

        $this->next = [$body, $status];
    }

    private function tenant(int $credits = 100, string $planKey = 'growth', array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey,
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        if ($credits > 0) {
            app(CreditService::class)->grant($account->fresh(), $credits, 'test:grant:'.uniqid());
        }

        return $account->fresh();
    }

    private function auth(Account $account): AiAuthorization
    {
        return app(AiAuthorizer::class)->forAccount($account, 'chatbot', 'automation');
    }

    private function provider(): GroqProvider
    {
        return app(AiManager::class)->provider('groq');
    }

    private function request(array $overrides = []): AiRequest
    {
        return new AiRequest(...array_merge(['prompt' => self::PROMPT, 'system' => 'Be brief.'], $overrides));
    }

    private function assertAiError(string $code, callable $fn): AiException
    {
        try {
            $fn();
        } catch (AiException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }

        $this->fail("Expected AiException {$code}.");
    }

    private function balance(Account $account): array
    {
        $b = app(CreditService::class)->balance($account->fresh());

        return [$b['balance'], $b['reserved']];
    }

    // ==================================================================
    // 1. Contract and resolution
    // ==================================================================

    public function test_groq_implements_the_provider_contract_through_the_shared_http_base(): void
    {
        $provider = $this->provider();

        $this->assertInstanceOf(AiProvider::class, $provider);
        $this->assertInstanceOf(HttpAiProvider::class, $provider);
        $this->assertSame('groq', $provider->name());
    }

    public function test_the_manager_resolves_groq_from_configuration_and_fails_safely_otherwise(): void
    {
        $this->assertSame('groq', app(AiManager::class)->provider()->name(), 'AI_PROVIDER=groq selects it');
        $this->assertTrue(app(AiService::class)->available('groq'));

        config(['ai.providers.groq.api_key' => null]);
        app(AiManager::class)->flush();
        $this->assertAiError(AiException::PROVIDER_NOT_CONFIGURED, fn () => app(AiManager::class)->provider('groq'));

        config(['ai.providers.groq.api_key' => self::SECRET, 'ai.enabled' => ['openai']]);
        app(AiManager::class)->flush();
        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, fn () => app(AiManager::class)->provider('groq'));

        config(['ai.default' => null]);
        $this->assertAiError(AiException::PROVIDER_NOT_CONFIGURED, fn () => app(AiManager::class)->provider(), 'no provider configured: unchanged, AI off');
    }

    public function test_groq_has_no_embedding_support(): void
    {
        config(['ai.providers.groq.api_key' => self::SECRET, 'ai.enabled' => ['groq']]);
        app(AiManager::class)->flush();

        $this->assertAiError(AiException::EMBEDDINGS_UNSUPPORTED, fn () => app(AiManager::class)->embeddingProvider('groq'));
    }

    public function test_the_configuration_reads_the_key_from_the_environment_only(): void
    {
        $config = file_get_contents(config_path('ai.php'));

        $this->assertMatchesRegularExpression("/'groq' => \\[\\s*'api_key' => env\\('GROQ_API_KEY'\\)/", $config);
        $this->assertStringContainsString("env('AI_GROQ_MODEL'", $config);
        $this->assertStringContainsString("'openai,anthropic,gemini,groq'", $config, 'enabled by default; still needs a key and AI_PROVIDER');

        $source = file_get_contents(app_path('Services/Ai/Providers/GroqProvider.php'));
        foreach (['env(', 'gemini_api_key', 'SocialProviderConfig', 'Account', 'Credit', 'Log::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "GroqProvider must not use {$forbidden}");
        }

        foreach (['GROQ_API_KEY=', 'AI_GROQ_MODEL=', 'GROQ_BASE_URL=', 'AI_ENABLED_PROVIDERS=openai,anthropic,gemini,groq'] as $documented) {
            $this->assertStringContainsString($documented, file_get_contents(base_path('.env.example')));
        }
    }

    // ==================================================================
    // 2. Text
    // ==================================================================

    public function test_text_is_sent_in_the_openai_compatible_format_and_normalized(): void
    {
        $this->groqReturns($this->answer('Hello there'));

        $response = $this->provider()->generateText($this->request(['maxTokens' => 300, 'temperature' => 0.2]));

        $this->assertSame(['groq', 'groq-test-2026', 'Hello there', null, 11, 7, 'stop'], [
            $response->provider, $response->model, $response->text, $response->data, $response->inputTokens, $response->outputTokens, $response->finishReason,
        ]);

        Http::assertSent(function (HttpRequest $r) {
            return $r->url() === self::URL
                && $r->method() === 'POST'
                && $r->hasHeader('Authorization', 'Bearer '.self::SECRET)
                && $r['model'] === 'groq-test' && $r['max_tokens'] === 300 && $r['temperature'] === 0.2
                && $r['messages'][0] === ['role' => 'system', 'content' => 'Be brief.']
                && end($r->data()['messages']) === ['role' => 'user', 'content' => self::PROMPT]
                && ! isset($r['response_format']);
        });
    }

    public function test_a_missing_model_is_a_controlled_error(): void
    {
        config(['ai.providers.groq.model' => null]);
        app(AiManager::class)->flush();

        $this->assertAiError(AiException::PROVIDER_NOT_CONFIGURED, fn () => $this->provider()->generateText($this->request()));
        Http::fake();
        Http::assertNothingSent();
    }

    // ==================================================================
    // 3. Structured
    // ==================================================================

    public function test_structured_generation_uses_json_mode_and_the_shared_validation(): void
    {
        $this->groqReturns($this->answer(json_encode(['variants' => [['hook' => 'H']]])));

        $response = $this->provider()->generateStructured($this->request(['requiredKeys' => ['variants']]));

        $this->assertSame(['variants' => [['hook' => 'H']]], $response->data);
        Http::assertSent(fn (HttpRequest $r) => ($r['response_format']['type'] ?? null) === 'json_object');

        // a fenced object is tolerated exactly as for the other vendors
        $this->groqReturns($this->answer("```json\n{\"a\":1}\n```"));
        $this->assertSame(['a' => 1], $this->provider()->generateStructured($this->request())->data);
    }

    public static function badStructured(): array
    {
        return ['not json' => ['Sure! Here you go'], 'a list' => ['[1,2]'], 'missing key' => ['{"other":1}'], 'truncated' => ['{"variants": [']];
    }

    #[DataProvider('badStructured')]
    public function test_a_structured_output_failure_is_a_malformed_response(string $text): void
    {
        $this->groqReturns($this->answer($text));

        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => $this->provider()->generateStructured($this->request(['requiredKeys' => ['variants']])));
    }

    // ==================================================================
    // 4. Usage
    // ==================================================================

    public static function usages(): array
    {
        return [
            'plain' => [['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18], 11, 7],
            'numeric strings' => [['prompt_tokens' => '3', 'completion_tokens' => '4'], 3, 4],
            'prompt only: output unknown' => [['prompt_tokens' => 10], 10, null],
            'empty usage' => [[], null, null],
            'no usage' => [null, null, null],
        ];
    }

    #[DataProvider('usages')]
    public function test_usage_is_normalized_without_inventing_counts(?array $usage, ?int $input, ?int $output): void
    {
        $this->groqReturns($this->answer(usage: $usage));

        $response = $this->provider()->generateText($this->request());

        $this->assertSame([$input, $output], [$response->inputTokens, $response->outputTokens]);
    }

    // ==================================================================
    // 5-7. Failures
    // ==================================================================

    public static function malformed(): array
    {
        return [
            'no choices' => [['choices' => []]],
            'empty choices array key missing' => [['usage' => ['prompt_tokens' => 3]]],
            'content is null' => [['choices' => [['message' => ['role' => 'assistant', 'content' => null], 'finish_reason' => 'stop']]]],
            'content is not a string' => [['choices' => [['message' => ['role' => 'assistant', 'content' => ['oops' => true]], 'finish_reason' => 'stop']]]],
        ];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_or_empty_answer_is_a_controlled_error(array $body): void
    {
        $this->groqReturns($body);

        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => $this->provider()->generateText($this->request()));
    }

    public function test_a_non_json_body_is_malformed(): void
    {
        $this->groqReturns('<html>oops</html>');

        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => $this->provider()->generateText($this->request()));
    }

    public static function vendorErrors(): array
    {
        return [
            // the exact shape this task exists for: a model Groq has retired/renamed.
            'model not found (deprecated/renamed model)' => [404, 'model_not_found', 'The model `gpt-oss-120b` does not exist or you do not have access to it.'],
            'authentication' => [401, 'invalid_api_key', 'Invalid API Key'],
            'rate limited' => [429, 'rate_limit_exceeded', 'Rate limit reached for requests'],
            'provider unavailable' => [503, 'service_unavailable', 'The model is currently overloaded.'],
            'internal' => [500, 'internal_error', 'An internal error has occurred.'],
        ];
    }

    #[DataProvider('vendorErrors')]
    public function test_vendor_errors_are_normalized_and_leak_nothing(int $status, string $vendorCode, string $vendorMessage): void
    {
        $this->groqReturns(['error' => ['message' => $vendorMessage, 'type' => 'invalid_request_error', 'code' => $vendorCode]], $status);
        $auth = $this->auth($this->tenant());

        $e = $this->assertAiError(AiException::PROVIDER_FAILED, fn () => app(AiService::class)->generateText($auth, $this->request()));

        $body = $e->render()->getContent();
        $this->assertSame(502, $e->httpStatus);
        $this->assertSame(['success' => false, 'message' => "The AI provider 'groq' could not complete the request.", 'error_code' => 'AI_PROVIDER_FAILED'], json_decode($body, true));
        foreach ([self::SECRET, self::PROMPT, $vendorMessage, $vendorCode] as $secret) {
            $this->assertStringNotContainsString($secret, $e->getMessage().$body);
        }
    }

    public function test_a_timeout_is_distinguished_and_a_vendor_answer_is_never_retried(): void
    {
        $calls = 0;
        $mode = 'timeout';
        Http::fake(function () use (&$calls, &$mode) {
            $calls++;

            return match ($mode) {
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'),
                'rate-limited' => Http::response(['error' => ['message' => 'rate limited', 'code' => 'rate_limit_exceeded']], 429),
                'flaky' => $calls === 1 ? throw new ConnectionException('cURL error 7: Failed to connect') : Http::response($this->answer('second try'), 200),
            };
        });

        $this->assertNull($this->assertAiError(AiException::PROVIDER_TIMEOUT, fn () => $this->provider()->generateText($this->request()))->vendorHttpStatus, 'a connection-level timeout carries no vendor status');

        // The existing retry policy (connection failures only, no sleep) applies unchanged.
        config(['ai.retries' => 1]);
        [$calls, $mode] = [0, 'rate-limited'];
        $this->assertAiError(AiException::PROVIDER_FAILED, fn () => $this->provider()->generateText($this->request()));
        $this->assertSame(1, $calls, 'a rate-limit ANSWER is not retried by the provider (it may be billed); callers retry through their own model');

        [$calls, $mode] = [0, 'flaky'];
        $started = microtime(true);
        $this->assertSame('second try', $this->provider()->generateText($this->request())->text);
        $this->assertSame(2, $calls);
        $this->assertLessThan(1, microtime(true) - $started, 'no back-off sleep');
    }

    public function test_logs_carry_metadata_only(): void
    {
        Log::spy();
        $this->groqReturns($this->answer('PRIVATE-ANSWER'));
        $account = $this->tenant();

        app(AiService::class)->generateText($this->auth($account), $this->request());

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($account) {
            $flat = json_encode($context);

            return $message === 'AI operation completed.' && $context['account_id'] === $account->id
                && $context['provider'] === 'groq' && $context['model'] === 'groq-test-2026'
                && ! str_contains($flat, 'PRIVATE') && ! str_contains($flat, self::SECRET);
        })->once();
    }

    // ==================================================================
    // 8-11. Metering through the unchanged MeteredAiService
    // ==================================================================

    public function test_metered_generation_through_groq_settles_the_actual_usage(): void
    {
        // 1200 prompt + 300 completion = 1500 tokens -> 2 credits (hold: maxTokens 4000 -> 5)
        $this->groqReturns($this->answer(usage: ['prompt_tokens' => 1200, 'completion_tokens' => 300, 'total_tokens' => 1500]));
        $account = $this->tenant(100);

        $result = app(MeteredAiService::class)->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'groq:op:1');

        $this->assertSame([2, true, 'Hello there'], [$result->creditsCharged, $result->settled, $result->response->text]);
        $this->assertSame([98, 0], $this->balance($account), 'actual usage consumed, rest of the hold released');
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame([AiOperation::STATUS_SETTLED, 'groq', 'groq-test-2026', 1200, 300, true, 2, 5], [
            $op->status, $op->provider, $op->model, (int) $op->input_tokens, (int) $op->output_tokens, (bool) $op->usage_reported, (int) $op->credits_charged, (int) $op->credits_reserved,
        ]);
        $this->assertSame(1, CreditLedgerEntry::where('account_id', $account->id)->where('type', 'consumption')->count());
    }

    public function test_missing_groq_usage_follows_the_missing_usage_rule(): void
    {
        $this->groqReturns($this->answer(usage: null));
        $account = $this->tenant(100);

        $this->assertSame(1, app(MeteredAiService::class)->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'groq:min')->creditsCharged, "'minimum'");

        config(['ai.credits.missing_usage' => 'reservation']);
        $this->assertSame(5, app(MeteredAiService::class)->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'groq:res')->creditsCharged, "'reservation'");

        $this->assertSame([94, 0], $this->balance($account));
        $this->assertSame([false, false], AiOperation::where('account_id', $account->id)->orderBy('id')->pluck('usage_reported')->map(fn ($v) => (bool) $v)->all());
    }

    public function test_a_groq_model_not_found_failure_releases_the_hold_and_charges_nothing(): void
    {
        // The exact failure mode Task 12 exists for: Groq retiring/renaming a hosted model.
        $this->groqReturns(['error' => ['message' => 'The model `gpt-oss-120b` does not exist or you do not have access to it.', 'type' => 'invalid_request_error', 'code' => 'model_not_found']], 404);
        $account = $this->tenant(100);

        $this->assertAiError(AiException::PROVIDER_FAILED, fn () => app(MeteredAiService::class)->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'groq:fail'));

        $this->assertSame([100, 0], $this->balance($account));
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame([AiOperation::STATUS_FAILED, 'AI_PROVIDER_FAILED'], [$op->status, $op->error_code]);
        $this->assertSame(CreditReservation::STATUS_RELEASED, CreditReservation::findOrFail($op->reservation_id)->status);
        $this->assertSame(0, CreditLedgerEntry::where('account_id', $account->id)->where('type', 'consumption')->count());
    }

    public function test_insufficient_credits_never_reach_groq(): void
    {
        Http::fake();
        $account = $this->tenant(0);

        $this->assertAiError(AiException::INSUFFICIENT_CREDITS, fn () => app(MeteredAiService::class)->generateText($this->auth($account), $this->request(), 'groq:poor'));
        Http::assertNothingSent();
    }

    public function test_an_idempotent_retry_never_double_charges(): void
    {
        $account = $this->tenant(100);
        $calls = 0;
        $fail = true;
        Http::fake(function () use (&$calls, &$fail) {
            $calls++;

            return $fail ? Http::response(['error' => ['message' => 'overloaded', 'code' => 'service_unavailable']], 503)
                : Http::response($this->answer(usage: ['prompt_tokens' => 1500, 'completion_tokens' => 500]), 200);
        });
        $metered = app(MeteredAiService::class);

        // failure -> the same key retries as a new attempt, charged once
        $this->assertAiError(AiException::PROVIDER_FAILED, fn () => $metered->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'groq:retry'));
        $fail = false;
        $this->assertSame(2, $metered->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'groq:retry')->creditsCharged);

        // success -> the same key again is refused, no vendor call, no charge
        $this->assertAiError(AiException::OPERATION_DUPLICATE, fn () => $metered->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'groq:retry'));

        $this->assertSame(2, $calls);
        $this->assertSame([98, 0], $this->balance($account));
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame([AiOperation::STATUS_SETTLED, 2], [$op->status, (int) $op->attempt]);
    }

    public function test_billing_code_stays_provider_neutral(): void
    {
        foreach (['Billing/MeteredAiService.php', 'Billing/AiCreditPricing.php', 'AiService.php', 'AiAuthorizer.php'] as $file) {
            $this->assertStringNotContainsStringIgnoringCase('groq', file_get_contents(app_path("Services/Ai/{$file}")), "{$file} must not know Groq");
        }
        foreach (['Services/WhatsApp/JourneyAiNodeRunner.php', 'Services/WhatsApp/WhatsAppJourneyEngine.php', 'Http/Controllers/Api/AICopywriterController.php'] as $file) {
            $source = file_get_contents(app_path($file));
            foreach (['GroqProvider', 'api.groq.com', "'groq'"] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$file} must not have a Groq path");
            }
        }
    }

    // ==================================================================
    // Existing consumers, unchanged, served by Groq via configuration
    // ==================================================================

    public function test_the_ad_copywriter_is_served_by_groq_with_the_same_contract(): void
    {
        $variants = array_map(fn ($i) => ['hook' => "Hook {$i}", 'caption' => "Caption {$i}", 'cta' => "CTA {$i}"], range(1, 5));
        $this->groqReturns($this->answer(json_encode(['variants' => $variants]), ['prompt_tokens' => 700, 'completion_tokens' => 600]));
        $account = $this->tenant(100);
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        $this->actingAs($user->fresh())->postJson('/api/social/ai/generate', [
            'business_name' => 'Sunrise Dental', 'target_industry' => 'Healthcare',
            'offer_details' => 'Free first check-up', 'target_goal' => 'PAID_LEAD_AD', 'tone' => 'Professional',
        ])->assertOk()->assertExactJson(['data' => ['provider' => 'groq', 'variants' => $variants]]);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::URL && ($r['response_format']['type'] ?? null) === 'json_object');
        // 700 prompt + 600 completion = 1300 tokens; at the default
        // tokens_per_credit=1000, charge = ceil(1300/1000) = 2 credits
        // (AiCreditPricing's documented formula), not 1.
        $this->assertSame([98, 0], $this->balance($account), 'the tenant (target account) paid 2 credits for 1300 tokens');
    }

    public function test_a_journey_prompt_node_is_served_by_groq_through_the_unchanged_runtime(): void
    {
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), 'api.groq.com')) {
                return Http::response($this->answer('Groq says hi', ['prompt_tokens' => 900, 'completion_tokens' => 600]), 200);
            }

            return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });
        config(['ai.journey.max_tokens' => 2000]); // hold 3 credits, so the 1500-token answer is charged in full
        $account = $this->tenant(100);
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
        WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'AI', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => [
                'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'p', 'type' => 'prompt', 'data' => ['prompt' => 'Greet the customer.', 'outputVariable' => 'out']], ['id' => 's', 'type' => 'text', 'data' => ['text' => 'AI: {{out}}']]],
                'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'p'], ['id' => 'e2', 'source' => 'p', 'target' => 's']],
            ],
        ]);

        app(ChatbotEngineService::class)->handleInboundMessage($account->id, '919812345678', 'go', null, 'qr', 'wamsg:'.uniqid());
        $session = WhatsAppFlowSession::where('account_id', $account->id)->sole();
        $this->assertSame(0, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.groq.com'))->count(), 'no vendor call on the inbound path');

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id));

        $this->assertSame('Groq says hi', $session->fresh()->context_data['out']);
        $this->assertSame(['AI: Groq says hi'], MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')->pluck('message_preview')->all());
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame(['groq', 'journey', 'journey.prompt', AiOperation::STATUS_SETTLED], [$op->provider, $op->source, $op->operation, $op->status]);
        $this->assertSame([98, 0], $this->balance($account));
    }

    // ==================================================================
    // Optional live smoke test (disabled by default)
    // ==================================================================

    public function test_optional_live_smoke_against_the_real_groq_api(): void
    {
        $key = getenv('GROQ_API_KEY') ?: null;

        if (getenv('GROQ_LIVE_SMOKE') !== '1' || ! $key) {
            $this->markTestSkipped('Live Groq smoke test disabled (set GROQ_LIVE_SMOKE=1 and GROQ_API_KEY to run it). Run this before switching production to Groq, and whenever AI_GROQ_MODEL changes -- Groq retires hosted models faster than the other vendors.');
        }

        config(['ai.providers.groq.api_key' => $key, 'ai.providers.groq.model' => getenv('AI_GROQ_MODEL') ?: 'llama-3.3-70b-versatile']);
        app(AiManager::class)->flush();
        Http::preventStrayRequests(false);

        $text = $this->provider()->generateText(new AiRequest('Reply with the single word: pong', maxTokens: 64));
        $data = $this->provider()->generateStructured(new AiRequest('Return {"ok": true}', maxTokens: 64, requiredKeys: ['ok']));

        $this->assertNotSame('', trim($text->text));
        $this->assertArrayHasKey('ok', $data->data);
    }
}
