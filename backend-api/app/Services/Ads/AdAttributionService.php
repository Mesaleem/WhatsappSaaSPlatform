<?php

namespace App\Services\Ads;

use App\Models\AdAttribution;
use App\Models\AdCampaign;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Models\WhatsAppFlowSession;
use App\Support\PhoneNumberNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 10 Task 1 — the Ads attribution lifecycle, provider-agnostic:
 *
 *   1. ad metadata       ad_campaigns (launcher) — matched by ad id WITHIN the tenant only
 *   2. referral received recordReferral()   (Meta CTWA webhook today)
 *   3. conversation      the tenant's WhatsApp number + the customer's phone, set in step 2
 *   4. CRM lead          recordReferral() (CTWA capture → CaptureLeadLinker) or
 *                        linkJourneyLead() (journey save_lead in the attributed session)
 *   5. journey started   journeyStarted()   (a ctwa_referral / catch-all journey starts)
 *   6. conversion        syncConversion()   (the attributed CRM lead enters / leaves `converted`)
 *
 * It NEVER makes provider calls, never decides authorization, and never
 * resolves a tenant: every method receives the account the caller already
 * resolved from a tenant-owned asset (the WhatsApp number, the journey
 * session, the CRM lead). An external ad id is only ever matched inside
 * that account. Every write is best-effort for the caller (the webhook and
 * the journey must never fail because attribution could not be written) —
 * callers wrap it; failures are logged without payloads.
 *
 * Nothing is fabricated: campaign, click id, CRM lead, journey and
 * conversion stay NULL until they are actually observed; conversion value
 * stays NULL because no current flow observes a conversion amount.
 */
class AdAttributionService
{
    /** A journey start is linked to a referral from the same phone received within this window. */
    public const JOURNEY_LINK_WINDOW_HOURS = 24;

    /**
     * Step 2–4: a click-to-WhatsApp referral arrived on the tenant's number.
     * Idempotent per (account, provider, provider message id).
     *
     * @param  array<string, mixed>  $referral  the provider's referral object
     */
    public function recordReferral(
        int $accountId,
        string $provider,
        string $channel,
        string $referralMessageId,
        string $contactPhone,
        array $referral,
        ?int $whatsappSessionId = null,
        ?Lead $captureLead = null,
        ?CrmLead $crmLead = null,
    ): AdAttribution {
        $sourceType = self::str($referral['source_type'] ?? null, 32);
        $sourceId = self::str($referral['source_id'] ?? null, 191);

        $attributes = [
            'channel' => $channel,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'click_id' => self::str($referral['ctwa_clid'] ?? null, 191),
            'whatsapp_session_id' => $whatsappSessionId,
            'contact_phone' => self::phone($contactPhone),
            'ad_campaign_id' => $sourceType === 'ad' ? $this->campaignFor($accountId, $provider, $sourceId) : null,
            'referral_received_at' => now(),
            'metadata' => array_filter([
                'headline' => self::str($referral['headline'] ?? null, 255),
                'source_url' => self::str($referral['source_url'] ?? null, 500),
                'media_type' => self::str($referral['media_type'] ?? null, 32),
            ], fn ($v) => $v !== null) ?: null,
        ];

        $key = ['account_id' => $accountId, 'provider' => $provider, 'referral_message_id' => self::str($referralMessageId, 191)];

        try {
            $attribution = AdAttribution::query()->firstOrCreate($key, $attributes);
        } catch (UniqueConstraintViolationException) {
            // A concurrent redelivery of the same message created it first.
            $attribution = AdAttribution::query()->where($key)->firstOrFail();
        }

        if ($captureLead && $attribution->capture_lead_id === null && (int) $captureLead->account_id === $accountId) {
            $attribution->capture_lead_id = $captureLead->id;
        }

        if ($crmLead) {
            $this->applyCrmLead($attribution, $crmLead);
        }

        if ($attribution->isDirty()) {
            $attribution->save();
        }

        return $attribution;
    }

    /**
     * Step 5: a journey started for a referral. Linked to the newest
     * referral from the same phone (and the same ad, when the referral names
     * one) on the same account within the window that has no journey yet.
     *
     * @param  array<string, mixed>  $referral
     */
    public function journeyStarted(int $accountId, string $senderPhone, array $referral, WhatsAppFlowSession $session): void
    {
        if ((int) $session->account_id !== $accountId) {
            return;
        }

        $sourceId = self::str($referral['source_id'] ?? null, 191);

        $attribution = AdAttribution::query()
            ->forAccount($accountId)
            ->where('contact_phone', self::phone($senderPhone))
            ->whereNull('flow_session_id')
            ->when($sourceId !== null, fn ($q) => $q->where('source_id', $sourceId))
            ->where('referral_received_at', '>=', now()->subHours(self::JOURNEY_LINK_WINDOW_HOURS))
            ->orderByDesc('referral_received_at')
            ->orderByDesc('id')
            ->first();

        if (! $attribution) {
            return;
        }

        AdAttribution::query()->whereKey($attribution->id)->whereNull('flow_session_id')->update([
            'flow_session_id' => $session->id,
            'journey_started_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Step 4 (journey path): save_lead produced a CRM lead inside an attributed journey session. */
    public function linkJourneyLead(WhatsAppFlowSession $session, CrmLead $crmLead): void
    {
        if ((int) $crmLead->account_id !== (int) $session->account_id) {
            return;
        }

        $attribution = AdAttribution::query()
            ->forAccount((int) $session->account_id)
            ->where('flow_session_id', $session->id)
            ->whereNull('crm_lead_id')
            ->first();

        if ($attribution) {
            $this->applyCrmLead($attribution, $crmLead);
            $attribution->save();
        }
    }

    /**
     * Step 6: the attributed CRM lead's status changed. Conversion is the
     * only outcome the product observes today: `converted` sets converted_at,
     * leaving `converted` clears it (the row always reflects the lead's
     * current state). Called from CrmLead's model events, so manual, bulk,
     * API and automated status changes all count the same way.
     */
    public function syncConversion(CrmLead $lead): void
    {
        $converted = $lead->status === CrmLead::STATUS_CONVERTED;

        AdAttribution::query()
            ->forAccount((int) $lead->account_id)
            ->where('crm_lead_id', $lead->id)
            ->when($converted, fn ($q) => $q->whereNull('converted_at'), fn ($q) => $q->whereNotNull('converted_at'))
            ->update(['converted_at' => $converted ? now() : null, 'updated_at' => now()]);
    }

    /**
     * Reporting foundation: per ad (source id) funnel counts for referrals
     * received in [from, to]. Spend is included only for ads linked to one of
     * the tenant's launcher campaigns (sum of its daily metrics in the same
     * range); ROAS only when both spend and a conversion value exist.
     *
     * @return array<string, mixed>
     */
    public function summary(int $accountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = AdAttribution::query()
            ->forAccount($accountId)
            ->whereBetween('referral_received_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('provider, source_type, source_id, ad_campaign_id,
                COUNT(*) as referrals,
                COUNT(DISTINCT contact_phone) as conversations,
                COUNT(crm_lead_id) as leads,
                COUNT(flow_session_id) as journeys,
                COUNT(converted_at) as conversions,
                SUM(conversion_value) as conversion_value,
                COUNT(conversion_value) as valued_conversions')
            ->groupBy('provider', 'source_type', 'source_id', 'ad_campaign_id')
            ->orderByDesc('referrals')
            ->limit(200)
            ->get();

        $campaignIds = $rows->pluck('ad_campaign_id')->filter()->unique()->values();
        $campaigns = AdCampaign::query()->forAccount($accountId)->whereIn('id', $campaignIds)->get(['id', 'name', 'meta_campaign_id', 'meta_adset_id', 'meta_ad_id'])->keyBy('id');
        $spend = DB::table('ad_campaign_daily_metrics')
            ->where('account_id', $accountId)
            ->whereIn('ad_campaign_id', $campaignIds)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('ad_campaign_id, SUM(spend) as spend')
            ->groupBy('ad_campaign_id')
            ->pluck('spend', 'ad_campaign_id');

        $ads = $rows->map(function ($row) use ($campaigns, $spend) {
            $campaign = $row->ad_campaign_id ? $campaigns->get($row->ad_campaign_id) : null;
            $cost = $campaign && isset($spend[$campaign->id]) ? round((float) $spend[$campaign->id], 2) : null;

            return $this->funnel($row, $cost) + [
                'provider' => $row->provider,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'campaign' => $campaign ? [
                    'id' => $campaign->id, 'name' => $campaign->name, 'external_campaign_id' => $campaign->meta_campaign_id,
                    'external_adset_id' => $campaign->meta_adset_id, 'external_ad_id' => $campaign->meta_ad_id,
                ] : null,
            ];
        })->values();

        $totals = (object) [
            'referrals' => (int) $rows->sum('referrals'),
            'conversations' => (int) AdAttribution::query()->forAccount($accountId)
                ->whereBetween('referral_received_at', [$from->startOfDay(), $to->endOfDay()])
                ->distinct()->count('contact_phone'),
            'leads' => (int) $rows->sum('leads'),
            'journeys' => (int) $rows->sum('journeys'),
            'conversions' => (int) $rows->sum('conversions'),
            'conversion_value' => $rows->sum('valued_conversions') > 0 ? $rows->sum('conversion_value') : null,
            'valued_conversions' => (int) $rows->sum('valued_conversions'),
        ];
        $linkedSpend = $ads->pluck('spend')->filter(fn ($v) => $v !== null);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->funnel($totals, $linkedSpend->isEmpty() ? null : round($linkedSpend->sum(), 2)),
            'ads' => $ads->all(),
            'notes' => [
                'conversion' => 'A conversion is an attributed CRM lead currently in status "converted".',
                'conversion_value' => 'No conversion value is observed yet, so value and ROAS are reported as null.',
                'spend' => 'Spend is available only for ads created by the Meta Ads launcher (daily metrics), summed over the same date range.',
            ],
        ];
    }

    // ================================================================ internals

    /** @return array<string, mixed> */
    private function funnel(object $row, ?float $spend): array
    {
        $referrals = (int) $row->referrals;
        $leads = (int) $row->leads;
        $conversions = (int) $row->conversions;
        $value = ((int) ($row->valued_conversions ?? 0)) > 0 && $row->conversion_value !== null ? round((float) $row->conversion_value, 2) : null;

        return [
            'referrals' => $referrals,
            'conversations' => (int) $row->conversations,
            'leads' => $leads,
            'journeys' => (int) $row->journeys,
            'conversions' => $conversions,
            'conversion_rate' => $referrals > 0 ? round($conversions / $referrals, 4) : null,
            'conversion_value' => $value,
            'spend' => $spend,
            'cost_per_lead' => $spend !== null && $leads > 0 ? round($spend / $leads, 2) : null,
            'roas' => $spend !== null && $spend > 0 && $value !== null ? round($value / $spend, 4) : null,
        ];
    }

    private function applyCrmLead(AdAttribution $attribution, CrmLead $crmLead): void
    {
        if ($attribution->crm_lead_id !== null || (int) $crmLead->account_id !== (int) $attribution->account_id) {
            return;
        }

        $attribution->crm_lead_id = $crmLead->id;
        $attribution->lead_linked_at = now();

        if ($crmLead->status === CrmLead::STATUS_CONVERTED) {
            $attribution->converted_at = now();
        }
    }

    /** The tenant's own launcher campaign for this ad id — exactly one, or none (ambiguity never guesses). */
    private function campaignFor(int $accountId, string $provider, ?string $sourceId): ?int
    {
        if ($provider !== AdAttribution::PROVIDER_META || $sourceId === null) {
            return null;
        }

        $ids = AdCampaign::query()->forAccount($accountId)->where('meta_ad_id', $sourceId)->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private static function phone(string $phone): ?string
    {
        $normalized = PhoneNumberNormalizer::normalize($phone);

        return self::str($normalized !== '' ? $normalized : $phone, 32);
    }

    private static function str(mixed $value, int $max): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $max);
    }

    /** Best-effort wrapper for callers that must never fail because of attribution. */
    public static function quietly(string $step, int $accountId, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::warning("AdAttributionService: {$step} could not be recorded.", ['account_id' => $accountId, 'exception' => class_basename($e)]);

            return null;
        }
    }
}
