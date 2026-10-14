<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ContactGroup;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A template's variables are Required or Optional (variables_schema). Every send path refuses a
 * missing required value with a message naming the variable's label and key — the Developer API,
 * the dashboard single send, group send, bulk send and Excel batch — and an optional one may be
 * left out. A client sets Required / Optional when requesting a template.
 */
class TemplateVariableRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEMA = [
        ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'string', 'required' => true],
        ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true],
        ['key' => 'note', 'label' => 'Note', 'type' => 'string', 'required' => false],
    ];

    private const MISSING_NAME = 'The "Customer Name" variable (variables.customer_name) is required.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{Account, User, MessageTemplate, WhatsAppNumber} */
    private function client(): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr', 'status' => 'active', 'expires_at' => now()->addMonth()]);
        $account->forceFill(['authorized_server_ip' => '127.0.0.1'])->save();
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $template = MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'VARS_'.strtoupper(Str::random(5)), 'title' => 'Payment due',
            'template_body' => 'Hi {{customer_name}}, {{amount}} is due. {{note}}', 'variables_schema' => self::SCHEMA,
            'status' => 'approved', 'language' => 'en_US', 'category' => 'UTILITY',
        ]);
        $number = WhatsAppNumber::query()->create(['account_id' => $account->id, 'phone_number' => '919800000001', 'is_included' => true, 'is_default' => true, 'status' => WhatsAppNumber::STATUS_LINKED]);

        return [$account, $user, $template, $number];
    }

    private function apiKey(Account $account): string
    {
        $plain = 'wasaas_live_'.Str::random(40);
        ApiKey::create([
            'account_id' => $account->id, 'name' => 'Test', 'key_prefix' => substr($plain, 0, 20),
            'key_hash' => ApiKey::hashKey($plain), 'legacy_binding_grace_expires_at' => now()->addDays(30),
        ]);

        return $plain;
    }

    public function test_the_developer_api_names_a_missing_required_variable(): void
    {
        [$account, , $template] = $this->client();
        $key = $this->apiKey($account);

        $this->withHeaders(['X-API-KEY' => $key, 'Idempotency-Key' => 'v1-'.Str::random(8)])
            ->postJson('/api/v1/messages/send-template', ['template_code' => $template->template_code, 'recipient_phone' => '919876543210', 'variables' => ['amount' => 500]])
            ->assertStatus(422)
            ->assertJsonPath('message', self::MISSING_NAME)
            ->assertJsonPath('errors', fn ($e) => isset($e['variables.customer_name']));

        $this->withHeaders(['X-API-KEY' => $key, 'Idempotency-Key' => 'v1-'.Str::random(8)])
            ->postJson('/api/v1/messages/send-template', ['template_code' => $template->template_code, 'recipient_phone' => '919876543210', 'variables' => ['customer_name' => 'Asha', 'amount' => 'five']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The "Amount" variable (variables.amount) must be a number.');
    }

    public function test_an_optional_variable_may_be_left_out_on_the_api(): void
    {
        [$account, , $template] = $this->client();

        $response = $this->withHeaders(['X-API-KEY' => $this->apiKey($account), 'Idempotency-Key' => 'v1-'.Str::random(8)])
            ->postJson('/api/v1/messages/send-template', ['template_code' => $template->template_code, 'recipient_phone' => '919876543210', 'variables' => ['customer_name' => 'Asha', 'amount' => 500]]);

        // Past validation: it stops later at the (absent) WhatsApp session, not on "note".
        $variableErrors = collect($response->json('errors') ?? [])->keys()->filter(fn ($k) => str_starts_with((string) $k, 'variables.'));
        $this->assertCount(0, $variableErrors, (string) $response->getContent());
        $this->assertStringNotContainsString('variable', (string) $response->json('message'));
    }

    public function test_the_dashboard_single_send_names_a_missing_required_variable(): void
    {
        [, $user, $template] = $this->client();

        $response = $this->actingAs($user)->postJson('/api/alerts/send-template', ['template_id' => $template->id, 'recipient_phone' => '919876543210', 'variables' => ['amount' => 500]]);

        $response->assertStatus(422);
        $this->assertStringContainsString(self::MISSING_NAME, (string) $response->json('message'));
        $this->assertSame([self::MISSING_NAME], $response->json('errors')['variables.customer_name']);
    }

    public function test_a_group_send_is_refused_up_front_but_name_comes_from_each_member(): void
    {
        [$account, $user] = $this->client();
        $template = MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'GRP_'.strtoupper(Str::random(5)), 'title' => 'Group due',
            'template_body' => 'Hi {{name}}, {{amount}} is due.', 'status' => 'approved', 'language' => 'en_US', 'category' => 'UTILITY',
        ]);
        $group = ContactGroup::create(['account_id' => $account->id, 'name' => 'Customers', 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL]);

        $response = $this->actingAs($user)->postJson("/api/groups/{$group->id}/send-template", ['template_id' => $template->id, 'variables' => []]);

        // {{amount}} is missing; {{name}} is not asked for — every member's own name fills it.
        $response->assertStatus(422)->assertJsonPath('error_code', 'INVALID_VARIABLES')->assertJsonPath('message', 'The "Amount" variable (variables.amount) is required.');
        $this->assertArrayNotHasKey('variables.name', $response->json('errors'));
    }

    public function test_bulk_send_and_excel_batch_refuse_a_missing_required_variable(): void
    {
        [, $user, $template, $number] = $this->client();

        $this->actingAs($user)->postJson('/api/alerts/bulk-sends', [
            'recipient_phones' => ['919876543210'], 'template_id' => $template->id, 'variables' => ['amount' => 500], 'sender_number_ids' => [$number->id],
        ])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_VARIABLES')->assertJsonPath('message', self::MISSING_NAME);

        $this->actingAs($user)->postJson('/api/alerts/message-batches', [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', "phone\n919876543210\n"),
            'template_id' => $template->id, 'variables' => ['amount' => 500], 'batch_size' => 10, 'sender_number_ids' => [$number->id],
        ])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_VARIABLES')->assertJsonPath('message', self::MISSING_NAME);
    }

    public function test_an_optional_variable_left_out_is_sent_blank_not_as_the_token(): void
    {
        [, , $template] = $this->client();

        $vars = $template->withBlankOptionalVariables(['customer_name' => 'Asha', 'amount' => 500]);

        $this->assertSame('', $vars['note']);
        $this->assertSame('Hi Asha, 500 is due. ', \App\Support\TemplateRenderer::render($template->template_body, $vars));
        $this->assertNull($template->variableErrors(['customer_name' => 'Asha', 'amount' => 500]));
    }

    public function test_a_client_sets_required_and_optional_when_requesting_a_template(): void
    {
        [, $user] = $this->client();

        $this->actingAs($user)->postJson('/api/alerts/message-templates/request', [
            'title' => 'Order shipped', 'template_body' => 'Hi {{customer_name}}, order {{order_id}} shipped. {{note}}', 'header_type' => 'text',
            'variables_schema' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order ID', 'type' => 'string', 'required' => true],
                ['key' => 'note', 'label' => 'Note', 'type' => 'string', 'required' => false],
            ],
        ])->assertCreated();

        $schema = MessageTemplate::query()->where('title', 'Order shipped')->firstOrFail()->effectiveVariablesSchema();
        $this->assertSame([true, true, false], array_column($schema, 'required'));
    }
}
