<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message Logs "View" action: message_dispatch_logs has only ever kept a
 * 160-character `message_preview`, so the complete resolved text a send
 * actually carried was not recoverable. This adds ONE nullable column that
 * holds that resolved text (the same string the dispatcher already passes to
 * MessageDispatchLog::record() as the preview) -- additive, no backfill:
 * rows written before this migration keep a null body and the detail view
 * falls back to their preview. Guarded + reversible like the sibling
 * migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_dispatch_logs', 'message_body')) {
            Schema::table('message_dispatch_logs', function (Blueprint $table) {
                $table->text('message_body')->nullable()->after('message_preview');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('message_dispatch_logs', 'message_body')) {
            Schema::table('message_dispatch_logs', function (Blueprint $table) {
                $table->dropColumn('message_body');
            });
        }
    }
};
