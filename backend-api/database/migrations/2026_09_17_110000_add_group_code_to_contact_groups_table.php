<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Developer API: `group_code` for contact_groups.
 *
 * Lets an external API client reference a group by a stable,
 * human-readable string (e.g. VIP_CUSTOMERS) on POST /api/v1/send-message
 * (recipient_type: "group") instead of the raw DB auto-increment `id` --
 * the same problem, and the same fix, add_template_code_to_message_templates_table
 * already solved for templates (see that migration's docblock).
 *
 * Deliberately UNIQUE PER ACCOUNT (account_id, group_code), not globally
 * unique like template_code: a ContactGroup always belongs to exactly one
 * tenant -- there is no "global group" concept the way a template can
 * have a null account_id -- so two unrelated tenants both naming a group
 * "VIP" is normal and must not collide. See ContactGroup::generateGroupCode()'s
 * own docblock for the same reasoning.
 *
 * Idempotent / production-safe, matching every other migration in this
 * project: guarded by Schema::hasColumn()/information_schema existence
 * checks so it can run safely no matter how far it has partially applied
 * before, and down() is a real, non-inert reversal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contact_groups', 'group_code')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->string('group_code', 100)->nullable()->after('id');
            });
        }

        $this->backfillGroupCodes();

        if (! $this->indexExists('contact_groups', 'contact_groups_account_id_group_code_unique')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->unique(['account_id', 'group_code']);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('contact_groups', 'contact_groups_account_id_group_code_unique')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->dropUnique('contact_groups_account_id_group_code_unique');
            });
        }

        if (Schema::hasColumn('contact_groups', 'group_code')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->dropColumn('group_code');
            });
        }
    }

    /**
     * Every existing row gets a default code before the unique index is
     * added: an UPPER_SNAKE_CASE slug of its name, falling back to
     * GRP_{id} when the name produces an empty slug, disambiguated
     * PER ACCOUNT (not globally -- see this migration's own docblock)
     * against every other code (old or freshly generated) for the same
     * account_id with a numeric suffix, so the unique index below never
     * fails to apply.
     */
    private function backfillGroupCodes(): void
    {
        $rows = DB::table('contact_groups')
            ->where(function ($q) {
                $q->whereNull('group_code')->orWhere('group_code', '');
            })
            ->select('id', 'account_id', 'name')
            ->orderBy('account_id')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $existingByAccount = [];

        foreach (
            DB::table('contact_groups')
                ->whereNotNull('group_code')
                ->where('group_code', '!=', '')
                ->select('account_id', 'group_code')
                ->get() as $row
        ) {
            $existingByAccount[$row->account_id][strtoupper($row->group_code)] = true;
        }

        foreach ($rows as $row) {
            $base = strtoupper((string) Str::of((string) $row->name)
                ->ascii()
                ->replaceMatches('/[^A-Za-z0-9]+/', '_')
                ->trim('_'));

            if ($base === '') {
                $base = 'GRP_'.$row->id;
            }

            $code = $base;
            $suffix = 2;
            while (isset($existingByAccount[$row->account_id][$code])) {
                $code = $base.'_'.$suffix;
                $suffix++;
            }

            $existingByAccount[$row->account_id][$code] = true;

            DB::table('contact_groups')->where('id', $row->id)->update(['group_code' => $code]);
        }
    }

    /**
     * Driver-aware existence check — the original MySQL-only
     * information_schema.statistics query left this migration (and its
     * sibling add_group_code_to_contact_groups_table.php) unrunnable
     * under sqlite, which is this project's own configured test/local
     * driver (see phpunit.xml) — every RefreshDatabase-based Feature
     * test failed with "no such table: information_schema.statistics"
     * before this fix, regardless of what that test actually exercised.
     * Fixed at the root (this shared existence check), not worked
     * around per-test. sqlite's PRAGMA does not accept a bound
     * parameter for the table name, so $table is interpolated directly
     * -- safe here since every call site in this file passes a fixed
     * string literal, never external input.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach (DB::select("PRAGMA index_list(\"{$table}\")") as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $result = DB::selectOne(
            'SELECT COUNT(1) AS cnt FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName]
        );

        return $result && (int) $result->cnt > 0;
    }
};
