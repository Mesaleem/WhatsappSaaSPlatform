<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The plaintext key ("wasaas_live_...") is NEVER stored — only its
     * SHA-256 hash (key_hash), looked up by AuthenticateApiKey on every
     * external API request. key_prefix stores a short, non-sensitive
     * slice of the plaintext (e.g. "wasaas_live_a1b2c3d4") purely so the
     * key-management UI can show tenants which key is which without ever
     * persisting anything that could reconstruct the secret — this column
     * is a disclosed addition beyond the spec's literal column list, the
     * same way GitHub/Stripe show a key's prefix in their own dashboards.
     */
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_prefix', 32);
            $table->string('key_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
