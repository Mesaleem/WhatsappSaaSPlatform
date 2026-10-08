<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Task 6 — cooldown schema/primitive foundation only.
 *
 * Per-KEY (api_keys.id), never per-account and never on
 * api_key_bindings: api_key_id is the one identifier that survives a
 * transfer (a key keeps its id while its binding rows come and go), so
 * this is the correct row to anchor a cooldown to — not
 * Account (would wrongly affect every other key of the account) and
 * not api_key_bindings (a transfer replaces the binding row itself,
 * which would lose the cooldown at exactly the moment it needs to
 * persist).
 *
 * NULLABLE, and nothing in this task ever sets it to a non-null value:
 * this migration adds storage and ApiKeyBindingService gets a
 * matching setCooldown()/clearCooldown() write primitive plus
 * ApiKey::isInCooldown() to read it, but no enforcement, no
 * destroy()-triggered cooldown, and no transfer-triggered cooldown yet.
 * That wiring is explicitly Task 9's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->timestamp('cooldown_until')->nullable()->after('legacy_binding_grace_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('cooldown_until');
        });
    }
};
