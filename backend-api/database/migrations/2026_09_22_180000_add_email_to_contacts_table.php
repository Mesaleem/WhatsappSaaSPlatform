<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — CRM Hardening, Issue 3. Closes the limitation Task 2
     * disclosed: a CRM Contact could not hold an email address, even
     * though two of the five lead sources (meta_ad, journey) already
     * capture one into leads.lead_email.
     *
     * SHAPE FOLLOWS THE EXISTING CONVENTION, verified by inspection
     * rather than chosen: every email column in this schema is a plain
     * `string` with no explicit length —
     * users.email (unique), login_audit_logs.email (nullable),
     * mail_logs.recipient_email, leads.lead_email (nullable) — and every
     * validator is Laravel's own `email` rule (TeamController::store/
     * update, AccountController::store). Nothing in this codebase
     * lower-cases or otherwise normalizes an email before storing it, so
     * neither does the CRM: inventing a casing rule here would make
     * contacts.email behave unlike users.email for no stated reason.
     *
     * NULLABLE, and NOT unique. A contact captured from WhatsApp has a
     * phone number and frequently nothing else, so requiring an email
     * would make most contacts unstorable. Uniqueness is deliberately
     * NOT claimed: identity in this domain is (account_id,
     * phone_number) — that is the rule Task 1 established and the one
     * ContactResolver keys off — and two family members sharing a
     * mailbox is ordinary, not a data error.
     *
     * NO BACKFILL HERE. Per the hardening brief, existing
     * Meta/Journey lead emails are not swept into contacts by this
     * migration. The separate backfill migration in this batch fills an
     * email only where it is CREATING a contact from a capture lead, or
     * where the contact's email is blank — never overwriting one that
     * already exists. That is the same fill-if-blank rule
     * ContactResolver already applies to names.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
