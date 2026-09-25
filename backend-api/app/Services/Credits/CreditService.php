<?php

namespace App\Services\Credits;

use App\Models\Account;
use App\Models\CreditAccount;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 Task 1 — THE credit service. The only code that changes a credit
 * balance. Controllers, Journey, CRM and (later) AI code call these methods;
 * none of them may write credit_accounts / credit_ledger_entries /
 * credit_reservations themselves.
 *
 * MODEL
 *   balance    credits the account owns;
 *   reserved   the part of balance held by open reservations;
 *   available  = balance − reserved  (never negative: reserved <= balance).
 *
 *   type                 balance_delta   reserved_delta
 *   grant / purchase         +a               0
 *   plan_allocation          +a               0      (Phase 8 Task 2)
 *   adjustment              ±a               0      (− only up to available)
 *   refund                  +a               0      (of a consumption; capped)
 *   reservation              0              +a      (a <= available)
 *   reservation_release      0              −a      (the reservation's remaining hold)
 *   consumption             −a              −a      (ALWAYS of a reservation)
 *
 * Every row stores its deltas AND the resulting balance_after /
 * reserved_after, so the ledger alone explains the balance.
 *
 * ATOMICITY / CONCURRENCY. Every operation is ONE database transaction that
 * first locks the account's credit_accounts row (SELECT … FOR UPDATE), so
 * all operations on one account are serialized: no lost update, no negative
 * balance, no reservation beyond what is available. Lock order is always
 * credit_accounts → credit_reservations → insert; there is no other lock,
 * and nothing outside this subsystem locks these rows (the Journey engine's
 * no-row-lock rule concerns whatsapp_flow_sessions, which this never
 * touches). A deadlock/serialization failure is retried by
 * DB::transaction(…, attempts: 3).
 *
 * IDEMPOTENCY (database-enforced). Every operation carries an idempotency
 * key, unique per credit account in the ledger (and in credit_reservations
 * for reserve). Inside the lock the key is looked up first: already applied
 * → the ORIGINAL entry is returned (`replayed`), nothing is written; the
 * same key with different parameters (request_hash) → CreditException
 * idempotency_conflict. The unique index is the backstop: a racing
 * duplicate that still reaches INSERT fails there and is answered as a
 * replay. consume/release derive their key from the reservation
 * (consume:{id} / release:{id}), so a reservation can be consumed or
 * released at most once however often it is retried.
 *
 * KEY CONVENTION: callers namespace their keys "{origin}:{operation}:{ref}"
 * (e.g. "admin:grant:<client key>"); the service stores the key as given.
 * The prefixes "consume:" and "release:" are reserved for the keys the
 * service derives from a reservation (Phase 8 Task 3: refused as caller
 * keys, so no caller key can pre-empt a reservation's settle/release).
 *
 * PHASE 8 TASK 3 — SPENDING. A reservation holds `amount`; `consumed_amount`
 * is what has been consumed from it so far; while it is open its remaining
 * hold is amount − consumed_amount, and that is exactly its share of
 * credit_accounts.reserved.
 *
 *   capture(r, a, key)   partial consumption: one `consumption` row (−a, −a);
 *                        the reservation stays open until nothing remains
 *                        (then → consumed). Caller key, one effect per key.
 *   consume(r, ?a)       settle (Task 1): consume a (default: all that
 *                        remains) and release the rest in the same step;
 *                        → consumed. Key consume:{id}.
 *   release(r)           give the remaining hold back; → released. Key
 *                        release:{id}. Allowed after expires_at.
 *   spend(acct, a, key)  DIRECT consumption. Written as a reservation that is
 *                        created already consumed, with its `reservation`
 *                        row (0, +a) and its `consumption` row (−a, −a) in
 *                        ONE transaction — so "a consumption always
 *                        references a reservation" stays true, refunds work
 *                        unchanged, and every row still reconstructs.
 *
 * A reservation may carry expires_at (caller-chosen; NULL = never). Past it,
 * capture/consume are refused (invalid_reservation) and only release — by
 * the caller or by credits:release-expired-reservations — is possible.
 * Terminal (consumed / released) reservations are never mutated again.
 *
 * PRODUCT GATES stay outside this class: reserve()/spend() accept an
 * optional CreditSpendGate (e.g. CreditEntitlementService for AI), evaluated
 * under the lock after the idempotency lookup.
 */
final class CreditService
{
    /** Largest amount one operation may move (keeps every sum far from BIGINT limits). */
    public const MAX_AMOUNT = 1_000_000_000_000;

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_ADMIN = 'admin_api';

    private const MAX_KEY_LENGTH = 191;

    /** Key prefixes the service derives itself (transition()); refused as caller keys. */
    private const DERIVED_KEY_PREFIXES = ['consume:', 'release:'];

    /**
     * The account's credit account, created (balance 0) on first use.
     * Race-safe: an UPSERT on the unique account_id, then a locking read.
     */
    public function creditAccountFor(Account $account): CreditAccount
    {
        $existing = CreditAccount::where('account_id', $account->id)->first();

        if ($existing) {
            return $existing;
        }

        // Phase 8 Task 2 fix (found by the plan-allocation probe, which runs
        // this INSIDE a caller's transaction): create-if-missing with an
        // UPSERT, not INSERT IGNORE. On a duplicate, INSERT IGNORE leaves a
        // SHARED lock on the existing row; several racers holding it then
        // all asking FOR UPDATE deadlocked (S→X upgrade). INSERT … ON
        // DUPLICATE KEY UPDATE takes the EXCLUSIVE lock directly, so racers
        // simply queue. The read after it is a LOCKING read: under
        // REPEATABLE READ a plain read would use the caller's older snapshot
        // and miss the row another process just committed.
        DB::table('credit_accounts')->upsert(
            [['account_id' => $account->id, 'balance' => 0, 'reserved' => 0, 'created_at' => now(), 'updated_at' => now()]],
            ['account_id'],
            ['account_id'],
        );

        return CreditAccount::where('account_id', $account->id)->lockForUpdate()->firstOrFail();
    }

    /** @return array{balance: int, reserved: int, available: int} */
    public function balance(Account $account): array
    {
        $credit = CreditAccount::where('account_id', $account->id)->first();

        $balance = (int) ($credit?->balance ?? 0);
        $reserved = (int) ($credit?->reserved ?? 0);

        return ['balance' => $balance, 'reserved' => $reserved, 'available' => $balance - $reserved];
    }

    /** Advisory (unlocked) check. Use reserve() to actually hold credits. */
    public function hasAvailable(Account $account, int $amount): bool
    {
        return $amount > 0 && $this->balance($account)['available'] >= $amount;
    }

    /**
     * Credits in: a grant, or (type purchase) credits bought. Plan/payment
     * wiring is Phase 8 Task 2; the type exists so it needs no new schema.
     *
     * @param  array{reason?: ?string, reference_type?: ?string, reference_id?: string|int|null, metadata?: ?array, actor_user_id?: ?int, source?: string}  $context
     */
    public function grant(Account $account, int $amount, string $idempotencyKey, array $context = [], string $type = CreditLedgerEntry::TYPE_GRANT): CreditOperationResult
    {
        // Phase 8 Task 2 — plan_allocation is written only by PlanCreditAllocator.
        if (! in_array($type, [CreditLedgerEntry::TYPE_GRANT, CreditLedgerEntry::TYPE_PURCHASE, CreditLedgerEntry::TYPE_PLAN_ALLOCATION], true)) {
            throw CreditException::invalid('A grant is of type grant, purchase or plan_allocation.');
        }

        $this->assertAmount($amount);
        $this->assertCallerKey($idempotencyKey);

        return $this->apply($account, $idempotencyKey, $type, ['amount' => $amount], $context, function (CreditAccount $credit) use ($amount, $type) {
            return ['type' => $type, 'amount' => $amount, 'balance_delta' => $amount, 'reserved_delta' => 0];
        });
    }

    /**
     * A manual correction, positive or negative. A negative adjustment can
     * take away only AVAILABLE credits (never reserved ones, never below 0).
     */
    public function adjust(Account $account, int $delta, string $idempotencyKey, array $context = []): CreditOperationResult
    {
        if ($delta === 0) {
            throw CreditException::amount('An adjustment must change the balance.');
        }

        $this->assertAmount(abs($delta));
        $this->assertCallerKey($idempotencyKey);

        return $this->apply($account, $idempotencyKey, CreditLedgerEntry::TYPE_ADJUSTMENT, ['delta' => $delta], $context, function (CreditAccount $credit) use ($delta) {
            if ($delta < 0 && $credit->available() < -$delta) {
                throw CreditException::insufficient(-$delta, $credit->available());
            }

            return ['type' => CreditLedgerEntry::TYPE_ADJUSTMENT, 'amount' => abs($delta), 'balance_delta' => $delta, 'reserved_delta' => 0];
        });
    }

    /**
     * Give back credits that a CONSUMPTION of this account took. The total
     * refunded against one consumption can never exceed what it consumed.
     */
    public function refund(Account $account, int $amount, string $idempotencyKey, int $consumptionEntryId, array $context = []): CreditOperationResult
    {
        $this->assertAmount($amount);
        $this->assertCallerKey($idempotencyKey);

        return $this->apply($account, $idempotencyKey, CreditLedgerEntry::TYPE_REFUND, ['amount' => $amount, 'of' => $consumptionEntryId], $context, function (CreditAccount $credit) use ($amount, $consumptionEntryId) {
            $consumption = CreditLedgerEntry::query()
                ->where('credit_account_id', $credit->id)
                ->where('type', CreditLedgerEntry::TYPE_CONSUMPTION)
                ->find($consumptionEntryId);

            // Another account's entry is indistinguishable from a missing one.
            if (! $consumption) {
                throw CreditException::invalid("Ledger entry #{$consumptionEntryId} is not a consumption of this account.");
            }

            $alreadyRefunded = (int) CreditLedgerEntry::query()
                ->where('credit_account_id', $credit->id)
                ->where('type', CreditLedgerEntry::TYPE_REFUND)
                ->where('refund_of_entry_id', $consumption->id)
                ->sum('amount');

            if ($alreadyRefunded + $amount > $consumption->amount) {
                throw CreditException::invalid('A refund cannot exceed what the consumption took ('.($consumption->amount - $alreadyRefunded).' refundable).');
            }

            return ['type' => CreditLedgerEntry::TYPE_REFUND, 'amount' => $amount, 'balance_delta' => $amount, 'reserved_delta' => 0, 'refund_of_entry_id' => $consumption->id];
        });
    }

    /**
     * Hold $amount of the AVAILABLE credits. Refused (nothing written) when
     * fewer are available or the gate refuses.
     *
     * $context['expires_at'] (Phase 8 Task 3, optional, must be in the
     * future): after it the hold can only be released. It is NOT part of the
     * idempotency parameters — a retry that recomputes "now + ttl" replays.
     */
    public function reserve(Account $account, int $amount, string $idempotencyKey, array $context = [], ?CreditSpendGate $gate = null): CreditOperationResult
    {
        $this->assertAmount($amount);
        $this->assertCallerKey($idempotencyKey);
        $expiresAt = $this->expiresAt($context);

        return $this->apply($account, $idempotencyKey, CreditLedgerEntry::TYPE_RESERVATION, ['amount' => $amount], $context, function (CreditAccount $credit) use ($account, $amount, $idempotencyKey, $context, $gate, $expiresAt) {
            $gate?->assertMaySpend($account, $amount);

            if ($credit->available() < $amount) {
                throw CreditException::insufficient($amount, $credit->available());
            }

            // expires_at is written only when set, so a reservation without
            // one never depends on the Task 3 column (deploy-order safety).
            $reservation = CreditReservation::create([
                'credit_account_id' => $credit->id,
                'account_id' => $credit->account_id,
                'amount' => $amount,
                'status' => CreditReservation::STATUS_RESERVED,
                'idempotency_key' => $idempotencyKey,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => isset($context['reference_id']) ? mb_substr((string) $context['reference_id'], 0, 64) : null,
                'metadata' => $context['metadata'] ?? null,
            ] + ($expiresAt ? ['expires_at' => $expiresAt] : []));

            return ['type' => CreditLedgerEntry::TYPE_RESERVATION, 'amount' => $amount, 'balance_delta' => 0, 'reserved_delta' => $amount, 'reservation_id' => $reservation->id];
        });
    }

    /**
     * Phase 8 Task 3 — DIRECT consumption: take $amount of the AVAILABLE
     * credits now. Recorded as a reservation created already consumed plus
     * its `reservation` and `consumption` rows, in one transaction (see the
     * class docblock). The result's entry is the consumption (refundable).
     */
    public function spend(Account $account, int $amount, string $idempotencyKey, array $context = [], ?CreditSpendGate $gate = null): CreditOperationResult
    {
        $this->assertAmount($amount);
        $this->assertCallerKey($idempotencyKey);

        return $this->apply($account, $idempotencyKey, CreditLedgerEntry::TYPE_CONSUMPTION, ['direct' => true, 'amount' => $amount], $context, function (CreditAccount $credit) use ($account, $amount, $idempotencyKey, $context, $gate) {
            $gate?->assertMaySpend($account, $amount);

            if ($credit->available() < $amount) {
                throw CreditException::insufficient($amount, $credit->available());
            }

            $now = now();
            $reservation = CreditReservation::create([
                'credit_account_id' => $credit->id,
                'account_id' => $credit->account_id,
                'amount' => $amount,
                'status' => CreditReservation::STATUS_CONSUMED,
                'consumed_amount' => $amount,
                'consumed_at' => $now,
                'idempotency_key' => $idempotencyKey,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => isset($context['reference_id']) ? mb_substr((string) $context['reference_id'], 0, 64) : null,
                'metadata' => $context['metadata'] ?? null,
            ]);

            // Row order matters: the hold first (0, +a), then the take (−a, −a).
            return [
                ['type' => CreditLedgerEntry::TYPE_RESERVATION, 'amount' => $amount, 'balance_delta' => 0, 'reserved_delta' => $amount, 'reservation_id' => $reservation->id, 'key_suffix' => ':hold'],
                ['type' => CreditLedgerEntry::TYPE_CONSUMPTION, 'amount' => $amount, 'balance_delta' => -$amount, 'reserved_delta' => -$amount, 'reservation_id' => $reservation->id, 'key_suffix' => ''],
            ];
        });
    }

    /**
     * Phase 8 Task 3 — PARTIAL consumption of an open reservation: consume
     * $amount (1 … what it still holds) under the caller's idempotency key.
     * The reservation stays open until nothing remains, then is consumed.
     */
    public function capture(CreditReservation $reservation, int $amount, string $idempotencyKey, array $context = []): CreditOperationResult
    {
        $this->assertAmount($amount);
        $this->assertCallerKey($idempotencyKey);

        if ($amount > (int) $reservation->amount) {
            throw CreditException::amount("The consumed amount must be between 1 and the reserved {$reservation->amount}.");
        }

        $account = Account::findOrFail($reservation->account_id);

        return $this->apply($account, $idempotencyKey, CreditLedgerEntry::TYPE_CONSUMPTION, ['capture' => $reservation->id, 'amount' => $amount], $context, function (CreditAccount $credit) use ($reservation, $amount) {
            $current = $this->lockOpenReservation($credit, $reservation->id, 'consumed', true);
            $remaining = $current->remaining();

            if ($amount > $remaining) {
                throw CreditException::amount("Reservation #{$current->id} holds {$remaining}; {$amount} cannot be consumed from it.");
            }

            $consumed = (int) ($current->consumed_amount ?? 0) + $amount;
            $now = now();
            $changes = ['consumed_amount' => $consumed, 'updated_at' => $now];

            if ($consumed === (int) $current->amount) {
                $changes += ['status' => CreditReservation::STATUS_CONSUMED, 'consumed_at' => $now];
            }

            $this->compareAndSet($current, $changes, 'consumed');

            return ['type' => CreditLedgerEntry::TYPE_CONSUMPTION, 'amount' => $amount, 'balance_delta' => -$amount, 'reserved_delta' => -$amount, 'reservation_id' => $current->id];
        });
    }

    /**
     * Settle an open reservation (Task 1 contract): $actual (1 … what it
     * still holds; default: all of it) is consumed and any remainder is
     * released in the same transaction; → consumed. A consumed reservation
     * replays; a released one is refused.
     */
    public function consume(CreditReservation $reservation, ?int $actual = null, array $context = []): CreditOperationResult
    {
        if ($actual !== null && ($actual < 1 || $actual > (int) $reservation->amount)) {
            throw CreditException::amount("The consumed amount must be between 1 and the reserved {$reservation->amount}.");
        }

        return $this->transition($reservation, 'consume', $actual, $context);
    }

    /**
     * Give an open reservation's remaining hold back to available;
     * → released. A released one replays; a consumed one is refused.
     * $amount, if given, must equal the remaining hold (a caller's guard
     * against releasing something other than it believes).
     */
    public function release(CreditReservation $reservation, array $context = [], ?int $amount = null): CreditOperationResult
    {
        if ($amount !== null && ($amount < 1 || $amount > (int) $reservation->amount)) {
            throw CreditException::amount("The released amount must be between 1 and the reserved {$reservation->amount}.");
        }

        $result = $this->transition($reservation, 'release', $amount, $context);

        if ($amount !== null && $result->replayed && (int) $result->entry->amount !== $amount) {
            throw CreditException::conflict((string) $result->entry->idempotency_key);
        }

        return $result;
    }

    // ------------------------------------------------------------------ internals

    private function transition(CreditReservation $reservation, string $operation, ?int $amount, array $context): CreditOperationResult
    {
        $account = Account::findOrFail($reservation->account_id);
        $key = "{$operation}:{$reservation->id}";
        // Release: the expected amount is a guard, not a parameter (checked
        // on replay by release()). Consume: the requested amount as given
        // (null = "all that remains").
        $params = ['reservation' => $reservation->id, 'actual' => $operation === 'consume' ? $amount : null];

        return $this->apply($account, $key, $operation === 'consume' ? CreditLedgerEntry::TYPE_CONSUMPTION : CreditLedgerEntry::TYPE_RESERVATION_RELEASE, $params, $context, function (CreditAccount $credit) use ($reservation, $operation, $amount) {
            $current = $this->lockOpenReservation($credit, $reservation->id, $operation === 'consume' ? 'consumed' : 'released', $operation === 'consume');
            $remaining = $current->remaining();
            $now = now();

            if ($operation === 'release') {
                if ($amount !== null && $amount !== $remaining) {
                    throw CreditException::amount("Reservation #{$current->id} holds {$remaining}; a release of {$amount} does not match.");
                }

                $this->compareAndSet($current, ['status' => CreditReservation::STATUS_RELEASED, 'released_at' => $now, 'updated_at' => $now], 'released');

                return ['type' => CreditLedgerEntry::TYPE_RESERVATION_RELEASE, 'amount' => $remaining, 'balance_delta' => 0, 'reserved_delta' => -$remaining, 'reservation_id' => $current->id];
            }

            $actual = $amount ?? $remaining;

            if ($actual > $remaining) {
                throw CreditException::amount("Reservation #{$current->id} holds {$remaining}; {$actual} cannot be consumed from it.");
            }

            $this->compareAndSet($current, ['status' => CreditReservation::STATUS_CONSUMED, 'consumed_amount' => (int) ($current->consumed_amount ?? 0) + $actual, 'consumed_at' => $now, 'updated_at' => $now], 'consumed');

            $rows = [['type' => CreditLedgerEntry::TYPE_CONSUMPTION, 'amount' => $actual, 'balance_delta' => -$actual, 'reserved_delta' => -$actual, 'reservation_id' => $current->id]];

            if ($actual < $remaining) {
                $rows[] = ['type' => CreditLedgerEntry::TYPE_RESERVATION_RELEASE, 'amount' => $remaining - $actual, 'balance_delta' => 0, 'reserved_delta' => -($remaining - $actual), 'reservation_id' => $current->id, 'key_suffix' => ':remainder'];
            }

            return $rows;
        });
    }

    /**
     * The reservation, locked, under the (already held) credit-account lock;
     * refused unless it is this credit account's, open and — when $forUse —
     * not expired. Lock order stays credit_accounts → credit_reservations.
     */
    private function lockOpenReservation(CreditAccount $credit, int $reservationId, string $attempted, bool $forUse): CreditReservation
    {
        $current = CreditReservation::query()
            ->where('credit_account_id', $credit->id)
            ->lockForUpdate()
            ->find($reservationId);

        if (! $current) {
            throw CreditException::tenantMismatch($reservationId);
        }

        if (! $current->isOpen()) {
            throw CreditException::reservationState($current->id, $current->status, $attempted);
        }

        if ($forUse && $current->isExpired()) {
            throw CreditException::reservationExpired($current->id);
        }

        return $current;
    }

    /**
     * Guarded compare-and-set, under the account lock: the row moves only
     * from exactly the state that was validated (open, same consumed_amount).
     */
    private function compareAndSet(CreditReservation $current, array $changes, string $attempted): void
    {
        $moved = CreditReservation::query()->whereKey($current->id)
            ->where('status', CreditReservation::STATUS_RESERVED)
            ->where(fn ($q) => $current->consumed_amount === null
                ? $q->whereNull('consumed_amount')
                : $q->where('consumed_amount', $current->consumed_amount))
            ->update($changes);

        if ($moved !== 1) {
            throw CreditException::reservationState($current->id, (string) CreditReservation::whereKey($current->id)->value('status'), $attempted);
        }
    }

    /**
     * One credit operation: lock → idempotency lookup → $compute (validates,
     * returns the ledger row(s)) → update the account → insert the row(s).
     * The FIRST row is the operation's entry (the one the key identifies).
     *
     * @param  array<string, mixed>  $params  what makes this request what it is (hashed)
     * @param  callable(CreditAccount): array  $compute
     */
    private function apply(Account $account, string $key, string $type, array $params, array $context, callable $compute): CreditOperationResult
    {
        $this->assertKey($key);
        $hash = hash('sha256', json_encode([$type, $params]));
        $credit = $this->creditAccountFor($account);

        try {
            return DB::transaction(function () use ($credit, $key, $type, $hash, $context, $compute) {
                $locked = CreditAccount::query()->whereKey($credit->id)->lockForUpdate()->firstOrFail();

                $existing = CreditLedgerEntry::query()
                    ->where('credit_account_id', $locked->id)
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->replay($existing, $type, $hash);
                }

                $rows = $compute($locked);
                $rows = isset($rows['type']) ? [$rows] : $rows;

                $balance = (int) $locked->balance;
                $reserved = (int) $locked->reserved;
                $entries = [];
                $primary = null;

                foreach ($rows as $i => $row) {
                    $balance += $row['balance_delta'];
                    $reserved += $row['reserved_delta'];

                    if ($balance < 0 || $reserved < 0 || $reserved > $balance) {
                        // Unreachable when $compute validated; kept as the last line of defence.
                        throw CreditException::insufficient((int) $row['amount'], $locked->available());
                    }

                    $entryKey = $key.($row['key_suffix'] ?? ($i === 0 ? '' : ":{$i}"));

                    $entries[] = $entry = CreditLedgerEntry::create([
                        'credit_account_id' => $locked->id,
                        'account_id' => $locked->account_id,
                        'type' => $row['type'],
                        'amount' => $row['amount'],
                        'balance_delta' => $row['balance_delta'],
                        'reserved_delta' => $row['reserved_delta'],
                        'balance_after' => $balance,
                        'reserved_after' => $reserved,
                        'idempotency_key' => $entryKey,
                        'request_hash' => $hash,
                        'reservation_id' => $row['reservation_id'] ?? null,
                        'refund_of_entry_id' => $row['refund_of_entry_id'] ?? null,
                        'reference_type' => $context['reference_type'] ?? null,
                        'reference_id' => isset($context['reference_id']) ? mb_substr((string) $context['reference_id'], 0, 64) : null,
                        'reason' => isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 255) : null,
                        'metadata' => $context['metadata'] ?? null,
                        'source' => $context['source'] ?? self::SOURCE_SYSTEM,
                        'actor_user_id' => $context['actor_user_id'] ?? null,
                        'created_at' => now(),
                    ]);

                    if ($entryKey === $key) {
                        $primary = $entry;
                    }
                }

                CreditAccount::query()->whereKey($locked->id)->update(['balance' => $balance, 'reserved' => $reserved, 'updated_at' => now()]);

                // The operation's entry is the row carrying the key itself.
                $first = $primary ?? $entries[0];

                return new CreditOperationResult($first, false, $first->reservation_id ? CreditReservation::find($first->reservation_id) : null);
            }, 3);
        } catch (QueryException $e) {
            // Backstop: a duplicate key that reached INSERT (unique index) is a replay.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = CreditLedgerEntry::query()->where('credit_account_id', $credit->id)->where('idempotency_key', $key)->first();

            if (! $existing) {
                throw $e;
            }

            return $this->replay($existing, $type, $hash);
        }
    }

    private function replay(CreditLedgerEntry $existing, string $type, string $hash): CreditOperationResult
    {
        if ($existing->type !== $type || ! hash_equals((string) $existing->getRawOriginal('request_hash'), $hash)) {
            throw CreditException::conflict((string) $existing->idempotency_key);
        }

        return new CreditOperationResult($existing, true, $existing->reservation_id ? CreditReservation::find($existing->reservation_id) : null);
    }

    private function assertAmount(int $amount): void
    {
        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            throw CreditException::amount('A credit amount must be a whole number between 1 and '.self::MAX_AMOUNT.'.');
        }
    }

    private function assertKey(string $key): void
    {
        if (trim($key) === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            throw CreditException::invalid('An idempotency key is required (at most '.self::MAX_KEY_LENGTH.' characters).');
        }
    }

    private function assertCallerKey(string $key): void
    {
        foreach (self::DERIVED_KEY_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                throw CreditException::invalid("Idempotency keys starting with '{$prefix}' are reserved.");
            }
        }
    }

    private function expiresAt(array $context): ?CarbonInterface
    {
        if (! isset($context['expires_at'])) {
            return null;
        }

        if (! $context['expires_at'] instanceof CarbonInterface || ! $context['expires_at']->isFuture()) {
            throw CreditException::invalid('A reservation expiry must be a time in the future.');
        }

        return $context['expires_at'];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
