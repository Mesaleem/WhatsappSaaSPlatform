<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message Log "View Message": historical template snapshot.
 *
 * Before this column, a template send kept only template_name plus a
 * message_preview cut to 160 characters. The full rendered text, the
 * template body as it was at send time, and the variables used were not
 * stored anywhere, and message_templates rows can be edited after a send.
 * So "exactly what did the customer receive" could not be answered
 * without guessing from the CURRENT template, which is not allowed.
 *
 * One nullable JSON column, written only by TemplateMessageDispatcher and
 * GroupMessageDispatcher (see MessageDispatchLog::record() /
 * recordGroupDispatchQueued()). Every existing row stays NULL, so the
 * detail endpoint reports "no snapshot" for sends made before this
 * migration rather than inventing one.
 *
 * Additive and nullable, so it is safe on a live table. Writers check
 * Schema::hasColumn() first, so deploying the code before running this
 * migration does not break sending.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_dispatch_logs', 'template_snapshot')) {
            Schema::table('message_dispatch_logs', function (Blueprint $table) {
                $table->json('template_snapshot')->nullable()->after('message_preview');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('message_dispatch_logs', 'template_snapshot')) {
            Schema::table('message_dispatch_logs', function (Blueprint $table) {
                $table->dropColumn('template_snapshot');
            });
        }
    }
};
