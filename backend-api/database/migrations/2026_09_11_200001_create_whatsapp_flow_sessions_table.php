<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 5 — No-Code WhatsApp Journey Builder. One row per in-progress
     * (or completed/expired) conversational journey a specific phone
     * number is walking through a specific WhatsAppFlow. WhatsAppJourneyEngine
     * looks up "does this phone number already have an ACTIVE session?"
     * on every inbound message BEFORE evaluating flow triggers — this
     * table is what makes a multi-turn conversation (several separate
     * inbound webhook requests, one per reply) resumable across those
     * requests, since each webhook call is otherwise stateless.
     *
     * `current_node_id` is the id of the 'question' node this session is
     * PAUSED at, waiting for the tenant's customer to answer — only
     * 'question' nodes ever leave a session paused (see
     * WhatsAppJourneyEngine::advance()); every other node type executes
     * immediately and moves on within the same webhook request.
     *
     * `context_data` (json) accumulates one key per 'question' node's
     * configured `variable_name`, keyed by that name, e.g.
     * {"user_name": "Priya", "budget_range": "10-20L"} — read by
     * 'condition' nodes for branching and written into `leads.raw_field_data`
     * (Lead::updateOrCreate) by a terminal 'save_lead' node.
     *
     * `status`: 'active' (paused at a question, or mid-execution within
     * the current request), 'completed' (reached a save_lead node, or a
     * dead-end with no further edge), 'expired' — DISCLOSED GAP: no
     * scheduled command exists yet to sweep stale 'active' sessions
     * (e.g. a customer who never replies) into 'expired' after a
     * timeout; 'expired' is a valid, modeled status but nothing
     * currently transitions a row into it automatically. A future
     * module should add a scheduled command for this (same shape as
     * CheckAdPerformanceRules) — flagged here rather than silently
     * assumed handled.
     *
     * cascadeOnDelete on flow_id (unlike ad_campaigns.social_account_id's
     * nullOnDelete precedent): a session has no meaning independent of
     * the flow graph it's walking — if the tenant deletes the flow
     * itself, its in-flight sessions are deleted with it rather than
     * left pointing at a graph that no longer exists.
     *
     * At most one 'active' session per (account_id, phone_number) is an
     * APPLICATION-level invariant (enforced in WhatsAppJourneyEngine's
     * session-lookup-before-create logic), not a DB constraint — a
     * partial/conditional unique index (unique only WHERE status =
     * 'active') is not portably expressible in a single Laravel
     * migration across this app's supported DB engines, same disclosed
     * trade-off already made for leads' 24-hour dedup window.
     */
    public function up(): void
    {
        Schema::create('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('whatsapp_flows')->cascadeOnDelete();
            $table->string('phone_number');
            $table->string('current_node_id')->nullable();
            $table->json('context_data')->nullable();
            $table->string('status')->default('active'); // active | completed | expired
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'phone_number', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flow_sessions');
    }
};
