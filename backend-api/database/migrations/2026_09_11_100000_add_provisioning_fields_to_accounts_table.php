<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin Client Provisioning, Client Admin Mapping, User Limits,
 * Granular Permission Matrix & Adaptive Dashboards refactor.
 *
 * Two new Account-level fields, both part of "Section 1: Client
 * Organization Details" in the Create Client form:
 *
 *  - `max_users_limit`: nullable unsigned integer cap on total users
 *    (owner + team members) this account may have. NULL = unlimited —
 *    the same zero-regression default this codebase already uses for
 *    `allowed_modules` (see that column's migration) so every existing
 *    account keeps unlimited team users until a Super Admin explicitly
 *    sets a cap. Enforced in TeamController::store().
 *
 *  - `module_assignment`: the client's coarse business-category
 *    ('whatsapp_messaging' | 'social_media' | 'both'), driving which
 *    widget set DashboardPage.tsx renders (see Account::MODULE_ASSIGNMENTS
 *    and this refactor's audit report for why this is a DISTINCT concept
 *    from the existing `allowed_modules` per-feature sidebar checklist,
 *    not a replacement for it). Defaults to 'both' for every existing
 *    account — zero-regression, since 'both' renders every dashboard
 *    widget this app already showed before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedInteger('max_users_limit')->nullable()->after('allowed_modules');
            $table->string('module_assignment')->default('both')->after('max_users_limit');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['max_users_limit', 'module_assignment']);
        });
    }
};
