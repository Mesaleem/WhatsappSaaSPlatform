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

    /** Diagnostics only: at most this many ambiguous candidates are annotated; matching never depends on it. */
    public const MAX_ANNOTATED_CANDIDATES = 10;

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
        [$campaignId, $campaignMatch] = $sourceType === 'ad' ? $this->campaignFor($accountId, $provider, $sourceId) : [null, null];

        $attributes = [
            'channel' => $channel,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'click_id' => self::str($referral['ctwa_clid'] ?? null, 191),
            'whatsapp_session_id' => $whatsappSessionId,
            'contact_phone' => self::phone($contactPhone),
            'ad_campaign_id' => $campaignId,
            'referral_received_at' => now(),
            'metadata' => array_filter([
                'headline' => self::str($referral['headline'] ?? null, 255),
                'source_url' => self::str($referral['source_url'] ?? null, 500),
                'media_type' => self::str($referral['media_type'] ?? null, 32),
                // Why the launcher campaign was (not) matched — diagnostics only.
                'campaign_match' => $campaignId === null ? $campaignMatch : null,
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
            $this->applyCrmLead($attribution, $crmLead, true);
        }

        if ($attribution->isDirty()) {
            $attribution->save();
        }

        return $attribution;
    }

    /**
     * Step 5: a journey started for a referral, linked inside ONE account by
     * the strongest identifier available:
     *
     *   1. referral_message_id — the provider message id of the inbound
     *      message that started the journey (exact);
     *   2. click_id            — the referral's ctwa_clid, when exactly one
     *      open row carries it;
     *   3. phone + ad id       — only when exactly ONE unlinked referral from
     *      this phone (and this ad, when named) lies inside the window.
     *
     * Several equally plausible referrals are AMBIGUOUS: nothing is linked and
     * the candidates are annotated `match.status = ambiguous`. A session is
     * linked at most once, so a repeated call is a no-op. The winning rule is
     * stored in metadata.match.
     *
     * @param  array<string, mixed>  $referral
     */
    public function journeyStarted(int $accountId, string $senderPhone, array $referral, WhatsAppFlowSession $session, ?string $referralMessageId = null): void
    {
        if ((int) $session->account_id !== $accountId) {
            return;
        }

        // Idempotent: this session is already attributed (retry / redelivery).
        if (AdAttribution::query()->forAccount($accountId)->where('flow_session_id', $session->id)->exists()) {
            return;
        }

        $messageId = self::str($referralMessageId, 191);
        $attribution = null;
        $rule = null;

        if ($messageId !== null) {
            $attribution = AdAttribution::query()->forAccount($accountId)
                ->where('referral_message_id', $messageId)->whereNull('flow_session_id')->first();
            $rule = 'referral_message_id';

            if ($attribution === null && AdAttribution::query()->forAccount($accountId)->where('referral_message_id', $messageId)->exists()) {
                return; // that referral already belongs to another journey session — never re-attach it.
            }
        }

        if ($attribution === null) {
            $sourceId = self::str($referral['source_id'] ?? null, 191);
            $clickId = self::str($referral['ctwa_clid'] ?? null, 191);

            $base = fn () => AdAttribution::query()
                ->forAccount($accountId)
                ->where('contact_phone', self::phone($senderPhone))
                ->whereNull('flow_session_id')
                ->when($sourceId !== null, fn ($q) => $q->where('source_id', $sourceId))
                ->where('referral_received_at', '>=', now()->subHours(self::JOURNEY_LINK_WINDOW_HOURS));

            // Uniqueness is always decided in the database (limit 2 = "one or more
            // than one"), never on a truncated list, so a row limit can only
            // ever turn into "ambiguous", never into an arbitrary match.
            if ($clickId !== null) {
                $byClick = $base()->where('click_id', $clickId)->orderBy('id')->limit(2)->get();

                if ($byClick->count() === 1) {
                    $attribution = $byClick->first();
                    $rule = 'click_id';
                }
            }

            if ($attribution === null) {
                $candidates = $base()->orderBy('id')->limit(2)->get();

                if ($candidates->count() === 1) {
                    $attribution = $candidates->first();
                    $rule = 'phone_source_window';
                } elseif ($candidates->count() > 1) {
                    $total = $base()->count();
                    $ids = $base()->orderByDesc('referral_received_at')->orderByDesc('id')->limit(self::MAX_ANNOTATED_CANDIDATES)->pluck('id');

                    foreach ($ids as $id) {
                        $this->annotate((int) $id, ['status' => 'ambiguous', 'reason' => 'multiple_referrals_in_window', 'candidates' => $total]);
                    }

                    return;
                }
            }
        }

        if ($attribution === null) {
            return;
        }

        $updated = AdAttribution::query()->whereKey($attribution->id)->whereNull('flow_session_id')->update([
            'flow_session_id' => $session->id,
            'journey_started_at' => now(),
            'updated_at' => now(),
        ]);

        if ($updated) {
            $this->annotate($attribution->id, ['status' => 'matched', 'rule' => $rule]);
        }
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
            ->limit(2)
            ->get();

        // One session ↔ one attribution; anything else is left unresolved.
        if ($attribution->count() === 1 && $attribution->first()->crm_lead_id === null) {
            $row = $attribution->first();
            $this->applyCrmLead($row, $crmLead);
            $row->save();
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
        $accountId = (int) $lead->account_id;

        if (! $converted) {
            // Leaving `converted` withdraws the conversion AND its value: a value only
            // describes a lead that is currently converted, and reports sum values of
            // converted rows. The previous value stays in the audit trail of the edit.
            AdAttribution::query()->forAccount($accountId)->where('crm_lead_id', $lead->id)
                ->whereNotNull('converted_at')
                ->update(['converted_at' => null, 'conversion_value' => null, 'conversion_currency' => null, 'updated_at' => now()]);

            return;
        }

        // One conversion per lead: credit the earliest referral only, so a lead
        // that somehow carries several attribution rows is never double-counted.
        // Already credited → no-op (repeated status saves are idempotent).
        $rows = AdAttribution::query()->forAccount($accountId)->where('crm_lead_id', $lead->id)
            ->orderBy('referral_received_at')->orderBy('id')->get(['id', 'converted_at']);

        if ($rows->isEmpty() || $rows->whereNotNull('converted_at')->isNotEmpty()) {
            return;
        }

        AdAttribution::query()->whereKey($rows->first()->id)->whereNull('converted_at')
            ->update(['converted_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Phase 10 Task 5 — record (or clear) the monetary value of a converted,
     * attributed CRM lead. The attribution row that holds the lead's single
     * conversion credit (see syncConversion()) is the ONLY owner of the value:
     * no second copy lives on the CRM lead.
     *
     *   - value optional: NULL = unknown (clears value and currency);
     *     0 is a real, valid value and stays 0;
     *   - currency is required when a value exists, forced NULL when it does not;
     *   - tenant scoped: only this account's attribution rows are read or written;
     *   - idempotent: an identical value + currency writes nothing;
     *   - written through the model so the audit trail (actor, time, old/new) records it;
     *   - never derived from spend or any other figure.
     *
     * @param  ?string  $value  normalized "1234.50" (or null)
     * @return array{attribution: AdAttribution, changed: bool}
     *
     * @throws ConversionValueException  no attribution / lead not converted
     */
    public function recordConversionValue(CrmLead $lead, ?string $value, ?string $currency): array
    {
        $accountId = (int) $lead->account_id;

        if ($value === null) {
            $currency = null;
        } elseif ($currency === null || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ConversionValueException('A conversion value needs a 3-letter currency code.', 'CONVERSION_CURRENCY_REQUIRED', 422);
        }

        return DB::transaction(function () use ($accountId, $lead, $value, $currency) {
            $rows = AdAttribution::query()->forAccount($accountId)->where('crm_lead_id', $lead->id)
                ->orderBy('referral_received_at')->orderBy('id')->lockForUpdate()->get();

            if ($rows->isEmpty()) {
                throw new ConversionValueException('This lead has no ad attribution, so there is nothing to record a conversion value against.', 'LEAD_NOT_ATTRIBUTED', 422);
            }

            $credited = $rows->first(fn (AdAttribution $row) => $row->converted_at !== null);

            if ($credited === null) {
                throw new ConversionValueException('Only a converted lead can have a conversion value. Mark the lead as converted first.', 'LEAD_NOT_CONVERTED', 422);
            }

            $credited->conversion_value = $value;
            $credited->conversion_currency = $currency;

            if (! $credited->isDirty(['conversion_value', 'conversion_currency'])) {
                return ['attribution' => $credited, 'changed' => false];
            }

            $credited->save();

            return ['attribution' => $credited->refresh(), 'changed' => true];
        });
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

    private function applyCrmLead(AdAttribution $attribution, CrmLead $crmLead, bool $requireCaptureMatch = false): void
    {
        if ($attribution->crm_lead_id !== null || (int) $crmLead->account_id !== (int) $attribution->account_id) {
            return;
        }

        // A CTWA referral is linked only to the CRM lead promoted from ITS OWN
        // capture row — never to an unrelated lead that merely shares a phone.
        if ($requireCaptureMatch && ($attribution->capture_lead_id === null || (int) $crmLead->capture_lead_id !== (int) $attribution->capture_lead_id)) {
            return;
        }

        $attribution->crm_lead_id = $crmLead->id;
        $attribution->lead_linked_at = now();

        // Single credit per lead (see syncConversion()).
        if ($crmLead->status === CrmLead::STATUS_CONVERTED
            && ! AdAttribution::query()->forAccount((int) $attribution->account_id)->where('crm_lead_id', $crmLead->id)->whereNotNull('converted_at')->exists()) {
            $attribution->converted_at = now();
        }
    }

    /** Merge match diagnostics into metadata.match (explains why a link was or was not made). */
    private function annotate(int $id, array $match): void
    {
        $row = AdAttribution::query()->find($id);

        if (! $row) {
            return;
        }

        $metadata = is_array($row->metadata) ? $row->metadata : [];
        $metadata['match'] = $match + ['at' => now()->toIso8601String()];
        $row->metadata = $metadata;
        $row->save();
    }

    /**
     * The tenant's own launcher campaign for this ad id — exactly one, or none
     * (ambiguity never guesses). Returns [campaign id|null, why].
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function campaignFor(int $accountId, string $provider, ?string $sourceId): array
    {
        if ($provider !== AdAttribution::PROVIDER_META || $sourceId === null) {
            return [null, null];
        }

        $ids = AdCampaign::query()->forAccount($accountId)->where('meta_ad_id', $sourceId)->limit(2)->pluck('id');

        return match ($ids->count()) {
            1 => [(int) $ids->first(), 'ad_id'],
            0 => [null, 'no_launcher_campaign'],
            default => [null, 'ambiguous_launcher_campaigns'],
        };
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
