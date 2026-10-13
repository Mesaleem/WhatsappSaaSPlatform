<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Sales Phase 3 — payments the TENANT receives from ITS customers (separate from
 * WapHub's own manual_payments / invoices, which bill the tenant).
 *
 * crm_payments             one received payment (manual or gateway). Never edited after
 *                          recording; a mistake is REVERSED (reversed_at + reason), which
 *                          releases its allocations. gateway_payment_id is unique per
 *                          tenant + gateway, so a webhook delivered twice records once.
 * crm_payment_allocations  how much of a payment pays which invoice (one payment may pay
 *                          several invoices; an invoice may get several payments). The
 *                          unallocated rest of a payment stays on it as a credit.
 * crm_gateway_settings     the tenant's OWN Razorpay / Stripe keys (encrypted), so its
 *                          customers pay the tenant directly. webhook_token is the
 *                          unguessable part of the tenant's webhook URL.
 * crm_gateway_orders       the server-side order created for one invoice payment.
 * crm_webhook_events       processed gateway events (unique), so a duplicate or replayed
 *                          delivery is ignored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('financial_year', 7);
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('crm_company_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('method', 20);
            $table->string('reference', 120)->nullable();
            $table->date('paid_on');
            $table->string('status', 12)->default('successful');
            $table->string('source', 10)->default('manual');
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_payment_id', 120)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'number']);
            $table->unique(['account_id', 'gateway', 'gateway_payment_id']);
            $table->unique(['account_id', 'idempotency_key']);
            $table->index(['account_id', 'paid_on']);
            $table->index(['account_id', 'contact_id']);
        });

        Schema::create('crm_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_payment_id')->constrained('crm_payments')->cascadeOnDelete();
            $table->foreignId('crm_document_id')->constrained('crm_documents')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['crm_document_id', 'reversed_at']);
            $table->index(['crm_payment_id', 'reversed_at']);
        });

        Schema::create('crm_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('gateway', 20)->default('razorpay');
            $table->string('mode', 10)->default('test');
            $table->string('key_id', 191)->nullable();
            $table->text('key_secret')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('crm_gateway_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_document_id')->constrained('crm_documents')->cascadeOnDelete();
            $table->string('gateway', 20);
            $table->string('gateway_order_id', 120);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 12)->default('created');
            $table->foreignId('crm_payment_id')->nullable()->constrained('crm_payments')->nullOnDelete();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_order_id']);
            $table->index(['account_id', 'crm_document_id']);
        });

        Schema::create('crm_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20);
            $table->string('event_id', 191);
            $table->string('outcome', 40)->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_webhook_events');
        Schema::dropIfExists('crm_gateway_orders');
        Schema::dropIfExists('crm_gateway_settings');
        Schema::dropIfExists('crm_payment_allocations');
        Schema::dropIfExists('crm_payments');
    }
};
