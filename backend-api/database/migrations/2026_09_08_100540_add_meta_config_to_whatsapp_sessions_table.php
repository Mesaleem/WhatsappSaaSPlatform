<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Meta Cloud API credentials, added to the same whatsapp_sessions row
     * used by the QR engine (Module 4) rather than a separate table — one
     * account has exactly one active engine (qr xor meta) at a time, per
     * Account.currentSubscription.engine_type, so a single row per account
     * is the correct shape either way.
     */
    public function up(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->string('meta_phone_number_id')->nullable()->after('account_id');
            $table->string('meta_waba_id')->nullable()->after('meta_phone_number_id');
            // 'text' — the encrypted ciphertext (Eloquent 'encrypted' cast) is
            // far longer than the plaintext token; a 'string' column would
            // silently truncate it.
            $table->text('meta_access_token')->nullable()->after('meta_waba_id');
            // Not secret — this is the value the admin also pastes into
            // Meta's App Dashboard webhook config, so it's fine to return to
            // the frontend (see MetaConfigController::store).
            $table->string('meta_webhook_verify_token')->nullable()->unique()->after('meta_access_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'meta_phone_number_id',
                'meta_waba_id',
                'meta_access_token',
                'meta_webhook_verify_token',
            ]);
        });
    }
};
