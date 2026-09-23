<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — CRM Hardening, Issue 4. Links an existing capture lead
     * to the CRM, additively, without touching anything the Meta Ads /
     * Journey / CTWA flows already do.
     *
     * THE TABLE IS NOT RENAMED, NOT REPLACED AND NOT MIGRATED AWAY.
     * `leads` stays exactly what it is — one immutable row per captured
     * submission, written by MetaLeadWebhookHandler,
     * WhatsAppJourneyEngine::upsertLead() and
     * MetaWebhookController::captureCtwaLead(), read by LeadController
     * and SocialInboxController. This migration adds ONE nullable column
     * to it and nothing else.
     *
     * DIRECTION: leads.crm_lead_id -> crm_leads.id. One column answers
     * the whole chain the brief asks for, because a CrmLead already
     * carries contact_id:
     *     capture lead -> crm_lead -> contact
     * A second contact_id column on `leads` would be derivable from the
     * first and would be one more thing that can disagree with itself.
     *
     * WHY THIS FOREIGN KEY IS SINGLE-COLUMN, unlike the composite
     * (contact_id, account_id) key Task 1 put on crm_leads — this is a
     * real trade-off, not an oversight:
     *  - The tenant-safe form would be a composite FK on (crm_lead_id,
     *    account_id) referencing crm_leads(id, account_id).
     *  - That key needs ON DELETE SET NULL, because deleting a CRM lead
     *    must leave the capture record intact (it is Meta's/the
     *    journey's historical record, and LeadController exposes it).
     *  - MySQL/MariaDB refuse ON DELETE SET NULL when any child column
     *    is NOT NULL, and leads.account_id is NOT NULL. So the composite
     *    form can only be CASCADE (which would destroy capture history
     *    whenever a CRM lead is deleted) or RESTRICT (which would block
     *    CRM lead deletion, and risks colliding with the existing
     *    account-deletion cascade paths into both tables).
     *  - Neither is acceptable, so the tenant match is enforced in the
     *    Lead model instead (see Lead::booted()), which refuses to store
     *    a crm_lead_id belonging to another account with the same
     *    opaque message the CRM uses everywhere else. Disclosed in the
     *    hardening report's Known Limitations.
     *
     * SET NULL is also what makes DELETE /api/crm/leads/{id} safe: the
     * capture row survives its CRM lead and simply becomes unlinked
     * again, exactly as it was before this migration ran.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_lead_id')->nullable()->after('social_account_id');

            /*
             * [Round 2 amendment] MySQL/MariaDB only. SQLite can neither
             * add nor DROP a foreign key through ALTER TABLE, and
             * 2026_09_22_190000 retires this column entirely — so on
             * SQLite creating the key here would make that later
             * migration impossible ("unknown column in foreign key
             * definition" on the drop). MySQL is authoritative for this
             * project and keeps the constraint for the window in which
             * the column exists.
             */
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->foreign('crm_lead_id')
                    ->references('id')
                    ->on('crm_leads')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->dropForeign(['crm_lead_id']);
            }

            $table->dropColumn('crm_lead_id');
        });
    }
};
