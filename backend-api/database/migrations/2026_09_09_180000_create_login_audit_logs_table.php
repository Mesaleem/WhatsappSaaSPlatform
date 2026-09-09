<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role-Based Login Audit Logging. One row per login ATTEMPT (success
     * or failure), written from AuthController::login() — see that
     * controller's logAttempt() for the write path.
     *
     * `role`/`account_id` are snapshotted onto the row at login time
     * (rather than only resolved via the users table at read time)
     * deliberately: a user's role or account can change later, and a
     * historical audit row should keep reflecting what was true at the
     * moment of that login, not the user's current state.
     *
     * `email` is not in the spec's literal field list but is needed to
     * make a FAILED attempt with an unrecognized email address (no
     * user_id to resolve) meaningful in the report — a disclosed,
     * necessary addition.
     *
     * `user_id` is nullable + nullOnDelete: a login attempt against an
     * email that never matched a user (typo, no such account) has no
     * user to reference; deleting a user later must not cascade-delete
     * their historical audit trail.
     */
    public function up(): void
    {
        Schema::create('login_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('role')->nullable();
            $table->string('email')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status'); // 'success' | 'failed'
            $table->timestamp('logged_in_at');
            $table->timestamps();

            $table->index(['account_id', 'logged_in_at']);
            $table->index(['user_id', 'logged_in_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_audit_logs');
    }
};
