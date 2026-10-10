<?php

namespace Tests\Feature;

use App\Events\AiOperationPerformed;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ActivityLog;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\EntitlementAuditLogger;
use App\Services\Ai\AiAuthorization;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 8 — AI Foundation & Provider Abstraction.
 *
 *   A. provider abstraction (AiManager / AiProvider / vendor providers):
 *      resolution, replaceability, normalization, controlled errors;
 *   B. safe logging (metadata only);
 *   C. authorization (AiAuthorizer): existing predicates, target account,
 *      tenant isolation, Super Admin without a global bypass, automated
 *      paths checked the same way;
 *   D. plan integration: the existing plan-management path assigns/removes
 *      `ai` and reconciliation grants/revokes the entitlement.
 *
 * No real vendor is called: HTTP is faked; no credit is touched.
 */
class AiFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-test-SECRET-never-shown';

    private const PROMPT = 'PRIVATE-PROMPT-customer Priya owes 5000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config([
            'ai.default' => 'openai',
            'ai.enabled' => ['openai', 'anthropic'],
            'ai.retries' => 0,
            'ai.providers.openai.api_key' => self::SECRET,
            'ai.providers.openai.model' => 'gpt-test',
            // pinned, so a host's own *_BASE_URL environment cannot change the asserted URLs
            'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'ai.providers.anthropic.base_url' => 'https://api.anthropic.com',
            'ai.providers.anthropic.api_key' => self::SECRET,
            'ai.providers.anthropic.model' => 'claude-test',
        ]);
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(string $planKey = 'growth', array $attributes = []): Account
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

        return $account->fresh();
    }

    private function user(?Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function authorizer(): AiAuthorizer
    {
        return app(AiAuthorizer::class);
    }

    private function allow(User $user, ?int $target = null, string $module = 'chatbot', ?string $permission = 'manage-chatbot'): AiAuthorization
    {
        return $this->authorizer()->forUser($user, $target, $module, $permission);
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

    private function openAiOk(string $content = 'Hello there'): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-test-2026', 'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7],
        ], 200)]);
    }

    private function request(array $overrides = []): AiRequest
    {
        return new AiRequest(...array_merge(['prompt' => self::PROMPT, 'system' => 'Be brief.'], $overrides));
    }

    // ==================================================================
    // A. Provider abstraction
    // ==================================================================

    public function test_the_configured_default_provider_resolves(): void
    {
        $this->assertInstanceOf(OpenAiProvider::class, app(AiManager::class)->provider());
        $this->assertInstanceOf(AnthropicProvider::class, app(AiManager::class)->provider('anthropic'));

        config(['ai.default' => 'anthropic']);
        app(AiManager::class)->flush();
        $this->assertSame('anthropic', app(AiManager::class)->provider()->name());
        $this->assertSame(app(AiManager::class), app(AiManager::class), 'one manager per container');
    }

    public function test_an_unsupported_or_disabled_provider_fails_cleanly(): void
    {
        $e = $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, fn () => app(AiManager::class)->provider('mystery'));
        $this->assertSame(503, $e->httpStatus);

        config(['ai.enabled' => ['openai']]);
        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, fn () => app(AiManager::class)->provider('anthropic'));
        $this->assertFalse(app(AiManager::class)->available('anthropic'));
    }

    public function test_missing_configuration_fails_cleanly(): void
    {
        config(['ai.default' => null]);
        $this->assertAiError(AiException::PROVIDER_NOT_CONFIGURED, fn () => app(AiManager::class)->provider());

        config(['ai.default' => 'openai', 'ai.providers.openai.api_key' => null]);
        app(AiManager::class)->flush();
        $this->assertAiError(AiException::PROVIDER_NOT_CONFIGURED, fn () => app(AiManager::class)->provider());
        $this->assertFalse(app(AiService::class)->available());
    }

    public function test_the_implementation_is_replaceable_without_touching_callers(): void
    {
        $fake = new class implements AiProvider {
            public function name(): string { return 'fake'; }
            public function generateText(AiRequest $request): AiResponse { return new AiResponse('fake', 'fake-1', 'from the fake'); }
            public function generateStructured(AiRequest $request): AiResponse { return new AiResponse('fake', 'fake-1', '{}', ['ok' => true]); }
        };
        app(AiManager::class)->extend('fake', fn () => $fake);
        config(['ai.default' => 'fake', 'ai.enabled' => ['fake']]);
        Http::fake();

        $auth = $this->allow($this->user($this->tenant()));
        $response = app(AiService::class)->generateText($auth, $this->request());

        $this->assertSame(['fake', 'from the fake'], [$response->provider, $response->text]);
        Http::assertNothingSent();

        // A factory that returns something that is not the named provider is refused.
        app(AiManager::class)->extend('liar', fn () => $fake);
        config(['ai.enabled' => ['fake', 'liar']]);
        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, fn () => app(AiManager::class)->provider('liar'));
    }

    public function test_openai_text_is_sent_and_normalized(): void
    {
        $this->openAiOk();
        $auth = $this->allow($this->user($this->tenant()));

        $response = app(AiService::class)->generateText($auth, $this->request(['maxTokens' => 50, 'temperature' => 0.2]));

        $this->assertSame(['openai', 'gpt-test-2026', 'Hello there', 11, 7, 'stop'], [$response->provider, $response->model, $response->text, $response->inputTokens, $response->outputTokens, $response->finishReason]);
        $this->assertNull($response->data);
        $this->assertGreaterThanOrEqual(0, $response->latencyMs);
        Http::assertSent(function (HttpRequest $r) {
            return $r->url() === 'https://api.openai.com/v1/chat/completions'
                && $r->hasHeader('Authorization', 'Bearer '.self::SECRET)
                && $r['model'] === 'gpt-test' && $r['max_tokens'] === 50 && $r['temperature'] === 0.2
                && $r['messages'][0] === ['role' => 'system', 'content' => 'Be brief.']
                && end($r->data()['messages']) === ['role' => 'user', 'content' => self::PROMPT]
                && ! isset($r['response_format']);
        });
    }

    public function test_anthropic_text_is_sent_and_normalized(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-test-x', 'content' => [['type' => 'text', 'text' => 'Hi '], ['type' => 'text', 'text' => 'there']],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
        ], 200)]);
        $auth = $this->allow($this->user($this->tenant()));

        $response = app(AiService::class)->generateText($auth, $this->request(), 'anthropic');

        $this->assertSame(['anthropic', 'claude-test-x', 'Hi there', 5, 3, 'end_turn'], [$response->provider, $response->model, $response->text, $response->inputTokens, $response->outputTokens, $response->finishReason]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.anthropic.com/v1/messages'
            && $r->hasHeader('x-api-key', self::SECRET) && $r->hasHeader('anthropic-version')
            && $r['system'] === 'Be brief.' && $r['model'] === 'claude-test');
    }

    public function test_structured_generation_decodes_and_validates_a_json_object(): void
    {
        $this->openAiOk('{"summary":"ok","score":3}');
        $auth = $this->allow($this->user($this->tenant()));

        $response = app(AiService::class)->generateStructured($auth, $this->request(['requiredKeys' => ['summary', 'score']]));

        $this->assertSame(['summary' => 'ok', 'score' => 3], $response->data);
        Http::assertSent(fn (HttpRequest $r) => ($r['response_format']['type'] ?? null) === 'json_object');

        // A fenced object (Anthropic has no JSON mode) is accepted.
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "```json\n{\"a\":1}\n```"]]], 200)]);
        $this->assertSame(['a' => 1], app(AiService::class)->generateStructured($auth, $this->request(), 'anthropic')->data);
    }

    public static function malformed(): array
    {
        return [
            'not json' => ['sure! here you go'],
            'a list, not an object' => ['[1,2,3]'],
            'missing a required key' => ['{"summary":"ok"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function test_a_malformed_structured_answer_is_a_controlled_error(string $content): void
    {
        $this->openAiOk($content);
        $auth = $this->allow($this->user($this->tenant()));

        $e = $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => app(AiService::class)->generateStructured($auth, $this->request(['requiredKeys' => ['summary', 'score']])));
        $this->assertSame(502, $e->httpStatus);
    }

    public function test_a_response_without_the_expected_shape_is_malformed(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => []], 200)]);
        $auth = $this->allow($this->user($this->tenant()));

        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => app(AiService::class)->generateText($auth, $this->request()));
    }

    public function test_a_provider_failure_is_normalized_and_leaks_nothing(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: '.self::SECRET]], 401)]);
        $auth = $this->allow($this->user($this->tenant()));

        $e = $this->assertAiError(AiException::PROVIDER_FAILED, fn () => app(AiService::class)->generateText($auth, $this->request()));

        $body = $e->render()->getContent();
        $this->assertSame(502, $e->render()->getStatusCode());
        $this->assertSame(['success' => false, 'message' => "The AI provider 'openai' could not complete the request.", 'error_code' => 'AI_PROVIDER_FAILED'], json_decode($body, true));
        $this->assertStringNotContainsString(self::SECRET, $e->getMessage().$body);
        $this->assertStringNotContainsString('Incorrect API key', $e->getMessage().$body);
    }

    public function test_a_timeout_and_a_connection_failure_are_distinguished(): void
    {
        $auth = $this->allow($this->user($this->tenant()));

        $failure = 'cURL error 28: Operation timed out after 30001 milliseconds';
        Http::fake(function () use (&$failure) {
            throw new ConnectionException($failure);
        });
        $this->assertSame(504, $this->assertAiError(AiException::PROVIDER_TIMEOUT, fn () => app(AiService::class)->generateText($auth, $this->request()))->httpStatus);

        $failure = 'cURL error 7: Failed to connect';
        $this->assertAiError(AiException::PROVIDER_FAILED, fn () => app(AiService::class)->generateText($auth, $this->request()));
    }

    public function test_retries_apply_to_connection_failures_only_and_never_sleep(): void
    {
        config(['ai.retries' => 1]);
        $auth = $this->allow($this->user($this->tenant()));

        $calls = 0;
        $mode = 'flaky-connection';
        Http::fake(function () use (&$calls, &$mode) {
            $calls++;
            if ($mode === 'vendor-error') {
                return Http::response(['error' => 'overloaded'], 500);
            }
            if ($calls === 1) {
                throw new ConnectionException('cURL error 7: Failed to connect');
            }

            return Http::response(['choices' => [['message' => ['content' => 'second try']]]], 200);
        });
        $started = microtime(true);
        $this->assertSame('second try', app(AiService::class)->generateText($auth, $this->request())->text);
        $this->assertSame(2, $calls);
        $this->assertLessThan(1, microtime(true) - $started, 'no back-off sleep');

        // A vendor ANSWER (even an error) is never retried — it may have been billed.
        $calls = 0;
        $mode = 'vendor-error';
        $this->assertAiError(AiException::PROVIDER_FAILED, fn () => app(AiService::class)->generateText($auth, $this->request()));
        $this->assertSame(1, $calls);
    }

    public function test_an_empty_prompt_is_rejected_before_any_call(): void
    {
        Http::fake();
        $this->assertAiError(AiException::INVALID_REQUEST, fn () => new AiRequest('   '));
        Http::assertNothingSent();
    }

    public function test_no_credential_is_hardcoded(): void
    {
        $config = file_get_contents(config_path('ai.php'));
        $this->assertMatchesRegularExpression("/'api_key' => env\\('OPENAI_API_KEY'\\)/", $config);
        $this->assertMatchesRegularExpression("/'api_key' => env\\('ANTHROPIC_API_KEY'\\)/", $config);

        foreach (glob(app_path('Services/Ai/{,*/}*.php'), GLOB_BRACE) as $file) {
            $this->assertDoesNotMatchRegularExpression('/sk-[A-Za-z0-9]{8,}|env\(/', file_get_contents($file), basename($file).' reads credentials only through config');
        }
    }

    public function test_the_ai_service_has_no_credit_or_feature_coupling(): void
    {
        $source = file_get_contents(app_path('Services/Ai/AiService.php'));

        foreach (['Credit', 'Crm', 'Journey', 'Ads', 'Wallet', 'reserve(', 'consume('] as $forbidden) {
            $this->assertDoesNotMatchRegularExpression('/^(?!\s*\*).*'.preg_quote($forbidden, '/').'/m', $source, "AiService must not reference {$forbidden}");
        }

        $method = new \ReflectionMethod(AiService::class, 'generateText');
        $this->assertSame(AiAuthorization::class, $method->getParameters()[0]->getType()->getName(), 'an AI call requires an authorization');
    }

    // ==================================================================
    // B. Logging
    // ==================================================================

    public function test_operations_are_logged_with_metadata_only(): void
    {
        Event::fake([AiOperationPerformed::class]);
        Log::spy();
        $this->openAiOk('PRIVATE-ANSWER');
        $account = $this->tenant();
        $auth = $this->allow($this->user($account));

        app(AiService::class)->generateText($auth, $this->request());

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($account) {
            $flat = json_encode($context);

            return $message === 'AI operation completed.'
                && $context['account_id'] === $account->id && $context['provider'] === 'openai' && $context['model'] === 'gpt-test-2026'
                && $context['operation'] === 'text' && $context['success'] === true && is_int($context['latency_ms'])
                && ! str_contains($flat, 'PRIVATE') && ! str_contains($flat, self::SECRET);
        })->once();
        Event::assertDispatched(AiOperationPerformed::class, fn ($e) => $e->success && $e->accountId === $account->id && $e->inputTokens === 11);
    }

    public function test_a_failure_is_logged_with_its_category_and_no_content(): void
    {
        Event::fake([AiOperationPerformed::class]);
        Log::spy();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => self::SECRET]], 500)]);
        $auth = $this->allow($this->user($this->tenant()));

        $this->assertAiError(AiException::PROVIDER_FAILED, fn () => app(AiService::class)->generateText($auth, $this->request(['operation' => 'journey.prompt'])));

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'AI operation failed.'
            && $context['error_code'] === 'AI_PROVIDER_FAILED' && $context['error_category'] === 'provider'
            && $context['operation'] === 'journey.prompt'
            && ! str_contains(json_encode($context), 'PRIVATE') && ! str_contains(json_encode($context), self::SECRET))->once();
        Event::assertDispatched(AiOperationPerformed::class, fn ($e) => ! $e->success && $e->errorCode === 'AI_PROVIDER_FAILED');
    }

    // ==================================================================
    // C. Authorization
    // ==================================================================

    public function test_a_user_without_the_ai_entitlement_is_denied(): void
    {
        Http::fake();
        $starter = $this->tenant('starter'); // Starter does not sell `ai`

        $e = $this->assertAiError(AiException::CAPABILITY_UNAVAILABLE, fn () => $this->allow($this->user($starter)));
        $this->assertSame(403, $e->httpStatus);
        Http::assertNothingSent();
        $this->assertTrue(ActivityLog::where('module_name', EntitlementAuditLogger::MODULE)->where('action_type', 'denied')->get()
            ->contains(fn ($row) => ($row->new_values['action'] ?? null) === 'ai.authorize' && $row->new_values['category'] === 'capability_not_entitled'));
    }

    public function test_a_user_with_the_ai_entitlement_reaches_the_ai_service(): void
    {
        $this->openAiOk();
        $account = $this->tenant('growth');

        $auth = $this->allow($this->user($account));

        $this->assertSame($account->id, $auth->account->id);
        $this->assertSame('Hello there', app(AiService::class)->generateText($auth, $this->request())->text);
        $this->assertTrue(ActivityLog::where('module_name', EntitlementAuditLogger::MODULE)->where('action_type', 'allowed')->get()
            ->contains(fn ($row) => ($row->new_values['action'] ?? null) === 'ai.authorize' && $row->new_values['capability'] === 'ai'));
    }

    public function test_a_revoked_ai_entitlement_denies(): void
    {
        $account = $this->tenant('growth');
        AccountEntitlement::where('account_id', $account->id)->where('capability_id', Capability::where('slug', 'ai')->value('id'))->update(['revoked_at' => now()]);

        $this->assertAiError(AiException::CAPABILITY_UNAVAILABLE, fn () => $this->allow($this->user($account)));
    }

    public function test_the_required_permission_is_enforced(): void
    {
        $account = $this->tenant('growth');

        // The 'user' role does not hold manage-chatbot.
        $this->assertAiError(AiException::PERMISSION_DENIED, fn () => $this->allow($this->user($account, 'user')));

        // A custom role holding the permission is allowed (roles built before any request).
        Role::create(['name' => 'ai_bot_builder'])->givePermissionTo('manage-chatbot');
        $this->assertInstanceOf(AiAuthorization::class, $this->allow($this->user($account, 'ai_bot_builder')));
    }

    public function test_the_required_module_is_enforced_even_with_the_capability(): void
    {
        $account = $this->tenant('growth');
        $account->forceFill(['allowed_modules' => ['dashboard', 'lead_crm']])->save();

        $this->assertAiError(AiException::MODULE_DISABLED, fn () => $this->allow($this->user($account), module: 'chatbot'));
        $this->assertInstanceOf(AiAuthorization::class, $this->allow($this->user($account), module: 'lead_crm', permission: 'manage-crm'));
    }

    public function test_subscription_and_account_state_are_enforced(): void
    {
        $expired = $this->tenant('growth');
        Subscription::where('account_id', $expired->id)->update(['expires_at' => now()->subDay()]);
        $this->assertAiError(AiException::SUBSCRIPTION_INACTIVE, fn () => $this->allow($this->user($expired)));

        $suspended = $this->tenant('growth');
        $user = $this->user($suspended);
        $suspended->forceFill(['status' => 'suspended'])->save();
        $this->assertAiError(AiException::ACCOUNT_SUSPENDED, fn () => $this->allow($user));

        // Same rule the AI credit side uses: an exhausted MESSAGE quota does not switch AI off.
        $exhausted = $this->tenant('growth');
        Subscription::where('account_id', $exhausted->id)->update(['used_messages' => \DB::raw('total_allocated_messages')]);
        $this->assertInstanceOf(AiAuthorization::class, $this->allow($this->user($exhausted)));
    }

    public function test_an_inactive_or_missing_user_is_refused(): void
    {
        $account = $this->tenant('growth');
        $user = $this->user($account);
        $user->forceFill(['is_active' => false])->save();

        $this->assertAiError(AiException::UNAUTHENTICATED, fn () => $this->allow($user));
        $this->assertAiError(AiException::UNAUTHENTICATED, fn () => $this->authorizer()->forUser(null, $account->id, 'chatbot', 'manage-chatbot'));
    }

    public function test_tenant_isolation_is_enforced(): void
    {
        $mine = $this->tenant('growth');
        $theirs = $this->tenant('growth');
        $user = $this->user($mine);

        $e = $this->assertAiError(AiException::TARGET_ACCOUNT_FORBIDDEN, fn () => $this->allow($user, $theirs->id));
        $this->assertSame(404, $e->httpStatus);
        $this->assertStringNotContainsString((string) $theirs->company_name, $e->getMessage());
        $this->assertTrue(ActivityLog::where('action_type', 'denied')->get()->contains(fn ($r) => ($r->new_values['category'] ?? null) === 'cross_tenant' && $r->new_values['target_account_id'] === $theirs->id));

        $this->assertSame($mine->id, $this->allow($user)->account->id, 'no target = own account');
        $this->assertSame($mine->id, $this->allow($user, $mine->id)->account->id);
        $this->assertAiError(AiException::TARGET_ACCOUNT_FORBIDDEN, fn () => $this->allow($user, 999999));
    }

    public function test_an_agent_may_target_only_its_own_sub_clients_and_their_entitlements_apply(): void
    {
        $agent = $this->tenant('growth', ['account_type' => 'agent']);
        $entitledSub = $this->tenant('growth', ['agent_id' => $agent->id]);
        $starterSub = $this->tenant('starter', ['agent_id' => $agent->id]);
        $stranger = $this->tenant('growth');
        $agentUser = $this->user($agent);

        $this->assertSame($entitledSub->id, $this->allow($agentUser, $entitledSub->id)->account->id);
        $this->assertAiError(AiException::CAPABILITY_UNAVAILABLE, fn () => $this->allow($agentUser, $starterSub->id), 'the sub-client\'s own plan decides');
        $this->assertAiError(AiException::TARGET_ACCOUNT_FORBIDDEN, fn () => $this->allow($agentUser, $stranger->id));
    }

    public function test_the_super_admin_needs_a_target_and_gets_no_entitlement_bypass(): void
    {
        $superAdmin = $this->user(null, 'super_admin');
        $growth = $this->tenant('growth');
        $starter = $this->tenant('starter');

        $this->assertAiError(AiException::TARGET_ACCOUNT_REQUIRED, fn () => $this->allow($superAdmin));
        $this->assertAiError(AiException::CAPABILITY_UNAVAILABLE, fn () => $this->allow($superAdmin, $starter->id));
        $this->assertAiError(AiException::TARGET_ACCOUNT_FORBIDDEN, fn () => $this->allow($superAdmin, 999999));
        $this->assertSame($growth->id, $this->allow($superAdmin, $growth->id)->account->id);
    }

    public function test_the_request_target_is_the_one_tenant_isolation_resolved(): void
    {
        $mine = $this->tenant('growth');
        $user = $this->user($mine);

        $request = Request::create('/api/x', 'POST', ['account_id' => 12345]); // a body value is never read
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('account_id', $mine->id);

        $this->assertSame($mine->id, $this->authorizer()->forRequest($request, 'chatbot', 'manage-chatbot')->account->id);

        $superAdmin = $this->user(null, 'super_admin');
        $saRequest = Request::create('/api/x', 'POST');
        $saRequest->setUserResolver(fn () => $superAdmin);
        $saRequest->attributes->set('account_id', null); // no client selected
        $this->assertAiError(AiException::TARGET_ACCOUNT_REQUIRED, fn () => $this->authorizer()->forRequest($saRequest, 'chatbot', 'manage-chatbot'));
    }

    public function test_automated_paths_are_checked_the_same_way_whatever_their_source(): void
    {
        $growth = $this->tenant('growth');
        $starter = $this->tenant('starter');

        $auth = $this->authorizer()->forAccount($growth, 'chatbot', 'journey');
        $this->assertSame([$growth->id, null, 'journey'], [$auth->account->id, $auth->actorUserId, $auth->source]);

        foreach (['journey', 'api', 'crm', 'automation'] as $source) {
            $this->assertAiError(AiException::CAPABILITY_UNAVAILABLE, fn () => $this->authorizer()->forAccount($starter, 'chatbot', $source));
        }

        $growth->forceFill(['allowed_modules' => ['dashboard']])->save();
        $this->assertAiError(AiException::MODULE_DISABLED, fn () => $this->authorizer()->forAccount($growth, 'chatbot', 'journey'));
    }

    // ==================================================================
    // D. Plan integration (the existing plan-management path)
    // ==================================================================

    public function test_the_seeded_defaults_sell_ai_on_growth_and_business_only(): void
    {
        $this->assertContains('ai', Plan::where('slug', 'growth')->firstOrFail()->capabilities->pluck('slug')->all());
        $this->assertContains('ai', Plan::where('slug', 'business')->firstOrFail()->capabilities->pluck('slug')->all());
        $this->assertNotContains('ai', Plan::where('slug', 'starter')->firstOrFail()->capabilities->pluck('slug')->all());
        $this->assertSame(1, Capability::where('slug', 'ai')->count(), 'the existing capability is reused, not duplicated');
    }

    public function test_ai_can_be_assigned_to_and_removed_from_a_plan_and_entitlements_follow(): void
    {
        $customer = $this->tenant('starter');
        $customerUser = $this->user($customer);
        $superAdmin = $this->user(null, 'super_admin');
        $this->assertAiError(AiException::CAPABILITY_UNAVAILABLE, fn () => $this->allow($customerUser));

        $starterBundle = Plan::where('slug', 'starter')->firstOrFail()->capabilities->pluck('slug')->all();

        // The seeded 'starter' plan already carries external_api without
        // api_installations (deliberately — see Phase1FoundationSeeder's own
        // docblock on Requirement 7). Backfill a concrete limit first, the
        // same way a real Super Admin would via the admin UI, so the ai
        // add/remove below only ever touches 'ai' and data.added/data.removed
        // stay accurate.
        if (! in_array('api_installations', $starterBundle, true)) {
            $this->actingAs($superAdmin)->putJson('/api/admin/plans-management/starter', [
                'capabilities' => [...$starterBundle, 'api_installations'],
                'capability_limits' => ['api_installations' => 5],
            ])->assertOk();
            $starterBundle[] = 'api_installations';
        }

        // Assign `ai` through the existing admin endpoint (reconciliation runs on the sync queue).
        // capability_limits must be resupplied on every bundle-rewriting PUT —
        // the controller resolves an unresupplied limit as null whenever
        // `capabilities` is present at all (see PlanManagementController::
        // update()'s own comment on $finalCapabilityLimits), so omitting it
        // here would re-null api_installations' limit just backfilled above.
        $this->actingAs($superAdmin)->putJson('/api/admin/plans-management/starter', [
            'capabilities' => [...$starterBundle, 'ai'],
            'capability_limits' => ['api_installations' => 5],
        ])->assertOk()->assertJsonPath('data.added', ['ai']);
        $this->assertInstanceOf(AiAuthorization::class, $this->allow($customerUser->fresh()), 'entitlement granted to the plan\'s accounts');

        // Remove it again.
        $this->actingAs($superAdmin)->putJson('/api/admin/plans-management/starter', [
            'capabilities' => $starterBundle,
            'capability_limits' => ['api_installations' => 5],
        ])->assertOk()->assertJsonPath('data.removed', ['ai']);
        $this->assertAiError(AiException::CAPABILITY_UNAVAILABLE, fn () => $this->allow($customerUser->fresh()));

        // A price-only edit leaves the bundle (and AI access) untouched.
        $growthUser = $this->user($this->tenant('growth'));
        $this->actingAs($superAdmin)->putJson('/api/admin/plans-management/growth', ['price' => 2100])->assertOk()->assertJsonPath('data.bundle_changed', false);
        $this->assertInstanceOf(AiAuthorization::class, $this->allow($growthUser));
    }

    public function test_a_new_plan_can_be_created_with_or_without_ai(): void
    {
        $superAdmin = $this->user(null, 'super_admin');
        $base = ['label' => 'X', 'price' => 10, 'duration_days' => 30, 'engine_type' => 'qr', 'billing_model' => 'flat_quota', 'total_allocated_messages' => 10];

        $this->actingAs($superAdmin)->postJson('/api/admin/plans-management', $base + ['slug' => 'with-ai', 'capabilities' => ['whatsapp_send', 'ai']])
            ->assertCreated()->assertJsonPath('data.capabilities', ['ai', 'whatsapp_send']);
        $this->actingAs($superAdmin)->postJson('/api/admin/plans-management', $base + ['slug' => 'no-ai', 'capabilities' => ['whatsapp_send']])
            ->assertCreated()->assertJsonPath('data.capabilities', ['whatsapp_send']);

        $available = $this->actingAs($superAdmin)->getJson('/api/admin/plans-management')->assertOk()->json('available_capabilities.*.slug');
        $this->assertContains('ai', $available, 'the admin UI discovers `ai` dynamically');
    }
}
