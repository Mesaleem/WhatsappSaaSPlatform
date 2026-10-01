<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 Task 2 — scheduled social connection health checks.
 *
 * `health_check_attempted_at` is when a provider check of this connection
 * was last CLAIMED/attempted (scheduled or manual), whatever its outcome.
 * It is distinct from `status_checked_at` (when the stored status was last
 * CONFIRMED): a check that cannot reach the provider changes no status but
 * still counts as an attempt, so a provider outage is retried once per
 * interval rather than on every scheduler tick. SocialConnectionService
 * claims a due row with one conditional UPDATE on this column, so
 * overlapping runs, several workers or several servers never check the same
 * connection twice within an interval.
 *
 * Additive and nullable: existing rows (including legacy connections) are
 * simply due for their first check. The index serves the due-row scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->timestamp('health_check_attempted_at')->nullable()->after('status_checked_at');
            $table->index(['health_status', 'health_check_attempted_at'], 'social_accounts_health_check_index');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex('social_accounts_health_check_index');
            $table->dropColumn('health_check_attempted_at');
        });
    }
};
