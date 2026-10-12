<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every QR (Baileys) plan includes unlimited messages (owner decision 2026-10-12);
 * Starter was changed by 2026_10_12_120000, this does Growth. Business runs on the
 * Meta Cloud API (Meta bills per message) and keeps its quota.
 *
 * Only a Growth row still on its original 2,500-message flat quota is changed;
 * subscriptions and invoices already issued keep their own terms. Reversible:
 * down() restores the quota only where the row is still exactly what up() left.
 */
return new class extends Migration
{
    private const UNLIMITED_DESCRIPTION = 'Unlimited messages over the QR (Baileys) engine.';

    public function up(): void
    {
        $updated = DB::table('plans')
            ->where('slug', 'growth')
            ->where('engine_type', 'qr')
            ->where('billing_model', 'flat_quota')
            ->where('total_allocated_messages', 2500)
            ->update([
                'billing_model' => 'unlimited',
                'total_allocated_messages' => null,
                'description' => self::UNLIMITED_DESCRIPTION,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $this->setSendLimit(2500, null);
        }
    }

    public function down(): void
    {
        $updated = DB::table('plans')
            ->where('slug', 'growth')
            ->where('billing_model', 'unlimited')
            ->whereNull('total_allocated_messages')
            ->where('description', self::UNLIMITED_DESCRIPTION)
            ->update([
                'billing_model' => 'flat_quota',
                'total_allocated_messages' => 2500,
                'description' => '2,500 messages/month over the QR (Baileys) engine.',
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $this->setSendLimit(null, 2500);
        }
    }

    private function setSendLimit(?int $from, ?int $to): void
    {
        $planId = DB::table('plans')->where('slug', 'growth')->value('id');
        $capabilityId = DB::table('capabilities')->where('slug', 'whatsapp_send')->value('id');
        if (! $planId || ! $capabilityId) {
            return;
        }

        $query = DB::table('plan_entitlements')->where('plan_id', $planId)->where('capability_id', $capabilityId);
        $from === null ? $query->whereNull('usage_limit') : $query->where('usage_limit', $from);
        $query->update(['usage_limit' => $to, 'updated_at' => now()]);
    }
};
