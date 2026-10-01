<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Task 4 — the generic Billing & Collections core. Three tables, industry-agnostic: nothing here
 * knows what a student, patient or buyer is.
 *
 *   contacts (CRM) ◄── collection_charge_assignments ──► collection_charge_items
 *                                   ▲
 *                          collection_payments
 *
 * Customer reference: the platform's existing customer abstraction, the CRM `contacts` row (every
 * industry module hangs its profile off a contact — see config/industries.php `crm_anchor`). It is a plain
 * composite FK, NOT a polymorphic (type, id) pair, so ownership is a database guarantee. Education resolves
 * a student to its contact in its own adapter; this schema never sees a student. No name/phone/email is
 * stored here.
 *
 * `scope` is an opaque tag the CALLING module supplies (e.g. its own key). It partitions an account's
 * catalogue and charges so one consumer never lists or pays another consumer's records for the same
 * contact. The core attaches no meaning to it and has no list of valid values.
 *
 * Money is DECIMAL(12,2) (project convention); the service computes in integer minor units, never floats.
 * Nothing derivable is stored: paid / outstanding / status come from the payment rows, so there is no
 * running balance to drift or race on.
 *
 * Composite FKs (…, account_id) make every reference tenant-safe. The contact reference is RESTRICT (as for
 * education_students): a contact with financial history cannot be deleted from under it. Items → assignments
 * → payments CASCADE; the API never deletes any of them (items are archived, payments are immutable).
 *
 * unique(account_id, idempotency_key) lets a retried payment find its original row; NULL keys never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_charge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 40);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('frequency', 20)->default('one_time');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['id', 'account_id'], 'collection_charge_items_id_account_unique');
            $table->unique(['account_id', 'scope', 'name'], 'collection_charge_items_name_unique');
        });

        Schema::create('collection_charge_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('charge_item_id');
            $table->decimal('amount_due', 12, 2);
            $table->date('due_date')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'account_id'], 'collection_assignments_id_account_unique');
            $table->index(['account_id', 'contact_id'], 'collection_assignments_contact_index');
            $table->index(['account_id', 'charge_item_id'], 'collection_assignments_item_index');
            $table->foreign(['contact_id', 'account_id'], 'collection_assignments_contact_account_foreign')
                ->references(['id', 'account_id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['charge_item_id', 'account_id'], 'collection_assignments_item_account_foreign')
                ->references(['id', 'account_id'])->on('collection_charge_items')->cascadeOnDelete();
        });

        Schema::create('collection_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('charge_assignment_id');
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method', 30)->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'idempotency_key'], 'collection_payments_idempotency_unique');
            $table->index(['account_id', 'charge_assignment_id'], 'collection_payments_assignment_index');
            $table->index(['account_id', 'payment_date'], 'collection_payments_date_index');
            $table->foreign(['charge_assignment_id', 'account_id'], 'collection_payments_assignment_account_foreign')
                ->references(['id', 'account_id'])->on('collection_charge_assignments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_payments');
        Schema::dropIfExists('collection_charge_assignments');
        Schema::dropIfExists('collection_charge_items');
    }
};
