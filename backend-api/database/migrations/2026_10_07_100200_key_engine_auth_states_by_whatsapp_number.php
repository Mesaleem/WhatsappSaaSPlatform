<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: a WhatsApp login belongs to a NUMBER SLOT (whatsapp_numbers.id), not
 * to the account. An account can own several numbers, each with its own login.
 *
 * whatsapp_engine_auth_states.account_id (FK to accounts) becomes
 * whatsapp_number_id (FK to whatsapp_numbers). A slot id is not an account id,
 * so the foreign key cannot be kept, and SQLite cannot alter foreign keys in
 * place. The table is therefore rebuilt.
 *
 * Refused while the table holds rows: it would drop stored logins. Clear them
 * (or re-link) first. At the time of writing it is empty (the login was cleared
 * by the owner's disconnect), so this runs as a rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_engine_auth_states')) {
            $this->createNew();

            return;
        }

        if (! Schema::hasColumn('whatsapp_engine_auth_states', 'account_id')) {
            return; // already keyed by number slot
        }

        if (DB::table('whatsapp_engine_auth_states')->exists()) {
            throw new RuntimeException(
                'whatsapp_engine_auth_states still holds stored WhatsApp logins; refusing to drop them. '
                .'Disconnect every account first, then run this migration again.'
            );
        }

        Schema::drop('whatsapp_engine_auth_states');
        $this->createNew();
    }

    public function down(): void
    {
        if (! Schema::hasTable('whatsapp_engine_auth_states')) {
            return;
        }

        if (DB::table('whatsapp_engine_auth_states')->exists()) {
            throw new RuntimeException('whatsapp_engine_auth_states still holds stored WhatsApp logins; refusing to roll back.');
        }

        Schema::drop('whatsapp_engine_auth_states');

        Schema::create('whatsapp_engine_auth_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('name', 191);
            $table->longText('value');
            $table->timestamps();

            $table->unique(['account_id', 'name'], 'wa_engine_auth_account_name_unique');
        });
    }

    private function createNew(): void
    {
        Schema::create('whatsapp_engine_auth_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_number_id')->constrained('whatsapp_numbers')->cascadeOnDelete();
            $table->string('name', 191);
            $table->longText('value');
            $table->timestamps();

            $table->unique(['whatsapp_number_id', 'name'], 'wa_engine_auth_number_name_unique');
        });
    }
};
