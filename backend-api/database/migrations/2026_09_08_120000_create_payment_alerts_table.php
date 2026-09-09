<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `payment_ref` is unique PER ACCOUNT (not globally) — two different
     * tenants may legitimately reuse the same payment gateway reference
     * numbering, but the same account must never send two alerts for the
     * same reference. This composite unique index IS the deduplication
     * guard's backstop: PaymentAlertController checks for a duplicate
     * before dispatching, but the index is what makes that check safe
     * under concurrent requests (two simultaneous POSTs for the same
     * payment_ref can't both win the pre-check race).
     */
    public function up(): void
    {
        Schema::create('payment_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->string('recipient_phone');
            $table->string('customer_name');
            $table->decimal('amount', 10, 2);
            $table->string('payment_ref');

            // 'pending' -> 'queued' -> 'sent' | 'failed'. Rows are created
            // directly at 'queued' by PaymentAlertController (dispatch is
            // synchronous with row creation); 'pending' exists for a future
            // caller that stages a row before deciding to send it.
            $table->string('status')->default('pending');

            // Actual per-message cost charged against the account's billing
            // (only non-zero for billing_model = 'per_message'); always
            // 0.0000 when status = 'failed' — a failed send is never billed.
            $table->decimal('cost_deducted', 8, 4)->default(0);

            $table->text('error_reason')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'payment_ref']);
            $table->index(['account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_alerts');
    }
};
