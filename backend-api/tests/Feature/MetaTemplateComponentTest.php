<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Templates\TemplateComponentTranslator;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 7 -- Meta template COMPONENT support.
 *
 * Task 6 shipped `type: template` with body parameters only. This file
 * covers the remaining components: a HEADER text parameter, a media
 * header driven by the template's own pre-existing header_type /
 * header_media_url fields, BUTTON (url + quick_reply) parameters at
 * schema-declared indexes, and FOOTER -- which Meta accepts no runtime
 * parameter for at all and which is therefore rejected locally rather
 * than sent.
 *
 * No second template system was created. TemplateMessageDispatcher still
 * owns the whole flow; TemplateComponentTranslator gained component
 * awareness; MetaCloudApiDriver is untouched.
 *
 * The one schema change is NARROW and ADDITIVE: three optional per-field
 * keys (`component`, `button_sub_type`, `button_index`). Every schema
 * written before this task omits them, and a field with no `component`
 * means 'body' -- so every pre-Task-7 template produces a byte-identical
 * payload, asserted below.
 */
class MetaTemplateComponentTest extends TestCase
{
    use RefreshDatabase;

    private const META_TOKEN = 'EAAG_tenant_token_never_leak_me_0123456789';

    private const RECIPIENT = '919999999999';

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

    // ------------------------------------------------------------------
    // Fixtures (same shape as MetaTemplateSendTest -- deliberately not
    // extracted into a shared base class: that would be an unrelated
    // refactor of an already-passing suite.)
    // ------------------------------------------------------------------
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

    /** @param array<string, mixed> $overrides */
    private function metaTemplate(Account $account, array $overrides = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'account_id' => $account->id,
            'template_code' => 'TPL_'.strtoupper(Str::random(6)),
            'title' => 'Order Update',
            'template_body' => 'Hello {{name}}, order {{order_id}} shipped.',
            'status' => 'approved',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'meta_template_name' => 'order_update',
            'meta_template_status' => 'APPROVED',
        ], $overrides));
    }

    private function fakeGraphSuccess(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.COMPONENT_SENT']],
            ], 200),
            '*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200),
        ]);
    }

    /** @return array<string, mixed> the decoded Graph request body */
    private function graphBody(): array
    {
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                return $request->data();
            }
        }

        $this->fail('No Graph API request was recorded.');
    }

    private function assertNoGraphCall(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString(
                'graph.facebook.com',
                $request->url(),
                'A validation failure must never reach the Graph API.',
            );
        }
    }

    /** @param list<array<string, mixed>> $components */
    private function componentOfType(array $components, string $type): ?array
    {
        foreach ($components as $component) {
            if (($component['type'] ?? null) === $type) {
                return $component;
            }
        }

        return null;
    }

    // ==================================================================
    // 1. BODY -- unchanged, and unchanged for every pre-Task-7 schema
    // ==================================================================
    public function test_a_schema_with_no_component_key_is_still_sent_entirely_as_body(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        // Exactly the shape every template written before Task 7 has --
        // no `component` key anywhere.
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $this->assertSame('sent', $result['status']);

        $components = $this->graphBody()['template']['components'];
        $this->assertCount(1, $components, 'A body-only schema must emit exactly one component.');
        $this->assertSame('body', $components[0]['type']);
        $this->assertSame(
            [['type' => 'text', 'text' => 'Bob'], ['type' => 'text', 'text' => 'A-1001']],
            $components[0]['parameters'],
        );
    }

    public function test_an_explicit_body_component_is_identical_to_omitting_it(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true, 'component' => 'body'],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true, 'component' => 'body'],
            ],
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $components = $this->graphBody()['template']['components'];
        $this->assertCount(1, $components);
        $this->assertSame('body', $components[0]['type']);
        $this->assertCount(2, $components[0]['parameters']);
    }

    // ==================================================================
    // 2. HEADER -- text parameter
    // ==================================================================
    public function test_a_header_text_parameter_is_emitted_as_its_own_header_component(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'string', 'required' => true, 'component' => 'header'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
            ],
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'headline' => 'Shipped!',
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $components = $this->graphBody()['template']['components'];
        $header = $this->componentOfType($components, 'header');

        $this->assertNotNull($header);
        $this->assertSame([['type' => 'text', 'text' => 'Shipped!']], $header['parameters']);
    }

    public function test_a_header_parameter_is_excluded_from_the_body_parameters(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'string', 'required' => true, 'component' => 'header'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
            ],
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'headline' => 'Shipped!',
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $body = $this->componentOfType($this->graphBody()['template']['components'], 'body');

        // Two body parameters, NOT three -- the header value must not
        // shift the positional body mapping.
        $this->assertSame(
            [['type' => 'text', 'text' => 'Bob'], ['type' => 'text', 'text' => 'A-1001']],
            $body['parameters'],
        );
    }

    public function test_components_are_ordered_header_then_body_then_buttons(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                // Deliberately declared out of Meta's own order to prove
                // the emitted order comes from the translator, not the
                // schema's array order.
                ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 0],
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'string', 'required' => true, 'component' => 'header'],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
            ],
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'tracking' => 'TRK1',
            'name' => 'Bob',
            'headline' => 'Shipped!',
            'order_id' => 'A-1001',
        ]);

        $types = array_map(
            static fn (array $c) => $c['type'],
            $this->graphBody()['template']['components'],
        );

        $this->assertSame(['header', 'body', 'button'], $types);
    }

    // ==================================================================
    // 3. HEADER -- media, from the template's OWN existing fields
    // ==================================================================
    public function test_a_configured_media_header_is_emitted_with_no_schema_variable_at_all(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'header_type' => 'image',
            'header_media_url' => 'https://cdn.example.com/banner.jpg',
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $header = $this->componentOfType($this->graphBody()['template']['components'], 'header');

        $this->assertNotNull($header);
        $this->assertSame(
            [['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/banner.jpg']]],
            $header['parameters'],
        );
    }

    public function test_a_document_media_header_carries_a_filename(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'header_type' => 'document',
            'header_media_url' => 'https://cdn.example.com/invoices/INV-77.pdf',
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $header = $this->componentOfType($this->graphBody()['template']['components'], 'header');

        $this->assertSame('document', $header['parameters'][0]['type']);
        $this->assertSame('INV-77.pdf', $header['parameters'][0]['document']['filename']);
    }

    public function test_a_send_time_media_url_overrides_the_templates_configured_one(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'header_type' => 'document',
            'header_media_url' => 'https://cdn.example.com/default.pdf',
        ]);

        TemplateMessageDispatcher::dispatch(
            $account->id,
            $template->id,
            self::RECIPIENT,
            ['name' => 'Bob', 'order_id' => 'A-1001'],
            mediaUrl: 'https://cdn.example.com/bills/customer-42.pdf',
        );

        $header = $this->componentOfType($this->graphBody()['template']['components'], 'header');

        $this->assertSame(
            'https://cdn.example.com/bills/customer-42.pdf',
            $header['parameters'][0]['document']['link'],
        );
    }

    public function test_a_media_header_with_no_url_anywhere_emits_no_header_component(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'header_type' => 'image',
            'header_media_url' => null,
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $components = $this->graphBody()['template']['components'];

        $this->assertNull($this->componentOfType($components, 'header'));
        $this->assertNotNull($this->componentOfType($components, 'body'));
    }

    public function test_a_non_http_media_url_is_never_emitted(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'header_type' => 'image',
            'header_media_url' => 'file:///etc/passwd',
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $this->assertNull($this->componentOfType($this->graphBody()['template']['components'], 'header'));
    }

    public function test_a_meta_media_header_send_is_logged_as_carrying_media(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'header_type' => 'image',
            'header_media_url' => 'https://cdn.example.com/banner.jpg',
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->first();

        $this->assertSame('sent', $log->status);
        $this->assertTrue((bool) $log->has_media);
        $this->assertSame('https://cdn.example.com/banner.jpg', $log->media_url);
    }

    public function test_a_plain_meta_template_send_is_still_logged_as_carrying_no_media(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->first();

        $this->assertFalse((bool) $log->has_media);
        $this->assertNull($log->media_url);
    }

    // ==================================================================
    // 4. BUTTON
    // ==================================================================
    public function test_a_url_button_parameter_carries_the_schema_declared_sub_type_and_index(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'tracking', 'label' => 'Tracking suffix', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 0],
            ],
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
            'tracking' => 'TRK-9',
        ]);

        $button = $this->componentOfType($this->graphBody()['template']['components'], 'button');

        $this->assertSame('button', $button['type']);
        $this->assertSame('url', $button['sub_type']);
        $this->assertSame('0', $button['index'], 'Meta sends the button index as a string.');
        $this->assertSame([['type' => 'text', 'text' => 'TRK-9']], $button['parameters']);
    }

    public function test_a_button_index_is_never_hardcoded(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'payload', 'label' => 'Payload', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'quick_reply', 'button_index' => 2],
            ],
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
            'payload' => 'YES',
        ]);

        $button = $this->componentOfType($this->graphBody()['template']['components'], 'button');

        $this->assertSame('2', $button['index']);
        $this->assertSame('quick_reply', $button['sub_type']);
    }

    public function test_multiple_buttons_become_separate_components_in_ascending_index_order(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                // Declared high-index first on purpose.
                ['key' => 'second', 'label' => 'Second', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'quick_reply', 'button_index' => 1],
                ['key' => 'first', 'label' => 'First', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 0],
            ],
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
            'second' => 'NO',
            'first' => 'TRK-1',
        ]);

        $buttons = array_values(array_filter(
            $this->graphBody()['template']['components'],
            static fn (array $c) => ($c['type'] ?? null) === 'button',
        ));

        $this->assertCount(2, $buttons);
        $this->assertSame(['0', '1'], [$buttons[0]['index'], $buttons[1]['index']]);
        $this->assertSame('TRK-1', $buttons[0]['parameters'][0]['text']);
        $this->assertSame('NO', $buttons[1]['parameters'][0]['text']);
    }

    public function test_a_button_parameter_is_excluded_from_the_body_parameters(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 0],
            ],
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
            'tracking' => 'TRK-9',
        ]);

        $body = $this->componentOfType($this->graphBody()['template']['components'], 'body');

        $this->assertCount(2, $body['parameters']);
    }

    // ==================================================================
    // 5. FOOTER -- rejected, never sent
    // ==================================================================
    public function test_a_footer_parameter_is_refused_before_any_graph_call(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'small_print', 'label' => 'Small print', 'type' => 'string', 'required' => true, 'component' => 'footer'],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
            'small_print' => 'T&C apply',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('footer', $result['message']);
        $this->assertNoGraphCall();
    }

    public function test_the_footer_component_is_not_an_accepted_schema_value(): void
    {
        $this->assertNotContains('footer', MessageTemplate::VARIABLE_COMPONENTS);
    }

    // ==================================================================
    // 6. Structural validation -- 422 before Graph, no quota consumed
    // ==================================================================
    public function test_a_button_field_with_no_sub_type_is_refused_before_any_graph_call(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_index' => 0],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob', 'order_id' => 'A-1', 'tracking' => 'T',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('button_sub_type', $result['message']);
        $this->assertNoGraphCall();
    }

    public function test_a_button_field_with_no_index_is_refused_before_any_graph_call(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url'],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob', 'order_id' => 'A-1', 'tracking' => 'T',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('button_index', $result['message']);
        $this->assertNoGraphCall();
    }

    public function test_more_than_one_header_parameter_is_refused_before_any_graph_call(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'h1', 'label' => 'H1', 'type' => 'string', 'required' => true, 'component' => 'header'],
                ['key' => 'h2', 'label' => 'H2', 'type' => 'string', 'required' => true, 'component' => 'header'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'h1' => 'A', 'h2' => 'B', 'name' => 'Bob', 'order_id' => 'A-1',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('at most one header parameter', $result['message']);
        $this->assertNoGraphCall();
    }

    public function test_a_media_header_that_also_declares_header_text_is_refused(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'header_type' => 'image',
            'header_media_url' => 'https://cdn.example.com/banner.jpg',
            'variables_schema' => [
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'string', 'required' => true, 'component' => 'header'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'headline' => 'Hi', 'name' => 'Bob', 'order_id' => 'A-1',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('media header', $result['message']);
        $this->assertNoGraphCall();
    }

    public function test_one_button_index_declaring_two_sub_types_is_refused(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'a', 'label' => 'A', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 0],
                ['key' => 'b', 'label' => 'B', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'quick_reply', 'button_index' => 0],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob', 'order_id' => 'A-1', 'a' => '1', 'b' => '2',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('more than one button_sub_type', $result['message']);
        $this->assertNoGraphCall();
    }

    public function test_a_missing_header_parameter_is_refused_before_any_graph_call(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'string', 'required' => true, 'component' => 'header'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'headline' => '', 'name' => 'Bob', 'order_id' => 'A-1',
        ]);

        $this->assertSame('missing_variables', $result['status']);
        $this->assertContains('headline', $result['missing']);
        $this->assertNoGraphCall();
    }

    public function test_a_blank_button_parameter_is_refused_before_any_graph_call(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => false],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => false],
                ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => false, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 0],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob', 'order_id' => 'A-1', 'tracking' => '   ',
        ]);

        $this->assertSame('missing_variables', $result['status']);
        $this->assertContains('tracking', $result['missing']);
        $this->assertNoGraphCall();
    }

    public function test_a_structural_validation_failure_consumes_no_quota(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'small_print', 'label' => 'Small print', 'type' => 'string', 'required' => true, 'component' => 'footer'],
            ],
        ]);

        $before = $account->currentSubscription->used_messages;

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob', 'order_id' => 'A-1', 'small_print' => 'x',
        ]);

        $this->assertSame($before, $account->currentSubscription()->first()->used_messages);
    }

    public function test_a_structural_validation_failure_is_logged_as_a_failed_dispatch(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'small_print', 'label' => 'Small print', 'type' => 'string', 'required' => true, 'component' => 'footer'],
            ],
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob', 'order_id' => 'A-1', 'small_print' => 'x',
        ]);

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->first();

        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('footer', $log->error_reason);
        $this->assertNull($log->gateway_message_id);
    }

    // ==================================================================
    // 7. Translator unit-level behaviour
    // ==================================================================
    public function test_fields_for_defaults_a_component_less_field_to_body(): void
    {
        $template = new MessageTemplate([
            'template_body' => 'x {{a}} {{b}}',
            'variables_schema' => [
                ['key' => 'a', 'label' => 'A', 'type' => 'string', 'required' => true],
                ['key' => 'b', 'label' => 'B', 'type' => 'string', 'required' => true, 'component' => 'header'],
            ],
        ]);

        $this->assertSame(['a'], array_column(TemplateComponentTranslator::fieldsFor($template, 'body'), 'key'));
        $this->assertSame(['b'], array_column(TemplateComponentTranslator::fieldsFor($template, 'header'), 'key'));
        $this->assertSame([], TemplateComponentTranslator::fieldsFor($template, 'button'));
    }

    public function test_inbound_body_parameters_never_land_on_a_header_or_button_key(): void
    {
        $template = new MessageTemplate([
            'template_body' => 'x {{name}}',
            'variables_schema' => [
                ['key' => 'headline', 'label' => 'H', 'type' => 'string', 'required' => true, 'component' => 'header'],
                ['key' => 'name', 'label' => 'N', 'type' => 'string', 'required' => true],
            ],
        ]);

        $variables = TemplateComponentTranslator::toVariables($template, [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Bob']]],
        ]);

        // The single body parameter maps onto the first BODY field, not
        // onto the header field that happens to be declared first.
        $this->assertSame(['name' => 'Bob'], $variables);
    }

    public function test_header_media_url_in_reads_the_link_back_out_of_a_built_payload(): void
    {
        $this->assertNull(TemplateComponentTranslator::headerMediaUrlIn([
            ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'Hi']]],
            ['type' => 'body', 'parameters' => []],
        ]));

        $this->assertSame('https://cdn.example.com/a.jpg', TemplateComponentTranslator::headerMediaUrlIn([
            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/a.jpg']]]],
        ]));
    }

    public function test_a_template_with_no_parameters_still_omits_components_entirely(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'template_body' => 'Your order has shipped.',
            'variables_schema' => [],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, []);

        $this->assertSame('sent', $result['status']);
        $this->assertArrayNotHasKey('components', $this->graphBody()['template']);
    }

    // ==================================================================
    // 8. Schema validation at the controller
    // ==================================================================
    public function test_the_controller_persists_the_new_component_keys(): void
    {
        $account = $this->metaAccount();

        $this->actingAs($this->superAdmin())->postJson('/api/message-templates', [
            'account_id' => $account->id,
            'title' => 'Component Template',
            'template_code' => 'COMP_'.strtoupper(bin2hex(random_bytes(3))),
            'template_body' => 'Hello {{name}}.',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'meta_template_name' => 'component_template',
            'variables_schema' => [
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'string', 'required' => true, 'component' => 'header'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 1],
            ],
        ])->assertStatus(201);

        $schema = MessageTemplate::where('title', 'Component Template')->first()->variables_schema;

        $this->assertSame('header', $schema[0]['component']);
        $this->assertSame('button', $schema[2]['component']);
        $this->assertSame('url', $schema[2]['button_sub_type']);
        $this->assertSame(1, $schema[2]['button_index']);
    }

    public function test_the_controller_rejects_an_unsupported_component_value(): void
    {
        $account = $this->metaAccount();

        $this->actingAs($this->superAdmin())->postJson('/api/message-templates', [
            'account_id' => $account->id,
            'title' => 'Bad Component',
            'template_body' => 'Hello {{name}}.',
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true, 'component' => 'footer'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('variables_schema.0.component');
    }

    public function test_the_controller_rejects_an_unsupported_button_sub_type(): void
    {
        $account = $this->metaAccount();

        $this->actingAs($this->superAdmin())->postJson('/api/message-templates', [
            'account_id' => $account->id,
            'title' => 'Bad Sub Type',
            'template_body' => 'Hello {{name}}.',
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'catalog', 'button_index' => 0],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('variables_schema.0.button_sub_type');
    }

    public function test_a_schema_with_no_component_keys_is_still_accepted_unchanged(): void
    {
        $account = $this->metaAccount();

        $this->actingAs($this->superAdmin())->postJson('/api/message-templates', [
            'account_id' => $account->id,
            'title' => 'Legacy Shape',
            'template_body' => 'Hello {{name}}.',
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
            ],
        ])->assertStatus(201);

        $schema = MessageTemplate::where('title', 'Legacy Shape')->first()->variables_schema;

        $this->assertArrayNotHasKey('component', $schema[0]);
    }

    // ==================================================================
    // 9. Regression -- QR and non-Meta-defined sends are untouched
    // ==================================================================
    public function test_a_qr_send_ignores_component_markers_entirely(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->qrAccount();
        $template = MessageTemplate::create([
            'account_id' => $account->id,
            'template_code' => 'QR_'.strtoupper(Str::random(6)),
            'title' => 'QR Template',
            'template_body' => 'Hello {{name}}, order {{order_id}} shipped.',
            'status' => 'approved',
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                // A marker that would be meaningful on Meta and must be
                // completely inert here.
                ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => false, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 0],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $this->assertSame('sent', $result['status']);
        $this->assertSame('Hello Bob, order A-1001 shipped.', $result['rendered_message']);
        $this->assertNoGraphCall();
    }

    public function test_a_non_meta_defined_template_on_a_meta_account_still_sends_as_text(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'meta_template_name' => null,
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'string', 'required' => false, 'component' => 'header'],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $this->assertSame('sent', $result['status']);

        $body = $this->graphBody();
        $this->assertSame('text', $body['type'] ?? 'text');
        $this->assertArrayNotHasKey('template', $body);
    }

    public function test_no_meta_credential_ever_appears_in_the_graph_url_or_body(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'header_type' => 'image',
            'header_media_url' => 'https://cdn.example.com/banner.jpg',
        ]);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString(self::META_TOKEN, $request->url());
            $this->assertStringNotContainsString(self::META_TOKEN, $request->body());
        }
    }

    // ==================================================================
    // 10. Phase 4 Task 8 -- WRITE-time component structure rules
    // ==================================================================

    /** @param array<int, array<string, mixed>> $schema */
    private function saveSchema(array $schema, string $title = 'Structure Check')
    {
        $account = $this->metaAccount();

        return $this->actingAs($this->superAdmin())->postJson('/api/message-templates', [
            'account_id' => $account->id,
            'title' => $title,
            'template_body' => 'Hello {{name}}.',
            'variables_schema' => $schema,
        ]);
    }

    public function test_a_button_field_saved_without_a_sub_type_is_rejected(): void
    {
        $this->saveSchema([
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
            ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_index' => 0],
        ])->assertStatus(422)->assertJsonPath('message', '"tracking" is a button parameter, so it needs a button type (URL or Quick Reply).');
    }

    public function test_a_button_field_saved_without_an_index_is_rejected(): void
    {
        $this->saveSchema([
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
            ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url'],
        ])->assertStatus(422)->assertSee('needs a button index', false);
    }

    public function test_two_button_fields_on_the_same_index_are_rejected(): void
    {
        $this->saveSchema([
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
            ['key' => 'a', 'label' => 'A', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 1],
            ['key' => 'b', 'label' => 'B', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 1],
        ])->assertStatus(422)->assertSee('Button index 1 is already used by another variable', false);
    }

    public function test_a_body_field_carrying_button_metadata_is_rejected(): void
    {
        $this->saveSchema([
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true, 'button_index' => 0],
        ])->assertStatus(422)->assertSee('cannot carry button settings', false);
    }

    public function test_a_header_field_carrying_button_metadata_is_rejected(): void
    {
        $this->saveSchema([
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
            ['key' => 'headline', 'label' => 'Headline', 'type' => 'string', 'required' => true, 'component' => 'header', 'button_sub_type' => 'url'],
        ])->assertStatus(422)->assertSee('cannot carry button settings', false);
    }

    public function test_two_buttons_on_distinct_indexes_are_accepted(): void
    {
        $this->saveSchema([
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
            ['key' => 'a', 'label' => 'A', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url', 'button_index' => 0],
            ['key' => 'b', 'label' => 'B', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'quick_reply', 'button_index' => 1],
        ], 'Two Buttons')->assertStatus(201);

        $schema = MessageTemplate::where('title', 'Two Buttons')->first()->variables_schema;
        $this->assertSame([0, 1], [$schema[1]['button_index'], $schema[2]['button_index']]);
    }

    public function test_an_explicit_body_component_may_still_be_saved(): void
    {
        $this->saveSchema([
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true, 'component' => 'body'],
        ], 'Explicit Body')->assertStatus(201);
    }

    public function test_the_update_path_enforces_the_same_component_rules(): void
    {
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, ['template_body' => 'Hello {{name}}.']);

        $this->actingAs($this->superAdmin())->putJson("/api/message-templates/{$template->id}", [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'string', 'required' => true, 'component' => 'button', 'button_sub_type' => 'url'],
            ],
        ])->assertStatus(422)->assertSee('needs a button index', false);
    }

    public function test_a_legacy_schema_still_updates_cleanly(): void
    {
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, ['template_body' => 'Hello {{name}}.']);

        $this->actingAs($this->superAdmin())->putJson("/api/message-templates/{$template->id}", [
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
            ],
        ])->assertStatus(200);

        $this->assertArrayNotHasKey('component', $template->fresh()->variables_schema[0]);
    }
}
