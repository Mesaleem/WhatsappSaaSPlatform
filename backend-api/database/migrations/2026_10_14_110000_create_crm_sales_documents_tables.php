<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Sales Phase 2 — products and sales documents (tenant -> its own customers; NOT
 * WapHub's own `invoices` table, which bills the tenant).
 *
 * crm_business_profiles   the tenant's seller details printed on documents (GSTIN, state
 *                         code for CGST+SGST vs IGST, address, bank / UPI, terms).
 * crm_products            the catalogue; a document copies (snapshots) what it uses.
 * crm_documents           ONE table for proposal / quotation / customer_po / sales_order /
 *                         invoice / credit_note: shared numbering, PDF, delivery and
 *                         payment plumbing. Customer and seller details are frozen in json
 *                         snapshots when the document is issued; totals are decimal(14,2).
 *                         `number` stays NULL while a draft (no gaps from drafts) and is
 *                         unique per tenant + type. `public_token` is the unguessable key of
 *                         the share / pay link (Phase 4).
 * crm_document_items      snapshot line items (description, qty, rate, discount, GST split).
 * crm_document_counters   per tenant, type and financial year (April–March); the row is
 *                         locked while a number is taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name', 191)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('address_line1', 191)->nullable();
            $table->string('address_line2', 191)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->text('bank_details')->nullable();
            $table->string('upi_id', 100)->nullable();
            $table->text('default_terms')->nullable();
            $table->boolean('round_off')->default(true);
            $table->unsignedSmallInteger('default_due_days')->default(15);
            $table->timestamps();
        });

        Schema::create('crm_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->nullable();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('unit', 20)->default('nos');
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('hsn_sac', 20)->nullable();
            $table->string('billing_frequency', 10)->default('one_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'code']);
            $table->index(['account_id', 'is_active', 'name']);
        });

        Schema::create('crm_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('draft');
            $table->string('number', 40)->nullable();
            $table->string('financial_year', 7)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('crm_deal_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('crm_company_id')->nullable();
            $table->string('title', 191)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->string('tax_mode', 10)->default('none');
            $table->string('place_of_supply', 2)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('taxable_total', 14, 2)->default(0);
            $table->decimal('cgst_total', 14, 2)->default(0);
            $table->decimal('sgst_total', 14, 2)->default(0);
            $table->decimal('igst_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('round_off', 6, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_credited', 14, 2)->default(0);
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->json('customer_snapshot')->nullable();
            $table->json('seller_snapshot')->nullable();
            $table->text('body')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->string('public_token', 64)->unique();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'account_id']);
            $table->unique(['account_id', 'type', 'number']);
            $table->index(['account_id', 'type', 'status']);
            $table->index(['account_id', 'crm_deal_id']);
            $table->index(['account_id', 'contact_id']);
            $table->index(['account_id', 'crm_company_id']);
            $table->index(['account_id', 'type', 'due_date']);
            $table->index('parent_id');
        });

        Schema::create('crm_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_document_id')->constrained('crm_documents')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('crm_product_id')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('description', 500);
            $table->string('hsn_sac', 20)->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit', 20)->default('nos');
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['crm_document_id', 'position']);
        });

        Schema::create('crm_document_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('financial_year', 7);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'type', 'financial_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_document_counters');
        Schema::dropIfExists('crm_document_items');
        Schema::dropIfExists('crm_documents');
        Schema::dropIfExists('crm_products');
        Schema::dropIfExists('crm_business_profiles');
    }
};
