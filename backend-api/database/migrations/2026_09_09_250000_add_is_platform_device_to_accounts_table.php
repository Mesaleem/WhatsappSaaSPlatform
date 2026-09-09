<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Super Admin WhatsApp Device Integration (self-test device) — marks
     * the ONE reserved Account row that represents the Super Admin's own
     * WhatsApp test connection, not a real client. Account already has a
     * hard, non-nullable, UNIQUE-per-account whatsapp_sessions.account_id
     * FK (see that table's migration) — reusing a real Account row here
     * means the platform's own device rides the exact same
     * WhatsAppSession/qr-engine-service/QRScannerModal machinery every
     * tenant already uses, with zero changes to that FK, to
     * qr-engine-service's account_id handling, or to
     * WhatsAppStatusController's `exists:accounts,id` validation — all of
     * which a nullable-account_id "sentinel device" alternative would have
     * required touching (see WhatsAppController::adminIndex()'s docblock,
     * which had rejected that alternative for exactly this reason; a
     * reserved Account row sidesteps that entire risk).
     *
     * Account::class registers a global scope that excludes
     * is_platform_device = true from every ordinary query (client lists,
     * analytics counts, the Super Admin device-overview table, billing's
     * account dropdown) — see Account::booted(). Only
     * Account::platformDevice() reaches this row, via
     * withoutGlobalScope().
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_platform_device')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_platform_device');
        });
    }
};
