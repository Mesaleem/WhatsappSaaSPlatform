<?php

namespace App\Services\Crm\Sales;

use App\Models\Account;
use App\Models\CrmDocument;
use App\Models\CrmPayment;
use App\Models\CrmPaymentAllocation;
use App\Services\Crm\CrmActivityService;
use App\Services\Crm\CrmTenantLinks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 3 — payments the tenant receives, and how they pay invoices.
 *
 * ONE PATH for manual and gateway payments (record()): one transaction that locks every
 * invoice it touches (in id order, so two payments never deadlock), re-reads each
 * balance under the lock, refuses an allocation above it, writes the payment + its
 * allocations, then recomputes each invoice from its ACTIVE allocations
 * (CrmDocumentService::recomputeInvoice). Money is integer paise throughout.
 *
 * NEVER EDITED. A wrong payment is reversed (reverse()): the payment keeps its row and
 * gains reversed_at / by / reason, its allocations are stamped reversed, and the
 * invoices are recomputed — history is never rewritten or deleted.
 *
 * IDEMPOTENT. An optional idempotency_key (manual: a retried submit) and the gateway's
 * payment id (a webhook delivered twice, or the browser callback AND the webhook) are
 * unique per tenant, so the same payment is recorded once; a repeat returns the first.
 *
 * Part of a payment not allocated stays on it as a credit (unallocated) and can be
 * applied to another invoice later (allocate()).
 */
class CrmPaymentService
{
    public function __construct(
        private readonly CrmDocumentCalculator $calc,
        private readonly CrmDocumentNumbering $numbering,
        private readonly CrmDocumentService $documents,
        private readonly CrmActivityService $activities,
    ) {
    }

    /**
     * @param array{amount: mixed, method: string, paid_on?: string|null, reference?: string|null, notes?: string|null,
     *              contact_id?: int|null, crm_company_id?: int|null, idempotency_key?: string|null,
     *              allocations?: list<array{crm_document_id: int, amount: mixed}>,
     *              source?: string, gateway?: string|null, gateway_payment_id?: string|null} $data
     */
    public function record(Account $account, array $data, ?int $actorUserId): CrmPayment
    {
        $amount = $this->positive((string) ($data['amount'] ?? ''), 'amount');
        if (! in_array($data['method'] ?? '', CrmPayment::METHODS, true)) {
            throw ValidationException::withMessages(['method' => ['The selected payment method is invalid.']]);
        }

        $existing = $this->findRepeat($account->id, $data);
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($account, $data, $amount, $actorUserId): CrmPayment {
                $allocations = $this->lockAllocationTargets($account->id, $data['allocations'] ?? []);
                $allocated = array_sum(array_column($allocations, 'minor'));
                if ($allocated > $amount) {
                    throw ValidationException::withMessages(['allocations' => ['The allocations add up to more than the payment.']]);
                }

                [$contactId, $companyId] = $this->customerOf($account->id, $data, $allocations);
                $paidOn = Carbon::parse($data['paid_on'] ?? now()->toDateString());
                if ($paidOn->isAfter(now()->endOfDay())) {
                    throw ValidationException::withMessages(['paid_on' => ['The payment date cannot be in the future.']]);
                }
                $numbered = $this->numbering->next($account->id, 'receipt', 'RCPT', $paidOn);

                $payment = CrmPayment::create([
                    'account_id' => $account->id,
                    'number' => $numbered['number'],
                    'financial_year' => $numbered['financial_year'],
                    'contact_id' => $contactId,
                    'crm_company_id' => $companyId,
                    'amount' => $this->calc->format($amount),
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'paid_on' => $paidOn->toDateString(),
                    'status' => CrmPayment::STATUS_SUCCESSFUL,
                    'source' => $data['source'] ?? 'manual',
                    'gateway' => $data['gateway'] ?? null,
                    'gateway_payment_id' => $data['gateway_payment_id'] ?? null,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'recorded_by_user_id' => $actorUserId,
                ]);

                $this->applyAllocations($payment, $allocations);
                $this->activities->logEvent($account->id, "Payment {$payment->number} of {$payment->amount} received", [
                    'contact_id' => $contactId, 'crm_company_id' => $companyId,
                ], $actorUserId);

                return $payment->fresh('allocations');
            }, 3);
        } catch (QueryException $e) {
            // A concurrent duplicate (same idempotency key / gateway payment id) lost the race.
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate entry')) {
                $repeat = $this->findRepeat($account->id, $data);
                if ($repeat) {
                    return $repeat;
                }
            }
            throw $e;
        }
    }

    /**
     * Apply some of a payment's unallocated credit to invoices.
     *
     * @param list<array{crm_document_id: int, amount: mixed}> $allocations
     */
    public function allocate(CrmPayment $payment, array $allocations, ?int $actorUserId): CrmPayment
    {
        return DB::transaction(function () use ($payment, $allocations): CrmPayment {
            $payment = CrmPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->reversed_at !== null) {
                throw ValidationException::withMessages(['payment' => ['A reversed payment cannot be allocated.']]);
            }
            $targets = $this->lockAllocationTargets($payment->account_id, $allocations);
            if (array_sum(array_column($targets, 'minor')) > $this->unallocatedMinor($payment)) {
                throw ValidationException::withMessages(['allocations' => ['That is more than this payment has left to allocate.']]);
            }
            $this->applyAllocations($payment, $targets);

            return $payment->fresh('allocations');
        });
    }

    public function reverse(CrmPayment $payment, string $reason, ?int $actorUserId): CrmPayment
    {
        return DB::transaction(function () use ($payment, $reason, $actorUserId): CrmPayment {
            $payment = CrmPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->reversed_at !== null) {
                throw ValidationException::withMessages(['payment' => ['This payment has already been reversed.']]);
            }

            $invoiceIds = $payment->allocations()->active()->orderBy('crm_document_id')->pluck('crm_document_id')->unique()->values()->all();
            $invoices = CrmDocument::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get();

            $payment->allocations()->active()->update(['reversed_at' => now(), 'updated_at' => now()]);
            $payment->forceFill([
                'status' => CrmPayment::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by_user_id' => $actorUserId,
                'reversal_reason' => $reason,
            ])->save();

            foreach ($invoices as $invoice) {
                $this->documents->recomputeInvoice($invoice);
            }

            $this->activities->logEvent($payment->account_id, "Payment {$payment->number} reversed: {$reason}", [
                'contact_id' => $payment->contact_id, 'crm_company_id' => $payment->crm_company_id,
            ], $actorUserId);

            return $payment->fresh('allocations');
        });
    }

    public function unallocatedMinor(CrmPayment $payment): int
    {
        if ($payment->reversed_at !== null) {
            return 0;
        }
        $allocated = $this->calc->toMinor((string) ($payment->allocations()->active()->sum('amount') ?: '0'));

        return $this->calc->toMinor((string) $payment->amount) - $allocated;
    }

    public function unallocated(CrmPayment $payment): string
    {
        return $this->calc->format($this->unallocatedMinor($payment));
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, mixed> $data */
    private function findRepeat(int $accountId, array $data): ?CrmPayment
    {
        if (! empty($data['gateway_payment_id']) && ! empty($data['gateway'])) {
            $hit = CrmPayment::query()->forAccount($accountId)->where('gateway', $data['gateway'])->where('gateway_payment_id', $data['gateway_payment_id'])->first();
            if ($hit) {
                return $hit->load('allocations');
            }
        }
        if (! empty($data['idempotency_key'])) {
            $hit = CrmPayment::query()->forAccount($accountId)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($hit) {
                return $hit->load('allocations');
            }
        }

        return null;
    }

    /**
     * Lock the invoices (id order) and check each allocation fits its CURRENT balance.
     *
     * @param list<array{crm_document_id: int, amount: mixed}> $allocations
     * @return list<array{invoice: CrmDocument, minor: int}>
     */
    private function lockAllocationTargets(int $accountId, array $allocations): array
    {
        $byInvoice = [];
        foreach (array_values($allocations) as $i => $row) {
            $id = (int) ($row['crm_document_id'] ?? 0);
            $minor = $this->positive((string) ($row['amount'] ?? ''), "allocations.{$i}.amount");
            $byInvoice[$id] = ($byInvoice[$id] ?? 0) + $minor;
        }
        ksort($byInvoice);

        $out = [];
        foreach ($byInvoice as $id => $minor) {
            $invoice = CrmDocument::query()->forAccount($accountId)->whereKey($id)->lockForUpdate()->first();
            if (! $invoice || ! $invoice->isPayable()) {
                throw ValidationException::withMessages(['allocations' => ['Payments can only be applied to issued invoices with a balance.']]);
            }
            if ($minor > $this->calc->toMinor((string) $invoice->balance_due)) {
                throw ValidationException::withMessages(['allocations' => ["That is more than the balance of {$invoice->number} ({$invoice->balance_due})."]]);
            }
            $out[] = ['invoice' => $invoice, 'minor' => $minor];
        }

        return $out;
    }

    /** @param list<array{invoice: CrmDocument, minor: int}> $targets */
    private function applyAllocations(CrmPayment $payment, array $targets): void
    {
        foreach ($targets as $target) {
            CrmPaymentAllocation::create([
                'account_id' => $payment->account_id,
                'crm_payment_id' => $payment->id,
                'crm_document_id' => $target['invoice']->id,
                'amount' => $this->calc->format($target['minor']),
            ]);
            $this->documents->recomputeInvoice($target['invoice']);
        }
    }

    /**
     * The payer: given ids (tenant-checked), else the first invoice's customer.
     *
     * @param array<string, mixed> $data
     * @param list<array{invoice: CrmDocument, minor: int}> $allocations
     * @return array{0: ?int, 1: ?int}
     */
    private function customerOf(int $accountId, array $data, array $allocations): array
    {
        $contactId = isset($data['contact_id']) ? (int) $data['contact_id'] : null;
        $companyId = isset($data['crm_company_id']) ? (int) $data['crm_company_id'] : null;
        CrmTenantLinks::assertBelong($accountId, ['contact_id' => $contactId, 'crm_company_id' => $companyId]);

        if ($contactId === null && $companyId === null && $allocations !== []) {
            $contactId = $allocations[0]['invoice']->contact_id;
            $companyId = $allocations[0]['invoice']->crm_company_id;
        }

        return [$contactId, $companyId];
    }

    private function positive(string $value, string $key): int
    {
        $minor = $this->calc->toMinor($value, $key);
        if ($minor <= 0) {
            throw ValidationException::withMessages([$key => ['The amount must be greater than zero.']]);
        }

        return $minor;
    }
}
