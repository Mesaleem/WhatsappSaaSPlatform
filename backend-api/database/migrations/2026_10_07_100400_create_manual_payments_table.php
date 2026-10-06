<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per manually recorded payment (cash, bank transfer, UPI, cheque, other),
 * made by a Super Admin or an Agent while no payment gateway is configured. It keeps
 * the full details: amount, method, transaction reference, the date paid, the date
 * the paid term starts (which may be set by hand), and who recorded it.
 *
 * transaction_id is unique across the platform, so the same payment cannot be
 * recorded twice. Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_payments')) {
            return;
        }

        Schema::create('manual_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 20);
            $table->string('transaction_id', 120)->unique();
            $table->date('paid_on');
            $table->date('term_starts_on');
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->string('recorded_by_role', 20);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payments');
    }
};
