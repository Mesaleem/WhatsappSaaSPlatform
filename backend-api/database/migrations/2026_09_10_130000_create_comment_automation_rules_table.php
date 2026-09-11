<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
     * Ad Comment Auto-Responder. One row per tenant-configured keyword
     * trigger (CommentRulesPage's CRUD UI). Columns match the spec
     * exactly: account_id, keyword, public_reply_template,
     * private_dm_template, is_active.
     *
     * `keyword` of literal '*' is the documented wildcard — matches any
     * comment when no more specific keyword rule matches first (see
     * CommentAutomationService::findMatchingRule()'s docblock for the
     * exact precedence).
     */
    public function up(): void
    {
        Schema::create('comment_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->text('public_reply_template');
            $table->text('private_dm_template');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_automation_rules');
    }
};
