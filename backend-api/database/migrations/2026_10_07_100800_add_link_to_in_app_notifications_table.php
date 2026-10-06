<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A notification can open the page it is about (for example /billing). Additive, reversible. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('in_app_notifications', 'link')) {
            Schema::table('in_app_notifications', function (Blueprint $table) {
                $table->string('link', 255)->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('in_app_notifications', 'link')) {
            Schema::table('in_app_notifications', function (Blueprint $table) {
                $table->dropColumn('link');
            });
        }
    }
};
