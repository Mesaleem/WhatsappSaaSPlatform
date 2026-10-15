<?php

namespace App\Console\Commands;

use App\Models\AdAttribution;
use App\Models\AdCampaign;
use App\Models\AdCampaignDailyMetric;
use App\Services\Ads\MetaAdsService;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * Auto-Budget Guard cron: polls every ACTIVE AdCampaign's live Meta
 * Insights, caches the figures, and auto-pauses + alerts the tenant when
 * either rule fires. Registered on the schedule in routes/console.php
 * (Laravel 11 — this app has no app/Console/Kernel.php; see that file
 * for the actual cadence chosen and why).
 *
 * Each campaign is evaluated in its own try/catch so one campaign's
 * failure (Meta unreachable, a stale/expired token, ...) can never stop
 * the rest of the batch from being checked — same per-item isolation
 * discipline as SocialWebhookController::handle()'s per-entry try/catch.
 */
class CheckAdPerformanceRules extends Command
{
    protected $signature = 'ads:check-performance-rules';

    protected $description = 'Poll active Meta ad campaigns and auto-pause + alert on the zero-conversion or CPL-exceeded rules.';

    public function handle(MetaAdsService $service): int
    {
        $campaigns = AdCampaign::query()->active()->with(['account', 'socialAccount'])->get();

        $this->info("Checking {$campaigns->count()} active campaign(s)...");

        foreach ($campaigns as $campaign) {
            try {
                $this->evaluate($campaign, $service);
            } catch (Throwable $e) {
                Log::error("CheckAdPerformanceRules: failed to evaluate AdCampaign #{$campaign->id}.", [
                    'meta_campaign_id' => $campaign->meta_campaign_id,
                    'exception' => $e->getMessage(),
                ]);
                // QA fixes (2026-10-14): spend protection that stopped is announced, not only logged.
                if ($campaign->account !== null) {
                    app(\App\Services\Notifications\AccountAlerts::class)->notify(
                        $campaign->account,
                        'ads-guard-'.$campaign->id,
                        'Ad budget guard paused for '.$campaign->name,
                        "The campaign \"{$campaign->name}\" could not be checked on Meta (the ad account connection may have expired), so it is not protected by the automatic pause rules. Reconnect the ad account in Social Accounts.",
                        'ads',
                        '/social/accounts',
                        'Open Social Accounts',
                        24,
                    );
                }
            }
        }

        return self::SUCCESS;
    }

    private function evaluate(AdCampaign $campaign, MetaAdsService $service): void
    {
        // QA fixes (2026-10-14): a campaign paused, deleted or rejected in Ads Manager is
        // followed here (it stayed ACTIVE and was polled forever).
        // Phase B: past its end date Meta delivers nothing more; stop polling it.
        if ($campaign->hasEnded()) {
            $campaign->forceFill(['status' => AdCampaign::STATUS_PAUSED, 'meta_stop_status' => 'COMPLETED', 'last_checked_at' => now()])->save();

            return;
        }

        $metaStatus = $this->effectiveStatus($campaign);
        if ($metaStatus !== null && ! in_array($metaStatus, ['ACTIVE', 'IN_PROCESS', 'WITH_ISSUES', 'PENDING_REVIEW', 'PREAPPROVED'], true)) {
            // Phase B: remember why, so the list can say "stopped on Meta" instead of a bare "paused".
            $campaign->forceFill(['status' => AdCampaign::STATUS_PAUSED, 'meta_stop_status' => mb_substr($metaStatus, 0, 32), 'last_checked_at' => now()])->save();
            Log::info("CheckAdPerformanceRules: AdCampaign #{$campaign->id} is {$metaStatus} on Meta — marked paused here.");

            return;
        }

        $insights = $service->fetchInsights($campaign);

        // QA fixes (2026-10-14): a Click-to-WhatsApp / Messages campaign is judged by its
        // conversations — the stored figures (dashboard, reports) now say the same thing
        // the rule below uses, instead of 0 leads and no CPL.
        $isMessaging = in_array($campaign->objective, ['CLICK_TO_WHATSAPP', 'MESSAGES'], true);
        if ($isMessaging) {
            $insights['leads'] = max($insights['leads'], $insights['conversations']);
            $insights['cpl'] = $insights['leads'] > 0 ? round($insights['spend'] / $insights['leads'], 2) : null;
        }

        // Cache is written BEFORE the rule check regardless of outcome —
        // the frontend's performance table must reflect the latest known
        // spend/leads/CPL even for a campaign that stays active this cycle.
        $campaign->forceFill([
            'last_spend' => $insights['spend'],
            'last_impressions' => $insights['impressions'],
            'last_leads' => $insights['leads'],
            'last_cpl' => $insights['cpl'],
            'last_checked_at' => now(),
        ])->save();

        // Social Media Marketing & Meta Ads Automation Expansion — Final
        // Phase. Daily history snapshot for SocialReportController's
        // monthly PDF/summary — see ad_campaign_daily_metrics' creating
        // migration for why this is necessary (the columns above are
        // TODAY-only and get overwritten on every tick, so nothing else
        // in this schema can answer a "this month" query). updateOrCreate
        // on (ad_campaign_id, metric_date) makes repeated ticks within
        // the same day idempotent — this OVERWRITES today's row with
        // today's latest cumulative figures each time, it does not add
        // them up across ticks (Meta's date_preset=today insight is
        // itself already a running total for the day, so summing ticks
        // would double count).
        AdCampaignDailyMetric::updateOrCreate(
            [
                'ad_campaign_id' => $campaign->id,
                'metric_date' => now()->toDateString(),
            ],
            [
                'account_id' => $campaign->account_id,
                'spend' => $insights['spend'],
                'impressions' => $insights['impressions'],
                'leads' => $insights['leads'],
                'cpl' => $insights['cpl'],
            ]
        );

        $dailyBudget = (float) $campaign->daily_budget;
        $threshold = $campaign->cpl_threshold !== null ? (float) $campaign->cpl_threshold : null;

        // Phase 10 Task 6 — what counts as a "conversion" depends on the campaign's goal. A Click-to-WhatsApp /
        // Messages campaign produces messaging conversations, never Meta Lead Ads form leads, so judging it by
        // `lead` actions alone paused healthy CTWA campaigns as "zero conversions". For those objectives the
        // result is Meta's messaging conversations (same window as spend) and — for the zero-conversion rule
        // only, as a conservative guard — CTWA referrals stored for THIS campaign in the last 24 h. Every other
        // objective keeps the original Lead Ads rule unchanged.
        $messaging = in_array($campaign->objective, ['CLICK_TO_WHATSAPP', 'MESSAGES'], true);
        $results = $messaging ? max($insights['leads'], $insights['conversations']) : $insights['leads'];
        $cpl = $messaging ? ($results > 0 ? round($insights['spend'] / $results, 2) : null) : $insights['cpl'];
        $insights['cpl'] = $cpl;
        $recentReferrals = $messaging && $results === 0
            ? AdAttribution::query()->forAccount((int) $campaign->account_id)->where('ad_campaign_id', $campaign->id)
                ->where('referral_received_at', '>=', now()->subDay())->exists()
            : false;

        // QA fixes (2026-10-14): Meta's pacing rarely spends MORE than the daily budget, so
        // "zero conversions" fires once 90% of it is spent.
        $zeroConversionOverBudget = $dailyBudget > 0 && $insights['spend'] >= 0.9 * $dailyBudget && $results === 0 && ! $recentReferrals;
        $cplExceeded = $threshold !== null && $cpl !== null && $cpl > $threshold;

        if (! $zeroConversionOverBudget && ! $cplExceeded) {
            return;
        }

        $cur = $this->currencySymbol($campaign);
        $reason = $zeroConversionOverBudget
            ? sprintf('Zero conversions after %s%.2f spend (daily budget %s%.2f).', $cur, $insights['spend'], $cur, $dailyBudget)
            : sprintf('CPL %s%.2f exceeded the %s%.2f threshold.', $cur, (float) $cpl, $cur, $threshold);

        // Pause on Meta FIRST — only record the local PAUSED status once
        // Meta has actually confirmed it. If this throws, the campaign
        // stays ACTIVE here too (correct: it's still active on Meta), the
        // exception propagates to handle()'s catch, and the next cycle
        // (15 min later) tries again — never a false-positive "paused"
        // state while real spend continues unchecked on Meta's side.
        //
        // Phase 10 Task 2 — through the same guarded transition as a manual
        // pause (one change at a time; status written only after Meta
        // confirms, only from ACTIVE). A campaign someone already paused is
        // not paused (or alerted) twice. A pause whose outcome is unknown is
        // not retried here; the next cycle re-evaluates fresh insights.
        if (! $service->changeStatus($campaign, AdCampaign::STATUS_PAUSED, $reason)) {
            return;
        }

        $this->sendAlert($campaign, $insights, $zeroConversionOverBudget, $threshold);
    }

    /**
     * @param array{spend: float, impressions: int, leads: int, conversations: int, cpl: float|null} $insights
     */
    private function sendAlert(AdCampaign $campaign, array $insights, bool $zeroConversionOverBudget, ?float $threshold): void
    {
        $account = $campaign->account;
        $cur = $this->currencySymbol($campaign);

        // QA fixes (2026-10-14): in-app + email to the client, always — the WhatsApp text
        // below needs a primary phone and, on the Meta API, an open 24-hour window.
        if ($account !== null) {
            app(\App\Services\Notifications\AccountAlerts::class)->notify(
                $account,
                'ads-paused-'.$campaign->id.'-'.now()->toDateString(),
                'Campaign paused: '.$campaign->name,
                $zeroConversionOverBudget
                    ? sprintf('"%s" was paused automatically: no conversions after %s%.2f spend today.', $campaign->name, $cur, $insights['spend'])
                    : sprintf('"%s" was paused automatically: cost per lead %s%.2f is above your %s%.2f limit.', $campaign->name, $cur, (float) $insights['cpl'], $cur, (float) $threshold),
                'ads',
                '/social/ads/dashboard',
                'Open Ads Dashboard',
                24,
            );
            if ($account->currentSubscription?->engine_type !== 'qr') {
                return;
            }
        }

        if (! $account || ! $account->primary_phone) {
            Log::warning("CheckAdPerformanceRules: no primary_phone on Account for AdCampaign #{$campaign->id} — alert not sent.");

            return;
        }

        $normalized = PhoneNumberNormalizer::normalize($account->primary_phone);

        if ($normalized === '') {
            Log::warning("CheckAdPerformanceRules: Account #{$account->id}'s primary_phone did not normalize — alert not sent.");

            return;
        }

        $message = $zeroConversionOverBudget
            ? sprintf(
                "\xE2\x9A\xA0\xEF\xB8\x8F Campaign %s was automatically paused due to Zero Conversions after %s%.2f Spend.",
                $campaign->name,
                $cur,
                $insights['spend']
            )
            : sprintf(
                "\xE2\x9A\xA0\xEF\xB8\x8F Campaign %s was automatically paused due to high CPL (%s%.2f).",
                $campaign->name,
                $cur,
                (float) $insights['cpl']
            );

        // Best-effort — a failed WhatsApp send must NOT undo the pause
        // that already happened on Meta and locally above.
        try {
            $account->loadMissing(['currentSubscription', 'whatsAppSession']);

            if (! $account->hasActiveSubscription()) {
                Log::warning("CheckAdPerformanceRules: Account #{$account->id} has no active WhatsApp subscription — alert not sent.");

                return;
            }

            $driver = WhatsAppEngineFactory::make($account);
            $result = $driver->sendMessage($normalized, $message);

            if (empty($result['success'])) {
                Log::warning("CheckAdPerformanceRules: WhatsApp alert failed for AdCampaign #{$campaign->id}.", [
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        } catch (RuntimeException $e) {
            Log::warning("CheckAdPerformanceRules: could not send WhatsApp alert for AdCampaign #{$campaign->id}.", [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * QA fixes (2026-10-14) — the campaign's own status on Meta (effective_status), or
     * null when it cannot be read (then the guard carries on as before).
     */
    private function effectiveStatus(AdCampaign $campaign): ?string
    {
        $token = $campaign->socialAccount?->access_token;
        if (! $token || ! $campaign->meta_campaign_id) {
            return null;
        }
        try {
            $response = \Illuminate\Support\Facades\Http::withToken((string) $token)->timeout(10)
                ->get('https://graph.facebook.com/v19.0/'.$campaign->meta_campaign_id, ['fields' => 'effective_status']);
        } catch (Throwable) {
            return null;
        }
        $status = $response->successful() ? $response->json('effective_status') : null;

        return is_string($status) && $status !== '' ? strtoupper($status) : null;
    }

    /** QA fixes (2026-10-14): the ad account's currency (it was always "$"). */
    private function currencySymbol(AdCampaign $campaign): string
    {
        $metadata = $campaign->socialAccount?->metadata;
        $currency = strtoupper((string) (is_array($metadata) ? ($metadata['currency'] ?? 'INR') : 'INR'));

        return match ($currency) {
            'INR' => '₹',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $currency.' ',
        };
    }
}
