<?php

namespace App\Services\Education;

use App\Models\Account;
use App\Models\Collections\CollectionChargeAssignment;
use App\Models\Collections\CollectionChargeItem;
use App\Models\Education\EducationStudent;
use App\Services\Collections\BillingCollectionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Phase 11 Task 4 — the Education ADAPTER over the generic Billing & Collections core.
 *
 * It does exactly two things: (1) resolve an Education student to the CRM contact the core charges
 * (the core never sees a student), and (2) pin every call to the `education` scope so Education's charges
 * and payments stay separate from any other module's records for the same contact. All money, balance,
 * locking and idempotency logic lives in BillingCollectionService — nothing is copied here.
 */
class EducationFeeService
{
    /** The opaque tag Education passes to the core. */
    public const SCOPE = 'education';

    public function __construct(private readonly BillingCollectionService $core)
    {
    }

    public function core(): BillingCollectionService
    {
        return $this->core;
    }

    // ---- charge catalogue ----

    /** @return \Illuminate\Database\Eloquent\Builder<CollectionChargeItem> */
    public function items(Account $account)
    {
        return $this->core->items($account, self::SCOPE);
    }

    public function item(Account $account, int $id): CollectionChargeItem
    {
        return $this->core->item($account, self::SCOPE, $id);
    }

    /** @param array<string, mixed> $data */
    public function createItem(Account $account, array $data): CollectionChargeItem
    {
        return $this->core->createItem($account, self::SCOPE, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateItem(Account $account, CollectionChargeItem $item, array $data): CollectionChargeItem
    {
        return $this->core->updateItem($account, self::SCOPE, $item, $data);
    }

    // ---- per student ----

    /** @param array<string, mixed> $data charge_item_id, amount_due?, due_date? */
    public function assign(Account $account, EducationStudent $student, array $data, ?int $userId = null): CollectionChargeAssignment
    {
        return $this->core->assign($account, self::SCOPE, $this->contactId($account, $student), $data, $userId);
    }

    /** @return array{data: list<array<string, mixed>>, summary: array<string, mixed>} */
    public function ledger(Account $account, EducationStudent $student, ?string $status = null): array
    {
        return $this->core->ledger($account, self::SCOPE, $this->contactId($account, $student), $status);
    }

    /** The student's assignment, or 404 (another student's, another account's, another module's). */
    public function assignment(Account $account, EducationStudent $student, int $id): CollectionChargeAssignment
    {
        return $this->core->assignment($account, self::SCOPE, $id, $this->contactId($account, $student));
    }

    /** @param array<string, mixed> $data amount_due?, due_date? */
    public function updateAssignment(Account $account, EducationStudent $student, int $assignmentId, array $data): CollectionChargeAssignment
    {
        return $this->core->updateAssignment($account, self::SCOPE, $this->assignment($account, $student, $assignmentId), $data);
    }

    /** Void a student's charge (only while it has no payment). The rules live in the core. */
    public function cancelFee(Account $account, EducationStudent $student, int $assignmentId, ?string $reason = null, ?int $userId = null): CollectionChargeAssignment
    {
        return $this->core->cancelAssignment($account, self::SCOPE, $this->assignment($account, $student, $assignmentId), $reason, $userId);
    }

    public function reinstateFee(Account $account, EducationStudent $student, int $assignmentId): CollectionChargeAssignment
    {
        return $this->core->reinstateAssignment($account, self::SCOPE, $this->assignment($account, $student, $assignmentId));
    }

    /**
     * @param array<string, mixed> $data amount, payment_date?, payment_method?, reference?, idempotency_key?
     *
     * @return array{payment: \App\Models\Collections\CollectionPayment, idempotent: bool, assignment: array<string, mixed>}
     */
    public function recordPayment(Account $account, EducationStudent $student, int $assignmentId, array $data, ?int $userId = null): array
    {
        return $this->core->recordPayment($account, self::SCOPE, $this->assignment($account, $student, $assignmentId), $data, $userId);
    }

    /** @param array<string, mixed> $filters from, to, payment_method, charge_assignment_id */
    public function studentPayments(Account $account, EducationStudent $student, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->core->paymentHistory($account, self::SCOPE, ['contact_id' => $this->contactId($account, $student)] + $filters, $perPage);
    }

    /** @param array<string, mixed> $filters */
    public function payments(Account $account, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->core->paymentHistory($account, self::SCOPE, $filters, $perPage);
    }

    /** The one Education-specific step: student → CRM contact, only inside the account. */
    private function contactId(Account $account, EducationStudent $student): int
    {
        abort_if($student->account_id !== $account->id, 404, 'Student not found.');

        return (int) $student->contact_id;
    }
}
