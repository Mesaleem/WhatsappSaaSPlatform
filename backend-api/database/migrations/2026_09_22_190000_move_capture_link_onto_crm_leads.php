<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — CRM Hardening Round 2, Limitation 1. Replaces the
     * single-column, application-enforced capture link with a COMPOSITE,
     * database-enforced one by putting the link on the other side.
     *
     * WHY THE PREVIOUS SHAPE COULD NOT BE FIXED IN PLACE — verified
     * experimentally on MariaDB 10.11.14, not assumed:
     *
     *   CREATE TABLE leads (... account_id BIGINT NOT NULL,
     *     crm_lead_id BIGINT NULL,
     *     FOREIGN KEY (crm_lead_id, account_id)
     *       REFERENCES crm_leads (id, account_id) ON DELETE SET NULL)
     *   -> ERROR 1005 (HY000) errno: 150 "Foreign key constraint is
     *      incorrectly formed"
     *
     * because ON DELETE SET NULL requires every child column to be
     * nullable and leads.account_id is NOT NULL. The two workarounds
     * were also tested and both rejected on evidence:
     *   - a nullable (crm_lead_id, crm_account_id) pair with SET NULL
     *     creates fine, but the CHECK constraint needed to tie
     *     crm_account_id back to account_id is refused:
     *     ERROR 1901 "Function or expression 'crm_account_id' cannot be
     *     used in the CHECK clause" — MariaDB forbids a CHECK over a
     *     column that ON DELETE SET NULL writes to. So that shape buys a
     *     column and still leaves a gap.
     *   - the same composite FK with ON DELETE RESTRICT creates fine and
     *     does refuse cross-tenant links (ERROR 1452), but then
     *     DELETE FROM accounts fails with ERROR 1451 while any link is
     *     live — it would break account deletion outright.
     *
     * THE SHAPE THAT WORKS, and was verified end to end:
     *   crm_leads.capture_lead_id NULL,
     *   FOREIGN KEY (capture_lead_id, account_id)
     *     REFERENCES leads (id, account_id) ON DELETE CASCADE
     * crm_leads.account_id is NOT NULL, and CASCADE places no nullability
     * requirement on the child, so the composite key is accepted. Proven
     * behaviour on MariaDB 10.11.14:
     *   - a CRM lead in Account A cannot reference a capture row in
     *     Account B (ERROR 1452) — the tenant rule is now a DATABASE
     *     guarantee, not an application convention;
     *   - deleting a CRM lead leaves the capture row untouched (the FK
     *     points the other way now), which is the semantics Issue 9
     *     requires and the whole reason SET NULL was wanted;
     *   - deleting a Contact removes its CRM leads and still leaves the
     *     capture rows intact;
     *   - deleting an Account cascades cleanly through all four tables
     *     with no constraint failure.
     * The only new coupling is that deleting a capture row would now
     * delete its CRM lead. Nothing in this codebase deletes a capture
     * row — LeadController is read-only and has no destroy — so this is
     * unreachable today, and it is the correct direction anyway: the
     * capture is the origin of the opportunity.
     *
     * unique(capture_lead_id) states the 1:1 rule the linker already
     * enforced in code: one capture submission produces at most one CRM
     * opportunity.
     *
     * FOREIGN KEYS ARE APPLIED ON MySQL/MariaDB ONLY. SQLite cannot add
     * or drop a foreign key through ALTER TABLE, and the brief makes
     * MySQL/MariaDB authoritative. On SQLite the columns, indexes and
     * backfill are identical and CrmLead's model guard still refuses a
     * cross-tenant link, so the test suite exercises the same behaviour
     * either way; the database-level proof is asserted against MySQL.
     */
    public function up(): void
    {
        $mysql = $this->onMySql();

        if ($mysql) {
            // Referenced side of the composite key. leads.id is already
            // the primary key; this index exists solely so the FK below
            // has something to point at.
            Schema::table('leads', function (Blueprint $table) {
                $table->unique(['id', 'account_id'], 'leads_id_account_id_unique');
            });
        }

        Schema::table('crm_leads', function (Blueprint $table) use ($mysql) {
            $table->unsignedBigInteger('capture_lead_id')->nullable()->after('contact_id');
            $table->unique('capture_lead_id', 'crm_leads_capture_lead_id_unique');

            if ($mysql) {
                $table->foreign(['capture_lead_id', 'account_id'], 'crm_leads_capture_lead_account_foreign')
                    ->references(['id', 'account_id'])
                    ->on('leads')
                    ->cascadeOnDelete();
            }
        });

        // Carry every existing link across. Correlated subquery rather
        // than UPDATE ... JOIN so the same statement runs on MySQL and
        // SQLite. No row is created or destroyed.
        if (Schema::hasColumn('leads', 'crm_lead_id')) {
            DB::statement('UPDATE crm_leads SET capture_lead_id = (SELECT MIN(l.id) FROM leads l WHERE l.crm_lead_id = crm_leads.id) WHERE EXISTS (SELECT 1 FROM leads l2 WHERE l2.crm_lead_id = crm_leads.id)');

            Schema::table('leads', function (Blueprint $table) use ($mysql) {
                if ($mysql) {
                    $table->dropForeign(['crm_lead_id']);
                }

                $table->dropColumn('crm_lead_id');
            });
        }
    }

    /**
     * Restores the previous single-column shape and the links it held.
     * No CRM lead, contact or capture row is deleted in either
     * direction — this migration only ever moves where a link is
     * recorded.
     */
    public function down(): void
    {
        $mysql = $this->onMySql();

        Schema::table('leads', function (Blueprint $table) use ($mysql) {
            $table->unsignedBigInteger('crm_lead_id')->nullable()->after('social_account_id');

            if ($mysql) {
                $table->foreign('crm_lead_id')->references('id')->on('crm_leads')->nullOnDelete();
            }
        });

        DB::statement('UPDATE leads SET crm_lead_id = (SELECT MIN(c.id) FROM crm_leads c WHERE c.capture_lead_id = leads.id) WHERE EXISTS (SELECT 1 FROM crm_leads c2 WHERE c2.capture_lead_id = leads.id)');

        Schema::table('crm_leads', function (Blueprint $table) use ($mysql) {
            if ($mysql) {
                $table->dropForeign('crm_leads_capture_lead_account_foreign');
            }

            $table->dropUnique('crm_leads_capture_lead_id_unique');
            $table->dropColumn('capture_lead_id');
        });

        if ($mysql) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropUnique('leads_id_account_id_unique');
            });
        }
    }

    private function onMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
