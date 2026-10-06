<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units-based pricing for a paid module: the client states how many units it needs
 * (for Custom Contact Groups, how many groups), and the price comes from the tier that
 * holds that number. Tiers are editable by a Super Admin. A request records its units,
 * so the invoice and the group limit follow what was asked for. Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_addon_tiers')) {
            Schema::create('module_addon_tiers', function (Blueprint $table) {
                $table->id();
                $table->string('module', 60)->index();
                $table->unsignedInteger('from_units');
                $table->unsignedInteger('to_units')->nullable();
                $table->decimal('price', 10, 2);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('module_addon_requests', 'units')) {
            Schema::table('module_addon_requests', function (Blueprint $table) {
                $table->unsignedInteger('units')->nullable()->after('module');
            });
        }

        if (! DB::table('module_addon_tiers')->where('module', 'contact_groups')->exists()) {
            DB::table('module_addon_tiers')->insert([
                ['module' => 'contact_groups', 'from_units' => 1, 'to_units' => 5, 'price' => 99, 'created_at' => now(), 'updated_at' => now()],
                ['module' => 'contact_groups', 'from_units' => 6, 'to_units' => null, 'price' => 199, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_addon_tiers');

        if (Schema::hasColumn('module_addon_requests', 'units')) {
            Schema::table('module_addon_requests', function (Blueprint $table) {
                $table->dropColumn('units');
            });
        }
    }
};
