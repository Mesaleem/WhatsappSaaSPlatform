<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: extra WhatsApp numbers are paid add-ons with their own one-month term.
 *
 *  - whatsapp_numbers.addon_invoice_id: the invoice that bought the slot.
 *  - whatsapp_numbers.term_ends_at: when an add-on's term ends (one month after
 *    payment). NULL for the included number, whose term follows the plan.
 *  - invoice_line_items: one row per purchased item (each WhatsApp number), so an
 *    invoice shows what it charges for. Invoices show the GST-inclusive total only.
 *
 * Additive and reversible. Guarded like the sibling migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_numbers', 'addon_invoice_id')) {
            Schema::table('whatsapp_numbers', function (Blueprint $table) {
                $table->foreignId('addon_invoice_id')->nullable()->after('locked_at')->constrained('invoices')->nullOnDelete();
                $table->timestamp('term_ends_at')->nullable()->after('addon_invoice_id');
                $table->index('term_ends_at');
            });
        }

        if (! Schema::hasTable('invoice_line_items')) {
            Schema::create('invoice_line_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('description');
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_amount', 10, 2);
                // GST-inclusive, like the invoice total.
                $table->decimal('amount', 10, 2);
                $table->timestamps();

                $table->index('invoice_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_items');

        if (Schema::hasColumn('whatsapp_numbers', 'addon_invoice_id')) {
            Schema::table('whatsapp_numbers', function (Blueprint $table) {
                $table->dropIndex(['term_ends_at']);
                $table->dropConstrainedForeignId('addon_invoice_id');
                $table->dropColumn('term_ends_at');
            });
        }
    }
};
