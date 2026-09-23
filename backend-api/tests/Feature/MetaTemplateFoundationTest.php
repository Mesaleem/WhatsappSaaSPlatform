<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4 Task 5 -- Meta WhatsApp Template Foundation.
 *
 * The template architecture already existed and was reused wholesale:
 * MessageTemplate / message_templates (there is no WhatsAppTemplate model
 * in this codebase), TemplateService's tiered approval routing -- which
 * already knew about Meta via the 'pending_meta_approval' status --
 * MessageTemplateController's CRUD, and TemplateMessageDispatcher.
 *
 * What was missing was the Meta-specific half of a template record
 * (language, category, Meta template name/status), the provider
 * restriction that keeps such a record usable only by a Meta account, and
 * the variable-structure check. This file covers all of it, plus a QR
 * regression proving the existing engine is untouched.
 */
class MetaTemplateFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const META_TOKEN = 'EAAG_tenant_token_never_leak_me_0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * Phase 5 Task 11 — the `plans` table is now the runtime source of
         * truth for checkout AND fulfilment, so markPaidAndCreditQuota()
         * can no longer credit a payment on an unseeded database. Real
         * environments always have this seeded; seeding it here makes the
         * fixture match production rather than relying on the static
         * PlanCatalog the cutover removed from every runtime path.
         */
        $this->seed(Phase1FoundationSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function planKeyFor(string $engine): string
    {
        foreach (PlanCatalog::all() as $key => $plan) {
            if ($plan['engine_type'] === $engine) {
                return $key;
            }
        }

        $this->fail("No PlanCatalog plan maps to the {$engine} provider.");
    }

    private function giveActiveSubscription(Account $account, string $engine): void
    {
        $planKey = $this->planKeyFor($engine);
        $plan = PlanCatalog::find($planKey);

        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $planKey,
            'plan_label' => $plan['label'],
            'amount' => $plan['price'],
            'tax_amount' => 0,
            'total_amount' => $plan['price'],
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(),
            'gateway_payment_id' => null,
            'status' => 'pending',
            'paid_at' => null,
            'gateway_raw_response' => null,
        ]);

        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
    }

    private function metaAccount(): Account
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'meta');

        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => '1098'.random_int(100000000, 999999999),
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => self::META_TOKEN,
        ]);

        return $account;
    }

    private function qrAccount(): Account
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'qr');
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** @return array<string, mixed> */
    private function metaPayload(Account $account, array $overrides = []): array
    {
        return array_merge([
            'account_id' => $account->id,
            'title' => 'Order Update',
            'template_code' => 'ORDER_UPDATE_'.strtoupper(bin2hex(random_bytes(3))),
            'template_body' => 'Hello {{name}}, your order {{order_id}} has shipped.',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'meta_template_name' => 'order_update',
            'meta_template_status' => 'APPROVED',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Create / read / update
    // ------------------------------------------------------------------
    public function test_a_meta_template_is_created_with_its_meta_fields(): void
    {
        $account = $this->metaAccount();

        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/message-templates', $this->metaPayload($account));

        $response->assertStatus(201);
        $response->assertJsonPath('data.language', 'en_US');
        $response->assertJsonPath('data.category', 'UTILITY');
        $response->assertJsonPath('data.meta_template_name', 'order_update');
        $response->assertJsonPath('data.meta_template_status', 'APPROVED');

        $template = MessageTemplate::latest('id')->firstOrFail();
        $this->assertSame($account->id, $template->account_id);
        $this->assertTrue($template->isMetaDefined());
    }

    public function test_meta_fields_can_be_read_back_and_updated(): void
    {
        $account = $this->metaAccount();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson('/api/message-templates', $this->metaPayload($account))->assertStatus(201);
        $template = MessageTemplate::latest('id')->firstOrFail();

        $this->actingAs($admin)->getJson('/api/message-templates')
            ->assertStatus(200)
            ->assertJsonFragment(['meta_template_name' => 'order_update', 'language' => 'en_US']);

        $this->actingAs($admin)->putJson("/api/message-templates/{$template->id}", [
            'language' => 'pt_BR',
            'category' => 'MARKETING',
            'meta_template_status' => 'PAUSED',
        ])->assertStatus(200);

        $fresh = $template->fresh();
        $this->assertSame('pt_BR', $fresh->language);
        $this->assertSame('MARKETING', $fresh->category);
        $this->assertSame('PAUSED', $fresh->meta_template_status);
    }

    public function test_a_qr_template_is_unchanged_and_carries_no_meta_fields(): void
    {
        $account = $this->qrAccount();

        $response = $this->actingAs($this->superAdmin())->postJson('/api/message-templates', [
            'account_id' => $account->id,
            'title' => 'QR Greeting',
            'template_body' => 'Hi {{name}}!',
        ]);

        $response->assertStatus(201);
        $template = MessageTemplate::latest('id')->firstOrFail();
        $this->assertNull($template->language);
        $this->assertNull($template->category);
        $this->assertNull($template->meta_template_name);
        $this->assertFalse($template->isMetaDefined());
    }

    // ------------------------------------------------------------------
    // Required-field & structural validation
    // ------------------------------------------------------------------
    public function test_required_fields_are_enforced(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/message-templates', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'template_body']);
    }

    public function test_an_invalid_language_code_is_rejected(): void
    {
        $account = $this->metaAccount();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/message-templates', $this->metaPayload($account, ['language' => 'english']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['language']);
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $account = $this->metaAccount();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/message-templates', $this->metaPayload($account, ['category' => 'PROMOTIONS']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    public function test_an_unknown_meta_status_is_rejected(): void
    {
        $account = $this->metaAccount();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/message-templates', $this->metaPayload($account, ['meta_template_status' => 'MAYBE']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meta_template_status']);
    }

    public function test_a_meta_template_name_without_a_language_is_rejected(): void
    {
        $account = $this->metaAccount();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/message-templates', $this->metaPayload($account, ['language' => null]))
            ->assertStatus(422);

        $this->assertSame(0, MessageTemplate::count());
    }

    /**
     * Requirement 6. Once variables_schema is configured it becomes
     * authoritative, so a body token missing from it is never substituted
     * and the RECIPIENT would receive the literal string "{{order_id}}".
     */
    public function test_a_body_variable_missing_from_the_configured_schema_is_rejected(): void
    {
        $account = $this->metaAccount();

        $response = $this->actingAs($this->superAdmin())->postJson('/api/message-templates', $this->metaPayload($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                // {{order_id}} deliberately not declared.
            ],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('{{order_id}}', $response->json('message'));
        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_a_fully_declared_schema_is_accepted(): void
    {
        $account = $this->metaAccount();

        $this->actingAs($this->superAdmin())->postJson('/api/message-templates', $this->metaPayload($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
            ],
        ]))->assertStatus(201);

        $this->assertSame([], MessageTemplate::latest('id')->firstOrFail()->undeclaredVariableNames());
    }

    public function test_an_empty_schema_still_auto_derives_and_is_accepted(): void
    {
        $account = $this->metaAccount();

        // No variables_schema at all -- effectiveVariablesSchema() derives
        // from the body, the pre-existing behaviour, still allowed.
        $this->actingAs($this->superAdmin())
            ->postJson('/api/message-templates', $this->metaPayload($account))
            ->assertStatus(201);

        $this->assertSame([], MessageTemplate::latest('id')->firstOrFail()->undeclaredVariableNames());
    }

    // ------------------------------------------------------------------
    // Meta-only provider restriction
    // ------------------------------------------------------------------
    public function test_meta_fields_cannot_be_set_on_a_qr_account(): void
    {
        $account = $this->qrAccount();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/message-templates', $this->metaPayload($account))
            ->assertStatus(422);

        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_meta_fields_cannot_be_moved_onto_a_qr_account_by_update(): void
    {
        $metaAccount = $this->metaAccount();
        $qrAccount = $this->qrAccount();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson('/api/message-templates', $this->metaPayload($metaAccount))->assertStatus(201);
        $template = MessageTemplate::latest('id')->firstOrFail();

        $this->actingAs($admin)->putJson("/api/message-templates/{$template->id}", [
            'account_id' => $qrAccount->id,
            'language' => 'en_US',
            'meta_template_name' => 'order_update',
        ])->assertStatus(422);

        $this->assertSame($metaAccount->id, $template->fresh()->account_id);
    }

    /** A Meta-registered template is not sendable from a QR account. */
    public function test_a_meta_defined_template_cannot_be_sent_by_a_qr_account(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'SHOULD_NOT_SEND'], 200)]);
        $qrAccount = $this->qrAccount();

        $template = MessageTemplate::create([
            'account_id' => $qrAccount->id,
            'template_code' => 'META_ONLY_TPL',
            'title' => 'Meta only',
            'template_body' => 'Hello {{name}}',
            'status' => 'approved',
            'language' => 'en_US',
            'meta_template_name' => 'meta_only_tpl',
        ]);

        $result = TemplateMessageDispatcher::dispatch(
            $qrAccount->id,
            $template->id,
            '919999999999',
            ['name' => 'Bob'],
        );

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Meta Cloud API provider', $result['message']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Tenant isolation / ownership
    // ------------------------------------------------------------------
    public function test_an_agent_cannot_create_a_template_for_another_agents_client(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $this->giveActiveSubscription($agentAccount, 'meta');
        $agentUser = User::factory()->create(['account_id' => $agentAccount->id, 'is_active' => true]);
        $agentUser->assignRole('agent');

        $foreignClient = $this->metaAccount(); // belongs to no agent

        $this->actingAs($agentUser)
            ->postJson('/api/message-templates', $this->metaPayload($foreignClient))
            ->assertStatus(404);

        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_an_agent_cannot_update_another_tenants_template(): void
    {
        $owner = $this->metaAccount();
        $this->actingAs($this->superAdmin())->postJson('/api/message-templates', $this->metaPayload($owner))->assertStatus(201);
        $template = MessageTemplate::latest('id')->firstOrFail();

        $agentAccount = Account::factory()->agent()->create();
        $this->giveActiveSubscription($agentAccount, 'meta');
        $agentUser = User::factory()->create(['account_id' => $agentAccount->id, 'is_active' => true]);
        $agentUser->assignRole('agent');

        $this->actingAs($agentUser)
            ->putJson("/api/message-templates/{$template->id}", ['title' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Order Update', $template->fresh()->title);
    }

    public function test_a_template_belonging_to_another_tenant_cannot_be_sent(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
        $owner = $this->metaAccount();
        $stranger = $this->metaAccount();

        $template = MessageTemplate::create([
            'account_id' => $owner->id,
            'template_code' => 'OWNER_ONLY',
            'title' => 'Owner only',
            'template_body' => 'Hi {{name}}',
            'status' => 'approved',
            'language' => 'en_US',
        ]);

        $result = TemplateMessageDispatcher::dispatch(
            $stranger->id,
            $template->id,
            '919999999999',
            ['name' => 'Bob'],
        );

        $this->assertSame('not_found', $result['status']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Duplicate / conflicting template
    // ------------------------------------------------------------------
    public function test_a_duplicate_template_code_is_rejected(): void
    {
        $account = $this->metaAccount();
        $admin = $this->superAdmin();
        $payload = $this->metaPayload($account, ['template_code' => 'DUPLICATE_CODE']);

        $this->actingAs($admin)->postJson('/api/message-templates', $payload)->assertStatus(201);

        $this->actingAs($admin)->postJson('/api/message-templates', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['template_code']);

        $this->assertSame(1, MessageTemplate::count());
    }

    // ------------------------------------------------------------------
    // Authorization / inactive account
    // ------------------------------------------------------------------
    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/message-templates')->assertStatus(401);
        $this->postJson('/api/message-templates', ['title' => 'x', 'template_body' => 'y'])->assertStatus(401);
    }

    public function test_a_user_without_manage_templates_is_rejected(): void
    {
        $account = $this->metaAccount();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('user');

        $this->actingAs($user)->getJson('/api/message-templates')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/message-templates', $this->metaPayload($account))->assertStatus(403);
    }

    public function test_an_inactive_account_cannot_send_a_meta_template(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
        $account = $this->metaAccount();
        $account->forceFill(['status' => 'inactive'])->save();

        $template = MessageTemplate::create([
            'account_id' => $account->id,
            'template_code' => 'INACTIVE_TPL',
            'title' => 'Inactive',
            'template_body' => 'Hi {{name}}',
            'status' => 'approved',
            'language' => 'en_US',
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, '919999999999', ['name' => 'Bob']);

        $this->assertSame('quota_exhausted', $result['status']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Meta API error handling at send time
    // ------------------------------------------------------------------
    public function test_a_meta_api_rejection_is_reported_without_leaking_internals(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Template name does not exist in the translation.', 'code' => 132001],
            ], 400),
        ]);
        $account = $this->metaAccount();

        $template = MessageTemplate::create([
            'account_id' => $account->id,
            'template_code' => 'META_ERR_TPL',
            'title' => 'Meta error',
            'template_body' => 'Hi {{name}}',
            'status' => 'approved',
            'language' => 'en_US',
            'meta_template_name' => 'meta_err_tpl',
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, '919999999999', ['name' => 'Bob']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Template name does not exist in the translation.', $result['message']);
        $this->assertStringNotContainsString(self::META_TOKEN, json_encode($result));
    }

    // ------------------------------------------------------------------
    // QR regression
    // ------------------------------------------------------------------
    public function test_a_qr_template_still_sends_through_the_qr_engine(): void
    {
        Http::fake([
            '*/api/message/send' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'should not be called']], 500),
        ]);
        $account = $this->qrAccount();

        $template = MessageTemplate::create([
            'account_id' => $account->id,
            'template_code' => 'QR_TPL',
            'title' => 'QR template',
            'template_body' => 'Hi {{name}}',
            'status' => 'approved',
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, '919999999999', ['name' => 'Bob']);

        $this->assertSame('sent', $result['status']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/message/send'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    // ------------------------------------------------------------------
    // Credential non-leakage
    // ------------------------------------------------------------------
    public function test_no_meta_credential_is_exposed_by_any_template_response(): void
    {
        $account = $this->metaAccount();
        $admin = $this->superAdmin();

        $created = $this->actingAs($admin)->postJson('/api/message-templates', $this->metaPayload($account));
        $listed = $this->actingAs($admin)->getJson('/api/message-templates');
        $template = MessageTemplate::latest('id')->firstOrFail();
        $updated = $this->actingAs($admin)->putJson("/api/message-templates/{$template->id}", ['title' => 'Renamed']);

        foreach ([$created, $listed, $updated] as $response) {
            $body = $response->getContent();
            $this->assertStringNotContainsString(self::META_TOKEN, $body);
            $this->assertStringNotContainsString('EAAG', $body);
            $this->assertStringNotContainsString('meta_access_token', $body);
        }
    }
}
