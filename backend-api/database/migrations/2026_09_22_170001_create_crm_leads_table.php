<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — CRM, Task 1. One CRM Lead = one business opportunity
     * attached to one Contact. Deliberately NOT the same thing as the
     * existing `leads` table, which is an immutable Meta Lead Ads /
     * journey capture record (see LeadController's docblock); that table
     * is untouched by this phase, and the distinct `crm_` prefix keeps
     * the two unambiguous in queries, logs and migrations forever.
     *
     * COLUMN RATIONALE — every column traces to a Task 1 requirement:
     *  - id: unique internal ID. $table->id() bigint auto-increment, the
     *    convention every table in this schema uses (no UUIDs anywhere).
     *  - account_id: tenant ownership. foreignId + cascadeOnDelete, same
     *    as every other tenant-scoped table.
     *  - contact_id: the contact relationship. Declared as a bare
     *    unsignedBigInteger rather than foreignId()->constrained()
     *    because its foreign key is COMPOSITE (see below) — a second
     *    single-column FK to contacts.id would add nothing the composite
     *    one does not already enforce.
     *  - status: lead status. A string column plus a PHP constant
     *    whitelist (CrmLead::STATUSES) rather than a DB enum — the
     *    established convention here (ContactGroup::GROUP_TYPES,
     *    subscriptions.status, accounts.account_type are all strings with
     *    constants). That is what "extensible representation" means in
     *    this codebase: adding a status is a one-line constant change, not
     *    an ALTER TABLE that locks the table on MySQL.
     *  - source: lead source, same representation, same reasoning.
     *  - assigned_user_id: assignment, reusing the EXISTING account-member
     *    architecture (users.account_id -> accounts, spatie roles on top).
     *    No second user/employee table. Nullable because a newly created
     *    lead has no owner yet; nullOnDelete so removing a team member
     *    unassigns their leads instead of deleting them.
     *  - converted_at / not_converted_at: the two terminal outcomes need
     *    their own timestamps because updated_at moves on ANY edit and so
     *    can never answer "when was this decided". Both nullable; exactly
     *    one is set, matching the status.
     *  - not_converted_reason: nullable free-text outcome reason. This is
     *    the "reason structure if justified by the existing architecture"
     *    the brief allows, and it IS justified: this schema already stores
     *    why-a-terminal-state-happened as a nullable string in exactly
     *    this shape (leads.tenant_notify_error, leads.lead_welcome_error,
     *    contact_groups.sync_error). A not_converted status with no
     *    recorded reason is unactionable. No matching converted_reason:
     *    nothing asked for one and "why did this succeed" has no
     *    established precedent here.
     *  - timestamps(): created_at / updated_at, required by the brief and
     *    universal in this schema.
     * No pipeline stage, value, currency, tag, score, next-action or
     * close-date column — all explicitly out of scope.
     *
     * TENANT ISOLATION IS A DATABASE CONSTRAINT, NOT A CONVENTION.
     * The composite foreign key (contact_id, account_id) -> contacts
     * (id, account_id) makes it physically impossible to store a lead
     * whose contact belongs to a different account, on both MariaDB and
     * SQLite, even if every line of application code were bypassed. A
     * plain contact_id FK could not express this. cascadeOnDelete: a
     * contact's opportunities die with the contact.
     * Assignment isolation is enforced in the model instead (see
     * CrmLead::booted) rather than by a second composite FK, because that
     * would require adding a unique(id, account_id) index to the core
     * `users` table, and users.account_id is nullable (super_admins have
     * none) — altering users for this is a larger change than Task 1
     * needs. Disclosed in the report's Known Limitations.
     *
     * INDEXES — only where a query already exists or is the column's sole
     * purpose:
     *  - (account_id, status): the CRM's primary list query is "this
     *    tenant's leads in this status" (New / Contacted / Converted /
     *    Not Converted are the product's four views).
     *  - (account_id, assigned_user_id): "leads assigned to this member"
     *    is the only query assigned_user_id exists to serve.
     *  - (account_id, created_at): every list endpoint in this codebase
     *    orders newest-first within an account (->latest(), see
     *    LeadController::index and AccountController::index).
     * No index on source: nothing filters or aggregates by it in this
     * phase (analytics is explicitly out of scope) and it is a 5-value
     * low-cardinality column. It can be added when a query needs it.
     * No standalone contact_id index: the composite FK's own index is
     * contact_id-leading, so "leads for this contact" is already covered.
     *
     * No soft deletes — zero SoftDeletes usage exists anywhere in this
     * repository; hard delete is the convention.
     */
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');

            $table->string('status')->default('new');
            $table->string('source')->default('manual');

            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('converted_at')->nullable();
            $table->timestamp('not_converted_at')->nullable();
            $table->string('not_converted_reason')->nullable();

            $table->timestamps();

            // The tenant-isolation guarantee. Named explicitly so the
            // constraint is identifiable in MariaDB error output and in
            // any future migration that needs to drop it.
            $table->foreign(['contact_id', 'account_id'], 'crm_leads_contact_account_foreign')
                ->references(['id', 'account_id'])
                ->on('contacts')
                ->cascadeOnDelete();

            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'assigned_user_id']);
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
};
