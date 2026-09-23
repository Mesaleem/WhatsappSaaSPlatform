<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — CRM Hardening Round 2, Limitation 2. Turns the
     * membership -> Contact tenant rule from an application convention
     * into a database guarantee.
     *
     * ROUND 1 DECIDED NOT TO ADD THIS COLUMN, and that decision is being
     * reversed on evidence rather than on taste. The reasoning then was
     * that contact_groups already owns the account, so denormalizing it
     * would be duplication for theoretical purity. What the round-2
     * investigation established is that WITHOUT this column no composite
     * foreign key is expressible at all — a composite key's columns must
     * live on the child row — so the choice is not "purity vs
     * duplication", it is "one denormalized column vs no database-level
     * tenant integrity on this table". Verified on MariaDB 10.11.14:
     *
     *   FOREIGN KEY (group_id, account_id)
     *     REFERENCES contact_groups (id, account_id) ON DELETE CASCADE
     *   FOREIGN KEY (contact_id, account_id)
     *     REFERENCES contacts (id, account_id) ON DELETE CASCADE
     *
     * with both keys in place:
     *   - a membership in Account A pointing at Account B's contact is
     *     refused (ERROR 1452);
     *   - a membership claiming an account that its own group does not
     *     belong to is
     *     also refused (ERROR 1452) — so the denormalized column cannot
     *     drift from the group that owns it;
     *   - deleting a group still cascades its members, unchanged;
     *   - deleting an account still cascades cleanly, with no constraint
     *     failure.
     *
     * WHY CASCADE ON THE CONTACT KEY and not SET NULL: SET NULL is
     * impossible for the same engine reason documented in the capture-link
     * migration (account_id is NOT NULL). CASCADE is behaviourally
     * unreachable in the product as it stands — CrmContactController
     * ::destroy() refuses to delete a contact that has any group
     * membership (409 CONTACT_HAS_DEPENDENTS), and Contact merge
     * reassigns memberships before removing the source — so the only
     * path that could fire it is account deletion, where the membership
     * is destroyed by the group cascade anyway. Disclosed in the report.
     *
     * NOT NULL, backfilled from the owning group. No row is created or
     * destroyed and no existing column is touched: phone_number and name
     * stay exactly where they are, so every dispatch path keeps working
     * byte-identically.
     *
     * FOREIGN KEYS ON MySQL/MariaDB ONLY — SQLite cannot add or drop one
     * through ALTER TABLE. The column, its backfill and its NOT NULL
     * constraint are identical on both, and the linker still derives the
     * account from the group in application code either way.
     */
    public function up(): void
    {
        $mysql = $this->onMySql();

        if ($mysql) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->unique(['id', 'account_id'], 'contact_groups_id_account_id_unique');
            });
        }

        Schema::table('contact_group_members', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->after('group_id');
        });

        // Backfill from the owning group. Correlated subquery so the one
        // statement runs on MySQL and SQLite alike. group_id is NOT NULL
        // with a cascading FK, so every row has exactly one owner and
        // none can be left null.
        DB::statement('UPDATE contact_group_members SET account_id = (SELECT g.account_id FROM contact_groups g WHERE g.id = contact_group_members.group_id) WHERE account_id IS NULL');

        Schema::table('contact_group_members', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable(false)->change();
        });

        if ($mysql) {
            Schema::table('contact_group_members', function (Blueprint $table) {
                // Replace the two single-column keys with composite,
                // tenant-proving ones.
                $table->dropForeign(['group_id']);
                $table->dropForeign(['contact_id']);

                $table->foreign(['group_id', 'account_id'], 'cgm_group_account_foreign')
                    ->references(['id', 'account_id'])->on('contact_groups')->cascadeOnDelete();

                $table->foreign(['contact_id', 'account_id'], 'cgm_contact_account_foreign')
                    ->references(['id', 'account_id'])->on('contacts')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $mysql = $this->onMySql();

        if ($mysql) {
            Schema::table('contact_group_members', function (Blueprint $table) {
                $table->dropForeign('cgm_group_account_foreign');
                $table->dropForeign('cgm_contact_account_foreign');

                $table->foreign('group_id')->references('id')->on('contact_groups')->cascadeOnDelete();
                $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            });
        }

        Schema::table('contact_group_members', function (Blueprint $table) {
            $table->dropColumn('account_id');
        });

        if ($mysql) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->dropUnique('contact_groups_id_account_id_unique');
            });
        }
    }

    private function onMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
