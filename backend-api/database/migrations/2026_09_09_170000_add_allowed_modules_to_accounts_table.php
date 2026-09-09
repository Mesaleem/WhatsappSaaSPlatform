<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Absolute Super Admin Control — Dynamic Client Privilege Toggles.
     * Nullable JSON array of module slugs (see App\Models\Account::MODULES)
     * a Super Admin has explicitly enabled for this client. NULL (the
     * default, and the value for every existing account after this
     * migration) means "every module enabled" — a deliberate zero-regression
     * default so no existing client loses access to anything until a Super
     * Admin explicitly narrows their modules via
     * PATCH /api/admin/accounts/{id}/permissions.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->json('allowed_modules')->nullable()->after('api_rate_limit_per_minute');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('allowed_modules');
        });
    }
};
