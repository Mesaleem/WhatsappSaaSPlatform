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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P5-6 — Journey secret / configuration protection.
 *
 * The only journey fields meant to carry credentials are the `api`
 * node's credential-named headers / query parameters (JourneySecrets).
 * Everything is driven through the real Journey API; encryption is
 * verified through the application layer (the model + JourneySecrets::
 * reveal()), never by reading a plaintext value back from a response.
 */
class JourneySecretProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const FLOWS = '/api/whatsapp/flows';

    private const SECRET = 'sk_live_TOPSECRET_9f8e7d6c5b4a';

    private const QUERY_SECRET = 'qk_TOPSECRET_1234567890';

    /** @var list<string> every log line written during the test (message + context) */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        // Built before any actingAs() (the documented Spatie guard constraint).
        Role::firstOrCreate(['name' => 'journey_viewer'])->givePermissionTo('whatsapp.view');

        Http::fake(fn () => Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200));
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
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

    private function assertNoSecretIn(string $haystack, string $where): void
    {
        $this->assertStringNotContainsString(self::SECRET, $haystack, "plaintext header secret in {$where}");
        $this->assertStringNotContainsString(self::QUERY_SECRET, $haystack, "plaintext query secret in {$where}");
    }

    // ================================================================== 1–2 save + storage

    public function test_1_and_2_an_authorized_save_stores_credential_values_encrypted_and_nothing_else(): void
    {
        $account = $this->account();
        $flow = $this->create($account);

        $node = $this->storedApiNode($flow);
        [$auth, $contentType] = $node['data']['headers'];
        [$apiKey, $page] = $node['data']['query'];

        // Stored encrypted — Laravel's encrypter, readable through the application layer only.
        $this->assertTrue(JourneySecrets::isEncrypted($auth['value']));
        $this->assertTrue(JourneySecrets::isEncrypted($apiKey['value']));
        $this->assertSame('Bearer '.self::SECRET, JourneySecrets::reveal($auth['value']));
        $this->assertSame(self::QUERY_SECRET, JourneySecrets::reveal($apiKey['value']));

        // Ordinary configuration is not encrypted.
        $this->assertSame('application/json', $contentType['value']);
        $this->assertSame('1', $page['value']);
        $this->assertSame('orders-api', $node['data']['credentialRef']);
        $this->assertSame('https://api.example.test/v1/orders', $node['data']['url']);

        // No plaintext anywhere in the database rows that hold the graph.
        $this->assertNoSecretIn((string) DB::table('whatsapp_flows')->where('id', $flow->id)->value('graph_data'), 'whatsapp_flows');
        $this->assertNoSecretIn((string) DB::table('whatsapp_flow_versions')->where('flow_id', $flow->id)->pluck('graph_data')->implode(''), 'whatsapp_flow_versions');
        $this->assertTrue(JourneySecrets::isEncrypted($this->storedApiNode($flow->versions()->firstOrFail())['data']['headers'][0]['value']));
    }

    // ================================================================== 3 read

    public function test_3_no_journey_read_returns_a_secret_or_its_ciphertext(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $flow = $this->create($account, $user);
        $versionId = $flow->versions()->value('id');

        foreach ([self::FLOWS, self::FLOWS."/{$flow->id}", self::FLOWS."/{$flow->id}/versions", self::FLOWS."/{$flow->id}/versions/{$versionId}"] as $url) {
            $body = $this->actingAs($user)->getJson($url)->assertOk()->getContent();
            $this->assertNoSecretIn($body, $url);
            $this->assertStringNotContainsString(JourneySecrets::PREFIX, $body, "ciphertext in {$url}");
        }

        $node = collect($this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data.nodes'))->firstWhere('id', 'api1');
        $this->assertSame(['key' => 'Authorization', 'value' => JourneySecrets::MASK, 'masked' => true], $node['data']['headers'][0]);
        $this->assertSame(['key' => 'Content-Type', 'value' => 'application/json'], $node['data']['headers'][1]);
        $this->assertSame(JourneySecrets::MASK, $node['data']['query'][0]['value']);
        $this->assertSame('1', $node['data']['query'][1]['value']);
    }

    // ================================================================== 4 update

    public function test_4_an_update_keeps_a_masked_secret_without_ever_returning_it_and_replaces_a_new_one(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $flow = $this->create($account, $user);
        $before = $this->storedApiNode($flow)['data']['headers'][0]['value'];

        // The client sends back exactly what it was given (masked) plus an unrelated edit.
        $graph = $this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data');
        $graph['nodes'][1]['data']['text'] = 'Checking your order now';
        $response = $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertOk();

        $this->assertNoSecretIn($response->getContent(), 'update response');
        $this->assertStringNotContainsString(JourneySecrets::PREFIX, $response->getContent());
        $kept = $this->storedApiNode($flow)['data']['headers'][0]['value'];
        $this->assertSame($before, $kept, 'the stored secret is kept as it was');
        $this->assertSame('Bearer '.self::SECRET, JourneySecrets::reveal($kept));
        $this->assertSame(self::QUERY_SECRET, JourneySecrets::reveal($this->storedApiNode($flow)['data']['query'][0]['value']));

        // A new value replaces it, encrypted.
        $graph['nodes'][2]['data']['headers'][0]['value'] = 'Bearer rotated-value-42';
        unset($graph['nodes'][2]['data']['headers'][0]['masked']);
        $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertOk();
        $rotated = $this->storedApiNode($flow)['data']['headers'][0]['value'];
        $this->assertTrue(JourneySecrets::isEncrypted($rotated));
        $this->assertSame('Bearer rotated-value-42', JourneySecrets::reveal($rotated));

        // Older versions keep their own (encrypted) value; nothing was decrypted into them.
        $this->assertSame(3, $flow->versions()->count());
        $this->assertSame('Bearer '.self::SECRET, JourneySecrets::reveal($this->storedApiNode($flow->versions()->orderBy('version')->firstOrFail())['data']['headers'][0]['value']));
    }

    public function test_4b_a_mask_that_matches_no_stored_secret_is_refused_and_never_stored(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        // A new journey has nothing to keep.
        $masked = $this->apiNode([['key' => 'Authorization', 'value' => JourneySecrets::MASK, 'masked' => true]]);
        $this->actingAs($user)->postJson(self::FLOWS, $this->payload($masked))
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.2.data.headers.0.value');
        $this->assertSame(0, WhatsAppFlow::count());

        // Renaming the header breaks the link to the stored value.
        $flow = $this->create($account, $user);
        $graph = $this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data');
        $graph['nodes'][2]['data']['headers'][0]['key'] = 'X-Api-Key';
        $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.2.data.headers.0.value');
        $this->assertSame('Bearer '.self::SECRET, JourneySecrets::reveal($this->storedApiNode($flow)['data']['headers'][0]['value']));
    }

    // ================================================================== 5–6 authorization

    public function test_5_another_tenant_can_neither_read_nor_change_the_journey(): void
    {
        $owner = $this->account();
        $flow = $this->create($owner);
        $intruder = $this->user($this->account());

        $this->actingAs($intruder)->getJson(self::FLOWS."/{$flow->id}")->assertNotFound();
        $this->actingAs($intruder)->getJson(self::FLOWS."/{$flow->id}/versions")->assertNotFound();
        $this->actingAs($intruder)->putJson(self::FLOWS."/{$flow->id}", $this->payload($this->apiNode([['key' => 'Authorization', 'value' => 'Bearer hijack']])))->assertNotFound();

        $list = $this->actingAs($intruder)->getJson(self::FLOWS)->assertOk()->getContent();
        $this->assertNoSecretIn($list, 'another tenant\'s list');
        $this->assertSame('Bearer '.self::SECRET, JourneySecrets::reveal($this->storedApiNode($flow)['data']['headers'][0]['value']));
    }

    public function test_6_a_role_without_edit_permission_cannot_change_a_secret_but_may_read_the_masked_journey(): void
    {
        $account = $this->account();
        $flow = $this->create($account);
        $viewer = $this->user($account, 'journey_viewer');

        $body = $this->actingAs($viewer)->getJson(self::FLOWS."/{$flow->id}")->assertOk()->getContent();
        $this->assertNoSecretIn($body, 'viewer read');

        $this->actingAs($viewer)->putJson(self::FLOWS."/{$flow->id}", $this->payload($this->apiNode([['key' => 'Authorization', 'value' => 'Bearer viewer-change']])))->assertForbidden();
        $this->actingAs($viewer)->postJson(self::FLOWS, $this->payload($this->apiNode()))->assertForbidden();
        $this->assertSame('Bearer '.self::SECRET, JourneySecrets::reveal($this->storedApiNode($flow)['data']['headers'][0]['value']));
    }

    // ================================================================== 7 errors

    public function test_7_validation_and_authorization_errors_never_echo_a_secret(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        // Credentials in the URL are refused, without echoing them.
        foreach (['https://admin:'.self::SECRET.'@api.example.test/v1', 'https://api.example.test/v1?token='.self::SECRET] as $url) {
            $response = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($this->apiNode(url: $url)))
                ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.2.data.url');
            $this->assertNoSecretIn($response->getContent(), 'url validation error');
        }

        // Another validation failure in the same request (bad trigger type).
        $response = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($this->apiNode(), ['trigger_type' => 'nope']))->assertStatus(422);
        $this->assertNoSecretIn($response->getContent(), 'validation error');

        // Publishing the non-executable journey (P5-7) fails without echoing it.
        $response = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($this->apiNode(), ['publish' => true]))->assertStatus(422);
        $this->assertNoSecretIn($response->getContent(), 'publish refusal');

        // The node entitlement refusal (403) names types only.
        AccountEntitlement::where('account_id', $account->id)->where('capability_id', Capability::where('slug', 'external_api')->value('id'))->update(['revoked_at' => now()]);
        $response = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($this->apiNode()))->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        $this->assertNoSecretIn($response->getContent(), 'entitlement refusal');
    }

    // ================================================================== 8 logs / audit

    public function test_8_no_secret_reaches_the_application_log_or_the_audit_trail(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, false);

        try {
            $flow = $this->create($account, $user);
            $graph = $this->actingAs($user)->getJson(self::FLOWS."/{$flow->id}")->json('data.graph_data');
            $graph['nodes'][2]['data']['headers'][0]['value'] = 'Bearer '.self::SECRET.'-v2';
            unset($graph['nodes'][2]['data']['headers'][0]['masked']);
            $this->actingAs($user)->putJson(self::FLOWS."/{$flow->id}", $this->payload([], ['graph_data' => $graph]))->assertOk();
            $this->actingAs($user)->deleteJson(self::FLOWS."/{$flow->id}")->assertOk();
        } finally {
            $property->setValue($this->app, true);
        }

        $audit = ActivityLog::where('module_name', 'WhatsApp Flows')->get();
        $this->assertSame(['create', 'update', 'delete'], $audit->pluck('action_type')->all(), 'the audit trail itself is intact');

        $auditRaw = DB::table('activity_logs')->pluck('old_values')->implode('').DB::table('activity_logs')->pluck('new_values')->implode('');
        $this->assertNoSecretIn($auditRaw, 'activity_logs');
        $this->assertStringNotContainsString(JourneySecrets::PREFIX, $auditRaw, 'ciphertext in activity_logs');
        $this->assertStringContainsString('api.example.test', $auditRaw, 'non-secret configuration is still audited');

        // Nothing in the application log either (the run + an inbound message).
        app(\App\Services\Chatbot\ChatbotEngineService::class)->handleInboundMessage($account->id, '919812345678', 'order', null, 'qr', null);
        $this->assertNoSecretIn(implode("\n", $this->logs), 'application log');
    }

    // ================================================================== 9–10 regressions

    public function test_9_ordinary_journey_configuration_round_trips_unchanged(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $graph = [
            'nodes' => [
                ['id' => 't', 'type' => 'trigger', 'data' => []],
                ['id' => 'x', 'type' => 'text', 'data' => ['text' => 'Your token is ready, {{ name }}']],
                ['id' => 'i', 'type' => 'image', 'data' => ['mediaUrl' => 'https://cdn.example.test/a.png', 'caption' => 'secret sale']],
            ],
            'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'x'], ['id' => 'e2', 'source' => 'x', 'target' => 'i']],
        ];

        $id = $this->actingAs($user)->postJson(self::FLOWS, ['name' => 'Plain', 'trigger_type' => 'keyword', 'trigger_value' => 'hi', 'graph_data' => $graph])
            ->assertCreated()->json('data.id');

        $this->assertSame($this->canonical($graph), $this->canonical($this->actingAs($user)->getJson(self::FLOWS."/{$id}")->json('data.graph_data')));
        $this->assertSame($this->canonical($graph), $this->canonical(WhatsAppFlow::findOrFail($id)->graph_data));

        // A header whose name is not credential-like stays readable configuration.
        $plain = $this->apiNode([['key' => 'Accept', 'value' => 'application/json']], [['key' => 'page', 'value' => '2']]);
        $draft = $this->actingAs($user)->postJson(self::FLOWS, $this->payload($plain))->assertCreated();
        $node = collect($draft->json('data.graph_data.nodes'))->firstWhere('id', 'api1');
        $this->assertSame([['key' => 'Accept', 'value' => 'application/json']], $node['data']['headers']);
        $this->assertSame([['key' => 'page', 'value' => '2']], $node['data']['query']);
    }

    public function test_10_journey_authorization_module_and_entitlement_behaviour_is_intact(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        // Node entitlement: without external_api the api node is a 403, nothing stored.
        AccountEntitlement::where('account_id', $account->id)->where('capability_id', Capability::where('slug', 'external_api')->value('id'))->update(['revoked_at' => now()]);
        $this->actingAs($user)->postJson(self::FLOWS, $this->payload($this->apiNode()))
            ->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        $this->assertSame(0, WhatsAppFlow::count());

        // Journey capability gate on the route (journey_automation).
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'journey_automation')->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => now()],
        );
        $this->actingAs($user)->getJson(self::FLOWS)->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
    }

    /**
     * Key order is not content: MySQL 8's native JSON type stores object
     * keys in its own order (MariaDB keeps them as written), so JSON
     * payloads are compared with every object's keys sorted recursively.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($v) => $this->canonical($v), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

}
