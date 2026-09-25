<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\CreditAccount;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\CreditConsumptionService;
use App\Services\Credits\CreditEntitlementService;
use App\Services\Credits\CreditException;
use App\Services\Credits\CreditService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 8 Task 3 — credit spending: direct consumption, reservation, partial
 * consumption, settle, release, expiry cleanup, idempotency, tenant binding,
 * product gate, failure categories and ledger invariants.
 *
 * Everything runs through the real CreditConsumptionService / CreditService.
 * Multi-process races are in tests/Probes/credit_concurrency_probe.php
 * (run by CreditSystemFoundationTest on MariaDB/MySQL).
 */
class CreditConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ================================================================== fixtures

    private function spending(): CreditConsumptionService
    {
        return app(CreditConsumptionService::class);
    }

    private function svc(): CreditService
    {
        return app(CreditService::class);
    }

    private function funded(int $credits = 100): Account
    {
        $account = Account::factory()->create();
        if ($credits > 0) {
            $this->svc()->grant($account, $credits, 't:seed');
        }

        return $account;
    }

    /** An AI-entitled subscriber, set up through the real plan purchase path. */
    private function aiSubscriber(int $planCredits = 300): Account
    {
        Plan::where('slug', 'growth')->firstOrFail()->update(['included_credits' => $planCredits]);
        $account = Account::factory()->create();
        $plan = Plan::where('slug', 'growth')->firstOrFail();
        $invoice = new Invoice([
            'account_id' => $account->id, 'invoice_number' => 'INV-T3-'.uniqid(), 'plan_key' => 'growth', 'plan_label' => $plan->label,
            'amount' => $plan->price, 'tax_amount' => 0, 'total_amount' => $plan->price, 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        $invoice->capturePlanTerms($plan)->save();
        $this->assertTrue(app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid()));

        return $account->fresh();
    }

    private function gate(): CreditEntitlementService
    {
        return app(CreditEntitlementService::class);
    }

    private function state(Account $account): array
    {
        return $this->svc()->balance($account);
    }

    private function refused(string $reason, callable $call): void
    {
        $before = [CreditLedgerEntry::count(), CreditReservation::count(), CreditAccount::query()->sum('balance'), CreditAccount::query()->sum('reserved')];

        try {
            $call();
            $this->fail("expected {$reason}");
        } catch (CreditException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }

        $this->assertSame($before, [CreditLedgerEntry::count(), CreditReservation::count(), CreditAccount::query()->sum('balance'), CreditAccount::query()->sum('reserved')], 'a refused operation wrote something');
    }

    /**
     * The financial invariants, and the ledger replayed row by row equals the
     * stored state; every open reservation's remaining hold sums to reserved.
     */
    private function assertInvariants(Account $account): void
    {
        $credit = CreditAccount::where('account_id', $account->id)->firstOrFail();
        $balance = 0;
        $reserved = 0;

        foreach (CreditLedgerEntry::where('account_id', $account->id)->orderBy('id')->get() as $entry) {
            $balance += $entry->balance_delta;
            $reserved += $entry->reserved_delta;
            $this->assertSame([$balance, $reserved], [$entry->balance_after, $entry->reserved_after], "entry #{$entry->id}");
            $this->assertGreaterThanOrEqual(0, $reserved);
            $this->assertLessThanOrEqual($balance, $reserved);
        }

        $this->assertSame([$balance, $reserved], [(int) $credit->balance, (int) $credit->reserved], 'ledger reconstruction ≠ credit_accounts');
        $this->assertGreaterThanOrEqual(0, $balance);
        $this->assertSame($balance - $reserved, $this->state($account)['available']);

        $held = CreditReservation::where('account_id', $account->id)->where('status', 'reserved')->get()->sum(fn ($r) => $r->remaining());
        $this->assertSame($reserved, $held, 'open reservations ≠ reserved');

        // Every reservation's rows explain it: +amount, then −(consumed + released) when terminal.
        foreach (CreditReservation::where('account_id', $account->id)->get() as $r) {
            $rows = CreditLedgerEntry::where('reservation_id', $r->id)->get();
            $this->assertSame((int) $r->amount, (int) $rows->where('type', 'reservation')->sum('amount'), "reservation #{$r->id} hold");
            $this->assertSame((int) ($r->consumed_amount ?? 0), (int) $rows->where('type', 'consumption')->sum('amount'), "reservation #{$r->id} consumed");
            $this->assertSame($r->remaining(), (int) $rows->sum('reserved_delta'), "reservation #{$r->id} remaining");
        }
    }

    // ================================================================== the contract (spec examples)

    public function test_direct_consumption_takes_available_credits_immediately(): void
    {
        $account = $this->funded(100);

        $result = $this->spending()->spend($account, 25, 'ai:request:r1', ['reference_type' => 'ai_request', 'reference_id' => 'r1']);

        $this->assertFalse($result->replayed);
        $this->assertSame(['balance' => 75, 'reserved' => 0, 'available' => 75], $this->state($account));
        $this->assertSame([CreditLedgerEntry::TYPE_CONSUMPTION, 25, -25, -25, 75, 0], [$result->entry->type, $result->entry->amount, $result->entry->balance_delta, $result->entry->reserved_delta, $result->entry->balance_after, $result->entry->reserved_after]);
        $this->assertSame('ai:request:r1', $result->entry->idempotency_key);
        $this->assertSame(['consumed', 25], [$result->reservation->status, $result->reservation->consumed_amount]);
        // Direct consumption keeps the Task 1 rule: a consumption always references a reservation.
        $this->assertSame([['reservation', 0, 25], ['consumption', -25, -25]], CreditLedgerEntry::where('reservation_id', $result->reservation->id)->orderBy('id')->get()->map(fn ($e) => [$e->type, $e->balance_delta, $e->reserved_delta])->all());
        // …and it is refundable like any consumption.
        $this->svc()->refund($account, 5, 't:refund', $result->entry->id);
        $this->assertSame(80, $this->state($account)['balance']);
        $this->assertInvariants($account);
    }

    public function test_reserve_consume_and_release_follow_the_documented_arithmetic(): void
    {
        $account = $this->funded(100);

        $reservation = $this->spending()->reserve($account, 40, 'ai:reservation:q1')->reservation;
        $this->assertSame(['balance' => 100, 'reserved' => 40, 'available' => 60], $this->state($account));

        $consumed = $this->spending()->consume($account, $reservation->id, 25, 'ai:consume:q1:1');
        $this->assertSame(['balance' => 75, 'reserved' => 15, 'available' => 60], $this->state($account));
        $this->assertSame([$reservation->id, 'reserved', 25, 15], [$consumed->entry->reservation_id, $reservation->fresh()->status, $reservation->fresh()->consumed_amount, $reservation->fresh()->remaining()]);

        $released = $this->spending()->release($account, $reservation->id, 15);
        $this->assertSame(['balance' => 75, 'reserved' => 0, 'available' => 75], $this->state($account));
        $this->assertSame([CreditLedgerEntry::TYPE_RESERVATION_RELEASE, 15], [$released->entry->type, $released->entry->amount]);
        $this->assertSame(['released', 25], [$reservation->fresh()->status, $reservation->fresh()->consumed_amount]);
        $this->assertInvariants($account);
    }

    public function test_multiple_partial_consumptions_then_release_of_the_rest(): void
    {
        $account = $this->funded(200);
        $r = $this->spending()->reserve($account, 100, 'job:reservation:1')->reservation;

        $this->spending()->consume($account, $r->id, 30, 'job:consume:1:a');
        $this->spending()->consume($account, $r->id, 20, 'job:consume:1:b');
        $this->spending()->release($account, $r->id);

        $this->assertSame(['balance' => 150, 'reserved' => 0, 'available' => 150], $this->state($account));
        $this->assertSame(['released', 50], [$r->fresh()->status, $r->fresh()->consumed_amount]);
        $this->assertSame(['reservation', 'consumption', 'consumption', 'reservation_release'], CreditLedgerEntry::where('reservation_id', $r->id)->orderBy('id')->pluck('type')->all());
        $this->assertInvariants($account);
    }

    public function test_consuming_the_whole_hold_makes_the_reservation_terminal(): void
    {
        $account = $this->funded(100);
        $r = $this->spending()->reserve($account, 30, 'job:reservation:full')->reservation;

        $this->spending()->consume($account, $r->id, 10, 'job:c:1');
        $this->spending()->consume($account, $r->id, 20, 'job:c:2');

        $this->assertSame(['consumed', 30, 0], [$r->fresh()->status, $r->fresh()->consumed_amount, $r->fresh()->remaining()]);
        $this->assertNotNull($r->fresh()->consumed_at);
        $this->refused(CreditException::RESERVATION_ALREADY_TERMINAL, fn () => $this->spending()->consume($account, $r->id, 1, 'job:c:3'));
        $this->refused(CreditException::RESERVATION_ALREADY_TERMINAL, fn () => $this->spending()->release($account, $r->id));
        $this->assertSame(['balance' => 70, 'reserved' => 0, 'available' => 70], $this->state($account));
        $this->assertInvariants($account);
    }

    public function test_settle_after_partial_consumption_consumes_and_releases_only_what_remains(): void
    {
        $account = $this->funded(100);
        $r = $this->spending()->reserve($account, 50, 'job:reservation:s')->reservation;
        $this->spending()->consume($account, $r->id, 10, 'job:c:s1');

        $this->refused(CreditException::INVALID_AMOUNT, fn () => $this->spending()->settle($account, $r->id, 41));

        $settled = $this->spending()->settle($account, $r->id, 20);
        $this->assertSame(['consumed', 30], [$r->fresh()->status, $r->fresh()->consumed_amount]);
        $this->assertSame(['balance' => 70, 'reserved' => 0, 'available' => 70], $this->state($account));
        $this->assertSame(20, $settled->entry->amount);
        $this->assertSame(20, (int) CreditLedgerEntry::where('reservation_id', $r->id)->where('type', 'reservation_release')->sum('amount'));
        $this->assertTrue($this->spending()->settle($account, $r->id, 20)->replayed);
        $this->assertInvariants($account);
    }

    // ================================================================== reservation lifecycle

    public function test_terminal_reservations_are_never_mutated_again(): void
    {
        $account = $this->funded(100);

        $released = $this->spending()->reserve($account, 10, 'r:released')->reservation;
        $this->spending()->release($account, $released->id);
        $this->refused(CreditException::RESERVATION_ALREADY_TERMINAL, fn () => $this->spending()->consume($account, $released->id, 1, 'c:after-release'));
        $this->refused(CreditException::RESERVATION_ALREADY_TERMINAL, fn () => $this->spending()->settle($account, $released->id));

        $consumed = $this->spending()->reserve($account, 10, 'r:consumed')->reservation;
        $this->spending()->settle($account, $consumed->id);
        $this->refused(CreditException::RESERVATION_ALREADY_TERMINAL, fn () => $this->spending()->release($account, $consumed->id));
        $this->refused(CreditException::RESERVATION_ALREADY_TERMINAL, fn () => $this->spending()->consume($account, $consumed->id, 1, 'c:after-settle'));

        $spent = $this->spending()->spend($account, 5, 's:direct')->reservation;
        $this->refused(CreditException::RESERVATION_ALREADY_TERMINAL, fn () => $this->spending()->release($account, $spent->id));

        $snapshot = CreditReservation::orderBy('id')->get()->map->only(['status', 'consumed_amount', 'consumed_at', 'released_at'])->all();
        $this->spending()->release($account, $released->id); // replay
        $this->spending()->settle($account, $consumed->id);    // replay
        $this->assertEquals($snapshot, CreditReservation::orderBy('id')->get()->map->only(['status', 'consumed_amount', 'consumed_at', 'released_at'])->all());
        $this->assertInvariants($account);
    }

    public function test_invalid_amounts_are_refused_with_invalid_amount_and_write_nothing(): void
    {
        $account = $this->funded(100);
        $r = $this->spending()->reserve($account, 20, 'r:amounts')->reservation;
        $this->spending()->consume($account, $r->id, 5, 'c:amounts:1');

        foreach ([
            fn () => $this->spending()->spend($account, 0, 's:zero'),
            fn () => $this->spending()->spend($account, -3, 's:neg'),
            fn () => $this->spending()->reserve($account, 0, 'r:zero'),
            fn () => $this->spending()->consume($account, $r->id, 0, 'c:zero'),
            fn () => $this->spending()->consume($account, $r->id, -1, 'c:neg'),
            fn () => $this->spending()->consume($account, $r->id, 21, 'c:over-amount'),
            fn () => $this->spending()->consume($account, $r->id, 16, 'c:over-remaining'),
            fn () => $this->spending()->release($account, $r->id, 14),
            fn () => $this->spending()->release($account, $r->id, 0),
            fn () => $this->spending()->release($account, $r->id, 21),
            fn () => $this->spending()->settle($account, $r->id, 0),
            fn () => $this->spending()->settle($account, $r->id, 16),
        ] as $call) {
            $this->refused(CreditException::INVALID_AMOUNT, $call);
        }

        $this->assertSame(['reserved', 5], [$r->fresh()->status, $r->fresh()->consumed_amount]);
        $this->spending()->release($account, $r->id, 15);
        $this->assertInvariants($account);
    }

    public function test_insufficient_credits_refuse_reserve_and_spend_and_reserved_credits_are_not_spendable(): void
    {
        $account = $this->funded(100);
        $this->spending()->reserve($account, 70, 'r:big');

        $this->refused(CreditException::INSUFFICIENT_CREDITS, fn () => $this->spending()->spend($account, 31, 's:too-much'));
        $this->refused(CreditException::INSUFFICIENT_CREDITS, fn () => $this->spending()->reserve($account, 31, 'r:too-much'));
        $this->refused(CreditException::INSUFFICIENT_CREDITS, fn () => $this->spending()->spend($this->funded(0), 1, 's:empty'));

        $this->spending()->spend($account, 30, 's:exact');
        $this->assertSame(['balance' => 70, 'reserved' => 70, 'available' => 0], $this->state($account));
        $this->assertInvariants($account);
    }

    // ================================================================== idempotency

    public function test_same_key_same_parameters_replays_the_original_and_writes_nothing(): void
    {
        $account = $this->funded(100);

        $spend = $this->spending()->spend($account, 10, 'ai:request:dup');
        $this->assertTrue(($again = $this->spending()->spend($account, 10, 'ai:request:dup'))->replayed);
        $this->assertSame([$spend->entry->id, $spend->reservation->id], [$again->entry->id, $again->reservation->id]);

        $res = $this->spending()->reserve($account, 30, 'ai:reservation:dup');
        $this->assertTrue($this->spending()->reserve($account, 30, 'ai:reservation:dup')->replayed);

        $c = $this->spending()->consume($account, $res->reservation->id, 10, 'ai:consume:dup');
        $replay = $this->spending()->consume($account, $res->reservation->id, 10, 'ai:consume:dup');
        $this->assertSame([true, $c->entry->id], [$replay->replayed, $replay->entry->id]);

        $rel = $this->spending()->release($account, $res->reservation->id);
        $this->assertSame($rel->entry->id, $this->spending()->release($account, $res->reservation->id, 20)->entry->id);

        $this->assertSame(['balance' => 80, 'reserved' => 0, 'available' => 80], $this->state($account));
        $this->assertSame(1 + 2 + 1 + 1 + 1, CreditLedgerEntry::where('account_id', $account->id)->count());
        $this->assertSame(2, CreditReservation::where('account_id', $account->id)->count());
        $this->assertInvariants($account);
    }

    public function test_same_key_different_parameters_is_an_idempotency_conflict(): void
    {
        $account = $this->funded(100);
        $this->spending()->spend($account, 10, 'k:spend');
        $r = $this->spending()->reserve($account, 30, 'k:reserve')->reservation;
        $other = $this->spending()->reserve($account, 30, 'k:reserve2')->reservation;
        $this->spending()->consume($account, $r->id, 5, 'k:consume');
        $this->spending()->release($account, $other->id);

        foreach ([
            fn () => $this->spending()->spend($account, 11, 'k:spend'),
            fn () => $this->spending()->reserve($account, 10, 'k:spend'),        // key of a spend used to reserve
            fn () => $this->spending()->spend($account, 30, 'k:reserve'),        // key of a reserve used to spend
            fn () => $this->svc()->grant($account, 10, 'k:spend'),
            fn () => $this->spending()->consume($account, $r->id, 6, 'k:consume'),
            fn () => $this->spending()->consume($account, $r->id, 5, 'k:spend'),
            fn () => $this->spending()->release($account, $other->id, 29),      // replay expecting another amount
        ] as $call) {
            $this->refused(CreditException::IDEMPOTENCY_CONFLICT, $call);
        }

        // A capture key reused on ANOTHER reservation is a different request.
        $third = $this->spending()->reserve($account, 10, 'k:reserve3')->reservation;
        $this->refused(CreditException::IDEMPOTENCY_CONFLICT, fn () => $this->spending()->consume($account, $third->id, 5, 'k:consume'));
        $this->assertInvariants($account);
    }

    public function test_the_service_derived_key_prefixes_cannot_be_used_by_callers(): void
    {
        $account = $this->funded(100);
        $r = $this->spending()->reserve($account, 10, 'r:prefix')->reservation;

        foreach (["consume:{$r->id}", "release:{$r->id}"] as $key) {
            $this->refused(CreditException::INVALID_OPERATION, fn () => $this->spending()->spend($account, 1, $key));
            $this->refused(CreditException::INVALID_OPERATION, fn () => $this->svc()->grant($account, 1, $key));
            $this->refused(CreditException::INVALID_OPERATION, fn () => $this->spending()->consume($account, $r->id, 1, $key));
        }

        // …so the reservation's own settle / release still work normally.
        $this->spending()->settle($account, $r->id, 4);
        $this->assertSame(['consumed', 4], [$r->fresh()->status, $r->fresh()->consumed_amount]);
    }

    public function test_a_failed_operation_inside_a_caller_transaction_leaves_nothing_behind(): void
    {
        $account = $this->funded(100);

        try {
            DB::transaction(function () use ($account) {
                $this->spending()->spend($account, 40, 'nested:spend');
                $r = $this->spending()->reserve($account, 20, 'nested:reserve')->reservation;
                $this->spending()->consume($account, $r->id, 5, 'nested:consume');
                throw new RuntimeException('the caller failed after spending');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(['balance' => 100, 'reserved' => 0, 'available' => 100], $this->state($account));
        $this->assertSame(0, CreditReservation::count());

        // The same keys are free again: nothing was applied.
        $this->assertFalse($this->spending()->spend($account, 40, 'nested:spend')->replayed);
        $this->assertInvariants($account);
    }

    // ================================================================== tenant isolation

    public function test_a_reservation_can_only_be_used_by_the_account_that_owns_it(): void
    {
        $a = $this->funded(100);
        $b = $this->funded(100);
        $r = $this->spending()->reserve($a, 30, 'r:tenant')->reservation;

        $this->refused(CreditException::TENANT_MISMATCH, fn () => $this->spending()->consume($b, $r->id, 5, 'b:consume'));
        $this->refused(CreditException::TENANT_MISMATCH, fn () => $this->spending()->settle($b, $r->id));
        $this->refused(CreditException::TENANT_MISMATCH, fn () => $this->spending()->release($b, $r->id));
        $this->refused(CreditException::RESERVATION_NOT_FOUND, fn () => $this->spending()->consume($a, 999999, 5, 'a:missing'));
        $this->refused(CreditException::RESERVATION_NOT_FOUND, fn () => $this->spending()->release($a, 999999));

        $this->assertSame(['balance' => 100, 'reserved' => 0, 'available' => 100], $this->state($b));
        $this->assertSame(['balance' => 100, 'reserved' => 30, 'available' => 70], $this->state($a));
    }

    public function test_agents_and_clients_never_pool_or_spend_each_others_credits(): void
    {
        $agent = Account::factory()->agent()->create();
        $client = Account::factory()->client($agent)->create();
        $this->svc()->grant($agent, 500, 't:agent');

        // The Agent's credits do not fund its client…
        $this->refused(CreditException::INSUFFICIENT_CREDITS, fn () => $this->spending()->spend($client, 1, 'client:spend'));

        // …and neither side can touch the other's reservation.
        $this->svc()->grant($client, 50, 't:client');
        $clientHold = $this->spending()->reserve($client, 20, 'client:hold')->reservation;
        $agentHold = $this->spending()->reserve($agent, 20, 'agent:hold')->reservation;
        $this->refused(CreditException::TENANT_MISMATCH, fn () => $this->spending()->consume($agent, $clientHold->id, 5, 'agent:takes-client'));
        $this->refused(CreditException::TENANT_MISMATCH, fn () => $this->spending()->consume($client, $agentHold->id, 5, 'client:takes-agent'));

        // A spend on the client is charged to the client only.
        $this->spending()->spend($client, 10, 'client:spend-ok');
        $this->assertSame(['balance' => 40, 'reserved' => 20, 'available' => 20], $this->state($client));
        $this->assertSame(['balance' => 500, 'reserved' => 20, 'available' => 480], $this->state($agent));
        $this->assertSame(0, CreditLedgerEntry::where('account_id', $agent->id)->where('type', 'consumption')->count());
    }

    public function test_the_ledger_rows_carry_the_paying_account_and_a_full_audit_trail(): void
    {
        $account = $this->funded(100);
        $actor = User::factory()->create(['account_id' => $account->id]);

        $r = $this->spending()->reserve($account, 30, 'ai:reservation:audit', [
            'reference_type' => 'ai_request', 'reference_id' => 'req-77', 'reason' => 'AI draft', 'actor_user_id' => $actor->id, 'source' => 'ai', 'metadata' => ['request_id' => 'req-77', 'module' => 'ai'],
        ])->reservation;
        $entry = $this->spending()->consume($account, $r->id, 12, 'ai:consume:audit', ['reference_type' => 'ai_request', 'reference_id' => 'req-77', 'actor_user_id' => $actor->id, 'source' => 'ai'])->entry->fresh();

        $this->assertSame(
            [$account->id, 'consumption', 12, -12, -12, 88, 18, 'ai:consume:audit', $r->id, 'ai_request', 'req-77', 'ai', $actor->id],
            [$entry->account_id, $entry->type, $entry->amount, $entry->balance_delta, $entry->reserved_delta, $entry->balance_after, $entry->reserved_after, $entry->idempotency_key, $entry->reservation_id, $entry->reference_type, $entry->reference_id, $entry->source, $entry->actor_user_id],
        );
        $this->assertNotNull($entry->created_at);
        $this->assertSame($account->id, (int) CreditAccount::find($entry->credit_account_id)->account_id);
    }

    public function test_metadata_is_an_identifier_trail_never_content(): void
    {
        $account = $this->funded(100);

        foreach ([['prompt' => str_repeat('x', 192)], ['messages' => [['role' => 'user', 'content' => 'hi']]], array_fill_keys(range('a', 'u'), 'v'), [0 => 'list']] as $metadata) {
            $this->refused(CreditException::INVALID_OPERATION, fn () => $this->spending()->spend($account, 1, 'meta:'.md5(serialize($metadata)), ['metadata' => $metadata]));
        }

        $this->spending()->spend($account, 1, 'meta:ok', ['metadata' => ['request_id' => 'abc', 'tokens' => 120, 'model' => null]]);
        $this->assertSame(99, $this->state($account)['balance']);
    }

    // ================================================================== product gate (AI entitlement)

    public function test_the_ai_gate_needs_the_capability_an_active_account_a_current_subscription_and_credits(): void
    {
        $account = $this->aiSubscriber(300);

        $ok = $this->spending()->reserve($account, 50, 'ai:reservation:ok', [], $this->gate());
        $this->assertFalse($ok->replayed);
        $this->spending()->spend($account, 10, 'ai:request:ok', [], $this->gate());

        // Capability, no credits (all held/spent): financial refusal.
        $this->spending()->spend($account, 240, 'ai:request:rest', [], $this->gate());
        $this->refused(CreditException::INSUFFICIENT_CREDITS, fn () => $this->spending()->spend($account, 1, 'ai:request:broke', [], $this->gate()));
        $this->spending()->release($account, $ok->reservation->id);

        // Credits, capability revoked: product refusal.
        AccountEntitlement::where('account_id', $account->id)->where('capability_id', Capability::where('slug', 'ai')->value('id'))->update(['revoked_at' => now()]);
        $this->assertFalse(app(AccessControlService::class)->canTenant($account->fresh(), 'ai'));
        $this->refused(CreditException::ENTITLEMENT_BLOCKED, fn () => $this->spending()->spend($account, 1, 'ai:request:no-cap', [], $this->gate()));
        $this->refused(CreditException::ENTITLEMENT_BLOCKED, fn () => $this->spending()->reserve($account, 1, 'ai:reservation:no-cap', [], $this->gate()));
        // The generic ledger itself is not AI-specific: without a gate the credits still move.
        $this->spending()->spend($account, 1, 'other-module:spend', []);
        $this->assertSame(49, $this->state($account)['available']);
    }

    public function test_the_ai_gate_blocks_suspended_accounts_and_expired_subscriptions_but_never_settlement(): void
    {
        $account = $this->aiSubscriber(300);
        $hold = $this->spending()->reserve($account, 40, 'ai:reservation:before', [], $this->gate())->reservation;

        $account->update(['status' => 'suspended']);
        $this->refused(CreditException::ACCOUNT_BLOCKED, fn () => $this->spending()->reserve($account, 1, 'ai:reservation:susp', [], $this->gate()));
        $this->refused(CreditException::ACCOUNT_BLOCKED, fn () => $this->spending()->spend($account, 1, 'ai:request:susp', [], $this->gate()));
        // A retry of the request applied before the suspension replays its original result.
        $this->assertTrue($this->spending()->reserve($account, 40, 'ai:reservation:before', [], $this->gate())->replayed);
        // Work already done is still accounted; unused holds are still returned.
        $this->spending()->consume($account, $hold->id, 15, 'ai:consume:after-suspension');
        $this->spending()->release($account, $hold->id);
        $this->assertSame(['balance' => 285, 'reserved' => 0, 'available' => 285], $this->state($account));

        $account->update(['status' => 'active']);
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);
        $this->refused(CreditException::ACCOUNT_BLOCKED, fn () => $this->spending()->spend($account, 1, 'ai:request:expired', [], $this->gate()));

        // An exhausted MESSAGE quota is not an AI block.
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->addDays(10), 'used_messages' => 999999]);
        $this->spending()->spend($account, 5, 'ai:request:quota-exhausted', [], $this->gate());
        $this->assertInvariants($account);
    }

    // ================================================================== reservation expiry

    public function test_an_expired_reservation_can_only_be_released(): void
    {
        $account = $this->funded(100);
        $this->refused(CreditException::INVALID_OPERATION, fn () => $this->spending()->reserve($account, 10, 'r:past', ['expires_at' => now()->subSecond()]));

        $r = $this->spending()->reserve($account, 30, 'r:ttl', ['expires_at' => now()->addMinutes(10)])->reservation;
        $this->assertTrue($r->fresh()->expires_at->isFuture());
        $this->spending()->consume($account, $r->id, 5, 'c:ttl:1');

        $this->travel(11)->minutes();
        $this->refused(CreditException::INVALID_RESERVATION, fn () => $this->spending()->consume($account, $r->id, 5, 'c:ttl:2'));
        $this->refused(CreditException::INVALID_RESERVATION, fn () => $this->spending()->settle($account, $r->id));

        $this->spending()->release($account, $r->id);
        $this->assertSame(['released', 5], [$r->fresh()->status, $r->fresh()->consumed_amount]);
        $this->assertSame(['balance' => 95, 'reserved' => 0, 'available' => 95], $this->state($account));
        $this->assertInvariants($account);
    }

    public function test_the_cleanup_releases_only_due_reservations_and_is_idempotent(): void
    {
        $account = $this->funded(100);
        $due = $this->spending()->reserve($account, 10, 'r:due', ['expires_at' => now()->addMinute()])->reservation;
        $partly = $this->spending()->reserve($account, 20, 'r:partly', ['expires_at' => now()->addMinute()])->reservation;
        $this->spending()->consume($account, $partly->id, 8, 'c:partly');
        $later = $this->spending()->reserve($account, 10, 'r:later', ['expires_at' => now()->addHour()])->reservation;
        $never = $this->spending()->reserve($account, 10, 'r:never')->reservation;
        $settled = $this->spending()->reserve($account, 10, 'r:settled', ['expires_at' => now()->addMinute()])->reservation;
        $this->spending()->settle($account, $settled->id);

        $this->travel(5)->minutes();

        $entries = CreditLedgerEntry::count();
        $this->assertSame(0, Artisan::call('credits:release-expired-reservations', ['--dry-run' => true]));
        $this->assertSame($entries, CreditLedgerEntry::count(), 'the dry run wrote something');

        $this->assertSame(0, Artisan::call('credits:release-expired-reservations'));
        $this->assertSame(['released', 'released', 'reserved', 'reserved', 'consumed'], [$due->fresh()->status, $partly->fresh()->status, $later->fresh()->status, $never->fresh()->status, $settled->fresh()->status]);
        $this->assertSame(['reason' => 'Reservation expired', 'source' => 'system', 'amount' => 12], CreditLedgerEntry::where('idempotency_key', "release:{$partly->id}")->first()->only(['reason', 'source', 'amount']));
        $this->assertSame(['balance' => 82, 'reserved' => 20, 'available' => 62], $this->state($account));

        $entries = CreditLedgerEntry::count();
        $this->assertSame(['due' => 0, 'released' => 0, 'skipped' => 0], $this->spending()->releaseExpired());
        $this->assertSame($entries, CreditLedgerEntry::count(), 'a second run wrote something');
        $this->assertInvariants($account);
    }

    // ================================================================== invariants over mixed operations

    public function test_ledger_reconstruction_matches_the_stored_state_across_every_operation_type(): void
    {
        $account = Account::factory()->create();
        $this->svc()->grant($account, 500, 'mix:grant');
        $this->svc()->grant($account, 100, 'mix:purchase', [], CreditLedgerEntry::TYPE_PURCHASE);
        $this->svc()->adjust($account, -50, 'mix:adjust-down');
        $this->svc()->adjust($account, 20, 'mix:adjust-up');
        $this->assertInvariants($account);

        $a = $this->spending()->reserve($account, 100, 'mix:r:a')->reservation;
        $b = $this->spending()->reserve($account, 60, 'mix:r:b')->reservation;
        $c = $this->spending()->reserve($account, 40, 'mix:r:c', ['expires_at' => now()->addMinute()])->reservation;
        $this->spending()->consume($account, $a->id, 30, 'mix:c:a1');
        $this->spending()->consume($account, $a->id, 20, 'mix:c:a2');
        $this->assertInvariants($account);
        $this->spending()->settle($account, $b->id);
        $spent = $this->spending()->spend($account, 25, 'mix:spend')->entry;
        $this->svc()->refund($account, 10, 'mix:refund', $spent->id);
        $full = $this->svc()->consume($b->fresh()); // replay of the settle
        $this->svc()->refund($account, 60, 'mix:refund-b', $full->entry->id);
        $this->spending()->release($account, $a->id, 50);
        $this->assertInvariants($account);

        $this->travel(2)->minutes();
        $this->spending()->releaseExpired();
        $this->assertSame('released', $c->fresh()->status);

        // 500 + 100 − 50 + 20 − 50 (a) − 60 (b) − 25 (spend) + 10 + 60 (refunds)
        $this->assertSame(['balance' => 505, 'reserved' => 0, 'available' => 505], $this->state($account));
        $this->assertInvariants($account);
    }

    public function test_the_database_refuses_a_reservation_consuming_more_than_it_held_on_mysql_family(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('CHECK constraints are declared on MySQL/MariaDB only.');
        }

        $account = $this->funded(100);
        $r = $this->spending()->reserve($account, 10, 'r:check')->reservation;

        $this->expectException(QueryException::class);
        DB::table('credit_reservations')->where('id', $r->id)->update(['consumed_amount' => 11]);
    }

    // ================================================================== API surface

    public function test_there_is_no_spending_endpoint_and_tenants_cannot_move_credits(): void
    {
        $spendingRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'credit') && array_diff($route->methods(), ['GET', 'HEAD']))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        // Only the Task 1 Super Admin operations mutate credits over HTTP.
        $this->assertEqualsCanonicalizing([
            'api/admin/accounts/{id}/credits/grant', 'api/admin/accounts/{id}/credits/adjust', 'api/admin/accounts/{id}/credits/refund',
        ], $spendingRoutes);

        $account = $this->funded(100);
        $client = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $client->assignRole('admin');

        foreach (['/api/billing/credits/consume', '/api/billing/credits/reserve', '/api/billing/credits/spend', "/api/admin/accounts/{$account->id}/credits/grant"] as $path) {
            $this->assertContains($this->actingAs($client)->postJson($path, ['amount' => 5, 'idempotency_key' => 'x'.md5($path)])->status(), [403, 404, 405], $path);
        }

        $this->assertSame(['balance' => 100, 'reserved' => 0, 'available' => 100], $this->state($account));
    }
}
