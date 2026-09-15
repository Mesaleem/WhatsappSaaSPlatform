<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Developer API Platform for WhatsApp Group Creation & Unified
     * Messaging -- requirement 1's dual-factor "x-api-key + x-api-secret"
     * auth. Both new columns are NULLABLE, added to the EXISTING api_keys
     * table rather than a parallel one: every key already issued
     * (ApiKeyController::store(), ClientApiKeyController::regenerate())
     * keeps working, unchanged, against the pre-existing single-factor
     * `auth.apikey` middleware and every route already using it
     * (/v1/messages/send-payment-alert, /v1/messages/send-template,
     * /v1/send-message) -- those routes are NOT touched by this feature.
     *
     * secret_hash is null for every key created before this migration
     * (and for any created afterward via the old single-factor
     * ApiKeyController::store()/ClientApiKeyController::regenerate()
     * paths, which are deliberately left unchanged) -- such a key simply
     * cannot authenticate the NEW dual-factor endpoints
     * (/api/v1/whatsapp/groups/create, /api/v1/whatsapp/messages/send)
     * until its holder calls the new
     * POST /developer/api-keys/{id}/regenerate-secret endpoint
     * (ApiKeyController::regenerateSecret()) to mint one. This is a
     * disclosed, deliberate migration path, not an oversight: it avoids
     * silently revoking/rotating every existing key's primary secret
     * (which would break the routes above) just to backfill a new,
     * narrower-scoped credential.
     *
     * secret_hash is SHA-256 (ApiKey::hashSecret()), same hashing
     * convention and column width as key_hash on this same table -- the
     * plaintext secret is never persisted, only its digest.
     * secret_prefix mirrors key_prefix's purpose (a short, non-sensitive
     * slice the Developer Portal UI can display so a tenant can tell
     * which key's secret is which, without ever showing the full
     * secret again after creation).
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('secret_prefix', 32)->nullable()->after('key_hash');
            $table->string('secret_hash', 64)->nullable()->unique()->after('secret_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['secret_prefix', 'secret_hash']);
        });
    }
};
