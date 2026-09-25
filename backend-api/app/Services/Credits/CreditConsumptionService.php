<?php

namespace App\Services\Credits;

use App\Models\Account;
use App\Models\CreditReservation;

/**
 * Phase 8 Task 3 — THE spending contract. Every credit-consuming feature
 * (AI in a later task, any other module after it) spends through this class
 * and nothing else. It holds no balance logic of its own: every movement is
 * a CreditService operation (one transaction, credit-account row lock,
 * ledger rows, database-enforced idempotency).
 *
 * What it adds on top of CreditService:
 *   - ACCOUNT BINDING. Every call names the account that pays — the account
 *     the caller already resolved through the platform's tenant rules
 *     (ResolvesTenantAccount / TenantIsolationMiddleware; an Agent reaches a
 *     sub-client only where that model authorizes it). A reservation is
 *     addressed by id and must belong to exactly that account
 *     (tenant_mismatch otherwise); there is no pooling, no fallback to a
 *     parent/Agent account, and origin/source never grants anything.
 *   - METADATA HYGIENE. Ledger/reservation metadata is flat, small and
 *     scalar — an identifier trail, never prompt/content data or secrets.
 *   - The optional PRODUCT GATE (CreditSpendGate) on reserve/spend.
 *
 *   reserve(acct, a, key)         hold a (a <= available)            → reservation
 *   consume(acct, rid, a, key)    partial consumption of a hold      → consumption
 *   settle(acct, rid, ?a)         consume a (default: all remaining),
 *                                 release the rest; terminal         → consumption
 *   release(acct, rid, ?a)        give the remaining hold back;
 *                                 terminal                           → reservation_release
 *   spend(acct, a, key)           direct consumption, no prior hold  → consumption
 *
 * Suggested key namespace: "{module}:{operation}:{external id}", e.g.
 * ai:reservation:<request id>, ai:consume:<request id>:<step>,
 * ai:request:<request id> (spend). consume:/release: prefixes are reserved.
 */
final class CreditConsumptionService
{
    private const MAX_METADATA_KEYS = 20;

    private const MAX_METADATA_VALUE_LENGTH = 191;

    public function __construct(private readonly CreditService $credits)
    {
    }

    public function reserve(Account $account, int $amount, string $idempotencyKey, array $context = [], ?CreditSpendGate $gate = null): CreditOperationResult
    {
        $this->assertMetadata($context);

        return $this->credits->reserve($account, $amount, $idempotencyKey, $context, $gate);
    }

    public function spend(Account $account, int $amount, string $idempotencyKey, array $context = [], ?CreditSpendGate $gate = null): CreditOperationResult
    {
        $this->assertMetadata($context);

        return $this->credits->spend($account, $amount, $idempotencyKey, $context, $gate);
    }

    public function consume(Account $account, int $reservationId, int $amount, string $idempotencyKey, array $context = []): CreditOperationResult
    {
        $this->assertMetadata($context);

        return $this->credits->capture($this->reservationOf($account, $reservationId), $amount, $idempotencyKey, $context);
    }

    public function settle(Account $account, int $reservationId, ?int $amount = null, array $context = []): CreditOperationResult
    {
        $this->assertMetadata($context);

        return $this->credits->consume($this->reservationOf($account, $reservationId), $amount, $context);
    }

    public function release(Account $account, int $reservationId, ?int $amount = null, array $context = []): CreditOperationResult
    {
        $this->assertMetadata($context);

        return $this->credits->release($this->reservationOf($account, $reservationId), $context, $amount);
    }

    /**
     * Release open reservations whose expires_at has passed, oldest first.
     * Each is an ordinary release (key release:{id}): a concurrent caller
     * release, a caller settle or a second cleanup run simply wins or
     * replays — never a second effect.
     *
     * @return array{due: int, released: int, skipped: int}
     */
    public function releaseExpired(int $limit = 500, bool $dryRun = false): array
    {
        $due = CreditReservation::query()
            ->where('status', CreditReservation::STATUS_RESERVED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $stats = ['due' => $due->count(), 'released' => 0, 'skipped' => 0];

        if ($dryRun) {
            return $stats;
        }

        foreach ($due as $reservation) {
            try {
                $result = $this->credits->release($reservation, [
                    'reason' => 'Reservation expired',
                    'reference_type' => $reservation->reference_type,
                    'reference_id' => $reservation->reference_id,
                    'source' => CreditService::SOURCE_SYSTEM,
                ]);
                $stats[$result->replayed ? 'skipped' : 'released']++;
            } catch (CreditException $e) {
                // Settled or released by its owner in the meantime.
                if ($e->reason !== CreditException::RESERVATION_ALREADY_TERMINAL) {
                    throw $e;
                }
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    private function reservationOf(Account $account, int $reservationId): CreditReservation
    {
        $reservation = CreditReservation::query()->find($reservationId);

        if (! $reservation) {
            throw CreditException::reservationNotFound($reservationId);
        }

        if ((int) $reservation->account_id !== (int) $account->id) {
            throw CreditException::tenantMismatch($reservationId);
        }

        return $reservation;
    }

    private function assertMetadata(array $context): void
    {
        $metadata = $context['metadata'] ?? null;

        if ($metadata === null) {
            return;
        }

        if (! is_array($metadata) || count($metadata) > self::MAX_METADATA_KEYS) {
            throw CreditException::invalid('Credit metadata must be a small key/value map.');
        }

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || ! (is_scalar($value) || $value === null) || (is_string($value) && mb_strlen($value) > self::MAX_METADATA_VALUE_LENGTH)) {
                throw CreditException::invalid('Credit metadata holds identifiers only: flat scalar values of at most '.self::MAX_METADATA_VALUE_LENGTH.' characters.');
            }
        }
    }
}
