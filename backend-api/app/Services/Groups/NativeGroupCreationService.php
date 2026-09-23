<?php

namespace App\Services\Groups;

use App\Jobs\CreateNativeWhatsAppGroupJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Services\Crm\ContactGroupContactLinker;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- extracted, byte-for-byte, from
 * ContactGroupController::storeNativeGroup()'s transaction body (that
 * method now delegates here -- see its own updated docblock) so the new
 * external POST /api/v1/whatsapp/groups/create endpoint
 * (Api\V1\GroupController) creates a native WhatsApp group through the
 * EXACT SAME code path as the existing internal Sanctum-authenticated
 * "Create Contact Group" screen, rather than a second, divergent copy of
 * this transaction + upsert + queue-after-commit logic. Precondition
 * checks (engine_type==='qr', WhatsApp session connected) are
 * deliberately NOT moved here -- each caller's own error-response
 * envelope/status codes differ slightly (see ContactGroupController's
 * internal {success,error_code,message} vs the new external API's own
 * contract), so those stay in each caller, exactly as before this
 * extraction for ContactGroupController.
 */
class NativeGroupCreationService
{
    /**
     * @param list<array{phone_number: string, name?: string|null}> $contacts
     */
    public static function create(Account $account, string $name, array $contacts): ContactGroup
    {
        $group = DB::transaction(function () use ($account, $name, $contacts) {
            $group = ContactGroup::create([
                'account_id' => $account->id,
                'name' => $name,
                'group_code' => ContactGroup::generateGroupCode($account->id, $name),
                'is_default' => false,
                'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
                'sync_status' => ContactGroup::SYNC_STATUS_PENDING,
            ]);

            $now = now();
            $rows = collect($contacts)
                ->map(fn (array $c) => [
                    'group_id' => $group->id,
                    // Round 2 — NOT NULL, half of the composite
                    // (group_id, account_id) FK. From the group, never input.
                    'account_id' => $group->account_id,
                    'phone_number' => PhoneNumberNormalizer::normalize($c['phone_number']),
                    'name' => $c['name'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            ContactGroupMember::upsert($rows, ['group_id', 'phone_number'], ['name', 'updated_at']);

            /*
             * Phase 6 CRM Hardening (Issue 8) — reconcile the rows this
             * bulk upsert just wrote to universal CRM Contacts. The
             * upsert itself is deliberately left alone (it is the fast
             * path this feature depends on); linking runs afterwards and
             * only over rows whose contact_id is still NULL, so it is
             * idempotent and adds nothing for rows already reconciled.
             * Quietly: a CRM reconciliation failure must not fail a
             * group creation.
             */
            app(ContactGroupContactLinker::class)->linkGroupQuietly($group);

            return $group;
        });

        // Dispatched AFTER the transaction commits -- same
        // queue-after-commit ordering every other job in this codebase
        // already follows.
        CreateNativeWhatsAppGroupJob::dispatch($group->id);

        return $group->fresh()->loadCount('members');
    }

    /**
     * "Select an existing group" extension -- adopts an ALREADY-LIVE
     * WhatsApp group (one the tenant's connected number is already a
     * participant of, discovered via ContactGroupController::
     * availableNativeGroups()) as a ContactGroup row, instead of
     * create()'s "make a brand-new WhatsApp group" path above.
     *
     * Unlike create(), this never dispatches CreateNativeWhatsAppGroupJob
     * -- the real group already exists, so the row is created directly
     * as sync_status=SYNCED with its real wa_group_jid, and the group's
     * CURRENT real member list (already fetched by the caller via
     * NativeWhatsAppGroupService::groupMetadata(), as JIDs) is converted
     * back into this app's normalized phone-digit form and inserted
     * as ContactGroupMember rows in the same transaction -- so the
     * imported group shows its real member count immediately rather
     * than starting at 0.
     *
     * invite_link is intentionally left null: groupInviteCode() only
     * succeeds for a participant with admin rights on the real group
     * (same non-fatal limitation create() above already accepts for a
     * brand-new group -- see sessionManager.js's createGroup()), and is
     * not required for this app to send to the group.
     *
     * @param list<array{jid: string, is_admin: bool}> $participants
     */
    public static function importExisting(Account $account, string $groupJid, string $name, array $participants): ContactGroup
    {
        return DB::transaction(function () use ($account, $groupJid, $name, $participants) {
            $group = ContactGroup::create([
                'account_id' => $account->id,
                'name' => $name,
                'group_code' => ContactGroup::generateGroupCode($account->id, $name),
                'is_default' => false,
                'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
                'wa_group_jid' => $groupJid,
                'sync_status' => ContactGroup::SYNC_STATUS_SYNCED,
            ]);

            $now = now();
            $rows = collect($participants)
                ->map(fn (array $p) => PhoneNumberNormalizer::digitsFromJid($p['jid'] ?? ''))
                ->filter(fn (string $digits) => $digits !== '')
                ->unique()
                ->map(fn (string $digits) => [
                    'group_id' => $group->id,
                    // Round 2 — NOT NULL, half of the composite FK.
                    'account_id' => $group->account_id,
                    'phone_number' => $digits,
                    'name' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            if ($rows !== []) {
                ContactGroupMember::upsert($rows, ['group_id', 'phone_number'], ['updated_at']);

                // Phase 6 CRM Hardening (Issue 8) — same reconciliation
                // as create() above. Note that an imported native group
                // can legitimately contain participants with no phone
                // number at all (an @lid identifier), which
                // digitsFromJid() has already filtered out above; the
                // linker skips anything unresolvable rather than
                // inventing a Contact for it.
                app(ContactGroupContactLinker::class)->linkGroupQuietly($group);
            }

            return $group->fresh()->loadCount('members');
        });
    }
}
