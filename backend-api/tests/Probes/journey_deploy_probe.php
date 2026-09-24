<?php
// Phase 7 Task 9 — Journey fresh install / upgrade / rollback / re-apply probe against REAL MariaDB.
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). It refuses to run
// unless the connected database is named exactly `wa_throwaway_test`. Never
// point it at wa_saas_platform or any shared database. Not part of PHPUnit
// (not under tests/Unit or tests/Feature).
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing php tests/Probes/journey_deploy_probe.php
// Exit code 0 = every check passed.
// Phase 7 Task 9 — deployment/upgrade probe on the THROWAWAY MariaDB DB only.
use App\Models\{Account, Invoice, WhatsAppFlow, WhatsAppFlowSession, WhatsAppSession, MessageDispatchLog, JourneyExecutionEvent as Ev};
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\{DB, Http, Artisan, Schema};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_test') { fwrite(STDERR, "refusing\n"); exit(2); }
Http::fake(fn () => Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200));
$out = []; $ok = true;
$step = function (string $name, bool $pass, string $d = '') use (&$out, &$ok) { $ok = $ok && $pass; $out[] = ($pass ? 'PASS ' : 'FAIL ').$name.($pass ? '' : "  $d"); };

$PHASE7 = ['2026_09_23_130000_create_super_admin_platform_crm_account', '2026_09_24_100000_add_temporal_state_to_whatsapp_flow_sessions_table', '2026_09_24_110000_support_journey_automation_on_qr_provider', '2026_09_24_120000_create_group_dispatch_recipients_table', '2026_09_24_130000_create_whatsapp_flow_versions_table', '2026_09_24_130001_backfill_whatsapp_flow_versions', '2026_09_24_140000_create_inbound_message_events_and_conversation_locks_tables', '2026_09_24_150000_add_claim_columns_to_message_dispatch_logs_table', '2026_09_24_160000_add_plan_terms_snapshot_to_invoices_table', '2026_09_24_170000_create_journey_execution_events_table'];

// 1. fresh install
Artisan::call('migrate:fresh', ['--force' => true]);
$step('fresh: all 120 migrations ran', DB::table('migrations')->count() === 120);

// 2. back to the pre-Phase-7 schema (the real DB has 110 of 120 per PROJECT_STATE)
Artisan::call('migrate:rollback', ['--step' => 10, '--force' => true]);
$step('rollback of the 10 pending migrations', ! Schema::hasTable('journey_execution_events') && ! Schema::hasTable('whatsapp_flow_versions') && ! Schema::hasColumn('whatsapp_flow_sessions', 'wait_until'));
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);

// legacy data, written the way pre-Phase-7 code wrote it
$acc = Account::factory()->create(); $p = PlanCatalog::find('growth');
DB::table('subscriptions')->insert(['account_id' => $acc->id, 'engine_type' => 'qr', 'billing_model' => 'flat_quota', 'total_allocated_messages' => 100, 'used_messages' => 0, 'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now()] + (Schema::hasColumn('subscriptions', 'plan_key') ? ['plan_key' => 'growth'] : []));
DB::table('whatsapp_sessions')->insert(['account_id' => $acc->id, 'status' => 'connected', 'created_at' => now(), 'updated_at' => now()]);
$graph = ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Name?', 'variable_name' => 'name', 'input_type' => 'text']], ['id' => 's', 'type' => 'save_lead', 'data' => ['name_variable' => 'name', 'completion_message' => 'Thanks']]], 'edges' => [['id' => 'a', 'source' => 't', 'target' => 'q'], ['id' => 'b', 'source' => 'q', 'target' => 's']]];
$flowId = DB::table('whatsapp_flows')->insertGetId(['account_id' => $acc->id, 'name' => 'Legacy', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'graph_data' => json_encode($graph), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
$open = DB::table('whatsapp_flow_sessions')->insertGetId(['account_id' => $acc->id, 'flow_id' => $flowId, 'phone_number' => '919800000001', 'current_node_id' => 'q', 'context_data' => '{}', 'status' => 'active', 'last_interaction_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
$done = DB::table('whatsapp_flow_sessions')->insertGetId(['account_id' => $acc->id, 'flow_id' => $flowId, 'phone_number' => '919800000002', 'current_node_id' => 's', 'context_data' => '{"name":"Old"}', 'status' => 'completed', 'last_interaction_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
$leadId = DB::table('leads')->insertGetId(['account_id' => $acc->id, 'provider' => 'whatsapp_journey', 'provider_lead_id' => "journey:{$flowId}:{$done}", 'lead_name' => 'Old', 'lead_phone' => '919800000002', 'raw_field_data' => json_encode(['flow_id' => $flowId, 'session_id' => $done]), 'created_at' => now(), 'updated_at' => now()]);

// 3. upgrade in the documented order
Artisan::call('migrate', ['--force' => true]);
$ran = DB::table('migrations')->whereIn('migration', $PHASE7)->orderBy('id')->pluck('migration')->all();
$step('upgrade ran the 10 pending migrations in filename order', $ran === $PHASE7, json_encode($ran));
$flow = WhatsAppFlow::find($flowId);
$s = WhatsAppFlowSession::find($open);
$step('backfill: legacy journey has v1 published, open + ended sessions pinned', $flow->published_version_id !== null && $s->flow_version_id === $flow->published_version_id && WhatsAppFlowSession::find($done)->flow_version_id === $flow->published_version_id);
$step('temporal columns default safely (attempts 0, no timer, no error)', $s->attempts === 0 && $s->wait_until === null && $s->last_error === null);
$step('CRM lead reference to an old session is untouched', DB::table('leads')->where('id', $leadId)->value('provider_lead_id') === "journey:{$flowId}:{$done}");
// the old in-flight conversation continues on new code
DB::table('account_entitlements')->count(); // ensure table
$step('entitlements:backfill-plan runs (twice, idempotent)', Artisan::call('entitlements:backfill-plan') === 0 && Artisan::call('entitlements:backfill-plan') === 0);
app(InvoiceCreditService::class); // warm
$ent = App\Models\Capability::whereIn('slug', ['journey_automation', 'whatsapp_send'])->pluck('id');
foreach ($ent as $cid) { App\Models\AccountEntitlement::updateOrCreate(['account_id' => $acc->id, 'capability_id' => $cid], ['source' => 'manual_grant', 'revoked_at' => null]); }
app(ChatbotEngineService::class)->handleInboundMessage($acc->id, '919800000001', 'Ada', null, 'qr', 'wamid.post-upgrade');
$s->refresh();
$step('a pre-upgrade session answered after the upgrade completes on its pinned version', $s->status === 'completed' && MessageDispatchLog::where('account_id', $acc->id)->where('status', 'sent')->pluck('message_preview')->all() === ['Thanks'], json_encode([$s->status, $s->last_error]));
$step('and it has execution history with the inbound id', Ev::where('session_id', $s->id)->whereNotNull('inbound_event_id')->exists());
$step('the ended pre-upgrade session was not revived', WhatsAppFlowSession::find($done)->status === 'completed');

// 4. idempotency: rollback all 10 again and re-apply; data survives what it should
Artisan::call('migrate:rollback', ['--step' => 10, '--force' => true]);
$step('second rollback clean', ! Schema::hasTable('journey_execution_events') && DB::table('whatsapp_flows')->where('id', $flowId)->exists());
Artisan::call('migrate', ['--force' => true]);
$step('re-apply clean; journey re-backfilled with exactly one version', DB::table('whatsapp_flow_versions')->where('flow_id', $flowId)->count() === 1 && WhatsAppFlow::find($flowId)->published_version_id !== null);
Artisan::call('migrate', ['--force' => true]);
$step('migrate again is a no-op', DB::table('migrations')->count() === 120);

echo implode("\n", $out)."\nMariaDB ".DB::selectOne('select version() v')->v."\n";
exit($ok ? 0 : 1);
