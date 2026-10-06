<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A template message the client asked to send later. The payload is what the immediate send would
 * have used; the dispatcher reads it when the message is due. Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scheduled_messages')) {
            return;
        }

        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('source', 40)->default('api');
            $table->string('kind', 30);
            $table->json('payload');
            $table->timestamp('send_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
