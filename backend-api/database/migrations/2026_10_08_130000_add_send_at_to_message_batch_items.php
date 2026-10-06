<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The time each number of a batch is due to be sent, in UTC. Numbers are spaced 25 seconds apart so WhatsApp does not
 * see a burst; the every-minute runner sends each one at its time (see MessageBatchService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_batch_items', function (Blueprint $table) {
            $table->timestamp('send_at')->nullable()->after('sequence');
            $table->index(['message_batch_id', 'status', 'send_at'], 'mbi_batch_status_send_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_batch_items', function (Blueprint $table) {
            $table->dropIndex('mbi_batch_status_send_at');
            $table->dropColumn('send_at');
        });
    }
};
