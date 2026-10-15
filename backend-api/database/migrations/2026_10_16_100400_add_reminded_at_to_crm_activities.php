<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** QA fixes (2026-10-14) — crm:send-task-reminders sends each task's reminder once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_activities', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_activities', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
