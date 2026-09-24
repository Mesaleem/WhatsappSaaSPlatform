<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\JourneyExecutionEvent as Ev;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Access\JourneyNodeAuthorizer;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\WhatsApp\JourneySendGate;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 Task 8 — one quota / retry / entitlement contract for every
 * Journey send (JourneySendGate + JourneyNodeAuthorizer::runtimeDenialFor):
 *
 *   quota exhausted / plan expired      quota_failure        retried (Task 1/5 machinery)
 *   suspended account / no subscription entitlement_blocked  failed at once
 *   whatsapp_send absent (palette node) entitlement_blocked  failed at once
 *   journey_automation / chatbot module session blocked, restored later (Task 1.6)
 *   malformed configuration             invalid_configuration failed, never retried
 *   provider failure                    provider_failure     retried
 */
class JourneyQuotaConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const URL = 'https://cdn.example.com/f.bin';

    /** @var list<string> message texts the fake provider refuses */
    private array $fail = [];

    /** exhaust this account's quota right after the provider accepts this text */
    private ?string $exhaustAfter = null;

    /** @var (\Closure(): void)|null runs inside the next provider call */
    private ?\Closure $during = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            if ($this->during) {
                $during = $this->during;
                $this->during = null;
                $during();
            }

            $message = $request->data()['message'] ?? null;

            if (in_array($message, $this->fail, true)) {
                return Http::response(['success' => false, 'error' => 'Engine offline'], 200);
            }

            return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });

        // After the provider accepted $exhaustAfter and its quota was
        // consumed, the plan is used up: the NEXT send finds it exhausted.
        MessageDispatchLog::created(function (MessageDispatchLog $log) {
            if ($this->exhaustAfter !== null && $log->status === 'sent' && $log->message_preview === $this->exhaustAfter) {
                $this->exhaustAfter = null;
                $this->exhaust(Account::findOrFail($log->account_id));
            }
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function account(): Account
    {
        $account = Account::factory()->create();
        $plan = PlanCatalog::find('growth');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth',
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account->fresh();
    }

    private function exhaust(Account $account): void
    {
        Subscription::where('account_id', $account->id)->update(['used_messages' => DB::raw('total_allocated_messages')]);
    }

    private function topUp(Account $account): void
    {
        Subscription::where('account_id', $account->id)->update(['used_messages' => 0]);
    }

    private function setCapability(Account $account, string $slug, bool $revoked): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => $revoked ? now() : null],
        );
    }

    private function msg(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'message', 'data' => ['text' => $text]];
    }

    private function text(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'text', 'data' => ['text' => $text]];
    }

    private function flow(Account $account, array $nodes, string $keyword = 'go'): WhatsAppFlow
    {
        $edges = [['id' => 'e_t', 'source' => 't', 'target' => $nodes[0]['id']]];
        for ($i = 1; $i < count($nodes); $i++) {
            $edges[] = ['id' => "e{$i}", 'source' => $nodes[$i - 1]['id'], 'target' => $nodes[$i]['id']];
        }

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Task 8', 'trigger_type' => 'keyword', 'trigger_value' => $keyword,
            'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => $edges],
        ]);
    }

    private function inbound(Account $account, string $text, ?string $messageId = null, string $phone = self::PHONE): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, 'qr', $messageId);
    }

    private function flowSession(Account $account, string $phone = self::PHONE): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->where('phone_number', $phone)->latest('id')->firstOrFail();
    }

    private function resume(WhatsAppFlowSession $session): string
    {
        return app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id);
    }

    /** @return list<string> */
    private function sent(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')
            ->where('recipient_phone', $phone)->orderBy('id')->pluck('message_preview')->all();
    }

    /** @return list<string> "event:node:category" for failures/retries */
    private function failures(WhatsAppFlowSession $session): array
    {
        return Ev::where('session_id', $session->id)->whereIn('event', [Ev::NODE_FAILED, Ev::NODE_RETRY_SCHEDULED, Ev::SESSION_FAILED])
            ->orderBy('id')->get()->map(fn (Ev $e) => "{$e->event}:{$e->node_id}:{$e->error_category}:{$e->attempt}")->all();
    }

    // ==================================================================
    // 1. Every outbound action: quota exhausted → retried → advances once
    // ==================================================================

    /** [node under test, what its successful send logs as preview] */
    public static function outboundActions(): array
    {
        return [
            'message' => [['id' => 'x', 'type' => 'message', 'data' => ['text' => 'X']], 'X'],
            'text' => [['id' => 'x', 'type' => 'text', 'data' => ['text' => 'X']], 'X'],
            'image' => [['id' => 'x', 'type' => 'image', 'data' => ['mediaUrl' => self::URL]], '[image]'],
            'video' => [['id' => 'x', 'type' => 'video', 'data' => ['mediaUrl' => self::URL]], '[video]'],
            'document' => [['id' => 'x', 'type' => 'document', 'data' => ['mediaUrl' => self::URL, 'filename' => 'f.pdf']], '[document]'],
            'audio' => [['id' => 'x', 'type' => 'audio', 'data' => ['mediaUrl' => self::URL]], '[audio]'],
            'question' => [['id' => 'x', 'type' => 'question', 'data' => ['prompt_text' => 'X?', 'variable_name' => 'v', 'input_type' => 'text']], 'X?'],
        ];
    }

    #[DataProvider('outboundActions')]
    public function test_exhausted_quota_is_retried_and_the_retry_advances_exactly_once(array $node, string $preview): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('pre', 'PRE'), $node, $this->text('post', 'POST')]);
        $this->exhaustAfter = 'PRE';

        $this->inbound($account, 'go');

        // Parked at the failed node; nothing downstream ran.
        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'x'], [$s->status, $s->current_node_id]);
        $this->assertStringContainsString('Quota exhausted', $s->last_error);
        $this->assertSame(['PRE'], $this->sent($account));
        $this->assertSame(['node_failed:x:quota_failure:0', 'node_retry_scheduled:x:quota_failure:0'], $this->failures($s));

        // Still exhausted at the first retry: retried again, still nothing downstream.
        $this->travel(2)->minutes();
        $this->assertSame('retrying', $this->resume($s));
        $this->assertSame(['PRE'], $this->sent($account));

        // Topped up: the retry sends X once and moves on.
        $this->topUp($account);
        $this->travel(5)->minutes();
        $status = $this->resume($s);

        if ($node['type'] === 'question') {
            $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $status, 'the prompt went out; now awaiting the reply');
            $this->assertSame(['PRE', $preview], $this->sent($account));
            $this->inbound($account, 'answer');
        } else {
            $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $status);
        }

        $this->assertSame(['PRE', $preview, 'POST'], $this->sent($account), 'PRE is never repeated; X exactly once');
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->fresh()->status);
        $this->assertSame(1, Ev::where('session_id', $s->id)->where('event', Ev::NODE_SUCCEEDED)->where('node_id', 'x')->count());
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->fresh()->status);
    }

    #[DataProvider('outboundActions')]
    public function test_quota_still_exhausted_after_the_retry_budget_fails_durably_as_quota_failure(array $node, string $preview): void
    {
        $account = $this->account();
        $this->flow($account, [$node, $this->text('post', 'POST')]);
        $this->exhaust($account);

        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        for ($i = 0; $i < WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
            $this->travel(1)->hours();
            $status = $this->resume($s);
        }

        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $status);
        $s->refresh();
        $this->assertSame(['x', null], [$s->current_node_id, $s->wait_until]);
        $this->assertStringContainsString('Quota exhausted', $s->last_error);
        $this->assertSame('session_failed:x:quota_failure:3', last($this->failures($s)));
        $this->assertSame([], $this->sent($account));

        // Durable: a later top-up does not revive it.
        $this->topUp($account);
        $this->travel(1)->hours();
        $this->assertSame('skipped', $this->resume($s));
        $this->assertSame([], $this->sent($account));
    }

    public function test_a_run_that_uses_up_the_last_message_stops_at_the_next_send_instead_of_overshooting(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B'), $this->text('c', 'C')]);
        Subscription::where('account_id', $account->id)->update(['used_messages' => DB::raw('total_allocated_messages - 1')]);

        $this->inbound($account, 'go');

        $sub = Subscription::where('account_id', $account->id)->first();
        $this->assertSame(['A'], $this->sent($account));
        $this->assertSame($sub->total_allocated_messages, $sub->used_messages, 'never beyond the cap');
        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'b'], [$s->status, $s->current_node_id]);
        $this->assertSame('node_failed:b:quota_failure:0', $this->failures($s)[0]);
    }

    public function test_a_save_lead_completion_message_blocked_by_quota_is_retried_without_duplicating_the_lead(): void
    {
        $account = $this->account();
        $this->flow($account, [['id' => 's', 'type' => 'save_lead', 'data' => ['completion_message' => 'SAVED']]]);
        $this->exhaust($account);

        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 's'], [$s->status, $s->current_node_id]);
        $this->assertSame('node_failed:s:quota_failure:0', $this->failures($s)[0]);

        $this->topUp($account);
        $this->travel(2)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['SAVED'], $this->sent($account));
        $this->assertSame(1, Lead::count());
    }

    public function test_an_expired_plan_is_a_retryable_quota_failure_and_renewal_resumes_it(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('x', 'X')]);
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);

        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertStringContainsString('expired', $s->last_error);
        $this->assertSame('node_retry_scheduled:x:quota_failure:0', $this->failures($s)[1]);

        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->addMonth()]);
        $this->travel(2)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['X'], $this->sent($account));
    }

    // ==================================================================
    // 2. Entitlement is not quota
    // ==================================================================

    public function test_whatsapp_send_revoked_fails_a_palette_node_at_once_and_is_not_labelled_quota(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('m', 'LEGACY'), $this->text('x', 'X'), $this->text('post', 'POST')]);
        $this->setCapability($account, 'whatsapp_send', true);
        $this->exhaustAfter = 'LEGACY';

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_FAILED, 'x'], [$s->status, $s->current_node_id]);
        $this->assertStringContainsString("requires the 'whatsapp_send' capability", $s->last_error, 'capability, even though the quota is also exhausted');
        $this->assertSame(['node_failed:x:entitlement_blocked:0', 'session_failed:x:entitlement_blocked:0'], $this->failures($s));
        $this->assertSame(['LEGACY'], $this->sent($account), 'the legacy message node stays grandfathered');
        $this->travel(1)->hours();
        $this->assertSame('skipped', $this->resume($s), 'never retried');
    }

    public static function permanentSendingStates(): array
    {
        return [
            'suspended account' => [fn (Account $a) => $a->forceFill(['status' => 'suspended'])->save(), 'not active'],
            'no subscription' => [fn (Account $a) => Subscription::where('account_id', $a->id)->delete(), 'no subscription'],
        ];
    }

    #[DataProvider('permanentSendingStates')]
    public function test_a_state_no_retry_can_fix_fails_at_once_for_legacy_and_palette_nodes_alike(\Closure $break, string $reason): void
    {
        foreach (['message' => $this->msg('x', 'X'), 'text' => $this->text('x', 'X')] as $label => $node) {
            $account = $this->account();
            $this->flow($account, [$node, $this->text('post', 'POST')]);
            $break($account);

            $this->inbound($account, 'go');

            $s = $this->flowSession($account);
            $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $s->status, $label);
            // (A palette node on an account with no subscription is refused one
            // step earlier, by its provider check — same category.)
            if ($label === 'message') {
                $this->assertStringContainsString($reason, $s->last_error, $label);
            }
            $this->assertSame(['node_failed:x:entitlement_blocked:0', 'session_failed:x:entitlement_blocked:0'], $this->failures($s), $label);
            $this->assertSame([], $this->sent($account), $label);
        }
    }

    public function test_journey_entitlement_lost_while_waiting_for_a_quota_retry_blocks_and_restoration_resumes_at_the_node(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('pre', 'PRE'), ['id' => 'x', 'type' => 'image', 'data' => ['mediaUrl' => self::URL]], $this->text('post', 'POST')]);
        $this->exhaustAfter = 'PRE';
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $this->setCapability($account, 'journey_automation', true);
        $this->topUp($account);
        $this->travel(2)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->resume($s));
        $this->assertSame(['PRE'], $this->sent($account));
        $this->assertSame('x', $s->fresh()->current_node_id);

        $this->setCapability($account, 'journey_automation', false);
        $this->assertSame(1, app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions());
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s->fresh()));
        $this->assertSame(['PRE', '[image]', 'POST'], $this->sent($account));
    }

    public function test_chatbot_module_disabled_while_waiting_for_a_quota_retry_blocks_and_restoration_resumes(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('pre', 'PRE'), $this->text('x', 'X')]);
        $this->exhaustAfter = 'PRE';
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        $this->topUp($account);
        $this->travel(2)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->resume($s));
        $this->assertSame('entitlement_blocked', Ev::where('session_id', $s->id)->where('event', Ev::SESSION_BLOCKED)->value('error_category'));

        $account->forceFill(['allowed_modules' => null])->save();
        app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s->fresh()));
        $this->assertSame(['PRE', 'X'], $this->sent($account));
    }

    public function test_the_runtime_node_check_ignores_sending_state_but_the_save_time_check_keeps_it(): void
    {
        $account = $this->account();
        $this->exhaust($account);
        $authorizer = app(JourneyNodeAuthorizer::class);

        $this->assertNull($authorizer->runtimeDenialFor($account->fresh(), 'text'), 'quota is the send gate\'s business');
        $this->assertSame('This account has no active subscription.', $authorizer->denialFor($account->fresh(), 'text'), 'save time unchanged');

        $this->setCapability($account, 'whatsapp_send', true);
        $this->assertStringContainsString('whatsapp_send', (string) $authorizer->runtimeDenialFor($account->fresh(), 'text'));
        $this->assertNull($authorizer->runtimeDenialFor($account->fresh(), 'message'), 'legacy nodes are grandfathered');
    }

    public function test_the_send_gate_classifies_each_sending_state(): void
    {
        $gate = app(JourneySendGate::class);
        $account = $this->account();
        $sub = fn () => Subscription::where('account_id', $account->id)->first();

        $this->assertNull($gate->refusal($account, $sub()));
        $this->exhaust($account);
        $this->assertSame(['quota_failure', true], array_values(array_slice($gate->refusal($account, $sub()), 0, 2)));
        $this->topUp($account);
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);
        $this->assertSame(['quota_failure', true], array_values(array_slice($gate->refusal($account, $sub()), 0, 2)));
        $this->assertSame(['entitlement_blocked', false], array_values(array_slice($gate->refusal($account, null), 0, 2)));
        $account->forceFill(['status' => 'suspended'])->save();
        $this->assertSame(['entitlement_blocked', false], array_values(array_slice($gate->refusal($account, $sub()), 0, 2)));
    }

    // ==================================================================
    // 3. Other failure kinds keep their contract
    // ==================================================================

    public function test_a_malformed_node_fails_as_configuration_even_when_quota_is_exhausted(): void
    {
        $account = $this->account();
        $this->flow($account, [['id' => 'x', 'type' => 'image', 'data' => ['mediaUrl' => 'javascript:alert(1)']]]);
        $this->exhaust($account);

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $s->status);
        $this->assertSame(['node_failed:x:invalid_configuration:0', 'session_failed:x:invalid_configuration:0'], $this->failures($s));
    }

    public function test_a_provider_failure_keeps_its_own_category_and_retry(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('x', 'X')]);
        $this->fail = ['X'];

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame(['node_failed:x:provider_failure:0', 'node_retry_scheduled:x:provider_failure:0'], $this->failures($s));
    }

    // ==================================================================
    // 4. Retry mechanics under quota failure
    // ==================================================================

    public function test_cancelling_a_session_waiting_for_a_quota_retry_stops_all_further_retries(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('x', 'X')]);
        $this->exhaust($account);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        // The cancel endpoint delegates to cancelSession(); called directly here
        // because subscription.guard (pre-existing) refuses the Journey API
        // while the plan is exhausted.
        $this->assertTrue(app(WhatsAppJourneyEngine::class)->cancelSession($s));
        $this->topUp($account);
        $this->travel(1)->hours();

        $this->assertSame('skipped', $this->resume($s));
        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $s->fresh()->status);
        $this->assertSame([], $this->sent($account));
    }

    public function test_a_worker_that_died_during_a_quota_retry_does_not_repeat_completed_actions(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('pre', 'PRE'), $this->text('x', 'X'), $this->text('post', 'POST')]);
        $this->exhaustAfter = 'PRE';
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->topUp($account);
        $this->travel(2)->minutes();

        // A worker claims the retry and dies before doing anything.
        $this->assertSame(1, WhatsAppFlowSession::query()->whereKey($s->id)->where('status', 'waiting')
            ->update(['wait_until' => now()->addSeconds(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS), 'attempts' => 1]));
        $this->assertSame('skipped', $this->resume($s));

        $this->travel(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS + 1)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['PRE', 'X', 'POST'], $this->sent($account));
    }

    public function test_a_duplicate_scheduled_resume_of_a_quota_retry_runs_it_once(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('x', 'X'), $this->text('post', 'POST')]);
        $this->exhaust($account);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->topUp($account);
        $this->travel(2)->minutes();

        $second = null;
        $this->during = function () use ($s, &$second) {
            $second = $this->resume($s);
        };

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame('skipped', $second);
        $this->assertSame(['X', 'POST'], $this->sent($account));
    }

    public function test_a_redelivered_inbound_does_not_restart_or_resend_a_session_waiting_for_quota(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('x', 'X')]);
        $this->exhaust($account);

        $this->inbound($account, 'go', 'wamid.q');
        $this->topUp($account);
        $this->inbound($account, 'go', 'wamid.q');

        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $account->id)->count());
        $this->assertSame([], $this->sent($account));
        $this->assertSame(1, Ev::where('event', Ev::INBOUND_DEDUPLICATED)->count());
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $this->flowSession($account)->status);
    }

    public function test_a_quota_retry_sends_the_pinned_version_not_a_later_edit(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('x', 'X_V1')]);
        $this->exhaust($account);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $graph = $flow->graph_data;
        $graph['nodes'][1]['data']['text'] = 'X_V2';
        $flow->update(['graph_data' => $graph]);
        $this->topUp($account);
        $this->travel(2)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['X_V1'], $this->sent($account));
    }

    public function test_one_tenants_exhausted_quota_never_affects_another_tenant(): void
    {
        $a = $this->account();
        $b = $this->account();
        $this->flow($a, [$this->text('x', 'A_X')]);
        $this->flow($b, [$this->text('x', 'B_X')]);
        $this->exhaust($a);

        $this->inbound($a, 'go');
        $this->inbound($b, 'go');

        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $this->flowSession($a)->status);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($b)->status);
        $this->assertSame(['B_X'], $this->sent($b));
        $this->assertSame([], $this->sent($a));
        $this->assertSame(0, Ev::where('account_id', $b->id)->where('error_category', 'quota_failure')->count());
    }

    public function test_the_session_endpoint_distinguishes_a_quota_retry_from_a_permanent_entitlement_failure(): void
    {
        $account = $this->account();
        $admin = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $admin->assignRole('admin');
        $quotaFlow = $this->flow($account, [$this->text('x', 'X')], 'quota');
        $capFlow = $this->flow($account, [$this->text('x', 'X')], 'cap');

        $this->exhaust($account);
        $this->inbound($account, 'quota', null, '919800000001');
        $this->topUp($account);
        $this->setCapability($account, 'whatsapp_send', true);
        $this->inbound($account, 'cap', null, '919800000002');

        $quota = $this->actingAs($admin)->getJson("/api/whatsapp/flows/{$quotaFlow->id}/sessions/{$this->flowSession($account, '919800000001')->id}")->json('data.session');
        $cap = $this->actingAs($admin)->getJson("/api/whatsapp/flows/{$capFlow->id}/sessions/{$this->flowSession($account, '919800000002')->id}")->json('data.session');

        $this->assertSame(['waiting', 'quota_failure', true], [$quota['status'], $quota['error_category'], $quota['retry']['retrying']]);
        $this->assertSame(['failed', 'entitlement_blocked', false], [$cap['status'], $cap['error_category'], $cap['retry']['retrying']]);
    }
}
