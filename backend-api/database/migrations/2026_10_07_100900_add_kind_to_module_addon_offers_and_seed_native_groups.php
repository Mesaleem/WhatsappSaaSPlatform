<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An offer sells either a MODULE (switched on in the account's module list, for example
 * Custom Contact Groups) or a CAPABILITY (granted as an entitlement, for example native
 * WhatsApp groups). Adds the kind and the capability to the offers, and seeds the native
 * groups offer. Its price and term are placeholders until the owner confirms them.
 * Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('module_addon_offers', 'kind')) {
            Schema::table('module_addon_offers', function (Blueprint $table) {
                $table->string('kind', 20)->default('module')->after('is_active');
                $table->string('capability_slug', 60)->nullable()->after('kind');
            });
        }

        if (! DB::table('module_addon_offers')->where('module', 'whatsapp_groups')->exists()) {
            DB::table('module_addon_offers')->insert([
                'module' => 'whatsapp_groups',
                'label' => 'Native WhatsApp Groups',
                'price' => 99,
                'term_months' => 1,
                'units_included' => 0,
                'is_active' => true,
                'kind' => 'capability',
                'capability_slug' => 'whatsapp_groups',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('module_addon_offers')->where('module', 'whatsapp_groups')->delete();

        if (Schema::hasColumn('module_addon_offers', 'kind')) {
            Schema::table('module_addon_offers', function (Blueprint $table) {
                $table->dropColumn(['kind', 'capability_slug']);
            });
        }
    }
};
