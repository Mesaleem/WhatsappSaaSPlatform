<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppNumberChangeRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A client whose WhatsApp slot holds a wrongly entered number asks for a change. A Super Admin,
 * or the agent for its own client, approves it: the slot takes the new number, its old session
 * is logged out, and it must be linked again. Nothing changes until someone approves.
 */
class WhatsAppNumberChangeService
{
    public function __construct(
        private readonly WhatsAppNumberService $numbers,
        private readonly WhatsAppAddonService $addons,
    ) {
    }

    public function request(Account $account, User $actor, int $numberId, string $newRaw, string $reason): WhatsAppNumberChangeRequest
    {
        $slot = WhatsAppNumber::query()->where('account_id', $account->id)->whereKey($numberId)->first();
        if ($slot === null) {
            throw new WhatsAppNumberException('WhatsApp number not found.', 'not_found', 404);
        }

        $newPhone = $this->numbers->normalize($newRaw);

        if ($newPhone === $slot->phone_number) {
            throw new WhatsAppNumberException('That is the number already on this slot. Enter the number you meant to use.', 'same_number', 422);
        }

        $this->assertNumberFree($newPhone);

        $open = WhatsAppNumberChangeRequest::query()
            ->where('whatsapp_number_id', $slot->id)
            ->where('status', WhatsAppNumberChangeRequest::REQUESTED)
            ->exists();
        if ($open) {
            throw new WhatsAppNumberException('A change for this number is already waiting for approval.', 'request_pending', 409);
        }

        $row = WhatsAppNumberChangeRequest::query()->create([
            'account_id' => $account->id,
            'whatsapp_number_id' => $slot->id,
            'old_phone' => $slot->phone_number,
            'new_phone' => $newPhone,
            'reason' => trim($reason),
            'status' => WhatsAppNumberChangeRequest::REQUESTED,
            'requested_by_user_id' => $actor->id,
        ]);

        return $row;
    }

    /** Requests the caller may act on: all for a Super Admin, its own clients for an agent. */
    public function pendingFor(User $actor): Collection
    {
        $query = WhatsAppNumberChangeRequest::query()
            ->with('account:id,company_name,agent_id')
            ->where('status', WhatsAppNumberChangeRequest::REQUESTED)
            ->orderByDesc('id');

        if ($actor->hasRole('super_admin')) {
            return $query->get();
        }

        if (! $actor->hasRole('agent')) {
            throw new WhatsAppNumberException('Only a Super Admin or an Agent can see these requests.', 'not_allowed', 403);
        }

        return $query->whereHas('account', fn ($q) => $q->where('agent_id', $actor->account_id))->get();
    }

    public function approve(WhatsAppNumberChangeRequest $row, User $actor): WhatsAppNumberChangeRequest
    {
        $this->assertCanManage($row->account_id, $actor);

        return DB::transaction(function () use ($row, $actor): WhatsAppNumberChangeRequest {
            $locked = WhatsAppNumberChangeRequest::query()->lockForUpdate()->whereKey($row->id)->firstOrFail();

            if ($locked->status !== WhatsAppNumberChangeRequest::REQUESTED) {
                throw new WhatsAppNumberException('Only a new request can be approved.', 'not_requested', 409);
            }

            $slot = WhatsAppNumber::query()->lockForUpdate()->whereKey($locked->whatsapp_number_id)->first();
            if ($slot === null) {
                throw new WhatsAppNumberException('WhatsApp number not found.', 'not_found', 404);
            }

            $this->assertNumberFree($locked->new_phone);

            $wasLinked = in_array($slot->status, [WhatsAppNumber::STATUS_LINKED, WhatsAppNumber::STATUS_PAUSED], true);

            try {
                $slot->forceFill([
                    'phone_number' => $locked->new_phone,
                    'status' => $wasLinked ? WhatsAppNumber::STATUS_UNLINKED : $slot->status,
                ])->save();
            } catch (QueryException $e) {
                // Another slot took the number between the check above and this write.
                throw new WhatsAppNumberException('This WhatsApp number is already added.', 'number_already_used', 409);
            }

            $locked->forceFill([
                'status' => WhatsAppNumberChangeRequest::APPROVED,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ])->save();

            if ($wasLinked) {
                // The old device belonged to the wrong number: its session must not stay up.
                $this->addons->disconnectEngine($slot);
            }

            $this->notifyRequester($locked, 'WhatsApp number changed', 'The number on this slot is now +'.$locked->new_phone.'. Connect it again on the WhatsApp Setup page.');

            return $locked->refresh();
        });
    }

    public function reject(WhatsAppNumberChangeRequest $row, User $actor, ?string $note): WhatsAppNumberChangeRequest
    {
        $this->assertCanManage($row->account_id, $actor);

        if ($row->status !== WhatsAppNumberChangeRequest::REQUESTED) {
            throw new WhatsAppNumberException('Only a new request can be rejected.', 'not_requested', 409);
        }

        $row->forceFill([
            'status' => WhatsAppNumberChangeRequest::REJECTED,
            'decided_by_user_id' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->notifyRequester($row, 'Number change not approved', 'The change to +'.$row->new_phone.' was not approved.'.($note ? ' Note: '.$note : ''));

        return $row->refresh();
    }

    /** Super Admin: any account. Agent: only its own clients (never a direct one). Anyone else: refused. */
    private function assertCanManage(int $accountId, User $actor): void
    {
        if ($actor->hasRole('super_admin')) {
            return;
        }

        if (! $actor->hasRole('agent')) {
            throw new WhatsAppNumberException('Only a Super Admin or an Agent can decide this.', 'not_allowed', 403);
        }

        $account = Account::query()->find($accountId);
        if ($account === null || $account->agent_id === null) {
            throw new WhatsAppNumberException('This account signed up directly. Only a Super Admin can decide its request.', 'self_signup_needs_super_admin', 403);
        }

        if ((int) $account->agent_id !== (int) $actor->account_id) {
            throw new WhatsAppNumberException('This account is not one of your clients.', 'not_your_client', 403);
        }
    }

    /** A number belongs to one slot platform-wide. Deliberately vague about where it is used. */
    private function assertNumberFree(string $phone): void
    {
        if (WhatsAppNumber::query()->where('phone_number', $phone)->exists()) {
            throw new WhatsAppNumberException(
                'This WhatsApp number is already added. Each number can be used by only one WhatsApp slot.',
                'number_already_used',
                409,
            );
        }
    }

    private function notifyRequester(WhatsAppNumberChangeRequest $row, string $title, string $body): void
    {
        $userId = $row->requested_by_user_id ?? User::query()->where('account_id', $row->account_id)->value('id');

        if ($userId === null) {
            return;
        }

        InAppNotification::query()->create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'category' => 'whatsapp',
            'link' => '/settings/whatsapp',
        ]);
    }
}
