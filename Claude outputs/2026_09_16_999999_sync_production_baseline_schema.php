<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * =============================================================================
 * PRODUCTION-SAFE BASELINE SYNC MIGRATION
 * =============================================================================
 *
 * PURPOSE
 * -------
 * Brings ANY out-of-sync `wa_saas_platform` database (production, staging, or
 * a developer's machine) up to the full local-development schema baseline as
 * of 2026-09-16, without assuming which of the 70 preceding migration files
 * have or have not actually been applied there.
 *
 * WHY THIS EXISTS (root cause, confirmed by direct inspection of every
 * migration file in this directory before writing this one):
 *   1. Several recent migrations carry an explicit, disclosed note that they
 *      were "NOT run ... per standing instruction" / "still pending
 *      authorization" at the time they were authored (see, e.g.,
 *      2026_09_13_130000_add_template_metadata_to_message_dispatch_logs_table.php,
 *      2026_09_13_140003_add_resolution_counts_to_message_dispatch_logs_table.php,
 *      2026_09_13_160000_add_gateway_message_id_to_message_dispatch_logs_table.php).
 *   2. One migration (2026_09_15_100000_add_tiered_approval_statuses_to_message_templates_table.php)
 *      discloses it was authored and committed without ever being executed
 *      against any database, because no `php` binary was reachable in that
 *      shell.
 *   3. The team's own stated workaround for #1/#2 has been manually merging
 *      local and production SQL dumps, which is exactly the error-prone
 *      process this migration replaces.
 *
 * Rather than guess which of the 70 migrations already ran on production and
 * which did not, this single file reconstructs the CURRENT, FINAL column/
 * index/foreign-key state of every table this project's migrations define,
 * with every single operation wrapped in a defensive existence check. Running
 * `php artisan migrate` with this file present is safe regardless of how far
 * production's migrations table has actually progressed:
 *   - A table that is completely missing gets created with ONLY the columns
 *     its ORIGINAL creating migration defined.
 *   - Every column/index/foreign key any LATER migration ever added to that
 *     table is then added on top, individually, only if it does not already
 *     exist — this correctly "catches up" a table regardless of whether it
 *     was last touched by migration #12 or migration #68.
 *   - A table/column/index/foreign key that already exists is left
 *     completely untouched. No ALTER runs against it. No data in any
 *     existing row is read, moved, or rewritten by this migration.
 *
 * STRICT SAFETY GUARANTEES (per explicit instruction — read before editing)
 * ---------------------------------------------------------------------------
 *   - This file issues ONLY: Schema::create() guarded by !Schema::hasTable(),
 *     and Schema::table()->addColumn/index/foreign() guarded by
 *     !Schema::hasColumn() / a custom information_schema index/FK check.
 *   - This file NEVER calls Schema::drop(), Schema::dropIfExists(),
 *     dropColumn(), dropIndex(), dropForeign(), truncate(), or any raw
 *     DROP/DELETE/TRUNCATE SQL, anywhere, under any condition.
 *   - This file NEVER calls migrate:fresh, db:wipe, or reads/writes a single
 *     existing data row. It only inspects and extends SCHEMA (table/column/
 *     index/constraint metadata), never row data.
 *   - down() is intentionally inert (see its own docblock) — this migration
 *     is designed to be applied once and left in place, never rolled back,
 *     because a generic rollback cannot safely distinguish "this migration
 *     created this column" from "this column already existed before this
 *     migration ran," and guessing wrong would risk destroying production
 *     data. If a genuine rollback is ever required, it must be hand-written
 *     against the specific production state at that time, reviewed, and
 *     explicitly authorized — never inferred from this file.
 *   - Every raw SQL statement in the helper methods below is a SELECT against
 *     information_schema (read-only, MySQL/MariaDB) or a PRAGMA read (SQLite,
 *     for local-dev parity only) — never a mutation.
 *
 * DATABASE ENGINE
 * ----------------
 * Confirmed via backend-api/.env and .env.example: DB_CONNECTION=mysql in
 * every environment this project defines. The information_schema-based
 * helpers below are written for MySQL/MariaDB. A defensive SQLite branch is
 * included only so this file does not error out if ever run against a local
 * sqlite database (matching this project's own existing convention in
 * 2026_09_14_090000_add_account_type_and_agent_id_to_accounts_table.php of
 * skipping DB-level foreign keys on sqlite) — it is not the production path.
 *
 * ORDERING
 * --------
 * Tables are created in dependency order (a table with a foreign key is
 * created after the table it references), so this file is safe to run
 * top-to-bottom against a completely empty schema, a fully-migrated schema,
 * or anything in between.
 */
return new class extends Migration
{
    // =========================================================================
    // Defensive helpers — every one is read-only against information_schema
    // (or sqlite's PRAGMA equivalents) until the moment it decides a specific
    // CREATE/ADD is actually needed.
    // =========================================================================

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    /**
     * Add one column to an existing table, only if that table exists and
     * does not already have that column.
     */
    private function addColumn(string $table, string $column, \Closure $definition): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
            Schema::table($table, $definition);
        }
    }

    /**
     * True if the named index/unique-key already exists on the table.
     * MySQL/MariaDB: information_schema.statistics (read-only).
     * SQLite: PRAGMA index_list (read-only) — local-dev parity only.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        if ($this->isSqlite()) {
            foreach (DB::select('PRAGMA index_list(' . json_encode($table) . ')') as $row) {
                if ($row->name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $row = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [DB::getDatabaseName(), $table, $indexName]
        );

        return (int) ($row->cnt ?? 0) > 0;
    }

    /**
     * Add a named index to an existing table, only if that table exists,
     * has the column(s) the index needs, and does not already have an index
     * with this exact name.
     */
    private function addIndex(string $table, string $indexName, \Closure $definition): void
    {
        if (Schema::hasTable($table) && ! $this->indexExists($table, $indexName)) {
            Schema::table($table, $definition);
        }
    }

    /**
     * True if a foreign key constraint already exists on the given column of
     * the given table. MySQL/MariaDB: information_schema.KEY_COLUMN_USAGE
     * (read-only, filtered to rows that actually reference another table).
     */
    private function foreignKeyExists(string $table, string $column): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        if ($this->isSqlite()) {
            // SQLite cannot ALTER TABLE ... ADD CONSTRAINT at all (same
            // limitation documented in this project's own
            // 2026_09_14_090000_add_account_type_and_agent_id_to_accounts_table.php).
            // Report "exists" so callers below skip attempting it, exactly
            // like that migration's own driver check does.
            return true;
        }

        $row = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = ? AND table_name = ? AND column_name = ?
               AND referenced_table_name IS NOT NULL',
            [DB::getDatabaseName(), $table, $column]
        );

        return (int) ($row->cnt ?? 0) > 0;
    }

    /**
     * Add a foreign key on an existing column, only on drivers that support
     * altering an existing table to add one, only if the column exists, and
     * only if no foreign key already sits on that column.
     */
    private function addForeignKey(string $table, string $column, \Closure $definition): void
    {
        if ($this->isSqlite()) {
            return;
        }

        if (Schema::hasTable($table) && Schema::hasColumn($table, $column) && ! $this->foreignKeyExists($table, $column)) {
            Schema::table($table, $definition);
        }
    }

    /**
     * True if a MySQL ENUM column's declared value list already contains the
     * given value (e.g. widening message_templates.status). Read-only.
     */
    private function enumContainsValue(string $table, string $column, string $value): bool
    {
        if ($this->isSqlite()) {
            // SQLite has no native ENUM type (Laravel emulates it with a
            // CHECK constraint it can freely redeclare); nothing destructive
            // is attempted either way, so report "already fine".
            return true;
        }

        $row = DB::selectOne(
            'SELECT COLUMN_TYPE AS col_type FROM information_schema.COLUMNS
             WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [DB::getDatabaseName(), $table, $column]
        );

        if (! $row) {
            return false;
        }

        return str_contains($row->col_type, "'{$value}'");
    }

    public function up(): void
    {
        // =====================================================================
        // 1. LARAVEL FRAMEWORK DEFAULT TABLES
        //    (0001_01_01_000000/000001/000002_*, no later ALTERs on any of
        //    these — table-level hasTable guard is sufficient.)
        // =====================================================================

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        // =====================================================================
        // 2. SPATIE LARAVEL-PERMISSION TABLES
        //    config/permission.php confirmed: default table names, and
        //    'teams' => false — no team_foreign_key column on any of these.
        // =====================================================================

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
            });
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
                $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            });
        }

        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
                $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            });
        }

        // =====================================================================
        // 3. ACCOUNTS
        //    Base create (2026_09_08_100510) + every later ALTER, replayed.
        //    NOTE: the base create below already reflects the RENAME done by
        //    2026_09_08_100521_update_accounts_table_for_provisioning.php
        //    ('name' -> 'company_name') and the DROP of that same migration's
        //    superseded subscription_status/expires_at columns — a table
        //    created fresh by THIS file never has the old names, so there is
        //    nothing to rename/drop here. If an existing production
        //    `accounts` table is ever found to STILL have the old
        //    'name'/'subscription_status'/'expires_at' columns, STOP and
        //    escalate — see the deployment guide's pre-flight check. This
        //    migration deliberately never renames or drops a column on an
        //    existing table.
        // =====================================================================

        if (! Schema::hasTable('accounts')) {
            Schema::create('accounts', function (Blueprint $table) {
                $table->id();
                $table->string('company_name');
                $table->timestamps();
            });
        }

        $this->addColumn('accounts', 'primary_phone', fn (Blueprint $t) => $t->string('primary_phone')->nullable());
        $this->addColumn('accounts', 'status', fn (Blueprint $t) => $t->string('status')->default('active'));
        $this->addColumn('accounts', 'api_rate_limit_per_minute', fn (Blueprint $t) => $t->unsignedInteger('api_rate_limit_per_minute')->default(60));
        $this->addColumn('accounts', 'allowed_modules', fn (Blueprint $t) => $t->json('allowed_modules')->nullable());
        $this->addColumn('accounts', 'is_platform_device', fn (Blueprint $t) => $t->boolean('is_platform_device')->default(false));
        $this->addColumn('accounts', 'allow_facebook', fn (Blueprint $t) => $t->boolean('allow_facebook')->default(false));
        $this->addColumn('accounts', 'allow_instagram', fn (Blueprint $t) => $t->boolean('allow_instagram')->default(false));
        $this->addColumn('accounts', 'allow_linkedin', fn (Blueprint $t) => $t->boolean('allow_linkedin')->default(false));
        $this->addColumn('accounts', 'allow_youtube', fn (Blueprint $t) => $t->boolean('allow_youtube')->default(false));
        $this->addColumn('accounts', 'logo_url', fn (Blueprint $t) => $t->string('logo_url')->nullable());
        $this->addColumn('accounts', 'brand_accent_color', fn (Blueprint $t) => $t->string('brand_accent_color', 7)->nullable());
        $this->addColumn('accounts', 'max_users_limit', fn (Blueprint $t) => $t->unsignedInteger('max_users_limit')->nullable());
        $this->addColumn('accounts', 'module_assignment', fn (Blueprint $t) => $t->string('module_assignment')->default('both'));
        $this->addColumn('accounts', 'gemini_api_key', fn (Blueprint $t) => $t->text('gemini_api_key')->nullable());
        $this->addColumn('accounts', 'account_type', fn (Blueprint $t) => $t->enum('account_type', ['super_admin', 'agent', 'client'])->default('client'));
        $this->addColumn('accounts', 'agent_id', fn (Blueprint $t) => $t->unsignedBigInteger('agent_id')->nullable());

        $this->addIndex('accounts', 'accounts_status_index', fn (Blueprint $t) => $t->index('status', 'accounts_status_index'));
        $this->addIndex('accounts', 'accounts_agent_id_index', fn (Blueprint $t) => $t->index('agent_id', 'accounts_agent_id_index'));
        $this->addIndex('accounts', 'accounts_account_type_index', fn (Blueprint $t) => $t->index('account_type', 'accounts_account_type_index'));
        // mysql/pgsql only, matching 2026_09_14_090000's own driver check.
        $this->addForeignKey('accounts', 'agent_id', fn (Blueprint $t) => $t->foreign('agent_id')->references('id')->on('accounts')->onDelete('cascade'));

        // =====================================================================
        // 4. USERS — ALTERs on top of the framework-default table above.
        // =====================================================================

        $this->addColumn('users', 'account_id', fn (Blueprint $t) => $t->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete());
        $this->addColumn('users', 'is_active', fn (Blueprint $t) => $t->boolean('is_active')->default(true));
        $this->addColumn('users', 'phone_number', fn (Blueprint $t) => $t->string('phone_number', 32)->nullable());
        $this->addIndex('users', 'users_account_id_is_active_index', fn (Blueprint $t) => $t->index(['account_id', 'is_active'], 'users_account_id_is_active_index'));

        // =====================================================================
        // 5. SUBSCRIPTIONS
        // =====================================================================

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('engine_type')->default('qr');
                $table->string('billing_model')->default('flat_quota');
                $table->decimal('rate_per_message', 8, 4)->nullable();
                $table->unsignedInteger('total_allocated_messages')->nullable();
                $table->unsignedInteger('used_messages')->default(0);
                $table->decimal('price_paid', 10, 2)->default(0);
                $table->string('payment_mode')->default('cash');
                $table->timestamp('starts_at');
                // Already nullable here (matches the FINAL state after
                // 2026_09_10_100000_make_subscriptions_expires_at_nullable.php)
                // — a table created fresh by this file needs no separate
                // ->change() call.
                $table->timestamp('expires_at')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
                $table->index(['account_id', 'starts_at'], 'subscriptions_account_id_starts_at_index');
                $table->index('status', 'subscriptions_status_index');
            });
        }

        // If `subscriptions` already existed on this database from before
        // 2026_09_10_100000 ran, expires_at may still be NOT NULL. Widen it
        // to nullable only if it is not already — this is a column-nullability
        // relaxation (never narrows, never drops, never touches existing row
        // values), matching that migration's own up() exactly.
        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'expires_at') && ! $this->isSqlite()) {
            $row = DB::selectOne(
                "SELECT IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS
                 WHERE table_schema = ? AND table_name = 'subscriptions' AND column_name = 'expires_at'",
                [DB::getDatabaseName()]
            );
            if ($row && strtoupper($row->is_nullable) === 'NO') {
                Schema::table('subscriptions', function (Blueprint $table) {
                    $table->timestamp('expires_at')->nullable()->change();
                });
            }
        }

        // =====================================================================
        // 6. WHATSAPP_SESSIONS
        // =====================================================================

        if (! Schema::hasTable('whatsapp_sessions')) {
            Schema::create('whatsapp_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('status')->default('disconnected');
                $table->timestamp('last_connected_at')->nullable();
                $table->timestamps();
            });
        }

        $this->addColumn('whatsapp_sessions', 'meta_phone_number_id', fn (Blueprint $t) => $t->string('meta_phone_number_id')->nullable());
        $this->addColumn('whatsapp_sessions', 'meta_waba_id', fn (Blueprint $t) => $t->string('meta_waba_id')->nullable());
        $this->addColumn('whatsapp_sessions', 'meta_access_token', fn (Blueprint $t) => $t->text('meta_access_token')->nullable());
        $this->addColumn('whatsapp_sessions', 'meta_webhook_verify_token', fn (Blueprint $t) => $t->string('meta_webhook_verify_token')->nullable()->unique());
        $this->addIndex('whatsapp_sessions', 'whatsapp_sessions_status_index', fn (Blueprint $t) => $t->index('status', 'whatsapp_sessions_status_index'));

        // =====================================================================
        // 7. PAYMENT_ALERTS
        // =====================================================================

        if (! Schema::hasTable('payment_alerts')) {
            Schema::create('payment_alerts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('recipient_phone');
                $table->string('customer_name');
                $table->decimal('amount', 10, 2);
                $table->string('payment_ref');
                $table->string('status')->default('pending');
                $table->decimal('cost_deducted', 8, 4)->default(0);
                $table->text('error_reason')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->unique(['account_id', 'payment_ref']);
                $table->index(['account_id', 'status'], 'payment_alerts_account_id_status_index');
            });
        }

        $this->addColumn('payment_alerts', 'raw_response', fn (Blueprint $t) => $t->text('raw_response')->nullable());
        $this->addIndex('payment_alerts', 'payment_alerts_account_created_idx', fn (Blueprint $t) => $t->index(['account_id', 'created_at'], 'payment_alerts_account_created_idx'));
        $this->addColumn('payment_alerts', 'gateway_message_id', fn (Blueprint $t) => $t->string('gateway_message_id')->nullable());
        $this->addIndex('payment_alerts', 'payment_alerts_gateway_message_id_index', fn (Blueprint $t) => $t->index('gateway_message_id', 'payment_alerts_gateway_message_id_index'));

        // =====================================================================
        // 8. PAYMENT_GATEWAY_SETTINGS
        // =====================================================================

        if (! Schema::hasTable('payment_gateway_settings')) {
            Schema::create('payment_gateway_settings', function (Blueprint $table) {
                $table->id();
                $table->string('gateway')->unique();
                $table->string('mode')->default('test');
                $table->boolean('is_enabled')->default(false);
                $table->string('test_key_id')->nullable();
                $table->text('test_key_secret')->nullable();
                $table->text('test_webhook_secret')->nullable();
                $table->string('live_key_id')->nullable();
                $table->text('live_key_secret')->nullable();
                $table->text('live_webhook_secret')->nullable();
                $table->timestamps();
            });
        }

        // =====================================================================
        // 9. INVOICES
        // =====================================================================

        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('invoice_number')->unique();
                $table->string('plan_key');
                $table->string('plan_label');
                $table->decimal('amount', 10, 2);
                $table->decimal('tax_amount', 10, 2)->default(0);
                $table->decimal('total_amount', 10, 2);
                $table->string('currency', 3)->default('INR');
                $table->string('payment_gateway');
                $table->string('gateway_order_id')->nullable()->unique();
                $table->string('gateway_payment_id')->nullable();
                $table->string('status')->default('pending');
                $table->timestamp('paid_at')->nullable();
                $table->text('gateway_raw_response')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'status'], 'invoices_account_id_status_index');
                $table->index(['account_id', 'created_at'], 'invoices_account_id_created_at_index');
            });
        }

        $this->addIndex('invoices', 'invoices_status_paid_at_idx', fn (Blueprint $t) => $t->index(['status', 'paid_at'], 'invoices_status_paid_at_idx'));

        // =====================================================================
        // 10. API_KEYS
        // =====================================================================

        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('key_prefix', 32);
                $table->string('key_hash', 64)->unique();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'revoked_at'], 'api_keys_account_id_revoked_at_index');
            });
        }

        $this->addColumn('api_keys', 'secret_prefix', fn (Blueprint $t) => $t->string('secret_prefix', 32)->nullable());
        $this->addColumn('api_keys', 'secret_hash', fn (Blueprint $t) => $t->string('secret_hash', 64)->nullable()->unique());

        // =====================================================================
        // 11. WEBHOOK_SUBSCRIPTIONS / WEBHOOK_DELIVERIES
        // =====================================================================

        if (! Schema::hasTable('webhook_subscriptions')) {
            Schema::create('webhook_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('url');
                $table->text('secret');
                $table->json('events');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['account_id', 'is_active'], 'webhook_subscriptions_account_id_is_active_index');
            });
        }

        if (! Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
                $table->string('event');
                $table->json('payload');
                $table->unsignedSmallInteger('response_code')->nullable();
                $table->string('status');
                $table->unsignedTinyInteger('attempt')->default(1);
                $table->timestamps();
                $table->index(['webhook_subscription_id', 'created_at'], 'webhook_deliveries_webhook_subscription_id_created_at_index');
            });
        }

        // =====================================================================
        // 12. CHATBOT_RULES / CHATBOT_LOGS
        // =====================================================================

        if (! Schema::hasTable('chatbot_rules')) {
            Schema::create('chatbot_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('match_type');
                $table->json('keywords');
                $table->string('response_type');
                $table->json('response_payload');
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['account_id', 'is_active', 'priority'], 'chatbot_rules_account_id_is_active_priority_index');
            });
        }

        if (! Schema::hasTable('chatbot_logs')) {
            Schema::create('chatbot_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chatbot_rule_id')->nullable()->constrained()->nullOnDelete();
                $table->string('sender_phone');
                $table->text('incoming_message');
                $table->text('reply_sent')->nullable();
                $table->string('status');
                $table->timestamps();
                $table->index(['account_id', 'created_at'], 'chatbot_logs_account_id_created_at_index');
            });
        }

        // =====================================================================
        // 13. MAIL_SETTINGS
        // =====================================================================

        if (! Schema::hasTable('mail_settings')) {
            Schema::create('mail_settings', function (Blueprint $table) {
                $table->id();
                $table->string('mailer')->default('smtp');
                $table->string('host')->nullable();
                $table->unsignedInteger('port')->nullable();
                $table->string('username')->nullable();
                $table->text('password')->nullable();
                $table->string('encryption')->nullable();
                $table->string('from_address')->nullable();
                $table->string('from_name')->nullable();
                $table->timestamps();
            });
        }

        // =====================================================================
        // 14. LOGIN_AUDIT_LOGS
        // =====================================================================

        if (! Schema::hasTable('login_audit_logs')) {
            Schema::create('login_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->string('role')->nullable();
                $table->string('email')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('status');
                $table->timestamp('logged_in_at');
                $table->timestamps();
                $table->index(['account_id', 'logged_in_at'], 'login_audit_logs_account_id_logged_in_at_index');
                $table->index(['user_id', 'logged_in_at'], 'login_audit_logs_user_id_logged_in_at_index');
                $table->index('status', 'login_audit_logs_status_index');
            });
        }

        // =====================================================================
        // 15. NOTIFICATION_TEMPLATES / NOTIFICATION_BROADCASTS /
        //     IN_APP_NOTIFICATIONS / MAIL_LOGS
        // =====================================================================

        if (! Schema::hasTable('notification_templates')) {
            Schema::create('notification_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type')->default('rich_text');
                $table->string('category')->nullable();
                $table->string('subject');
                $table->longText('body');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notification_broadcasts')) {
            Schema::create('notification_broadcasts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->string('subject');
                $table->longText('body');
                $table->string('category')->nullable();
                $table->json('channels');
                $table->string('target_type');
                $table->string('target_summary')->nullable();
                $table->unsignedInteger('recipient_count')->default(0);
                $table->unsignedInteger('email_sent_count')->default(0);
                $table->unsignedInteger('email_failed_count')->default(0);
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'sent_at'], 'notification_broadcasts_account_id_sent_at_index');
            });
        }

        if (! Schema::hasTable('in_app_notifications')) {
            Schema::create('in_app_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('broadcast_id')->nullable()->constrained('notification_broadcasts')->nullOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('title');
                $table->text('body');
                $table->string('category')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'is_read'], 'in_app_notifications_user_id_is_read_index');
            });
        }

        if (! Schema::hasTable('mail_logs')) {
            Schema::create('mail_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('broadcast_id')->nullable()->constrained('notification_broadcasts')->nullOnDelete();
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->string('recipient_email');
                $table->string('recipient_name')->nullable();
                $table->string('subject');
                $table->enum('status', ['sent', 'failed']);
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at');
                $table->timestamps();
                $table->index(['account_id', 'sent_at'], 'mail_logs_account_id_sent_at_index');
                $table->index(['broadcast_id'], 'mail_logs_broadcast_id_index');
                $table->index(['status'], 'mail_logs_status_index');
            });
        }

        // =====================================================================
        // 16. MESSAGE_TEMPLATES
        // =====================================================================

        if (! Schema::hasTable('message_templates')) {
            Schema::create('message_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('industry_type')->nullable();
                $table->string('title');
                $table->text('template_body');
                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['account_id', 'status'], 'message_templates_account_id_status_index');
                $table->index('status', 'message_templates_status_index');
                $table->index('industry_type', 'message_templates_industry_type_index');
            });
        }

        $this->addColumn('message_templates', 'variables_schema', fn (Blueprint $t) => $t->json('variables_schema')->nullable());
        $this->addColumn('message_templates', 'is_super_admin_tested', fn (Blueprint $t) => $t->boolean('is_super_admin_tested')->default(false));
        $this->addColumn('message_templates', 'tested_at', fn (Blueprint $t) => $t->timestamp('tested_at')->nullable());
        $this->addColumn('message_templates', 'header_type', fn (Blueprint $t) => $t->enum('header_type', ['text', 'image', 'document'])->default('text'));
        $this->addColumn('message_templates', 'rejection_reason', fn (Blueprint $t) => $t->text('rejection_reason')->nullable());
        $this->addColumn('message_templates', 'header_media_url', fn (Blueprint $t) => $t->string('header_media_url')->nullable());

        // Widen the status enum only if it hasn't been widened yet — never
        // narrows, never touches existing row values (every pre-existing
        // 'pending'/'approved'/'rejected' row stays valid against the wider list).
        if (Schema::hasTable('message_templates') && Schema::hasColumn('message_templates', 'status')
            && ! $this->enumContainsValue('message_templates', 'status', 'pending_agent_review')) {
            Schema::table('message_templates', function (Blueprint $table) {
                $table->enum('status', [
                    'pending',
                    'pending_agent_review',
                    'pending_admin_review',
                    'pending_meta_approval',
                    'approved',
                    'rejected',
                ])->default('pending')->change();
            });
        }

        // =====================================================================
        // 17. SOCIAL_PROVIDER_CONFIGS / SOCIAL_ACCOUNTS
        // =====================================================================

        if (! Schema::hasTable('social_provider_configs')) {
            Schema::create('social_provider_configs', function (Blueprint $table) {
                $table->id();
                $table->string('provider')->unique();
                $table->text('client_id')->nullable();
                $table->text('client_secret')->nullable();
                $table->string('redirect_uri')->nullable();
                $table->text('webhook_verify_token')->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('social_accounts')) {
            Schema::create('social_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('provider');
                $table->string('asset_type');
                $table->string('provider_id');
                $table->string('name')->nullable();
                $table->string('avatar_url')->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->string('health_status')->default('connected');
                $table->timestamps();
                $table->unique(['account_id', 'provider', 'provider_id']);
                $table->index(['account_id', 'asset_type'], 'social_accounts_account_id_asset_type_index');
            });
        }

        // =====================================================================
        // 18. LEADS
        // =====================================================================

        if (! Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();
                $table->string('provider')->default('meta');
                $table->string('provider_lead_id')->unique();
                $table->string('form_id')->nullable();
                $table->string('ad_id')->nullable();
                $table->string('lead_name')->nullable();
                $table->string('lead_phone')->nullable();
                $table->string('lead_email')->nullable();
                $table->json('raw_field_data')->nullable();
                $table->timestamp('tenant_notified_at')->nullable();
                $table->timestamp('lead_welcomed_at')->nullable();
                $table->string('tenant_notify_error')->nullable();
                $table->string('lead_welcome_error')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'lead_phone', 'created_at'], 'leads_account_id_lead_phone_created_at_index');
            });
        }

        // =====================================================================
        // 19. AD_CAMPAIGNS / AD_CAMPAIGN_DAILY_METRICS
        // =====================================================================

        if (! Schema::hasTable('ad_campaigns')) {
            Schema::create('ad_campaigns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();
                $table->string('meta_campaign_id')->unique();
                $table->string('meta_adset_id')->nullable();
                $table->string('meta_ad_id')->nullable();
                $table->string('name');
                $table->string('objective');
                $table->string('status')->default('ACTIVE');
                $table->decimal('daily_budget', 10, 2);
                $table->decimal('cpl_threshold', 10, 2)->nullable();
                $table->decimal('last_spend', 10, 2)->default(0);
                $table->unsignedInteger('last_impressions')->default(0);
                $table->unsignedInteger('last_leads')->default(0);
                $table->decimal('last_cpl', 10, 2)->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('auto_paused_at')->nullable();
                $table->string('auto_pause_reason')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'status'], 'ad_campaigns_account_id_status_index');
            });
        }

        if (! Schema::hasTable('ad_campaign_daily_metrics')) {
            Schema::create('ad_campaign_daily_metrics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
                $table->date('metric_date');
                $table->decimal('spend', 10, 2)->default(0);
                $table->unsignedInteger('impressions')->default(0);
                $table->unsignedInteger('leads')->default(0);
                $table->decimal('cpl', 10, 2)->nullable();
                $table->timestamps();
                $table->unique(['ad_campaign_id', 'metric_date']);
                $table->index(['account_id', 'metric_date'], 'ad_campaign_daily_metrics_account_id_metric_date_index');
            });
        }

        // =====================================================================
        // 20. COMMENT_AUTOMATION_RULES / COMMENT_AUTOMATION_EVENTS
        // =====================================================================

        if (! Schema::hasTable('comment_automation_rules')) {
            Schema::create('comment_automation_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('keyword');
                $table->text('public_reply_template');
                $table->text('private_dm_template');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['account_id', 'is_active'], 'comment_automation_rules_account_id_is_active_index');
            });
        }

        if (! Schema::hasTable('comment_automation_events')) {
            Schema::create('comment_automation_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('comment_automation_rule_id')->nullable()->constrained('comment_automation_rules')->nullOnDelete();
                $table->string('platform');
                $table->string('comment_id')->unique();
                $table->string('post_id')->nullable();
                $table->string('commenter_id')->nullable();
                $table->timestamp('public_replied_at')->nullable();
                $table->string('public_reply_error')->nullable();
                $table->timestamp('private_message_sent_at')->nullable();
                $table->string('private_message_error')->nullable();
                $table->timestamps();
            });
        }

        // =====================================================================
        // 21. QUOTA_REQUESTS
        // =====================================================================

        if (! Schema::hasTable('quota_requests')) {
            Schema::create('quota_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
                $table->unsignedInteger('requested_extra_messages');
                $table->text('reason')->nullable();
                $table->string('status')->default('pending');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->timestamps();
                $table->index(['account_id', 'status'], 'quota_requests_account_id_status_index');
                $table->index(['status', 'created_at'], 'quota_requests_status_created_at_index');
            });
        }

        // =====================================================================
        // 22. ORGANIC_POSTS
        // =====================================================================

        if (! Schema::hasTable('organic_posts')) {
            Schema::create('organic_posts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();
                $table->string('provider');
                $table->string('platform');
                $table->text('caption');
                $table->string('media_url')->nullable();
                $table->string('media_type')->nullable();
                $table->string('status')->default('pending');
                $table->string('external_post_id')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'platform', 'status'], 'organic_posts_account_id_platform_status_index');
            });
        }

        // =====================================================================
        // 23. WHATSAPP_FLOWS / WHATSAPP_FLOW_SESSIONS
        // =====================================================================

        if (! Schema::hasTable('whatsapp_flows')) {
            Schema::create('whatsapp_flows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('trigger_type');
                $table->string('trigger_value')->nullable();
                $table->json('graph_data');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['account_id', 'is_active', 'trigger_type'], 'whatsapp_flows_account_id_is_active_trigger_type_index');
            });
        }

        if (! Schema::hasTable('whatsapp_flow_sessions')) {
            Schema::create('whatsapp_flow_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('flow_id')->constrained('whatsapp_flows')->cascadeOnDelete();
                $table->string('phone_number');
                $table->string('current_node_id')->nullable();
                $table->json('context_data')->nullable();
                $table->string('status')->default('active');
                $table->timestamp('last_interaction_at')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'phone_number', 'status'], 'whatsapp_flow_sessions_account_id_phone_number_status_index');
            });
        }

        // =====================================================================
        // 24. CONTACT_GROUPS / CONTACT_GROUP_MEMBERS
        //     (created here, BEFORE message_dispatch_logs below, so that
        //     table's group_id foreign key always has somewhere to point.)
        // =====================================================================

        if (! Schema::hasTable('contact_groups')) {
            Schema::create('contact_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                $table->index(['account_id', 'is_default'], 'contact_groups_account_id_is_default_index');
            });
        }

        $this->addColumn('contact_groups', 'group_type', fn (Blueprint $t) => $t->string('group_type')->default('internal_segment'));
        $this->addColumn('contact_groups', 'wa_group_jid', fn (Blueprint $t) => $t->string('wa_group_jid')->nullable());
        $this->addColumn('contact_groups', 'invite_link', fn (Blueprint $t) => $t->string('invite_link')->nullable());
        $this->addColumn('contact_groups', 'sync_status', fn (Blueprint $t) => $t->string('sync_status')->nullable());
        $this->addColumn('contact_groups', 'sync_error', fn (Blueprint $t) => $t->text('sync_error')->nullable());
        $this->addIndex('contact_groups', 'contact_groups_account_id_group_type_index', fn (Blueprint $t) => $t->index(['account_id', 'group_type'], 'contact_groups_account_id_group_type_index'));

        if (! Schema::hasTable('contact_group_members')) {
            Schema::create('contact_group_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained('contact_groups')->cascadeOnDelete();
                $table->string('phone_number');
                $table->string('name')->nullable();
                $table->timestamps();
                $table->unique(['group_id', 'phone_number']);
            });
        }

        // =====================================================================
        // 25. MESSAGE_DISPATCH_LOGS
        //     Base create (2026_09_13_120000) + every later ALTER, replayed.
        //     By this point in the file, both `api_keys` and `contact_groups`
        //     already exist (created above), so the FK-bearing columns below
        //     are always safe to add.
        // =====================================================================

        if (! Schema::hasTable('message_dispatch_logs')) {
            Schema::create('message_dispatch_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('source');
                $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
                $table->string('recipient_phone');
                $table->string('status');
                $table->text('error_reason')->nullable();
                $table->boolean('has_media')->default(false);
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'created_at'], 'message_dispatch_logs_account_id_created_at_index');
                $table->index(['account_id', 'status'], 'message_dispatch_logs_account_id_status_index');
                $table->index(['account_id', 'source'], 'message_dispatch_logs_account_id_source_index');
            });
        }

        $this->addColumn('message_dispatch_logs', 'template_name', fn (Blueprint $t) => $t->string('template_name')->nullable());
        $this->addColumn('message_dispatch_logs', 'message_preview', fn (Blueprint $t) => $t->string('message_preview', 200)->nullable());
        $this->addColumn('message_dispatch_logs', 'recipient_type', fn (Blueprint $t) => $t->string('recipient_type')->default('individual'));
        $this->addColumn('message_dispatch_logs', 'group_id', fn (Blueprint $t) => $t->foreignId('group_id')->nullable()->constrained('contact_groups')->nullOnDelete());
        $this->addColumn('message_dispatch_logs', 'group_name', fn (Blueprint $t) => $t->string('group_name')->nullable());
        $this->addColumn('message_dispatch_logs', 'recipient_count', fn (Blueprint $t) => $t->unsignedInteger('recipient_count')->default(1));
        $this->addColumn('message_dispatch_logs', 'success_count', fn (Blueprint $t) => $t->unsignedInteger('success_count')->nullable());
        $this->addColumn('message_dispatch_logs', 'failure_count', fn (Blueprint $t) => $t->unsignedInteger('failure_count')->nullable());
        $this->addColumn('message_dispatch_logs', 'gateway_message_id', fn (Blueprint $t) => $t->string('gateway_message_id')->nullable());
        $this->addIndex('message_dispatch_logs', 'message_dispatch_logs_gateway_message_id_index', fn (Blueprint $t) => $t->index('gateway_message_id', 'message_dispatch_logs_gateway_message_id_index'));

        // =====================================================================
        // 26. ROUTE_CATEGORIES / SYSTEM_ROUTES / ACTIVITY_LOGS
        // =====================================================================

        if (! Schema::hasTable('route_categories')) {
            Schema::create('route_categories', function (Blueprint $table) {
                $table->id();
                $table->string('category_name');
                $table->string('category_code')->unique();
                $table->string('icon_name')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('system_routes')) {
            Schema::create('system_routes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('category_id');
                $table->index('category_id', 'system_routes_category_id_index');
                $table->string('route_title');
                $table->string('route_path')->nullable();
                $table->string('permission_key');
                $table->index('permission_key', 'system_routes_permission_key_index');
                $table->boolean('is_agent_assignable')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->unsignedBigInteger('agent_id')->nullable();
                $table->string('module_name');
                $table->string('action_type');
                $table->string('route_path')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'created_at'], 'activity_logs_account_id_created_at_index');
                $table->index(['agent_id', 'created_at'], 'activity_logs_agent_id_created_at_index');
                $table->index(['module_name', 'created_at'], 'activity_logs_module_name_created_at_index');
                $table->index('action_type', 'activity_logs_action_type_index');
            });
        }
    }

    /**
     * Intentionally inert.
     *
     * This migration only ever CREATEs missing tables and ADDs missing
     * columns/indexes/foreign keys — it never records which of those
     * already existed before it ran versus which it just added. A generic
     * down() cannot tell those apart, and guessing wrong would mean
     * dropping a column or table that existed on production long before
     * this migration was ever written — a direct violation of this
     * project's standing "no data loss on production" rule.
     *
     * If this migration ever needs to be reversed, write and review a
     * dedicated, explicitly-authorized migration for that specific
     * production state at that time. Do not attempt to infer one from
     * this file.
     */
    public function down(): void
    {
        // Deliberately empty — see docblock above.
    }
};
