<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiOperation;
use App\Models\Contact;
use App\Models\CrmLead;
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
 * Basic AI writing for every client (owner, 2026-10-14): a template draft for "Request a
 * Template", a message for Send Notification and a CRM follow-up. Even a plan without the `ai`
 * capability (Starter) may use it; it is charged to the client's credits; nothing is saved.
 */
class AiWritingTest extends TestCase
{
    use RefreshDatabase;

    private const OPENAI = 'https://api.openai.com/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config([
            'ai.default' => 'openai', 'ai.enabled' => ['openai'], 'ai.retries' => 0,
            'ai.providers.openai.api_key' => 'sk-test-writing', 'ai.providers.openai.model' => 'gpt-test',
            'ai.providers.openai.base_url' => self::OPENAI,
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum',
        ]);
    }

    private function client(string $planKey = 'starter', int $credits = 50): User
    {
        $account = Account::factory()->create(['company_name' => 'Sharma Travels']);
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
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user->fresh();
    }

    private function answer(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 60, 'total_tokens' => 180],
        ];
    }

    public function test_a_starter_client_drafts_a_template_with_its_credits_and_nothing_is_saved(): void
    {
        Http::fake(fn () => Http::response($this->answer(json_encode(['title' => 'Payment reminder', 'template_body' => 'Hi {{customer_name}}, {{amount}} is due.'])), 200));
        $user = $this->client('starter');
        $before = app(CreditService::class)->balance($user->account)['available'];

        $this->actingAs($user)->postJson('/api/alerts/message-templates/ai-generate', [
            'purpose' => 'Remind a customer that a payment is due', 'variable_keys' => ['customer_name', 'amount'],
        ])->assertOk()->assertJsonPath('data.title', 'Payment reminder')->assertJsonPath('data.template_body', 'Hi {{customer_name}}, {{amount}} is due.');

        $this->assertSame(0, MessageTemplate::query()->count());
        $this->assertSame(1, AiOperation::query()->where('account_id', $user->account_id)->count());
        $this->assertLessThan($before, app(CreditService::class)->balance($user->account->fresh())['available']);
    }

    public function test_send_notification_writes_or_improves_a_message_in_the_chosen_language(): void
    {
        Http::fake(fn () => Http::response($this->answer("\"Namaste! Aapka order kal deliver hoga. 😊\""), 200));
        $user = $this->client('starter');

        $this->actingAs($user)->postJson('/api/alerts/ai-write', ['purpose' => 'Order will be delivered tomorrow', 'language' => 'hinglish'])
            ->assertOk()->assertJsonPath('data.text', 'Namaste! Aapka order kal deliver hoga. 😊');

        Http::assertSent(fn (HttpRequest $r) => str_contains(json_encode($r->data()), 'Hinglish'));

        $this->actingAs($user)->postJson('/api/alerts/ai-write', ['language' => 'english'])
            ->assertStatus(422)->assertJsonValidationErrors(['purpose', 'draft']);
        $this->actingAs($user)->postJson('/api/alerts/ai-write', ['draft' => 'ur order ship tmrw', 'language' => 'klingon'])
            ->assertStatus(422)->assertJsonValidationErrors('language');
    }

    public function test_a_crm_follow_up_uses_the_leads_details_and_stays_in_the_tenant(): void
    {
        Http::fake(fn () => Http::response($this->answer('Hi Rahul, just checking in about your Goa trip. Shall we talk today?'), 200));
        $user = $this->client('growth');
        $contact = Contact::create(['account_id' => $user->account_id, 'phone_number' => '919900000001', 'name' => 'Rahul Verma']);
        $lead = CrmLead::create(['account_id' => $user->account_id, 'contact_id' => $contact->id, 'requirement' => 'Goa trip for 4 people', 'source' => 'manual']);

        $this->actingAs($user)->postJson("/api/crm/leads/{$lead->id}/ai-follow-up", ['goal' => 'Book a call'])
            ->assertOk()->assertJsonPath('data.text', 'Hi Rahul, just checking in about your Goa trip. Shall we talk today?');
        Http::assertSent(fn (HttpRequest $r) => str_contains(json_encode($r->data()), 'Goa trip for 4 people') && str_contains(json_encode($r->data()), 'Sharma Travels'));

        // Another tenant's lead is simply not found.
        $other = $this->client('growth');
        $this->actingAs($other)->postJson("/api/crm/leads/{$lead->id}/ai-follow-up", [])->assertNotFound();
    }

    public function test_no_credits_left_is_a_clear_refusal(): void
    {
        Http::fake(fn () => Http::response($this->answer('x'), 200));
        $user = $this->client('starter', credits: 0);
        \App\Models\PlatformSetting::query()->updateOrCreate(['key' => \App\Models\PlatformSetting::AI_FREE_DAILY_CLIENT], ['value' => '0']);

        $response = $this->actingAs($user)->postJson('/api/alerts/ai-write', ['purpose' => 'Diwali greeting']);
        $this->assertContains($response->status(), [402, 422]);
        $this->assertNotNull($response->json('error_code'));
        Http::assertNothingSent();
    }
}
