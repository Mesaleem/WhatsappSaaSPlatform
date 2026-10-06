<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A custom contact group that is outside the groups a client's paid term allows. Set when a
 * downgrade leaves more groups than the new term includes; cleared when the client chooses the
 * groups to keep. A locked group cannot be sent to until the next term. Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_groups') || Schema::hasColumn('contact_groups', 'locked_at')) {
            return;
        }

        Schema::table('contact_groups', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('is_default');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('contact_groups') && Schema::hasColumn('contact_groups', 'locked_at')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->dropColumn('locked_at');
            });
        }
    }
};
