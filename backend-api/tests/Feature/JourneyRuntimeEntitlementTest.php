<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 7 Task 1.6 — Journey entitlement enforced at RUNTIME.
 *
 * Both execution entry points — inbound messages (WhatsAppJourneyEngine::
 * handleInboundMessage(), reached from the Meta webhook and the QR internal
 * inbound endpoint through ChatbotEngineService) and scheduled resumes
 * (journeys:resume-due → ResumeJourneySessionJob → resumeDueSession()) —
 * refuse to run for an account that does not currently hold
 * journey_automation with the chatbot module on (JourneyRuntimeEntitlement,
 * the same predicates as the Journey API's guards).
 *
 * A refused run sends nothing, writes no lead, consumes no quota, and
 * parks its session as 'blocked' with every bit of state kept. Restoring
 * the entitlement brings it back and it continues from its checkpoint —
 * nothing already done runs again.
 *
 * Entitlement is revoked/restored through the real Super Admin endpoints.
 */
class JourneyRuntimeEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    /** Called from inside the faked WhatsApp engine when a message containing $hookOn is sent. */
    private ?\Closure $hook = null;

    private string $hookOn = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            if ($this->hook && str_contains($request->body(), $this->hookOn)) {
                ($this->hook)();
            }

            return Http::response(['success' => true, 'message_id' => 'QR_OK'], 200);
        });
    }

    // ------------------------------------------------------------------ fixtures

    /** Growth (QR): journey_automation and crm arrive from the plan. */
    private function tenant(string $planKey = 'growth'): Account
    {
        $account = Account::factory()->create();
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey,
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null, 'status' => 'pending',
            'paid_at' => null, 'gateway_raw_response' => null,
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account->fresh();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function revoke(Account $account): void
    {
        $this->actingAs($this->superAdmin())->deleteJson("/api/admin/accounts/{$account->id}/entitlements/journey_automation")->assertOk();
    }

    private function restore(Account $account): void
    {
        $this->actingAs($this->superAdmin())->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'journey_automation'])->assertOk();
    }

    /** trigger → "Before" → delay(5 min) → "First" → "Second" → save_lead */
    private function delayedFlow(Account $account): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Nurture', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    ['id' => 'before', 'type' => 'message', 'data' => ['text' => 'Before']],
                    ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 5, 'unit' => 'minutes']],
                    ['id' => 'm0', 'type' => 'message', 'data' => ['text' => 'First']],
                    ['id' => 'm1', 'type' => 'message', 'data' => ['text' => 'Second']],
                    ['id' => 'save', 'type' => 'save_lead', 'data' => []],
                ],
                'edges' => [
                    ['id' => 'a', 'source' => 't', 'target' => 'before'], ['id' => 'b', 'source' => 'before', 'target' => 'd'],
                    ['id' => 'c', 'source' => 'd', 'target' => 'm0'], ['id' => 'e', 'source' => 'm0', 'target' => 'm1'],
                    ['id' => 'f', 'source' => 'm1', 'target' => 'save'],
                ],
            ],
        ]);
    }

    /** trigger → question(name) → save_lead("Thanks") */
    private function questionFlow(Account $account): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Ask', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Your name?', 'variable_name' => 'name', 'input_type' => 'text']],
                    ['id' => 'save', 'type' => 'save_lead', 'data' => ['name_variable' => 'name', 'completion_message' => 'Thanks']],
                ],
                'edges' => [['id' => 'a', 'source' => 't', 'target' => 'q'], ['id' => 'b', 'source' => 'q', 'target' => 'save']],
            ],
        ]);
    }

    private function inbound(Account $account, string $text, string $phone = self::PHONE): bool
    {
        return app(WhatsAppJourneyEngine::class)->handleInboundMessage($account->id, $phone, $text);
    }

    private function flowSession(Account $account): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->sole();
    }

    /** @return list<string> */
    private function sent(Account $account): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->orderBy('id')->pluck('message_preview')->all();
    }

    private function used(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->value('used_messages');
    }

    // ------------------------------------------------------------------ inbound

    public function test_an_entitled_inbound_trigger_executes(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);

        $this->assertTrue($this->inbound($account, 'join'));

        $this->assertSame(['Your name?'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $this->flowSession($account)->status);
    }

    public function test_a_revoked_entitlement_blocks_an_inbound_trigger_completely(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->revoke($account);
        $usedBefore = $this->used($account);

        $this->assertFalse($this->inbound($account, 'join'), 'falls through to chatbot rules');
        // Through the real choke point as well.
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, self::PHONE, 'join');

        $this->assertSame(0, WhatsAppFlowSession::count(), 'no journey starts');
        $this->assertSame([], $this->sent($account));
        $this->assertSame($usedBefore, $this->used($account), 'no quota consumed');
    }

    public function test_the_chatbot_module_being_off_also_blocks_execution(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();

        $this->assertFalse($this->inbound($account, 'join'));
        $this->assertSame(0, WhatsAppFlowSession::count());
        $this->assertSame([], $this->sent($account));
    }

    public function test_a_reply_to_a_revoked_question_session_blocks_it_keeps_its_state_and_writes_no_lead(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->inbound($account, 'join');
        $this->revoke($account);

        $this->assertFalse($this->inbound($account, 'Meera'));

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $s->status);
        $this->assertSame('q', $s->current_node_id, 'state kept');
        $this->assertNull($s->wait_until);
        $this->assertStringContainsString('not entitled', $s->last_error);
        $this->assertSame(['Your name?'], $this->sent($account), 'nothing sent');
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, CrmLead::count());

        // Still revoked: a new trigger starts no second journey.
        $this->assertFalse($this->inbound($account, 'join'));
        $this->assertSame(1, WhatsAppFlowSession::count());
    }

    public function test_a_restored_question_session_continues_from_the_question_exactly_once(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->inbound($account, 'join');
        $this->revoke($account);
        $this->inbound($account, 'ignored while blocked');

        $this->restore($account);
        $this->assertTrue($this->inbound($account, 'Meera'), 'restored inline and answered');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->status);
        $this->assertSame(['name' => 'Meera'], $s->context_data);
        $this->assertNull($s->last_error);
        $this->assertSame(['Your name?', 'Thanks'], $this->sent($account), 'the prompt is not re-sent');
        $this->assertSame('Meera', Lead::sole()->lead_name);
        $this->assertSame(CrmLead::SOURCE_JOURNEY, CrmLead::sole()->source);
    }

    // ------------------------------------------------------------------ scheduled resume

    public function test_a_revoked_entitlement_blocks_a_scheduled_resume(): void
    {
        $account = $this->tenant();
        $this->delayedFlow($account);
        $this->inbound($account, 'join');
        $this->revoke($account);
        $this->travel(5)->minutes();
        $usedBefore = $this->used($account);

        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, app(WhatsAppJourneyEngine::class)->resumeDueSession($this->flowSession($account)->id));

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $s->status);
        $this->assertSame('d', $s->current_node_id);
        $this->assertNotNull($s->wait_until);
        $this->assertSame(0, $s->attempts, 'a block is not a failed attempt');
        $this->assertSame(['Before'], $this->sent($account));
        $this->assertSame($usedBefore, $this->used($account));
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, CrmLead::count());

        // The scheduler leaves it alone while it stays revoked.
        Artisan::call('journeys:resume-due');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->flowSession($account)->status);
    }

    public function test_a_job_queued_while_entitled_cannot_run_after_revocation(): void
    {
        $account = $this->tenant();
        $this->delayedFlow($account);
        $this->inbound($account, 'join');
        $this->travel(5)->minutes();

        Artisan::call('journeys:resume-due');
        $this->assertSame(1, DB::table('jobs')->where('queue', 'journeys')->count(), 'queued while entitled');

        $this->revoke($account);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true]);

        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->flowSession($account)->status);
        $this->assertSame(['Before'], $this->sent($account));
        $this->assertSame(0, Lead::count());
    }

    public function test_restoring_entitlement_resumes_a_blocked_delay_through_the_scheduler_without_replay(): void
    {
        $account = $this->tenant();
        $this->delayedFlow($account);
        $this->inbound($account, 'join');
        $this->revoke($account);
        $this->travel(5)->minutes();
        app(WhatsAppJourneyEngine::class)->resumeDueSession($this->flowSession($account)->id);
        $this->travel(2)->days();

        $this->restore($account);
        Artisan::call('journeys:resume-due');
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $this->flowSession($account)->status, 'restored and due');
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true]);

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->status);
        $this->assertNull($s->last_error);
        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account), '"Before" is never re-sent');
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, CrmLead::count());
    }

    public function test_a_revocation_mid_run_stops_at_the_checkpoint_and_the_restored_run_does_not_replay(): void
    {
        $account = $this->tenant();
        $this->delayedFlow($account);
        $this->inbound($account, 'join');
        $this->travel(5)->minutes();

        // Entitlement is revoked while "First" is being sent.
        $this->hookOn = 'First';
        $this->hook = function () use ($account) {
            \App\Models\AccountEntitlement::where('account_id', $account->id)
                ->where('capability_id', \App\Models\Capability::where('slug', 'journey_automation')->value('id'))
                ->update(['revoked_at' => now()]);
            $this->hook = null;
        };

        app(WhatsAppJourneyEngine::class)->resumeDueSession($this->flowSession($account)->id);

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $s->status);
        $this->assertSame('m1', $s->current_node_id, 'checkpoint: the next node, after "First"');
        $this->assertSame(['Before', 'First'], $this->sent($account));
        $this->assertSame(0, Lead::count());

        $this->restore($account);
        Artisan::call('journeys:resume-due');
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true]);

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account), '"First" is sent exactly once');
        $this->assertSame(1, Lead::count());
    }

    public function test_a_blocked_session_can_still_be_cancelled_and_then_never_resumes(): void
    {
        $account = $this->tenant();
        $this->delayedFlow($account);
        $this->inbound($account, 'join');
        $this->revoke($account);
        $this->travel(5)->minutes();
        $s = $this->flowSession($account);
        app(WhatsAppJourneyEngine::class)->resumeDueSession($s->id);

        // The tenant cannot reach the Journey API without the capability; a Super Admin can.
        $this->actingAs($this->superAdmin())
            ->postJson("/api/whatsapp/flows/{$s->flow_id}/sessions/{$s->id}/cancel?account_id={$account->id}")
            ->assertOk();

        $this->restore($account);
        Artisan::call('journeys:resume-due');
        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $this->flowSession($account)->status);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(['Before'], $this->sent($account));
    }

    // ------------------------------------------------------------------ plan-based / tenant isolation

    public function test_a_starter_account_never_runs_a_journey_until_it_is_granted(): void
    {
        $starter = $this->tenant('starter');
        // A flow saved some other way (e.g. by a Super Admin, or before the API gate existed).
        $this->questionFlow($starter);

        $this->assertFalse($this->inbound($starter, 'join'));
        $this->assertSame([], $this->sent($starter));

        $this->restore($starter); // a manual grant — the plan matrix itself is unchanged
        $this->assertTrue($this->inbound($starter, 'join'));
        $this->assertSame(['Your name?'], $this->sent($starter));
    }

    public function test_one_accounts_revocation_never_affects_another_account(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->delayedFlow($a);
        $this->delayedFlow($b);
        $this->inbound($a, 'join');
        $this->inbound($b, 'join'); // same phone number, different tenant
        $this->revoke($a);
        $this->travel(5)->minutes();

        Artisan::call('journeys:resume-due');
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true]);

        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->flowSession($a)->status);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($b)->status);
        $this->assertSame(['Before'], $this->sent($a));
        $this->assertSame(['Before', 'First', 'Second'], $this->sent($b));

        // Restoring scans only entitled accounts' blocked rows.
        $this->assertSame(0, app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions());
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->flowSession($a)->status);
    }
}
