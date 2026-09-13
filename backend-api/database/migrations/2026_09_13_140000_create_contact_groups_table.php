<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group Messaging — Step 1 (schema + module gating only; the actual
     * group-blast dispatch pathway is a later step, not built here).
     *
     * One row per named contact list, scoped to a tenant. is_default
     * marks the single group AccountController::store() auto-creates for
     * every new tenant (see that controller for the actual creation
     * call) — there is no DB-level constraint enforcing "exactly one
     * default per account" here; it's an application-level convention
     * only, same trust level this app already extends to similar
     * single-row-per-account invariants (e.g. Account::owner() picking
     * the oldest admin User rather than a DB-enforced uniqueness rule).
     */
    public function up(): void
    {
        Schema::create('contact_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['account_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_groups');
    }
};
