<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 Task 2 — immutable Journey versions. Schema only; the
     * existing rows are backfilled by 2026_09_24_130001.
     *
     * whatsapp_flow_versions — one row per saved graph of a journey. Never
     * updated after insert (WhatsAppFlowVersion refuses it); removed only
     * by the cascade when its journey is deleted.
     *   flow_id, account_id   the journey and its tenant (account copied so
     *                         a version is tenant-scoped on its own)
     *   version               1, 2, 3 … per journey; unique(flow_id, version)
     *                         is the backstop against concurrent numbering
     *   graph_data            the full graph, exactly as saved
     *   created_by_user_id    who saved it (null for backfill/system)
     *
     * whatsapp_flows.published_version_id — which version NEW sessions
     * start on. graph_data stays on whatsapp_flows as the editable working
     * copy (= the latest version), so the existing CRUD contract is
     * unchanged.
     *
     * whatsapp_flow_sessions.flow_version_id — the version a session was
     * started on; every later step reads this graph and nothing else.
     *
     * All additive and nullable: no existing column changes meaning.
     */
    public function up(): void
    {
        Schema::create('whatsapp_flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('whatsapp_flows')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('graph_data');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['flow_id', 'version'], 'wfv_flow_version_unique');
        });

        Schema::table('whatsapp_flows', function (Blueprint $table) {
            $table->foreignId('published_version_id')->nullable()->after('graph_data')
                ->constrained('whatsapp_flow_versions')->nullOnDelete();
        });

        Schema::table('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->foreignId('flow_version_id')->nullable()->after('flow_id')
                ->constrained('whatsapp_flow_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flow_version_id');
        });

        Schema::table('whatsapp_flows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_version_id');
        });

        Schema::dropIfExists('whatsapp_flow_versions');
    }
};
