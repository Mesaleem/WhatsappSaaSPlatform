<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sender numbers. A contact group belongs to the WhatsApp number it was created on, so a group send goes out from
 * that number only. A batch sends from one or more chosen numbers, round-robin; each batch item records its sender.
 *
 * Existing groups are assigned to the account's default number, which is where they were sent from before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_groups', function (Blueprint $table) {
            $table->foreignId('whatsapp_number_id')->nullable()->after('account_id')
                ->constrained('whatsapp_numbers')->nullOnDelete();
            $table->index(['account_id', 'whatsapp_number_id'], 'contact_groups_account_number');
        });

        // Backfill: every existing group goes to its account's default number (null when the account has none yet).
        DB::table('contact_groups')->whereNull('whatsapp_number_id')->orderBy('id')->chunkById(500, function ($groups) {
            foreach ($groups as $group) {
                $defaultId = DB::table('whatsapp_numbers')
                    ->where('account_id', $group->account_id)
                    ->where('is_default', true)
                    ->value('id');

                if ($defaultId !== null) {
                    DB::table('contact_groups')->where('id', $group->id)->update(['whatsapp_number_id' => $defaultId]);
                }
            }
        });

        Schema::table('message_batches', function (Blueprint $table) {
            $table->json('sender_number_ids')->nullable()->after('template_id');
        });

        Schema::table('message_batch_items', function (Blueprint $table) {
            $table->foreignId('sender_number_id')->nullable()->after('phone')
                ->constrained('whatsapp_numbers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_batch_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_number_id');
        });
        Schema::table('message_batches', function (Blueprint $table) {
            $table->dropColumn('sender_number_ids');
        });
        Schema::table('contact_groups', function (Blueprint $table) {
            $table->dropIndex('contact_groups_account_number');
            $table->dropConstrainedForeignId('whatsapp_number_id');
        });
    }
};
