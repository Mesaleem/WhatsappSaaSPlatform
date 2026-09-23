<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Task 10 — WHY an entitlement was revoked.
 *
 * Task 9 added `revoked_at`, which answers "is it revoked". Task 10's
 * reconciliation has to answer a second question that column cannot:
 *
 *     absent because the plan stopped including it
 *          ←→
 *     taken away deliberately by an administrator
 *
 * Both were `revoked_at IS NOT NULL`. Without the distinction, a
 * customer who downgrades and later upgrades again would either lose
 * those capabilities permanently (if reconciliation never restores) or
 * have an administrator's deliberate revocation silently undone (if it
 * always restores). Neither is acceptable, so the reason becomes data.
 *
 * `revoked_by_user_id` was NOT reused for this. It happens to be NULL
 * for a system action and set for an HTTP one today, but that is
 * incidental — a console-initiated administrative revoke would also be
 * NULL — and inferring authorization semantics from an incidental field
 * is exactly the kind of implicit rule this schema keeps making explicit.
 *
 * SEMANTICS:
 *   NULL              not revoked (revoked_at IS NULL)
 *   'manual'          an administrator revoked it; reconciliation NEVER restores it
 *   'plan_downgrade'  the plan stopped including it; reconciliation restores it
 *                     if the plan includes it again
 *
 * BACKFILL OF EXISTING ROWS: every row revoked before this migration was
 * revoked through AccountController::revokeEntitlement(), the only
 * revocation path that existed — i.e. by a human administrator. They are
 * therefore backfilled to 'manual', which is also the conservative
 * choice: 'manual' is the value that is never auto-restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_entitlements', function (Blueprint $table) {
            $table->string('revoked_reason', 32)->nullable()->after('revoked_by_user_id');
        });

        DB::table('account_entitlements')
            ->whereNotNull('revoked_at')
            ->whereNull('revoked_reason')
            ->update(['revoked_reason' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('account_entitlements', function (Blueprint $table) {
            $table->dropColumn('revoked_reason');
        });
    }
};
