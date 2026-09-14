<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 1) — introduces
 * `account_type` (super_admin | agent | client) and a self-referencing
 * `agent_id` on `accounts`, so a Reseller ("Agent") account can own a set
 * of Client accounts underneath it.
 *
 * [Disclosed]: the platform's existing Super Admin is a User with
 * account_id=null carrying the spatie 'super_admin' role (see
 * User::isSuperAdmin(); TenantIsolationMiddleware already keys off that,
 * not off any Account row) — Super Admin has never had an Account of its
 * own. 'super_admin' is still included in this enum for schema
 * completeness / forward compatibility per this phase's spec, but no
 * Account row is expected to actually carry it under the current auth
 * model; nothing in this codebase reads account_type==='super_admin'.
 * See this phase's implementation note for the full write-up.
 *
 * account_type defaults to 'client' so every account that exists before
 * this migration runs (and every account any not-yet-updated code path
 * creates afterward) keeps behaving exactly as a plain direct client —
 * the same "new column defaults to zero behavior change" convention this
 * table already uses for allowed_modules/max_users_limit/etc.
 *
 * agent_id is nullable (a direct/platform client has no agent). A real
 * DB-level FOREIGN KEY + ON DELETE CASCADE is added only on drivers that
 * support altering an existing table to add one (mysql/pgsql) — SQLite's
 * ALTER TABLE has no ADD CONSTRAINT form at all, so attempting this on
 * sqlite throws a syntax error at migrate time. This project's own
 * migration history never once adds a foreign() inside Schema::table()
 * for exactly that reason (grep the whole database/migrations/ directory
 * — every existing foreign() call sits inside a Schema::create()); doing
 * so here would break `php artisan migrate` against this project's
 * stated local-dev default (DB_CONNECTION=sqlite per README §2) and the
 * CI pipeline added yesterday, which migrates a fresh sqlite DB on every
 * push. Referential integrity on sqlite (today's only environment this
 * has actually been run against) is enforced at the application layer
 * instead: Account::agent()/subClients() at the ORM level, and — once a
 * future phase adds an endpoint that actually sets agent_id — validation
 * there. [Flagged for your judgment]: revisit adding the DB-level
 * constraint unconditionally if/when every environment this migrates
 * against is confirmed to be mysql/pgsql.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->enum('account_type', ['super_admin', 'agent', 'client'])
                ->default('client')
                ->after('id');

            $table->unsignedBigInteger('agent_id')->nullable()->after('account_type');

            $table->index('agent_id');
            $table->index('account_type');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('accounts', function (Blueprint $table) {
                $table->foreign('agent_id')->references('id')->on('accounts')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropForeign(['agent_id']);
            });
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['agent_id']);
            $table->dropIndex(['account_type']);
            $table->dropColumn(['agent_id', 'account_type']);
        });
    }
};
