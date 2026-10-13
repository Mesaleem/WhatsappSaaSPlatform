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

        // No number chosen (Developer API, the groups page): a native group goes from ITS
        // OWN number, which must be connected -- never silently from another line.
        if ($requestedId === null && $group !== null && $group->group_type === ContactGroup::GROUP_TYPE_NATIVE && $group->whatsapp_number_id !== null) {
            $own = WhatsAppNumber::query()->where('account_id', $account->id)->whereKey($group->whatsapp_number_id)->first();
            if ($own !== null) {
                if ($own->status !== WhatsAppNumber::STATUS_LINKED) {
                    throw new SenderNumberException('sender_not_active', "The group \"{$group->name}\" belongs to {$own->phone_number}, which is not connected. Connect it to send to this group.", 422);
                }
                $senderId = $own->id;
            }
        }

        if ($requestedId !== null) {
            $number = WhatsAppNumber::query()->where('account_id', $account->id)->whereKey($requestedId)->first();

            if (! $number) {
                throw new SenderNumberException('sender_not_found', 'That WhatsApp number is not on this account.', 422);
            }

            if ($number->status !== WhatsAppNumber::STATUS_LINKED) {
                throw new SenderNumberException('sender_not_active', "The number {$number->phone_number} is not connected. Connect it, or choose another number.", 422);
            }
        }

        // Only a NATIVE WhatsApp group is tied to a number: it exists inside one WhatsApp
        // account, so it can only be messaged from that account. A custom (internal) group
        // is just a list of contacts messaged one by one, so it goes from whichever number
        // is chosen -- the default when none is (owner rule, 2026-10-13).
        if ($group !== null && $group->group_type === ContactGroup::GROUP_TYPE_NATIVE) {
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

            // Seat model: a slot can change its number, and the new number is not a member
            // of the old number's groups. Refuse instead of letting WhatsApp drop the send.
            $slotPhone = $groupNumberId !== null ? WhatsAppNumber::query()->whereKey($groupNumberId)->value('phone_number') : null;
            if ($group->wa_owner_phone !== null && $slotPhone !== null && $slotPhone !== $group->wa_owner_phone) {
                throw new SenderNumberException(
                    'group_number_changed',
                    "The group \"{$group->name}\" belongs to WhatsApp {$group->wa_owner_phone}, but that slot now has {$slotPhone}. Reconnect {$group->wa_owner_phone} to send to this group.",
                    422,
                );
            }
        }

        return $senderId;
    }

    /** The default slot's real phone number, or null while it has none (placeholder). */
    public function defaultPhone(Account $account): ?string
    {
        $phone = WhatsAppNumber::query()->where('account_id', $account->id)->where('is_default', true)->value('phone_number');

        return is_string($phone) && preg_match('/^[1-9][0-9]{7,14}$/', $phone) ? $phone : null;
    }

    /**
     * Native groups that can be messaged from $senderId right now: on that slot, and
     * owned by the number the slot currently carries. Custom groups are not filtered.
     */
    public function nativeGroupIdsSendableFrom(Account $account, ?int $senderId): array
    {
        $senderId ??= $this->defaultId($account);
        $slotPhone = $senderId !== null ? WhatsAppNumber::query()->whereKey($senderId)->value('phone_number') : null;
        $defaultId = $this->defaultId($account);

        return ContactGroup::query()
            ->where('account_id', $account->id)
            ->where('group_type', ContactGroup::GROUP_TYPE_NATIVE)
            ->get(['id', 'whatsapp_number_id', 'wa_owner_phone'])
            ->filter(fn (ContactGroup $g) => (int) ($g->whatsapp_number_id ?? $defaultId) === (int) $senderId
                && ($g->wa_owner_phone === null || $g->wa_owner_phone === $slotPhone))
            ->pluck('id')
            ->values()
            ->all();
    }
}
