<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `plan_key` references App\Support\PlanCatalog (a static catalog, not
     * a DB table — the spec's migration list names only `invoices`, so no
     * `plans` table was added; see PlanCatalog's docblock). `plan_label`
     * snapshots the plan's display name at purchase time so a historical
     * invoice still reads correctly even if the catalog changes later.
     *
     * `gateway_order_id` is unique — it is how PaymentWebhookController
     * looks up the invoice a webhook event belongs to, and a duplicate
     * would make that lookup ambiguous.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->string('invoice_number')->unique();
            $table->string('plan_key');
            $table->string('plan_label');

            $table->decimal('amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->string('currency', 3)->default('INR');

            $table->string('payment_gateway'); // 'razorpay' | 'stripe'
            $table->string('gateway_order_id')->nullable()->unique();
            $table->string('gateway_payment_id')->nullable();

            $table->string('status')->default('pending'); // 'pending' | 'paid' | 'failed'
            $table->timestamp('paid_at')->nullable();

            // Full gateway response snapshot at time of payment — same
            // audit-trail rationale as payment_alerts.raw_response (Module 7).
            $table->text('gateway_raw_response')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
