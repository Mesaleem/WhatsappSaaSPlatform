<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI — one checkbox row (e.g. "WhatsApp Setup"). `permission_key`
 * deliberately reuses the exact same string space as the existing
 * Account::MODULES/ACCOUNT_MODULES slugs (e.g. 'whatsapp_setup') rather
 * than inventing a parallel key space: RouteMasterSeeder backfills one
 * system_routes row per existing module slug, so
 * Account::effectiveModules() can dynamically cap allowed_modules
 * against whichever permission_keys are currently active here (see that
 * method's docblock) with zero disruption to every existing
 * enforcement call site (module.guard middleware, AuthContext::hasModule,
 * ProtectedRoute's `module` prop, AppLayout's `requiresModule`) — none of
 * which needed to change for this phase.
 *
 * Deliberately no DB-level foreign key / cascade delete on category_id
 * (this project's sqlite default has no FK enforcement configured
 * anywhere else in the schema either — see accounts.agent_id's own
 * migration docblock for the same disclosed precedent); RouteMasterController
 * enforces "a category with existing routes cannot be deleted" in the
 * application layer instead, so an orphaned system_routes row is not
 * reachable through the provided CRUD surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->index('category_id');
            $table->string('route_title');
            $table->string('route_path')->nullable();
            // Not unique: multiple UI rows are allowed to reference the
            // same underlying permission slug (e.g. during a future
            // migration away from the legacy Account::MODULES list),
            // though RouteMasterSeeder itself seeds one row per slug.
            $table->string('permission_key');
            $table->index('permission_key');
            // Whether an Agent (Reseller) may see/toggle this route at
            // all when provisioning a Sub-Client — mirrors the existing
            // "Agent Module Delegation UI Matrix" canDelegateModule
            // concept already used by CreateAccountModal's SuiteSection,
            // now driven from the database instead of being implicit.
            $table->boolean('is_agent_assignable')->default(true);
            // The dynamic kill switch this feature's spec requires:
            // "Ensure all backend permissions and middleware validate
            // dynamically against the system_routes.is_active state."
            // See Account::effectiveModules() / SystemRoute::
            // activePermissionKeys().
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_routes');
    }
};
