<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tiered Template Approval Workflow for 3-Tier Hierarchy. Widens
     * message_templates.status from ['pending','approved','rejected'] to
     * add three intermediate review states a template can now pass
     * through before it reaches the pre-existing 'pending' (awaiting the
     * Super Admin's mandatory WhatsApp test-fire + final approve — see
     * MessageTemplateController::approve()'s Strict 1-Template-Per-Client
     * & Testing Gate, UNCHANGED and still the only path to 'approved'):
     *
     *   - pending_agent_review  — a Sub-Client (Account.agent_id set)
     *     submitted this request; awaiting its own Parent Agent.
     *   - pending_admin_review  — a direct/platform Client (agent_id
     *     null) submitted this request; awaiting Super Admin directly,
     *     no Agent in the chain.
     *   - pending_meta_approval — an Agent has already reviewed/approved
     *     a Sub-Client submission whose account runs the 'meta' engine;
     *     awaiting Super Admin's final review. [Disclosed]: this
     *     codebase has no live integration with Meta's real WhatsApp
     *     Template Library API — this status is a human routing label
     *     (so a Super Admin's queue can tell "Agent-cleared, meta engine"
     *     apart from "Agent-cleared, qr engine" / plain "pending"), not
     *     an automated call to Meta. It still terminates at the exact
     *     same Super-Admin approve()/reject() actions as 'pending'.
     *
     * See TemplateService::resolveCreationStatus() /
     * ::routeAfterAgentApproval() for where each value is actually set.
     *
     * [Disclosed, unverified]: no `php` binary was reachable in the
     * shell this migration was authored from (matching the same
     * disclosed limitation noted in this table's other recent
     * migrations/commits), so this could not be run against the local
     * database as part of this change. Laravel 11 modifies an existing
     * column (including on sqlite) without requiring doctrine/dbal —
     * confirmed against this project's own composer.json (no
     * doctrine/dbal dependency) — but this specific ->change() call has
     * not been exercised in this environment. Run `php artisan migrate`
     * and confirm before relying on this in any environment.
     *
     * Values are appended, not reordered or removed — every existing row
     * already at 'pending'/'approved'/'rejected' keeps exactly the same
     * meaning and remains valid against the widened constraint.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'pending_agent_review',
                'pending_admin_review',
                'pending_meta_approval',
                'approved',
                'rejected',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Reversible only if no row currently uses one of the three new
        // values — matches this column's own original constraint. A
        // down() that silently reassigned those rows to 'pending' would
        // be a silent data-meaning change, not a schema revert; failing
        // the rollback here (via the DB's own CHECK/enum constraint
        // rejecting the narrower list, or a manual data fix first) is
        // deliberate.
        Schema::table('message_templates', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};
