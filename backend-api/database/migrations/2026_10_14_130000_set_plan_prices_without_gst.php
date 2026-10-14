<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * No GST on plans (owner, 2026-10-14): the price shown is the price paid — ₹599 / ₹1,999 /
     * ₹7,999 a month. The Starter plan had been saved as 507.63 (₹599 with 18% GST taken out), so
     * it showed "₹507.63 + tax ₹91.37". Only that exact back-calculated value is corrected; a price
     * the Super Admin set to anything else is left alone.
     */
    public function up(): void
    {
        DB::table('plans')->where('slug', 'starter')->where('price', 507.63)->update(['price' => 599.00, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('plans')->where('slug', 'starter')->where('price', 599.00)->update(['price' => 507.63, 'updated_at' => now()]);
    }
};
