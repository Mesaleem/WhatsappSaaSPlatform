<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a batch run came from: 'file' (an uploaded Excel/CSV list) or 'bulk' (the numbers typed on the Send page).
 * Both are listed in the same history, with the numbers they were sent from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_batches', function (Blueprint $table) {
            $table->string('kind', 16)->default('file')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('message_batches', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
