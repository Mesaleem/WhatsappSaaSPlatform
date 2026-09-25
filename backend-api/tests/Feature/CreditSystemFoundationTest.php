<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CreditAccount;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\User;
use App\Services\Credits\CreditException;
use App\Services\Credits\CreditService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Phase 8 Task 1 — Credit System Foundation.
 *
 * Service behaviour is driven through the real CreditService; the API
 * through the real routes (auth:sanctum, tenant.isolation, permissions).
 * Real multi-process concurrency is proven by tests/Probes/
 * credit_concurrency_probe.php, which the last test runs when the suite is
 * on MariaDB/MySQL (RefreshDatabase's single transaction cannot host it).
 */
class CreditSystemFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/admin/accounts/%d/credits';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ================================================================== fixtures

    private function svc(): CreditService
    {
        return app(CreditService::class);
    }

    private function user(?Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function superAdmin(): User
    {
        return $this->user(null, 'super_admin');
    }

    /** @return array{agent: Account, agentUser: User, client: Account} */
    private function agentWithClient(): array
    {
        $agent = Account::factory()->agent()->create();
        $agentUser = $this->user($agent);
        $agentUser->assignRole('agent');

        return ['agent' => $agent, 'agentUser' => $agentUser, 'client' => Account::factory()->client($agent)->create()];
    }

    private function grantVia(User $actor, Account $target, array $body, ?string $key = 'key-grant-0001')
    {
        return $this->actingAs($actor)->postJson(sprintf(self::ADMIN, $target->id).'/grant', $body + ($key ? ['idempotency_key' => $key] : []));
    }

    /** The ledger alone reproduces the account's state, row by row. */
    private function assertLedgerExplainsBalance(Account $account): void
    {
        $balance = 0;
        $reserved = 0;

        foreach (CreditLedgerEntry::where('account_id', $account->id)->orderBy('id')->get() as $entry) {
            $balance += $entry->balance_delta;
            $reserved += $entry->reserved_delta;
            $this->assertSame([$balance, $reserved], [$entry->balance_after, $entry->reserved_after], "entry #{$entry->id}");
            $this->assertGreaterThanOrEqual(0, $balance - $reserved);
        }

        $this->assertSame(['balance' => $balance, 'reserved' => $reserved, 'available' => $balance - $reserved], $this->svc()->balance($account));
        $this->assertSame($reserved, (int) CreditReservation::where('account_id', $account->id)->where('status', 'reserved')->sum('amount'));
    }

    // ================================================================== basic

    public function test_a_credit_account_is_created_once_per_account_at_zero(): void
    {
        $account = Account::factory()->create();

        $this->assertSame(['balance' => 0, 'reserved' => 0, 'available' => 0], $this->svc()->balance($account));
        $this->assertSame(0, CreditAccount::count(), 'reading a balance creates nothing');

        $first = $this->svc()->creditAccountFor($account);
        $second = $this->svc()->creditAccountFor($account);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CreditAccount::where('account_id', $account->id)->count());
        $this->assertSame([0, 0], [$first->fresh()->balance, $first->fresh()->reserved]);

        $this->expectException(QueryException::class);
        CreditAccount::query()->insert(['account_id' => $account->id, 'balance' => 0, 'reserved' => 0]);
    }

    public function test_grant_purchase_and_adjustment_move_the_balance_and_are_ledgered(): void
    {
        $account = Account::factory()->create();

        $g = $this->svc()->grant($account, 1000, 't:grant:1', ['reason' => 'welcome', 'reference_type' => 'promo', 'reference_id' => 'WELCOME']);
        $p = $this->svc()->grant($account, 500, 't:purchase:1', [], CreditLedgerEntry::TYPE_PURCHASE);
        $up = $this->svc()->adjust($account, 25, 't:adjust:1', ['reason' => 'goodwill']);
        $down = $this->svc()->adjust($account, -125, 't:adjust:2', ['reason' => 'correction']);

        $this->assertSame(['balance' => 1400, 'reserved' => 0, 'available' => 1400], $this->svc()->balance($account));
        $this->assertSame(
            [['grant', 1000, 1000, 1000], ['purchase', 500, 500, 1500], ['adjustment', 25, 25, 1525], ['adjustment', 125, -125, 1400]],
            collect([$g, $p, $up, $down])->map(fn ($r) => [$r->entry->type, $r->entry->amount, $r->entry->balance_delta, $r->entry->balance_after])->all()
        );
        $this->assertSame(['welcome', 'promo', 'WELCOME', 'system'], [$g->entry->reason, $g->entry->reference_type, $g->entry->reference_id, $g->entry->source]);
        $this->assertFalse($g->replayed);
        $this->assertLedgerExplainsBalance($account);
    }

    public function test_a_negative_adjustment_can_never_take_the_balance_or_reserved_credits_below_zero(): void
    {
        $account = Account::factory()->create();
        $this->svc()->grant($account, 100, 't:grant');
        $this->svc()->reserve($account, 60, 't:reserve');

        foreach ([-41, -101] as $delta) {
            try {
                $this->svc()->adjust($account, $delta, "t:adjust:{$delta}");
                $this->fail('over-adjustment accepted');
            } catch (CreditException $e) {
                $this->assertSame(CreditException::INSUFFICIENT_CREDITS, $e->reason);
            }
        }

        $this->svc()->adjust($account, -40, 't:adjust:ok');
        $this->assertSame(['balance' => 60, 'reserved' => 60, 'available' => 0], $this->svc()->balance($account));
        $this->assertLedgerExplainsBalance($account);
    }

    public function test_invalid_amounts_and_keys_are_refused_and_write_nothing(): void
    {
        $account = Account::factory()->create();

        foreach ([fn () => $this->svc()->grant($account, 0, 'k'), fn () => $this->svc()->grant($account, -5, 'k'), fn () => $this->svc()->grant($account, CreditService::MAX_AMOUNT + 1, 'k'),
            fn () => $this->svc()->adjust($account, 0, 'k'), fn () => $this->svc()->grant($account, 5, ''), fn () => $this->svc()->grant($account, 5, str_repeat('k', 192)),
            fn () => $this->svc()->grant($account, 5, 'k', [], CreditLedgerEntry::TYPE_CONSUMPTION)] as $i => $call) {
            try {
                $call();
                $this->fail('accepted');
            } catch (CreditException $e) {
                // Phase 8 Task 3: amount errors are invalid_amount; key/type errors stay invalid_operation.
                $this->assertSame($i < 4 ? CreditException::INVALID_AMOUNT : CreditException::INVALID_OPERATION, $e->reason);
            }
        }

        $this->assertSame(0, CreditLedgerEntry::count());
    }

    // ================================================================== ledger

    public function test_the_ledger_is_immutable(): void
    {
        $account = Account::factory()->create();
        $entry = $this->svc()->grant($account, 10, 't:grant')->entry;

        foreach ([fn () => $entry->update(['amount' => 999]), fn () => $entry->delete(), fn () => CreditLedgerEntry::find($entry->id)->forceFill(['balance_after' => 0])->save()] as $mutation) {
            try {
                $mutation();
                $this->fail('ledger row changed');
            } catch (LogicException) {
            }
        }

        $reservation = $this->svc()->reserve($account, 5, 't:res')->reservation;
        foreach ([fn () => $reservation->update(['status' => 'released']), fn () => $reservation->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('reservation changed outside the service');
            } catch (LogicException) {
            }
        }

        $this->assertSame([10, 10], [$entry->fresh()->amount, $entry->fresh()->balance_after]);
        $this->assertSame('reserved', $reservation->fresh()->status);
        $this->assertNull(CreditLedgerEntry::UPDATED_AT);
    }

    public function test_every_type_is_recorded_with_its_deltas_and_accounts_are_isolated(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();

        $this->svc()->grant($a, 100, 'same-key');
        $this->svc()->grant($b, 7, 'same-key'); // the same key on another account is another operation
        $r1 = $this->svc()->reserve($a, 30, 't:r1')->reservation;
        $consumption = $this->svc()->consume($r1, 20)->entry;
        $r2 = $this->svc()->reserve($a, 10, 't:r2')->reservation;
        $this->svc()->release($r2);
        $this->svc()->refund($a, 5, 't:refund', $consumption->id);

        $this->assertSame([
            ['grant', 100, 0], ['reservation', 0, 30], ['consumption', -20, -20], ['reservation_release', 0, -10],
            ['reservation', 0, 10], ['reservation_release', 0, -10], ['refund', 5, 0],
        ], CreditLedgerEntry::where('account_id', $a->id)->orderBy('id')->get()->map(fn ($e) => [$e->type, $e->balance_delta, $e->reserved_delta])->all());

        $this->assertSame(['balance' => 85, 'reserved' => 0, 'available' => 85], $this->svc()->balance($a));
        $this->assertSame(['balance' => 7, 'reserved' => 0, 'available' => 7], $this->svc()->balance($b));
        $this->assertSame(1, CreditLedgerEntry::where('account_id', $b->id)->count());
        $this->assertLedgerExplainsBalance($a);
        $this->assertLedgerExplainsBalance($b);
        $this->assertSame([$r1->id, $r1->id], CreditLedgerEntry::where('account_id', $a->id)->whereIn('type', ['consumption'])->pluck('reservation_id')->push($consumption->reservation_id)->all());
    }

    public function test_the_database_refuses_an_entry_that_mixes_two_tenants(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $creditA = $this->svc()->creditAccountFor($a);

        $this->expectException(QueryException::class);
        DB::table('credit_ledger_entries')->insert([
            'credit_account_id' => $creditA->id, 'account_id' => $b->id, 'type' => 'grant', 'amount' => 1, 'balance_delta' => 1,
            'reserved_delta' => 0, 'balance_after' => 1, 'reserved_after' => 0, 'idempotency_key' => 'x', 'request_hash' => str_repeat('0', 64), 'source' => 'system',
        ]);
    }

    public function test_the_database_itself_refuses_negative_or_over_reserved_state_on_mariadb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The CHECK constraints are MySQL/MariaDB-only (SQLite cannot ALTER them in).');
        }

        $credit = $this->svc()->creditAccountFor(Account::factory()->create());

        foreach ([['reserved' => 1], ['balance' => -1]] as $bad) {
            try {
                DB::transaction(fn () => DB::table('credit_accounts')->where('id', $credit->id)->update($bad));
                $this->fail('the database accepted '.json_encode($bad));
            } catch (QueryException) {
            }
        }

        $this->assertSame([0, 0], [(int) $credit->fresh()->balance, (int) $credit->fresh()->reserved]);
    }

    // ================================================================== idempotency

    public function test_duplicate_grant_adjust_and_refund_have_one_effect_and_replay_the_original(): void
    {
        $account = Account::factory()->create();

        $first = $this->svc()->grant($account, 50, 't:grant');
        $again = $this->svc()->grant($account, 50, 't:grant');
        $this->assertTrue($again->replayed);
        $this->assertSame($first->entry->id, $again->entry->id);

        $adj = $this->svc()->adjust($account, -10, 't:adj');
        $this->assertSame($adj->entry->id, $this->svc()->adjust($account, -10, 't:adj')->entry->id);

        $r = $this->svc()->reserve($account, 20, 't:res')->reservation;
        $c = $this->svc()->consume($r)->entry;
        $ref = $this->svc()->refund($account, 5, 't:ref', $c->id);
        $this->assertTrue($this->svc()->refund($account, 5, 't:ref', $c->id)->replayed);
        $this->assertSame($ref->entry->id, $this->svc()->refund($account, 5, 't:ref', $c->id)->entry->id);

        $this->assertSame(['balance' => 25, 'reserved' => 0, 'available' => 25], $this->svc()->balance($account));
        $this->assertSame(5, CreditLedgerEntry::where('account_id', $account->id)->count());
    }

    public function test_a_key_reused_for_different_parameters_is_a_conflict(): void
    {
        $account = Account::factory()->create();
        $this->svc()->grant($account, 50, 't:k');

        foreach ([fn () => $this->svc()->grant($account, 51, 't:k'), fn () => $this->svc()->adjust($account, 50, 't:k'), fn () => $this->svc()->grant($account, 50, 't:k', [], 'purchase')] as $call) {
            try {
                $call();
                $this->fail('conflicting reuse accepted');
            } catch (CreditException $e) {
                $this->assertSame(CreditException::IDEMPOTENCY_CONFLICT, $e->reason);
            }
        }

        $this->assertSame(50, $this->svc()->balance($account)['balance']);
    }

    public function test_duplicate_reserve_consume_and_release_have_one_effect(): void
    {
        $account = Account::factory()->create();
        $this->svc()->grant($account, 100, 't:grant');

        $one = $this->svc()->reserve($account, 40, 't:res');
        $two = $this->svc()->reserve($account, 40, 't:res');
        $this->assertTrue($two->replayed);
        $this->assertSame($one->reservation->id, $two->reservation->id);
        $this->assertSame(1, CreditReservation::count());
        $this->assertSame(40, $this->svc()->balance($account)['reserved']);

        $consumed = $this->svc()->consume($one->reservation, 30);
        $retry = $this->svc()->consume($one->reservation->fresh(), 30);
        $this->assertTrue($retry->replayed);
        $this->assertSame($consumed->entry->id, $retry->entry->id);

        $other = $this->svc()->reserve($account, 10, 't:res2')->reservation;
        $released = $this->svc()->release($other);
        $this->assertSame($released->entry->id, $this->svc()->release($other)->entry->id);

        $this->assertSame(['balance' => 70, 'reserved' => 0, 'available' => 70], $this->svc()->balance($account));
        $this->assertSame(1, CreditLedgerEntry::where('type', 'consumption')->count());
        $this->assertLedgerExplainsBalance($account);
    }

    public function test_idempotency_is_enforced_by_the_database_itself(): void
    {
        $account = Account::factory()->create();
        $entry = $this->svc()->grant($account, 5, 't:db')->entry;

        $this->expectException(QueryException::class);
        DB::table('credit_ledger_entries')->insert(collect($entry->getAttributes())->except('id')->all());
    }

    // ================================================================== reservation

    public function test_the_reservation_lifecycle_and_every_forbidden_transition(): void
    {
        $account = Account::factory()->create();
        $this->svc()->grant($account, 100, 't:grant');

        // Cannot reserve more than available.
        try {
            $this->svc()->reserve($account, 101, 't:too-much');
            $this->fail('over-reservation');
        } catch (CreditException $e) {
            $this->assertSame(CreditException::INSUFFICIENT_CREDITS, $e->reason);
        }
        $this->assertSame(0, CreditReservation::count());

        $a = $this->svc()->reserve($account, 60, 't:a')->reservation;
        $this->assertSame(['balance' => 100, 'reserved' => 60, 'available' => 40], $this->svc()->balance($account));
        $this->assertTrue($this->svc()->hasAvailable($account, 40));
        $this->assertFalse($this->svc()->hasAvailable($account, 41));

        try {
            $this->svc()->reserve($account, 41, 't:b');
            $this->fail('reserved credits were reserved twice');
        } catch (CreditException $e) {
            $this->assertSame(CreditException::INSUFFICIENT_CREDITS, $e->reason);
        }

        // Consume → consumed; cannot then be released.
        $this->svc()->consume($a);
        $this->assertSame(['consumed', 60], [$a->fresh()->status, $a->fresh()->consumed_amount]);
        $this->assertSame(['balance' => 40, 'reserved' => 0, 'available' => 40], $this->svc()->balance($account));
        $this->assertStateRefused(fn () => $this->svc()->release($a->fresh()));

        // Release → released; cannot then be consumed.
        $b = $this->svc()->reserve($account, 25, 't:c')->reservation;
        $this->svc()->release($b);
        $this->assertSame('released', $b->fresh()->status);
        $this->assertSame(['balance' => 40, 'reserved' => 0, 'available' => 40], $this->svc()->balance($account));
        $this->assertStateRefused(fn () => $this->svc()->consume($b->fresh()));

        // Partial consumption releases the remainder in the same step.
        $c = $this->svc()->reserve($account, 30, 't:d')->reservation;
        $this->svc()->consume($c, 12);
        $this->assertSame(['balance' => 28, 'reserved' => 0, 'available' => 28], $this->svc()->balance($account));
        $this->assertSame(['consumption', 'reservation_release'], CreditLedgerEntry::where('reservation_id', $c->id)->where('type', '!=', 'reservation')->orderBy('id')->pluck('type')->all());

        foreach ([0, 31] as $bad) {
            $d = $this->svc()->reserve($account, 1, "t:bad{$bad}")->reservation;
            try {
                $this->svc()->consume($d, $bad === 0 ? 0 : 2);
                $this->fail('bad consumed amount accepted');
            } catch (CreditException $e) {
                $this->assertSame(CreditException::INVALID_AMOUNT, $e->reason);
            }
        }

        $this->assertLedgerExplainsBalance($account);
    }

    public function test_a_refund_is_capped_by_its_consumption_and_bound_to_the_account(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $this->svc()->grant($a, 100, 't:ga');
        $this->svc()->grant($b, 100, 't:gb');
        $consumption = $this->svc()->consume($this->svc()->reserve($a, 30, 't:r')->reservation)->entry;
        $grant = CreditLedgerEntry::where('account_id', $a->id)->where('type', 'grant')->first();

        $this->svc()->refund($a, 20, 't:ref1', $consumption->id);

        foreach ([fn () => $this->svc()->refund($a, 11, 't:ref2', $consumption->id), fn () => $this->svc()->refund($a, 5, 't:ref3', $grant->id), fn () => $this->svc()->refund($b, 5, 't:ref4', $consumption->id)] as $call) {
            try {
                $call();
                $this->fail('refund accepted');
            } catch (CreditException $e) {
                $this->assertSame(CreditException::INVALID_OPERATION, $e->reason);
            }
        }

        $this->svc()->refund($a, 10, 't:ref5', $consumption->id);
        $this->assertSame(100, $this->svc()->balance($a)['balance']);
        $this->assertSame(100, $this->svc()->balance($b)['balance']);
    }

    private function assertStateRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('forbidden reservation transition accepted');
        } catch (CreditException $e) {
            $this->assertSame(CreditException::RESERVATION_STATE, $e->reason);
        }
    }

    // ================================================================== authorization / API

    public function test_the_super_admin_grants_adjusts_and_refunds_with_a_full_audit_record(): void
    {
        $client = Account::factory()->create();
        $admin = $this->superAdmin();

        $grant = $this->grantVia($admin, $client, ['amount' => 500, 'reason' => 'Launch bonus', 'reference' => 'TICKET-42'])->assertCreated()
            ->assertJsonPath('replayed', false)->assertJsonPath('data.balance.available', 500);
        $entry = CreditLedgerEntry::findOrFail($grant->json('data.entry.id'));
        $this->assertSame(
            [$client->id, 'grant', 500, 500, 'Launch bonus', 'admin_reference', 'TICKET-42', 'admin:grant:key-grant-0001', $admin->id, 'admin_api'],
            [$entry->account_id, $entry->type, $entry->amount, $entry->balance_after, $entry->reason, $entry->reference_type, $entry->reference_id, $entry->idempotency_key, $entry->actor_user_id, $entry->source]
        );
        $this->assertArrayNotHasKey('request_hash', $grant->json('data.entry'));

        $this->actingAs($admin)->postJson(sprintf(self::ADMIN, $client->id).'/adjust', ['amount' => -100, 'reason' => 'Correction', 'idempotency_key' => 'key-adjust-0001'])
            ->assertCreated()->assertJsonPath('data.balance.balance', 400);

        $consumption = $this->svc()->consume($this->svc()->reserve($client, 50, 'sys:res')->reservation)->entry;
        $this->actingAs($admin)->postJson(sprintf(self::ADMIN, $client->id).'/refund', ['amount' => 50, 'consumption_entry_id' => $consumption->id, 'reason' => 'Failed AI call', 'idempotency_key' => 'key-refund-0001'])
            ->assertCreated()->assertJsonPath('data.balance.balance', 400);

        $this->actingAs($admin)->getJson(sprintf(self::ADMIN, $client->id))->assertOk()
            ->assertJsonPath('data.balance', 400)->assertJsonPath('ledger.total', 5);
    }

    public function test_a_retried_admin_request_replays_is_logged_and_a_changed_retry_is_a_409(): void
    {
        $client = Account::factory()->create();
        $admin = $this->superAdmin();

        $this->grantVia($admin, $client, ['amount' => 100, 'reason' => 'x'])->assertCreated();
        $replay = $this->withHeader('Idempotency-Key', 'key-grant-0001')->actingAs($admin)
            ->postJson(sprintf(self::ADMIN, $client->id).'/grant', ['amount' => 100, 'reason' => 'x'])->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(100, $replay->json('data.balance.balance'));
        $this->flushHeaders();

        $this->grantVia($admin, $client, ['amount' => 999, 'reason' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');
        $this->grantVia($admin, $client, ['amount' => 100, 'reason' => 'x'], null)->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, CreditLedgerEntry::where('account_id', $client->id)->count());
        $log = ActivityLog::where('module_name', 'Credits')->where('action_type', 'replay')->sole();
        $this->assertSame([$client->id, $admin->id, 'admin:grant:key-grant-0001'], [$log->account_id, $log->user_id, $log->new_values['idempotency_key']]);
    }

    public function test_an_agent_reads_only_its_own_clients_and_can_never_change_credits(): void
    {
        ['agentUser' => $agentUser, 'client' => $client] = $this->agentWithClient();
        $stranger = Account::factory()->create();
        $this->svc()->grant($client, 30, 't:g');

        $this->actingAs($agentUser)->getJson(sprintf(self::ADMIN, $client->id))->assertOk()->assertJsonPath('data.balance', 30);
        $this->actingAs($agentUser)->getJson(sprintf(self::ADMIN, $stranger->id))->assertNotFound();
        $this->actingAs($agentUser)->getJson(sprintf(self::ADMIN, 999999))->assertNotFound();

        foreach (['grant' => ['amount' => 5, 'reason' => 'x'], 'adjust' => ['amount' => 5, 'reason' => 'x'], 'refund' => ['amount' => 5, 'consumption_entry_id' => 1, 'reason' => 'x']] as $op => $body) {
            foreach ([$client, $stranger] as $target) {
                $this->actingAs($agentUser)->postJson(sprintf(self::ADMIN, $target->id)."/{$op}", $body + ['idempotency_key' => "agent-{$op}-0001"])->assertForbidden();
            }
        }

        // Its own sub-client's balance on the tenant route too (tenant.isolation), never a stranger's.
        $this->actingAs($agentUser)->getJson("/api/billing/credits?account_id={$client->id}")->assertOk()->assertJsonPath('data.available', 30);
        $this->actingAs($agentUser)->getJson("/api/billing/credits?account_id={$stranger->id}")->assertNotFound();

        $this->assertSame(30, $this->svc()->balance($client)['balance']);
        $this->assertSame(0, $this->svc()->balance($stranger)['balance']);
    }

    public function test_a_client_sees_only_its_own_credits_and_cannot_change_them(): void
    {
        $mine = Account::factory()->create();
        $theirs = Account::factory()->create();
        $client = $this->user($mine);
        $this->svc()->grant($mine, 10, 't:mine');
        $this->svc()->grant($theirs, 999, 't:theirs');

        // Forged ?account_id= / body account_id are ignored: always its own account.
        $this->actingAs($client)->getJson("/api/billing/credits?account_id={$theirs->id}")
            ->assertOk()->assertJsonPath('data.account_id', $mine->id)->assertJsonPath('data.balance', 10);
        $ledger = $this->actingAs($client)->getJson("/api/billing/credits/ledger?account_id={$theirs->id}")->assertOk();
        $this->assertSame([$mine->id], collect($ledger->json('data'))->pluck('account_id')->unique()->values()->all());

        // No manual operation, on any account (IDOR).
        foreach ([$mine, $theirs] as $target) {
            foreach (['grant', 'adjust', 'refund'] as $op) {
                $this->actingAs($client)->postJson(sprintf(self::ADMIN, $target->id)."/{$op}", ['amount' => 5, 'reason' => 'x', 'consumption_entry_id' => 1, 'idempotency_key' => 'client-try-0001', 'account_id' => $mine->id])
                    ->assertForbidden();
            }
            $this->actingAs($client)->getJson(sprintf(self::ADMIN, $target->id))->assertForbidden();
        }

        $this->assertSame([10, 999], [$this->svc()->balance($mine)['balance'], $this->svc()->balance($theirs)['balance']]);
    }

    public function test_the_target_is_the_path_account_never_a_forged_body_account(): void
    {
        $target = Account::factory()->create();
        $other = Account::factory()->create();

        $this->grantVia($this->superAdmin(), $target, ['amount' => 5, 'reason' => 'x', 'account_id' => $other->id, 'tenant_id' => $other->id])->assertCreated();

        $this->assertSame([5, 0], [$this->svc()->balance($target)['balance'], $this->svc()->balance($other)['balance']]);
        $this->assertSame(0, CreditLedgerEntry::where('account_id', $other->id)->count());
    }

    public function test_a_super_admin_refund_of_another_accounts_consumption_is_refused(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $this->svc()->grant($a, 50, 't:a');
        $consumption = $this->svc()->consume($this->svc()->reserve($a, 20, 't:r')->reservation)->entry;

        $this->actingAs($this->superAdmin())->postJson(sprintf(self::ADMIN, $b->id).'/refund', ['amount' => 20, 'consumption_entry_id' => $consumption->id, 'reason' => 'x', 'idempotency_key' => 'idor-refund-0001'])
            ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_OPERATION');
        $this->assertSame(0, $this->svc()->balance($b)['balance']);
    }

    public function test_invalid_admin_payloads_are_422_and_write_nothing(): void
    {
        $client = Account::factory()->create();
        $admin = $this->superAdmin();

        foreach ([['amount' => 0, 'reason' => 'x'], ['amount' => -1, 'reason' => 'x'], ['amount' => 1.5, 'reason' => 'x'], ['amount' => '10', 'reason' => ''], ['amount' => CreditService::MAX_AMOUNT + 1, 'reason' => 'x']] as $body) {
            $this->grantVia($admin, $client, $body)->assertStatus(422);
        }
        $this->grantVia($admin, $client, ['amount' => 5, 'reason' => 'x'], 'bad key!')->assertStatus(422);
        $this->actingAs($admin)->postJson(sprintf(self::ADMIN, $client->id).'/adjust', ['amount' => 0, 'reason' => 'x', 'idempotency_key' => 'zero-adjust-01'])->assertStatus(422);
        $this->actingAs($admin)->postJson(sprintf(self::ADMIN, $client->id).'/adjust', ['amount' => -5, 'reason' => 'x', 'idempotency_key' => 'neg-adjust-001'])
            ->assertStatus(422)->assertJsonPath('error_code', 'INSUFFICIENT_CREDITS');
        $this->grantVia($admin, Account::factory()->make(['id' => 999999]), ['amount' => 5, 'reason' => 'x'])->assertNotFound();

        $this->assertSame(0, CreditLedgerEntry::count());
    }

    public function test_deleting_an_account_removes_its_credit_records_only(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $this->svc()->grant($a, 10, 't:a');
        $this->svc()->consume($this->svc()->reserve($a, 5, 't:r')->reservation);
        $this->svc()->grant($b, 10, 't:b');

        DB::table('accounts')->where('id', $a->id)->delete();

        $this->assertSame([0, 0, 0], [CreditAccount::where('account_id', $a->id)->count(), CreditLedgerEntry::where('account_id', $a->id)->count(), CreditReservation::where('account_id', $a->id)->count()]);
        $this->assertSame(10, $this->svc()->balance($b)['balance']);
    }

    // ================================================================== concurrency (real MariaDB)

    public function test_concurrent_credit_operations_are_consistent_on_real_mariadb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Real row-lock concurrency needs MariaDB/MySQL; SQLite serializes all writers. Run the suite on MariaDB (authoritative) or tests/Probes/credit_concurrency_probe.php directly.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The concurrency probe needs the pcntl extension.');
        }

        $current = (string) DB::connection()->getDatabaseName();
        $this->assertStringStartsWith('wa_throwaway_', $current, 'refusing to create a probe database next to a non-throwaway one');
        DB::statement('CREATE DATABASE IF NOT EXISTS `wa_throwaway_credit_probe`');

        $config = config('database.connections.'.config('database.default'));
        $process = new Process([PHP_BINARY, base_path('tests/Probes/credit_concurrency_probe.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => 'wa_throwaway_credit_probe', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => (string) $config['password'], 'PROBE_ROUNDS' => '1',
        ]);
        $process->setTimeout(600)->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringNotContainsString('FAIL', $output, $output);
        foreach (['20 simultaneous grants', '20 simultaneous reservations', '20 simultaneous consumes', 'consume vs release', 'duplicate grants', 'duplicate reservations', 'HTTP grants', 'mixed operations',
            // Phase 8 Task 2 — plan credit allocation under concurrency.
            'simultaneous fulfilments of one plan invoice', 'simultaneous re-allocations of one period', 'simultaneous first allocations of one period',
            'simultaneous plan invoices of one account', 'simultaneous backfills after the live allocation',
            // Phase 8 Task 3 — spending under concurrency.
            'simultaneous direct spends', 'simultaneous partial consumes of 10 on one reservation', 'partial consumes racing releases',
            'duplicate spend / consume / settle / release', '30 mixed grant/reserve/consume/release/settle/spend/refund/adjust',
            'expiry cleanup racing owner releases', 'spends in caller transactions racing a plan payment'] as $case) {
            $this->assertMatchesRegularExpression('/^PASS .*'.preg_quote($case, '/').'/m', $output, $case);
        }
    }
}
