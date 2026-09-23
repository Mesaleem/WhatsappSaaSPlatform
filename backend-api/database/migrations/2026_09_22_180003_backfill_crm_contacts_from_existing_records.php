<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SUPERSEDED BY 2026_09_22_190004 — see that migration.
     *
     * Round 2 moved the capture link from leads.crm_lead_id onto
     * crm_leads.capture_lead_id so it could carry a composite,
     * tenant-proving foreign key (the engine evidence is in
     * 2026_09_22_190000). CaptureLeadLinker writes the new column, which
     * does not exist yet at this point in the migration order — so this
     * migration can no longer do its own work and has been reduced to a
     * guarded no-op rather than deleted, because deleting it would
     * change the recorded history of any deployment that already ran it.
     *
     * Both paths converge on the same state:
     *  - ALREADY MIGRATED: this ran under the old code and populated
     *    leads.crm_lead_id; 190000 carries every one of those links
     *    across to the new column.
     *  - FRESH INSTALL: this does nothing, 190000 creates the column,
     *    and 190004 performs the backfill against it.
     * The guard is a column check rather than a flag, so it is correct
     * whichever order a given database happens to be in.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('crm_leads', 'capture_lead_id')) {
            Log::info('CRM backfill deferred to 2026_09_22_190004 — the capture link column does not exist yet at this point in the migration order.');

            return;
        }

        Log::info('CRM backfill: nothing to do here; 2026_09_22_190004 owns this backfill.');
    }

    /**
     * Deliberately a no-op.
     *
     * The reverse of this migration would be "delete the CRM contacts
     * and leads it created" — customer data that, by the time anyone
     * rolls back, a tenant may already have edited, assigned and worked.
     * Destroying it to undo a link is exactly the destructive migration
     * the brief forbids.
     *
     * Rolling back is still complete in the sense that matters: the
     * three preceding migrations drop leads.crm_lead_id and
     * contact_group_members.contact_id, so every link this migration
     * made disappears with the columns that held it, and the capture and
     * group domains return to precisely their pre-hardening shape. The
     * contacts and crm_leads rows simply remain, inert, until someone
     * decides what should happen to them.
     */
    public function down(): void
    {
        Log::info('CRM hardening backfill rollback: no rows removed by design — see this migration\'s docblock.');
    }
};
