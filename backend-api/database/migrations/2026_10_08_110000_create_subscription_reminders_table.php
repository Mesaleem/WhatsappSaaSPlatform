<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per reminder sent for one plan term: a client gets the "expiring in 7 days" and the "expired 7 days ago"
 * reminder once each per term. The unique key (account, kind, term end) stops a repeat when the daily job runs again,
 * and a renewal starts a new term, so the next term gets its own reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->timestamp('expires_at');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['account_id', 'kind', 'expires_at'], 'subscription_reminders_once_per_term');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_reminders');
    }
};
