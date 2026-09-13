<?php

namespace App\Jobs;

use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Services\Groups\NativeWhatsAppGroupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Native WhatsApp Group Re-Architecture — requirement 2's "sync
 * background action to create a real WhatsApp group on the connected
 * device when creating a group in the UI". Dispatched by
 * ContactGroupController::store() immediately after it creates a
 * sync_status='pending' ContactGroup row (+ its initial
 * ContactGroupMember rows) for group_type=native_wa_group — this job
 * makes the actual qr-engine-service call and resolves that row to
 * 'synced' (with wa_group_jid/invite_link) or 'failed' (with sync_error).
 *
 * Single attempt, same reasoning as every other WhatsApp-side job in
 * this codebase (ProcessPaymentAlertJob, ProcessGroupDispatchJob): group
 * creation is not idempotent on the WhatsApp side either — an automatic
 * retry risks creating a second real group.
 */
class CreateNativeWhatsAppGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $groupId)
    {
    }

    public function handle(NativeWhatsAppGroupService $service): void
    {
        $group = ContactGroup::find($this->groupId);

        if (! $group) {
            Log::warning("CreateNativeWhatsAppGroupJob: contact_groups#{$this->groupId} no longer exists.");

            return;
        }

        // Defensive, same convention as ProcessGroupDispatchJob: makes a
        // duplicate dispatch a no-op instead of a duplicate live group.
        if ($group->sync_status !== ContactGroup::SYNC_STATUS_PENDING) {
            return;
        }

        try {
            $phones = ContactGroupMember::where('group_id', $group->id)->pluck('phone_number')->all();

            $result = $service->createGroup($group->account_id, $group->name, $phones);

            if ($result['success'] ?? false) {
                $group->forceFill([
                    'wa_group_jid' => $result['jid'] ?? null,
                    'invite_link' => $result['invite_link'] ?? null,
                    'sync_status' => ContactGroup::SYNC_STATUS_SYNCED,
                    'sync_error' => null,
                ])->save();
            } else {
                $group->forceFill([
                    'sync_status' => ContactGroup::SYNC_STATUS_FAILED,
                    'sync_error' => $result['error'] ?? 'Unknown error creating the WhatsApp group.',
                ])->save();
            }
        } catch (Throwable $e) {
            Log::error('CreateNativeWhatsAppGroupJob failed unexpectedly.', [
                'group_id' => $group->id,
                'exception' => $e->getMessage(),
            ]);

            $group->forceFill([
                'sync_status' => ContactGroup::SYNC_STATUS_FAILED,
                'sync_error' => 'Internal error: '.$e->getMessage(),
            ])->save();
        }
    }
}
