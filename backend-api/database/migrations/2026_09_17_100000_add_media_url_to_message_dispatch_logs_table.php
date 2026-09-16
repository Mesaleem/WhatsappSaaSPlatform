<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message Logs / Audit Trail -- persists the actual media_url a send
 * attached, alongside the pre-existing `has_media` boolean.
 *
 * [Bug fix, disclosed]: `has_media` itself was only just wired up to
 * reflect real sends (see MessageDispatchLog::record()'s own docblock)
 * -- it was previously hardcoded false for every row. This migration
 * goes one step further per explicit request: the Message Logs /
 * Audit Trail should show WHICH file was sent, not just whether one
 * was, so the actual URL is now persisted too (nullable -- null for
 * every non-media send, and for a historical row from before this
 * column existed).
 *
 * Idempotent / production-safe, matching this project's established
 * migration convention (see PROJECT_ARCHITECTURE_DATABASE_BLUEPRINT.md):
 * guarded by Schema::hasColumn() so it can run safely regardless of
 * whether it has partially applied before, and down() is a real,
 * non-inert reversal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_dispatch_logs', 'media_url')) {
            Schema::table('message_dispatch_logs', function (Blueprint $table) {
                $table->string('media_url', 2048)->nullable()->after('has_media');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('message_dispatch_logs', 'media_url')) {
            Schema::table('message_dispatch_logs', function (Blueprint $table) {
                $table->dropColumn('media_url');
            });
        }
    }
};
