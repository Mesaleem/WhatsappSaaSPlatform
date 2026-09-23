<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\CrmLead;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — CRM Task 9. The one way a bulk operation turns a list of lead
 * ids into leads it may mutate. Shared by every bulk operation
 * (CrmLeadService status/assignee, CrmTagService tag attach/detach) so the
 * tenant rule cannot differ between them.
 *
 * ALL OR NOTHING. Every requested id must be a lead of $account. If even
 * one is foreign or missing, the whole operation is refused with a single
 * generic 422 — nothing is mutated, and the response never says which id
 * failed or whether it exists in another account (the same
 * non-enumeration rule as the individual endpoints' identical 404s).
 *
 * ONE QUERY, ROW LOCKS. The leads are read with
 * `WHERE account_id = ? AND id IN (...) FOR UPDATE` — the account-scoped
 * primary-key lookup already established in Phase 6. Must be called inside
 * the caller's transaction: on MySQL/MariaDB the selected rows (only those
 * rows, by primary key) stay locked until commit, so a concurrent single
 * or bulk write to the same leads waits instead of interleaving with this
 * batch. SQLite ignores FOR UPDATE; its writes are serialized anyway.
 */
class CrmBulkLeadSelection
{
    /** Server-side batch ceiling — the largest page the CRM list can show (CrmLeadController::PER_PAGE_MAX). */
    public const MAX_LEADS = 100;

    public const NOT_AVAILABLE = 'One or more selected leads are not available.';

    /**
     * @param list<int> $leadIds already validated: 1..MAX_LEADS distinct positive integers
     * @return Collection<int, CrmLead> in id order
     */
    public function lock(Account $account, array $leadIds): Collection
    {
        $leads = CrmLead::query()
            ->forAccount($account->id)
            ->whereIn('id', $leadIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($leads->count() !== count(array_unique($leadIds))) {
            throw ValidationException::withMessages([
                'lead_ids' => [self::NOT_AVAILABLE],
            ]);
        }

        return $leads;
    }

    /**
     * The summary every bulk endpoint returns.
     *
     * @return array{operation: string, requested: int, changed: int, unchanged: int}
     */
    public static function summary(string $operation, int $requested, int $changed): array
    {
        return [
            'operation' => $operation,
            'requested' => $requested,
            'changed' => $changed,
            'unchanged' => $requested - $changed,
        ];
    }
}
