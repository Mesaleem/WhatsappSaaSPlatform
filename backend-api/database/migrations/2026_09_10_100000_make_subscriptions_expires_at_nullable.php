<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BUG FIX — Super Admin "Scan to Connect" permanently disabled.
     *
     * Root cause (confirmed from storage/logs/laravel.log): every call to
     * GET /api/admin/whatsapp/self-device throws
     * "SQLSTATE[23000]: Integrity constraint violation: 1048 Column
     * 'expires_at' cannot be null" from inside Account::platformDevice(),
     * which deliberately does:
     *
     *   $account->subscriptions()->create([..., 'expires_at' => null, ...]);
     *
     * — a lazily-provisioned, intentionally NEVER-EXPIRING subscription
     * for the Super Admin's own WhatsApp test device (see that method's
     * docblock). Subscription::computeStatus() already treats a null
     * expires_at as "never expires" (`$this->expires_at !== null && ...`),
     * so the MODEL layer was always written to support this — only the
     * ORIGINAL create_subscriptions_table migration (2026_09_08_100520,
     * predating the platform-device feature) never made the column
     * nullable to match. The 500 this throws is silently swallowed by
     * frontend-app's AdminDeviceSettingsPage.tsx (empty catch block, no
     * error surfaced), which is why the symptom looks like nothing more
     * than "the button is disabled" — the account_id it needs never
     * successfully loads because this endpoint 500s on every single call,
     * not intermittently.
     *
     * No data cleanup needed: every prior attempt failed inside the same
     * transaction-less insert, so no row with a bad expires_at was ever
     * persisted. The very next request after this migration runs
     * self-heals.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible without deciding what to backfill NULLs with —
        // deliberately left as a no-op rather than silently picking an
        // arbitrary expiry date for the platform-device row (or any other
        // row that may have adopted a NULL expires_at by then).
    }
};
