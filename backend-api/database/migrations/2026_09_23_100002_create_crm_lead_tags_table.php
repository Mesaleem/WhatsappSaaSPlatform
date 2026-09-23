<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — CRM Task 7. Lead <-> tag assignments (many-to-many).
 *
 * TENANT SAFETY IS A SCHEMA PROPERTY, NOT ONLY AN APPLICATION CHECK.
 * Plain crm_lead_id -> crm_leads.id and crm_tag_id -> crm_tags.id foreign
 * keys would happily store Account A's lead against Account B's tag. So
 * the row carries account_id and BOTH foreign keys are composite:
 *
 *   (crm_lead_id, account_id) -> crm_leads(id, account_id)
 *   (crm_tag_id,  account_id) -> crm_tags(id, account_id)
 *
 * One account_id column has to match both parents, so a cross-tenant
 * assignment is physically unstorable. This is the pattern Tasks 1 and
 * Round 2 established (crm_leads_contact_account_foreign,
 * crm_leads_capture_lead_account_foreign).
 *
 * CASCADE on both, per the proven MariaDB 10.11 finding in
 * PROJECT_STATE.md §7: composite FK + CASCADE works and keeps
 * `DELETE FROM accounts` working; RESTRICT breaks it; SET NULL is
 * impossible with NOT NULL columns. Semantics:
 *   lead deleted  -> its assignments go (the lead is gone; nothing to tag)
 *   tag deleted   -> its assignments go (leads themselves untouched)
 *   account gone  -> both parents cascade, so do these rows
 *
 * Unlike the Round 2 capture-link FK (added by ALTER on an existing table
 * and therefore MySQL-only), these foreign keys are declared inside
 * CREATE TABLE, which SQLite does support — the same way crm_leads'
 * contact FK is declared. Both engines therefore enforce them.
 *
 * INDEXES
 *   PRIMARY (crm_lead_id, crm_tag_id)  the required uniqueness; also the
 *                                      lead -> tags lookup and the
 *                                      "lead has tag X" EXISTS probe used
 *                                      by tag filtering.
 *   (crm_tag_id, account_id)           tag -> leads and per-tag counts;
 *                                      also the tag FK's index.
 *   (crm_lead_id, account_id)          required by the lead FK (MariaDB
 *                                      needs an index whose leading
 *                                      columns are exactly the FK's; the
 *                                      primary key's second column is
 *                                      crm_tag_id). Named explicitly so it
 *                                      is not an anonymous auto-index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_lead_id');
            $table->unsignedBigInteger('crm_tag_id');
            $table->unsignedBigInteger('account_id');
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->primary(['crm_lead_id', 'crm_tag_id']);
            $table->index(['crm_tag_id', 'account_id'], 'crm_lead_tags_tag_account_index');
            $table->index(['crm_lead_id', 'account_id'], 'crm_lead_tags_lead_account_index');

            $table->foreign(['crm_lead_id', 'account_id'], 'crm_lead_tags_lead_account_foreign')
                ->references(['id', 'account_id'])
                ->on('crm_leads')
                ->cascadeOnDelete();

            $table->foreign(['crm_tag_id', 'account_id'], 'crm_lead_tags_tag_account_foreign')
                ->references(['id', 'account_id'])
                ->on('crm_tags')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_tags');
    }
};
