<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Task 5 — legacy API-key binding backfill, deadline
 * foundation. Per-KEY, not per-account (deliberately — Task 5's
 * legacy-IP-dependent definition is API-key-level: one key of an
 * account can already be bound while a sibling key of the same account
 * still needs resolving).
 *
 * NULL by default and for every NEW key going forward — only
 * BackfillApiKeyLegacyBindings (the Task 5 command) ever writes a
 * non-null value, and only for the one key it could unambiguously bind
 * without guessing. Anchored to the actual backfill run time
 * (`now()` at the moment that command creates the binding), never to
 * Account.ip_registered_at — a column on an entirely different table,
 * for an entirely different feature (ServerIpBindingService's own
 * change-cadence rules).
 *
 * This migration adds the column only. No enforcement reads it yet —
 * that is explicitly a later task (grace expiry consequence,
 * admin extension).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->timestamp('legacy_binding_grace_expires_at')->nullable()->after('access_disabled_reason');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('legacy_binding_grace_expires_at');
        });
    }
};
