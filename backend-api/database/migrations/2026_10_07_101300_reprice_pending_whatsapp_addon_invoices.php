<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs unpaid WhatsApp number purchases that were priced before a number was removed:
 * a removed number's line stayed on the invoice. Each pending purchase keeps only the lines
 * whose number is still in it, is repriced to them, and is cancelled when none remain.
 * Paid and cancelled invoices are not touched. Data only; the down step does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $planKey = (string) config('whatsapp_numbers.addon_plan_key', 'whatsapp_addon');

        $invoiceIds = DB::table('invoices')
            ->where('plan_key', $planKey)
            ->where('status', 'pending')
            ->pluck('id');

        foreach ($invoiceIds as $invoiceId) {
            $stillIn = DB::table('whatsapp_numbers')
                ->where('addon_invoice_id', $invoiceId)
                ->pluck('phone_number')
                ->map(fn ($phone) => 'Extra WhatsApp number +'.$phone)
                ->all();

            // whereNotIn with an empty list matches every line, so a purchase with no numbers left loses all of them.
            DB::table('invoice_line_items')
                ->where('invoice_id', $invoiceId)
                ->whereNotIn('description', $stillIn)
                ->delete();

            $lines = DB::table('invoice_line_items')->where('invoice_id', $invoiceId)->get(['amount']);

            if ($lines->isEmpty()) {
                DB::table('invoices')->where('id', $invoiceId)->update(['status' => 'failed', 'updated_at' => now()]);

                continue;
            }

            $total = round((float) $lines->sum('amount'), 2);
            $count = $lines->count();

            DB::table('invoices')->where('id', $invoiceId)->update([
                'amount' => $total,
                'total_amount' => $total,
                'plan_label' => 'WhatsApp extra number'.($count > 1 ? 's ('.$count.')' : ''),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: the removed lines were stale and are not restored.
    }
};
