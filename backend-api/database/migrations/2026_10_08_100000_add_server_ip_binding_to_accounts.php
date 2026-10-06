<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server IP binding for the Developer API. The account's one authorized server IP lives on the account, not on
 * an API key, so the IP can be registered before a key exists and every key of the account is held to it.
 *
 * - authorized_server_ip: the single IPv4/IPv6 address allowed to send with this account's keys (null until set).
 * - ip_edit_count: how many times the IP was changed after the first registration. One free change is allowed
 *   14 days after the last IP save; any change after that needs a paid plan invoice.
 * - ip_registered_at: when the IP was first saved (the first save is free and does not count as an edit).
 * - last_ip_updated_at: when the IP was last saved; the 14-day wait and the paid-invoice check both read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('authorized_server_ip', 45)->nullable()->after('allowed_modules');
            $table->unsignedSmallInteger('ip_edit_count')->default(0)->after('authorized_server_ip');
            $table->timestamp('ip_registered_at')->nullable()->after('ip_edit_count');
            $table->timestamp('last_ip_updated_at')->nullable()->after('ip_registered_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['authorized_server_ip', 'ip_edit_count', 'ip_registered_at', 'last_ip_updated_at']);
        });
    }
};
