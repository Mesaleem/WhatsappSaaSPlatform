<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 Task 3 — social publishing foundation & scheduling.
 *
 * Reuses the existing `organic_posts` table (Phase 1 organic publishing:
 * account, social account, provider, platform, caption, media, status,
 * external_post_id, error_message, published_at) as the ONE publishing
 * record for manual and scheduled posts, and adds only what scheduling,
 * safe claiming and idempotency need:
 *
 *   scheduled_at         when a scheduled post is due (null = publish now)
 *   origin               'manual' | 'scheduled' (informational; never authorization)
 *   idempotency_key      client key, unique per account (NULLs allowed)
 *   created_by_user_id   who created it (nullOnDelete)
 *   attempts             provider attempts made
 *   next_attempt_at      back-off after a transient provider failure
 *   claim_token/claimed_at  the single worker (or request) executing it
 *   provider_called_at   set just before the provider call of the current
 *                        claim: a stale claim WITH it is "outcome unknown"
 *                        (never auto re-sent), WITHOUT it nothing was sent
 *   failure_code         machine-readable failure (safe, no provider payload)
 *   cancelled_at         set when cancelled
 *   metadata             safe provider data (e.g. Instagram container id, Graph error code)
 *
 * Status values (string column, no enum change): scheduled, publishing,
 * pending (provider still processing — existing Instagram video meaning),
 * published, failed, reconnect_required, cancelled.
 *
 * Additive and nullable/defaulted: existing rows keep working as manual
 * posts. Indexes serve the scheduler scan and per-account lists. (No
 * (social_account_id, status) index: on MySQL it would silently replace the
 * social_account_id foreign key's own index and then block rollback.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organic_posts', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->string('origin', 16)->default('manual')->after('scheduled_at');
            $table->string('idempotency_key', 100)->nullable()->after('origin');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('idempotency_key');
            $table->unsignedSmallInteger('attempts')->default(0)->after('created_by_user_id');
            $table->timestamp('next_attempt_at')->nullable()->after('attempts');
            $table->string('claim_token', 64)->nullable()->after('next_attempt_at');
            $table->timestamp('claimed_at')->nullable()->after('claim_token');
            $table->timestamp('provider_called_at')->nullable()->after('claimed_at');
            $table->string('failure_code', 64)->nullable()->after('error_message');
            $table->timestamp('cancelled_at')->nullable()->after('published_at');
            $table->json('metadata')->nullable()->after('cancelled_at');

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['account_id', 'idempotency_key'], 'organic_posts_account_idempotency_unique');
            $table->index(['account_id', 'status'], 'organic_posts_account_status_index');
            $table->index(['status', 'scheduled_at'], 'organic_posts_status_scheduled_index');
        });
    }

    public function down(): void
    {
        Schema::table('organic_posts', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropUnique('organic_posts_account_idempotency_unique');
            $table->dropIndex('organic_posts_account_status_index');
            $table->dropIndex('organic_posts_status_scheduled_index');
            $table->dropColumn([
                'scheduled_at', 'origin', 'idempotency_key', 'created_by_user_id', 'attempts', 'next_attempt_at',
                'claim_token', 'claimed_at', 'provider_called_at', 'failure_code', 'cancelled_at', 'metadata',
            ]);
        });
    }
};
