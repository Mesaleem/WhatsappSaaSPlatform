<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A native WhatsApp group belongs to the WhatsApp NUMBER that created it, not just to
 * the slot (seat): since 2026-10-13 a seat can change its number, and the new number
 * is not a member of the old number's groups. `wa_owner_phone` records that number so
 * a send is refused once the seat no longer carries it (SenderNumberResolver).
 *
 * Backfill: existing native groups take the current real number of their slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contact_groups', 'wa_owner_phone')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->string('wa_owner_phone', 20)->nullable()->after('wa_group_jid');
            });
        }

        DB::table('contact_groups')
            ->where('group_type', 'native_wa_group')
            ->whereNull('wa_owner_phone')
            ->whereNotNull('whatsapp_number_id')
            ->orderBy('id')
            ->chunkById(200, function ($groups) {
                foreach ($groups as $group) {
                    $phone = DB::table('whatsapp_numbers')->where('id', $group->whatsapp_number_id)->value('phone_number');
                    if (is_string($phone) && preg_match('/^[1-9][0-9]{7,14}$/', $phone)) {
                        DB::table('contact_groups')->where('id', $group->id)->update(['wa_owner_phone' => $phone]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('contact_groups', 'wa_owner_phone')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->dropColumn('wa_owner_phone');
            });
        }
    }
};
