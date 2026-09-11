<?php

namespace App\Console\Commands;

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
            }
        }

        return self::SUCCESS;
    }

    private function evaluate(AdCampaign $campaign, MetaAdsService $service): void
    {
        $insights = $service->fetchInsights($campaign);

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

        $zeroConversionOverBudget = $insights['spend'] > $dailyBudget && $insights['leads'] === 0;
        $cplExceeded = $threshold !== null && $insights['cpl'] !== null && $insights['cpl'] > $threshold;

        if (! $zeroConversionOverBudget && ! $cplExceeded) {
            return;
        }

        $reason = $zeroConversionOverBudget
            ? sprintf('Zero conversions after $%.2f spend (daily budget $%.2f).', $insights['spend'], $dailyBudget)
            : sprintf('CPL $%.2f exceeded the $%.2f threshold.', (float) $insights['cpl'], $threshold);

        // Pause on Meta FIRST — only record the local PAUSED status once
        // Meta has actually confirmed it. If this throws, the campaign
        // stays ACTIVE here too (correct: it's still active on Meta), the
        // exception propagates to handle()'s catch, and the next cycle
        // (15 min later) tries again — never a false-positive "paused"
        // state while real spend continues unchecked on Meta's side.
        $service->pause($campaign);

        $campaign->forceFill([
            'status' => AdCampaign::STATUS_PAUSED,
            'auto_paused_at' => now(),
            'auto_pause_reason' => $reason,
        ])->save();

        $this->sendAlert($campaign, $insights, $zeroConversionOverBudget, $threshold);
    }

    /**
     * @param array{spend: float, impressions: int, leads: int, cpl: float|null} $insights
     */
    private function sendAlert(AdCampaign $campaign, array $insights, bool $zeroConversionOverBudget, ?float $threshold): void
    {
        $account = $campaign->account;

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
                "\xE2\x9A\xA0\xEF\xB8\x8F Campaign %s was automatically paused due to Zero Conversions after $%.2f Spend.",
                $campaign->name,
                $insights['spend']
            )
            : sprintf(
                "\xE2\x9A\xA0\xEF\xB8\x8F Campaign %s was automatically paused due to high CPL ($%.2f).",
                $campaign->name,
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
}
