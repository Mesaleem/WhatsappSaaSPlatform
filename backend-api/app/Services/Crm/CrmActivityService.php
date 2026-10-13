<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\CrmActivity;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 1 — activities and the timeline.
 *
 * A user creates calls, meetings, tasks, emails, WhatsApp follow-ups and notes. A note
 * is a record of something, not a to-do, so it is stored already done. logEvent() is
 * the system's own entry (lead converted, deal won, stage changed): always done, never
 * editable through the API.
 */
class CrmActivityService
{
    /** @param array<string, mixed> $data */
    public function create(Account $account, array $data, ?int $actorUserId): CrmActivity
    {
        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, CrmActivity::USER_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['The selected type is invalid.']]);
        }

        $done = $type === CrmActivity::TYPE_NOTE || ($data['status'] ?? null) === CrmActivity::STATUS_DONE;

        return CrmActivity::create([
            'account_id' => $account->id,
            'created_by_user_id' => $actorUserId,
            'status' => $done ? CrmActivity::STATUS_DONE : CrmActivity::STATUS_PENDING,
            'completed_at' => $done ? now() : null,
        ] + $this->only($data, ['type', 'subject', 'body', 'due_at', 'priority', 'outcome', 'assigned_user_id', ...CrmActivity::LINKS]));
    }

    /** @param array<string, mixed> $data */
    public function update(CrmActivity $activity, array $data): CrmActivity
    {
        $this->assertEditable($activity);

        $activity->fill($this->only($data, ['subject', 'body', 'due_at', 'priority', 'outcome', 'assigned_user_id']));

        if (array_key_exists('status', $data) && $data['status'] !== $activity->status) {
            $activity->status = $data['status'];
            $activity->completed_at = $data['status'] === CrmActivity::STATUS_DONE ? now() : null;
        }

        $activity->save();

        return $activity->fresh();
    }

    public function delete(CrmActivity $activity): void
    {
        $this->assertEditable($activity);

        $activity->delete();
    }

    /**
     * The system's timeline entry. Links as for any activity.
     *
     * @param array<string, int|null> $links
     */
    public function logEvent(int $accountId, string $subject, array $links, ?int $actorUserId = null, ?string $body = null): ?CrmActivity
    {
        // An event belongs on a record's timeline; with nothing to link to (a payment with no customer, say)
        // there is no timeline, and the action itself must not fail because of it.
        if (collect(CrmActivity::LINKS)->every(fn (string $f) => empty($links[$f]))) {
            return null;
        }

        return CrmActivity::create([
            'account_id' => $accountId,
            'type' => CrmActivity::TYPE_EVENT,
            'subject' => mb_substr($subject, 0, 191),
            'body' => $body,
            'status' => CrmActivity::STATUS_DONE,
            'completed_at' => now(),
            'created_by_user_id' => $actorUserId,
        ] + array_intersect_key($links, array_flip(CrmActivity::LINKS)));
    }

    private function assertEditable(CrmActivity $activity): void
    {
        if ($activity->type === CrmActivity::TYPE_EVENT) {
            throw ValidationException::withMessages(['activity' => ['A system event cannot be changed.']]);
        }
    }

    /** @param array<string, mixed> $data @param list<string> $keys @return array<string, mixed> */
    private function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }
}
