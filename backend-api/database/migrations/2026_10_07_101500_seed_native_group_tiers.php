<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Native WhatsApp Groups are priced by how many groups the client needs, like Custom Contact
 * Groups. The tiers below are placeholders the Super Admin changes on the Plans page. Idempotent:
 * a module that already has tiers is left alone. Data only; the down step removes what this seeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('module_addon_tiers')->where('module', 'whatsapp_groups')->exists()) {
            return;
        }

        DB::table('module_addon_tiers')->insert([
            ['module' => 'whatsapp_groups', 'from_units' => 1, 'to_units' => 5, 'price' => 99, 'created_at' => now(), 'updated_at' => now()],
            ['module' => 'whatsapp_groups', 'from_units' => 6, 'to_units' => null, 'price' => 199, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        DB::table('module_addon_tiers')->where('module', 'whatsapp_groups')->delete();
    }
};
