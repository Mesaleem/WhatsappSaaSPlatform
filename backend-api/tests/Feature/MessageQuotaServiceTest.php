<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Subscription;
use App\Services\Messaging\MessageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 5 Task 2 -- MessageQuotaService.
 *
 * The Task 1 audit found five pipelines each hand-rolling quota, in two
 * incompatible shapes, with the consume-after shape missing the
 * inside-the-lock re-check that the reserve-ahead shape has. This file
 * pins down the primitive that consolidates them: that reserve() is a
 * gate which refuses and writes nothing, that consume() is accounting
 * which never refuses, that release() cannot manufacture credits, and
 * that every limit answer still comes from Subscription::hasQuotaFor()
 * rather than a second copy of the rule.
 */
class MessageQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private MessageQuotaService $quota;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quota = app(MessageQuotaService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function subscription(array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'account_id' => Account::factory()->create()->id,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'rate_per_message' => null,
            'total_allocated_messages' => 100,
            'used_messages' => 0,
            'price_paid' => 4999,
            'payment_mode' => 'razorpay',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
        ], $overrides));
    }

    private function used(Subscription $s): int
    {
        return (int) Subscription::findOrFail($s->id)->used_messages;
    }

    /**
     * Two separate guarantees, asserted together because neither alone is
     * enough:
     *
     *   1. the write happens INSIDE a transaction -- driver-independent
     *      and the part that makes a lock mean anything;
     *   2. the preceding SELECT carries FOR UPDATE -- only assertable on
     *      a driver whose grammar emits it. Laravel's SQLite grammar
     *      compiles lockForUpdate() to nothing (SQLite serialises writes
     *      at the file level instead), so this half is skipped there and
     *      genuinely runs on the MySQL/MariaDB parity run.
     *
     * The behavioural consequence of the lock -- that the re-check reads
     * the committed row, not the caller's stale copy -- is pinned
     * separately and on every driver by
     * test_reserve_rechecks_against_the_locked_row_not_the_callers_stale_copy().
     */
    private function assertMutatesUnderLock(callable $operation): void
    {
        $sawLockedSelect = false;
        $sawWriteInTransaction = false;

        DB::listen(function ($query) use (&$sawLockedSelect, &$sawWriteInTransaction) {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update')) {
                $sawLockedSelect = true;
            }

            if (str_starts_with($sql, 'update "subscriptions"') || str_starts_with($sql, 'update `subscriptions`')) {
                $sawWriteInTransaction = DB::transactionLevel() > 0;
            }
        });

        $operation();

        $this->assertTrue($sawWriteInTransaction, 'the quota write must happen inside a transaction');

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $this->assertTrue($sawLockedSelect, 'the re-read must be a SELECT ... FOR UPDATE');
    }

    // ==================================================================
    // hasQuotaFor -- advisory, delegated
    // ==================================================================
    public function test_it_reports_sufficient_quota(): void
    {
        $s = $this->subscription(['used_messages' => 10]);

        $this->assertTrue($this->quota->hasQuotaFor($s, 1));
        $this->assertTrue($this->quota->hasQuotaFor($s, 90));
    }

    public function test_it_reports_insufficient_quota(): void
    {
        $s = $this->subscription(['used_messages' => 95]);

        $this->assertFalse($this->quota->hasQuotaFor($s, 6));
    }

    public function test_exactly_the_remaining_quota_is_sufficient(): void
    {
        $s = $this->subscription(['used_messages' => 95]);

        $this->assertTrue($this->quota->hasQuotaFor($s, 5), 'the last 5 credits must be usable');
        $this->assertFalse($this->quota->hasQuotaFor($s, 6));
    }

    public function test_an_uncapped_plan_always_has_quota(): void
    {
        $unlimited = $this->subscription(['billing_model' => 'unlimited', 'used_messages' => 999999]);
        $noCapSet = $this->subscription(['total_allocated_messages' => null, 'used_messages' => 999999]);

        $this->assertTrue($this->quota->hasQuotaFor($unlimited, 10000));
        $this->assertTrue($this->quota->hasQuotaFor($noCapSet, 10000));
    }

    public function test_the_limit_answer_is_delegated_to_the_subscription_model(): void
    {
        // Not a second copy of the rule: for every boundary the service's
        // answer must be exactly Subscription::hasQuotaFor()'s answer.
        $s = $this->subscription(['used_messages' => 98]);

        foreach ([1, 2, 3, 50] as $n) {
            $this->assertSame(
                $s->hasQuotaFor($n),
                $this->quota->hasQuotaFor($s, $n),
                "service and model disagreed for amount {$n}",
            );
        }
    }

    // ==================================================================
    // Invalid amounts
    // ==================================================================
    public function test_a_non_positive_amount_is_rejected_everywhere(): void
    {
        $s = $this->subscription(['used_messages' => 10]);

        foreach ([0, -1, -100] as $bad) {
            $this->assertFalse($this->quota->hasQuotaFor($s, $bad), "hasQuotaFor accepted {$bad}");
            $this->assertFalse($this->quota->reserve($s, $bad), "reserve accepted {$bad}");
            $this->assertFalse($this->quota->consume($s, $bad), "consume accepted {$bad}");
            $this->quota->release($s, $bad);
        }

        $this->assertSame(10, $this->used($s), 'an invalid amount must move nothing');
    }

    // ==================================================================
    // reserve -- the gate
    // ==================================================================
    public function test_reserve_claims_the_quota_when_it_fits(): void
    {
        $s = $this->subscription(['used_messages' => 10]);

        $this->assertTrue($this->quota->reserve($s, 25));
        $this->assertSame(35, $this->used($s));
    }

    public function test_reserve_refuses_and_writes_nothing_when_it_does_not_fit(): void
    {
        $s = $this->subscription(['used_messages' => 95]);

        $this->assertFalse($this->quota->reserve($s, 6));
        $this->assertSame(95, $this->used($s), 'a refused reservation must not increment');
    }

    public function test_reserve_allows_exactly_the_remaining_quota(): void
    {
        $s = $this->subscription(['used_messages' => 95]);

        $this->assertTrue($this->quota->reserve($s, 5));
        $this->assertSame(100, $this->used($s));

        // ...and the very next credit is refused.
        $this->assertFalse($this->quota->reserve($s, 1));
        $this->assertSame(100, $this->used($s));
    }

    public function test_reserve_marks_the_subscription_exhausted_once_the_cap_is_reached(): void
    {
        $s = $this->subscription(['used_messages' => 99]);

        $this->assertTrue($this->quota->reserve($s, 1));
        $this->assertSame('exhausted', Subscription::findOrFail($s->id)->status);
    }

    /**
     * THE defect this primitive exists to close. The caller's in-memory
     * Subscription is deliberately stale -- it still believes
     * used_messages is 0 -- exactly as it would be for a second
     * concurrent request that read the row before the first one
     * committed. reserve() must re-read under the lock and refuse.
     */
    public function test_reserve_rechecks_against_the_locked_row_not_the_callers_stale_copy(): void
    {
        $s = $this->subscription(['used_messages' => 0]);

        // Another request consumed the whole plan in the meantime.
        Subscription::whereKey($s->id)->update(['used_messages' => 100]);

        $this->assertSame(0, $s->used_messages, 'the caller copy must still be stale for this test to mean anything');
        $this->assertTrue($s->hasQuotaFor(1), 'the stale copy would happily say yes');

        $this->assertFalse($this->quota->reserve($s, 1), 'reserve must re-check inside the lock');
        $this->assertSame(100, $this->used($s));
    }

    public function test_reserve_runs_inside_a_transaction_with_a_row_lock(): void
    {
        $s = $this->subscription();

        $this->assertMutatesUnderLock(fn () => $this->quota->reserve($s, 1));
    }

    public function test_sequential_reserves_cannot_overshoot_the_cap(): void
    {
        $s = $this->subscription(['total_allocated_messages' => 5, 'used_messages' => 0]);

        $granted = 0;
        for ($i = 0; $i < 20; $i++) {
            if ($this->quota->reserve($s, 1)) {
                $granted++;
            }
        }

        $this->assertSame(5, $granted, 'exactly the cap may be granted');
        $this->assertSame(5, $this->used($s), 'used_messages must never exceed the cap via reserve()');
    }

    public function test_reserve_returns_false_for_a_deleted_subscription(): void
    {
        $s = $this->subscription();
        Subscription::whereKey($s->id)->delete();

        $this->assertFalse($this->quota->reserve($s, 1));
    }

    // ==================================================================
    // consume -- accounting for a send that already happened
    // ==================================================================
    public function test_consume_increments_atomically(): void
    {
        $s = $this->subscription(['used_messages' => 10]);

        $this->assertTrue($this->quota->consume($s, 1));
        $this->assertSame(11, $this->used($s));

        $this->assertTrue($this->quota->consume($s, 4));
        $this->assertSame(15, $this->used($s));
    }

    /**
     * consume() must NOT refuse on cap grounds: by the time it is called
     * the message is already on WhatsApp, and every pre-existing
     * consume-after call site increments unconditionally. Refusing here
     * would silently under-count real usage.
     */
    public function test_consume_never_refuses_on_cap_grounds(): void
    {
        $s = $this->subscription(['used_messages' => 100]);

        $this->assertFalse($this->quota->hasQuotaFor($s, 1), 'precondition: the plan is full');
        $this->assertTrue($this->quota->consume($s, 1), 'consume records usage that already happened');
        $this->assertSame(101, $this->used($s));
    }

    public function test_consume_refreshes_the_status(): void
    {
        $s = $this->subscription(['used_messages' => 99]);

        $this->quota->consume($s, 1);

        $this->assertSame('exhausted', Subscription::findOrFail($s->id)->status);
    }

    public function test_consume_runs_inside_a_transaction_with_a_row_lock(): void
    {
        $s = $this->subscription();

        $this->assertMutatesUnderLock(fn () => $this->quota->consume($s, 1));
    }

    public function test_consume_does_not_lose_increments_to_a_stale_caller_copy(): void
    {
        $s = $this->subscription(['used_messages' => 0]);
        Subscription::whereKey($s->id)->update(['used_messages' => 40]);

        // The caller copy still says 0; the increment must apply to 40.
        $this->assertTrue($this->quota->consume($s, 1));
        $this->assertSame(41, $this->used($s));
    }

    public function test_consume_returns_false_for_a_deleted_subscription(): void
    {
        $s = $this->subscription();
        Subscription::whereKey($s->id)->delete();

        $this->assertFalse($this->quota->consume($s, 1));
    }

    // ==================================================================
    // release -- giving quota back
    // ==================================================================
    public function test_release_gives_quota_back(): void
    {
        $s = $this->subscription(['used_messages' => 30]);

        $this->quota->release($s, 10);

        $this->assertSame(20, $this->used($s));
    }

    public function test_release_never_drives_usage_negative(): void
    {
        $s = $this->subscription(['used_messages' => 3]);

        $this->quota->release($s, 10);

        $this->assertSame(0, $this->used($s), 'usage must floor at zero, never go negative');
    }

    public function test_a_double_release_cannot_manufacture_credits(): void
    {
        $s = $this->subscription(['used_messages' => 5]);

        $this->quota->release($s, 5);
        $this->quota->release($s, 5);

        $this->assertSame(0, $this->used($s));
    }

    public function test_release_reopens_an_exhausted_subscription(): void
    {
        $s = $this->subscription(['used_messages' => 100]);
        $this->quota->consume($s, 1);
        $this->assertSame('exhausted', Subscription::findOrFail($s->id)->status);

        $this->quota->release($s, 50);

        $this->assertSame('active', Subscription::findOrFail($s->id)->status);
        $this->assertSame(51, $this->used($s));
    }

    public function test_release_is_a_no_op_for_a_deleted_subscription(): void
    {
        $s = $this->subscription();
        Subscription::whereKey($s->id)->delete();

        $this->quota->release($s, 1);

        $this->assertSame(0, Subscription::whereKey($s->id)->count());
    }

    // ==================================================================
    // Round trip
    // ==================================================================
    public function test_reserve_then_release_restores_the_original_state(): void
    {
        $s = $this->subscription(['used_messages' => 20]);

        $this->assertTrue($this->quota->reserve($s, 30));
        $this->assertSame(50, $this->used($s));

        $this->quota->release($s, 30);
        $this->assertSame(20, $this->used($s));
        $this->assertSame('active', Subscription::findOrFail($s->id)->status);
    }
}
