<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WhatsApp number a 'qr' engine account is linked to, shown on the WhatsApp
 * Setup page so the owner can see which phone is connected. Reported by
 * qr-engine-service on connection open and cleared on disconnect. Digits only,
 * country code first, no '+'. Additive and reversible, guarded like the sibling
 * migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_sessions', 'connected_phone_number')) {
            Schema::table('whatsapp_sessions', function (Blueprint $table) {
                $table->string('connected_phone_number', 20)->nullable()->after('last_connected_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('whatsapp_sessions', 'connected_phone_number')) {
            Schema::table('whatsapp_sessions', function (Blueprint $table) {
                $table->dropColumn('connected_phone_number');
            });
        }
    }
};
