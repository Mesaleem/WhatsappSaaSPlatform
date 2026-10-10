<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Task 15 — "Manage All APIs": a named, reusable server-side API
 * connection (base URL + headers + query parameters) that an `api` node
 * can reference by id instead of re-entering its endpoint and
 * credentials in every node that calls it.
 *
 * ACCOUNT-SCOPED, NOT PLATFORM-WIDE. ConnexxaIQ's own "Manage All APIs"
 * screen (the UAT finding this task is based on) is a tenant's own list
 * of API configurations; this is tenant-scoped too, by construction
 * (`account_id`), never a Super-Admin global list — the headers/query
 * fields carry the SAME tenant credentials JourneySecrets already
 * protects on an `api` node, and a platform-wide list would let one
 * tenant's journey reference another tenant's credentials.
 *
 * SECRET STORAGE mirrors `JourneySecrets`'s own convention exactly (see
 * `JourneyApiConnectionSecrets`): a credential-named pair's value is
 * stored as `JourneySecrets::PREFIX . Crypt::encryptString(...)`, so it
 * is read with the SAME `JourneySecrets::reveal()`/`isEncrypted()` any
 * future runtime consumer already knows how to use — no second secret
 * format introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_api_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('base_url', 2048)->nullable();
            /** list<{key, value}> — only credential-named entries are encrypted (see JourneyApiConnectionSecrets). */
            $table->json('headers')->nullable();
            $table->json('query')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_api_connections');
    }
};
