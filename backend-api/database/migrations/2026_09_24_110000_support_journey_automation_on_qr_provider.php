<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 7 Task 1.5 — DATA ONLY, one row. Owner decision: Journey
     * automation is supported on the QR provider (see the matching row in
     * Phase1FoundationSeeder::PROVIDER_CAPABILITIES, the source of truth
     * for fresh installs). This brings an EXISTING install's
     * provider_capabilities row (qr, journey_automation) in line, so that
     * plan grants (InvoiceCreditService / entitlements:backfill-plan /
     * reconciliation) stop skipping journey_automation on QR accounts.
     *
     * Touches nothing else: no account entitlement is written here.
     * Existing Growth accounts receive the capability from
     * `php artisan entitlements:backfill-plan` (dry-run first), which
     * already skips revoked rows and never rewrites manual grants.
     *
     * No-op when the row does not exist (a database the foundation seeder
     * has not run on yet — the seeder then writes the new value itself).
     */
    private const REASON_NEW = 'Journey automation is engine-agnostic: journeys send through the unified WhatsApp driver contract, and the confirmed plan matrix bundles it into the QR-engine Growth plan.';

    private const REASON_OLD = 'Business/product-tier restriction: QR does not get Journey automation.';

    public function up(): void
    {
        $this->set(true, self::REASON_NEW);
    }

    public function down(): void
    {
        $this->set(false, self::REASON_OLD);
    }

    private function set(bool $supported, string $reason): void
    {
        $providerId = DB::table('providers')->where('slug', 'qr')->value('id');
        $capabilityId = DB::table('capabilities')->where('slug', 'journey_automation')->value('id');

        if ($providerId && $capabilityId) {
            DB::table('provider_capabilities')
                ->where('provider_id', $providerId)
                ->where('capability_id', $capabilityId)
                ->update(['supported' => $supported, 'reason' => $reason, 'updated_at' => now()]);
        }

        // ProviderCapabilityService caches supports() per pairing.
        Cache::forget('provider_capability:qr:journey_automation');
    }
};
