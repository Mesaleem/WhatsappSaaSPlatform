<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Task 11 — Agent Registry + controlled tool execution. Three new
 * tables; nothing existing is altered (purely additive).
 *
 *   ai_agents                  one registered AI agent, owned by ONE account
 *   ai_agent_versions          immutable snapshots of an agent's executable
 *                              configuration (instructions, model hint, tool
 *                              allow-list, limits)
 *   ai_agent_tool_invocations  one executed (or refused) tool call — the
 *                              durable idempotency record of side effects
 *
 * Named ai_* on purpose: "agent" already means a reseller account in this
 * codebase (accounts.agent_id).
 *
 * TENANT SAFETY IS A SCHEMA PROPERTY (the CRM / knowledge-base pattern):
 *   ai_agent_versions (ai_agent_id, account_id) → ai_agents (id, account_id)
 * so a version can never belong to another account's agent. CASCADE: deleting
 * an agent deletes its versions (a journey still pointing at it then fails at
 * run time — resolved inside the session's own account only).
 *
 * ai_agents.current_version_id deliberately has NO foreign key (it would be
 * circular); it is only ever resolved through
 * AiAgentVersion::forAccount()->where('ai_agent_id', …).
 *
 * ai_agent_tool_invocations keeps agent/version/flow/session ids as plain
 * columns (an audit record outlives a deleted agent or journey) and only
 * cascades with its account. unique(account_id, invocation_key) is the
 * exactly-once guarantee: a side-effecting tool writes its effect and this
 * row in ONE transaction, so a retry either finds the row (returns the
 * stored result, no second effect) or finds nothing because nothing
 * happened. Tool ARGUMENTS are not stored (they may carry customer data) —
 * only their hash; the stored result is the normalized, size-limited value
 * the model received.
 *
 * No credential is stored anywhere: agents use the platform AI provider
 * configuration through AiManager / MeteredAiService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('version_count')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'account_id'], 'ai_agents_id_account_unique');
            $table->unique(['account_id', 'name'], 'ai_agents_account_name_unique');
        });

        Schema::create('ai_agent_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('ai_agent_id');
            $table->unsignedInteger('version');
            $table->text('instructions');
            $table->string('model', 128)->nullable();
            $table->json('tools');
            $table->json('settings')->nullable();
            $table->char('config_hash', 64);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['ai_agent_id', 'version'], 'ai_agent_versions_agent_version_unique');
            $table->index(['ai_agent_id', 'account_id'], 'ai_agent_versions_agent_account_index');

            $table->foreign(['ai_agent_id', 'account_id'], 'ai_agent_versions_agent_account_foreign')
                ->references(['id', 'account_id'])
                ->on('ai_agents')
                ->cascadeOnDelete();
        });

        Schema::create('ai_agent_tool_invocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('ai_agent_id')->nullable();
            $table->unsignedBigInteger('ai_agent_version_id')->nullable();
            $table->string('invocation_key', 191);
            $table->string('tool', 64);
            $table->string('status', 16); // succeeded | error | denied
            $table->char('arguments_hash', 64);
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('source', 32)->nullable();
            $table->unsignedBigInteger('flow_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'invocation_key'], 'ai_agent_tool_invocations_account_key_unique');
            $table->index(['account_id', 'ai_agent_id'], 'ai_agent_tool_invocations_account_agent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_tool_invocations');
        Schema::dropIfExists('ai_agent_versions');
        Schema::dropIfExists('ai_agents');
    }
};
