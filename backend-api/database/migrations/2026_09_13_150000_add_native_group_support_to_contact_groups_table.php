<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group Messaging — Native WhatsApp Group Re-Architecture.
     *
     * Adds the columns needed to distinguish a group that is purely an
     * internal DB broadcast list (the ONLY kind that existed before this
     * migration — see contact_groups' original creating migration) from
     * a group backed by a real WhatsApp group chat created on the
     * connected 'qr' (Baileys) device.
     *
     * `group_type` is a plain string, not a native DB enum — same
     * convention this codebase already uses for message_dispatch_logs.status
     * ('queued' was added as a third value with no schema change) and for
     * every other small closed vocabulary in this app (PlanCatalog,
     * Account::MODULES): validated in PHP (see ContactGroup::GROUP_TYPES),
     * portable across the SQLite-by-default / MySQL-in-production split
     * this app already has (see README §2, Database & Cache).
     *
     * `wa_group_jid` is the Baileys/WhatsApp group JID (e.g.
     * "120363xxx@g.us") once the live group has been created —
     * null for every 'internal_segment' row and for a 'native_wa_group'
     * row that hasn't finished syncing yet.
     *
     * `sync_status` is null for 'internal_segment' groups (the concept
     * of "syncing to a live WhatsApp group" does not apply to them) and
     * one of 'pending' | 'synced' | 'failed' for 'native_wa_group' rows
     * — set to 'pending' at creation (ContactGroupController::store()),
     * then resolved by CreateNativeWhatsAppGroupJob once the
     * qr-engine-service call returns. `sync_error` carries the failure
     * reason for the "Failed" badge/tooltip when sync_status='failed'.
     */
    public function up(): void
    {
        Schema::table('contact_groups', function (Blueprint $table) {
            $table->string('group_type')->default('internal_segment')->after('name');
            $table->string('wa_group_jid')->nullable()->after('group_type');
            $table->string('invite_link')->nullable()->after('wa_group_jid');
            $table->string('sync_status')->nullable()->after('invite_link');
            $table->text('sync_error')->nullable()->after('sync_status');

            $table->index(['account_id', 'group_type']);
        });
    }

    public function down(): void
    {
        Schema::table('contact_groups', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'group_type']);
            $table->dropColumn(['group_type', 'wa_group_jid', 'invite_link', 'sync_status', 'sync_error']);
        });
    }
};
