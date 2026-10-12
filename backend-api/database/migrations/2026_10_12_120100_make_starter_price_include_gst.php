<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starter's ₹599 is now the price INCLUDING 18% GST (owner decision 2026-10-12,
 * replacing the 2026-10-05 "GST on top" decision). Checkout still adds GST to
 * the base price, so the base becomes ₹507.63: GST ₹91.37, total ₹599.00.
 *
 * Only a Starter row still at ₹599 base is changed, so a price a Super Admin set
 * on purpose is never overwritten. Invoices already issued keep their own snapshot.
 * Reversible: down() restores ₹599 only where the row is at ₹507.63.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')
            ->where('slug', 'starter')
            ->where('price', 599)
            ->update(['price' => 507.63, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('plans')
            ->where('slug', 'starter')
            ->where('price', 507.63)
            ->update(['price' => 599, 'updated_at' => now()]);
    }
};
