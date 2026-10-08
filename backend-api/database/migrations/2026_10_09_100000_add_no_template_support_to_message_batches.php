<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch can send free text instead of a template, the same "No template" option the Send Notification
 * page offers for an individual or group send. template_id becomes optional; message_text holds the typed
 * text for a no-template batch (null for a template batch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->change();
            $table->text('message_text')->nullable()->after('template_id');
        });
    }

    public function down(): void
    {
        Schema::table('message_batches', function (Blueprint $table) {
            $table->dropColumn('message_text');
            $table->unsignedBigInteger('template_id')->nullable(false)->change();
        });
    }
};
