<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Provider;
use App\Models\ProviderCapability;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Access\EntitlementAuditLogger;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Support\JourneySecrets;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P5-8 — Entitlement audit logging.
 *
 * Every authorization decision at an entitlement boundary lands in the
 * existing audit trail (activity_logs, module "Entitlement Authorization",
 * action_type allowed|denied) through EntitlementAuditLogger, with a
 * machine-readable category. Driven through the real HTTP routes, the real
 * inbound path and the real scheduler command; provider sends are faked at
 * the HTTP boundary only.
 */
class EntitlementAuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const FLOWS = '/api/whatsapp/flows';

    private const PHONE = '919812345678';

    private const SECRET = 'sk_live_AUDIT_SECRET_4b5c6d7e8f';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        // Built before any actingAs() (the documented Spatie guard constraint).
        Role::firstOrCreate(['name' => 'journey_viewer'])->givePermissionTo('whatsapp.view');

        Http::fake(fn () => Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200));
    }

    // ================================================================== fixtures

    private function account(string $plan = 'growth', array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
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
            'meta_access_token' => 'EAAG_p58_token_0123456789',
        ] : []));

        return $account->fresh();
    }

    private function user(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function setCapability(Account $account, string $slug, bool $held): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => $held ? null : now()],
        );
    }

    private function payload(array $nodes, array $extra = []): array
    {
        $edges = [['id' => 'e0', 'source' => 't', 'target' => $nodes[0]['id']]];
        for ($i = 1; $i < count($nodes); $i++) {
            $edges[] = ['id' => "e{$i}", 'source' => $nodes[$i - 1]['id'], 'target' => $nodes[$i]['id']];
        }

        return $extra + [
            'name' => 'Audit', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => $edges],
        ];
    }

    private function text(string $id = 'a', string $text = 'Hi'): array
    {
        return ['id' => $id, 'type' => 'text', 'data' => ['text' => $text]];
    }

    private function flow(Account $account, array $nodes): WhatsAppFlow
    {
        return WhatsAppFlow::create(['account_id' => $account->id, 'name' => 'J', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true] + ['graph_data' => $this->payload($nodes)['graph_data']]);
    }

    private function inbound(Account $account, string $text = 'go', string $phone = self::PHONE): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, 'qr', null);
    }

    /** @return \Illuminate\Support\Collection<int, ActivityLog> */
    private function audits(array $where = [])
    {
        return ActivityLog::where('module_name', EntitlementAuditLogger::MODULE)->orderBy('id')->get()
            ->filter(function (ActivityLog $log) use ($where) {
                foreach ($where as $key => $value) {
                    if ($key === 'action_type' ? $log->action_type !== $value : (($log->new_values[$key] ?? null) !== $value)) {
                        return false;
                    }
                }

                return true;
            })->values();
    }

    private function one(array $where): ActivityLog
    {
        $rows = $this->audits($where);
        $this->assertCount(1, $rows, 'expected exactly one audit row for '.json_encode($where).'; got '.$this->audits()->map(fn ($l) => $l->new_values)->toJson());

        return $rows->first();
    }

    // ================================================================== allowed

    public function test_an_entitled_capability_on_the_correct_provider_is_audited_as_allowed_with_its_tenant_context(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $flowId = $this->actingAs($user)->postJson(self::FLOWS, $this->payload([$this->text()]))->assertCreated()->json('data.id');

        $row = $this->one(['action' => 'journey.publish', 'node_type' => 'text']);
        $this->assertSame(EntitlementAuditLogger::ALLOWED, $row->action_type);
        $this->assertSame([$account->id, $user->id], [$row->account_id, $row->user_id]);
        $this->assertSame('api/whatsapp/flows', $row->route_path);
        $this->assertSame($this->canonical([
            'decision' => 'allowed', 'action' => 'journey.publish', 'category' => 'entitled', 'reason' => null, 'source' => 'api',
            'resource_type' => 'journey', 'resource_id' => null, 'node_type' => 'text', 'module' => 'chatbot',
            'capability' => null, 'capabilities' => ['whatsapp_send'], 'provider' => 'qr', 'providers' => ['qr', 'meta'],
            'error_code' => null, 'account_id' => $account->id,
        ]), $this->canonical($row->new_values));

        // Runtime: the same node, executed → one allowed decision per node type per run.
        $this->actingAs($user)->putJson(self::FLOWS."/{$flowId}", $this->payload([$this->text('a', 'One'), $this->text('b', 'Two')]))->assertOk();
        $this->app['auth']->forgetGuards(); // the webhook carries no user
        $this->inbound($account);

        $run = $this->one(['action' => 'journey.node.execute']);
        $this->assertSame(['allowed', 'entitled', 'text', 'qr', 'inbound', $account->id], [
            $run->action_type, $run->new_values['category'], $run->new_values['node_type'], $run->new_values['provider'], $run->new_values['source'], $run->account_id,
        ]);
        $this->assertNull($run->user_id, 'no actor on the webhook path');
        $this->assertSame(WhatsAppFlowSession::where('account_id', $account->id)->value('id'), $run->new_values['session_id']);
    }

    public function test_a_super_admin_bypass_is_audited_as_allowed_on_the_selected_client(): void
    {
        $client = $this->account();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson(self::FLOWS."?account_id={$client->id}", $this->payload([$this->text()]))->assertCreated();

        $row = $this->one(['category' => 'super_admin_bypass']);
        $this->assertSame([EntitlementAuditLogger::ALLOWED, $client->id, $admin->id], [$row->action_type, $row->account_id, $row->user_id]);
    }

    // ================================================================== denied

    public function test_a_missing_capability_is_audited_at_publish_and_the_403_is_unchanged(): void
    {
        $account = $this->account();
        $this->setCapability($account, 'whatsapp_send', false);

        $this->actingAs($this->user($account))->postJson(self::FLOWS, $this->payload([$this->text()]))
            ->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');

        $row = $this->one(['node_type' => 'text']);
        $this->assertSame(['denied', 'capability_not_entitled', 'whatsapp_send', 'JOURNEY_NODE_NOT_ENTITLED', 'journey.publish'], [
            $row->action_type, $row->new_values['category'], $row->new_values['capability'], $row->new_values['error_code'], $row->new_values['action'],
        ]);
        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_a_capability_revoked_after_publication_is_audited_as_denied_at_run_time(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text()]);
        $this->setCapability($account, 'whatsapp_send', false);

        $this->inbound($account);

        $row = $this->one(['action' => 'journey.node.execute']);
        $this->assertSame(['denied', 'capability_not_entitled', 'whatsapp_send', 'inbound'], [$row->action_type, $row->new_values['category'], $row->new_values['capability'], $row->new_values['source']]);
        // Runtime behaviour unchanged: failed at the node, nothing sent.
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, WhatsAppFlowSession::where('account_id', $account->id)->value('status'));
    }

    public function test_a_missing_route_entitlement_and_a_disabled_module_are_audited_at_the_guards(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $this->setCapability($account, 'journey_automation', false);
        $this->actingAs($user)->getJson(self::FLOWS)->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $cap = $this->one(['category' => 'capability_not_entitled']);
        $this->assertSame(['denied', 'journey_automation', 'route.access', 'api/whatsapp/flows', $account->id], [$cap->action_type, $cap->new_values['capability'], $cap->new_values['action'], $cap->route_path, $cap->account_id]);

        $this->setCapability($account, 'journey_automation', true);
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        $this->actingAs($user)->getJson(self::FLOWS)->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        $mod = $this->one(['category' => 'module_disabled']);
        $this->assertSame(['denied', 'chatbot', $account->id], [$mod->action_type, $mod->new_values['module'], $mod->account_id]);
    }

    public function test_the_runtime_journey_entitlement_block_and_its_restoration_are_both_audited(): void
    {
        $account = $this->account();
        $this->flow($account, [['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Q?', 'variable_name' => 'x', 'input_type' => 'text']], $this->text()]);
        $this->inbound($account);

        $this->setCapability($account, 'journey_automation', false);
        $this->inbound($account, 'answer');
        $blocked = $this->one(['action' => 'journey.session.run']);
        $this->assertSame(['denied', 'capability_not_entitled', 'journey_automation'], [$blocked->action_type, $blocked->new_values['category'], $blocked->new_values['capability']]);

        // A new trigger while not entitled: the start attempt is audited too.
        $this->inbound($account, 'go', '919800000002');
        $this->assertSame('denied', $this->one(['action' => 'journey.session.start'])->action_type);

        $this->setCapability($account, 'journey_automation', true);
        $this->artisan('journeys:resume-due')->assertSuccessful();
        $restored = $this->one(['action' => 'journey.session.restore']);
        $this->assertSame(['allowed', 'entitled', 'scheduler'], [$restored->action_type, $restored->new_values['category'], $restored->new_values['source']]);
    }

    public function test_a_wrong_provider_is_audited_as_provider_not_supported(): void
    {
        // Growth runs on QR; a Meta-only node (template) is refused at save.
        $account = $this->account('growth');
        $this->actingAs($this->user($account))->postJson(self::FLOWS, $this->payload([['id' => 'x', 'type' => 'template', 'data' => ['templateId' => 'welcome']]], ['publish' => false]))
            ->assertStatus(403);

        $row = $this->one(['node_type' => 'template']);
        $this->assertSame(['denied', 'provider_not_supported', 'qr', ['meta']], [$row->action_type, $row->new_values['category'], $row->new_values['provider'], $row->new_values['providers']]);
        $this->assertSame('journey.save', $row->new_values['action'], 'a draft save is audited as a save, not a publish');

        // At run time, too: Meta's whatsapp_send pairing switched off.
        $meta = $this->account('business');
        $this->flow($meta, [$this->text()]);
        ProviderCapability::where('provider_id', Provider::where('slug', 'meta')->value('id'))
            ->where('capability_id', Capability::where('slug', 'whatsapp_send')->value('id'))->firstOrFail()->update(['supported' => false]);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.x']]], 200));
        $this->inbound($meta);

        $run = $this->one(['action' => 'journey.node.execute', 'account_id' => $meta->id]);
        $this->assertSame(['denied', 'provider_not_supported', 'meta', 'whatsapp_send'], [$run->action_type, $run->new_values['category'], $run->new_values['provider'], $run->new_values['capability']]);
    }

    public function test_a_missing_permission_is_audited_as_missing_permission_not_as_an_entitlement(): void
    {
        $account = $this->account();
        $viewer = $this->user($account, 'journey_viewer');

        $this->actingAs($viewer)->postJson(self::FLOWS, $this->payload([$this->text()]))->assertForbidden();

        $row = $this->one(['category' => 'missing_permission']);
        $this->assertSame(['denied', 'manage-chatbot|whatsapp.create', $account->id, $viewer->id], [$row->action_type, $row->new_values['permission'], $row->account_id, $row->user_id]);
        $this->assertCount(0, $this->audits(['category' => 'capability_not_entitled']));
    }

    public function test_publish_refusals_keep_their_real_category(): void
    {
        $account = $this->account();
        $this->setCapability($account, 'external_api', true);
        $user = $this->user($account);

        $this->actingAs($user)->postJson(self::FLOWS, $this->payload([['id' => 'x', 'type' => 'api', 'data' => ['method' => 'GET', 'url' => 'https://api.test/v1']]]))
            ->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');
        $unsupported = $this->one(['category' => 'unsupported_node']);
        $this->assertSame(['denied', 'api', 'journey.publish'], [$unsupported->action_type, $unsupported->new_values['node_type'], $unsupported->new_values['action']]);
        // The entitlement check itself passed and is recorded as such — not turned into a denial.
        $this->assertSame('allowed', $this->one(['node_type' => 'api', 'category' => 'entitled'])->action_type);

        $this->actingAs($user)->postJson(self::FLOWS, $this->payload([$this->text('a', '')]))->assertStatus(422);
        $this->assertSame('denied', $this->one(['category' => 'invalid_configuration'])->action_type);

        // Ordinary 422 validation (bad trigger type) is not an authorization decision at all.
        $before = $this->audits()->count();
        $this->actingAs($user)->postJson(self::FLOWS, $this->payload([$this->text()], ['trigger_type' => 'nope']))->assertStatus(422);
        $this->assertSame($before, $this->audits()->count());
    }

    public function test_cross_tenant_access_is_audited_on_the_actors_own_tenant(): void
    {
        $owner = $this->account();
        $intruderAccount = $this->account();
        $flow = $this->flow($owner, [$this->text()]);
        $intruder = $this->user($intruderAccount);

        $this->actingAs($intruder)->getJson(self::FLOWS."/{$flow->id}")->assertNotFound();
        $this->actingAs($intruder)->putJson(self::FLOWS."/{$flow->id}", $this->payload([$this->text()]))->assertNotFound();

        $rows = $this->audits(['category' => 'cross_tenant']);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame([$intruderAccount->id, $owner->id, $flow->id, 'denied'], [$row->account_id, $row->new_values['target_account_id'], $row->new_values['resource_id'], $row->action_type]);
        }
        $this->assertSame(['journey.show', 'journey.update'], $rows->map(fn ($r) => $r->new_values['action'])->all());
        $this->assertSame(0, ActivityLog::where('module_name', EntitlementAuditLogger::MODULE)->where('account_id', $owner->id)->count(), 'nothing written into the victim tenant');

        // A genuinely missing id is just a 404 — no audit row.
        $this->actingAs($intruder)->getJson(self::FLOWS.'/999999')->assertNotFound();
        $this->assertCount(2, $this->audits(['category' => 'cross_tenant']));
    }

    public function test_an_agent_selecting_an_account_that_is_not_its_client_is_audited_on_the_agents_account(): void
    {
        $agentAccount = $this->account('growth', ['account_type' => 'agent']);
        $agent = $this->user($agentAccount);
        $client = $this->account();
        $client->forceFill(['agent_id' => $agentAccount->id])->save();
        $stranger = $this->account();

        $this->actingAs($agent)->getJson(self::FLOWS."?account_id={$client->id}")->assertOk();
        $this->assertCount(0, $this->audits(['category' => 'unauthorized_target_account']));

        $this->actingAs($agent)->getJson(self::FLOWS."?account_id={$stranger->id}")->assertNotFound();

        $row = $this->one(['category' => 'unauthorized_target_account']);
        $this->assertSame([$agentAccount->id, $stranger->id, $agent->id], [$row->account_id, $row->new_values['target_account_id'], $row->user_id]);
        $this->assertSame(0, ActivityLog::where('account_id', $stranger->id)->where('module_name', EntitlementAuditLogger::MODULE)->count());
    }

    public function test_an_api_key_path_is_audited_at_the_same_boundary(): void
    {
        $account = $this->account('starter'); // starter has no crm
        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create(['account_id' => $account->id, 'name' => 'Integration', 'key_prefix' => substr($plain, 0, 10), 'key_hash' => ApiKey::hashKey($plain)]);

        $this->withHeaders(['X-API-KEY' => $plain, 'Accept' => 'application/json'])->getJson('/api/v1/crm/leads/1')
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $row = $this->one(['source' => 'api_key']);
        $this->assertSame(['denied', 'capability_not_entitled', 'crm', $account->id], [$row->action_type, $row->new_values['category'], $row->new_values['capability'], $row->account_id]);
        $this->assertNull($row->user_id);

        $raw = json_encode(DB::table('activity_logs')->get());
        $this->assertStringNotContainsString($plain, $raw, 'the API key is never audited');
    }

    // ================================================================== security

    public function test_no_secret_token_key_or_password_reaches_an_audit_row(): void
    {
        $account = $this->account();
        $this->setCapability($account, 'external_api', true);
        $user = $this->user($account);
        $api = ['id' => 'x', 'type' => 'api', 'data' => [
            'method' => 'GET', 'url' => 'https://api.test/v1',
            'headers' => [['key' => 'Authorization', 'value' => 'Bearer '.self::SECRET]],
            'query' => [['key' => 'password', 'value' => self::SECRET]],
        ]];

        // Allowed (draft), refused (publish) and denied (entitlement) decisions over a graph holding secrets.
        $this->actingAs($user)->postJson(self::FLOWS, $this->payload([$api], ['publish' => false]))->assertCreated();
        $this->actingAs($user)->postJson(self::FLOWS, $this->payload([$api]))->assertStatus(422);
        $this->setCapability($account, 'external_api', false);
        $this->actingAs($user)->postJson(self::FLOWS, $this->payload([$api], ['publish' => false]))->assertStatus(403);

        $audits = $this->audits();
        $this->assertGreaterThanOrEqual(3, $audits->count());
        $raw = json_encode(DB::table('activity_logs')->where('module_name', EntitlementAuditLogger::MODULE)->get());
        foreach ([self::SECRET, JourneySecrets::PREFIX, 'Bearer', 'headers', 'graph_data', 'EAAG_'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "'{$forbidden}' in an entitlement audit row");
        }

        // Only allow-listed keys are ever stored.
        $allowed = ['decision', 'action', 'category', 'reason', 'source', 'resource_type', 'resource_id', 'node_type', 'node_id', 'module', 'capability', 'capabilities', 'provider', 'providers', 'permission', 'actor_account_id', 'target_account_id', 'account_id', 'session_id', 'flow_version_id', 'inbound_event_id', 'error_code', 'http_status'];
        foreach ($audits as $row) {
            $this->assertSame([], array_diff(array_keys($row->new_values), $allowed));
            $this->assertNull($row->old_values);
        }
    }

    public function test_the_logger_drops_anything_outside_its_contract(): void
    {
        $account = $this->account();
        app(EntitlementAuditLogger::class)->record($account, false, [
            'action' => 'test', 'category' => 'capability_not_entitled',
            'token' => self::SECRET, 'password' => self::SECRET, 'graph' => ['nodes' => []], 'account_id' => 999999,
            'reason' => str_repeat('x', 500), 'capabilities' => ['crm', ['nested' => self::SECRET]],
        ]);

        $row = $this->audits()->last();
        $this->assertSame($account->id, $row->account_id);
        $this->assertSame($account->id, $row->new_values['account_id'], 'a caller cannot redirect the tenant');
        $this->assertArrayNotHasKey('token', $row->new_values);
        $this->assertArrayNotHasKey('graph', $row->new_values);
        $this->assertSame(['crm'], $row->new_values['capabilities']);
        $this->assertSame(191, mb_strlen($row->new_values['reason']));
        $this->assertStringNotContainsString(self::SECRET, json_encode($row->new_values));
    }

    public function test_a_forged_account_id_cannot_create_an_audit_row_in_another_tenant(): void
    {
        $mine = $this->account();
        $theirs = $this->account();
        $user = $this->user($mine);
        $this->setCapability($mine, 'whatsapp_send', false);

        // A tenant (non-agent) user's ?account_id= / body account_id are ignored.
        $this->actingAs($user)->postJson(self::FLOWS."?account_id={$theirs->id}", $this->payload([$this->text()]) + ['account_id' => $theirs->id, 'tenant_id' => $theirs->id])
            ->assertStatus(403);

        $this->assertSame(0, ActivityLog::where('account_id', $theirs->id)->count());
        $this->assertSame($mine->id, $this->one(['node_type' => 'text'])->account_id);
    }

    public function test_only_the_super_admin_can_read_authorization_audit_records(): void
    {
        $account = $this->account();
        $this->setCapability($account, 'whatsapp_send', false);
        $user = $this->user($account);
        $this->actingAs($user)->postJson(self::FLOWS, $this->payload([$this->text()]))->assertStatus(403);

        $agentAccount = $this->account('growth', ['account_type' => 'agent']);
        $account->forceFill(['agent_id' => $agentAccount->id])->save();

        $this->actingAs($user)->getJson('/api/admin/activity-logs')->assertForbidden();
        $this->actingAs($this->user($agentAccount))->getJson("/api/admin/activity-logs?account_id={$account->id}")->assertForbidden();

        $page = $this->actingAs($this->superAdmin())->getJson('/api/admin/activity-logs?action_type=denied&module_name='.urlencode(EntitlementAuditLogger::MODULE))->assertOk();
        $this->assertSame(['denied'], collect($page->json('data'))->pluck('action_type')->unique()->values()->all());
        $this->assertContains($account->id, collect($page->json('data'))->pluck('account.id')->all());
        // The two refused reads were themselves audited (missing_permission), on the readers' own accounts.
        $this->assertSame([$account->id, $agentAccount->id], $this->audits(['category' => 'missing_permission'])->pluck('account_id')->all());
    }

    public function test_an_audit_write_failure_never_changes_the_decision(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        // The audit store is unavailable: every write throws (no DDL, so the
        // test transaction stays intact on MariaDB too).
        ActivityLog::creating(function () {
            throw new \RuntimeException('audit store offline');
        });

        try {
            // Allowed stays allowed…
            $this->actingAs($user)->postJson(self::FLOWS, $this->payload([$this->text()]))->assertCreated();
            // …denied stays denied, with the same body.
            $this->setCapability($account, 'whatsapp_send', false);
            $this->actingAs($user)->postJson(self::FLOWS, $this->payload([$this->text()]))->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        } finally {
            ActivityLog::flushEventListeners();
        }

        $this->assertSame(0, ActivityLog::where('module_name', EntitlementAuditLogger::MODULE)->count());
    }

    // ================================================================== regression

    public function test_one_logical_decision_is_audited_once_per_run(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B'), $this->text('c', 'C'), ['id' => 'i', 'type' => 'image', 'data' => ['mediaUrl' => 'https://cdn.test/a.png']]]);

        $this->inbound($account);

        $rows = $this->audits(['action' => 'journey.node.execute']);
        $this->assertSame(['text', 'image'], $rows->map(fn ($r) => $r->new_values['node_type'])->all(), 'three text nodes = one decision');
    }

    public function test_legacy_and_control_flow_nodes_check_nothing_and_record_nothing(): void
    {
        $account = $this->account();
        $this->flow($account, [['id' => 'm', 'type' => 'message', 'data' => ['text' => 'Legacy']], ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 1, 'unit' => 'minutes']]]);

        $this->inbound($account);

        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, WhatsAppFlowSession::where('account_id', $account->id)->value('status'));
        $this->assertCount(0, $this->audits(['action' => 'journey.node.execute']));
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
