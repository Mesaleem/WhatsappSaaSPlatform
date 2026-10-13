<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Sales — verification follow-up: record WHO accepted / rejected / approved /
 * cancelled / voided a document (the spec's "who created, edited, approved and issued";
 * created / updated / issued were already stored). Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_documents', function (Blueprint $table) {
            $table->foreignId('decided_by_user_id')->nullable()->after('decided_at')->constrained('users')->nullOnDelete();
            $table->string('attachment_name', 191)->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('crm_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by_user_id');
            $table->dropColumn('attachment_name');
        });
    }
};
