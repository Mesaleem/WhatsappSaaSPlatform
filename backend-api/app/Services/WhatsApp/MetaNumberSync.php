<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * QA fixes (2026-10-14) — the Meta (Cloud API) number is a sender number too.
 *
 * Bulk sends, Excel/CSV batches and multi-number Send all pick their senders
 * from whatsapp_numbers (SenderNumberResolver), and only QR slots ever
 * created rows there — so a Meta Pro client, whose plan includes broadcasts,
 * always got "No connected WhatsApp number". When Meta credentials are saved
 * (manually or through Embedded Signup) the account's default number row is
 * set to the Meta number and marked linked; when Meta is disconnected it is
 * marked unlinked.
 */
class MetaNumberSync
{
    public function connected(Account $account, ?string $displayPhoneNumber = null): ?WhatsAppNumber
    {
        $session = WhatsAppSession::query()->where('account_id', $account->id)->first();
        if ($session === null || ! filled($session->meta_phone_number_id)) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', (string) ($displayPhoneNumber ?? $session->connected_phone_number ?? ''));
        // A number Meta did not tell us yet gets a stable placeholder (isPlaceholder()).
        $phone = preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? $digits : WhatsAppNumber::PENDING_PLACEHOLDER_PREFIX.'meta-'.$session->meta_phone_number_id;

        if ($digits !== '' && $session->connected_phone_number !== $digits) {
            $session->forceFill(['connected_phone_number' => $digits])->save();
        }

        $row = WhatsAppNumber::query()->where('account_id', $account->id)->where('is_default', true)->first()
            ?? WhatsAppNumber::query()->where('account_id', $account->id)->orderBy('id')->first();

        try {
            if ($row === null) {
                return WhatsAppNumber::query()->create([
                    'account_id' => $account->id,
                    'phone_number' => $phone,
                    'is_included' => true,
                    'is_default' => true,
                    'status' => WhatsAppNumber::STATUS_LINKED,
                ]);
            }

            $row->forceFill(['phone_number' => $phone, 'is_default' => true, 'status' => WhatsAppNumber::STATUS_LINKED])->save();

            return $row;
        } catch (QueryException $e) {
            // The number is already a slot elsewhere (unique index): sending still works
            // through the session; only the sender row is missing.
            Log::warning('MetaNumberSync: could not record the Meta number as a sender.', ['account_id' => $account->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function disconnected(Account $account): void
    {
        WhatsAppNumber::query()->where('account_id', $account->id)->where('is_default', true)
            ->update(['status' => WhatsAppNumber::STATUS_UNLINKED]);
    }
}
