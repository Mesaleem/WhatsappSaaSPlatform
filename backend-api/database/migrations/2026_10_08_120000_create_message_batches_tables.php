<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch sends from an uploaded Excel/CSV list (Send Notification page only, not the Developer API).
 *
 * message_batches: one upload, sent in chunks of batch_size every interval_minutes. A batch can be scheduled for a time,
 * paused, resumed and stopped; a stopped batch keeps its sent history and its unsent numbers are marked cancelled.
 * message_batch_items: one row per phone number, so progress and the failure reason of each number are visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('template_id');
            $table->json('variables')->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->string('title', 120);
            $table->string('source_filename', 190)->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedSmallInteger('batch_size')->default(50);
            $table->unsignedSmallInteger('interval_minutes')->default(5);
            $table->string('status', 20);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('next_chunk_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('stop_reason', 120)->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['status', 'next_chunk_at']);
        });

        Schema::create('message_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_batch_id')->constrained('message_batches')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('phone', 20);
            $table->string('status', 16)->default('pending');
            $table->string('error', 255)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            $table->unique(['message_batch_id', 'phone']);
            $table->index(['message_batch_id', 'status', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_batch_items');
        Schema::dropIfExists('message_batches');
    }
};
