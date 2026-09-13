<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 5 — No-Code WhatsApp Journey Builder. One row per tenant-
     * authored conversational flow: a graph of nodes (trigger/message/
     * question/condition/save_lead) and directed edges, edited visually
     * on JourneyBuilderPage.tsx and executed by WhatsAppJourneyEngine.
     *
     * `trigger_type`/`trigger_value` mirror chatbot_rules' `match_type`/
     * `keywords` precedent (see that migration's docblock) but are
     * deliberately a SEPARATE, simpler vocabulary rather than reusing
     * ChatbotRule::MATCH_TYPES: a Journey only needs to know HOW it gets
     * entered (a keyword message, a Click-to-WhatsApp ad referral, or
     * "nothing else matched" — the catch-all), not chatbot_rules' finer
     * exact/contains/starts_with/regex matching distinctions, which
     * would be over-specified for a flow's single entry trigger.
     *   - 'keyword': trigger_value holds one or more comma-separated
     *     keywords, matched case-insensitively by substring containment
     *     against the inbound message (see WhatsAppJourneyEngine).
     *   - 'ctwa_referral': fires on a Click-to-Whatsapp-ad-attributed
     *     inbound message (see Social/Ads Launcher Overhaul Step 4's
     *     message.referral capture). trigger_value optionally holds a
     *     specific Meta ad_id to scope this flow to ONE ad's clicks;
     *     left null/empty, the flow fires on ANY CTWA referral for this
     *     tenant.
     *   - 'default': the catch-all fallback flow, analogous to
     *     chatbot_rules.match_type='fallback' — fires only when no
     *     keyword/ctwa_referral flow (and no chatbot_rules match either
     *     — journeys are tried BEFORE chatbot rules, see
     *     ChatbotEngineService::handleInboundMessage()) matched. At most
     *     one 'default' flow per account is meaningful; a tenant with
     *     more than one active 'default' flow gets the first one found
     *     (application-level, not DB-enforced — same disclosed pattern
     *     as chatbot_rules' fallback-priority handling).
     *
     * `graph_data` shape (see WhatsAppJourneyEngine's class docblock for
     * the full node-type contract):
     *   {"nodes": [{"id": string, "type": "trigger"|"message"|"question"|
     *     "condition"|"save_lead", "position": {"x": number, "y": number},
     *     "data": {...node-type-specific fields...}}, ...],
     *    "edges": [{"id": string, "source": string, "target": string,
     *     "condition"?: {"operator": string, "value": string},
     *     "is_default"?: boolean}, ...]}
     * `position` is UI-only (canvas layout), never read by the engine —
     * kept alongside the node so the frontend doesn't need a second
     * lookup table to remember where the tenant dragged each box.
     */
    public function up(): void
    {
        Schema::create('whatsapp_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('trigger_type'); // keyword | ctwa_referral | default
            $table->string('trigger_value')->nullable();
            $table->json('graph_data');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'is_active', 'trigger_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flows');
    }
};
