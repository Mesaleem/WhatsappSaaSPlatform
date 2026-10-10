<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Meta Channel Creation (per-tenant Meta App model, confirmed
 * with the user over the shared-platform-App alternative). Each tenant
 * creates their own Facebook App for WhatsApp Embedded Signup and pastes
 * its App ID / App Secret / Embedded-Signup Config ID here before they
 * can use the "Connect with Facebook" flow. Additive and reversible,
 * guarded like the sibling migrations (add_connected_phone_number_to_*,
 * add_meta_config_to_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_sessions', 'meta_app_id')) {
                $table->string('meta_app_id')->nullable()->after('meta_waba_id');
            }
            if (! Schema::hasColumn('whatsapp_sessions', 'meta_app_secret')) {
                // encrypted cast on the model, same treatment as meta_access_token.
                $table->text('meta_app_secret')->nullable()->after('meta_app_id');
            }
            if (! Schema::hasColumn('whatsapp_sessions', 'meta_config_id')) {
                $table->string('meta_config_id')->nullable()->after('meta_app_secret');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            foreach (['meta_app_id', 'meta_app_secret', 'meta_config_id'] as $column) {
                if (Schema::hasColumn('whatsapp_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
