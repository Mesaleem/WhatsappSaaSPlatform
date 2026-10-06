<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's request for a paid module add-on (for example Custom Contact Groups).
 * Lifecycle: requested -> invoiced (approved, invoice created) -> paid (term running)
 * -> expired. A request can also be rejected. The invoice is the add-on's invoice,
 * paid by the same manual or online rules as the WhatsApp add-ons.
 * Additive and reversible. Guarded like the sibling migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('module_addon_requests')) {
            return;
        }

        Schema::create('module_addon_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('module', 60);
            $table->string('status', 20)->default('requested');
            $table->text('reason')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamp('term_starts_at')->nullable();
            $table->timestamp('term_ends_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'module', 'status']);
            $table->index(['status', 'term_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_addon_requests');
    }
};
