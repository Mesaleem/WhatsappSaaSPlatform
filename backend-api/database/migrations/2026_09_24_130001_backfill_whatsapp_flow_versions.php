<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 7 Task 2 — DATA: give every existing journey exactly one
     * initial version and pin its existing sessions to it.
     *
     * For each whatsapp_flows row that has NO version yet:
     *   1. insert version 1 = its current graph_data (the graph those
     *      sessions have been running against), created_by_user_id NULL;
     *   2. set published_version_id to it;
     *   3. pin every session of that flow that has no pin yet.
     *
     * Idempotent (a flow that already has a version is skipped; only
     * unpinned sessions are touched), per-flow transactional, chunked,
     * query-builder only (no model events). Nothing is deleted or
     * overwritten, so down() is a no-op: rolling back the schema
     * migration removes these rows/columns.
     */
    public function up(): void
    {
        DB::table('whatsapp_flows')->orderBy('id')->chunkById(200, function ($flows) {
            foreach ($flows as $flow) {
                DB::transaction(function () use ($flow) {
                    if (DB::table('whatsapp_flow_versions')->where('flow_id', $flow->id)->exists()) {
                        return;
                    }

                    $stamp = $flow->updated_at ?? $flow->created_at ?? now();

                    $versionId = DB::table('whatsapp_flow_versions')->insertGetId([
                        'flow_id' => $flow->id,
                        'account_id' => $flow->account_id,
                        'version' => 1,
                        'graph_data' => $flow->graph_data,
                        'created_by_user_id' => null,
                        'created_at' => $stamp,
                        'updated_at' => $stamp,
                    ]);

                    DB::table('whatsapp_flows')->where('id', $flow->id)->update(['published_version_id' => $versionId]);

                    DB::table('whatsapp_flow_sessions')
                        ->where('flow_id', $flow->id)
                        ->whereNull('flow_version_id')
                        ->update(['flow_version_id' => $versionId]);
                });
            }
        });
    }

    public function down(): void
    {
        // Data-only and non-destructive; see the docblock.
    }
};
