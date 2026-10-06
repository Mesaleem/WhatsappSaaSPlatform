<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paid module offers, editable by a Super Admin: label, price (GST included), term
 * length, the number of units included (for Custom Contact Groups: group chats), and
 * whether the offer is on sale. Invoices keep the price they were issued at, so a
 * later change never rewrites an existing invoice. Seeded with the current offer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_addon_offers')) {
            Schema::create('module_addon_offers', function (Blueprint $table) {
                $table->id();
                $table->string('module', 60)->unique();
                $table->string('label', 120);
                $table->decimal('price', 10, 2);
                $table->unsignedSmallInteger('term_months')->default(1);
                $table->unsignedInteger('units_included')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! DB::table('module_addon_offers')->where('module', 'contact_groups')->exists()) {
            DB::table('module_addon_offers')->insert([
                'module' => 'contact_groups',
                'label' => 'Custom Contact Groups',
                'price' => 99,
                'term_months' => 1,
                'units_included' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_addon_offers');
    }
};
