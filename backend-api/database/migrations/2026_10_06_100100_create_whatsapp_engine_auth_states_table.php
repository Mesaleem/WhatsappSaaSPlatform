<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baileys ('qr' engine) credentials, one row per account and per key file.
 *
 * qr-engine-service used to keep these on its own disk (sessions/<id>/).
 * A container's filesystem is replaced on every deploy or pod recreation,
 * so every WhatsApp connection was lost with it. Storing them here makes the
 * database, not the Node process, the source of truth; the Node service reads
 * and writes them through /api/internal/whatsapp-auth.
 *
 * `value` holds the same JSON Baileys writes to disk, encrypted with APP_KEY
 * via the model's `encrypted` cast. It is longText because app-state and
 * signal keys can be large. Additive and reversible. Guarded like the sibling
 * migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_engine_auth_states')) {
            return;
        }

        Schema::create('whatsapp_engine_auth_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('name', 191);
            $table->longText('value');
            $table->timestamps();

            $table->unique(['account_id', 'name'], 'wa_engine_auth_account_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_engine_auth_states');
    }
};
