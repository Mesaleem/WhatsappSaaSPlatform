<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Task 3 — reservation expiry infrastructure. Additive only.
 *
 *   credit_reservations.expires_at   NULLABLE. Set by the CALLER that
 *                                    reserves (NULL = never expires, the
 *                                    Task 1 behaviour, so every existing row
 *                                    keeps its meaning). Past it the hold can
 *                                    only be released; the scheduled
 *                                    credits:release-expired-reservations
 *                                    releases it so a crashed caller cannot
 *                                    strand credits. No product TTL is
 *                                    decided here.
 *   index (status, expires_at)       the cleanup's lookup.
 *
 * MySQL/MariaDB only (SQLite cannot ALTER a CHECK in): a reservation can
 * never record more consumed than it held.
 */
return new class extends Migration
{
    private const CHECK = 'credit_reservations_consumed_within_amount';

    public function up(): void
    {
        Schema::table('credit_reservations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('released_at');
            $table->index(['status', 'expires_at'], 'credit_reservations_status_expiry_index');
        });

        if ($this->mysqlFamily()) {
            DB::statement('ALTER TABLE credit_reservations ADD CONSTRAINT '.self::CHECK.' CHECK (consumed_amount IS NULL OR consumed_amount <= amount)');
        }
    }

    public function down(): void
    {
        if ($this->mysqlFamily()) {
            DB::statement('ALTER TABLE credit_reservations DROP CONSTRAINT '.self::CHECK);
        }

        Schema::table('credit_reservations', function (Blueprint $table) {
            $table->dropIndex('credit_reservations_status_expiry_index');
            $table->dropColumn('expires_at');
        });
    }

    private function mysqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
