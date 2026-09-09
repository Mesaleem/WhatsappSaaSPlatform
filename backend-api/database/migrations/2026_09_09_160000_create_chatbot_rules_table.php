<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `priority`: LOWER value is evaluated FIRST. ChatbotEngineService
     * walks a tenant's active rules ordered `priority ASC, id ASC` and
     * stops at the first match — this direction is a disclosed design
     * choice (the spec did not state which way "priority score" runs).
     *
     * `match_type` includes a 5th value beyond the spec's literal four
     * (exact/contains/starts_with/regex): 'fallback'. Rather than adding
     * a separate fallback-settings table/columns for "enforces fallback
     * response if enabled" (spec, requirement 2), a fallback response IS
     * simply a chatbot_rules row with match_type='fallback' — its
     * `keywords` are unused/empty, it always "matches" but ONLY as the
     * last resort when no other active rule matched, and "if enabled" is
     * just that row's own `is_active` flag. This reuses 100% of the
     * existing CRUD/UI instead of inventing a parallel feature — a
     * disclosed, minimal-schema-impact interpretation.
     *
     * `keywords` (json array of strings) and `response_payload` (json)
     * shapes are documented in ChatbotEngineService's docblock.
     */
    public function up(): void
    {
        Schema::create('chatbot_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('match_type'); // exact | contains | starts_with | regex | fallback
            $table->json('keywords');
            $table->string('response_type'); // text | media | interactive
            $table->json('response_payload');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_rules');
    }
};
