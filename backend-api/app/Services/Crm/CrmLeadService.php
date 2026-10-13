<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmCompany;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 — CRM, Task 2. Lead creation and lifecycle updates, kept out
 * of the controller so the same path serves the API today and the
 * webhook/journey/public-API sources Task 3+ will add.
 *
 * DELIBERATELY DOES NOT RE-VALIDATE what CrmLead already guarantees.
 * Task 1's model saving hook owns every invariant — supported status,
 * supported source, contact belongs to this account, assignee belongs to
 * this account — and it throws ValidationException, which Laravel
 * renders as an ordinary 422. Duplicating those checks here would create
 * two rule sets that can disagree; the brief forbids it and so does
 * plain sense. This service's job is orchestration: resolve the contact,
 * assemble the row, keep the terminal timestamps honest.
 */
class CrmLeadService
{
    /**
     * CRM Sales Phase 1 — qualification fields a user may set on create and update.
     * `attribution` is NOT here: it is written only by system callers of create().
     */
    public const QUALIFICATION_FIELDS = ['company_name', 'requirement', 'budget', 'timeline', 'temperature', 'next_follow_up_at'];

    public function __construct(
        private readonly ContactResolver $contacts,
        private readonly CrmBulkLeadSelection $bulkSelection,
        private readonly CrmCompanyService $companies,
        private readonly CrmDealService $deals,
        private readonly CrmActivityService $activities,
    ) {
    }

    /**
     * Create a lead against a resolved (existing or new) contact.
     *
     * WRAPPED IN A TRANSACTION for one concrete reason: contact
     * resolution can CREATE a row, and CrmLead::create() can then reject
     * the lead (an assignee from another account, say). Without the
     * transaction a rejected request would leave a newly created contact
     * behind — a side effect from a call that returned 422. Inside it,
     * a rejected lead leaves the database exactly as it was. An
     * ALREADY-EXISTING contact is of course untouched either way.
     *
     * Defaults are status=new / source=manual per the brief. They are
     * applied here as well as on the model so a direct service call with
     * a partial array behaves identically to an API call.
     *
     * @param array{phone_number: string, name?: string|null, email?: string|null, status?: string|null, source?: string|null, assigned_user_id?: int|null, not_converted_reason?: string|null} $data
     */
    public function create(Account $account, array $data): CrmLead
    {
        return DB::transaction(function () use ($account, $data): CrmLead {
            $contact = $this->contacts->resolve(
                $account->id,
                (string) $data['phone_number'],
                $data['name'] ?? null,
                // Round 2 — the Developer API and the capture flows both
                // supply an email when they have one; ContactResolver
                // fills it only when the contact's own is blank.
                $data['email'] ?? null,
            );

            $status = $data['status'] ?? CrmLead::STATUS_NEW;

            $lead = new CrmLead([
                'account_id' => $account->id,
                'contact_id' => $contact->id,
                'status' => $status,
                'source' => $data['source'] ?? CrmLead::SOURCE_MANUAL,
                'assigned_user_id' => $data['assigned_user_id'] ?? null,
            ] + array_intersect_key($data, array_flip([...self::QUALIFICATION_FIELDS, 'attribution'])));

            $this->stampTerminalOutcome($lead, $status, $data['not_converted_reason'] ?? null);

            $lead->save();

            return $lead;
        });
    }

    /**
     * Update the mutable part of a lead's lifecycle.
     *
     * WHAT IS DELIBERATELY NOT UPDATABLE: account_id and contact_id. The
     * brief forbids free reassignment of either, and there is no
     * legitimate product action behind them — a lead created against the
     * wrong person is deleted and recreated, not silently re-pointed.
     * Because they are never written here, this method cannot move a
     * lead to another tenant no matter what the caller sends; the model
     * guard and the composite foreign key remain the backstop.
     *
     * Contact NAME is updatable, and that is not a contradiction of
     * ContactResolver's never-overwrite rule: resolution is an automatic
     * side effect of creating a lead, while this is a person explicitly
     * asking for this contact's name to change. There is no separate
     * contacts endpoint in this task, so this is the only way to correct
     * one.
     *
     * @param array{status?: string, assigned_user_id?: int|null, not_converted_reason?: string|null, name?: string|null} $data
     */
    public function update(CrmLead $lead, array $data): CrmLead
    {
        return DB::transaction(function () use ($lead, $data): CrmLead {
            // Task 4 — the SAME step the dedicated assignee endpoint
            // uses, so the two paths cannot develop different behaviour.
            // A field that is absent leaves ownership alone; a field
            // present with null is an explicit unassign.
            if (array_key_exists('assigned_user_id', $data)) {
                $this->applyAssignee($lead, $data['assigned_user_id']);
            }

            if (array_key_exists('not_converted_reason', $data)) {
                $lead->not_converted_reason = $data['not_converted_reason'];
            }

            // CRM Sales Phase 1 — qualification fields.
            $lead->fill(array_intersect_key($data, array_flip(self::QUALIFICATION_FIELDS)));

            // Task 5 — the SAME step the dedicated status endpoint
            // uses, so the two paths cannot validate, transition or
            // audit differently. An absent status leaves the lifecycle
            // alone.
            if (array_key_exists('status', $data)) {
                $this->applyStatus($lead, (string) $data['status'], $data['not_converted_reason'] ?? null);
            }

            $lead->save();

            if (array_key_exists('name', $data)) {
                $contact = $lead->contact;
                $name = $data['name'] === null ? null : trim((string) $data['name']);
                $contact->name = $name === '' ? null : $name;
                $contact->save();
            }

            return $lead->fresh(['contact', 'assignedUser']);
        });
    }

    /**
     * Task 5 — the one lifecycle operation.
     *
     * WHAT CHANGES: status, and the outcome columns that must agree with
     * it (converted_at / not_converted_at / not_converted_reason). Not
     * the tenant, the contact, the capture origin, the source or the
     * owner — a lead that moves to `converted` keeps everything that
     * makes it that lead. A test asserts every unrelated column is
     * byte-identical afterwards.
     *
     * BOTH WRITE PATHS COME THROUGH HERE. PATCH /crm/leads/{id}/status
     * calls this method; PATCH /crm/leads/{id} calls applyStatus()
     * below, the same step minus the save. Neither can acquire
     * transition, validation or audit behaviour the other lacks, and a
     * test asserts the two refuse the same moves with the same response.
     *
     * TRUE NO-OP: setting the status to what it already is, with no new
     * outcome reason, returns without writing anything. That matters for
     * more than efficiency — stampTerminalOutcome() RE-STAMPS a terminal
     * timestamp, so `converted -> converted` would otherwise move
     * converted_at, dirty the model, and record a lifecycle change that
     * never happened. The brief forbids exactly that phantom event.
     *
     * ->save(), never a query-builder update: LogsActivity hooks
     * Eloquent's `updated` event and that hook is the whole audit trail
     * for lifecycle changes.
     */
    public function changeStatus(CrmLead $lead, string $status, ?string $notConvertedReason = null): CrmLead
    {
        return DB::transaction(function () use ($lead, $status, $notConvertedReason): CrmLead {
            $this->applyStatus($lead, $status, $notConvertedReason);

            $lead->save();

            return $lead->fresh();
        });
    }

    /**
     * The single lifecycle mutation: validate the move, then bring the
     * outcome columns into agreement with the new status.
     *
     * The transition itself is checked against CrmLead::STATUS_TRANSITIONS
     * — the matrix lives on the model beside the statuses it governs, so
     * there is one place to read and one place to change. That the
     * matrix currently permits every move is a product decision, not an
     * absence of rules; the check is real and a retired transition would
     * start failing here immediately.
     *
     * Value validation (is this one of the four canonical statuses at
     * all?) stays where it has always been — CrmLead's saving guard —
     * so there remains exactly one authority for it rather than two
     * copies that can disagree.
     */
    private function applyStatus(CrmLead $lead, string $status, ?string $notConvertedReason): void
    {
        if (! CrmLead::canTransitionTo($lead->status, $status)) {
            throw ValidationException::withMessages([
                'status' => ["A lead cannot move from {$lead->status} to {$status}."],
            ]);
        }

        // Nothing actually changes: leave the row, and the audit trail,
        // untouched. A supplied reason IS a change, so it falls through.
        if ($lead->status === $status && $notConvertedReason === null) {
            return;
        }

        $this->stampTerminalOutcome($lead, $status, $notConvertedReason);
    }

    /**
     * Task 9 — bulk lifecycle change: the SAME applyStatus() step the
     * individual endpoints use, applied to up to CrmBulkLeadSelection::MAX_LEADS
     * leads of one account in ONE transaction.
     *
     * ALL OR NOTHING, IN TWO PASSES:
     *   1. every lead is locked and every transition is checked against
     *      CrmLead::STATUS_TRANSITIONS before anything is written — one
     *      disallowed move rejects the whole batch with zero mutations;
     *   2. only then is each lead saved. Any failure during the writes
     *      (a guard, the database) rolls the whole transaction back.
     *
     * Each changed lead is saved through Eloquent, so LogsActivity writes
     * one `update` row per lead with its own old/new status — per-lead
     * accountability, not a single "bulk happened" line. A lead already
     * in the target status (and no reason supplied) is left untouched and
     * audits nothing, exactly like the individual no-op.
     *
     * @param list<int> $leadIds
     * @return array{operation: string, requested: int, changed: int, unchanged: int}
     */
    public function bulkChangeStatus(Account $account, array $leadIds, string $status, ?string $notConvertedReason = null): array
    {
        return DB::transaction(function () use ($account, $leadIds, $status, $notConvertedReason): array {
            $leads = $this->bulkSelection->lock($account, $leadIds);

            foreach ($leads as $lead) {
                if (! CrmLead::canTransitionTo($lead->status, $status)) {
                    throw ValidationException::withMessages([
                        'status' => ["One or more selected leads cannot move to {$status}."],
                    ]);
                }
            }

            $changed = 0;
            foreach ($leads as $lead) {
                $this->applyStatus($lead, $status, $notConvertedReason);

                if ($lead->isDirty()) {
                    $lead->save();
                    $changed++;
                }
            }

            return CrmBulkLeadSelection::summary('status', $leads->count(), $changed);
        });
    }

    /**
     * Task 9 — bulk assign / reassign / unassign (null): the SAME
     * applyAssignee() step and the SAME eligibility authority
     * (CrmLead's saving guard -> assigneeIsEligible()) as the individual
     * endpoint, for up to MAX_LEADS leads in ONE transaction.
     *
     * The target is checked ONCE up front, through the same predicate, so
     * an ineligible assignee rejects the batch before any lead is touched
     * (one generic message for foreign / missing / inactive / no
     * manage-crm, as the individual endpoint gives). The per-save guard
     * still runs for every lead; withAssigneeEligibilityMemo() only lets it
     * reuse that one answer instead of re-querying the same user per lead.
     *
     * Leads already owned by the target (or already unassigned, for null)
     * are not saved and audit nothing.
     *
     * @param list<int> $leadIds
     * @return array{operation: string, requested: int, changed: int, unchanged: int}
     */
    public function bulkChangeAssignee(Account $account, array $leadIds, ?int $assignedUserId): array
    {
        return CrmLead::withAssigneeEligibilityMemo(function () use ($account, $leadIds, $assignedUserId): array {
            if ($assignedUserId !== null && ! CrmLead::assigneeIsEligibleById($assignedUserId, (int) $account->id)) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => ['The selected assignee is not available.'],
                ]);
            }

            return DB::transaction(function () use ($account, $leadIds, $assignedUserId): array {
                $leads = $this->bulkSelection->lock($account, $leadIds);

                $changed = 0;
                foreach ($leads as $lead) {
                    $this->applyAssignee($lead, $assignedUserId);

                    if ($lead->isDirty()) {
                        $lead->save();
                        $changed++;
                    }
                }

                return CrmBulkLeadSelection::summary($assignedUserId === null ? 'unassign' : 'assign', $leads->count(), $changed);
            });
        });
    }

    /**
     * Task 4 — the one ownership operation: assign, reassign and
     * unassign are the same write with a different argument.
     *
     *   null -> id    assign
     *   id   -> id'   reassign
     *   id   -> null  unassign
     *
     * WHAT CHANGES: assigned_user_id, and nothing else. Not contact_id,
     * account_id, source, status, the terminal timestamps or the
     * capture linkage — only Eloquent's own updated_at moves, which is
     * the normal model timestamp the brief allows.
     *
     * WHY IT IS A SERVICE METHOD AND NOT TWO CONTROLLER BODIES: both
     * PATCH /crm/leads/{id} and PATCH /crm/leads/{id}/assignee funnel
     * through here, so neither can acquire authorization, validation or
     * audit behaviour the other lacks. Tests assert the two paths refuse
     * the same assignees with the same response.
     *
     * VALIDATION HAS EXACTLY ONE AUTHORITY: CrmLead's saving guard,
     * via CrmLead::assigneeIsEligible(). This service deliberately does
     * NOT re-check eligibility — a second copy of the rule is a second
     * rule, and it would be the copy that goes stale. The guard runs on
     * save() whoever the caller is: a controller, a job, a seeder or a
     * future Journey node.
     *
     * ->save(), never a query-builder update: LogsActivity hooks
     * Eloquent's `updated` event, and that hook is the entire audit
     * trail for ownership changes. A mass update would be silent.
     *
     * NO-OP WHEN NOTHING CHANGES: reassigning a lead to the user who
     * already owns it leaves the model clean, so Eloquent fires no
     * `updated` event and no misleading audit row is written. That is
     * Eloquent's own dirty-checking doing the right thing, and a test
     * pins it so a future refactor cannot start writing phantom
     * ownership history.
     *
     * CONCURRENCY: no lock, no version column, deliberately. This is a
     * single-column write whose only invariant — the assignee is
     * eligible for THIS lead's account — is re-evaluated inside every
     * save and, on MySQL/MariaDB, backed by the users foreign key. Two
     * racing requests therefore leave one of their two values, both of
     * which are individually valid, and both audit. There is no
     * interleaving that produces a cross-tenant or ineligible result,
     * so a row lock would buy ordering nobody asked for at the cost of
     * contention on a hot table.
     */
    public function changeAssignee(CrmLead $lead, ?int $assignedUserId): CrmLead
    {
        return DB::transaction(function () use ($lead, $assignedUserId): CrmLead {
            $this->applyAssignee($lead, $assignedUserId);

            $lead->save();

            return $lead->fresh();
        });
    }

    /**
     * The single assignment mutation. Trivial by design — its value is
     * that there is exactly one of it, so "assignment" cannot mean two
     * slightly different things in two call sites.
     */
    private function applyAssignee(CrmLead $lead, ?int $assignedUserId): void
    {
        $lead->assigned_user_id = $assignedUserId;
    }

    /**
     * Task 3 — move a lead onto a different Contact of the SAME tenant.
     *
     * EXACTLY ONE COLUMN IS WRITTEN: contact_id. Everything that makes
     * this lead what it is survives untouched —
     *   account_id        never written here, so a lead cannot change tenant
     *   capture_lead_id   the original provider submission stays attached,
     *                     which is why `capture lead -> CRM lead -> new
     *                     contact` is still a valid chain afterwards
     *   source            where the lead came from is a historical fact,
     *                     not something a reassignment revises
     *   status / converted_at / not_converted_at / not_converted_reason
     *   assigned_user_id  the owner keeps their work
     * No lead is created and none is deleted, so a reassignment can never
     * duplicate an opportunity or orphan a capture row.
     *
     * NEITHER CONTACT IS MODIFIED. The old contact keeps its name, email
     * and phone and simply has one fewer opportunity; the new one gains
     * one. Contact identity and lead lifecycle are separate records and
     * this operation only re-points the arrow between them.
     *
     * Transactional, and the tenant rule is checked three times over: the
     * caller resolves both rows through forAccount(), CrmLead's saving
     * guard re-checks the pair, and on MySQL/MariaDB the composite
     * (contact_id, account_id) foreign key makes a cross-tenant result
     * physically unstorable.
     */
    public function reassignContact(CrmLead $lead, Contact $contact): CrmLead
    {
        return DB::transaction(function () use ($lead, $contact): CrmLead {
            $lead->contact_id = $contact->getKey();

            // ->save() rather than a query-builder update: LogsActivity
            // hooks Eloquent's `updated` event, so this is what records
            // the actor, the timestamp and the old/new contact_id in
            // activity_logs. A mass update would be silent.
            $lead->save();

            return $lead->fresh();
        });
    }

    /**
     * CRM Sales Phase 1 — Convert Lead. One transaction, the lead row locked, so two
     * clicks (or two tabs) cannot convert twice:
     *   1. the contact is already this lead's (ContactResolver deduped it by phone);
     *   2. company: an existing one (crm_company_id) or `company` data, found by GSTIN,
     *      else by name, else created (CrmCompanyService::findOrCreate); the contact
     *      joins it when it has no company yet;
     *   3. deal: only when create_deal is true, and never a second one for the same
     *      lead (crm_deals.crm_lead_id is unique); value defaults to the lead's budget;
     *   4. the lead becomes converted (converted_at, converted_by_user_id), keeping its
     *      source, attribution, tags and history; a timeline event is written.
     * Converting an already converted lead returns its existing links (and only adds a
     * deal or company that does not exist yet).
     *
     * @param array{crm_company_id?: int|null, company?: array<string, mixed>|null, create_deal?: bool, deal?: array<string, mixed>} $options
     * @return array{lead: CrmLead, company: ?CrmCompany, deal: ?CrmDeal}
     */
    public function convert(CrmLead $lead, array $options, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($lead, $options, $actorUserId): array {
            $lead = CrmLead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $account = Account::query()->findOrFail($lead->account_id);
            $alreadyConverted = $lead->status === CrmLead::STATUS_CONVERTED;

            $company = $lead->crm_company_id ? CrmCompany::query()->forAccount($account->id)->find($lead->crm_company_id) : null;
            if ($company === null && ! empty($options['crm_company_id'])) {
                $company = CrmCompany::query()->forAccount($account->id)->find((int) $options['crm_company_id']);
                if (! $company) {
                    throw ValidationException::withMessages(['crm_company_id' => ['The selected company is not available.']]);
                }
            }
            if ($company === null && ! empty($options['company']['name'])) {
                $company = $this->companies->findOrCreate($account, $options['company']);
            }

            if ($company !== null) {
                $lead->crm_company_id = $company->id;
                $contact = $lead->contact;
                if ($contact && $contact->crm_company_id === null) {
                    $contact->forceFill(['crm_company_id' => $company->id])->save();
                }
            }

            if (! $alreadyConverted) {
                $this->applyStatus($lead, CrmLead::STATUS_CONVERTED, null);
                $lead->converted_by_user_id = $actorUserId;
            }
            $lead->save();

            $deal = CrmDeal::query()->where('crm_lead_id', $lead->id)->first();
            if ($deal === null && ! empty($options['create_deal'])) {
                $dealData = $options['deal'] ?? [];
                $contact = $lead->contact;
                $defaultName = $company->name ?? $contact?->name ?? $contact?->phone_number ?? 'Deal';
                if ($lead->requirement) {
                    $defaultName .= ' — '.mb_substr($lead->requirement, 0, 80);
                }
                $deal = $this->deals->create($account, [
                    'name' => $dealData['name'] ?? $defaultName,
                    'value' => $dealData['value'] ?? ($lead->budget ?? '0'),
                    'stage_id' => $dealData['stage_id'] ?? null,
                    'expected_close_date' => $dealData['expected_close_date'] ?? null,
                    'owner_user_id' => $dealData['owner_user_id'] ?? $lead->assigned_user_id,
                    'crm_lead_id' => $lead->id,
                    'contact_id' => $lead->contact_id,
                    'crm_company_id' => $company?->id,
                    'source' => $lead->source,
                ], $actorUserId);
            }

            if (! $alreadyConverted) {
                $this->activities->logEvent($account->id, 'Lead converted'.($deal ? " to deal {$deal->number}" : ''), [
                    'crm_lead_id' => $lead->id, 'contact_id' => $lead->contact_id, 'crm_company_id' => $company?->id, 'crm_deal_id' => $deal?->id,
                ], $actorUserId);
            }

            return ['lead' => $lead->fresh(), 'company' => $company, 'deal' => $deal?->fresh()];
        });
    }

    /**
     * CRM Sales Phase 1 — the owner's rule: a converted lead is final. When the same
     * customer comes back, this creates a NEW lead for the same contact (status new,
     * source manual, previous_lead_id = the converted lead), keeping the company and
     * the owner when the owner may still be assigned.
     *
     * @param array{requirement?: string|null} $data
     */
    public function reEngage(CrmLead $lead, array $data = [], ?int $actorUserId = null): CrmLead
    {
        if ($lead->status !== CrmLead::STATUS_CONVERTED) {
            throw ValidationException::withMessages(['status' => ['Only a converted lead is re-engaged. Change this lead\'s status instead.']]);
        }

        return DB::transaction(function () use ($lead, $data, $actorUserId): CrmLead {
            $owner = $lead->assigned_user_id !== null && CrmLead::assigneeIsEligibleById((int) $lead->assigned_user_id, (int) $lead->account_id)
                ? $lead->assigned_user_id
                : null;

            $new = CrmLead::create([
                'account_id' => $lead->account_id,
                'contact_id' => $lead->contact_id,
                'status' => CrmLead::STATUS_NEW,
                'source' => CrmLead::SOURCE_MANUAL,
                'assigned_user_id' => $owner,
                'previous_lead_id' => $lead->id,
                'crm_company_id' => $lead->crm_company_id,
                'company_name' => $lead->company_name,
                'requirement' => $data['requirement'] ?? null,
            ]);

            $this->activities->logEvent((int) $lead->account_id, "Re-engaged from converted lead #{$lead->id}", [
                'crm_lead_id' => $new->id, 'contact_id' => $new->contact_id, 'crm_company_id' => $new->crm_company_id,
            ], $actorUserId);

            return $new;
        });
    }

    /**
     * Keep converted_at / not_converted_at consistent with status.
     *
     * This is timestamp bookkeeping, not a state machine — Task 1 left
     * transitions deliberately unconstrained and this task does not
     * introduce a pipeline. The rule is only: the timestamp for the
     * status a lead is IN gets set when it enters that status, and the
     * timestamp for a terminal status it has LEFT is cleared, so a row
     * can never claim "status: contacted" while carrying a converted_at.
     * Re-entering a terminal status re-stamps it, because the useful
     * answer to "when was this converted" is the most recent time, not
     * the first.
     */
    private function stampTerminalOutcome(CrmLead $lead, string $status, ?string $notConvertedReason): void
    {
        $lead->status = $status;

        if ($status === CrmLead::STATUS_CONVERTED) {
            $lead->converted_at = now();
            $lead->not_converted_at = null;
            $lead->not_converted_reason = null;

            return;
        }

        if ($status === CrmLead::STATUS_NOT_CONVERTED) {
            $lead->not_converted_at = now();
            $lead->converted_at = null;

            if ($notConvertedReason !== null) {
                $lead->not_converted_reason = $notConvertedReason;
            }

            return;
        }

        // Back to an open status: neither outcome is true any more.
        $lead->converted_at = null;
        $lead->not_converted_at = null;
        $lead->not_converted_reason = null;
    }
}
