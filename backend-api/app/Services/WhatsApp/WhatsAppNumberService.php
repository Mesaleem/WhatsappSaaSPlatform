<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\WhatsAppNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp number slots for an account: the plan's included number plus paid
 * add-ons. Rules (agreed 2026-10-05):
 *  - a phone number belongs to at most ONE slot across all accounts (global unique);
 *  - the first number an account adds is the included one and the default;
 *  - numbers and the default can change only until the plan + add-ons are paid
 *    (locked_at), and stay locked until the plan expires;
 *  - only an unpaid, non-included number can be removed.
 *
 * Payment, invoices and linking live in later phases. This service is the single
 * place that enforces the rules above.
 */
class WhatsAppNumberService
{
    /** Digits only, country code first, no leading zero; E.164 without the +. */
    private const PHONE_PATTERN = '/^[1-9][0-9]{7,14}$/';

    /** @return array<int, WhatsAppNumber> */
    public function listFor(Account $account): array
    {
        return WhatsAppNumber::query()
            ->where('account_id', $account->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function isLocked(Account $account): bool
    {
        return WhatsAppNumber::query()
            ->where('account_id', $account->id)
            ->whereNotNull('locked_at')
            ->exists();
    }

    public function normalize(string $raw): string
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);

        // Exactly 10 digits is almost always a national number without its country code.
        if (strlen($digits) === 10) {
            throw new WhatsAppNumberException(
                'This number is missing its country code. Add it in front, for example 91 for India.',
                'missing_country_code',
            );
        }

        if (! preg_match(self::PHONE_PATTERN, $digits)) {
            throw new WhatsAppNumberException(
                'Enter the full phone number with country code and no + or spaces, for example 919876543210.',
                'invalid_phone_number',
            );
        }

        return $digits;
    }

    public function add(Account $account, string $raw): WhatsAppNumber
    {
        $phone = $this->normalize($raw);

        if ($this->isLocked($account)) {
            throw new WhatsAppNumberException(
                'Your WhatsApp numbers are locked until your plan expires.',
                'numbers_locked',
                409,
            );
        }

        // Uniqueness is decided by the database (unique index), so two requests
        // racing for the same number cannot both succeed.
        try {
            return DB::transaction(function () use ($account, $phone): WhatsAppNumber {
                $isFirst = ! WhatsAppNumber::query()->where('account_id', $account->id)->exists();

                return WhatsAppNumber::query()->create([
                    'account_id' => $account->id,
                    'phone_number' => $phone,
                    'is_included' => $isFirst,
                    'is_default' => $isFirst,
                    'status' => WhatsAppNumber::STATUS_PENDING_PAYMENT,
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // Deliberately vague about WHERE the number is used: it may belong to another tenant.
                throw new WhatsAppNumberException(
                    'This WhatsApp number is already added. Each number can be used by only one WhatsApp slot.',
                    'number_already_used',
                    409,
                );
            }
            throw $e;
        }
    }

    /**
     * Which numbers may become the default right now. The default changes only when
     * every number on the account is linked with a running term, and the subscription
     * is active. The chosen number must itself be linked, paid, and not already the
     * default. Returns number id => allowed.
     *
     * @return array<int, bool>
     */
    public function defaultEligibility(Account $account): array
    {
        $slots = WhatsAppNumber::query()->where('account_id', $account->id)->get();
        $subscriptionActive = $account->hasActiveSubscription();
        $allLinked = $slots->every(fn (WhatsAppNumber $s) => $this->isLinkedAndRunning($s));

        $eligible = [];
        foreach ($slots as $slot) {
            $eligible[$slot->id] = $subscriptionActive && $allLinked && ! $slot->is_default && $this->isLinkedAndRunning($slot);
        }

        return $eligible;
    }

    public function setDefault(Account $account, int $id): WhatsAppNumber
    {
        $target = $this->findOwned($account, $id);

        if (! ($this->defaultEligibility($account)[$target->id] ?? false)) {
            throw new WhatsAppNumberException(
                'The default number can be changed only when all your numbers are linked and your subscription is active.',
                'default_not_allowed',
                409,
            );
        }

        return DB::transaction(function () use ($account, $target): WhatsAppNumber {
            WhatsAppNumber::query()->where('account_id', $account->id)->update(['is_default' => false]);
            $target->forceFill(['is_default' => true])->save();

            return $target->refresh();
        });
    }

    /**
     * Self-service number change (agreed 2026-10-07): a client may retype the number on
     * any slot that is NOT currently linked (connect it first "disconnects" it, or it was
     * never connected). No approval is required -- this replaces the approval flow for the
     * common case of a wrongly typed number. The slot's paid/locked state, invoice and
     * default flag are untouched; only phone_number changes, and the slot stays whatever
     * status it already was (unlinked/paused/pending_payment) so the normal Connect button
     * picks it up for re-pairing.
     */
    public function changeNumber(Account $account, int $id, string $raw): WhatsAppNumber
    {
        $target = $this->findOwned($account, $id);

        if ($target->status === WhatsAppNumber::STATUS_LINKED) {
            throw new WhatsAppNumberException(
                'Disconnect this number first, then you can change it.',
                'must_disconnect_first',
                409,
            );
        }

        $phone = $this->normalize($raw);

        if ($phone === $target->phone_number) {
            throw new WhatsAppNumberException(
                'That is the number already on this slot.',
                'same_number',
                422,
            );
        }

        try {
            $target->forceFill(['phone_number' => $phone])->save();
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new WhatsAppNumberException(
                    'This WhatsApp number is already added. Each number can be used by only one WhatsApp slot.',
                    'number_already_used',
                    409,
                );
            }
            throw $e;
        }

        return $target->refresh();
    }

    public function remove(Account $account, int $id): void
    {
        if ($this->isLocked($account)) {
            throw new WhatsAppNumberException(
                'Your WhatsApp numbers are locked until your plan expires.',
                'numbers_locked',
                409,
            );
        }

        $target = $this->findOwned($account, $id);

        if ($target->is_included) {
            throw new WhatsAppNumberException('The number included in your plan cannot be removed.', 'included_number');
        }

        if ($target->status !== WhatsAppNumber::STATUS_PENDING_PAYMENT) {
            throw new WhatsAppNumberException('Only a number that has not been paid for can be removed.', 'number_in_use');
        }

        // The number is removed and its invoice repriced in one step, so the bill always
        // matches the numbers still in the purchase (and an empty purchase is cancelled).
        DB::transaction(function () use ($target): void {
            $invoiceId = $target->addon_invoice_id;
            $phone = $target->phone_number;
            $target->delete();

            if ($invoiceId !== null) {
                app(WhatsAppAddonService::class)->dropNumberFromInvoice((int) $invoiceId, (string) $phone);
            }
        });
    }

    /**
     * Locks the account's numbers and default, once plan + add-ons are paid.
     * Called by the payment phase; it is the only way to set locked_at.
     */
    public function lockAll(Account $account): void
    {
        WhatsAppNumber::query()
            ->where('account_id', $account->id)
            ->whereNull('locked_at')
            ->update(['locked_at' => now()]);
    }

    /** Linked, and its paid term has not ended (the included number has no term of its own). */
    private function isLinkedAndRunning(WhatsAppNumber $slot): bool
    {
        if ($slot->status !== WhatsAppNumber::STATUS_LINKED) {
            return false;
        }

        return $slot->term_ends_at === null || $slot->term_ends_at->isFuture();
    }

    private function findOwned(Account $account, int $id): WhatsAppNumber
    {
        // Another tenant's id is reported as not found, never as "belongs to someone else".
        $number = WhatsAppNumber::query()->where('account_id', $account->id)->whereKey($id)->first();

        if ($number === null) {
            throw new WhatsAppNumberException('WhatsApp number not found.', 'not_found', 404);
        }

        return $number;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000 = integrity constraint violation (MySQL/MariaDB duplicate key, SQLite unique).
        return str_contains((string) $e->getCode(), '23000') || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
