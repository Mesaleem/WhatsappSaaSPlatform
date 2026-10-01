<?php
// Phase 8 Task 11 — registered agents + side-effecting tools under REAL concurrency (MariaDB).
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). Refuses to run
// unless the database is named exactly `wa_throwaway_test`. Not PHPUnit
// (RefreshDatabase's transaction is invisible to other processes).
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing php tests/Probes/agent_tool_concurrency_probe.php
// Needs pcntl. Exit 0 = every check passed.
//
// (a) 8 OS processes invoke the SAME tool invocation key (crm.lead.capture_current)
//     at once → exactly one CRM lead, one invocation row, 8 identical results;
// (b) 8 OS processes resume the SAME due session parked at a registered-agent
//     node (model → capture tool → model) through the production path →
//     one tool effect, each model step called and charged once.
use App\Models\{Account, AiAgentToolInvocation, AiOperation, CreditReservation, CrmLead, Invoice, User, WhatsAppFlow, WhatsAppFlowSession, WhatsAppSession};
use App\Services\Ai\Agents\AgentRegistry;
use App\Services\Ai\Agents\Tools\{CrmCaptureCurrentLeadTool, ToolContext, ToolExecutor};
use App\Services\Ai\AiManager;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\{AiRequest, AiResponse};
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Credits\CreditService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\{Artisan, DB, Http};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_test') { fwrite(STDERR, "refusing: not the throwaway DB\n"); exit(2); }

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);
Http::fake(fn () => Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200));

$calls = tempnam(sys_get_temp_dir(), 'agentcalls');
config(['ai.default' => 'fake', 'ai.enabled' => ['fake'], 'ai.providers.fake' => ['model' => 'fake'],
    'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum',
    'ai.agents.tools' => ['journey.variable.get', 'crm.lead.find_current', 'crm.lead.capture_current', 'crm.lead.update_status']]);
app(AiManager::class)->extend('fake', fn () => new class($calls) implements AiProvider {
    public function __construct(private readonly string $calls) {}
    public function name(): string { return 'fake'; }
    public function generateText(AiRequest $r): AiResponse { throw new LogicException('unused'); }
    public function generateStructured(AiRequest $r): AiResponse
    {
        file_put_contents($this->calls, $r->operation."\n", FILE_APPEND | LOCK_EX);
        usleep(random_int(50000, 200000));
        $data = str_contains($r->prompt, 'Tool result') ? ['action' => 'final', 'reply' => 'Saved.'] : ['action' => 'tool', 'tool' => 'crm.lead.capture_current', 'arguments' => []];

        return new AiResponse('fake', 'fake', (string) json_encode($data), $data, 1500, 500); // 2 credits
    }
});
CrmLead::creating(fn () => usleep(random_int(20000, 120000))); // widen the effect window

function tenant(): Account {
    $a = Account::factory()->create(); $p = PlanCatalog::find('growth');
    $i = Invoice::create(['account_id' => $a->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth', 'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0, 'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'gateway_order_id' => 'o_'.uniqid(), 'status' => 'pending']);
    app(InvoiceCreditService::class)->markPaidAndCreditQuota($i->id, 'pay_'.uniqid());
    WhatsAppSession::create(['account_id' => $a->id, 'status' => 'connected']);
    app(CreditService::class)->grant($a->fresh(), 100, 'probe:grant:'.uniqid());
    return $a->fresh();
}
function admin(Account $a): User { $u = User::factory()->create(['account_id' => $a->id, 'is_active' => true]); $u->assignRole('admin'); return $u->fresh(); }
function race(int $n, callable $work, string $out): void {
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            try { $r = $work($i); $r = is_array($r) ? json_encode($r) : (string) $r; } catch (Throwable $e) { $r = 'EXC:'.class_basename($e).':'.$e->getMessage(); }
            file_put_contents($out, $r."\n", FILE_APPEND | LOCK_EX);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $p) pcntl_waitpid($p, $st);
    DB::reconnect();
}
function lines(string $file): array { return array_values(array_filter(explode("\n", trim((string) file_get_contents($file))))); }
$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results) { $results[] = $ok; echo ($ok ? 'PASS ' : 'FAIL ')."{$name}".($detail !== '' ? " — {$detail}" : '')."\n"; };

foreach ([1, 2, 3] as $round) {
    // (a) the same invocation key from 8 processes
    $a = tenant();
    $agent = app(AgentRegistry::class)->create($a, admin($a), ['name' => "A{$round}", 'instructions' => 'Capture leads.', 'tools' => ['crm.lead.capture_current']]);
    $phone = '9198'.random_int(10000000, 99999999);
    $out = tempnam(sys_get_temp_dir(), 'agentout');
    race(8, function () use ($a, $agent, $phone, $round) {
        $session = (new WhatsAppFlowSession)->forceFill(['account_id' => $a->id, 'phone_number' => $phone, 'context_data' => []]);
        $ctx = new ToolContext(Account::find($a->id), $agent->fresh(), $agent->fresh()->currentVersion(), null, $session);

        return app(ToolExecutor::class)->invoke($ctx, CrmCaptureCurrentLeadTool::NAME, [], "probe:r{$round}:t0");
    }, $out);
    $outcomes = lines($out);
    $check("[{$round}] one effect for one invocation key", CrmLead::where('account_id', $a->id)->count() === 1 && AiAgentToolInvocation::where('account_id', $a->id)->count() === 1, json_encode(array_count_values($outcomes)));
    $check("[{$round}] every racer got the same recorded result", count($outcomes) === 8 && count(array_unique($outcomes)) === 1 && str_contains($outcomes[0], '"ok":true'), $outcomes[0] ?? '');
    @unlink($out);

    // (b) overlapping journeys workers on one due agent session
    file_put_contents($calls, '');
    $b = tenant();
    $agentB = app(AgentRegistry::class)->create($b, admin($b), ['name' => "B{$round}", 'instructions' => 'Capture leads.', 'tools' => ['crm.lead.capture_current']]);
    WhatsAppFlow::create(['account_id' => $b->id, 'name' => 'Agent', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true, 'graph_data' => [
        'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'a', 'type' => 'agent', 'data' => ['registeredAgentId' => (string) $agentB->id, 'outputVariable' => 'reply']]],
        'edges' => [['id' => 'e', 'source' => 't', 'target' => 'a']],
    ]]);
    $customer = '9197'.random_int(10000000, 99999999);
    app(ChatbotEngineService::class)->handleInboundMessage($b->id, $customer, 'go', null, 'qr', 'wamsg:'.uniqid());
    $session = WhatsAppFlowSession::where('account_id', $b->id)->latest('id')->firstOrFail();
    WhatsAppFlowSession::whereKey($session->id)->update(['wait_until' => now()->subSecond()]);
    $out = tempnam(sys_get_temp_dir(), 'agentout');
    race(8, fn () => app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id), $out);
    $session->refresh();
    $modelCalls = count(lines($calls));
    $check("[{$round}] the session completes once", $session->status === 'completed' && ($session->context_data['reply'] ?? null) === 'Saved.', json_encode(array_count_values(lines($out))));
    $check("[{$round}] one tool effect, each model step called and charged once", CrmLead::where('account_id', $b->id)->count() === 1 && AiAgentToolInvocation::where('account_id', $b->id)->count() === 1
        && $modelCalls === 2 && AiOperation::where('account_id', $b->id)->where('status', 'settled')->count() === 2
        && app(CreditService::class)->balance($b->fresh())['balance'] === 96 && CreditReservation::where('account_id', $b->id)->where('status', 'reserved')->count() === 0, "model calls={$modelCalls}");
    @unlink($out);
}

@unlink($calls);
$passed = count(array_filter($results));
echo "\n{$passed}/".count($results)." checks passed\n".DB::select('select version() v')[0]->v."\n";
exit($passed === count($results) ? 0 : 1);
