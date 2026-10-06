<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starter is priced at ₹599 (base). GST at 18% is added on top at checkout and shown
 * on the invoice (agreed 2026-10-05).
 *
 * Only a Starter row still at ₹499 is changed, so a price a Super Admin set on
 * purpose is never overwritten. Invoices already issued keep their own snapshot.
 * Reversible: down() restores ₹499 only where the row is at ₹599.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')
            ->where('slug', 'starter')
            ->where('price', 499)
            ->update(['price' => 599, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('plans')
            ->where('slug', 'starter')
            ->where('price', 599)
            ->update(['price' => 499, 'updated_at' => now()]);
    }
};
