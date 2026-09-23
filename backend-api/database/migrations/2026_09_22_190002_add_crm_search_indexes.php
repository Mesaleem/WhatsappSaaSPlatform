<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — CRM Hardening Round 2, Limitation 4. Indexes for the CRM
     * lookups that can actually use one.
     *
     * WHAT WAS ALREADY THERE, confirmed with SHOW INDEX rather than
     * assumed:
     *   contacts    PRIMARY(id)
     *               UNIQUE(account_id, phone_number)   <- exact phone lookup
     *               UNIQUE(id, account_id)             <- FK support only
     *   crm_leads   PRIMARY(id)
     *               (account_id, status)
     *               (account_id, assigned_user_id)
     *               (account_id, created_at)
     *               (contact_id, account_id)           <- FK support
     * So `account_id + phone_number` was ALREADY optimal: the existing
     * unique index serves both the exact lookup ContactResolver does on
     * every capture and the ?phone_number= filter, which the controller
     * normalizes before querying precisely so it can hit that index
     * instead of scanning. No new phone index is added, because adding
     * one would duplicate an index that already exists.
     *
     * WHAT WAS MISSING: nothing indexed `name` at all, so both
     * ?name= (a prefix-friendly filter) and the name half of ?search=
     * scanned every contact in the account.
     *
     * (account_id, name) is what this adds, and it is honest about what
     * a B-tree can do:
     *   - `name LIKE 'ada%'`  -> range scan on this index. This is the
     *     ?name= filter, which the controller now expresses as a PREFIX
     *     match for exactly this reason.
     *   - `name LIKE '%ada%'` -> no index can help; a leading wildcard
     *     defeats every B-tree. ?search= keeps substring semantics
     *     because narrowing it would change behaviour users already
     *     rely on, but it is now confined to the account partition,
     *     which is the part an index CAN accelerate.
     * There is deliberately no FULLTEXT index: it would change match
     * semantics from substring to word-boundary, silently breaking
     * searches for a partial word, and the brief says not to
     * over-engineer.
     *
     * crm_leads gets no new index. Its list query filters by account_id
     * plus status/assignee and orders by created_at — all three already
     * covered — and its ?search= goes through contacts via whereHas,
     * which lands on the contacts indexes above.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['account_id', 'name'], 'contacts_account_id_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_account_id_name_index');
        });
    }
};
