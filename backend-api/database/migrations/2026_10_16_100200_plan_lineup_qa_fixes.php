<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA fixes (2026-10-14) — plan line-up decisions by the owner:
 *
 *  - Meta Growth: 10 broadcasts a month (was 0, so its 2,500 marketing
 *    messages could only go out one by one).
 *  - Meta Pro: includes AI (AI agents, knowledge base, AI journey nodes) —
 *    no current plan had the `ai` capability after Growth was retired.
 *  - CRM: includes the Developer API (external_api) — its only lead source used
 *    to be typing leads in by hand; now a website form or another system can
 *    send leads through /v1/crm/leads (and receive crm.* webhooks).
 *  - Free Trial: a hidden plan (`trial`, never sold or listed) with Starter's
 *    features, 50 messages, 7 days and Starter's limits. Website signup records
 *    it, so a trial's menu matches what actually works (it used to show every
 *    module while most APIs refused).
 *
 * Idempotent. Existing Meta Pro clients get AI on their next reconciliation
 * (plan edit / payment); clients already on the plans keep their captured
 * limits until their next purchase, like every plan change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'max_broadcasts_per_month')) {
            return;
        }
        $now = now();

        DB::table('plans')->where('slug', 'meta_growth')->where('max_broadcasts_per_month', 0)
            ->update(['max_broadcasts_per_month' => 10, 'updated_at' => $now]);

        $capabilityIds = Schema::hasTable('capabilities') ? DB::table('capabilities')->pluck('id', 'slug') : collect();

        $crm = DB::table('plans')->where('slug', 'crm')->value('id');
        if ($crm && isset($capabilityIds['external_api'])
            && ! DB::table('plan_entitlements')->where('plan_id', $crm)->where('capability_id', $capabilityIds['external_api'])->exists()) {
            DB::table('plan_entitlements')->insert([
                'plan_id' => $crm, 'capability_id' => $capabilityIds['external_api'], 'usage_limit' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $metaPro = DB::table('plans')->where('slug', 'meta_pro')->value('id');
        if ($metaPro && isset($capabilityIds['ai'])
            && ! DB::table('plan_entitlements')->where('plan_id', $metaPro)->where('capability_id', $capabilityIds['ai'])->exists()) {
            DB::table('plan_entitlements')->insert([
                'plan_id' => $metaPro, 'capability_id' => $capabilityIds['ai'], 'usage_limit' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $starter = DB::table('plans')->where('slug', 'starter')->first();
        if ($starter && ! DB::table('plans')->where('slug', 'trial')->exists()) {
            $trialId = DB::table('plans')->insertGetId([
                'slug' => 'trial',
                'label' => 'Free Trial',
                'price' => 0,
                'duration_days' => 7,
                'description' => 'Website signup trial: Starter features, 50 messages, 7 days.',
                'engine_type' => 'qr',
                'billing_model' => 'flat_quota',
                'rate_per_message' => null,
                'total_allocated_messages' => 50,
                'included_credits' => 0,
                'is_active' => true,
                'is_public' => false,
                'account_id' => null,
                'max_users' => $starter->max_users ?? 2,
                'max_meta_templates' => $starter->max_meta_templates,
                'max_flows' => $starter->max_flows,
                'max_broadcasts_per_month' => $starter->max_broadcasts_per_month,
                'marketing_message_limit' => $starter->marketing_message_limit,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (DB::table('plan_entitlements')->where('plan_id', $starter->id)->get() as $row) {
                DB::table('plan_entitlements')->insert([
                    'plan_id' => $trialId,
                    'capability_id' => $row->capability_id,
                    'usage_limit' => isset($capabilityIds['whatsapp_send']) && (int) $row->capability_id === (int) $capabilityIds['whatsapp_send'] ? 50 : $row->usage_limit,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        DB::table('plans')->where('slug', 'meta_growth')->where('max_broadcasts_per_month', 10)->update(['max_broadcasts_per_month' => 0]);
        if (! DB::table('invoices')->where('plan_key', 'trial')->exists()) {
            DB::table('plans')->where('slug', 'trial')->delete();
        }
    }
};
