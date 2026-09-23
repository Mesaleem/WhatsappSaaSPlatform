<?php

namespace App\Services\Crm;

use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 6 — CRM Hardening, Issue 8. Reconciles broadcast-list
 * memberships with the universal CRM Contact, one direction only:
 *
 *     contact_group_members.contact_id  ->  contacts.id
 *
 * WHAT THIS DOES NOT DO, on purpose:
 *  - It never deletes, rewrites or migrates a membership row. phone_number
 *    and name stay exactly where they are, and every dispatch path
 *    (ProcessGroupDispatchJob, ProcessGroupDirectMessageJob,
 *    CreateNativeWhatsAppGroupJob, GroupMessageDispatcher) keeps reading
 *    phone_number off the membership, untouched.
 *  - It never writes back to the Contact. A group import carrying a name
 *    fills a blank contact name through ContactResolver's existing
 *    fill-if-blank rule and can never overwrite one the tenant curated.
 *  - It never accepts a caller-supplied contact_id. The Contact is always
 *    resolved from the membership's OWN group's account, which is how a
 *    table with no account_id column stays tenant-safe.
 *
 * ACCOUNT DERIVATION: contact_group_members.group_id ->
 * contact_groups.account_id, which is NOT NULL with a cascading FK to
 * accounts. Exactly one owning account, one join away. That derivation
 * is what this service uses, and in Round 2 it is also what backfilled
 * the table's own account_id column — added then (and only then)
 * because a composite foreign key's columns have to live on the child
 * row, so without it no DATABASE-level tenant rule was expressible at
 * all. The (group_id, account_id) key means that column can never drift
 * from the group that owns it.
 *
 * DUPLICATE CONTACTS ARE IMPOSSIBLE HERE because ContactResolver is the
 * only way a Contact is created: it normalizes with the platform's
 * single PhoneNumberNormalizer and keys off unique(account_id,
 * phone_number), so two group members with the same number under one
 * tenant converge on one Contact — which is the whole point of the
 * reconciliation.
 */
class ContactGroupContactLinker
{
    public function __construct(private readonly ContactResolver $contacts)
    {
    }

    /**
     * Link every not-yet-linked membership in one group. Returns how
     * many rows were linked.
     *
     * Rows whose phone_number cannot be normalized to digits are skipped
     * and left unlinked rather than given an invented Contact — the same
     * rule the capture backfill follows.
     */
    public function linkGroup(ContactGroup $group): int
    {
        $accountId = (int) $group->account_id;
        $linked = 0;

        $group->members()
            ->whereNull('contact_id')
            ->chunkById(200, function ($members) use ($accountId, &$linked): void {
                foreach ($members as $member) {
                    if ($this->linkMember($member, $accountId)) {
                        $linked++;
                    }
                }
            });

        return $linked;
    }

    /**
     * Link one membership row. $accountId may be passed in when the
     * caller already knows it (a bulk loop), avoiding one query per row;
     * otherwise it is derived from the member's own group.
     */
    public function linkMember(ContactGroupMember $member, ?int $accountId = null): bool
    {
        if ($member->contact_id !== null) {
            return false;
        }

        $accountId ??= (int) $member->group?->account_id;

        if (! $accountId || blank($member->phone_number)) {
            return false;
        }

        $contact = $this->contacts->resolve($accountId, (string) $member->phone_number, $member->name);

        // account_id is written alongside contact_id because the two are
        // one composite foreign key on MySQL/MariaDB since Round 2: a
        // membership can only point at a contact of the account its own
        // group belongs to, and the database enforces both halves.
        $member->forceFill(['account_id' => $accountId, 'contact_id' => $contact->id])->save();

        return true;
    }

    /**
     * linkGroup(), but reconciliation can never break group management.
     *
     * Used by the live write paths (ContactGroupController::addContacts,
     * NativeGroupCreationService). Adding people to a broadcast list is
     * an existing, working feature; its CRM projection is derived, so a
     * failure there is a logged incident rather than a failed import.
     * The rows stay unlinked and the next reconciliation picks them up,
     * because linkGroup() only ever touches contact_id IS NULL rows.
     */
    public function linkGroupQuietly(ContactGroup $group): int
    {
        try {
            return $this->linkGroup($group);
        } catch (Throwable $e) {
            Log::warning('ContactGroupContactLinker: could not reconcile group members to CRM contacts; membership is unchanged and stays unlinked.', [
                'group_id' => $group->id,
                'account_id' => $group->account_id,
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
