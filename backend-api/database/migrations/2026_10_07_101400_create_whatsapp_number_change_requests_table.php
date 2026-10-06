<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client asks for a WhatsApp number slot to hold a different number (the number was entered
 * wrongly). A Super Admin, or the agent for its own client, approves or rejects it. Additive
 * and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_number_change_requests')) {
            return;
        }

        Schema::create('whatsapp_number_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('whatsapp_number_id')->constrained('whatsapp_numbers')->cascadeOnDelete();
            $table->string('old_phone', 20);
            $table->string('new_phone', 20);
            $table->text('reason');
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['whatsapp_number_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_number_change_requests');
    }
};
