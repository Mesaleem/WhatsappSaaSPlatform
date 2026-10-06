<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the client chose which custom groups stay open for this term (after a downgrade). Additive. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_addon_requests') || Schema::hasColumn('module_addon_requests', 'group_selection_at')) {
            return;
        }

        Schema::table('module_addon_requests', function (Blueprint $table) {
            $table->timestamp('group_selection_at')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('module_addon_requests') && Schema::hasColumn('module_addon_requests', 'group_selection_at')) {
            Schema::table('module_addon_requests', function (Blueprint $table) {
                $table->dropColumn('group_selection_at');
            });
        }
    }
};
