<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a module add-on request was approved (its invoice issued) or rejected. The paid
 * date is the invoice's paid_at, so this is the only timestamp the history needs to add.
 * Additive and reversible. Older rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_addon_requests') || Schema::hasColumn('module_addon_requests', 'decided_at')) {
            return;
        }

        Schema::table('module_addon_requests', function (Blueprint $table) {
            $table->timestamp('decided_at')->nullable()->after('decision_note');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('module_addon_requests') && Schema::hasColumn('module_addon_requests', 'decided_at')) {
            Schema::table('module_addon_requests', function (Blueprint $table) {
                $table->dropColumn('decided_at');
            });
        }
    }
};
