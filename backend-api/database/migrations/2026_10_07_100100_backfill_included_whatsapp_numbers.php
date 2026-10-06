<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 backfill: every account that already has a known WhatsApp number gets
 * that number as its included, default slot in whatsapp_numbers.
 *
 * Source: whatsapp_sessions.connected_phone_number (recorded on connect since
 * 2026-10-05). Accounts with no known number are skipped, and so is any number
 * already in the table (the global unique rule must hold). Data-only and
 * reversible: down() removes exactly the rows this migration created.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sessions = DB::table('whatsapp_sessions')
            ->whereNotNull('connected_phone_number')
            ->get(['account_id', 'connected_phone_number', 'status']);

        foreach ($sessions as $session) {
            $phone = (string) $session->connected_phone_number;

            $alreadyHasSlot = DB::table('whatsapp_numbers')->where('account_id', $session->account_id)->exists();
            $phoneTaken = DB::table('whatsapp_numbers')->where('phone_number', $phone)->exists();

            if ($alreadyHasSlot || $phoneTaken) {
                continue;
            }

            DB::table('whatsapp_numbers')->insert([
                'account_id' => $session->account_id,
                'phone_number' => $phone,
                'is_included' => true,
                'is_default' => true,
                'status' => $session->status === 'connected' ? 'linked' : 'unlinked',
                'locked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $sessions = DB::table('whatsapp_sessions')
            ->whereNotNull('connected_phone_number')
            ->get(['account_id', 'connected_phone_number']);

        foreach ($sessions as $session) {
            DB::table('whatsapp_numbers')
                ->where('account_id', $session->account_id)
                ->where('phone_number', $session->connected_phone_number)
                ->where('is_included', true)
                ->delete();
        }
    }
};
