<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per WhatsApp number an account owns ("WhatsApp slot").
 *
 * The plan includes one number (is_included). Extra numbers are paid add-ons.
 * phone_number is UNIQUE across ALL accounts: WhatsApp allows one linked-device
 * login per phone number at a time, so the same number can never belong to two
 * tenants. The database enforces this even under concurrent requests.
 *
 * `locked_at` is set when the plan + add-ons are paid. From then until the plan
 * expires, the account cannot change its numbers or its default. Additive and
 * reversible, guarded like the sibling migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_numbers')) {
            return;
        }

        Schema::create('whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('phone_number', 20)->unique();
            $table->boolean('is_included')->default(false);
            $table->boolean('is_default')->default(false);
            // pending_payment | unlinked | linked | paused
            $table->string('status', 30)->default('pending_payment');
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_numbers');
    }
};
