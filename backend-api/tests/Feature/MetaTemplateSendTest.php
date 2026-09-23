<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Templates\TemplateComponentTranslator;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 6 -- Meta WhatsApp template SENDING.
 *
 * Closes the blocker Task 5 reported: a Meta-defined template was being
 * rendered locally and sent as a free-form text message, which Meta
 * rejects outside the 24-hour service window -- the exact situation
 * templates exist for.
 *
 * No second template-sending system was built. TemplateMessageDispatcher
 * still owns the whole flow (authorization -> provider resolution ->
 * validation -> quota -> dispatch -> send -> log), TemplateComponentTranslator
 * gained the inverse of the mapping it already performed, and
 * MetaCloudApiDriver needed NO change at all: it already honours a `type`
 * override in $metaData and strips the default text body when one is set.
 *
 * Only `isMetaDefined() && engine === 'meta'` switches payload. Every QR
 * send, and a Meta account sending an ordinary non-Meta-defined template,
 * keeps the previous rendered-text behaviour -- both asserted below.
 */
class MetaTemplateSendTest extends TestCase
{
    use RefreshDatabase;


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
    }

    private const META_TOKEN = 'EAAG_tenant_token_never_leak_me_0123456789';

    private const RECIPIENT = '919999999999';

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

    private function metaAccount(string $phoneId = '109876543210987'): Account
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'meta');

        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => $phoneId,
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
                'messages' => [['id' => 'wamid.TEMPLATE_SENT']],
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

    // ------------------------------------------------------------------
    // The payload itself
    // ------------------------------------------------------------------
    public function test_a_meta_defined_template_is_sent_as_a_graph_template_payload(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);

        $result = TemplateMessageDispatcher::dispatch(
            $account->id,
            $template->id,
            self::RECIPIENT,
            ['name' => 'Bob', 'order_id' => 'A-1001'],
        );

        $this->assertSame('sent', $result['status']);

        $body = $this->graphBody();
        $this->assertSame('whatsapp', $body['messaging_product']);
        $this->assertSame('template', $body['type']);
        $this->assertSame('919999999999', $body['to']);
        // The free-form text body must be gone entirely.
        $this->assertArrayNotHasKey('text', $body);
        // ...and so must the engine-internal hints.
        $this->assertArrayNotHasKey('template_id', $body);
        $this->assertArrayNotHasKey('media_url', $body);
    }

    public function test_the_payload_carries_the_configured_name_and_language_code(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, ['meta_template_name' => 'shipping_notice', 'language' => 'pt_BR']);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1',
        ]);

        $body = $this->graphBody();
        $this->assertSame('shipping_notice', $body['template']['name']);
        $this->assertSame(['code' => 'pt_BR'], $body['template']['language']);
    }

    public function test_named_variables_become_positional_body_parameters_in_schema_order(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'template_body' => 'Hi {{name}}, order {{order_id}} ships on {{eta}}.',
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => true],
                ['key' => 'eta', 'label' => 'ETA', 'type' => 'string', 'required' => true],
            ],
        ]);

        // Deliberately supplied OUT of schema order — position must follow
        // the schema, not the caller's array.
        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'eta' => 'Friday',
            'order_id' => 'A-1001',
            'name' => 'Bob',
        ]);

        $components = $this->graphBody()['template']['components'];
        $this->assertCount(1, $components);
        $this->assertSame('body', $components[0]['type']);
        $this->assertSame(
            [
                ['type' => 'text', 'text' => 'Bob'],
                ['type' => 'text', 'text' => 'A-1001'],
                ['type' => 'text', 'text' => 'Friday'],
            ],
            $components[0]['parameters'],
        );
    }

    public function test_a_template_with_no_variables_omits_components_entirely(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, ['template_body' => 'Your order has shipped.']);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, []);

        $body = $this->graphBody();
        $this->assertSame('template', $body['type']);
        // Meta rejects an empty components array on a parameterless template.
        $this->assertArrayNotHasKey('components', $body['template']);
    }

    public function test_the_translator_round_trips_a_value_back_to_its_own_key(): void
    {
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);
        $variables = ['name' => 'Bob', 'order_id' => 'A-1001'];

        $components = TemplateComponentTranslator::toComponents($template, $variables);

        $this->assertSame($variables, TemplateComponentTranslator::toVariables($template, $components));
    }

    // ------------------------------------------------------------------
    // Validation before the Graph call
    // ------------------------------------------------------------------
    public function test_a_missing_variable_is_rejected_without_calling_graph(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => 'Bob']);

        $this->assertSame('missing_variables', $result['status']);
        $this->assertContains('order_id', $result['missing']);
        Http::assertNothingSent();
    }

    public function test_a_blank_variable_is_rejected_without_calling_graph(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, [
            'template_body' => 'Hi {{name}}, ref {{ref}}.',
            // 'ref' is optional in the schema, so the plain-text path would
            // substitute '' — but an empty positional parameter is not a
            // valid Meta template send.
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ['key' => 'ref', 'label' => 'Ref', 'type' => 'string', 'required' => false],
            ],
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'ref' => '   ',
        ]);

        $this->assertSame('missing_variables', $result['status']);
        $this->assertContains('ref', $result['missing']);
        Http::assertNothingSent();
    }

    public function test_a_meta_template_with_no_language_is_rejected_without_calling_graph(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        // Written directly, bypassing the controller guard that normally
        // prevents this pairing, to prove the send path defends itself too.
        $template = $this->metaTemplate($account, ['language' => null]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('language code', $result['message']);
        Http::assertNothingSent();
    }

    public function test_no_quota_is_consumed_when_validation_fails(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);
        $before = $account->fresh()->currentSubscription->used_messages;

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => 'Bob']);

        $this->assertSame($before, $account->fresh()->currentSubscription->used_messages);
    }

    // ------------------------------------------------------------------
    // Preserved behaviour: quota, logging, WAMID, errors
    // ------------------------------------------------------------------
    public function test_a_successful_template_send_consumes_quota_and_stores_the_wamid(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);
        $before = $account->fresh()->currentSubscription->used_messages;

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1001',
        ]);

        $this->assertSame('sent', $result['status']);
        $this->assertSame($before + 1, $account->fresh()->currentSubscription->used_messages);

        $log = MessageDispatchLog::latest('id')->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame($account->id, $log->account_id);
        $this->assertSame('template', $log->reference_type);
        $this->assertSame($template->id, $log->reference_id);
        $this->assertSame('Order Update', $log->template_name);
        $this->assertSame('wamid.TEMPLATE_SENT', $log->gateway_message_id);
        $this->assertSame($log->id, $result['dispatch_log_id']);
    }

    public function test_a_graph_rejection_surfaces_a_safe_error_and_consumes_no_quota(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Template name does not exist in the translation.', 'code' => 132001],
            ], 400),
        ]);
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);
        $before = $account->fresh()->currentSubscription->used_messages;

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Template name does not exist in the translation.', $result['message']);
        $this->assertSame($before, $account->fresh()->currentSubscription->used_messages);
        $this->assertSame('failed', MessageDispatchLog::latest('id')->firstOrFail()->status);
    }

    public function test_an_unreachable_graph_api_is_reported_safely(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host');
        });
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringNotContainsString('cURL', $result['message']);
    }

    // ------------------------------------------------------------------
    // Credential hygiene
    // ------------------------------------------------------------------
    public function test_the_token_travels_only_in_the_authorization_header_and_never_leaks(): void
    {
        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message.' '.json_encode($message->context);
        });

        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1',
        ]);

        Http::assertSent(function (ClientRequest $request) {
            if (! str_contains($request->url(), 'graph.facebook.com')) {
                return false;
            }
            $this->assertSame('Bearer '.self::META_TOKEN, $request->header('Authorization')[0]);
            $this->assertStringNotContainsString(self::META_TOKEN, $request->url());
            $this->assertStringNotContainsString(self::META_TOKEN, json_encode($request->data()));

            return true;
        });

        $this->assertStringNotContainsString(self::META_TOKEN, json_encode($result));
        $this->assertStringNotContainsString(self::META_TOKEN, implode("\n", $lines));
        $this->assertStringNotContainsString(
            self::META_TOKEN,
            json_encode(MessageDispatchLog::latest('id')->firstOrFail()->getAttributes()),
        );
    }

    // ------------------------------------------------------------------
    // Tenant isolation / authorization / inactive account
    // ------------------------------------------------------------------
    public function test_another_tenants_template_cannot_be_sent(): void
    {
        $this->fakeGraphSuccess();
        $owner = $this->metaAccount('111111111111111');
        $stranger = $this->metaAccount('222222222222222');
        $template = $this->metaTemplate($owner);

        $result = TemplateMessageDispatcher::dispatch($stranger->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1',
        ]);

        $this->assertSame('not_found', $result['status']);
        Http::assertNothingSent();
    }

    public function test_a_send_uses_the_senders_own_phone_number_id(): void
    {
        $this->fakeGraphSuccess();
        $mine = $this->metaAccount('111111111111111');
        $this->metaAccount('222222222222222');
        $template = $this->metaTemplate($mine);

        TemplateMessageDispatcher::dispatch($mine->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1',
        ]);

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/111111111111111/messages'));
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/222222222222222/messages'));
    }

    public function test_an_inactive_account_cannot_send_a_meta_template(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account);
        $account->forceFill(['status' => 'inactive'])->save();

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1',
        ]);

        $this->assertSame('quota_exhausted', $result['status']);
        Http::assertNothingSent();
    }

    public function test_the_public_api_contract_is_unchanged_for_a_meta_template_send(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $template = $this->metaTemplate($account, ['template_code' => 'API_TPL']);

        $plainKey = 'wasaas_live_'.Str::random(40);
        ApiKey::create([
            'account_id' => $account->id,
            'name' => 'k',
            'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey),
        ]);

        $response = $this->withHeader('X-API-KEY', $plainKey)->postJson('/api/v1/messages/send-template', [
            'template_code' => 'API_TPL',
            'recipient_phone' => self::RECIPIENT,
            'variables' => ['name' => 'Bob', 'order_id' => 'A-1'],
        ]);

        // The pre-existing {status: true} envelope, unchanged.
        $response->assertStatus(200);
        $response->assertExactJson(['status' => true, 'message' => 'Message sent.']);
        $this->assertSame('template', $this->graphBody()['type']);
    }

    public function test_the_public_api_returns_422_when_a_variable_is_missing(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();
        $this->metaTemplate($account, [
            'template_code' => 'API_TPL2',
            'variables_schema' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => false],
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'string', 'required' => false],
            ],
        ]);

        $plainKey = 'wasaas_live_'.Str::random(40);
        ApiKey::create([
            'account_id' => $account->id,
            'name' => 'k',
            'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey),
        ]);

        // Both variables are optional at the request-validation layer, so
        // the request passes validation and the Meta-specific check is what
        // refuses it.
        $response = $this->withHeader('X-API-KEY', $plainKey)->postJson('/api/v1/messages/send-template', [
            'template_code' => 'API_TPL2',
            'recipient_phone' => self::RECIPIENT,
            'variables' => ['name' => 'Bob'],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => false]);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    // ------------------------------------------------------------------
    // Regressions: QR, and ordinary Meta text
    // ------------------------------------------------------------------
    public function test_a_qr_template_send_is_completely_unchanged(): void
    {
        Http::fake([
            '*/api/message/send' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'must not be called']], 500),
        ]);
        $account = $this->qrAccount();

        $template = MessageTemplate::create([
            'account_id' => $account->id,
            'template_code' => 'QR_TPL',
            'title' => 'QR template',
            'template_body' => 'Hi {{name}}, order {{order_id}}.',
            'status' => 'approved',
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, [
            'name' => 'Bob',
            'order_id' => 'A-1',
        ]);

        $this->assertSame('sent', $result['status']);

        Http::assertSent(function (ClientRequest $request) {
            if (! str_contains($request->url(), '/api/message/send')) {
                return false;
            }
            // Still the rendered plain-text payload with the engine hint.
            $this->assertSame('Hi Bob, order A-1.', $request->data()['message']);
            $this->assertArrayHasKey('template_id', $request->data());

            return true;
        });
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    /** A Meta account sending a template that is NOT Meta-defined keeps the old text path. */
    public function test_a_non_meta_defined_template_on_a_meta_account_still_sends_as_text(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();

        $template = MessageTemplate::create([
            'account_id' => $account->id,
            'template_code' => 'PLAIN_TPL',
            'title' => 'Plain',
            'template_body' => 'Hi {{name}}.',
            'status' => 'approved',
            // No meta_template_name -> not Meta-defined.
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => 'Bob']);

        $this->assertSame('sent', $result['status']);

        $body = $this->graphBody();
        $this->assertSame('text', $body['type']);
        $this->assertSame('Hi Bob.', $body['text']['body']);
        $this->assertArrayNotHasKey('template', $body);
    }

    public function test_an_ordinary_meta_free_form_text_message_is_unchanged(): void
    {
        $this->fakeGraphSuccess();
        $account = $this->metaAccount();

        $result = \App\Services\WhatsApp\DirectMessageDispatcher::dispatch(
            $account->id,
            self::RECIPIENT,
            'text',
            ['body' => 'Just a normal message.'],
        );

        $this->assertSame('sent', $result['status']);

        $body = $this->graphBody();
        $this->assertSame('text', $body['type']);
        $this->assertSame('Just a normal message.', $body['text']['body']);
        $this->assertArrayNotHasKey('template', $body);
    }
}
