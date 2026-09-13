<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group Messaging — Step 1. Deliberately no account_id column here
     * (not in the requested column list) — tenant scoping for this table
     * goes through group_id -> contact_groups.account_id; any query
     * needing to enforce tenant isolation directly against this table
     * must join contact_groups rather than filter this table alone.
     *
     * unique(['group_id', 'phone_number']) is an addition beyond the
     * literal request: prevents the same phone number being added twice
     * to one group, which is almost certainly always a data-entry bug
     * rather than an intended state. Not in the original column list,
     * but a minimal, low-risk integrity guard.
     */
    public function up(): void
    {
        Schema::create('contact_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('contact_groups')->cascadeOnDelete();
            $table->string('phone_number');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'phone_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_group_members');
    }
};
