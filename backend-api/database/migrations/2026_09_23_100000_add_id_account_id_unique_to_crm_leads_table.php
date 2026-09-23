<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — CRM Task 7 (Lead Tags). Makes (id, account_id) on crm_leads a
 * referenceable key, so crm_lead_tags can carry a COMPOSITE, tenant-proving
 * foreign key — the same pattern contacts already uses
 * (contacts_id_account_id_unique, referenced by
 * crm_leads_contact_account_foreign) and leads uses
 * (leads_id_account_id_unique, referenced by
 * crm_leads_capture_lead_account_foreign).
 *
 * `id` alone is already unique, so this index adds no new uniqueness; it
 * exists only because MySQL/MariaDB and SQLite both require the REFERENCED
 * column list of a foreign key to be covered by a unique index in that
 * exact order. It is not a speculative read index.
 *
 * Created on every driver: it is an index, not a foreign key, so SQLite's
 * "cannot ALTER a foreign key" limitation does not apply to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->unique(['id', 'account_id'], 'crm_leads_id_account_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropUnique('crm_leads_id_account_id_unique');
        });
    }
};
