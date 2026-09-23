<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — CRM Task 7. Tenant-owned CRM lead tags ("Hot", "Follow Up",
 * "VIP", ...). Metadata only — independent of crm_leads.status, which
 * stays the only lifecycle/pipeline field.
 *
 * COLUMNS
 *   account_id       NOT NULL. There is no global tag namespace.
 *   name             the display form, trimmed and whitespace-collapsed
 *                    by CrmTag (e.g. "Follow Up").
 *   normalized_name  CrmTag::normalize(name): the same string lower-cased.
 *                    The uniqueness key, so "Hot" / "hot" / "HOT" are one
 *                    tag within an account and two tags across accounts.
 *
 * WHY A normalized_name COLUMN RATHER THAN RELYING ON THE COLLATION:
 * utf8mb4_unicode_ci (config/database.php's default) would make a unique
 * index on `name` case-insensitive on MariaDB, but it also folds accents
 * ("Café" = "Cafe") and SQLite's default BINARY collation does neither —
 * the two engines would disagree about what a duplicate is. Storing the
 * normalized form and comparing it with a BINARY collation gives one
 * deterministic rule, defined in PHP (CrmTag::normalize()), identical on
 * both engines and identical to the model-level duplicate check.
 *
 * No slug: nothing in this application routes or keys on one, and the
 * normalized name already is the stable account-scoped identifier. Adding
 * one would be a second uniqueness rule to keep in sync.
 *
 * INDEXES
 *   unique(account_id, normalized_name)  duplicate prevention; also serves
 *                                        the account-scoped name-ordered
 *                                        list and prefix search, and (being
 *                                        account_id-leading) the account FK.
 *   unique(id, account_id)               referenced by crm_lead_tags'
 *                                        composite foreign key.
 * The account foreign key is declared LAST so MariaDB reuses the
 * account_id-leading unique index instead of auto-creating a redundant
 * single-column one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('crm_tags', function (Blueprint $table) use ($mysql) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('name', 50);

            $normalized = $table->string('normalized_name', 50);
            if ($mysql) {
                $normalized->collation('utf8mb4_bin');
            }

            $table->timestamps();

            $table->unique(['account_id', 'normalized_name'], 'crm_tags_account_normalized_name_unique');
            $table->unique(['id', 'account_id'], 'crm_tags_id_account_id_unique');

            $table->foreign('account_id', 'crm_tags_account_id_foreign')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tags');
    }
};
