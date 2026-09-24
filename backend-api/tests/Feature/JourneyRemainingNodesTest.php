<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\WhatsApp\JourneyActionConfig;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 Task 6 — the remaining palette nodes the engine can run safely
 * (text, image, video, document, audio) and the completion / terminal
 * contract, driven through the real inbound path and the real scheduler
 * resume. Sends are faked at the HTTP boundary of the unified driver; a
 * text or media URL listed in $fail is rejected by the "provider".
 */
class JourneyRemainingNodesTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const IMG = 'https://cdn.example.com/p/photo.jpg';

    /** @var list<string> message texts / media URLs the fake provider refuses */
    private array $fail = [];

    /** @var list<array<string, mixed>> every request body the provider received */
    private array $calls = [];

    /** @var (\Closure(): void)|null runs inside the next provider call */
    private ?\Closure $during = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            $this->calls[] = ['url' => $request->url()] + $request->data();

            if ($this->during) {
                $during = $this->during;
                $this->during = null;
                $during();
            }

            $data = $request->data();
            $keys = [$data['message'] ?? null, $data['media_url'] ?? null, $data['image']['link'] ?? null, $data['text']['body'] ?? null];
            foreach ($this->fail as $bad) {
                if (in_array($bad, $keys, true)) {
                    return Http::response(['success' => false, 'error' => 'Engine offline'], 200);
                }
            }

            if (str_contains($request->url(), 'graph.facebook.com')) {
                return Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200);
            }

            return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });
    }

    // ------------------------------------------------------------------ fixtures

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
            'meta_access_token' => 'EAAG_journey_task6_token_0123456789',
        ] : []));

        return $account->fresh();
    }

    private function text(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'text', 'data' => ['text' => $text]];
    }

    private function media(string $id, string $type, array $data = []): array
    {
        return ['id' => $id, 'type' => $type, 'data' => $data + ['mediaUrl' => self::IMG]];
    }

    private function msg(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'message', 'data' => ['text' => $text]];
    }

    private function q(string $id, string $variable, string $prompt): array
    {
        return ['id' => $id, 'type' => 'question', 'data' => ['prompt_text' => $prompt, 'variable_name' => $variable, 'input_type' => 'text']];
    }

    private function delay(string $id, int $minutes = 5): array
    {
        return ['id' => $id, 'type' => 'delay', 'data' => ['amount' => $minutes, 'unit' => 'minutes']];
    }

    /** $edges as [source, target, extra?]; default: a chain in node order. */
    private function flow(Account $account, array $nodes, ?array $edges = null, string $keyword = 'go'): WhatsAppFlow
    {
        if ($edges === null) {
            $edges = [];
            for ($i = 1; $i < count($nodes); $i++) {
                $edges[] = [$nodes[$i - 1]['id'], $nodes[$i]['id']];
            }
        }

        $allEdges = [['id' => 'e_t', 'source' => 't', 'target' => $nodes[0]['id']]];
        foreach ($edges as $i => $edge) {
            $allEdges[] = ['id' => "e{$i}", 'source' => $edge[0], 'target' => $edge[1]] + ($edge[2] ?? []);
        }

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Task 6', 'trigger_type' => 'keyword', 'trigger_value' => $keyword,
            'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => $allEdges],
        ]);
    }

    private function inbound(Account $account, string $text, string $phone = self::PHONE, ?string $messageId = null, string $engine = 'qr'): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, $engine, $messageId ? "wamsg:{$messageId}" : null);
    }

    private function flowSession(Account $account, string $phone = self::PHONE): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->where('phone_number', $phone)->latest('id')->firstOrFail();
    }

    private function resume(WhatsAppFlowSession $session): string
    {
        return app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id);
    }

    /** @return list<string> previews of successfully sent journey messages, in order */
    private function sent(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')
            ->where('recipient_phone', $phone)->orderBy('id')->pluck('message_preview')->all();
    }

    private function revoke(Account $account, string $slug): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => now()],
        );
    }

    private function grant(Account $account, string $slug): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => null],
        );
    }

    private function assertEnded(WhatsAppFlowSession $session, string $status, string $nodeId, ?string $error = null): void
    {
        $session->refresh();
        $this->assertSame($status, $session->status);
        $this->assertSame($nodeId, $session->current_node_id);
        $this->assertNull($session->wait_until);

        if ($error === null) {
            $this->assertNull($session->last_error);
        } else {
            $this->assertStringContainsString($error, (string) $session->last_error);
        }
    }

    /** Call one of the engine's private status helpers, as a run still in flight would. */
    private function engineCall(string $method, WhatsAppFlowSession $session, mixed ...$args): mixed
    {
        $engine = app(WhatsAppJourneyEngine::class);

        return (new \ReflectionMethod($engine, $method))->invoke($engine, $session, ...$args);
    }

    // ==================================================================
    // 1. Inventory: what runs now, what still stops
    // ==================================================================

    public function test_the_send_palette_nodes_are_governed_by_the_action_contract(): void
    {
        $this->assertSame(['text', 'image', 'video', 'document', 'audio'], JourneyActionConfig::PALETTE_SEND_TYPES);
        $this->assertSame(['image', 'video', 'document', 'audio'], JourneyActionConfig::MEDIA_TYPES);

        foreach (JourneyActionConfig::PALETTE_SEND_TYPES as $type) {
            $this->assertContains($type, JourneyActionConfig::ACTION_TYPES);
            $this->assertContains($type, WhatsAppFlow::PALETTE_NODE_TYPES, 'still a palette type: listed once, as the registry parity test requires');
        }
    }

    /** Every palette node that still has no safe execution path. */
    public static function unimplemented(): array
    {
        $implemented = ['text', 'image', 'video', 'document', 'audio', 'delay', 'conditional'];
        $cases = [];
        foreach (array_diff(WhatsAppFlow::PALETTE_NODE_TYPES, $implemented) as $type) {
            $cases[$type] = [$type];
        }

        return $cases;
    }

    #[DataProvider('unimplemented')]
    public function test_an_unimplemented_palette_node_still_expires_with_its_reason_and_nothing_after_it_runs(string $type): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), ['id' => 'x', 'type' => $type, 'data' => []], $this->text('b', 'B')]);

        $this->inbound($account, 'go');

        $this->assertSame(['A'], $this->sent($account));
        $this->assertEnded($this->flowSession($account), WhatsAppFlowSession::STATUS_EXPIRED, 'x', "type '{$type}'");
    }

    public function test_exactly_twenty_palette_types_remain_unimplemented(): void
    {
        $this->assertCount(20, self::unimplemented());
    }

    // ==================================================================
    // 2. Configuration contract (run time + save time)
    // ==================================================================

    public static function configs(): array
    {
        return [
            'text ok' => ['text', ['text' => 'Hi'], null, null],
            'text empty' => ['text', ['text' => '  '], 'non-empty text', null],
            'text missing' => ['text', [], 'non-empty text', null],
            'text not a string' => ['text', ['text' => ['x']], 'non-empty text', 'non-empty text'],
            'image ok' => ['image', ['mediaUrl' => self::IMG, 'caption' => 'C'], null, null],
            'image http ok' => ['image', ['mediaUrl' => 'http://cdn.example.com/a.png'], null, null],
            'image missing url' => ['image', [], 'needs a media URL', null],
            'image empty url' => ['image', ['mediaUrl' => ''], 'needs a media URL', null],
            'image javascript url' => ['image', ['mediaUrl' => 'javascript:alert(1)'], 'http(s)', 'http(s)'],
            'image file url' => ['image', ['mediaUrl' => 'file:///etc/passwd'], 'http(s)', 'http(s)'],
            'image relative path' => ['image', ['mediaUrl' => '/uploads/a.png'], 'http(s)', 'http(s)'],
            'image ftp' => ['image', ['mediaUrl' => 'ftp://example.com/a.png'], 'http(s)', 'http(s)'],
            'image url not a string' => ['image', ['mediaUrl' => ['x']], 'needs a media URL', 'needs a media URL'],
            'image caption not text' => ['image', ['mediaUrl' => self::IMG, 'caption' => ['x']], 'caption must be text', 'caption must be text'],
            'video ok' => ['video', ['mediaUrl' => self::IMG], null, null],
            'document ok' => ['document', ['mediaUrl' => self::IMG, 'filename' => 'a.pdf'], null, null],
            'document filename not text' => ['document', ['mediaUrl' => self::IMG, 'filename' => 5], 'filename must be text', 'filename must be text'],
            'audio ok' => ['audio', ['mediaUrl' => self::IMG], null, null],
            'audio bad url' => ['audio', ['mediaUrl' => 'not a url'], 'http(s)', 'http(s)'],
            'data not an object' => ['video', 'x', 'must be an object', 'must be an object'],
        ];
    }

    #[DataProvider('configs')]
    public function test_the_configuration_contract(string $type, mixed $data, ?string $runError, ?string $draftError): void
    {
        $run = JourneyActionConfig::error($type, $data);
        $draft = JourneyActionConfig::error($type, $data, draft: true);

        $runError === null ? $this->assertNull($run) : $this->assertStringContainsString($runError, (string) $run);
        $draftError === null ? $this->assertNull($draft) : $this->assertStringContainsString($draftError, (string) $draft);
    }

    public function test_text_placeholders_are_filled_by_substitution_only(): void
    {
        $context = ['name' => 'Ada', 'n' => 3, 'yes' => true, 'list' => ['x'], 'nil' => null, 'a.b' => 'dot'];

        $this->assertSame('Hi Ada, 3 true . dot', JourneyActionConfig::renderText('Hi {{ name }}, {{n}} {{yes}} {{list}}{{nil}}. {{a.b}}', $context));
        $this->assertSame('Missing: []', JourneyActionConfig::renderText('Missing: [{{ nope }}]', $context));
        $this->assertSame('{{ not valid }} {{}} {name}', JourneyActionConfig::renderText('{{ not valid }} {{}} {name}', $context));
        $this->assertSame('{{name}}', JourneyActionConfig::renderText('{{name}}', ['name' => '{{name}}']), 'a value is never re-expanded');
        $this->assertSame('${x} <?php', JourneyActionConfig::renderText('${x} <?php', $context));
    }

    public static function malformedAtRunTime(): array
    {
        return [
            'text empty' => [['id' => 'x', 'type' => 'text', 'data' => ['text' => '']], 'non-empty text'],
            'text not a string' => [['id' => 'x', 'type' => 'text', 'data' => ['text' => 42]], 'non-empty text'],
            'image no url' => [['id' => 'x', 'type' => 'image', 'data' => []], 'needs a media URL'],
            'video javascript url' => [['id' => 'x', 'type' => 'video', 'data' => ['mediaUrl' => 'javascript:alert(1)']], 'http(s)'],
            'document filename list' => [['id' => 'x', 'type' => 'document', 'data' => ['mediaUrl' => self::IMG, 'filename' => ['a']]], 'filename'],
            'audio data list' => [['id' => 'x', 'type' => 'audio', 'data' => 'nope'], 'must be an object'],
        ];
    }

    #[DataProvider('malformedAtRunTime')]
    public function test_a_malformed_send_node_fails_at_the_node_and_nothing_downstream_runs(array $node, string $error): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $node, $this->text('b', 'B')]);

        $this->inbound($account, 'go');

        $this->assertSame(['A'], $this->sent($account));
        $this->assertEnded($this->flowSession($account), WhatsAppFlowSession::STATUS_FAILED, 'x', $error);
        $this->travel(2)->hours();
        $this->assertSame('skipped', $this->resume($this->flowSession($account)), 'malformed is never retried');
    }

    public function test_the_save_api_refuses_media_and_text_values_that_could_never_run_but_keeps_drafts(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');
        $post = fn (array $node) => $this->actingAs($user)->postJson('/api/whatsapp/flows', [
            'name' => 'X', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $node], 'edges' => [['id' => 'e', 'source' => 't', 'target' => $node['id']]]],
        ]);

        $post($this->media('i', 'image', ['mediaUrl' => 'javascript:alert(1)']))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
        $post($this->media('d', 'document', ['filename' => ['x']]))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
        $post(['id' => 'x', 'type' => 'text', 'data' => ['text' => ['x']]])->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');

        // Drafts (the registry's defaultConfig) still save; a full node too.
        $post(['id' => 'i', 'type' => 'image', 'data' => ['mediaUrl' => '']])->assertCreated();
        $post(['id' => 'x', 'type' => 'text', 'data' => ['text' => '']])->assertCreated();
        $post($this->media('v', 'video', ['caption' => 'Watch']))->assertCreated();
    }

    // ==================================================================
    // 3. Execution: text + media through the unified driver
    // ==================================================================

    public function test_text_nodes_send_with_collected_answers_and_complete_at_the_end(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'name', 'Name?'), $this->text('a', 'Hi {{ name }}{{ city }}!'), $this->text('b', 'Bye')]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'Ada');

        $this->assertSame(['Name?', 'Hi Ada!', 'Bye'], $this->sent($account));
        $this->assertEnded($this->flowSession($account), WhatsAppFlowSession::STATUS_COMPLETED, 'b');
        $this->assertSame(3, (int) $account->currentSubscription()->first()->used_messages);
    }

    public function test_a_text_that_renders_empty_fails_instead_of_sending_nothing(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', '{{ never_collected }}'), $this->text('b', 'B')]);

        $this->inbound($account, 'go');

        $this->assertSame([], $this->sent($account));
        $this->assertEnded($this->flowSession($account), WhatsAppFlowSession::STATUS_FAILED, 'a', 'empty once its variables are filled in');
        $this->assertSame(0, (int) $account->currentSubscription()->first()->used_messages);
    }

    public function test_media_nodes_send_through_the_qr_driver_with_the_media_contract(): void
    {
        $account = $this->account();
        $this->flow($account, [
            $this->media('i', 'image', ['caption' => 'Look']),
            $this->media('v', 'video', ['mediaUrl' => 'https://cdn.example.com/v.mp4']),
            $this->media('d', 'document', ['mediaUrl' => 'https://cdn.example.com/q.pdf', 'filename' => 'quote.pdf', 'caption' => 'Your quote']),
            $this->media('a', 'audio', ['mediaUrl' => 'https://cdn.example.com/n.ogg', 'caption' => 'ignored for audio']),
        ]);

        $this->inbound($account, 'go');

        $media = array_values(array_filter($this->calls, fn ($c) => isset($c['media_type'])));
        $this->assertCount(4, $media);
        $this->assertSame(['image', self::IMG, 'Look', 'Look'], [$media[0]['media_type'], $media[0]['media_url'], $media[0]['caption'], $media[0]['message']]);
        $this->assertSame(['video', 'https://cdn.example.com/v.mp4'], [$media[1]['media_type'], $media[1]['media_url']]);
        $this->assertArrayNotHasKey('caption', $media[1]);
        $this->assertSame(['document', 'quote.pdf', 'Your quote'], [$media[2]['media_type'], $media[2]['filename'], $media[2]['caption']]);
        $this->assertSame('audio', $media[3]['media_type']);
        $this->assertArrayNotHasKey('caption', $media[3], 'WhatsApp audio carries no caption');

        $logs = MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->orderBy('id')->get();
        $this->assertSame(['Look', '[video]', 'Your quote', '[audio]'], $logs->pluck('message_preview')->all());
        $this->assertSame([true, true, true, true], $logs->pluck('has_media')->map(fn ($v) => (bool) $v)->all());
        $this->assertSame(self::IMG, $logs[0]->media_url);
        $this->assertSame(4, (int) $account->currentSubscription()->first()->used_messages);
        $this->assertEnded($this->flowSession($account), WhatsAppFlowSession::STATUS_COMPLETED, 'a');
    }

    public function test_media_nodes_send_through_the_meta_driver_with_the_cloud_api_shape(): void
    {
        $account = $this->account('business');
        $this->flow($account, [$this->media('i', 'image', ['caption' => 'Look']), $this->media('a', 'audio', ['caption' => 'no'])]);

        $this->inbound($account, 'go', engine: 'meta');

        $graph = array_values(array_filter($this->calls, fn ($c) => str_contains($c['url'], 'graph.facebook.com')));
        $this->assertCount(2, $graph);
        $this->assertSame('image', $graph[0]['type']);
        $this->assertSame(['link' => self::IMG, 'caption' => 'Look'], $graph[0]['image']);
        $this->assertSame('audio', $graph[1]['type']);
        $this->assertSame(['link' => self::IMG], $graph[1]['audio']);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    // ==================================================================
    // 4. Retry / idempotency / resume
    // ==================================================================

    public function test_a_failed_media_send_parks_for_retry_and_the_retry_continues_from_that_node(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->media('i', 'image'), $this->text('b', 'B')]);
        $this->fail = [self::IMG];

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(['A'], $this->sent($account), 'nothing downstream of the failed node');
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame('i', $s->current_node_id);
        $this->assertStringContainsString("Node 'i': message not sent", $s->last_error);

        $this->fail = [];
        $this->travel(2)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['A', '[image]', 'B'], $this->sent($account), 'A is not re-sent');
        $this->assertEnded($s, WhatsAppFlowSession::STATUS_COMPLETED, 'b');
    }

    public function test_a_text_send_that_keeps_failing_ends_failed_not_completed(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A')]);
        $this->fail = ['A'];

        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        for ($i = 0; $i < WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
            $this->travel(1)->hours();
            $status = $this->resume($s);
        }

        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $status);
        $this->assertEnded($s, WhatsAppFlowSession::STATUS_FAILED, 'a', 'message not sent');
        $this->assertSame([], $this->sent($account));
    }

    public function test_a_redelivered_trigger_sends_the_media_once(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->media('i', 'image'), $this->text('b', 'B')]);

        $this->inbound($account, 'go', messageId: 'dup-1');
        $this->inbound($account, 'go', messageId: 'dup-1');

        $this->assertSame(['[image]', 'B'], $this->sent($account));
        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $account->id)->count());
    }

    public function test_media_after_a_delay_runs_on_the_scheduler_and_completes(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->delay('d'), $this->media('i', 'document', ['filename' => 'x.pdf']), $this->text('b', 'B')]);

        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->assertSame(['A'], $this->sent($account));
        $this->assertSame('skipped', $this->resume($s), 'not yet due');

        $this->travel(5)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['A', '[document]', 'B'], $this->sent($account));
        $this->assertSame('skipped', $this->resume($s), 'a completed session is never resumed again');
        $this->assertSame(['A', '[document]', 'B'], $this->sent($account));
    }

    public function test_a_worker_that_died_after_the_media_checkpoint_does_not_replay_earlier_nodes(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->text('a', 'A'), $this->media('i', 'video'), $this->text('b', 'B')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();

        // A worker claims, sends A, checkpoints the video node … and dies.
        WhatsAppFlowSession::query()->whereKey($s->id)->where('status', 'waiting')
            ->update(['wait_until' => now()->addSeconds(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS), 'attempts' => 1, 'current_node_id' => 'i']);
        MessageDispatchLog::record($account->id, 'journey', self::PHONE, success: true, messagePreview: 'A');

        $this->assertSame('skipped', $this->resume($s), 'the lease still holds');
        $this->travel(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS + 1)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['A', '[video]', 'B'], $this->sent($account));
    }

    // ==================================================================
    // 5. Version pinning (immediate + resumed)
    // ==================================================================

    private function edit(WhatsAppFlow $flow, array $changes): void
    {
        $graph = $flow->graph_data;
        foreach ($graph['nodes'] as &$node) {
            if (isset($changes[$node['id']])) {
                $node['data'] = $changes[$node['id']] + $node['data'];
            }
        }
        unset($node);
        $flow->update(['graph_data' => $graph]);
    }

    public function test_an_open_session_keeps_its_versions_text_and_media_on_the_immediate_path(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q', 'n', 'Q?'), $this->text('a', 'TEXT_V1'), $this->media('i', 'image', ['caption' => 'CAP_V1'])]);
        $this->inbound($account, 'go');
        $old = $this->flowSession($account);

        $this->edit($flow, ['a' => ['text' => 'TEXT_V2'], 'i' => ['caption' => 'CAP_V2', 'mediaUrl' => 'https://cdn.example.com/v2.jpg']]);

        $this->inbound($account, 'answer');
        $this->assertSame(['Q?', 'TEXT_V1', 'CAP_V1'], $this->sent($account));
        $this->assertSame(self::IMG, MessageDispatchLog::where('message_preview', 'CAP_V1')->value('media_url'));

        $this->inbound($account, 'go', '919800000002');
        $this->inbound($account, 'x', '919800000002');
        $this->assertSame(['Q?', 'TEXT_V2', 'CAP_V2'], $this->sent($account, '919800000002'));
        $this->assertNotSame($old->flow_version_id, $this->flowSession($account, '919800000002')->flow_version_id);
    }

    public function test_a_resumed_session_keeps_its_versions_text_and_media(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->delay('d'), $this->text('a', 'TEXT_V1'), $this->media('i', 'audio', ['mediaUrl' => 'https://cdn.example.com/v1.ogg'])]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $this->edit($flow, ['a' => ['text' => 'TEXT_V2'], 'i' => ['mediaUrl' => 'https://cdn.example.com/v2.ogg']]);
        $this->travel(5)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['TEXT_V1', '[audio]'], $this->sent($account));
        $this->assertSame('https://cdn.example.com/v1.ogg', MessageDispatchLog::where('message_preview', '[audio]')->value('media_url'));
    }

    public function test_a_retry_sends_the_pinned_media_not_the_edit_made_after_the_failure(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->media('i', 'image')]);
        $this->fail = [self::IMG];
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $this->edit($flow, ['i' => ['mediaUrl' => 'https://cdn.example.com/edited.jpg']]);
        $this->fail = [];
        $this->travel(2)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(self::IMG, MessageDispatchLog::where('status', 'sent')->where('source', 'journey')->sole()->media_url);
    }

    // ==================================================================
    // 6. Entitlement / RBAC / tenant
    // ==================================================================

    public function test_a_send_node_whose_capability_was_revoked_fails_at_the_node(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('m', 'LEGACY'), $this->media('i', 'image'), $this->text('b', 'B')]);
        $this->revoke($account, 'whatsapp_send');

        $this->inbound($account, 'go');

        $this->assertSame(['LEGACY'], $this->sent($account), 'the grandfathered legacy message still runs; the palette node does not');
        $this->assertEnded($this->flowSession($account), WhatsAppFlowSession::STATUS_FAILED, 'i', "requires the 'whatsapp_send' capability");
    }

    public function test_journey_entitlement_lost_before_a_resumed_media_node_blocks_and_restoration_resumes_it_once(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->media('i', 'image'), $this->text('b', 'B')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();

        $this->revoke($account, 'journey_automation');
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->resume($s));
        $this->assertSame([], $this->sent($account));

        // A blocked session is not "completed" by anything in flight.
        $this->assertFalse($this->engineCall('complete', $s->fresh()));
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $s->fresh()->status);

        $this->grant($account, 'journey_automation');
        app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s->fresh()));
        $this->assertSame(['[image]', 'B'], $this->sent($account));
    }

    public function test_nothing_runs_when_the_chatbot_module_is_disabled(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->media('i', 'image')]);
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();

        $this->inbound($account, 'go');

        $this->assertSame([], $this->calls);
        $this->assertSame(0, WhatsAppFlowSession::where('account_id', $account->id)->count());
    }

    public function test_a_session_pointed_at_another_accounts_flow_never_sends_its_media(): void
    {
        $a = $this->account();
        $b = $this->account();
        $foreign = $this->flow($b, [$this->q('q', 'x', 'X?'), $this->media('i', 'image', ['caption' => 'LEAK'])]);
        $this->flow($a, [$this->q('q', 'x', 'X?'), $this->text('m', 'MINE')]);
        $this->inbound($a, 'go');

        $s = $this->flowSession($a);
        $s->forceFill(['flow_id' => $foreign->id, 'flow_version_id' => $foreign->published_version_id])->save();
        $this->inbound($a, 'answer');

        $this->assertSame(['X?'], $this->sent($a));
        $this->assertSame([], $this->sent($b));
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $s->fresh()->status);
        $this->assertSame(0, MessageDispatchLog::where('message_preview', 'LEAK')->count());
    }

    // ==================================================================
    // 7. Completion / dead-end / terminal semantics
    // ==================================================================

    public function test_a_condition_with_no_matching_branch_and_no_default_completes_successfully(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'plan', 'Plan?'), ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'plan']], $this->text('pro', 'PRO')], [
            ['q', 'c'],
            ['c', 'pro', ['condition' => ['operator' => 'equals', 'value' => 'pro']]],
        ]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'free');

        $this->assertSame(['Plan?'], $this->sent($account));
        $this->assertEnded($this->flowSession($account), WhatsAppFlowSession::STATUS_COMPLETED, 'c');
    }

    public function test_a_conditional_whose_chosen_handle_is_unconnected_completes_successfully(): void
    {
        $account = $this->account();
        $this->flow($account, [
            $this->q('q', 'n', 'N?'),
            ['id' => 'k', 'type' => 'conditional', 'data' => ['match' => 'all', 'conditions' => [['variable' => 'n', 'operator' => 'greater_than', 'value' => '10']]]],
            $this->media('big', 'image'),
        ], [['q', 'k'], ['k', 'big', ['sourceHandle' => 'true']]]);

        $this->inbound($account, 'go');
        $this->inbound($account, '3');

        $this->assertSame(['N?'], $this->sent($account));
        $this->assertEnded($this->flowSession($account), WhatsAppFlowSession::STATUS_COMPLETED, 'k');
    }

    public function test_save_lead_completes_and_its_outgoing_connections_never_run(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), ['id' => 's', 'type' => 'save_lead', 'data' => []], $this->media('after', 'image')]);

        $this->inbound($account, 'go');

        $this->assertSame(['A'], $this->sent($account));
        $this->assertEnded($this->flowSession($account), WhatsAppFlowSession::STATUS_COMPLETED, 's');
        $this->assertSame(1, Lead::count());
    }

    public function test_a_reply_after_completion_does_not_continue_the_finished_session(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $this->inbound($account, 'hello again');

        $this->assertSame(['A'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->fresh()->status);
        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $account->id)->count());
    }

    public function test_a_cancellation_during_the_last_send_is_not_overwritten_by_completion(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->media('i', 'image')]);
        $this->during = function () use ($account) {
            WhatsAppFlowSession::where('account_id', $account->id)->update(['status' => WhatsAppFlowSession::STATUS_CANCELLED]);
        };

        $this->inbound($account, 'go');

        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $this->flowSession($account)->status);
    }

    public function test_a_cancellation_during_a_failing_send_is_not_turned_into_a_retry(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A')]);
        $this->fail = ['A'];
        // Phase 7 Task 9: cancelled through the real cancel path (it clears the
        // timer; a running immediate session now carries a run lease there).
        $this->during = function () use ($account) {
            app(WhatsAppJourneyEngine::class)->cancelSession(WhatsAppFlowSession::where('account_id', $account->id)->sole());
        };

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $s->status);
        $this->assertNull($s->wait_until);
        $this->travel(1)->hours();
        $this->assertSame('skipped', $this->resume($s));
    }

    public static function terminalStatuses(): array
    {
        return [
            'completed' => [WhatsAppFlowSession::STATUS_COMPLETED],
            'failed' => [WhatsAppFlowSession::STATUS_FAILED],
            'expired' => [WhatsAppFlowSession::STATUS_EXPIRED],
            'cancelled' => [WhatsAppFlowSession::STATUS_CANCELLED],
            'blocked' => [WhatsAppFlowSession::STATUS_BLOCKED],
        ];
    }

    #[DataProvider('terminalStatuses')]
    public function test_an_ended_or_blocked_session_is_never_rewritten_by_a_run_in_flight(string $status): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'x', 'X?')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        WhatsAppFlowSession::query()->whereKey($s->id)->update(['status' => $status, 'last_error' => 'kept']);

        // The in-memory copy still thinks it is running.
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $s->status);
        $this->assertFalse($this->engineCall('complete', $s));
        $this->assertFalse($this->engineCall('expire', $s, 'late'));
        $this->assertSame($status, $this->engineCall('failSession', $s, 'late'));
        $this->assertFalse($this->engineCall('transition', $s, ['status' => WhatsAppFlowSession::STATUS_ACTIVE]));
        $this->engineCall('scheduleRetry', $s, 'late');

        $s->refresh();
        $this->assertSame($status, $s->status);
        $this->assertSame('kept', $s->last_error);
        $this->assertNull($s->wait_until);

        $this->inbound($account, 'answer');
        $this->assertSame(['X?'], $this->sent($account), 'a reply never reaches it');
        $this->travel(1)->hours();
        $this->assertSame('skipped', $this->resume($s));
    }

    public function test_completion_clears_run_state_and_keeps_the_final_node(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')]);
        $this->fail = ['B'];
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->assertNotNull($s->last_error);

        $this->fail = [];
        $this->travel(2)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertEnded($s, WhatsAppFlowSession::STATUS_COMPLETED, 'b');
        $this->assertSame(0, (int) $s->attempts);
    }

    // ==================================================================
    // 8. Step limit
    // ==================================================================

    public function test_a_loop_of_text_nodes_stops_at_the_step_limit_durably(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->media('i', 'image')], [['a', 'i'], ['i', 'a']]);

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $s->status);
        $this->assertStringContainsString('Stopped after 25 steps', $s->last_error);
        $this->assertCount(25, $this->sent($account));
    }

    public function test_a_resumed_run_that_hits_the_step_limit_keeps_its_reason(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->text('a', 'A'), $this->text('b', 'B')], [['d', 'a'], ['a', 'b'], ['b', 'a']]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $this->resume($s));

        $s->refresh();
        $this->assertStringContainsString('Stopped after 25 steps', (string) $s->last_error, 'settle() used to wipe the reason on the resumed path');
        $this->assertNull($s->wait_until);
        $this->assertCount(25, $this->sent($account));
    }
}
