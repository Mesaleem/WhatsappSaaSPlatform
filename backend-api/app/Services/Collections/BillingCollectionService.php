<?php

namespace App\Services\Collections;

use App\Models\Account;
use App\Models\Collections\CollectionChargeAssignment;
use App\Models\Collections\CollectionChargeItem;
use App\Models\Collections\CollectionPayment;
use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 11 Task 4 — the generic Billing & Collections core: charge items, assigning a charge to a CRM
 * contact, recording payments, outstanding balance, history. Industry-agnostic.
 *
 *   Education adapter ─┐
 *   (future adapters) ─┼──►  BillingCollectionService  ──►  collection_charge_items / _assignments / _payments
 *   Journey/API/import ┘
 *
 * What a caller supplies: an already-resolved target Account, an opaque `$scope` string that partitions
 * the account's records per calling module (the core has no list of valid scopes and attaches no meaning
 * to one), and a CRM `contact_id` as the customer. How a domain entity (a student…) maps to a contact is
 * the ADAPTER's job. Authorization happens at the edge, never here and never by caller origin.
 *
 * Money: DECIMAL(12,2) in the database, strings ("1500.00") on the wire, INTEGER MINOR UNITS in every
 * calculation — never floats. paid / outstanding / status are derived from the payment rows on every read;
 * no running balance is stored.
 *
 * recordPayment() locks the assignment row (SELECT … FOR UPDATE), re-reads the paid total under that lock
 * and only then checks "amount ≤ outstanding" and inserts, in one transaction: concurrent payments
 * serialize and the later one sees the earlier. An optional idempotency key returns the original payment
 * for a retried submission. Payments are append-only — nothing here updates or deletes one.
 */
class BillingCollectionService
{
    /** Largest value DECIMAL(12,2) holds, in minor units. */
    private const MAX_MINOR = 99_999_999_999_99;

    /** A payment may be dated up to a day ahead (server clock vs the customer's), never further. */
    private const FUTURE_DAYS_ALLOWED = 1;

    private const LEDGER_MAX = 200;

    /** @var list<string> fields an assignment update must refuse (see updateAssignment) */
    private const IMMUTABLE_ON_UPDATE = ['charge_item_id', 'contact_id', 'account_id', 'status', 'amount_paid', 'outstanding', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason'];

    // ------------------------------------------------------------------ money

    /** Parse an amount into integer minor units; at most 2 decimals, no exponent, no sign. */
    public function toMinor(mixed $value, string $key = 'amount'): int
    {
        if (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }
        $string = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $string, $m)) {
            throw ValidationException::withMessages([$key => ['Enter an amount with at most 2 decimal places.']]);
        }
        $minor = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($minor > self::MAX_MINOR) {
            throw ValidationException::withMessages([$key => ['That amount is too large.']]);
        }

        return $minor;
    }

    public function format(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function positiveMinor(mixed $value, string $key): int
    {
        $minor = $this->toMinor($value, $key);
        if ($minor <= 0) {
            throw ValidationException::withMessages([$key => ['The amount must be greater than zero.']]);
        }

        return $minor;
    }

    /** A stored DECIMAL as read back (string on MariaDB, float on SQLite) → minor units. */
    private function storedMinor(mixed $stored): int
    {
        return $this->toMinor(number_format((float) $stored, 2, '.', ''));
    }

    // ------------------------------------------------------------------ lookups (account + scope)

    public function item(Account $account, string $scope, int $id): CollectionChargeItem
    {
        $item = CollectionChargeItem::query()->forAccount($account->id)->where('scope', $scope)->find($id);
        abort_if(! $item, 404, 'Charge not found.');

        return $item;
    }

    public function assignment(Account $account, string $scope, int $id, ?int $contactId = null): CollectionChargeAssignment
    {
        $assignment = CollectionChargeAssignment::query()->forAccount($account->id)
            ->whereIn('charge_item_id', $this->scopeItemIds($account, $scope))
            ->when($contactId !== null, fn ($q) => $q->where('contact_id', $contactId))
            ->with('item')->find($id);
        abort_if(! $assignment, 404, 'Charge assignment not found.');

        return $assignment;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<CollectionChargeItem> */
    public function items(Account $account, string $scope)
    {
        return CollectionChargeItem::query()->forAccount($account->id)->where('scope', $scope);
    }

    // ------------------------------------------------------------------ charge items

    /** @param array<string, mixed> $data name, description?, amount, frequency?, status? */
    public function createItem(Account $account, string $scope, array $data): CollectionChargeItem
    {
        $name = trim((string) ($data['name'] ?? ''));
        $this->assertItemNameFree($account, $scope, $name, null);
        $minor = $this->positiveMinor($data['amount'] ?? null, 'amount');

        return CollectionChargeItem::create([
            'account_id' => $account->id,
            'scope' => $scope,
            'name' => $name,
            'description' => $this->blankToNull($data['description'] ?? null),
            'amount' => $this->format($minor),
            'frequency' => $data['frequency'] ?? 'one_time',
            'status' => $data['status'] ?? 'active',
        ]);
    }

    /**
     * Existing assignments keep their own `amount_due` snapshot: changing an item's price never rewrites
     * what customers already owe.
     *
     * @param array<string, mixed> $data any of name, description, amount, frequency, status
     */
    public function updateItem(Account $account, string $scope, CollectionChargeItem $item, array $data): CollectionChargeItem
    {
        $item = $this->item($account, $scope, $item->id);
        $attributes = array_intersect_key($data, array_flip(['name', 'description', 'amount', 'frequency', 'status']));
        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = trim((string) $attributes['name']);
            $this->assertItemNameFree($account, $scope, $attributes['name'], $item->getKey());
        }
        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = $this->blankToNull($attributes['description']);
        }
        if (array_key_exists('amount', $attributes)) {
            $attributes['amount'] = $this->format($this->positiveMinor($attributes['amount'], 'amount'));
        }
        $item->fill($attributes)->save();

        return $item->fresh();
    }

    // ------------------------------------------------------------------ assignments

    /**
     * Assign a charge item to a contact. `amount_due` defaults to the item's amount (a discount or custom
     * amount is simply another amount_due). The same item + due date cannot be assigned twice to one
     * contact. Archived items cannot be assigned. The contact must belong to the account.
     *
     * @param array<string, mixed> $data charge_item_id, amount_due?, due_date?
     */
    public function assign(Account $account, string $scope, int $contactId, array $data, ?int $userId = null): CollectionChargeAssignment
    {
        return DB::transaction(function () use ($account, $scope, $contactId, $data, $userId) {
            // The contact must be this account's; the row lock serializes assignments per contact so the
            // duplicate check below cannot race.
            $contact = Contact::query()->forAccount($account->id)->whereKey($contactId)->lockForUpdate()->first();
            abort_if(! $contact, 404, 'Customer not found.');

            $item = CollectionChargeItem::query()->forAccount($account->id)->where('scope', $scope)->find($data['charge_item_id'] ?? 0);
            if (! $item) {
                throw ValidationException::withMessages(['charge_item_id' => ['This charge does not exist.']]);
            }
            if ($item->status !== 'active') {
                throw ValidationException::withMessages(['charge_item_id' => ['This charge is archived and cannot be assigned.']]);
            }
            $minor = isset($data['amount_due']) && $data['amount_due'] !== ''
                ? $this->positiveMinor($data['amount_due'], 'amount_due')
                : $this->storedMinor($item->amount);
            $due = $this->parseDueDate($data['due_date'] ?? null);

            $duplicate = CollectionChargeAssignment::query()->forAccount($account->id)
                ->where('contact_id', $contact->id)->where('charge_item_id', $item->id)
                ->whereNull('cancelled_at') // a voided assignment may be assigned again
                ->when($due === null, fn ($q) => $q->whereNull('due_date'), fn ($q) => $q->where('due_date', $due))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['charge_item_id' => ['This charge is already assigned to the customer for that due date.']]);
            }

            return CollectionChargeAssignment::create([
                'account_id' => $account->id,
                'contact_id' => $contact->id,
                'charge_item_id' => $item->id,
                'amount_due' => $this->format($minor),
                'due_date' => $due,
                'assigned_by_user_id' => $userId,
            ])->load('item');
        });
    }

    /**
     * Change an assignment's amount and/or due date. Invariant: total payments ≤ amount due. The paid total is
     * re-read under the same row lock recordPayment() takes, so a payment and an update serialize: whichever runs
     * second sees the other's effect, and a reduction below the paid total is a 422 that writes nothing.
     *
     * @param array<string, mixed> $data amount_due?, due_date?
     */
    public function updateAssignment(Account $account, string $scope, CollectionChargeAssignment $assignment, array $data): CollectionChargeAssignment
    {
        $this->assignment($account, $scope, $assignment->id);

        // Only the amount due and the due date are adjustable. The charge item, the customer, the account, and the
        // derived paid / outstanding / status define what the assignment MEANS financially and are never writable
        // here: refuse loudly (and before writing anything) instead of silently ignoring.
        foreach (self::IMMUTABLE_ON_UPDATE as $key) {
            if (array_key_exists($key, $data)) {
                throw ValidationException::withMessages([$key => ['This field cannot be changed on an existing charge assignment.']]);
            }
        }

        return DB::transaction(function () use ($account, $assignment, $data) {
            $locked = CollectionChargeAssignment::query()->forAccount($account->id)->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertNotCancelled($locked, 'status');
            $attributes = [];
            if (array_key_exists('amount_due', $data)) {
                $minor = $this->positiveMinor($data['amount_due'], 'amount_due');
                if ($minor < $this->paidMinor($account, $locked->id)) {
                    throw ValidationException::withMessages(['amount_due' => ['The amount due cannot be less than what has already been paid.']]);
                }
                $attributes['amount_due'] = $this->format($minor);
            }
            if (array_key_exists('due_date', $data)) {
                $attributes['due_date'] = $this->parseDueDate($data['due_date']);
            }
            $locked->fill($attributes)->save();

            return $locked->fresh('item');
        });
    }

    // ------------------------------------------------------------------ lifecycle (cancel / reinstate)

    /**
     * Void an assignment. Lifecycle: open | partially_paid | paid | overdue are DERIVED from payments; `cancelled`
     * is the one stored state. Allowed only while the assignment has NO payment — there are no refunds, so money
     * recorded against a charge can never be voided (a partially paid or paid charge is a 422). The paid-total
     * check runs under the assignment's row lock, the same one recordPayment() takes, so a cancel and a payment
     * serialize: whichever runs second sees the other (a payment on a cancelled charge, or a cancel of a charge
     * with a payment, is refused). Payment rows are never touched.
     */
    public function cancelAssignment(Account $account, string $scope, CollectionChargeAssignment $assignment, ?string $reason = null, ?int $userId = null): CollectionChargeAssignment
    {
        $this->assignment($account, $scope, $assignment->id);
        $reason = $this->blankToNull($reason);
        if ($reason !== null && mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => ['The reason may not be longer than 255 characters.']]);
        }

        return DB::transaction(function () use ($account, $assignment, $reason, $userId) {
            $locked = CollectionChargeAssignment::query()->forAccount($account->id)->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($locked->cancelled_at !== null) {
                throw ValidationException::withMessages(['status' => ['This charge is already cancelled.']]);
            }
            if ($this->paidMinor($account, $locked->id) > 0) {
                throw ValidationException::withMessages(['status' => ['A charge that has payments cannot be cancelled. Refunds are not supported.']]);
            }
            $locked->forceFill(['cancelled_at' => now(), 'cancelled_by_user_id' => $userId, 'cancellation_reason' => $reason])->save();

            return $locked->fresh('item');
        });
    }

    /**
     * Undo a cancellation (cancelled → open). Refused if the assignment is not cancelled, or if an active
     * assignment of the same charge + due date now exists for the customer (it would become a duplicate).
     */
    public function reinstateAssignment(Account $account, string $scope, CollectionChargeAssignment $assignment): CollectionChargeAssignment
    {
        $this->assignment($account, $scope, $assignment->id);

        return DB::transaction(function () use ($account, $assignment) {
            // Same lock order as assign(): the customer row first (it serializes the duplicate check), then the assignment.
            Contact::query()->forAccount($account->id)->whereKey($assignment->contact_id)->lockForUpdate()->firstOrFail();
            $locked = CollectionChargeAssignment::query()->forAccount($account->id)->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($locked->cancelled_at === null) {
                throw ValidationException::withMessages(['status' => ['Only a cancelled charge can be reinstated.']]);
            }
            $duplicate = CollectionChargeAssignment::query()->forAccount($account->id)
                ->where('contact_id', $locked->contact_id)->where('charge_item_id', $locked->charge_item_id)
                ->whereNull('cancelled_at')->where('id', '!=', $locked->id)
                ->when($locked->due_date === null, fn ($q) => $q->whereNull('due_date'), fn ($q) => $q->where('due_date', $locked->due_date->toDateString()))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['status' => ['This charge is already assigned to the customer for that due date, so the cancelled one cannot be reinstated.']]);
            }
            $locked->forceFill(['cancelled_at' => null, 'cancelled_by_user_id' => null, 'cancellation_reason' => null])->save();

            return $locked->fresh('item');
        });
    }

    private function assertNotCancelled(CollectionChargeAssignment $assignment, string $key): void
    {
        if ($assignment->cancelled_at !== null) {
            throw ValidationException::withMessages([$key => ['This charge is cancelled. Reinstate it first.']]);
        }
    }

    // ------------------------------------------------------------------ payments

    /**
     * Record a payment against one assignment. A rejected payment writes nothing.
     *
     * @param array<string, mixed> $data amount, payment_date?, payment_method?, reference?, idempotency_key?
     *
     * @return array{payment: CollectionPayment, idempotent: bool, assignment: array<string, mixed>}
     */
    public function recordPayment(Account $account, string $scope, CollectionChargeAssignment $assignment, array $data, ?int $userId = null): array
    {
        $assignment = $this->assignment($account, $scope, $assignment->id);
        $minor = $this->positiveMinor($data['amount'] ?? null, 'amount');
        $date = $this->parseDate($data['payment_date'] ?? Carbon::today()->toDateString(), 'payment_date');
        $method = $this->blankToNull($data['payment_method'] ?? null);
        if ($method !== null && ! in_array($method, CollectionPayment::METHODS, true)) {
            throw ValidationException::withMessages(['payment_method' => ['Choose a valid payment method.']]);
        }
        $reference = $this->blankToNull($data['reference'] ?? null);
        if ($reference !== null && mb_strlen($reference) > 120) {
            throw ValidationException::withMessages(['reference' => ['The reference may not be longer than 120 characters.']]);
        }
        $key = $this->blankToNull($data['idempotency_key'] ?? null);
        if ($key !== null && ! preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => ['The idempotency key must be 8–64 letters, digits or . _ : -']]);
        }

        $payment = null;
        $idempotent = false;
        try {
            DB::transaction(function () use ($account, $assignment, $minor, $date, $method, $reference, $key, $userId, &$payment, &$idempotent) {
                // The lock that makes concurrent payments safe: later payers wait here, then see the earlier ones.
                $locked = CollectionChargeAssignment::query()->forAccount($account->id)->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

                if ($key !== null) {
                    $existing = CollectionPayment::query()->forAccount($account->id)->where('idempotency_key', $key)->first();
                    if ($existing) {
                        if ($existing->charge_assignment_id !== $locked->id || $this->storedMinor($existing->amount) !== $minor || $existing->payment_date->toDateString() !== $date) {
                            throw ValidationException::withMessages(['idempotency_key' => ['This key was already used for a different payment.']]);
                        }
                        $payment = $existing;
                        $idempotent = true;

                        return;
                    }
                }

                $this->assertNotCancelled($locked, 'amount');

                $outstanding = $this->storedMinor($locked->amount_due) - $this->paidMinor($account, $locked->id);
                if ($minor > $outstanding) {
                    throw ValidationException::withMessages(['amount' => [
                        $outstanding <= 0 ? 'This charge is already fully paid.' : 'The payment exceeds the outstanding amount of '.$this->format($outstanding).'.',
                    ]]);
                }

                $payment = CollectionPayment::create([
                    'account_id' => $account->id,
                    'charge_assignment_id' => $locked->id,
                    'amount' => $this->format($minor),
                    'payment_date' => $date,
                    'payment_method' => $method,
                    'reference' => $reference,
                    'idempotency_key' => $key,
                    'recorded_by_user_id' => $userId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same key reached the insert together: the database picked one — return it.
            $payment = CollectionPayment::query()->forAccount($account->id)->where('idempotency_key', $key)->firstOrFail();
            $idempotent = true;
        }

        return ['payment' => $payment, 'idempotent' => $idempotent, 'assignment' => $this->present($account, $assignment->fresh('item'))];
    }

    // ------------------------------------------------------------------ reads

    /**
     * One contact's charges in a scope: every assignment with paid / outstanding / derived status, plus
     * totals from the records. `$status` filters the rows (not the totals).
     *
     * @return array{data: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function ledger(Account $account, string $scope, int $contactId, ?string $status = null): array
    {
        $assignments = CollectionChargeAssignment::query()->forAccount($account->id)->where('contact_id', $contactId)
            ->whereIn('charge_item_id', $this->scopeItemIds($account, $scope))
            ->with('item')->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id')->limit(self::LEDGER_MAX)->get();
        $rows = $this->presentMany($account, $assignments);

        $sum = ['due' => 0, 'paid' => 0, 'outstanding' => 0, 'overdue' => 0];
        $active = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'cancelled') {
                continue; // a voided charge is owed by no one: it is listed but never counted
            }
            $active++;
            $sum['due'] += $row['_due'];
            $sum['paid'] += $row['_paid'];
            $sum['outstanding'] += $row['_outstanding'];
            if ($row['status'] === 'overdue') {
                $sum['overdue'] += $row['_outstanding'];
            }
        }

        return [
            'data' => array_values(array_map(fn ($r) => $this->clean($r), array_filter($rows, fn ($r) => $status === null || $r['status'] === $status))),
            'summary' => [
                'total_due' => $this->format($sum['due']),
                'total_paid' => $this->format($sum['paid']),
                'outstanding' => $this->format($sum['outstanding']),
                'overdue' => $this->format($sum['overdue']),
                'count' => $active,
            ],
        ];
    }

    /**
     * Payment history in a scope, newest first. Filters: contact_id, charge_assignment_id, payment_method, from, to.
     *
     * @param array<string, mixed> $filters
     */
    public function paymentHistory(Account $account, string $scope, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $assignmentIds = CollectionChargeAssignment::query()->forAccount($account->id)
            ->whereIn('charge_item_id', $this->scopeItemIds($account, $scope))
            ->when($filters['contact_id'] ?? null, fn ($q, $v) => $q->where('contact_id', $v))
            ->select('id');

        return CollectionPayment::query()->forAccount($account->id)
            ->whereIn('charge_assignment_id', $assignmentIds)
            ->with(['assignment.item', 'assignment.contact'])
            ->when($filters['charge_assignment_id'] ?? null, fn ($q, $v) => $q->where('charge_assignment_id', $v))
            ->when($filters['payment_method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('payment_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('payment_date', '<=', $v))
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function presentPayment(CollectionPayment $payment): array
    {
        $assignment = $payment->relationLoaded('assignment') ? $payment->assignment : null;

        return [
            'id' => $payment->id,
            'charge_assignment_id' => $payment->charge_assignment_id,
            'contact_id' => $assignment?->contact_id,
            'contact_name' => $assignment?->contact?->name,
            'charge_name' => $assignment?->item?->name,
            'amount' => $this->format($this->storedMinor($payment->amount)),
            'payment_date' => $payment->payment_date->toDateString(),
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'recorded_by_user_id' => $payment->recorded_by_user_id,
            'created_at' => $payment->created_at,
        ];
    }

    /** @return array<string, mixed> one assignment with derived paid / outstanding / status */
    public function present(Account $account, CollectionChargeAssignment $assignment): array
    {
        return $this->clean($this->presentMany($account, collect([$assignment]))[0]);
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function clean(array $row): array
    {
        return array_diff_key($row, ['_due' => 1, '_paid' => 1, '_outstanding' => 1]);
    }

    /**
     * @param iterable<CollectionChargeAssignment> $assignments
     *
     * @return list<array<string, mixed>>
     */
    private function presentMany(Account $account, iterable $assignments): array
    {
        $assignments = collect($assignments);
        $paid = [];
        if ($assignments->isNotEmpty()) {
            foreach (CollectionPayment::query()->forAccount($account->id)->whereIn('charge_assignment_id', $assignments->pluck('id'))->get(['charge_assignment_id', 'amount']) as $p) {
                $paid[$p->charge_assignment_id] = ($paid[$p->charge_assignment_id] ?? 0) + $this->storedMinor($p->amount);
            }
        }
        $today = Carbon::today()->toDateString();

        return $assignments->map(function (CollectionChargeAssignment $a) use ($paid, $today) {
            $due = $this->storedMinor($a->amount_due);
            $paidMinor = $paid[$a->id] ?? 0;
            $outstanding = max(0, $due - $paidMinor);
            $dueDate = $a->due_date?->toDateString();
            $cancelled = $a->cancelled_at !== null;
            if ($cancelled) {
                $outstanding = 0; // nothing is owed on a voided charge
            }
            $status = $cancelled ? 'cancelled'
                : ($outstanding === 0 ? 'paid'
                : ($dueDate !== null && $dueDate < $today ? 'overdue' : ($paidMinor > 0 ? 'partially_paid' : 'open')));

            return [
                'id' => $a->id,
                'contact_id' => $a->contact_id,
                'charge_item_id' => $a->charge_item_id,
                'charge_name' => $a->item?->name,
                'charge_status' => $a->item?->status,
                'amount_due' => $this->format($due),
                'amount_paid' => $this->format($paidMinor),
                'outstanding' => $this->format($outstanding),
                'due_date' => $dueDate,
                'status' => $status,
                'cancelled_at' => $a->cancelled_at?->toIso8601String(),
                'cancellation_reason' => $a->cancellation_reason,
                'created_at' => $a->created_at,
                '_due' => $due, '_paid' => $paidMinor, '_outstanding' => $outstanding,
            ];
        })->values()->all();
    }

    private function paidMinor(Account $account, int $assignmentId): int
    {
        $total = 0;
        foreach (CollectionPayment::query()->forAccount($account->id)->where('charge_assignment_id', $assignmentId)->pluck('amount') as $amount) {
            $total += $this->storedMinor($amount);
        }

        return $total;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<CollectionChargeItem> */
    private function scopeItemIds(Account $account, string $scope)
    {
        return CollectionChargeItem::query()->forAccount($account->id)->where('scope', $scope)->select('id');
    }

    private function assertItemNameFree(Account $account, string $scope, string $name, ?int $ignoreId): void
    {
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['The name is required.']]);
        }
        $taken = CollectionChargeItem::query()->forAccount($account->id)->where('scope', $scope)->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => ['A charge with this name already exists.']]);
        }
    }

    private function parseDueDate(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : $this->parseDate((string) $value, 'due_date', false);
    }

    private function parseDate(string $value, string $key, bool $limitFuture = true): string
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
            $valid = $date !== false && $date->format('Y-m-d') === $value;
        } catch (\Throwable) {
            $valid = false;
        }
        if (! $valid) {
            throw ValidationException::withMessages([$key => ['Use a valid date (YYYY-MM-DD).']]);
        }
        if ($limitFuture && $date->greaterThan(Carbon::today()->addDays(self::FUTURE_DAYS_ALLOWED))) {
            throw ValidationException::withMessages([$key => ['The date cannot be in the future.']]);
        }

        return $value;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
