<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ₹599 QR plan (Starter) includes unlimited messages (owner decision 2026-10-12).
 *
 * Only a Starter row still on its original 500-message flat quota is changed, so
 * terms a Super Admin set on purpose are never overwritten. A purchase credits an
 * 'unlimited' plan with no message cap (InvoiceCreditService). Subscriptions and
 * invoices already issued keep their own terms. The whatsapp_send entitlement's
 * usage_limit (500) is cleared to match; it is informational for sending.
 * Reversible: down() restores the 500-message flat quota only where the row is
 * still exactly what up() left.
 */
return new class extends Migration
{
    private const UNLIMITED_DESCRIPTION = 'Unlimited messages over the QR (Baileys) engine.';

    public function up(): void
    {
        $updated = DB::table('plans')
            ->where('slug', 'starter')
            ->where('billing_model', 'flat_quota')
            ->where('total_allocated_messages', 500)
            ->update([
                'billing_model' => 'unlimited',
                'total_allocated_messages' => null,
                'description' => self::UNLIMITED_DESCRIPTION,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $this->setSendLimit(500, null);
        }
    }

    public function down(): void
    {
        $updated = DB::table('plans')
            ->where('slug', 'starter')
            ->where('billing_model', 'unlimited')
            ->whereNull('total_allocated_messages')
            ->where('description', self::UNLIMITED_DESCRIPTION)
            ->update([
                'billing_model' => 'flat_quota',
                'total_allocated_messages' => 500,
                'description' => '500 messages/month over the QR (Baileys) engine.',
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $this->setSendLimit(null, 500);
        }
    }

    private function setSendLimit(?int $from, ?int $to): void
    {
        $planId = DB::table('plans')->where('slug', 'starter')->value('id');
        $capabilityId = DB::table('capabilities')->where('slug', 'whatsapp_send')->value('id');
        if (! $planId || ! $capabilityId) {
            return;
        }

        $query = DB::table('plan_entitlements')->where('plan_id', $planId)->where('capability_id', $capabilityId);
        $from === null ? $query->whereNull('usage_limit') : $query->where('usage_limit', $from);
        $query->update(['usage_limit' => $to, 'updated_at' => now()]);
    }
};
