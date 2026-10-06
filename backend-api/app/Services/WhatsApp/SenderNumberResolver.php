<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\WhatsAppNumber;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides which of the account's WhatsApp numbers a send goes out from.
 *
 * - Only linked (connected, active) numbers of this account can send. A number that is paused, unlinked or pending
 *   is refused, so a send never silently goes from the wrong line.
 * - No number chosen: the default number is used.
 * - A group send is allowed only when the group is on the sending number. A group belongs to the number it was created
 *   on (a group with no number is treated as on the default number). Otherwise the send is refused and nothing is sent.
 */
class SenderNumberResolver
{
    /** @return Collection<int, WhatsAppNumber> the linked, active numbers the account may send from, default first */
    public function activeNumbers(Account $account): Collection
    {
        return WhatsAppNumber::query()
            ->where('account_id', $account->id)
            ->where('status', WhatsAppNumber::STATUS_LINKED)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /** The default number's id, or null when the account has none. */
    public function defaultId(Account $account): ?int
    {
        return WhatsAppNumber::query()
            ->where('account_id', $account->id)
            ->where('is_default', true)
            ->value('id');
    }

    /**
     * The number id a send uses. Null means "the account's default slot" (the legacy path, when no number is set up).
     *
     * @throws SenderNumberException when the chosen number is not this account's linked number, or the group is not on it
     */
    public function resolve(Account $account, ?int $requestedId, ?ContactGroup $group = null): ?int
    {
        $senderId = $requestedId ?? $this->defaultId($account);

        if ($requestedId !== null) {
            $number = WhatsAppNumber::query()->where('account_id', $account->id)->whereKey($requestedId)->first();

            if (! $number) {
                throw new SenderNumberException('sender_not_found', 'That WhatsApp number is not on this account.', 422);
            }

            if ($number->status !== WhatsAppNumber::STATUS_LINKED) {
                throw new SenderNumberException('sender_not_active', "The number {$number->phone_number} is not connected. Connect it, or choose another number.", 422);
            }
        }

        if ($group !== null) {
            // A group with no number was created before numbers existed: it belongs to the default number.
            $groupNumberId = $group->whatsapp_number_id ?? $this->defaultId($account);

            if ($groupNumberId !== null && $senderId !== $groupNumberId) {
                $groupNumber = WhatsAppNumber::query()->whereKey($groupNumberId)->value('phone_number');

                throw new SenderNumberException(
                    'group_not_on_number',
                    "The group \"{$group->name}\" is not on the chosen number. It belongs to {$groupNumber}. Please check and choose that number.",
                    422,
                );
            }
        }

        return $senderId;
    }
}
