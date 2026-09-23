<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — CRM, Task 1. The platform's first CONTACT entity: one row
     * per person, per tenant.
     *
     * WHY A NEW TABLE, given Task 1 says "reuse the existing Contact
     * architecture and do not duplicate tables" — inspection found that
     * this repository has no Contact model and no contacts table at all.
     * The two person-shaped structures that do exist are both unusable as
     * the CRM's Contact, for reasons that are structural rather than
     * cosmetic:
     *
     *  - contact_group_members (the rows the UI labels "contacts"): a
     *    phone number INSIDE one broadcast list, not a person. It carries
     *    no account_id (its creating migration says so explicitly —
     *    tenant scoping goes through group_id -> contact_groups), so the
     *    "a lead in Account A can never reference a contact in Account B"
     *    rule could not be expressed as a database constraint at all. It
     *    is cascadeOnDelete on its group, so deleting a broadcast list
     *    would silently destroy CRM history. The same person in two lists
     *    is two rows, so it has no stable person identity. And the whole
     *    table sits behind the paid `contact_groups` addon module
     *    (module.guard:contact_groups in routes/api.php), which would
     *    make the CRM capability structurally unusable for any tenant
     *    entitled to CRM but not to that addon.
     *
     *  - leads (Meta Lead Ads / journey save_lead captures): account-owned
     *    and person-shaped, but it is an immutable capture EVENT, not a
     *    person — LeadController's own docblock says so. Its
     *    provider_lead_id is NOT NULL and globally unique, so every
     *    manually created contact would need a synthetic one, and the
     *    same person submitting two forms is two rows. Naming it the
     *    "Contact" of a table called crm_leads would also be a permanent
     *    semantic trap.
     *
     * So this table is additive, not duplicative: it models something
     * nothing in the schema models today. Nothing existing is modified —
     * contact_groups/contact_group_members and leads are untouched, and
     * no existing code path reads or writes this table. Reconciling the
     * two (importing group members as contacts, linking a captured lead
     * to its contact) belongs to a later CRM task, not here.
     *
     * COLUMN RATIONALE — deliberately only what identifies a person:
     *  - account_id: tenant ownership, the convention every tenant-scoped
     *    table in this schema follows (foreignId + cascadeOnDelete).
     *  - phone_number: the person's identity on this platform. A WhatsApp
     *    contact IS a phone number; every messaging path in this codebase
     *    keys off the PhoneNumberNormalizer digit form.
     *  - name: nullable display label, exactly as contact_group_members
     *    and leads.lead_name already model it (a captured contact often
     *    has no name).
     * No email column: no Task 1 acceptance criterion needs one, and the
     * brief forbids speculative fields. See the report's Known
     * Limitations — the first source integration that carries an email
     * (meta_ad) should add it then, with a real reason.
     *
     * unique(account_id, phone_number) is what makes this a PERSON table
     * rather than another capture log: one contact per number per tenant.
     * Deliberately per-account, not global — two unrelated tenants having
     * the same customer is normal, the same reasoning ContactGroup::
     * generateGroupCode() already applies to group_code.
     *
     * unique(id, account_id) exists purely so crm_leads can declare a
     * COMPOSITE foreign key against (id, account_id) — MySQL/MariaDB
     * requires an index on the referenced columns, and SQLite requires
     * them to be UNIQUE. That composite FK is what turns "a lead may not
     * reference another tenant's contact" from an application rule into a
     * database guarantee. It is redundant as a uniqueness claim (id is
     * already the primary key); it is not redundant as an index.
     *
     * No soft deletes: verified by inspection that SoftDeletes appears in
     * zero models and zero migrations in this repository. Hard delete is
     * the established convention for every comparable business entity.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'phone_number']);
            $table->unique(['id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
