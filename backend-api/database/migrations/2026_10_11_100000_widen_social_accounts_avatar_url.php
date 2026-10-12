<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * avatar_url was VARCHAR(255), but a Facebook Page's picture URL (a signed
 * fbcdn.net link) is often longer, so MySQL refused the insert ("Data too long")
 * and POST /api/social/accounts/bind failed with a 500. SQLite ignores the
 * length, which is why tests never caught it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->text('avatar_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('avatar_url')->nullable()->change();
        });
    }
};
