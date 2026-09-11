<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
     * NOT part of the literal spec's table list — a necessary addition,
     * disclosed in the Phase 4 audit report, for the same reason
     * `leads.provider_lead_id` exists (Phase 2): Meta retries a webhook
     * delivery on any non-2xx or slow response, and this app's own
     * per-entry try/catch (SocialWebhookController::handle()) always
     * returns 200 regardless of processing outcome — without a
     * technical redelivery guard, the SAME comment could be
     * auto-replied to (and a duplicate private DM sent) more than once.
     * `comment_id` unique is that guard, checked BEFORE any Graph API
     * call in CommentAutomationService (cheap, no external round trip).
     */
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('comment_automation_events');
    }
};
