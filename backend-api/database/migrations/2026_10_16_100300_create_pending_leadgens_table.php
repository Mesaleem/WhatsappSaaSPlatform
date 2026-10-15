<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA fixes (2026-10-14) — a Meta lead-ad lead is never lost.
 *
 * The leadgen webhook carries only an id; the lead's answers are fetched from
 * Meta. When that fetch failed (expired Page token, network, rate limit) the
 * lead was dropped while the webhook still answered 200, so Meta never sent it
 * again. The id is now kept here and leads:retry-pending fetches it again until
 * it works (Meta keeps leads for 90 days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_leadgens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();
            $table->string('leadgen_id', 64)->unique();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_leadgens');
    }
};
