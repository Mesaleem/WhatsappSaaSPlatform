<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\CrmLead;
use App\Models\CrmLeadTag;
use App\Models\CrmTag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — CRM Task 7. THE place tag rules live: tag CRUD and lead
 * assignment. Controllers resolve the tenant and the records (through
 * forAccount(), so foreign ids are already 404s), then delegate here.
 *
 * NOTHING HERE WRITES TO crm_leads. Attaching or detaching a tag never
 * changes a lead's status, lifecycle timestamps, contact, owner or
 * updated_at — tags are metadata, and the Task 5 lifecycle is untouched.
 *
 * TENANT SAFETY is layered, the same way the rest of the CRM does it:
 *   1. the controller resolves lead and tag through forAccount();
 *   2. assertSameAccount() below refuses a mismatched pair for any other
 *      caller (a job, a future bulk action);
 *   3. CrmLeadTag's creating guard re-checks both rows against account_id;
 *   4. the composite foreign keys make a cross-tenant row unstorable.
 *
 * BULK: Task 9 added bulkAttach()/bulkDetach() below — one tag across up
 * to CrmBulkLeadSelection::MAX_LEADS leads in one transaction, all or
 * nothing, one audited row per lead. No schema change was needed.
 */
class CrmTagService
{
    public function __construct(private readonly CrmBulkLeadSelection $bulkSelection)
    {
    }

    public function create(Account $account, string $name): CrmTag
    {
        return $this->guardUnique(fn (): CrmTag => CrmTag::create([
            'account_id' => $account->id,
            'name' => $name,
        ]));
    }

    public function rename(CrmTag $tag, string $name): CrmTag
    {
        return $this->guardUnique(function () use ($tag, $name): CrmTag {
            $tag->name = $name;
            $tag->save();

            return $tag;
        });
    }

    /**
     * Deletes the tag and its assignments. Leads, their status, their
     * contacts and capture rows are untouched.
     *
     * The assignment rows are removed explicitly (one set-based DELETE)
     * rather than left to the ON DELETE CASCADE alone, so the behaviour
     * does not depend on the driver enforcing foreign keys. Per-lead
     * detach audit rows are deliberately not emitted for this bulk
     * removal; the tag's own `delete` audit row records the event.
     */
    public function delete(CrmTag $tag): void
    {
        DB::transaction(function () use ($tag): void {
            CrmLeadTag::query()->where('crm_tag_id', $tag->getKey())->delete();
            $tag->delete();
        });
    }

    /**
     * Attach $tag to $lead. Idempotent: attaching a tag the lead already
     * carries changes nothing and returns false. Never removes other
     * tags.
     *
     * @return bool true when a new assignment was written
     */
    public function attach(CrmLead $lead, CrmTag $tag): bool
    {
        $this->assertSameAccount($lead, $tag);

        if ($this->isAttached($lead, $tag)) {
            return false;
        }

        try {
            CrmLeadTag::create([
                'crm_lead_id' => $lead->getKey(),
                'crm_tag_id' => $tag->getKey(),
                'account_id' => $lead->account_id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request attached the same pair between the
            // check and the insert — the outcome the caller asked for.
            return false;
        }

        return true;
    }

    /**
     * Detach $tag from $lead. Idempotent: detaching a tag the lead does
     * not carry changes nothing and returns false.
     *
     * @return bool true when an assignment was removed
     */
    public function detach(CrmLead $lead, CrmTag $tag): bool
    {
        $this->assertSameAccount($lead, $tag);

        $row = CrmLeadTag::query()
            ->where('crm_lead_id', $lead->getKey())
            ->where('crm_tag_id', $tag->getKey())
            ->first();

        if (! $row) {
            return false;
        }

        // Model delete, so LogsActivity records the detach.
        $row->delete();

        return true;
    }

    /**
     * Task 9 — attach one tag to up to MAX_LEADS leads of one account, in
     * ONE transaction, all or nothing.
     *
     * The tag must already have been resolved through forAccount() by the
     * caller; assertTagBelongs() re-checks it anyway. The leads are
     * resolved and locked by CrmBulkLeadSelection (one foreign/missing id
     * rejects everything). Existing pairs are found in ONE query; only
     * missing pairs are created — idempotent, never a duplicate row (the
     * primary key forbids one regardless). Each created row goes through
     * the CrmLeadTag model, so LogsActivity records one `create` row per
     * lead; the creating guard skips re-querying lead/tag ownership that
     * this method has just proven (withVerifiedPairs), and the composite
     * foreign keys still apply. Lead rows themselves are never written.
     *
     * @param list<int> $leadIds
     * @return array{operation: string, requested: int, changed: int, unchanged: int}
     */
    public function bulkAttach(Account $account, array $leadIds, CrmTag $tag): array
    {
        return DB::transaction(function () use ($account, $leadIds, $tag): array {
            $leads = $this->bulkSelection->lock($account, $leadIds);
            $this->assertTagBelongs($account, $tag);

            $already = CrmLeadTag::query()
                ->where('crm_tag_id', $tag->getKey())
                ->whereIn('crm_lead_id', $leads->modelKeys())
                ->pluck('crm_lead_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $missing = array_values(array_diff($leads->modelKeys(), $already));

            CrmLeadTag::withVerifiedPairs((int) $account->id, $leads->modelKeys(), [(int) $tag->getKey()], function () use ($missing, $tag, $account): void {
                foreach ($missing as $leadId) {
                    CrmLeadTag::create([
                        'crm_lead_id' => $leadId,
                        'crm_tag_id' => $tag->getKey(),
                        'account_id' => $account->id,
                    ]);
                }
            });

            return CrmBulkLeadSelection::summary('tag_attach', $leads->count(), count($missing));
        });
    }

    /**
     * Task 9 — detach one tag from up to MAX_LEADS leads, in ONE
     * transaction, all or nothing. Leads without the tag are harmless
     * no-ops. Each removed row is deleted through the model (one audit
     * `delete` row per lead). The tag, the leads, their status, owner and
     * contacts are never touched.
     *
     * @param list<int> $leadIds
     * @return array{operation: string, requested: int, changed: int, unchanged: int}
     */
    public function bulkDetach(Account $account, array $leadIds, CrmTag $tag): array
    {
        return DB::transaction(function () use ($account, $leadIds, $tag): array {
            $leads = $this->bulkSelection->lock($account, $leadIds);
            $this->assertTagBelongs($account, $tag);

            $rows = CrmLeadTag::query()
                ->where('crm_tag_id', $tag->getKey())
                ->whereIn('crm_lead_id', $leads->modelKeys())
                ->get();

            foreach ($rows as $row) {
                $row->delete();
            }

            return CrmBulkLeadSelection::summary('tag_detach', $leads->count(), $rows->count());
        });
    }

    private function assertTagBelongs(Account $account, CrmTag $tag): void
    {
        if ((int) $tag->account_id !== (int) $account->id) {
            throw ValidationException::withMessages([
                'tag_id' => ['The selected tag is not available.'],
            ]);
        }
    }

    public function isAttached(CrmLead $lead, CrmTag $tag): bool
    {
        return CrmLeadTag::query()
            ->where('crm_lead_id', $lead->getKey())
            ->where('crm_tag_id', $tag->getKey())
            ->exists();
    }

    private function assertSameAccount(CrmLead $lead, CrmTag $tag): void
    {
        if ((int) $lead->account_id !== (int) $tag->account_id) {
            throw ValidationException::withMessages([
                'tag' => ['The selected tag is not available.'],
            ]);
        }
    }

    /**
     * The model's saving guard catches ordinary duplicates with a clean
     * 422. Two concurrent creates of "Hot" can both pass that check; the
     * unique(account_id, normalized_name) index then rejects the second,
     * and this turns that into the same 422 instead of a 500.
     *
     * @template T
     * @param callable(): T $write
     * @return T
     */
    private function guardUnique(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => [CrmTag::duplicateMessage()],
            ]);
        }
    }
}
