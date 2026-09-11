<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quota Exhaustion Request Workflow & Custom Invoice Generation.
     *
     * `invoice_id` is nullable and only set once a Super Admin approves
     * the request (QuotaRequestController::approve()) — a pending/rejected
     * request never has an invoice. nullOnDelete() (not cascade) so
     * deleting an invoice later doesn't silently delete audit history of
     * the request that spawned it.
     */
    public function up(): void
    {
        Schema::create('quota_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('requested_extra_messages');
            $table->text('reason')->nullable();

            $table->string('status')->default('pending'); // 'pending' | 'approved' | 'rejected'

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_requests');
    }
};
