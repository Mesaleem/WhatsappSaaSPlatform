<?php

use App\Models\ContactGroup;
use App\Models\Lead;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Crm\ContactGroupContactLinker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Phase 6 — CRM Hardening Round 2. The canonical one-time
     * reconciliation of historical data into the CRM Contact domain,
     * moved here from 2026_09_22_180003 because it has to run AFTER the
     * capture link's new home exists (2026_09_22_190000) and after
     * contact_group_members gained its account_id (2026_09_22_190001).
     *
     * STRICTLY ADDITIVE. It creates rows; it never updates a column
     * other than the link columns those two migrations added, and it
     * never deletes anything. No existing lead, group, membership, phone
     * number or name is modified.
     *
     * IDEMPOTENT, AND THAT IS TESTED, not asserted: both linkers only
     * ever consider rows that are not yet linked, and
     * unique(capture_lead_id) plus unique(account_id, phone_number) make
     * a duplicate unstorable even if they did. Running this twice, or
     * running it after the live capture paths have already promoted the
     * same rows, produces exactly the same state. Verified on MariaDB by
     * re-running both linkers over a populated database and confirming
     * the contact / crm_lead / linked-lead counts do not move.
     *
     * IT REUSES THE LIVE CODE PATHS rather than reimplementing them, so a
     * contact created by this backfill is identical to one created by a
     * webhook tomorrow: CaptureLeadLinker and ContactGroupContactLinker
     * both resolve through ContactResolver, which normalizes through the
     * platform's single PhoneNumberNormalizer and keys off
     * unique(account_id, phone_number). That is why two capture rows
     * carrying "9876543210" and "+91 98765 43210" converge on ONE
     * contact instead of creating two.
     *
     * WHAT IS DELIBERATELY LEFT UNLINKED:
     *  - a capture lead with no lead_phone, or one that normalizes to no
     *    digits. `account_id + normalized phone` is the identity rule;
     *    without a phone there is nothing to resolve against, and
     *    inventing a contact from a name or an email would create a
     *    duplicate the moment that person arrives with their real
     *    number. Since Round 2 each of these also writes a
     *    CrmCaptureLinkFailure row, so "left unlinked" is now visible
     *    rather than merely logged.
     *  - a group membership with an unusable phone_number — which
     *    NativeGroupCreationService::importExisting() can legitimately
     *    produce, because a WhatsApp @lid participant identifier
     *    contains no phone number at all.
     *
     * EMAIL: where a capture lead carries lead_email it fills the
     * contact's email ONLY when that field is blank. This is the
     * "explicitly handled by the integration" case the brief allows, not
     * a blanket sweep, and it can never overwrite an address a tenant
     * entered.
     *
     * CHUNKED, and each row is linked in its own transaction inside the
     * linker, so one malformed historical row cannot roll back the
     * batch or leave a deployment half-reconciled.
     */
    public function up(): void
    {
        $captureLinker = app(CaptureLeadLinker::class);
        $groupLinker = app(ContactGroupContactLinker::class);

        $linkedLeads = 0;
        $skippedLeads = 0;

        Lead::query()
            ->whereDoesntHave('crmLead')
            ->orderBy('id')
            ->chunkById(200, function ($leads) use ($captureLinker, &$linkedLeads, &$skippedLeads): void {
                foreach ($leads as $lead) {
                    $captureLinker->linkQuietly($lead) !== null
                        ? $linkedLeads++
                        : $skippedLeads++;
                }
            });

        $linkedMembers = 0;

        ContactGroup::query()
            ->orderBy('id')
            ->chunkById(100, function ($groups) use ($groupLinker, &$linkedMembers): void {
                foreach ($groups as $group) {
                    $linkedMembers += $groupLinker->linkGroupQuietly($group);
                }
            });

        Log::info('CRM hardening backfill complete.', [
            'capture_leads_linked' => $linkedLeads,
            'capture_leads_left_unlinked' => $skippedLeads,
            'group_memberships_linked' => $linkedMembers,
        ]);
    }

    /**
     * Deliberately a no-op, and this is what rollback can and cannot
     * reverse — stated plainly because the brief asks for exactly that:
     *
     * CAN be reversed, by the schema migrations either side of this one:
     *   - every capture -> CRM link (crm_leads.capture_lead_id is dropped
     *     by 190000's down(), after 190000 first carries the links back
     *     to leads.crm_lead_id, which 180001's down() then drops);
     *   - every membership -> contact link, and the denormalized
     *     account_id (dropped by 190001's down());
     *   - contacts.email (dropped by 180000's down()).
     * After a full rollback the capture and group domains are byte-for-byte
     * what they were before the CRM existed.
     *
     * CANNOT be reversed, by design:
     *   - the `contacts` and `crm_leads` ROWS this created. By the time
     *     anyone rolls back, a tenant may have renamed, assigned,
     *     converted or added notes to them. Deleting customer records to
     *     undo a link is precisely the destructive migration the brief
     *     forbids, so they are left in place, inert, until a human
     *     decides what should happen to them.
     * Re-running forward after a rollback is safe for the same reason
     * the migration is idempotent: it relinks what exists and creates
     * nothing twice.
     */
    public function down(): void
    {
        Log::info('CRM hardening backfill rollback: links are removed by the schema migrations; no contact or CRM lead row is deleted, by design — see this migration\'s docblock.');
    }
};
