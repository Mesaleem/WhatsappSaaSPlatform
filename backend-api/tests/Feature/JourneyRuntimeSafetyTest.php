<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\ChatbotLog;
use App\Models\ChatbotRule;
use App\Models\Invoice;
use App\Models\JourneyExecutionEvent;
use App\Models\MessageDispatchLog;
use App\Models\Provider;
use App\Models\ProviderCapability;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\JourneyNodeCatalog;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P5-7 — Journey runtime execution & safety.
 *
 * Driven through the real paths: the save/publish API
 * (WhatsAppFlowController), the real inbound choke point
 * (ChatbotEngineService::handleInboundMessage → InboundEventGate →
 * WhatsAppJourneyEngine) and the real scheduler command. Provider sends
 * are faked only at the HTTP boundary of the unified WhatsApp driver.
 */
class JourneyRuntimeSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const FLOWS = '/api/whatsapp/flows';

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var list<string> texts the fake provider refuses */
    private array $fail = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            $this->calls[] = ['url' => $request->url()] + $request->data();
            $data = $request->data();

            if (in_array($data['message'] ?? ($data['text']['body'] ?? null), $this->fail, true)) {
                return Http::response(['success' => false, 'error' => 'Engine offline'], 200);
            }

            if (str_contains($request->url(), 'graph.facebook.com')) {
                return Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200);
            }

            return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });
    }

    // ================================================================== fixtures

    private function account(string $plan = 'growth'): Account
    {
        $account = Account::factory()->create();
        $p = PlanCatalog::find($plan);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $plan,
            'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0,
            'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected'] + ($p['engine_type'] === 'meta' ? [
            'meta_phone_number_id' => '1098'.random_int(100000000, 999999999),
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => 'EAAG_p57_token_0123456789',
        ] : []));

        return $account->fresh();
    }

    private function admin(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function text(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'text', 'data' => ['text' => $text]];
    }

    private function msg(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'message', 'data' => ['text' => $text]];
    }

    private function q(string $id, string $variable = 'answer', string $prompt = 'Q?'): array
    {
        return ['id' => $id, 'type' => 'question', 'data' => ['prompt_text' => $prompt, 'variable_name' => $variable, 'input_type' => 'text']];
    }

    /** @param list<array<string, mixed>> $nodes chained trigger → nodes[0] → nodes[1] … */
    private function graph(array $nodes, ?array $edges = null): array
    {
        if ($edges === null) {
            $edges = [];
            for ($i = 1; $i < count($nodes); $i++) {
                $edges[] = ['id' => "e{$i}", 'source' => $nodes[$i - 1]['id'], 'target' => $nodes[$i]['id']];
            }
        }

        return [
            'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes],
            'edges' => [['id' => 'e_t', 'source' => 't', 'target' => $nodes[0]['id']], ...$edges],
        ];
    }

    /** A journey stored directly (as legacy data / pre-P5-7 publishes would be). */
    private function flow(Account $account, array $nodes, string $triggerType = 'keyword', ?string $value = 'go', ?array $edges = null, string $name = 'J'): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => $name, 'trigger_type' => $triggerType, 'trigger_value' => $value,
            'is_active' => true, 'graph_data' => $this->graph($nodes, $edges),
        ]);
    }

    private function payload(array $nodes, array $extra = [], ?array $edges = null): array
    {
        return $extra + [
            'name' => 'Journey', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => $this->graph($nodes, $edges),
        ];
    }

    private function inbound(Account $account, string $text, string $phone = self::PHONE, ?string $messageId = null): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, 'qr', $messageId ? "wamsg:{$messageId}" : null);
    }

    /** The engine's own answer to "did a journey consume this message?". */
    private function consumed(Account $account, string $text, string $phone = self::PHONE): bool
    {
        return app(WhatsAppJourneyEngine::class)->handleInboundMessage($account->id, $phone, $text);
    }

    /** @return list<string> */
    private function sent(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')
            ->where('recipient_phone', $phone)->orderBy('id')->pluck('message_preview')->all();
    }

    /** @return list<string> */
    private function chatbotReplies(Account $account): array
    {
        return ChatbotLog::where('account_id', $account->id)->where('status', 'replied')->orderBy('id')->pluck('reply_sent')->all();
    }

    private function flowSession(Account $account, string $phone = self::PHONE): ?WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->where('phone_number', $phone)->latest('id')->first();
    }

    private function rule(Account $account, string $matchType, array $keywords, string $reply, int $priority = 1): ChatbotRule
    {
        return ChatbotRule::create([
            'account_id' => $account->id, 'name' => $reply, 'match_type' => $matchType, 'keywords' => $keywords,
            'response_type' => 'text', 'response_payload' => ['text' => $reply], 'priority' => $priority, 'is_active' => true,
        ]);
    }

    private function revoke(Account $account, string $slug): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => now()],
        );
    }

    // ================================================================== runtime truth

    public function test_the_runtime_executable_list_is_exact_and_every_other_palette_node_is_unsupported(): void
    {
        $this->assertSame(
            ['trigger', 'message', 'question', 'condition', 'save_lead', 'delay', 'conditional', 'text', 'image', 'video', 'document', 'audio'],
            JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES
        );

        $unsupported = array_values(array_diff(WhatsAppFlow::NODE_TYPES, JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES));
        $this->assertCount(20, $unsupported);
        $this->assertSame([], array_diff(JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES, WhatsAppFlow::NODE_TYPES));

        foreach (['api', 'payment', 'email', 'code', 'agent', 'rag', 'flow', 'catalog', 'product', 'template', 'journey', 'human_intervention', 'prompt', 'sticker', 'list', 'external_url', 'reply_button', 'location', 'location_request', 'address_request'] as $type) {
            $this->assertFalse(JourneyNodeCatalog::isRuntimeExecutable($type), $type);
        }
    }

    public function test_the_frontend_registry_declares_exactly_the_backend_runtime_executable_list(): void
    {
        $path = collect([env('JOURNEY_REGISTRY_PATH'), base_path('../frontend-app/src/journey/nodeRegistry.tsx')])
            ->first(fn ($p) => is_string($p) && is_file($p));

        if (! $path) {
            $this->markTestSkipped('frontend-app/src/journey/nodeRegistry.tsx not found next to this checkout.');
        }

        $source = (string) file_get_contents($path);
        $this->assertSame(1, preg_match('/export const RUNTIME_EXECUTABLE_NODE_TYPES[^=]*=\s*\[(.*?)\]/s', $source, $m), 'RUNTIME_EXECUTABLE_NODE_TYPES not found in the registry');
        preg_match_all("/'([a-z_]+)'/", $m[1], $types);

        $this->assertEqualsCanonicalizing(JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES, $types[1]);
    }

    // ================================================================== publish validation (1–3)

    public function test_1_an_executable_journey_publishes(): void
    {
        $account = $this->account();

        $response = $this->actingAs($this->admin($account))->postJson(self::FLOWS, $this->payload([$this->text('a', 'Hi'), $this->msg('b', 'Bye')]))
            ->assertCreated();

        $this->assertNotNull($response->json('data.published_version_id'));
    }

    public function test_2_a_journey_with_an_unsupported_node_cannot_be_published_but_saves_as_a_draft(): void
    {
        $account = $this->account();
        $this->grantManually($account, 'external_api');
        $admin = $this->admin($account);
        $nodes = [$this->text('a', 'Hi'), ['id' => 'x', 'type' => 'api', 'data' => ['method' => 'GET', 'url' => 'https://api.test/v1']], $this->msg('b', 'Bye')];

        $this->actingAs($admin)->postJson(self::FLOWS, $this->payload($nodes))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE')
            ->assertJsonStructure(['errors' => ['graph_data.nodes.2.type']]);
        $this->assertSame(0, WhatsAppFlow::count(), 'nothing persisted on a refused publish');

        // A draft keeps the builder usable, but never becomes runnable.
        $draft = $this->actingAs($admin)->postJson(self::FLOWS, $this->payload($nodes, ['publish' => false]))->assertCreated();
        $flowId = $draft->json('data.id');
        $this->assertNull($draft->json('data.published_version_id'));

        $versionId = WhatsAppFlow::findOrFail($flowId)->versions()->value('id');
        $this->actingAs($admin)->postJson(self::FLOWS."/{$flowId}/versions/{$versionId}/publish")
            ->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');
        $this->actingAs($admin)->putJson(self::FLOWS."/{$flowId}", $this->payload($nodes))
            ->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');
        $this->assertNull(WhatsAppFlow::findOrFail($flowId)->published_version_id);

        $this->inbound($account, 'go');
        $this->assertSame([], $this->sent($account));
        $this->assertNull($this->flowSession($account));
    }

    public function test_2b_activating_a_journey_whose_published_version_cannot_run_is_refused(): void
    {
        $account = $this->account();
        // Legacy data: published before P5-7 with a node the runtime cannot execute.
        $flow = $this->flow($account, [$this->text('a', 'Hi'), ['id' => 'x', 'type' => 'email', 'data' => ['to' => 'a@b.test', 'subject' => 's', 'body' => 'b']]]);
        $flow->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin($account))->postJson(self::FLOWS."/{$flow->id}/toggle")
            ->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');
        $this->assertFalse($flow->fresh()->is_active);
    }

    public function test_2c_the_super_admin_gets_no_bypass_of_publish_validation(): void
    {
        $client = $this->account();

        $this->actingAs($this->superAdmin())->postJson(self::FLOWS."?account_id={$client->id}", $this->payload([['id' => 'x', 'type' => 'code', 'data' => ['language' => 'javascript', 'code' => 'x']]]))
            ->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');

        // Management itself is unchanged: an executable journey still saves for the client.
        $this->actingAs($this->superAdmin())->postJson(self::FLOWS."?account_id={$client->id}", $this->payload([$this->text('a', 'Hi')]))
            ->assertCreated();
    }

    public function test_3_a_malformed_graph_cannot_be_published(): void
    {
        $account = $this->account();
        $admin = $this->admin($account);

        // A connection into a node that does not exist.
        $this->actingAs($admin)->postJson(self::FLOWS, $this->payload([$this->text('a', 'Hi')], [], [['id' => 'e_bad', 'source' => 'a', 'target' => 'ghost']]))
            ->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE')->assertJsonStructure(['errors' => ['graph_data.edges.1']]);

        // Two nodes with one id.
        $this->actingAs($admin)->postJson(self::FLOWS, $this->payload([$this->text('a', 'Hi'), $this->text('a', 'Again')], [], []))
            ->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');

        // An action node the engine would refuse (empty text).
        $this->actingAs($admin)->postJson(self::FLOWS, $this->payload([$this->text('a', '')]))
            ->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');

        $this->assertSame(0, WhatsAppFlow::count());
    }

    // ================================================================== runtime (4–12)

    public function test_4_and_5_text_and_message_nodes_execute(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'Text node'), $this->msg('b', 'Message node')]);

        $this->assertTrue($this->consumed($account, 'go'));
        $this->assertSame(['Text node', 'Message node'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    public function test_6_a_delay_parks_the_session_without_blocking_and_resumes_through_the_scheduler(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'Before'), ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 1, 'unit' => 'days']], $this->text('b', 'After')]);

        $started = microtime(true);
        $this->inbound($account, 'go');
        $this->assertLessThan(5.0, microtime(true) - $started, 'a one-day delay must not hold the request');

        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'd'], [$s->status, $s->current_node_id]);
        $this->assertTrue($s->wait_until->greaterThan(now()->addHours(23)));
        $this->assertSame(['Before'], $this->sent($account));

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->artisan('journeys:resume-due')->assertSuccessful();
        app(WhatsAppJourneyEngine::class)->resumeDueSession($s->id);

        $this->assertSame(['Before', 'After'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->fresh()->status);

        $engineSource = (string) file_get_contents(app_path('Services/WhatsApp/WhatsAppJourneyEngine.php'));
        $this->assertDoesNotMatchRegularExpression('/\b(u?sleep)\s*\(/', $engineSource, 'no sleep() in the Journey engine');
    }

    public function test_7_a_condition_routes_on_the_captured_answer(): void
    {
        $account = $this->account();
        $this->flow($account, [
            $this->q('q', 'plan'),
            ['id' => 'c', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'plan', 'operator' => 'equals', 'value' => 'gold']], 'match' => 'all']],
            $this->text('yes', 'Gold!'),
            $this->text('no', 'Not gold'),
        ], edges: [
            ['id' => 'e1', 'source' => 'q', 'target' => 'c'],
            ['id' => 'e2', 'source' => 'c', 'target' => 'yes', 'sourceHandle' => 'true'],
            ['id' => 'e3', 'source' => 'c', 'target' => 'no', 'sourceHandle' => 'false'],
        ]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'silver');
        $this->assertSame(['Q?', 'Not gold'], $this->sent($account));

        $this->inbound($account, 'go', '919800000002');
        $this->inbound($account, 'gold', '919800000002');
        $this->assertSame(['Q?', 'Gold!'], $this->sent($account, '919800000002'));
    }

    public function test_8_an_unsupported_node_never_executes(): void
    {
        $account = $this->account();
        $this->grantManually($account, 'external_api');
        $this->flow($account, [$this->text('a', 'Hi'), ['id' => 'x', 'type' => 'api', 'data' => ['method' => 'GET', 'url' => 'https://api.test/v1']], $this->text('b', 'Never')]);

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_EXPIRED, 'x'], [$s->status, $s->current_node_id]);
        $this->assertSame(['Hi'], $this->sent($account));
        $this->assertCount(0, collect($this->calls)->filter(fn ($c) => str_contains($c['url'], 'api.test')), 'no outbound API call was made');
        $this->assertSame('unsupported_node', JourneyExecutionEvent::where('session_id', $s->id)->where('event', JourneyExecutionEvent::SESSION_EXPIRED)->value('error_category'));
    }

    public function test_9_a_capability_revoked_after_publication_is_denied_at_run_time(): void
    {
        $account = $this->account();
        $this->actingAs($this->admin($account))->postJson(self::FLOWS, $this->payload([$this->msg('m', 'Legacy'), $this->text('a', 'Palette text')]))->assertCreated();

        $this->revoke($account, 'whatsapp_send');
        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_FAILED, 'a'], [$s->status, $s->current_node_id]);
        $this->assertStringContainsString("requires the 'whatsapp_send' capability", (string) $s->last_error);
        $this->assertSame(['Legacy'], $this->sent($account), 'the grandfathered legacy node runs; the palette node is denied');
    }

    public function test_10_a_provider_mismatch_is_denied_without_falling_back_to_another_provider(): void
    {
        // A Meta account: the node's provider is resolved from THIS account's
        // own subscription (the same field WhatsAppEngineFactory dispatches on).
        $account = $this->account('business');
        $this->flow($account, [$this->text('a', 'Hi')]);
        // The provider matrix now says Meta cannot do whatsapp_send (e.g. a
        // platform-level suspension of that pairing) — decided at run time.
        ProviderCapability::query()
            ->where('provider_id', Provider::where('slug', 'meta')->value('id'))
            ->where('capability_id', Capability::where('slug', 'whatsapp_send')->value('id'))
            ->firstOrFail()->update(['supported' => false]);
        $this->calls = [];

        $this->assertFalse($this->consumed($account, 'go'));

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $s->status);
        $this->assertStringContainsString('not supported on your WhatsApp provider (meta)', (string) $s->last_error);
        $this->assertSame([], $this->calls, 'no provider was called — no silent fallback to QR');
    }

    public function test_11_and_12_a_first_node_failure_is_a_failure_and_does_not_consume_the_message(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'Hi')]);
        $this->rule($account, 'fallback', [], 'CHATBOT FALLBACK');
        $this->revoke($account, 'whatsapp_send');

        // 11 — the engine reports it was NOT consumed.
        $this->assertFalse($this->consumed($account, 'go'));
        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $s->status);
        $this->assertTrue(JourneyExecutionEvent::where('session_id', $s->id)->where('event', JourneyExecutionEvent::SESSION_FAILED)->where('error_category', 'entitlement_blocked')->exists());

        // 12 — so the chatbot answers it on the real inbound path.
        $this->inbound($account, 'go', '919800000003');
        $this->assertSame([], $this->sent($account, '919800000003'));
        $this->assertSame(['CHATBOT FALLBACK'], $this->chatbotReplies($account));
        $this->assertSame(1, (int) Subscription::where('account_id', $account->id)->value('used_messages'), 'only the chatbot reply consumed quota');
    }

    public function test_a_transient_first_node_failure_keeps_the_message_and_is_retried(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'Flaky')]);
        $this->rule($account, 'fallback', [], 'CHATBOT FALLBACK');
        $this->fail = ['Flaky'];

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status, 'parked for a retry: the journey still owns it');
        $this->assertSame([], $this->chatbotReplies($account), 'no second, competing answer');
        $this->assertSame(0, (int) Subscription::where('account_id', $account->id)->value('used_messages'), 'a send that did not go out consumes no quota');
    }

    // ================================================================== trigger safety (13–16)

    public function test_13_and_14_only_a_matching_trigger_starts_a_journey(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'Journey')], 'keyword', 'pricing');
        $this->rule($account, 'fallback', [], 'CHATBOT FALLBACK');

        $this->inbound($account, 'hello there');
        $this->assertNull($this->flowSession($account));
        $this->assertSame(['CHATBOT FALLBACK'], $this->chatbotReplies($account));

        $this->inbound($account, 'what is your PRICING?');
        $this->assertSame(['Journey'], $this->sent($account));
        $this->assertSame(['CHATBOT FALLBACK'], $this->chatbotReplies($account), 'the journey consumed the matching message');
    }

    public function test_15_a_default_journey_never_takes_a_message_a_chatbot_rule_answers(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'Default journey')], 'default', null);
        $this->rule($account, 'contains', ['hours'], 'We open at 9');

        $this->inbound($account, 'what are your hours?');
        $this->assertNull($this->flowSession($account));
        $this->assertSame(['We open at 9'], $this->chatbotReplies($account));
        $this->assertSame([], $this->sent($account));

        // A message nothing else answers is still the default journey's.
        $this->inbound($account, 'something else', '919800000004');
        $this->assertSame(['Default journey'], $this->sent($account, '919800000004'));
    }

    public function test_15b_a_default_journey_still_outranks_only_a_chatbot_fallback_rule(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'Default journey')], 'default', null);
        $this->rule($account, 'fallback', [], 'CHATBOT FALLBACK');

        $this->inbound($account, 'anything');
        $this->assertSame(['Default journey'], $this->sent($account));
        $this->assertSame([], $this->chatbotReplies($account));
    }

    public function test_16_trigger_selection_across_several_journeys_is_deterministic(): void
    {
        $account = $this->account();
        $second = $this->flow($account, [$this->text('a', 'Keyword B')], 'keyword', 'deal', name: 'B');
        $first = $this->flow($account, [$this->text('a', 'Keyword A')], 'keyword', 'deal', name: 'A');
        $this->assertLessThan($first->id, $second->id);
        $this->flow($account, [$this->text('a', 'Default 1')], 'default', null);
        $this->flow($account, [$this->text('a', 'Default 2')], 'default', null);

        foreach (['919800000010', '919800000011', '919800000012'] as $phone) {
            $this->inbound($account, 'any deal today?', $phone);
            $this->assertSame(['Keyword B'], $this->sent($account, $phone), 'lowest id wins among matching keyword journeys');
        }

        foreach (['919800000020', '919800000021'] as $phone) {
            $this->inbound($account, 'unmatched', $phone);
            $this->assertSame(['Default 1'], $this->sent($account, $phone), 'lowest id wins among default journeys');
        }
    }

    // ================================================================== question session (17–18)

    public function test_17_an_active_question_session_accepts_the_answer(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'name', 'Your name?'), $this->text('a', 'Thanks {{ name }}')]);

        $this->inbound($account, 'go');
        $this->travel(WhatsAppJourneyEngine::QUESTION_REPLY_TTL_SECONDS - 120)->seconds();
        $this->inbound($account, 'Asha');

        $this->assertSame(['Your name?', 'Thanks Asha'], $this->sent($account));
        $this->assertSame('Asha', $this->flowSession($account)->context_data['name']);
    }

    public function test_18_an_expired_question_session_does_not_consume_the_reply(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'name', 'Your name?'), $this->text('a', 'Thanks {{ name }}')]);
        $this->rule($account, 'fallback', [], 'CHATBOT FALLBACK');

        $this->inbound($account, 'go');
        $question = $this->flowSession($account);
        $this->travel(WhatsAppJourneyEngine::QUESTION_REPLY_TTL_SECONDS + 60)->seconds();
        $this->inbound($account, 'Asha, two days later');

        $question->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $question->status);
        $this->assertArrayNotHasKey('name', $question->context_data ?? []);
        $this->assertSame(['Your name?'], $this->sent($account));
        $this->assertSame(['CHATBOT FALLBACK'], $this->chatbotReplies($account));
        $this->assertSame('reply_timeout', JourneyExecutionEvent::where('session_id', $question->id)->where('event', JourneyExecutionEvent::SESSION_EXPIRED)->value('error_category'));

        // A message after expiry may start the journey afresh.
        $this->inbound($account, 'go');
        $this->assertSame(['Your name?', 'Your name?'], $this->sent($account));
        $this->assertNotSame($question->id, $this->flowSession($account)->id);
    }

    public function test_18b_the_scheduler_closes_unanswered_questions_and_leaves_fresh_ones(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $this->travel(WhatsAppJourneyEngine::QUESTION_REPLY_TTL_SECONDS + 60)->seconds();
        $this->inbound($account, 'go', '919800000030');

        $this->artisan('journeys:resume-due')->assertSuccessful();

        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $this->flowSession($account)->status);
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $this->flowSession($account, '919800000030')->status);
    }

    // ================================================================== duplicates (19)

    public function test_19_a_redelivered_inbound_event_executes_and_sends_once(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'Once'), $this->q('q')]);

        $this->inbound($account, 'go', messageId: 'MSG-1');
        $this->inbound($account, 'go', messageId: 'MSG-1');
        $this->inbound($account, 'go', messageId: 'MSG-1');

        $this->assertSame(['Once', 'Q?'], $this->sent($account));
        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $account->id)->count());
        $this->assertSame(2, (int) Subscription::where('account_id', $account->id)->value('used_messages'));

        // The answer, redelivered, is captured once too.
        $this->inbound($account, 'yes', messageId: 'MSG-2');
        $this->inbound($account, 'yes', messageId: 'MSG-2');
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    // ================================================================== tenant isolation (20)

    public function test_20_a_journey_never_runs_with_another_tenants_configuration_session_or_provider(): void
    {
        $a = $this->account('growth');   // QR
        $b = $this->account('business'); // Meta
        $this->flow($a, [$this->q('q'), $this->text('x', 'A-only')], 'keyword', 'go');

        // Tenant B receives the same keyword from the same phone: A's journey is not B's.
        $this->inbound($b, 'go');
        $this->assertNull($this->flowSession($b));
        $this->assertSame([], $this->sent($b));

        // A's session is open; B's inbound for the same phone never continues it.
        $this->inbound($a, 'go');
        $this->inbound($b, 'my answer');
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $this->flowSession($a)->status);
        $this->assertArrayNotHasKey('answer', $this->flowSession($a)->context_data ?? []);

        // A's run uses A's own engine (QR), never B's Meta credentials.
        $this->assertCount(0, collect($this->calls)->filter(fn ($c) => str_contains($c['url'], 'graph.facebook.com')));

        // An API user of B cannot reach A's journey.
        $flowA = WhatsAppFlow::where('account_id', $a->id)->firstOrFail();
        $this->actingAs($this->admin($b))->getJson(self::FLOWS."/{$flowA->id}")->assertNotFound();
        $this->actingAs($this->admin($b))->postJson(self::FLOWS."/{$flowA->id}/toggle")->assertNotFound();
    }

    // ================================================================== helpers

    private function grantManually(Account $account, string $slug): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => null],
        );
    }
}
