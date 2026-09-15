<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit
 * Tracking — Global Audit Logging Engine (requirement 3).
 *
 * DISCLOSED, PRE-EXISTING NAMING COLLISION: this codebase already has a
 * `login_audit_logs` table + `/api/audit-logs` route + AppLayout's
 * "Audit Logs" nav item (Role-Based Login Audit Logging Architecture,
 * 2026-09-09) — that system logs ONLY login attempts (success/failed)
 * and has none of this requirement's fields (module_name, action_type,
 * route_path, old_values, new_values). This is a NEW, separate table for
 * a NEW, separate concern (CRUD mutation activity, not authentication
 * events) — named `activity_logs` exactly as this spec's requirement 3
 * literally asks, and surfaced under a distinctly labeled "Activity
 * Logs" nav item (see ActivityLogsPage.tsx) so the two audit surfaces
 * are never confused with each other.
 *
 * No DB-level FK on agent_id, matching this project's existing
 * convention for that column (see accounts.agent_id's own migration
 * docblock) — an Agent account can be looked up via Account::findCached()
 * but there is no cycle/orphan risk this migration needs to prevent at
 * the schema level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('module_name');
            // 'create' | 'update' | 'delete' | 'toggle' (see LogsActivity
            // trait: 'toggle' is a disclosed refinement fired when the
            // ONLY substantive field changed is is_active, so a Route
            // Master/account activation flip reads as "toggled" rather
            // than a generic "updated").
            $table->string('action_type');
            $table->string('route_path')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
            $table->index(['agent_id', 'created_at']);
            $table->index(['module_name', 'created_at']);
            $table->index('action_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
