<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiOperation;
use App\Models\MessageTemplate;
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
 * Phase 8 Task 13 — "Generate with AI" for the Template Designer
 * (POST /api/message-templates/ai-generate) is a metered consumer of the
 * central AI system, the same shape as AdCopywriterMeteredAiTest:
 *
 *   controller → AiAuthorizer (target account, templates, manage-templates, ai)
 *              → TemplateCopywriterService → MeteredAiService → AiService → AiManager → provider
 *
 * The key behavioural assertion this suite exists for: the AI's answer is
 * returned to the caller UNCHANGED (no ConnexxaIQ-style silent "anti-copy"
 * rewrite), and no MessageTemplate row is ever created by this endpoint —
 * it is a draft the caller must still submit through store()/update().
 *
 * UNVERIFIED: written without a reachable PHP/phpunit runtime in the
 * authoring session — run `php artisan test --filter=TemplateAiGenerate`
 * before trusting this file; see Phase 8 Task 13's row in PROJECT_STATE.md.
 */
class TemplateAiGenerateTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/message-templates/ai-generate';

    private const OPENAI = 'https://api.openai.com/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config([
            'ai.default' => 'openai', 'ai.enabled' => ['openai'], 'ai.retries' => 0,
            'ai.providers.openai.api_key' => 'sk-test-template', 'ai.providers.openai.model' => 'gpt-test',
            'ai.providers.openai.base_url' => self::OPENAI,
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum',
        ]);
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(string $planKey = 'growth', int $credits = 100): Account
    {
        $account = Account::factory()->create();
        $plan = PlanCatalog::find($planKey);
        $invoice = \App\Models\Invoice::create([
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

    private function user(?Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'purpose' => 'Confirm a customer appointment for tomorrow',
            'category' => 'UTILITY',
            'tone' => 'Professional',
            'variable_keys' => ['customer_name', 'appointment_time'],
        ], $overrides);
    }

    private function openAiAnswer(string $title, string $body): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['title' => $title, 'template_body' => $body])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 60, 'total_tokens' => 180],
        ];
    }

    // ==================================================================

    public function test_a_super_admin_must_name_an_account_to_authorize_and_charge(): void
    {
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => null]))
            ->assertStatus(422); // validation: account_id required
    }

    public function test_a_super_admin_can_generate_a_draft_against_a_chosen_account(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer('Appointment Reminder', 'Hello {{customer_name}}, your appointment is confirmed for {{appointment_time}}.'), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $response = $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))
            ->assertOk();

        $response->assertJsonPath('data.provider', 'openai');
        $response->assertJsonPath('data.title', 'Appointment Reminder');
        $response->assertJsonPath('data.template_body', 'Hello {{customer_name}}, your appointment is confirmed for {{appointment_time}}.');
    }

    public function test_an_agent_may_generate_for_its_own_sub_client_but_not_an_unrelated_account(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer('T', 'Hello {{customer_name}}.'), 200));
        $agentAccount = $this->tenant();
        // AiAuthorizer::resolveTarget() checks the ACCOUNT's account_type
        // (default 'client' per AccountFactory), not the user's RBAC role -
        // same distinction TenantIsolationMiddleware relies on.
        $agentAccount->update(['account_type' => 'agent']);
        $agent = $this->user($agentAccount, 'agent');
        $subClient = $this->tenant();
        $subClient->update(['agent_id' => $agentAccount->id]);
        $unrelated = $this->tenant();

        $this->actingAs($agent)->postJson(self::URL, $this->payload(['account_id' => $subClient->id]))->assertOk();
        // AiException::targetAccountForbidden() is a 404 (not 403) —
        // "not found or you may not act on it", same as AiAuthorizer's
        // other cross-tenant refusals (never confirms the id exists).
        $this->actingAs($agent)->postJson(self::URL, $this->payload(['account_id' => $unrelated->id]))->assertStatus(404);
    }

    public function test_a_plain_admin_without_manage_templates_is_forbidden(): void
    {
        $account = $this->tenant();
        $user = $this->user($account, 'user');

        $this->actingAs($user)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertForbidden();
    }

    public function test_the_ai_answer_is_returned_unchanged_no_silent_rewrite(): void
    {
        // The exact behaviour this task exists to get right: whatever the
        // model answers with IS what the caller gets back — no
        // ConnexxaIQ-style "reference example" + anti-copy substitution.
        $distinctiveBody = 'Hi {{customer_name}}, this is a VERY distinctive, unusual confirmation sentence that a generic fallback would never produce verbatim: your slot at {{appointment_time}} is locked in.';
        Http::fake(fn () => Http::response($this->openAiAnswer('Distinctive Title XYZ', $distinctiveBody), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))
            ->assertOk()
            ->assertJsonPath('data.title', 'Distinctive Title XYZ')
            ->assertJsonPath('data.template_body', $distinctiveBody);
    }

    public function test_no_message_template_row_is_ever_created_by_this_endpoint(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer('T', 'Hello {{customer_name}}.'), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_credits_are_charged_to_the_named_account_and_metered(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer('T', 'Hello {{customer_name}}, confirmed for {{appointment_time}}.'), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        $balance = app(CreditService::class)->balance($account->fresh());
        $this->assertLessThan(100, $balance['balance'], 'the named account paid, not the Super Admin');
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame([AiOperation::STATUS_SETTLED, 'templates.ai_generate'], [$op->status, $op->operation]);
    }

    public function test_a_provider_failure_falls_back_to_a_deterministic_draft_uncharged(): void
    {
        Http::fake(fn () => Http::response(['error' => ['message' => 'overloaded', 'code' => 'service_unavailable']], 503));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $response = $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        $response->assertJsonPath('data.provider', 'template');
        $this->assertNotEmpty($response->json('data.template_body'));
        $balance = app(CreditService::class)->balance($account->fresh());
        $this->assertSame(100, $balance['balance'], 'a provider failure is never charged — same contract as CopywriterService');
    }

    public function test_a_malformed_answer_also_falls_back_to_the_deterministic_draft(): void
    {
        Http::fake(fn () => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'not json at all'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))
            ->assertOk()
            ->assertJsonPath('data.provider', 'template');
    }

    public function test_an_empty_title_or_body_is_treated_as_unusable_and_falls_back(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer('', ''), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))
            ->assertOk()
            ->assertJsonPath('data.provider', 'template');
    }

    public function test_the_request_asks_for_json_mode_with_the_declared_variable_keys(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer('T', 'Hello {{customer_name}}.'), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        Http::assertSent(function (HttpRequest $r) {
            $body = json_encode($r->data());

            return ($r['response_format']['type'] ?? null) === 'json_object'
                && str_contains($body, 'customer_name')
                && str_contains($body, 'appointment_time');
        });
    }

    public function test_an_invalid_category_is_rejected(): void
    {
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id, 'category' => 'NOT_A_REAL_CATEGORY']))
            ->assertStatus(422);
    }

    public function test_an_idempotent_retry_with_the_same_header_is_not_double_charged(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer('T', 'Hello {{customer_name}}, confirmed for {{appointment_time}}.'), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $this->withHeader('Idempotency-Key', 'retry-key-1')
            ->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        $balanceAfterFirst = app(CreditService::class)->balance($account->fresh())['balance'];

        $this->withHeader('Idempotency-Key', 'retry-key-1')
            ->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertStatus(409);

        $this->assertSame($balanceAfterFirst, app(CreditService::class)->balance($account->fresh())['balance']);
    }
}
