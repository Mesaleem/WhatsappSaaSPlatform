<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Task 9 — an explicit REVOKED state for account entitlements
 * (the product owner's "REVOCATION = A" decision).
 *
 * THE PROBLEM THIS FIXES. AccountController::revokeEntitlement() used to
 * hard-delete the row, and this table had no negative state, so the
 * schema could not tell apart:
 *
 *     never granted        ←→   deliberately revoked by a Super Admin
 *
 * Both were "row absent". That is harmless while every grant is manual,
 * and becomes destructive the moment a plan BACKFILL runs: it would
 * re-grant a capability an administrator had deliberately taken away,
 * silently reversing their decision. Task 9 adds that backfill, so the
 * state has to exist first.
 *
 * WHY A COLUMN AND NOT SOFT DELETES. `unique(account_id, capability_id)`
 * is what makes every grant path idempotent (firstOrCreate throughout).
 * Laravel's SoftDeletes keeps deleted rows inside that same unique index,
 * so a revoke-then-regrant would collide on the constraint rather than
 * reusing the row. A nullable timestamp keeps exactly one row per
 * (account, capability) for its whole lifetime — granted, revoked, or
 * re-granted — which is also what makes the audit trail readable.
 *
 * SEMANTICS: revoked_at IS NULL means the entitlement is held.
 * Purely additive — every existing row gets NULL, i.e. "held", which is
 * precisely what an existing row already meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_entitlements', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('granted_by_account_id');
            $table->foreignId('revoked_by_user_id')->nullable()->after('revoked_at')
                ->constrained('users')->nullOnDelete();

            // Every read path filters on this ("which capabilities does
            // this account actually hold"), so it is worth the index.
            $table->index(['account_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('account_entitlements', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'revoked_at']);
            $table->dropConstrainedForeignId('revoked_by_user_id');
            $table->dropColumn('revoked_at');
        });
    }
};
