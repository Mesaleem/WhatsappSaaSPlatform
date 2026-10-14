<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Website visitor analytics (owner, 2026-10-14) — who came to the public website, from where,
     * when, which pages they opened and how long they stayed. Super Admin only.
     *
     * site_visits: one row per browsing session (a visitor's tab; a new one after 30 idle minutes).
     * site_page_views: one row per page opened in that session, with the seconds the page was
     * actually visible. visitor_id is a random id the browser keeps, so a returning visitor is
     * recognised without any login.
     */
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->unique();
            $table->uuid('visitor_id')->index();
            $table->boolean('is_returning')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('device', 16)->nullable();
            $table->string('browser', 32)->nullable();
            $table->string('os', 32)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->string('source', 64)->index();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('landing_path', 255);
            $table->string('exit_path', 255)->nullable();
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('signed_up')->default(false);
            $table->timestamp('started_at')->index();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        Schema::create('site_page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_visit_id')->constrained('site_visits')->cascadeOnDelete();
            $table->uuid('view_id')->unique();
            $table->string('path', 255)->index();
            $table->string('title', 255)->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('entered_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_page_views');
        Schema::dropIfExists('site_visits');
    }
};
