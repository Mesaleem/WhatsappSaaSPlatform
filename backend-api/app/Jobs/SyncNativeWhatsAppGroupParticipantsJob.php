<?php

namespace App\Jobs;

use App\Models\ContactGroup;
use App\Services\Groups\NativeWhatsAppGroupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Native WhatsApp Group Re-Architecture — the async half of
 * ContactGroupController::addContacts() for a synced native group. Adds
 * the newly-imported phone numbers to the REAL WhatsApp group (not just
 * this app's own contact_group_members shadow copy, which addContacts()
 * has already written by the time this is queued).
 *
 * Deliberately does not flip the group's own sync_status — a failed
 * participant-add doesn't invalidate the group itself (it's still a
 * real, usable group other members can be added to later), unlike a
 * failed CreateNativeWhatsAppGroupJob, which leaves the group without a
 * wa_group_jid at all. Failures are logged only; there is no per-member
 * "did this contact actually get added to the live group" UI surface in
 * this change (not requested) — see this job's own log line as the only
 * record of a partial failure today.
 */
class SyncNativeWhatsAppGroupParticipantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** @param list<string> $phoneNumbers Un-normalized phone numbers as originally submitted; NativeWhatsAppGroupService normalizes them again before building JIDs. */
    public function __construct(
        public readonly int $groupId,
        public readonly array $phoneNumbers,
    ) {
    }

    public function handle(NativeWhatsAppGroupService $service): void
    {
        $group = ContactGroup::find($this->groupId);

        if (! $group || ! $group->isSyncedNativeGroup() || $this->phoneNumbers === []) {
            return;
        }

        try {
            $result = $service->addParticipants($group->account_id, $group->wa_group_jid, $this->phoneNumbers);

            if (! ($result['success'] ?? false)) {
                Log::warning('SyncNativeWhatsAppGroupParticipantsJob: add-participants failed.', [
                    'group_id' => $group->id,
                    'error' => $result['error'] ?? null,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('SyncNativeWhatsAppGroupParticipantsJob failed unexpectedly.', [
                'group_id' => $group->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
