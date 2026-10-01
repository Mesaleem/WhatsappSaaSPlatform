<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ActivityLog;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowVersion;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Support\JourneySecrets;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F-5.3 — every credential an `api` journey node can carry is detected,
 * encrypted at rest, masked in every response and audit row.
 *
 * The audit found four bypasses of the P5-6 rules: `Ocp-Apim-Subscription-Key`,
 * `x-functions-key`, `?code=` and credentials inside the request body. The
 * SAME mechanism (JourneySecrets, applied by the flow models) now covers
 * them; there is no second secret system.
 */
class JourneyApiNodeSecretCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const FLOWS = '/api/whatsapp/flows';

    private const S1 = 'S1_ocp_apim_TOPSECRET_aaaa1111';

    private const S2 = 'S2_functions_TOPSECRET_bbbb2222';

    private const S3 = 'S3_code_TOPSECRET_cccc3333';

    private const S4 = 'S4_body_secret_TOPSECRET_dddd4444';

    private const S5 = 'S5_body_password_TOPSECRET_eeee5555';

    private const S6 = 'S6_nested_token_TOPSECRET_ffff6666';

    private const S7 = 'S7_form_secret_TOPSECRET_gggg7777';

    private const S8 = 'S8_legacy_url_TOPSECRET_hhhh8888';

    /** the default fixture node's own credentials (see apiNode()) */
    private const SECRET = 'sk_live_TOPSECRET_9f8e7d6c5b4a';

    private const QUERY_SECRET = 'qk_TOPSECRET_1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake(fn () => Http::response(['success' => true], 200));
    }

    // ================================================================== fixtures

    private function account(): Account
    {
        $account = Account::factory()->create();
        $p = PlanCatalog::find('growth');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth',
            'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0,
            'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'external_api')->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => null],
        );

        return $account->fresh();
    }

    private function user(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function apiNode(array $headers = null, array $query = null, string $url = 'https://api.example.test/v1/orders'): array
    {
        return ['id' => 'api1', 'type' => 'api', 'data' => [
            'method' => 'POST',
            'url' => $url,
            'headers' => $headers ?? [
                ['key' => 'Authorization', 'value' => 'Bearer '.self::SECRET],
                ['key' => 'Content-Type', 'value' => 'application/json'],
            ],
            'query' => $query ?? [
                ['key' => 'api_key', 'value' => self::QUERY_SECRET],
                ['key' => 'page', 'value' => '1'],
            ],
            'body' => '{"order":"{{ order_id }}"}',
            'credentialRef' => 'orders-api',
        ]];
    }

    /** `api` nodes are not runtime-executable (P5-7), so a journey holding one is a draft. */
    private function payload(array $apiNode, array $extra = []): array
    {
        return $extra + [
            'name' => 'Orders', 'trigger_type' => 'keyword', 'trigger_value' => 'order', 'is_active' => true, 'publish' => false,
            'graph_data' => [
                'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'm', 'type' => 'message', 'data' => ['text' => 'Checking your order']], $apiNode],
                'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'm'], ['id' => 'e2', 'source' => 'm', 'target' => 'api1']],
            ],
        ];
    }

    private function create(Account $account, ?User $user = null): WhatsAppFlow
    {
        $id = $this->actingAs($user ?? $this->user($account))->postJson(self::FLOWS, $this->payload($this->apiNode()))
            ->assertCreated()->json('data.id');

        return WhatsAppFlow::findOrFail($id);
    }

    /** @return array<string, mixed> the stored api node */
    private function storedApiNode(WhatsAppFlow|WhatsAppFlowVersion $model): array
    {
        return collect($model->fresh()->graph_data['nodes'])->firstWhere('id', 'api1');
    }


    /** @return list<string> */
    private function allSecrets(): array
    {
        return [self::S1, self::S2, self::S3, self::S4, self::S5, self::S6, self::S7, self::S8, self::SECRET, self::QUERY_SECRET];
    }

    private function assertNoSecrets(string $haystack, string $where): void
    {
        foreach ($this->allSecrets() as $secret) {
            $this->assertStringNotContainsString($secret, $haystack, "plaintext {$secret} in {$where}");
        }
        $this->assertStringNotContainsString(JourneySecrets::PREFIX, $haystack, "ciphertext in {$where}");
    }

    /** For raw database rows, where ciphertext is EXPECTED but plaintext never is. */
    private function assertNoPlaintext(string $haystack, string $where): void
    {
        foreach ($this->allSecrets() as $secret) {
            $this->assertStringNotContainsString($secret, $haystack, "plaintext {$secret} in {$where}");
        }
    }

    private function node(WhatsAppFlow|WhatsAppFlowVersion $m): array
    {
        return collect($m->fresh()->graph_data['nodes'])->firstWhere('id', 'api1');
    }

    /** every read surface a client or an auditor can see */
    private function assertEveryReadIsClean(User $user, WhatsAppFlow $flow): void
    {
        $versionId = $flow->versions()->value('id');

        foreach ([self::FLOWS, self::FLOWS."/{$flow->id}", self::FLOWS."/{$flow->id}/versions", self::FLOWS."/{$flow->id}/versions/{$versionId}"] as $url) {
            $this->assertNoSecrets($this->actingAs($user)->getJson($url)->assertOk()->getContent(), $url);
        }
    }

    private function headersFor(array $pairs): array
    {
        return array_map(fn ($k, $v) => ['key' => $k, 'value' => $v], array_keys($pairs), array_values($pairs));
    }

    // ================================================================== headers (case-insensitive, vendor-specific)

    public function test_vendor_and_mixed_case_credential_headers_are_encrypted_masked_and_never_returned(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $headers = $this->headersFor([
            'Ocp-Apim-Subscription-Key' => self::S1,
            'x-functions-key' => self::S2,
            'X-API-KEY' => 'xak_'.self::S1,
            'x-api-key' => 'xak2_'.self::S1,
            'AUTHORIZATION' => 'Bearer '.self::S2,
            'authorization' => 'Basic '.self::S2,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        $id = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($this->apiNode($headers, [])))->assertCreated()->json('data.id');
        $flow = WhatsAppFlow::findOrFail($id);
        $stored = $this->node($flow)['data']['headers'];

        foreach ($stored as $pair) {
            if (in_array($pair['key'], ['Content-Type', 'Accept'], true)) {
                $this->assertSame('application/json', $pair['value'], 'ordinary configuration stays readable');

                continue;
            }

            $this->assertTrue(JourneySecrets::isEncrypted($pair['value']), "{$pair['key']} must be encrypted at rest");
        }

        $this->assertSame(self::S1, JourneySecrets::reveal($stored[0]['value']));
        $this->assertSame(self::S2, JourneySecrets::reveal($stored[1]['value']));
        $this->assertNoPlaintext((string) DB::table('whatsapp_flows')->where('id', $flow->id)->value('graph_data'), 'whatsapp_flows row');
        $this->assertNoPlaintext((string) DB::table('whatsapp_flow_versions')->where('flow_id', $flow->id)->pluck('graph_data')->implode(''), 'whatsapp_flow_versions rows');

        $this->assertEveryReadIsClean($user, $flow);

        $shown = collect($this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data.nodes'))->firstWhere('id', 'api1')['data']['headers'];
        $this->assertSame(['key' => 'Ocp-Apim-Subscription-Key', 'value' => JourneySecrets::MASK, 'masked' => true], $shown[0]);
        $this->assertSame(['key' => 'x-functions-key', 'value' => JourneySecrets::MASK, 'masked' => true], $shown[1]);
        $this->assertSame(['key' => 'Content-Type', 'value' => 'application/json'], $shown[6]);
    }

    public function test_name_to_value_map_shaped_headers_and_query_are_protected_in_their_own_shape(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $node = $this->apiNode(null, null);
        $node['data']['headers'] = ['x-functions-key' => self::S2, 'Accept' => 'application/json'];
        $node['data']['query'] = ['code' => self::S3, 'page' => '2'];

        $id = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($node))->assertCreated()->json('data.id');
        $flow = WhatsAppFlow::findOrFail($id);
        $stored = $this->node($flow)['data'];

        $this->assertTrue(JourneySecrets::isEncrypted($stored['headers']['x-functions-key']));
        $this->assertSame(self::S2, JourneySecrets::reveal($stored['headers']['x-functions-key']));
        $this->assertSame('application/json', $stored['headers']['Accept']);
        $this->assertTrue(JourneySecrets::isEncrypted($stored['query']['code']));
        $this->assertSame('2', $stored['query']['page']);
        $this->assertEveryReadIsClean($user, $flow);

        $shown = collect($this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data.nodes'))->firstWhere('id', 'api1')['data'];
        $this->assertSame(JourneySecrets::MASK, $shown['headers']['x-functions-key']);
        $this->assertSame(JourneySecrets::MASK, $shown['query']['code']);
        $this->assertSame('2', $shown['query']['page']);

        // masked unchanged → kept
        $graph = $this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data');
        $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertOk();
        $this->assertSame(self::S2, JourneySecrets::reveal($this->node($flow)['data']['headers']['x-functions-key']));
    }

    // ================================================================== query parameters

    public function test_credential_query_parameters_are_encrypted_and_masked(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $query = $this->headersFor(['code' => self::S3, 'Code' => self::S3.'x', 'token' => self::S3.'t', 'api_key' => self::S3.'a', 'apikey' => self::S3.'k', 'access_token' => self::S3.'z', 'page' => '1']);

        $id = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($this->apiNode([], $query)))->assertCreated()->json('data.id');
        $flow = WhatsAppFlow::findOrFail($id);

        foreach ($this->node($flow)['data']['query'] as $pair) {
            if ($pair['key'] === 'page') {
                $this->assertSame('1', $pair['value']);

                continue;
            }

            $this->assertTrue(JourneySecrets::isEncrypted($pair['value']), "query {$pair['key']} must be encrypted");
        }

        $this->assertEveryReadIsClean($user, $flow);
    }

    // ================================================================== URLs

    public function test_a_credential_in_a_new_url_is_refused_and_never_stored(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        foreach (['https://api.example.test/run?code='.self::S3, 'https://api.example.test/run?x=1&access_token='.self::S3, 'https://api.example.test/run?apikey='.self::S3, 'https://user:'.self::S3.'@api.example.test/run'] as $url) {
            $response = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($this->apiNode([], [], $url)))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.2.data.url');
            $this->assertNoSecrets($response->getContent(), "422 for {$url}");
        }

        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_a_credential_already_stored_in_a_url_is_masked_everywhere_it_is_shown(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $legacyUrl = 'https://api.example.test/run?page=1&code='.self::S8.'&x=2';
        $flow = WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Legacy', 'trigger_type' => 'keyword', 'trigger_value' => 'legacy', 'is_active' => false,
            'graph_data' => $this->payload($this->apiNode([], [], $legacyUrl))['graph_data'],
        ]);

        $this->assertEveryReadIsClean($user, $flow);

        $node = collect($this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data.nodes'))->firstWhere('id', 'api1');
        $this->assertSame('https://api.example.test/run?page=1&code='.JourneySecrets::MASK.'&x=2', $node['data']['url']);

        // Re-saving the masked URL is refused with a message that does not echo a value (the user must move it to an encrypted field).
        $graph = $this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data');
        $response = $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertStatus(422);
        $this->assertNoSecrets($response->getContent(), 'URL 422');
    }

    // ================================================================== request body

    private function jsonBody(): string
    {
        return json_encode([
            'client_id' => 'public-client-id',
            'client_secret' => self::S4,
            'auth' => ['user' => 'svc-user', 'password' => self::S5],
            'items' => [['token' => self::S6, 'sku' => 'A-1']],
            'note' => 'hello world',
            'order' => '{{ order_id }}',
            'access_token' => '{{ saved_token }}',
        ], JSON_PRETTY_PRINT);
    }

    public function test_only_credential_bearing_body_fields_are_encrypted_the_rest_stays_readable(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $node = $this->apiNode([], []);
        $node['data']['body'] = $this->jsonBody();

        $id = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($node))->assertCreated()->json('data.id');
        $flow = WhatsAppFlow::findOrFail($id);
        $body = json_decode($this->node($flow)['data']['body'], true);

        $this->assertSame('public-client-id', $body['client_id']);
        $this->assertSame('hello world', $body['note']);
        $this->assertSame('A-1', $body['items'][0]['sku']);
        $this->assertSame('{{ order_id }}', $body['order'], 'a {{variable}} reference is a pointer, not a secret');
        $this->assertSame('{{ saved_token }}', $body['access_token']);

        $this->assertSame(self::S4, JourneySecrets::reveal($body['client_secret']));
        $this->assertSame(self::S5, JourneySecrets::reveal($body['auth']['password']));
        $this->assertTrue(JourneySecrets::isEncrypted($body['auth']['user']), 'everything under a credential-named object is secret');
        $this->assertSame(self::S6, JourneySecrets::reveal($body['items'][0]['token']));

        $this->assertNoPlaintext((string) DB::table('whatsapp_flows')->where('id', $flow->id)->value('graph_data'), 'whatsapp_flows row');
        $this->assertNoPlaintext((string) DB::table('whatsapp_flow_versions')->where('flow_id', $flow->id)->pluck('graph_data')->implode(''), 'whatsapp_flow_versions rows');
        $this->assertEveryReadIsClean($user, $flow);

        $shown = json_decode(collect($this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data.nodes'))->firstWhere('id', 'api1')['data']['body'], true);
        $this->assertSame(JourneySecrets::MASK, $shown['client_secret']);
        $this->assertSame(JourneySecrets::MASK, $shown['auth']['password']);
        $this->assertSame(JourneySecrets::MASK, $shown['items'][0]['token']);
        $this->assertSame('public-client-id', $shown['client_id']);
        $this->assertSame('hello world', $shown['note']);
    }

    public function test_a_form_encoded_body_is_protected_field_by_field(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $node = $this->apiNode([], []);
        $node['data']['body'] = 'grant_type=client_credentials&client_secret='.self::S7.'&scope=read';

        $id = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($node))->assertCreated()->json('data.id');
        $flow = WhatsAppFlow::findOrFail($id);

        $stored = $this->node($flow)['data']['body'];
        $this->assertStringStartsWith('grant_type=client_credentials&client_secret='.JourneySecrets::PREFIX, $stored);
        $this->assertStringEndsWith('&scope=read', $stored);
        $this->assertNoPlaintext((string) DB::table('whatsapp_flows')->where('id', $flow->id)->value('graph_data'), 'whatsapp_flows row');
        $this->assertEveryReadIsClean($user, $flow);

        $shown = collect($this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data.nodes'))->firstWhere('id', 'api1')['data']['body'];
        $this->assertSame('grant_type=client_credentials&client_secret='.JourneySecrets::MASK.'&scope=read', $shown);
    }

    public function test_body_update_semantics_keep_replace_and_reject(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $node = $this->apiNode([], []);
        $node['data']['body'] = $this->jsonBody();
        $id = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($node))->assertCreated()->json('data.id');
        $flow = WhatsAppFlow::findOrFail($id);
        $before = json_decode($this->node($flow)['data']['body'], true)['client_secret'];

        // masked value submitted unchanged → the stored secret is kept (and never returned)
        $graph = $this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data');
        $graph['nodes'][1]['data']['text'] = 'Edited elsewhere';
        $response = $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertOk();
        $this->assertNoSecrets($response->getContent(), 'update response');
        $this->assertSame($before, json_decode($this->node($flow)['data']['body'], true)['client_secret']);
        $this->assertSame(self::S4, JourneySecrets::reveal(json_decode($this->node($flow)['data']['body'], true)['client_secret']));

        // a new secret replaces it
        $body = json_decode($graph['nodes'][2]['data']['body'], true);
        $body['client_secret'] = 'rotated-body-secret-99';
        $graph['nodes'][2]['data']['body'] = json_encode($body);
        $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertOk();
        $this->assertSame('rotated-body-secret-99', JourneySecrets::reveal(json_decode($this->node($flow)['data']['body'], true)['client_secret']));
        // the untouched masked sibling still resolves to its original secret
        $this->assertSame(self::S5, JourneySecrets::reveal(json_decode($this->node($flow)['data']['body'], true)['auth']['password']));

        // an unknown fake mask is rejected and never stored
        $bad = json_decode($graph['nodes'][2]['data']['body'], true);
        $bad['brand_new_secret'] = JourneySecrets::MASK;
        $graph['nodes'][2]['data']['body'] = json_encode($bad);
        $response = $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.2.data.body');
        $this->assertNoSecrets($response->getContent(), 'body mask 422');
        $this->assertStringNotContainsString('brand_new_secret', json_encode($this->node($flow)['data']['body']));

        // ...also on a brand-new journey, where there is nothing to keep
        $new = $this->apiNode([], []);
        $new['data']['body'] = '{"client_secret":"'.JourneySecrets::MASK.'"}';
        $this->actingAs($user)->postJson(self::FLOWS, $this->payload($new))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.2.data.body');
    }

    public function test_header_update_semantics_for_the_newly_covered_names(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $headers = $this->headersFor(['Ocp-Apim-Subscription-Key' => self::S1, 'x-functions-key' => self::S2]);
        $id = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($this->apiNode($headers, [])))->assertCreated()->json('data.id');
        $flow = WhatsAppFlow::findOrFail($id);

        $graph = $this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data');
        $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertOk();
        $this->assertSame(self::S1, JourneySecrets::reveal($this->node($flow)['data']['headers'][0]['value']));

        $graph['nodes'][2]['data']['headers'][1]['value'] = 'new-functions-key';
        unset($graph['nodes'][2]['data']['headers'][1]['masked']);
        $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertOk();
        $this->assertSame('new-functions-key', JourneySecrets::reveal($this->node($flow)['data']['headers'][1]['value']));
        $this->assertSame(self::S1, JourneySecrets::reveal($this->node($flow)['data']['headers'][0]['value']));

        $graph['nodes'][2]['data']['headers'][0]['key'] = 'Renamed-Subscription-Key';
        $graph['nodes'][2]['data']['headers'][0]['value'] = JourneySecrets::MASK;
        $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertStatus(422);
    }

    // ================================================================== publish + audit

    public function test_publish_paths_and_the_audit_trail_never_expose_a_secret(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $node = $this->apiNode($this->headersFor(['Ocp-Apim-Subscription-Key' => self::S1, 'x-functions-key' => self::S2]), $this->headersFor(['code' => self::S3]));
        $node['data']['body'] = $this->jsonBody();

        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, false);

        try {
            $id = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($node))->assertCreated()->json('data.id');
            $flow = WhatsAppFlow::findOrFail($id);
            $versionId = $flow->versions()->value('id');

            // publish-related responses (an api node is not runtime-executable, so these are refused — the refusal must not echo anything either)
            $this->assertNoSecrets($this->actingAs($user)->postJson(self::FLOWS."/{$flow->id}/versions/{$versionId}/publish")->getContent(), 'publish version');
            $this->assertNoSecrets($this->actingAs($user)->postJson(self::FLOWS."/{$flow->id}/toggle")->getContent(), 'toggle');
            $this->assertNoSecrets($this->actingAs($user)->postJson(self::FLOWS, $this->payload($node, ['publish' => true]))->getContent(), 'create+publish');

            $graph = $this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data');
            $body = json_decode($graph['nodes'][2]['data']['body'], true);
            $body['client_secret'] = self::S4.'-v2';
            $graph['nodes'][2]['data']['body'] = json_encode($body);
            $this->assertNoSecrets($this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->getContent(), 'update response');
            $this->actingAs($user)->deleteJson(self::FLOWS."/{$flow->id}")->assertOk();
        } finally {
            $property->setValue($this->app, true);
        }

        $audit = ActivityLog::where('module_name', 'WhatsApp Flows')->get();
        $this->assertContains('create', $audit->pluck('action_type')->all(), 'the audit trail itself is intact');

        $raw = DB::table('activity_logs')->pluck('old_values')->implode('').DB::table('activity_logs')->pluck('new_values')->implode('');
        foreach ($this->allSecrets() as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "{$secret} in activity_logs");
            $this->assertStringNotContainsString($secret.'-v2', $raw);
        }
        $this->assertStringNotContainsString(JourneySecrets::PREFIX, $raw, 'ciphertext in activity_logs');
        $this->assertStringContainsString('public-client-id', $raw, 'non-secret configuration is still audited');
    }

    public function test_secret_detection_matches_names_case_insensitively(): void
    {
        foreach (['Ocp-Apim-Subscription-Key', 'OCP-APIM-SUBSCRIPTION-KEY', 'x-functions-key', 'X-Functions-Key', 'X-API-KEY', 'x-api-key', 'X-Api-Key', 'Authorization', 'AUTHORIZATION', 'code', 'CODE', 'token', 'api_key', 'apikey', 'access_token', 'client_secret', 'Password'] as $name) {
            $this->assertTrue(JourneySecrets::isSensitiveName($name), "{$name} must be treated as a credential");
        }
        foreach (['Content-Type', 'Accept', 'page', 'order', 'client_id', 'sku', 'User-Agent', 'grant_type', 'scope'] as $name) {
            $this->assertFalse(JourneySecrets::isSensitiveName($name), "{$name} is ordinary configuration");
        }
    }
}
