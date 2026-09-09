<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic System & Mail Configuration — Super Admin SMTP setup, kept
     * in the database (not .env/config/mail.php) so it can be changed at
     * runtime from the UI. Single-row table, same "one settled config"
     * shape as payment_gateway_settings but with no natural unique key
     * to key off (there's only ever one mail configuration for the whole
     * platform) — enforced at the application layer
     * (MailSettingsController always operates on MailSetting::first()).
     */
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            // Mirrors Laravel's own mailer driver names ('smtp' is the
            // only one this UI exposes — see MailSettingsController).
            $table->string('mailer')->default('smtp');
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username')->nullable();
            // TEXT + encrypted-cast, same pattern as
            // PaymentGatewaySetting's *_key_secret columns.
            $table->text('password')->nullable();
            $table->string('encryption')->nullable(); // 'tls' | 'ssl' | null
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
