<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performance & Database Optimization audit — this migration adds the
     * indexes that inspection showed were GENUINELY missing and actively
     * queried. It deliberately does NOT blanket-add indexes on every
     * column the spec named (account_id, role, status, created_at,
     * email, user_id) on every table it named (users, accounts,
     * login_audit_logs, messages, broadcasts) — most of that ground is
     * already covered:
     *  - Every foreignId()->constrained() column (account_id, user_id,
     *    etc. on users, login_audit_logs, payment_alerts,
     *    notification_broadcasts, chatbot_rules, chatbot_logs, mail_logs,
     *    ...) already gets an implicit index from its FK constraint.
     *  - login_audit_logs already has (account_id, logged_in_at),
     *    (user_id, logged_in_at) and status indexes from its own
     *    migration.
     *  - payment_alerts ("messages") already has (account_id, status),
     *    (account_id, created_at) and gateway_message_id indexes.
     *  - notification_broadcasts ("broadcasts") already has
     *    (account_id, sent_at).
     *  - users.email is already unique (= indexed); "role" isn't a users
     *    column at all (Spatie roles live in model_has_roles, which ships
     *    its own indexes).
     *
     * What was actually missing, confirmed by reading the controllers
     * that query them:
     *  - accounts.status — filtered directly in AccountController::index()
     *    with no index backing it.
     *  - users(account_id, is_active) — account_id alone is already
     *    indexed via its FK, but TeamController/NotificationBroadcastController
     *    both filter account_id together with is_active; a composite
     *    index serves that combined filter without a second lookup.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'is_active']);
        });
    }
};
