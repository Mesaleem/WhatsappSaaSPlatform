<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public API authorized-server binding. A valid API key alone no longer proves the caller is the buyer's server:
 * a key is bound to ONE authorized installation (a cryptographically generated installation credential, stored only
 * as a hash) plus an IP policy. All additive: three new tables and two nullable columns on api_keys. Existing keys get
 * NO binding row and therefore keep working exactly as before ("legacy / unbound", see config/api_binding.php) until
 * their owner or a Super Admin binds them.
 *
 *  - api_key_bindings          one active/pending binding per key (active_slot unique trick, portable to SQLite/MariaDB);
 *                              revoked rows are kept as history.
 *  - api_key_change_requests   buyer-initiated "move to another server" requests, decided by a Super Admin
 *                              (pending_slot allows one pending request per key).
 *  - api_key_security_events   safe audit trail: event name, key/account/binding ids, client IP - never a secret/payload.
 *  - api_keys.access_disabled_at / access_disabled_reason   Super Admin kill-switch for a key without revoking it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->string('status', 24);                       // pending_activation | active | revoked
            $table->unsignedTinyInteger('active_slot')->nullable(); // 1 while pending/active, NULL when revoked
            $table->string('label', 100)->nullable();
            $table->string('ip_policy', 16);                    // NONE | SINGLE_IP | IP_ALLOWLIST
            $table->json('authorized_ips')->nullable();         // exact IPs and/or CIDRs, normalised
            $table->string('installation_prefix', 24)->nullable();
            $table->string('installation_hash', 64)->nullable(); // sha256 of the installation credential
            $table->string('registered_ip', 45)->nullable();     // IP observed at activation
            $table->timestamp('registered_at')->nullable();
            $table->string('last_success_ip', 45)->nullable();
            $table->string('last_success_client', 24)->nullable(); // installation_prefix of the credential last accepted
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 191)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['api_key_id', 'active_slot'], 'akb_one_live_binding_per_key');
            $table->index(['account_id', 'status'], 'akb_account_status_index');
        });

        Schema::create('api_key_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->foreignId('current_binding_id')->nullable()->constrained('api_key_bindings')->nullOnDelete();
            $table->string('status', 16);                       // pending | approved | rejected
            $table->unsignedTinyInteger('pending_slot')->nullable();
            $table->string('current_label', 100)->nullable();
            $table->string('current_ip', 45)->nullable();
            $table->string('requested_label', 100)->nullable();
            $table->string('requested_ip_policy', 16);
            $table->json('requested_ips');
            $table->string('reason', 500);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('new_binding_id')->nullable()->constrained('api_key_bindings')->nullOnDelete();
            $table->timestamps();

            $table->unique(['api_key_id', 'pending_slot'], 'akcr_one_pending_per_key');
            $table->index(['status', 'created_at'], 'akcr_status_index');
        });

        Schema::create('api_key_security_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->unsignedBigInteger('binding_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event', 48);
            $table->string('ip', 45)->nullable();
            $table->json('context')->nullable();                // safe identifiers only
            $table->timestamp('created_at')->useCurrent();

            $table->index(['api_key_id', 'created_at'], 'akse_key_time_index');
            $table->index(['account_id', 'created_at'], 'akse_account_time_index');
            $table->index(['event', 'created_at'], 'akse_event_time_index');
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->timestamp('access_disabled_at')->nullable();
            $table->string('access_disabled_reason', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['access_disabled_at', 'access_disabled_reason']);
        });
        Schema::dropIfExists('api_key_security_events');
        Schema::dropIfExists('api_key_change_requests');
        Schema::dropIfExists('api_key_bindings');
    }
};
