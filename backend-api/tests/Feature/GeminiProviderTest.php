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
use App\Services\Ai\Providers\GeminiProvider;
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
 * Phase 8 Task 8 — Gemini as a first-class provider under the existing AI
 * abstraction (AiManager → GeminiProvider), used by the existing consumers
 * (MeteredAiService, Journey AI nodes, Ad Copywriter) with no
 * Gemini-specific code outside the provider.
 *
 * Every vendor call is faked at the HTTP boundary — no Gemini key is needed.
 * The optional live smoke test at the bottom is skipped unless
 * GEMINI_LIVE_SMOKE=1 and a real GEMINI_API_KEY are set in the environment.
 *
 * Pricing: 1000 tokens per credit, minimum 1.
 */
class GeminiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'AIza-test-SECRET-never-shown';

    private const PROMPT = 'PRIVATE-PROMPT-customer Priya owes 5000';

    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config([
            'ai.default' => 'gemini',
            'ai.enabled' => ['openai', 'anthropic', 'gemini'],
            'ai.retries' => 0,
            'ai.providers.gemini.api_key' => self::SECRET,
            'ai.providers.gemini.model' => 'gemini-test',
            // pinned, so a host's own GEMINI_* environment cannot change the asserted URL
            'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com',
            'ai.providers.gemini.api_version' => 'v1beta',
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1,
            'ai.credits.tokens_per_credit_overrides' => [], 'ai.credits.missing_usage' => 'minimum',
        ]);
        app(AiManager::class)->flush();
    }

    // ------------------------------------------------------------------ fixtures

    /** @param array<string, mixed>|null $usage */
    private function answer(array $parts = [['text' => 'Hello there']], ?array $usage = ['promptTokenCount' => 11, 'candidatesTokenCount' => 7, 'totalTokenCount' => 18], array $extra = []): array
    {
        return array_filter(array_merge([
            'candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => 'STOP', 'index' => 0]],
            'usageMetadata' => $usage,
            'modelVersion' => 'gemini-test-001',
        ], $extra), fn ($v) => $v !== null);
    }

    /** @var array{0: mixed, 1: int}|null the next faked vendor answer (one fake per test; Http::fake stubs stack) */
    private ?array $next = null;

    private function geminiReturns(mixed $body, int $status = 200): void
    {
        if ($this->next === null) {
            Http::fake(fn (HttpRequest $r) => str_contains($r->url(), 'generativelanguage.googleapis.com')
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

    private function provider(): GeminiProvider
    {
        return app(AiManager::class)->provider('gemini');
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
    // 1 + 8. Contract and resolution
    // ==================================================================

    public function test_gemini_implements_the_provider_contract_through_the_shared_http_base(): void
    {
        $provider = $this->provider();

        $this->assertInstanceOf(AiProvider::class, $provider);
        $this->assertInstanceOf(HttpAiProvider::class, $provider);
        $this->assertSame('gemini', $provider->name());
    }

    public function test_the_manager_resolves_gemini_from_configuration_and_fails_safely_otherwise(): void
    {
        $this->assertSame('gemini', app(AiManager::class)->provider()->name(), 'AI_PROVIDER=gemini selects it');
        $this->assertTrue(app(AiService::class)->available('gemini'));

        config(['ai.providers.gemini.api_key' => null]);
        app(AiManager::class)->flush();
        $this->assertAiError(AiException::PROVIDER_NOT_CONFIGURED, fn () => app(AiManager::class)->provider('gemini'));

        config(['ai.providers.gemini.api_key' => self::SECRET, 'ai.enabled' => ['openai']]);
        app(AiManager::class)->flush();
        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, fn () => app(AiManager::class)->provider('gemini'));

        config(['ai.default' => null]);
        $this->assertAiError(AiException::PROVIDER_NOT_CONFIGURED, fn () => app(AiManager::class)->provider(), 'no provider configured: unchanged, AI off');
        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, fn () => app(AiManager::class)->provider('bard'), 'unknown provider: unchanged');
    }

    public function test_the_configuration_reads_the_key_from_the_environment_only(): void
    {
        $config = file_get_contents(config_path('ai.php'));

        $this->assertMatchesRegularExpression("/'gemini' => \\[\\s*'api_key' => env\\('GEMINI_API_KEY'\\)/", $config);
        $this->assertStringContainsString("env('AI_GEMINI_MODEL'", $config);
        $this->assertStringContainsString("'openai,anthropic,gemini'", $config, 'enabled by default; still needs a key and AI_PROVIDER');
        $this->assertStringNotContainsString("env('GEMINI_MODEL'", $config, 'the legacy (retired-model) variable is not inherited');

        $source = file_get_contents(app_path('Services/Ai/Providers/GeminiProvider.php'));
        foreach (['env(', 'gemini_api_key', 'SocialProviderConfig', 'Account', 'Credit', 'Log::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "GeminiProvider must not use {$forbidden}");
        }

        foreach (['GEMINI_API_KEY=', 'AI_GEMINI_MODEL=', 'GEMINI_BASE_URL=', 'GEMINI_API_VERSION=', 'AI_ENABLED_PROVIDERS=openai,anthropic,gemini'] as $documented) {
            $this->assertStringContainsString($documented, file_get_contents(base_path('.env.example')));
        }
    }

    // ==================================================================
    // 2. Text
    // ==================================================================

    public function test_text_is_sent_in_the_gemini_format_and_normalized(): void
    {
        $this->geminiReturns($this->answer([['text' => 'internal reasoning', 'thought' => true], ['text' => 'Hello '], ['text' => 'there']]));

        $response = $this->provider()->generateText($this->request(['maxTokens' => 300, 'temperature' => 0.2]));

        $this->assertSame(['gemini', 'gemini-test-001', 'Hello there', null, 11, 7, 'STOP'], [
            $response->provider, $response->model, $response->text, $response->data, $response->inputTokens, $response->outputTokens, $response->finishReason,
        ]);

        Http::assertSent(function (HttpRequest $r) {
            return $r->url() === self::URL
                && $r->method() === 'POST'
                && $r->header('x-goog-api-key') === [self::SECRET]
                && ! str_contains($r->url(), 'key=')
                && $r->data() === [
                    'contents' => [['role' => 'user', 'parts' => [['text' => self::PROMPT]]]],
                    'generationConfig' => ['maxOutputTokens' => 300, 'temperature' => 0.2],
                    'systemInstruction' => ['parts' => [['text' => 'Be brief.']]],
                ];
        });
    }

    public function test_the_model_comes_from_the_request_or_config_and_is_path_safe(): void
    {
        $this->geminiReturns($this->answer(extra: ['modelVersion' => null]));

        $this->assertSame('gemini-other', $this->provider()->generateText($this->request(['model' => 'models/gemini-other', 'system' => null]))->model);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-other:generateContent'
            && ! array_key_exists('systemInstruction', $r->data()));

        Http::fake();
        foreach (['../evil', 'gemini?key=x', 'a/b', 'gemini-test:streamGenerateContent'] as $bad) {
            $this->assertAiError(AiException::INVALID_REQUEST, fn () => $this->provider()->generateText($this->request(['model' => $bad])));
        }
        config(['ai.providers.gemini.model' => null]);
        app(AiManager::class)->flush();
        $this->assertAiError(AiException::PROVIDER_NOT_CONFIGURED, fn () => $this->provider()->generateText($this->request()));
        Http::assertNothingSent();
    }

    // ==================================================================
    // 3. Structured
    // ==================================================================

    public function test_structured_generation_uses_native_json_mode_and_the_shared_validation(): void
    {
        $this->geminiReturns($this->answer([['text' => '{"variants":[{"hook":"H"}]}']]));

        $response = $this->provider()->generateStructured($this->request(['requiredKeys' => ['variants']]));

        $this->assertSame(['variants' => [['hook' => 'H']]], $response->data);
        Http::assertSent(fn (HttpRequest $r) => $r->data()['generationConfig']['responseMimeType'] === 'application/json'
            && str_contains($r->data()['systemInstruction']['parts'][0]['text'], 'Be brief.')
            && str_contains($r->data()['systemInstruction']['parts'][0]['text'], 'single valid JSON object'));

        // a fenced object is tolerated exactly as for the other vendors
        $this->geminiReturns($this->answer([['text' => "```json\n{\"a\":1}\n```"]]));
        $this->assertSame(['a' => 1], $this->provider()->generateStructured($this->request())->data);
    }

    public static function badStructured(): array
    {
        return ['not json' => ['Sure! Here you go'], 'a list' => ['[1,2]'], 'missing key' => ['{"other":1}'], 'truncated' => ['{"variants": [']];
    }

    #[DataProvider('badStructured')]
    public function test_a_structured_output_failure_is_a_malformed_response(string $text): void
    {
        $this->geminiReturns($this->answer([['text' => $text]]));

        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => $this->provider()->generateStructured($this->request(['requiredKeys' => ['variants']])));
    }

    // ==================================================================
    // 4. Usage
    // ==================================================================

    public static function usages(): array
    {
        return [
            'plain' => [['promptTokenCount' => 11, 'candidatesTokenCount' => 7, 'totalTokenCount' => 18], 11, 7],
            'thinking tokens are output' => [['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 40, 'totalTokenCount' => 55], 10, 45],
            'no candidates count: total − prompt' => [['promptTokenCount' => 10, 'totalTokenCount' => 30], 10, 20],
            'prompt only: output unknown' => [['promptTokenCount' => 10], 10, null],
            'numeric strings' => [['promptTokenCount' => '3', 'candidatesTokenCount' => '4'], 3, 4],
            'empty metadata' => [[], null, null],
            'no metadata' => [null, null, null],
        ];
    }

    #[DataProvider('usages')]
    public function test_usage_metadata_is_normalized_without_inventing_counts(?array $usage, ?int $input, ?int $output): void
    {
        $this->geminiReturns($this->answer(usage: $usage));

        $response = $this->provider()->generateText($this->request());

        $this->assertSame([$input, $output], [$response->inputTokens, $response->outputTokens]);
    }

    // ==================================================================
    // 5–7. Failures
    // ==================================================================

    public static function malformed(): array
    {
        return [
            'no candidates' => [['usageMetadata' => ['promptTokenCount' => 3]]],
            'prompt blocked' => [['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 3]]],
            'safety stop without content' => [['candidates' => [['finishReason' => 'SAFETY', 'index' => 0]]]],
            'only a thought part' => [['candidates' => [['content' => ['parts' => [['text' => 'thinking', 'thought' => true]]], 'finishReason' => 'MAX_TOKENS']]]],
            'whitespace text' => [['candidates' => [['content' => ['parts' => [['text' => "  \n"]]], 'finishReason' => 'STOP']]]],
            'non-text parts' => [['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png']]]]]]]],
        ];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_or_empty_answer_is_a_controlled_error(array $body): void
    {
        $this->geminiReturns($body);

        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => $this->provider()->generateText($this->request()));
    }

    public function test_a_non_json_body_is_malformed(): void
    {
        $this->geminiReturns('<html>oops</html>');

        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => $this->provider()->generateText($this->request()));
    }

    public static function vendorErrors(): array
    {
        return [
            'authentication (API_KEY_INVALID)' => [400, 'INVALID_ARGUMENT', 'API key not valid. Please pass a valid API key. '.self::SECRET],
            'permission denied' => [403, 'PERMISSION_DENIED', 'Method doesn\'t allow unregistered callers. key='.self::SECRET],
            'invalid model' => [404, 'NOT_FOUND', 'models/gemini-test is not found for API version v1beta'],
            'malformed request' => [400, 'INVALID_ARGUMENT', 'Invalid JSON payload received. '.self::PROMPT],
            'rate limited' => [429, 'RESOURCE_EXHAUSTED', 'Resource has been exhausted (e.g. check quota).'],
            'provider unavailable' => [503, 'UNAVAILABLE', 'The model is overloaded. Please try again later.'],
            'internal' => [500, 'INTERNAL', 'An internal error has occurred.'],
        ];
    }

    #[DataProvider('vendorErrors')]
    public function test_vendor_errors_are_normalized_and_leak_nothing(int $status, string $vendorStatus, string $vendorMessage): void
    {
        $this->geminiReturns(['error' => ['code' => $status, 'message' => $vendorMessage, 'status' => $vendorStatus]], $status);
        $auth = $this->auth($this->tenant());

        $e = $this->assertAiError(AiException::PROVIDER_FAILED, fn () => app(AiService::class)->generateText($auth, $this->request()));

        $body = $e->render()->getContent();
        $this->assertSame(502, $e->httpStatus);
        $this->assertSame(['success' => false, 'message' => "The AI provider 'gemini' could not complete the request.", 'error_code' => 'AI_PROVIDER_FAILED'], json_decode($body, true));
        foreach ([self::SECRET, self::PROMPT, $vendorMessage, $vendorStatus] as $secret) {
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
                'deadline' => Http::response(['error' => ['code' => 504, 'status' => 'DEADLINE_EXCEEDED']], 504),
                'rate-limited' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429),
                'flaky' => $calls === 1 ? throw new ConnectionException('cURL error 7: Failed to connect') : Http::response($this->answer([['text' => 'second try']]), 200),
            };
        });

        $this->assertSame(504, $this->assertAiError(AiException::PROVIDER_TIMEOUT, fn () => $this->provider()->generateText($this->request()))->httpStatus);

        $mode = 'deadline';
        $this->assertAiError(AiException::PROVIDER_TIMEOUT, fn () => $this->provider()->generateText($this->request()));

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
        $this->geminiReturns($this->answer([['text' => 'PRIVATE-ANSWER']]));
        $account = $this->tenant();

        app(AiService::class)->generateText($this->auth($account), $this->request());

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($account) {
            $flat = json_encode($context);

            return $message === 'AI operation completed.' && $context['account_id'] === $account->id
                && $context['provider'] === 'gemini' && $context['model'] === 'gemini-test-001'
                && ! str_contains($flat, 'PRIVATE') && ! str_contains($flat, self::SECRET);
        })->once();
    }

    // ==================================================================
    // 9–12. Metering through the unchanged MeteredAiService
    // ==================================================================

    public function test_metered_generation_through_gemini_settles_the_actual_usage(): void
    {
        // 1200 prompt + 300 candidates + 1500 thinking = 3000 tokens → 3 credits (hold: maxTokens 4000 → 5)
        $this->geminiReturns($this->answer(usage: ['promptTokenCount' => 1200, 'candidatesTokenCount' => 300, 'thoughtsTokenCount' => 1500, 'totalTokenCount' => 3000]));
        $account = $this->tenant(100);

        $result = app(MeteredAiService::class)->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'gem:op:1');

        $this->assertSame([3, true, 'Hello there'], [$result->creditsCharged, $result->settled, $result->response->text]);
        $this->assertSame([97, 0], $this->balance($account), 'actual usage consumed, rest of the hold released');
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame([AiOperation::STATUS_SETTLED, 'gemini', 'gemini-test-001', 1200, 1800, true, 3, 5], [
            $op->status, $op->provider, $op->model, (int) $op->input_tokens, (int) $op->output_tokens, (bool) $op->usage_reported, (int) $op->credits_charged, (int) $op->credits_reserved,
        ]);
        $this->assertSame(1, CreditLedgerEntry::where('account_id', $account->id)->where('type', 'consumption')->count());
    }

    public function test_missing_gemini_usage_follows_the_missing_usage_rule(): void
    {
        $this->geminiReturns($this->answer(usage: null));
        $account = $this->tenant(100);

        $this->assertSame(1, app(MeteredAiService::class)->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'gem:min')->creditsCharged, "'minimum'");

        config(['ai.credits.missing_usage' => 'reservation']);
        $this->assertSame(5, app(MeteredAiService::class)->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'gem:res')->creditsCharged, "'reservation'");

        $this->assertSame([94, 0], $this->balance($account));
        $this->assertSame([false, false], AiOperation::where('account_id', $account->id)->orderBy('id')->pluck('usage_reported')->map(fn ($v) => (bool) $v)->all());
    }

    public function test_a_gemini_failure_releases_the_hold_and_charges_nothing(): void
    {
        $this->geminiReturns(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429);
        $account = $this->tenant(100);

        $this->assertAiError(AiException::PROVIDER_FAILED, fn () => app(MeteredAiService::class)->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'gem:fail'));

        $this->assertSame([100, 0], $this->balance($account));
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame([AiOperation::STATUS_FAILED, 'AI_PROVIDER_FAILED'], [$op->status, $op->error_code]);
        $this->assertSame(CreditReservation::STATUS_RELEASED, CreditReservation::findOrFail($op->reservation_id)->status);
        $this->assertSame(0, CreditLedgerEntry::where('account_id', $account->id)->where('type', 'consumption')->count());
    }

    public function test_a_malformed_gemini_answer_is_not_charged(): void
    {
        $this->geminiReturns(['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 50]]);
        $account = $this->tenant(100);

        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => app(MeteredAiService::class)->generateStructured($this->auth($account), $this->request(), 'gem:blocked'));
        $this->assertSame([100, 0], $this->balance($account));
    }

    public function test_insufficient_credits_never_reach_gemini(): void
    {
        Http::fake();
        $account = $this->tenant(0);

        $this->assertAiError(AiException::INSUFFICIENT_CREDITS, fn () => app(MeteredAiService::class)->generateText($this->auth($account), $this->request(), 'gem:poor'));
        Http::assertNothingSent();
    }

    public function test_an_idempotent_retry_never_double_charges(): void
    {
        $account = $this->tenant(100);
        $calls = 0;
        $fail = true;
        Http::fake(function () use (&$calls, &$fail) {
            $calls++;

            return $fail ? Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503)
                : Http::response($this->answer(usage: ['promptTokenCount' => 1500, 'candidatesTokenCount' => 500]), 200);
        });
        $metered = app(MeteredAiService::class);

        // failure → the same key retries as a new attempt, charged once
        $this->assertAiError(AiException::PROVIDER_FAILED, fn () => $metered->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'gem:retry'));
        $fail = false;
        $this->assertSame(2, $metered->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'gem:retry')->creditsCharged);

        // success → the same key again is refused, no vendor call, no charge
        $this->assertAiError(AiException::OPERATION_DUPLICATE, fn () => $metered->generateText($this->auth($account), $this->request(['maxTokens' => 4000]), 'gem:retry'));

        $this->assertSame(2, $calls);
        $this->assertSame([98, 0], $this->balance($account));
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame([AiOperation::STATUS_SETTLED, 2], [$op->status, (int) $op->attempt]);
    }

    public function test_billing_code_stays_provider_neutral(): void
    {
        foreach (['Billing/MeteredAiService.php', 'Billing/AiCreditPricing.php', 'AiService.php', 'AiAuthorizer.php'] as $file) {
            $this->assertStringNotContainsStringIgnoringCase('gemini', file_get_contents(app_path("Services/Ai/{$file}")), "{$file} must not know Gemini");
        }
        foreach (['Services/WhatsApp/JourneyAiNodeRunner.php', 'Services/WhatsApp/WhatsAppJourneyEngine.php', 'Http/Controllers/Api/AICopywriterController.php'] as $file) {
            $source = file_get_contents(app_path($file));
            foreach (['GeminiProvider', 'generativelanguage', "'gemini'"] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$file} must not have a Gemini path");
            }
        }
    }

    // ==================================================================
    // Existing consumers, unchanged, served by Gemini via configuration
    // ==================================================================

    public function test_the_ad_copywriter_is_served_by_gemini_with_the_same_contract(): void
    {
        $variants = array_map(fn ($i) => ['hook' => "Hook {$i}", 'caption' => "Caption {$i}", 'cta' => "CTA {$i}"], range(1, 5));
        $this->geminiReturns($this->answer([['text' => json_encode(['variants' => $variants])]], ['promptTokenCount' => 700, 'candidatesTokenCount' => 600]));
        $account = $this->tenant(100);
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        $this->actingAs($user->fresh())->postJson('/api/social/ai/generate', [
            'business_name' => 'Sunrise Dental', 'target_industry' => 'Healthcare',
            'offer_details' => 'Free first check-up', 'target_goal' => 'PAID_LEAD_AD', 'tone' => 'Professional',
        ])->assertOk()->assertExactJson(['data' => ['provider' => 'gemini', 'variants' => $variants]]);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::URL && $r->data()['generationConfig']['responseMimeType'] === 'application/json');
        $this->assertSame([98, 0], $this->balance($account), 'the tenant (target account) paid 2 credits');
    }

    public function test_a_journey_prompt_node_is_served_by_gemini_through_the_unchanged_runtime(): void
    {
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), 'generativelanguage.googleapis.com')) {
                return Http::response($this->answer([['text' => 'Gemini says hi']], ['promptTokenCount' => 900, 'candidatesTokenCount' => 600]), 200);
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
        $this->assertSame(0, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'generativelanguage'))->count(), 'no vendor call on the inbound path');

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id));

        $this->assertSame('Gemini says hi', $session->fresh()->context_data['out']);
        $this->assertSame(['AI: Gemini says hi'], MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')->pluck('message_preview')->all());
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame(['gemini', 'journey', 'journey.prompt', AiOperation::STATUS_SETTLED], [$op->provider, $op->source, $op->operation, $op->status]);
        $this->assertSame([98, 0], $this->balance($account));
    }

    // ==================================================================
    // Optional live smoke test (disabled by default)
    // ==================================================================

    public function test_optional_live_smoke_against_the_real_gemini_api(): void
    {
        $key = getenv('GEMINI_API_KEY') ?: null;

        if (getenv('GEMINI_LIVE_SMOKE') !== '1' || ! $key) {
            $this->markTestSkipped('Live Gemini smoke test disabled (set GEMINI_LIVE_SMOKE=1 and GEMINI_API_KEY to run it).');
        }

        config(['ai.providers.gemini.api_key' => $key, 'ai.providers.gemini.model' => getenv('AI_GEMINI_MODEL') ?: 'gemini-3.5-flash']);
        app(AiManager::class)->flush();
        Http::preventStrayRequests(false);

        $text = $this->provider()->generateText(new AiRequest('Reply with the single word: pong', maxTokens: 64));
        $data = $this->provider()->generateStructured(new AiRequest('Return {"ok": true}', maxTokens: 64, requiredKeys: ['ok']));

        $this->assertNotSame('', trim($text->text));
        $this->assertArrayHasKey('ok', $data->data);
    }
}
