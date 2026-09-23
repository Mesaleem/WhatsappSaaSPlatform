<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Developer API: unique `template_code` for message_templates.
 *
 * Lets an external API client reference a template by a stable,
 * human-readable string (e.g. PAYMENT_RECEIPT_V1) instead of the raw
 * DB auto-increment `id` -- the id forced clients to fetch/guess
 * another tenant's primary key and produced confusing cross-tenant
 * "invalid id" errors even though the lookup was already account-scoped.
 *
 * Idempotent / production-safe, matching every other migration in this
 * project (see PROJECT_ARCHITECTURE_DATABASE_BLUEPRINT.md): guarded by
 * Schema::hasColumn()/information_schema existence checks so it can run
 * safely no matter how far it has partially applied before, and down()
 * is a real, non-inert reversal. Dated 2026-09-17 (after the
 * 2026_09_16_999999 baseline-sync sentinel) so it always runs after
 * that catch-up migration in filename order, though neither actually
 * depends on the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_templates', 'template_code')) {
            Schema::table('message_templates', function (Blueprint $table) {
                $table->string('template_code', 100)->nullable()->after('id');
            });
        }

        $this->backfillTemplateCodes();

        if (! $this->indexExists('message_templates', 'message_templates_template_code_unique')) {
            Schema::table('message_templates', function (Blueprint $table) {
                $table->unique('template_code');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('message_templates', 'message_templates_template_code_unique')) {
            Schema::table('message_templates', function (Blueprint $table) {
                $table->dropUnique('message_templates_template_code_unique');
            });
        }

        if (Schema::hasColumn('message_templates', 'template_code')) {
            Schema::table('message_templates', function (Blueprint $table) {
                $table->dropColumn('template_code');
            });
        }
    }

    /**
     * Every existing row gets a default code before the unique index is
     * added: an UPPER_SNAKE_CASE slug of its title, falling back to
     * TMP_{id} when the title produces an empty slug, disambiguated
     * against every other code (old or freshly generated) with a
     * numeric suffix so the unique index below never fails to apply.
     */
    private function backfillTemplateCodes(): void
    {
        $rows = DB::table('message_templates')
            ->where(function ($q) {
                $q->whereNull('template_code')->orWhere('template_code', '');
            })
            ->select('id', 'title')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $existing = DB::table('message_templates')
            ->whereNotNull('template_code')
            ->where('template_code', '!=', '')
            ->pluck('template_code')
            ->map(fn ($code) => strtoupper($code))
            ->flip()
            ->toArray();

        foreach ($rows as $row) {
            $base = strtoupper((string) Str::of((string) $row->title)
                ->ascii()
                ->replaceMatches('/[^A-Za-z0-9]+/', '_')
                ->trim('_'));

            if ($base === '') {
                $base = 'TMP_'.$row->id;
            }

            $code = $base;
            $suffix = 2;
            while (isset($existing[$code])) {
                $code = $base.'_'.$suffix;
                $suffix++;
            }

            $existing[$code] = true;

            DB::table('message_templates')->where('id', $row->id)->update(['template_code' => $code]);
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
