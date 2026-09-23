<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — CRM Hardening, Issue 8. Reconciles the broadcast-list
     * person-store with the universal CRM Contact — additively, in one
     * direction, changing nothing about how group membership already
     * works.
     *
     * NOTHING IS DELETED OR REPLACED. contact_group_members keeps its
     * own phone_number and name columns, its unique(group_id,
     * phone_number) index, its cascadeOnDelete on the group, and all
     * three of its existing `upsert()` writers
     * (ContactGroupController::addContacts,
     * NativeGroupCreationService::create/importExisting). Every group
     * dispatch path still reads phone_number off the membership row, so
     * broadcast behaviour is byte-identical whether contact_id is
     * populated or not.
     *
     * HOW ACCOUNT OWNERSHIP IS DERIVED — the brief asks this to be
     * settled before touching the schema. contact_group_members has no
     * account_id and its creating migration says so deliberately:
     * tenant scoping goes through group_id -> contact_groups.account_id,
     * and contact_groups.account_id is NOT NULL with a cascading FK to
     * accounts. So every membership row has exactly one, unambiguous
     * owning account, reachable by a single join. That is reliable
     * enough, and it is the derivation both the backfill and the runtime
     * linker use.
     *
     * AND THEREFORE NO account_id COLUMN IS ADDED. The brief permits one
     * only if necessary; it is not. Adding it would duplicate a fact
     * contact_groups already owns, create a second source of truth that
     * can drift from the group's own account, and require touching three
     * existing bulk-upsert call sites to keep it correct — for no
     * capability this migration does not already have.
     *
     * THE COST, DISCLOSED: because there is no account_id on this table,
     * the foreign key below cannot be the composite, tenant-proving form
     * Task 1 used on crm_leads. A membership row pointing at another
     * tenant's contact is prevented in application code
     * (ContactGroupContactLinker resolves the contact from the group's
     * OWN account and never accepts a caller-supplied contact_id) rather
     * than by the database. Nullable + nullOnDelete: a membership whose
     * contact is deleted stays a working broadcast recipient, it just
     * loses its CRM link.
     */
    public function up(): void
    {
        Schema::table('contact_group_members', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable()->after('group_id');

            $table->foreign('contact_id')
                ->references('id')
                ->on('contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_group_members', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });
    }
};
