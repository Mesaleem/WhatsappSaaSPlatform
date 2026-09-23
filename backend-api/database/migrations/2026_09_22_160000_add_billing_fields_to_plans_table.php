<?php

use App\Support\PlanCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Task 11 — the columns `plans` needs to actually BE a plan.
 *
 * Phase 1 created `plans` with slug/label/price/duration_days only,
 * because at that point it was a mirror for a future cutover rather than
 * a runtime source. Checkout meanwhile read five more fields off
 * PlanCatalog — engine_type, billing_model, rate_per_message,
 * total_allocated_messages — plus a notion of "purchasable" that
 * existed nowhere at all. A plan created through Task 10's management
 * API was therefore reconcilable but not purchasable: the table simply
 * could not answer what engine or quota it granted.
 *
 * This is NOT "copying PlanCatalog into another table" (which Task 11
 * explicitly forbids). It adds the columns the table genuinely lacks so
 * it can become the source of truth; the backfill below only preserves
 * the three existing rows' CURRENT runtime behaviour, so no customer's
 * price, engine or quota changes at the moment of the cutover.
 *
 * `is_active` is the activation flag Task 11 asks about: there was none
 * — no is_active, no status, no soft deletes — so a plan could never be
 * retired. It defaults TRUE, which keeps every existing plan purchasable
 * exactly as it is today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // 'qr' | 'meta' — mirrors subscriptions.engine_type.
            $table->string('engine_type')->default('qr')->after('description');
            // 'flat_quota' | 'per_message' | 'unlimited'.
            $table->string('billing_model')->default('flat_quota')->after('engine_type');
            // Only meaningful for per_message, same as on subscriptions.
            $table->decimal('rate_per_message', 8, 4)->nullable()->after('billing_model');
            // NULL for 'unlimited'.
            $table->unsignedInteger('total_allocated_messages')->nullable()->after('rate_per_message');
            // Task 11: a retired plan stays in the table (existing
            // invoices and reconciliation still reference it) but cannot
            // be bought again.
            $table->boolean('is_active')->default(true)->after('total_allocated_messages');

            $table->index('is_active');
        });

        /*
         * Backfill the rows that already exist from the values checkout
         * is serving RIGHT NOW, so the cutover is behaviour-preserving on
         * an already-deployed database even before the seeder is re-run.
         * This is the "seed/bootstrap" class of PlanCatalog usage that
         * Task 11 §3 permits — it runs once, at migration time, and no
         * runtime path reads PlanCatalog after this.
         */
        foreach (PlanCatalog::all() as $slug => $plan) {
            DB::table('plans')->where('slug', $slug)->update([
                'engine_type' => $plan['engine_type'],
                'billing_model' => $plan['billing_model'],
                'rate_per_message' => $plan['rate_per_message'],
                'total_allocated_messages' => $plan['total_allocated_messages'],
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'engine_type',
                'billing_model',
                'rate_per_message',
                'total_allocated_messages',
                'is_active',
            ]);
        });
    }
};
