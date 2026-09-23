<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 Task 5 -- per-recipient group dispatch logging.
     *
     * WHY A MIGRATION AT ALL (schema inspected first, per the task):
     * message_dispatch_logs already carries almost everything a
     * recipient-level row needs -- account_id, recipient_phone, source,
     * api_key_id, status, error_reason, has_media, media_url,
     * gateway_message_id (already indexed, already what
     * MetaWebhookController::correlateFailedStatus() matches on),
     * sent_at, template_name, message_preview, group_id/group_name and
     * timestamps. There is no separate message-event or recipient table
     * anywhere in this schema (checked: only comment_automation_events,
     * which is unrelated). So NO new table is created and no existing
     * column is altered.
     *
     * Exactly two things could not be expressed by what already exists:
     *
     *   parent_dispatch_id  The batch a recipient row belongs to. The
     *                       task explicitly forbids overloading an
     *                       unrelated field for this, and the obvious
     *                       candidate -- reference_type/reference_id --
     *                       is already in use for four other meanings
     *                       ('template', 'chatbot_rule', 'whatsapp_flow',
     *                       and the message type on the direct path), so
     *                       reusing it for the parent link as well would
     *                       be exactly the ambiguity being warned
     *                       against. Self-referencing FK, nullable:
     *                       every row that exists today, and every
     *                       individual send from now on, leaves it null.
     *
     *   engine_type         Which provider actually carried the message
     *                       ('qr' | 'meta'). Nothing on this table
     *                       recorded it -- `source` is the ENTRY POINT
     *                       ('api', 'web_template', 'chatbot', ...), not
     *                       the provider -- so a recipient-level audit
     *                       could not answer "which engine sent this".
     *                       Deliberately NOT derived from the
     *                       subscription at read time: engine_type is
     *                       mutable (an admin can move an account from
     *                       qr to meta), and an audit row must keep the
     *                       fact as it was at send time.
     *
     * The unique index is the duplicate-row guard the task requires to be
     * database-backed rather than cache-backed. Its three columns are
     * already present; only the index is new:
     *
     *   (parent_dispatch_id, reference_type, reference_id)
     *
     * A segment recipient is ('group_member', contact_group_members.id)
     * and a native group's single send is ('group_native', group id), so
     * the triple is unique within a batch and stable across re-runs --
     * re-running the same job finds the existing row instead of writing
     * a second one. Two members sharing a phone number stay two distinct
     * rows, because the identity is the membership, not the number.
     *
     * Every pre-existing row has parent_dispatch_id = NULL, and both
     * MySQL and SQLite treat NULLs in a unique index as distinct, so this
     * index constrains recipient rows ONLY and cannot collide with the
     * millions of individual/aggregate rows that share a
     * (reference_type, reference_id) pair. That NULL semantics is what
     * makes a single shared table workable here.
     *
     * The index name is set explicitly: the derived name would exceed
     * MySQL's 64-character identifier limit.
     */
    public function up(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->foreignId('parent_dispatch_id')
                ->nullable()
                ->after('group_name')
                ->constrained('message_dispatch_logs')
                ->nullOnDelete();

            $table->string('engine_type', 16)->nullable()->after('source');

            $table->unique(
                ['parent_dispatch_id', 'reference_type', 'reference_id'],
                'mdl_parent_recipient_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->dropUnique('mdl_parent_recipient_unique');
            $table->dropForeign(['parent_dispatch_id']);
            $table->dropColumn(['parent_dispatch_id', 'engine_type']);
        });
    }
};
