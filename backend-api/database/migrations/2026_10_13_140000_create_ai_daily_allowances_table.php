<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free daily AI credits (owner rule, 2026-10-13): each client / agent account gets N
 * free AI credits a day, set by the Super Admin. One row per account per day; a
 * day's unused credits are never carried over (a new day is a new row). See
 * App\Services\Ai\Billing\AiDailyAllowance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_daily_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('allowance')->default(0);
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_daily_allowances');
    }
};
