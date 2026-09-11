<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client Management, User Creation, Multi-Role Permissions & Feature
 * Module Checklists refactor — the User Creation/Edit form now collects
 * a Phone Number, and there was nowhere on the `users` table to store it
 * (confirmed by reading every prior users-table migration before writing
 * this one). Nullable — every existing seeded/created user has none, and
 * a phone number was never a login credential, so it can never gate
 * authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_number');
        });
    }
};
