<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppFlowVersion;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * Phase 7 Task 2 — immutable journey versions and in-progress run isolation.
 *
 * Every saved graph is an immutable WhatsAppFlowVersion; a session is
 * pinned to the published version it started on and every later step
 * (question answer, condition, message, delay resume, save_lead) reads
 * that version only. Edits and publishes affect NEW sessions only.
 */
class JourneyVersioningTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/whatsapp/flows';

    private const PHONE = '919812345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
    }

    // ------------------------------------------------------------------ fixtures

    /** Growth (QR): journey_automation + crm from the plan. */
    private function tenant(): Account
    {
        $account = Account::factory()->create();
        $plan = PlanCatalog::find('growth');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth',
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null, 'status' => 'pending',
            'paid_at' => null, 'gateway_raw_response' => null,
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account->fresh();
    }

    private function admin(Account $account, ?string $role = 'admin', array $permissions = []): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        if ($role) {
            $user->assignRole($role);
        }
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }

        return $user;
    }

    /** trigger → question(name) → condition(name) → [yes] message → save_lead / [default] message */
    private function askGraph(string $tag): array
    {
        return [
            'nodes' => [
                ['id' => 't', 'type' => 'trigger', 'data' => []],
                ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => "Interested? ({$tag})", 'variable_name' => 'answer', 'input_type' => 'text']],
                ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'answer']],
                ['id' => 'yes', 'type' => 'message', 'data' => ['text' => "Great ({$tag})"]],
                ['id' => 'save', 'type' => 'save_lead', 'data' => ['completion_message' => "Saved ({$tag})"]],
                ['id' => 'no', 'type' => 'message', 'data' => ['text' => "Maybe later ({$tag})"]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't', 'target' => 'q'],
                ['id' => 'e2', 'source' => 'q', 'target' => 'c'],
                ['id' => 'e3', 'source' => 'c', 'target' => 'yes', 'condition' => ['operator' => 'equals', 'value' => 'yes']],
                ['id' => 'e4', 'source' => 'c', 'target' => 'no', 'is_default' => true],
                ['id' => 'e5', 'source' => 'yes', 'target' => 'save'],
            ],
        ];
    }

    /** trigger → "Before" → delay(5m) → message(After tag) */
    private function delayGraph(string $tag): array
    {
        return [
            'nodes' => [
                ['id' => 't', 'type' => 'trigger', 'data' => []],
                ['id' => 'b', 'type' => 'message', 'data' => ['text' => "Before ({$tag})"]],
                ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 5, 'unit' => 'minutes']],
                ['id' => 'a', 'type' => 'message', 'data' => ['text' => "After ({$tag})"]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't', 'target' => 'b'],
                ['id' => 'e2', 'source' => 'b', 'target' => 'd'],
                ['id' => 'e3', 'source' => 'd', 'target' => 'a'],
            ],
        ];
    }

    private function payload(array $graph, array $extra = []): array
    {
        return $extra + ['name' => 'Journey', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true, 'graph_data' => $graph];
    }

    private function createFlow(User $admin, array $graph, array $extra = []): WhatsAppFlow
    {
        $id = $this->actingAs($admin)->postJson(self::BASE, $this->payload($graph, $extra))->assertCreated()->json('data.id');

        return WhatsAppFlow::findOrFail($id);
    }

    private function edit(User $admin, WhatsAppFlow $flow, array $graph, array $extra = []): void
    {
        $this->actingAs($admin)->putJson(self::BASE."/{$flow->id}", $this->payload($graph, $extra))->assertOk();
    }

    private function inbound(Account $account, string $text, string $phone = self::PHONE): bool
    {
        return app(WhatsAppJourneyEngine::class)->handleInboundMessage($account->id, $phone, $text);
    }

    private function sessionFor(string $phone = self::PHONE): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('phone_number', $phone)->latest('id')->firstOrFail();
    }

    /** @return list<string> */
    private function sentTo(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('recipient_phone', $phone)
            ->orderBy('id')->pluck('message_preview')->all();
    }

    private function versionNumbers(WhatsAppFlow $flow): array
    {
        return WhatsAppFlowVersion::where('flow_id', $flow->id)->orderBy('version')->pluck('version')->all();
    }

    // ------------------------------------------------------------------ backfill

    public function test_the_backfill_gives_an_existing_journey_exactly_one_initial_version_and_pins_its_sessions(): void
    {
        $account = $this->tenant();
        $flow = WhatsAppFlow::create($this->payload($this->askGraph('v1'), ['account_id' => $account->id]));
        // Recreate the pre-versioning state: no versions, no pointer, an unpinned waiting session.
        DB::table('whatsapp_flows')->where('id', $flow->id)->update(['published_version_id' => null]);
        DB::table('whatsapp_flow_versions')->where('flow_id', $flow->id)->delete();
        $session = WhatsAppFlowSession::create(['account_id' => $account->id, 'flow_id' => $flow->id, 'phone_number' => self::PHONE,
            'current_node_id' => 'q', 'context_data' => [], 'status' => WhatsAppFlowSession::STATUS_ACTIVE]);
        $migration = require database_path('migrations/2026_09_24_130001_backfill_whatsapp_flow_versions.php');

        $migration->up();
        $migration->up(); // idempotent

        $version = WhatsAppFlowVersion::where('flow_id', $flow->id)->sole();
        $this->assertSame(1, $version->version);
        $this->assertEquals($flow->graph_data, $version->graph_data);
        $this->assertNull($version->created_by_user_id);
        $this->assertSame($version->id, $flow->fresh()->published_version_id);
        $this->assertSame($version->id, $session->fresh()->flow_version_id);

        // …and the session keeps working on it.
        $this->assertTrue($this->inbound($account, 'yes'));
        $this->assertSame(['Great (v1)', 'Saved (v1)'], $this->sentTo($account));
    }

    // ------------------------------------------------------------------ versions + pinning

    public function test_create_makes_version_one_edit_makes_the_next_and_a_graph_neutral_save_makes_none(): void
    {
        $account = $this->tenant();
        $admin = $this->admin($account);

        $res = $this->actingAs($admin)->postJson(self::BASE, $this->payload($this->askGraph('v1')))->assertCreated();
        $flow = WhatsAppFlow::findOrFail($res->json('data.id'));
        $v1 = WhatsAppFlowVersion::where('flow_id', $flow->id)->sole();
        $this->assertSame($v1->id, $res->json('data.published_version_id'), 'the existing response carries the pointer, nothing else is required');
        $this->assertSame($admin->id, $v1->created_by_user_id);

        $this->edit($admin, $flow, $this->askGraph('v2'));
        $this->assertSame([1, 2], $this->versionNumbers($flow));
        $this->assertSame(WhatsAppFlowVersion::where('flow_id', $flow->id)->where('version', 2)->value('id'), $flow->fresh()->published_version_id);

        // Rename / toggle / identical re-save: no new version.
        $this->edit($admin, $flow, $this->askGraph('v2'), ['name' => 'Renamed']);
        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/toggle")->assertOk();
        $this->assertSame([1, 2], $this->versionNumbers($flow));
    }

    public function test_a_new_session_pins_to_the_published_version(): void
    {
        $account = $this->tenant();
        $flow = $this->createFlow($this->admin($account), $this->askGraph('v1'));

        $this->inbound($account, 'join');

        $this->assertSame($flow->fresh()->published_version_id, $this->sessionFor()->flow_version_id);
        $this->assertSame(['Interested? (v1)'], $this->sentTo($account));
    }

    public function test_a_question_session_answers_branches_and_saves_the_lead_on_its_old_version_exactly_once(): void
    {
        $account = $this->tenant();
        $admin = $this->admin($account);
        $flow = $this->createFlow($admin, $this->askGraph('v1'));
        $this->inbound($account, 'join');
        $oldPin = $this->sessionFor()->flow_version_id;

        // v2 changes every text AND deletes the save_lead node and its edge.
        $v2 = $this->askGraph('v2');
        $v2['nodes'] = array_values(array_filter($v2['nodes'], fn ($n) => $n['id'] !== 'save'));
        $v2['edges'] = array_values(array_filter($v2['edges'], fn ($e) => $e['id'] !== 'e5'));
        $this->edit($admin, $flow, $v2);

        $this->assertTrue($this->inbound($account, 'yes'));

        $s = $this->sessionFor();
        $this->assertSame($oldPin, $s->flow_version_id, 'the pin never moves');
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->status);
        $this->assertSame(['Interested? (v1)', 'Great (v1)', 'Saved (v1)'], $this->sentTo($account));
        $this->assertSame(1, Lead::count(), 'save_lead ran once, from the pinned graph');
        $this->assertSame(1, CrmLead::count());
        $this->assertSame($oldPin, Lead::sole()->raw_field_data['flow_version_id']);

        // A NEW session uses v2.
        $this->inbound($account, 'join', '919800000002');
        $this->inbound($account, 'yes', '919800000002');
        $this->assertSame(['Interested? (v2)', 'Great (v2)'], $this->sentTo($account, '919800000002'));
        $this->assertSame(1, Lead::count(), 'v2 has no save_lead');
    }

    public function test_a_waiting_delay_session_resumes_on_its_old_version_after_the_delay_is_deleted(): void
    {
        $account = $this->tenant();
        $admin = $this->admin($account);
        $flow = $this->createFlow($admin, $this->delayGraph('v1'));
        $this->inbound($account, 'join');
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $this->sessionFor()->status);

        // v2: no delay node at all, different texts.
        $this->edit($admin, $flow, [
            'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'only', 'type' => 'message', 'data' => ['text' => 'Only (v2)']]],
            'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'only']],
        ]);
        $this->travel(5)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, app(WhatsAppJourneyEngine::class)->resumeDueSession($this->sessionFor()->id));
        $this->assertSame(['Before (v1)', 'After (v1)'], $this->sentTo($account));

        $this->inbound($account, 'join', '919800000002');
        $this->assertSame(['Only (v2)'], $this->sentTo($account, '919800000002'));
    }

    // ------------------------------------------------------------------ drafts / publish / rollback / test

    public function test_a_draft_does_not_reach_new_sessions_until_published_and_an_old_version_can_be_republished(): void
    {
        $account = $this->tenant();
        $admin = $this->admin($account);
        $flow = $this->createFlow($admin, $this->delayGraph('v1'));
        $v1 = $flow->published_version_id;

        $this->edit($admin, $flow, $this->delayGraph('v2'), ['publish' => false]);
        $v2 = WhatsAppFlowVersion::where('flow_id', $flow->id)->where('version', 2)->value('id');
        $this->assertSame($v1, $flow->fresh()->published_version_id, 'a draft is not published');
        $this->assertSame('Before (v2)', $this->actingAs($admin)->getJson(self::BASE."/{$flow->id}")->json('data.graph_data.nodes.1.data.text'), 'the editor shows the draft');

        $this->inbound($account, 'join', '919800000001');
        $this->assertSame(['Before (v1)'], $this->sentTo($account, '919800000001'));

        // The manual test runs what was last saved (the draft).
        app(WhatsAppJourneyEngine::class)->testFlow($account, $flow->fresh(), '919800000009');
        $this->assertSame($v2, $this->sessionFor('919800000009')->flow_version_id);

        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/versions/{$v2}/publish")->assertOk()->assertJsonPath('data.published_version_id', $v2);
        $this->inbound($account, 'join', '919800000002');
        $this->assertSame(['Before (v2)'], $this->sentTo($account, '919800000002'));

        // Rollback = publish v1 again. The v2 session stays on v2.
        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/versions/{$v1}/publish")->assertOk();
        $this->inbound($account, 'join', '919800000003');
        $this->assertSame(['Before (v1)'], $this->sentTo($account, '919800000003'));
        $this->assertSame($v2, $this->sessionFor('919800000002')->flow_version_id);

        $list = $this->actingAs($admin)->getJson(self::BASE."/{$flow->id}/versions")->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($list, 'version'));
        $this->assertSame([false, true], array_column($list, 'is_published'));
        $this->assertArrayNotHasKey('graph_data', $list[0]);
        $this->assertSame('Before (v2)', $this->actingAs($admin)->getJson(self::BASE."/{$flow->id}/versions/{$v2}")->json('data.graph_data.nodes.1.data.text'));
    }

    // ------------------------------------------------------------------ immutability / concurrency

    public function test_a_version_cannot_be_modified_or_deleted(): void
    {
        $account = $this->tenant();
        $flow = $this->createFlow($this->admin($account), $this->askGraph('v1'));
        $version = WhatsAppFlowVersion::where('flow_id', $flow->id)->sole();

        try {
            $version->forceFill(['graph_data' => ['nodes' => [], 'edges' => []]])->save();
            $this->fail('update must throw');
        } catch (LogicException) {
        }

        try {
            $version->delete();
            $this->fail('delete must throw');
        } catch (LogicException) {
        }

        $this->assertEquals($this->askGraph('v1'), $version->fresh()->graph_data);
    }

    public function test_concurrent_saves_never_duplicate_a_version_number_and_the_pointer_matches_the_stored_graph(): void
    {
        $account = $this->tenant();
        $flow = $this->createFlow($this->admin($account), $this->askGraph('v1'));

        // Two editors holding stale copies of the same journey.
        $a = WhatsAppFlow::findOrFail($flow->id);
        $b = WhatsAppFlow::findOrFail($flow->id);
        $a->forceFill(['graph_data' => $this->askGraph('A')])->save();
        $b->forceFill(['graph_data' => $this->askGraph('B')])->save();

        $this->assertSame([1, 2, 3], $this->versionNumbers($flow));
        $fresh = $flow->fresh();
        $this->assertEquals($fresh->graph_data, $fresh->publishedVersion->graph_data, 'published = what is stored');

        // The database refuses a duplicate number outright.
        $this->expectException(QueryException::class);
        DB::table('whatsapp_flow_versions')->insert([
            'flow_id' => $flow->id, 'account_id' => $account->id, 'version' => 3,
            'graph_data' => json_encode($this->askGraph('dup')), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_next_number_is_read_under_a_lock_on_the_journey_row(): void
    {
        $account = $this->tenant();
        $flow = $this->createFlow($this->admin($account), $this->askGraph('v1'));
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = [strtolower($q->sql), DB::transactionLevel()];
        });

        $flow->forceFill(['graph_data' => $this->askGraph('v2')])->save();

        $locked = collect($queries)->first(fn ($q) => str_contains($q[0], 'from "whatsapp_flows"') || str_contains($q[0], 'from `whatsapp_flows`'));
        $this->assertNotNull($locked);
        $this->assertGreaterThan(0, $locked[1], 'read inside a transaction');
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertStringContainsString('for update', $locked[0]);
        }
    }

    public function test_deleting_a_journey_removes_its_versions_and_sessions_cleanly(): void
    {
        $account = $this->tenant();
        $admin = $this->admin($account);
        $flow = $this->createFlow($admin, $this->delayGraph('v1'));
        $this->edit($admin, $flow, $this->delayGraph('v2'));
        $this->inbound($account, 'join');

        $this->actingAs($admin)->deleteJson(self::BASE."/{$flow->id}")->assertOk();

        $this->assertSame(0, WhatsAppFlowVersion::count());
        $this->assertSame(0, WhatsAppFlowSession::count());
    }

    // ------------------------------------------------------------------ access control

    public function test_versions_are_tenant_scoped(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $aFlow = $this->createFlow($this->admin($a), $this->askGraph('a'));
        $bAdmin = $this->admin($b);
        $bFlow = $this->createFlow($bAdmin, $this->askGraph('b'));
        $aVersion = $aFlow->published_version_id;

        $this->actingAs($bAdmin)->getJson(self::BASE."/{$aFlow->id}/versions")->assertNotFound();
        $this->actingAs($bAdmin)->getJson(self::BASE."/{$aFlow->id}/versions/{$aVersion}")->assertNotFound();
        $this->actingAs($bAdmin)->getJson(self::BASE."/{$bFlow->id}/versions/{$aVersion}")->assertNotFound();
        $this->actingAs($bAdmin)->postJson(self::BASE."/{$bFlow->id}/versions/{$aVersion}/publish")->assertNotFound();
        $this->actingAs($bAdmin)->postJson(self::BASE."/{$aFlow->id}/versions/{$aVersion}/publish?account_id={$a->id}")->assertNotFound();

        $this->assertSame($bFlow->fresh()->published_version_id, WhatsAppFlowVersion::where('flow_id', $bFlow->id)->value('id'));
    }

    public function test_capability_module_and_permission_gates_apply_to_version_routes(): void
    {
        $account = $this->tenant();
        $admin = $this->admin($account);
        $flow = $this->createFlow($admin, $this->askGraph('v1'));
        $this->edit($admin, $flow, $this->askGraph('v2'), ['publish' => false]);
        $v1 = $flow->published_version_id;
        $v2 = WhatsAppFlowVersion::where('flow_id', $flow->id)->where('version', 2)->value('id');

        // Permission: a viewer lists/reads but cannot publish; a plain user gets nothing.
        $viewer = $this->admin($account, null, ['whatsapp.view']);
        $this->actingAs($viewer)->getJson(self::BASE."/{$flow->id}/versions")->assertOk();
        $this->actingAs($viewer)->postJson(self::BASE."/{$flow->id}/versions/{$v2}/publish")->assertForbidden();
        $this->actingAs($this->admin($account, 'user'))->getJson(self::BASE."/{$flow->id}/versions")->assertForbidden();

        // Capability.
        AccountEntitlement::where('account_id', $account->id)->where('capability_id', Capability::where('slug', 'journey_automation')->value('id'))->update(['revoked_at' => now()]);
        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/versions/{$v2}/publish")->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => null]);

        // Module.
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        $this->actingAs($admin)->getJson(self::BASE."/{$flow->id}/versions")->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');

        $this->assertSame($v1, $flow->fresh()->published_version_id, 'nothing was published');
    }

    public function test_publishing_rechecks_node_entitlements_against_todays_entitlements(): void
    {
        $account = $this->tenant();
        $admin = $this->admin($account);
        $codeId = Capability::where('slug', 'custom_code')->value('id');
        AccountEntitlement::create(['account_id' => $account->id, 'capability_id' => $codeId, 'source' => 'manual_grant', 'granted_by_account_id' => null]);
        $flow = $this->createFlow($admin, $this->askGraph('v1'));
        $withCode = $this->askGraph('code');
        $withCode['nodes'][] = ['id' => 'x', 'type' => 'code', 'data' => ['language' => 'javascript', 'code' => 'x']];
        $this->edit($admin, $flow, $withCode, ['publish' => false]);
        $v2 = WhatsAppFlowVersion::where('flow_id', $flow->id)->where('version', 2)->value('id');

        AccountEntitlement::where('account_id', $account->id)->where('capability_id', $codeId)->update(['revoked_at' => now()]);

        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/versions/{$v2}/publish")
            ->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        $this->assertNotSame($v2, $flow->fresh()->published_version_id);
    }

    public function test_non_numeric_version_ids_are_404_not_500(): void
    {
        $account = $this->tenant();
        $admin = $this->admin($account);
        $flow = $this->createFlow($admin, $this->askGraph('v1'));

        $this->actingAs($admin)->getJson(self::BASE."/{$flow->id}/versions/abc")->assertNotFound();
        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/versions/abc/publish")->assertNotFound();
    }
}
