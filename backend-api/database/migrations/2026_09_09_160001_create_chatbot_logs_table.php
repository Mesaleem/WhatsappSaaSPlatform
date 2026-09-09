<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `chatbot_rule_id` is nullable and set null (not cascade-deleted) if
     * the rule that produced a log row is later deleted — the execution
     * history is a durable record and must survive a rule's own lifecycle;
     * it is ALSO left null on a genuine "no rule matched, no fallback"
     * (`status` = 'ignored') row, since there was never a triggering rule
     * to reference. `reply_sent` is nullable for the same 'ignored' case,
     * and for 'failed' rows where nothing was actually delivered.
     */
    public function up(): void
    {
        Schema::create('chatbot_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatbot_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender_phone');
            $table->text('incoming_message');
            $table->text('reply_sent')->nullable();
            $table->string('status'); // 'replied' | 'ignored' | 'failed'
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_logs');
    }
};
