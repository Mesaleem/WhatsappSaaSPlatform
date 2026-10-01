<?php

namespace App\Services\Ads;

use App\Models\Account;
use App\Models\AdCampaign;
use App\Models\SocialAccount;
use App\Models\WhatsAppSession;
use App\Services\Ads\Exceptions\AdCampaignConflict;
use App\Services\Ads\Exceptions\AdProviderOutcomeUnknown;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;
use App\Services\SocialAuth\SocialConnectionService;
use RuntimeException;
use Throwable;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * Meta Marketing API wrapper: launches a Campaign -> AdSet -> AdCreative
 * -> Ad chain for a tenant's connected Ad Account, and the pause/resume/
 * insights calls CheckAdPerformanceRules uses afterward.
 *
 * DISCLOSED — NOT INTEGRATION-TESTED: this environment has no live Meta
 * Business Manager / Ad Account / payment method to launch a real
 * campaign against, and launching one for real spends the tenant's ad
 * budget. Every request shape below follows Meta's PUBLISHED Marketing
 * API v19.0 contract as accurately as documentation allows, but is
 * [Hypothesis] until verified against a live call — see the Phase 3
 * audit report for the specific mapping decisions this class makes and
 * why. Structural correctness (types, required fields per this app's own
 * data, error handling) is verified; Meta accepting the exact payload
 * shape at runtime is not.
 *
 * DISCLOSED SCOPE GAP: `ads_management` was not part of MetaOAuthProvider's
 * original SCOPES (Phase 1 deliberately deferred it to "the Ad Launcher,
 * a later phase" — see that class's docblock). This phase adds it. A
 * tenant who connected their Ad Account BEFORE this change has an
 * access_token that was never granted `ads_management` — Meta will
 * reject campaign-mutating calls for that token with an OAuthException
 * until the tenant reconnects (disconnect + Connect Meta Account again)
 * to re-consent under the new scope. This cannot be worked around
 * silently; it is a real re-auth requirement, flagged here and in the
 * audit report rather than assumed away.
 */
class MetaAdsService
{
    private const API_VERSION = 'v19.0';

    /**
     * User-facing objective (this API's own vocabulary, see AdCampaign::
     * OBJECTIVES) -> Meta's ODAX campaign `objective`. Meta stopped
     * accepting legacy per-goal objectives (LEAD_GENERATION, MESSAGES,
     * LINK_CLICKS, ...) as top-level campaign objectives for most ad
     * accounts from 2022 onward, replacing them with six outcome-based
     * OUTCOME_* values; the specific goal now lives one level down, on
     * the AdSet's `optimization_goal` (see OPTIMIZATION_GOAL_MAP).
     * [Hypothesis] — documented Meta behavior, not verified live.
     */
    private const OBJECTIVE_MAP = [
        'LEAD_GENERATION' => 'OUTCOME_LEADS',
        'MESSAGES' => 'OUTCOME_ENGAGEMENT',
        'TRAFFIC' => 'OUTCOME_TRAFFIC',
        // Step 4 (Click-to-WhatsApp Ads). Pre-ODAX, Click-to-WhatsApp used
        // the same legacy 'MESSAGES' objective as Click-to-Messenger, only
        // the AdSet's destination_type differed (WHATSAPP vs MESSENGER) —
        // [Hypothesis], documented Meta precedent. Mapped to the SAME
        // OUTCOME_ENGAGEMENT ODAX objective as MESSAGES for that reason.
        'CLICK_TO_WHATSAPP' => 'OUTCOME_ENGAGEMENT',
    ];

    /** AdSet-level optimization_goal per objective. [Hypothesis], see class docblock. */
    private const OPTIMIZATION_GOAL_MAP = [
        'LEAD_GENERATION' => 'LEAD_GENERATION',
        'MESSAGES' => 'CONVERSATIONS',
        'TRAFFIC' => 'LINK_CLICKS',
        // Step 4: a WhatsApp chat opened from an ad is the same
        // "CONVERSATIONS" optimization concept Meta already uses for
        // Messenger — [Hypothesis], see OBJECTIVE_MAP's note above.
        'CLICK_TO_WHATSAPP' => 'CONVERSATIONS',
    ];

    private const BILLING_EVENT = 'IMPRESSIONS';

    /**
     * Owner request (2026-09-30) — manual placements. Key => [publisher
     * platform, position] per Meta's AdSet targeting fields
     * publisher_platforms / facebook_positions / instagram_positions.
     * [Fact] documented position values: feed, story, facebook_reels
     * (Facebook) and stream, story, reels (Instagram). No placements =
     * Advantage+ (automatic) placements, which already include them all.
     */
    public const PLACEMENTS = [
        'facebook_feed' => ['facebook', 'feed'],
        'facebook_stories' => ['facebook', 'story'],
        'facebook_reels' => ['facebook', 'facebook_reels'],
        'instagram_feed' => ['instagram', 'stream'],
        'instagram_stories' => ['instagram', 'story'],
        'instagram_reels' => ['instagram', 'reels'],
    ];

    public const LOCATION_TYPES = ['country', 'region', 'city'];

    /**
     * Owner request (2026-09-30) — the ad's button (Meta call_to_action.type)
     * is chosen per campaign instead of being fixed per objective. First entry
     * = the default (the previous fixed value). [Hypothesis] per-objective
     * compatibility follows Meta's documented CTA lists; Meta stays the final
     * validator. Click-to-WhatsApp requires WHATSAPP_MESSAGE.
     */
    public const CALL_TO_ACTIONS = [
        'LEAD_GENERATION' => ['SIGN_UP', 'LEARN_MORE', 'APPLY_NOW', 'GET_QUOTE', 'SUBSCRIBE', 'DOWNLOAD', 'GET_OFFER', 'BOOK_TRAVEL', 'CONTACT_US'],
        'TRAFFIC' => ['LEARN_MORE', 'SHOP_NOW', 'SIGN_UP', 'APPLY_NOW', 'GET_QUOTE', 'BOOK_TRAVEL', 'CONTACT_US', 'DOWNLOAD', 'GET_OFFER', 'SUBSCRIBE'],
        'MESSAGES' => ['LEARN_MORE', 'MESSAGE_PAGE', 'SHOP_NOW'],
        'CLICK_TO_WHATSAPP' => ['WHATSAPP_MESSAGE'],
    ];

    /**
     * Meta amounts (daily_budget, amount_spent, spend_cap, balance) are in
     * the ad account currency's smallest unit — 100 per unit for most
     * currencies (INR paise), 1 for these. [Fact] Meta "currency offset"
     * table (Marketing API currencies reference).
     */
    private const ZERO_DECIMAL_CURRENCIES = ['CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'JPY', 'KRW', 'PYG', 'TWD', 'VND'];

    /** Meta ad account_status values a campaign can run under (1 ACTIVE, 9 IN_GRACE_PERIOD). */
    private const RUNNABLE_ACCOUNT_STATUSES = [1, 9];

    private const ACCOUNT_STATUS_LABELS = [
        1 => 'active', 2 => 'disabled', 3 => 'unsettled (payment due)', 7 => 'pending risk review', 8 => 'pending settlement',
        9 => 'in grace period', 100 => 'pending closure', 101 => 'closed',
    ];

    /**
     * Launches Campaign -> AdSet -> AdCreative -> Ad on Meta for the given
     * tenant Account.
     *
     * Phase 10 Task 2 — lifecycle hardening (replaces the earlier "persist only
     * after all four calls succeed" rule, which lost every failed or lost
     * launch):
     *   1. Everything checkable locally (Ad Account, token, connection health,
     *      objective, Page, CTWA number) is checked BEFORE any Meta write, so a
     *      missing prerequisite can no longer orphan a campaign at Meta.
     *   2. A LAUNCHING row is recorded before the first Meta write; each Meta id
     *      is stored on it as soon as Meta returns it.
     *   3. Success -> ACTIVE. A Meta rejection -> FAILED (objects already
     *      created stay on the row for manual review). No response / 5xx ->
     *      UNCONFIRMED and AdProviderOutcomeUnknown — never resent.
     *   4. $requestKey (the caller's Idempotency-Key) is unique per tenant: a
     *      repeat returns the stored row ($replayed = true) without calling Meta.
     *
     * @param array{
     *   campaign_name: string,
     *   objective: string,
     *   daily_budget: float,
     *   cpl_threshold?: float|null,
     *   targeting_specs: array{countries: list<string>, age_min: int, age_max: int, interests?: list<string>},
     *   creative: array{image_url: string|null, headline: string, primary_text: string}
     * } $payload
     */
    public function launch(Account $account, array $payload, ?string $requestKey = null, ?bool &$replayed = null): AdCampaign
    {
        $replayed = false;

        if ($requestKey !== null && ($existing = $this->findLaunch($account, $requestKey))) {
            $replayed = true;

            return $existing;
        }

        $adAccount = $this->resolveAdAccount($account);
        $page = $this->resolvePage($account);
        $accessToken = $adAccount->access_token;

        if (! $accessToken) {
            throw new RuntimeException('The connected Meta Ad Account has no stored access token. Please reconnect it.');
        }

        // Phase 9 Task 2 — an Ad Account already known to be expired/revoked
        // is refused before any Meta call (SocialConnectionException → 409).
        $connections = app(SocialConnectionService::class);
        $connections->assertUsable($adAccount, 'ads.launch');

        $whatsAppNumber = $this->preflight($account, $payload['objective'], $page, $payload['creative']['call_to_action'] ?? null);

        // Owner request (2026-09-30): budget in the ad account's own currency,
        // and refuse up front when the ad account cannot run the budget. A
        // read-only probe; when Meta cannot be asked, the launch proceeds as
        // before (offset 100) and Meta remains the final judge.
        $info = $this->probeAdAccount($adAccount, $accessToken);
        $this->assertBudgetFits($info, (float) $payload['daily_budget']);
        $payload['_currency_offset'] = self::currencyOffset($info['currency'] ?? null);
        $payload['_instagram_actor_id'] = $this->instagramActorFor($account, $payload['placements'] ?? []);

        $campaign = $this->recordLaunch($account, $adAccount, $payload + ['_currency' => $info['currency'] ?? null], $requestKey);

        if ($campaign->wasRecentlyCreated === false) {
            $replayed = true;

            return $campaign;
        }

        try {
            return $this->launchWith($account, $campaign, $payload, $adAccount, $page, $accessToken, $whatsAppNumber);
        } catch (AdProviderOutcomeUnknown $e) {
            $this->settleLaunch($campaign, AdCampaign::STATUS_UNCONFIRMED, 'Meta did not confirm the launch (no response). It was not resent — check Meta Ads Manager.');
            Log::warning('MetaAdsService::launch() outcome unknown — not retried.', ['account_id' => $account->id, 'ad_campaign_id' => $campaign->id]);
            $e->campaign = $campaign;

            throw $e;
        } catch (ProviderRequestFailed $e) {
            // Meta rejected a call: if it means the Ad Account connection
            // itself is expired/revoked, that is persisted and reported as
            // SocialConnectionException; any other rejection is re-thrown as is.
            $this->settleLaunch($campaign, AdCampaign::STATUS_FAILED, self::safeProviderError('launch', $e));
            $connections->escalate($adAccount, $e, 'ads.launch');
        } catch (Throwable $e) {
            $this->settleLaunch($campaign, AdCampaign::STATUS_FAILED, 'The launch stopped before Meta confirmed every step.');

            throw $e;
        }
    }

    /** The stored launch for this tenant's Idempotency-Key, if any. */
    public function findLaunch(Account $account, string $requestKey): ?AdCampaign
    {
        return AdCampaign::query()->forAccount($account->id)->where('launch_request_id', $requestKey)->first();
    }

    /**
     * Local prerequisites the Meta chain needs, checked before the first Meta
     * write (same messages as the in-chain checks they front-run).
     */
    private function preflight(Account $account, string $objective, ?SocialAccount $page, ?string $cta = null): ?string
    {
        if (! isset(self::OBJECTIVE_MAP[$objective])) {
            throw new RuntimeException("Unsupported objective '{$objective}'.");
        }

        if ($cta !== null && ! in_array($cta, self::CALL_TO_ACTIONS[$objective], true)) {
            throw new RuntimeException("The button '{$cta}' is not available for this objective.");
        }

        if (! $page) {
            throw new RuntimeException(in_array($objective, ['LEAD_GENERATION', 'MESSAGES', 'CLICK_TO_WHATSAPP'], true)
                ? "A connected Facebook Page is required to launch a {$objective} campaign. Connect one from the Social Hub first."
                : 'A connected Facebook Page is required to build ad creative. Connect one from the Social Hub first.');
        }

        return $objective === 'CLICK_TO_WHATSAPP' ? $this->resolveWhatsAppPhoneNumber($account) : null;
    }

    /** The LAUNCHING row, or the row a concurrent request with the same key already recorded. */
    private function recordLaunch(Account $account, SocialAccount $adAccount, array $payload, ?string $requestKey): AdCampaign
    {
        try {
            return AdCampaign::create([
                'account_id' => $account->id,
                'social_account_id' => $adAccount->id,
                'launch_request_id' => $requestKey,
                'name' => $payload['campaign_name'],
                'objective' => $payload['objective'],
                'status' => AdCampaign::STATUS_LAUNCHING,
                'daily_budget' => $payload['daily_budget'],
                'currency' => $payload['_currency'] ?? null,
                'cpl_threshold' => $payload['cpl_threshold'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if ($requestKey !== null && ($existing = $this->findLaunch($account, $requestKey))) {
                return $existing;
            }

            throw $e;
        }
    }

    private function settleLaunch(AdCampaign $campaign, string $status, string $error): void
    {
        AdCampaign::query()->whereKey($campaign->id)->where('status', AdCampaign::STATUS_LAUNCHING)->update([
            'status' => $status,
            'last_provider_error' => mb_substr($error, 0, 255),
            'last_provider_error_at' => now(),
            'updated_at' => now(),
        ]);

        $campaign->refresh();
    }

    private function launchWith(Account $account, AdCampaign $campaign, array $payload, SocialAccount $adAccount, SocialAccount $page, string $accessToken, ?string $whatsAppNumber): AdCampaign
    {
        $objective = $payload['objective'];
        $metaObjective = self::OBJECTIVE_MAP[$objective];

        $metaCampaignId = $this->createCampaign($adAccount->provider_id, $accessToken, $payload['campaign_name'], $metaObjective);
        $campaign->forceFill(['meta_campaign_id' => $metaCampaignId])->save();

        try {
            $metaAdsetId = $this->createAdSet(
                $adAccount->provider_id,
                $accessToken,
                $metaCampaignId,
                $objective,
                $payload,
                $page,
                $whatsAppNumber
            );
            $campaign->forceFill(['meta_adset_id' => $metaAdsetId])->save();

            $creativeId = $this->createAdCreative(
                $adAccount->provider_id,
                $accessToken,
                $page,
                $payload['creative'],
                $objective,
                $payload['_instagram_actor_id'] ?? null
            );

            $metaAdId = $this->createAd(
                $adAccount->provider_id,
                $accessToken,
                $metaAdsetId,
                $creativeId,
                $payload['campaign_name']
            );
        } catch (Throwable $e) {
            Log::error('MetaAdsService::launch() failed after campaign creation — orphaned Meta objects require manual review.', [
                'account_id' => $account->id,
                'ad_campaign_id' => $campaign->id,
                'meta_campaign_id' => $metaCampaignId,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        AdCampaign::query()->whereKey($campaign->id)->where('status', AdCampaign::STATUS_LAUNCHING)->update([
            'meta_ad_id' => $metaAdId,
            'status' => AdCampaign::STATUS_ACTIVE,
            'updated_at' => now(),
        ]);

        return $campaign->refresh();
    }

    /** Kept for compatibility — both go through changeStatus()'s guarded transition. */
    public function pause(AdCampaign $campaign): void
    {
        $this->changeStatus($campaign, AdCampaign::STATUS_PAUSED);
    }

    public function resume(AdCampaign $campaign): void
    {
        $this->changeStatus($campaign, AdCampaign::STATUS_ACTIVE);
    }

    /**
     * Phase 10 Task 2 — the ONE place a campaign's Meta status is changed
     * (manual pause/resume and the auto-pause rule).
     *   - one change per campaign at a time (cache lock; a concurrent one → 409 AD_CAMPAIGN_BUSY);
     *   - already in the target state → no Meta call, returns false;
     *   - only ACTIVE → PAUSED and PAUSED → ACTIVE (anything else → 409 AD_CAMPAIGN_INVALID_STATE);
     *   - the local status changes only after Meta confirms, and only from the state it was read in;
     *   - Meta reports the campaign gone → UNAVAILABLE (409 AD_CAMPAIGN_UNAVAILABLE);
     *   - no response / 5xx → status unchanged, AdProviderOutcomeUnknown, never retried here.
     *
     * @return bool true when the status was changed, false when it already was $target
     */
    public function changeStatus(AdCampaign $campaign, string $target, ?string $autoPauseReason = null): bool
    {
        $from = match ($target) {
            AdCampaign::STATUS_PAUSED => AdCampaign::STATUS_ACTIVE,
            AdCampaign::STATUS_ACTIVE => AdCampaign::STATUS_PAUSED,
            default => throw new RuntimeException("Unsupported campaign status '{$target}'."),
        };

        $lock = Cache::lock("ad-campaign-status:{$campaign->id}", 60);

        if (! $lock->get()) {
            throw new AdCampaignConflict('Another change to this campaign is in progress. Try again in a moment.', 'AD_CAMPAIGN_BUSY', 409, $campaign);
        }

        try {
            $campaign->refresh();

            if ($campaign->status === $target) {
                return false;
            }

            if ($campaign->status !== $from || ! $campaign->meta_campaign_id) {
                throw new AdCampaignConflict(
                    $campaign->status === AdCampaign::STATUS_UNAVAILABLE
                        ? 'Meta reports this campaign no longer exists or cannot be loaded, so it cannot be changed here.'
                        : "A campaign in status {$campaign->status} cannot be ".($target === AdCampaign::STATUS_PAUSED ? 'paused' : 'resumed').'.',
                    $campaign->status === AdCampaign::STATUS_UNAVAILABLE ? 'AD_CAMPAIGN_UNAVAILABLE' : 'AD_CAMPAIGN_INVALID_STATE',
                    409,
                    $campaign,
                );
            }

            $this->setCampaignStatus($campaign, $target);

            $changes = ['status' => $target, 'last_provider_error' => null, 'last_provider_error_at' => null, 'updated_at' => now()];
            $changes += $target === AdCampaign::STATUS_ACTIVE
                ? ['auto_paused_at' => null, 'auto_pause_reason' => null]
                : ($autoPauseReason !== null ? ['auto_paused_at' => now(), 'auto_pause_reason' => $autoPauseReason] : []);

            AdCampaign::query()->whereKey($campaign->id)->where('status', $from)->update($changes);
            $campaign->refresh();

            return true;
        } finally {
            $lock->release();
        }
    }

    // ================================================================ owner request 2026-09-30

    /**
     * The target's Meta ad account: name, currency, status and money figures
     * (amount spent, spend cap, balance) in MAJOR units of its own currency.
     * A read — nothing is changed at Meta.
     *
     * @return array{name: ?string, currency: ?string, account_status: ?int, account_status_label: ?string, runnable: ?bool, amount_spent: ?float, spend_cap: ?float, remaining_spend_cap: ?float, balance: ?float}
     */
    public function adAccountInfo(Account $account): array
    {
        $adAccount = $this->resolveAdAccount($account);
        $accessToken = $adAccount->access_token ?: throw new RuntimeException('The connected Meta Ad Account has no stored access token. Please reconnect it.');
        $connections = app(SocialConnectionService::class);
        $connections->assertUsable($adAccount, 'ads.account');

        try {
            $response = Http::withToken($accessToken)->timeout(15)->get(
                'https://graph.facebook.com/'.self::API_VERSION."/{$adAccount->provider_id}",
                ['fields' => 'name,currency,account_status,amount_spent,spend_cap,balance']
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach Meta to read the ad account.', previous: $e);
        }

        if ($response->failed()) {
            $connections->escalate($adAccount, ProviderRequestFailed::fromResponse($response, 'Meta rejected the ad account request.'), 'ads.account');
        }

        return self::normalizeAccountInfo($response->json() ?? []);
    }

    /**
     * Meta location search (Targeting Search API, type=adgeolocation) for the
     * launch form — countries, regions (states) and cities, each with the key
     * Meta targets by. A read.
     *
     * @return list<array{key: string, name: string, type: string, country_code: ?string, country_name: ?string, region: ?string}>
     */
    public function searchLocations(Account $account, string $query, int $limit = 10): array
    {
        $adAccount = $this->resolveAdAccount($account);
        $accessToken = $adAccount->access_token ?: throw new RuntimeException('The connected Meta Ad Account has no stored access token. Please reconnect it.');
        $connections = app(SocialConnectionService::class);
        $connections->assertUsable($adAccount, 'ads.locations');

        try {
            $response = Http::withToken($accessToken)->timeout(10)->get('https://graph.facebook.com/'.self::API_VERSION.'/search', [
                'type' => 'adgeolocation',
                'q' => $query,
                'location_types' => json_encode(self::LOCATION_TYPES),
                'limit' => $limit,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach Meta to search locations.', previous: $e);
        }

        if ($response->failed()) {
            $connections->escalate($adAccount, ProviderRequestFailed::fromResponse($response, 'Meta rejected the location search.'), 'ads.locations');
        }

        return collect($response->json('data') ?? [])
            ->filter(fn ($row) => is_array($row) && isset($row['key'], $row['name'], $row['type']) && in_array($row['type'], self::LOCATION_TYPES, true))
            ->map(fn (array $row) => [
                'key' => (string) $row['key'],
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'country_code' => isset($row['country_code']) ? (string) $row['country_code'] : null,
                'country_name' => isset($row['country_name']) ? (string) $row['country_name'] : null,
                'region' => isset($row['region']) ? (string) $row['region'] : null,
            ])
            ->values()->all();
    }

    /** Best-effort read used by launch(); null when Meta cannot be asked (the launch then proceeds as before). */
    private function probeAdAccount(SocialAccount $adAccount, string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)->timeout(10)->get(
                'https://graph.facebook.com/'.self::API_VERSION."/{$adAccount->provider_id}",
                ['fields' => 'name,currency,account_status,amount_spent,spend_cap,balance']
            );
        } catch (Throwable $e) {
            Log::warning('MetaAdsService: ad account probe unreachable before launch.', ['social_account_id' => $adAccount->id]);

            return null;
        }

        if ($response->failed() || ! is_array($response->json()) || ! isset($response->json()['currency'])) {
            return null;
        }

        return self::normalizeAccountInfo($response->json());
    }

    private function assertBudgetFits(?array $info, float $dailyBudget): void
    {
        if ($info === null) {
            return;
        }

        if ($info['runnable'] === false) {
            throw new RuntimeException("The connected Meta ad account is {$info['account_status_label']}, so campaigns cannot run. Resolve it in Meta Ads Manager first.");
        }

        if ($info['remaining_spend_cap'] !== null && $dailyBudget > $info['remaining_spend_cap']) {
            throw new RuntimeException(sprintf(
                'The daily budget (%s %s) is more than the %s %s left before the ad account reaches its spend cap. Lower the budget or raise the spend cap in Meta Ads Manager.',
                $info['currency'], number_format($dailyBudget, 2), $info['currency'], number_format($info['remaining_spend_cap'], 2),
            ));
        }
    }

    /** @param array<string, mixed> $raw */
    private static function normalizeAccountInfo(array $raw): array
    {
        $currency = isset($raw['currency']) ? strtoupper((string) $raw['currency']) : null;
        $offset = self::currencyOffset($currency);
        $money = fn (string $key) => isset($raw[$key]) && is_numeric($raw[$key]) ? round(((float) $raw[$key]) / $offset, 2) : null;
        $status = isset($raw['account_status']) && is_numeric($raw['account_status']) ? (int) $raw['account_status'] : null;
        $spendCap = $money('spend_cap');
        $spendCap = $spendCap !== null && $spendCap > 0 ? $spendCap : null; // 0 = no cap
        $spent = $money('amount_spent');

        return [
            'name' => isset($raw['name']) ? (string) $raw['name'] : null,
            'currency' => $currency,
            'account_status' => $status,
            'account_status_label' => $status !== null ? (self::ACCOUNT_STATUS_LABELS[$status] ?? "status {$status}") : null,
            'runnable' => $status !== null ? in_array($status, self::RUNNABLE_ACCOUNT_STATUSES, true) : null,
            'amount_spent' => $spent,
            'spend_cap' => $spendCap,
            'remaining_spend_cap' => $spendCap !== null ? max(0.0, round($spendCap - ($spent ?? 0.0), 2)) : null,
            'balance' => $money('balance'),
        ];
    }

    public static function currencyOffset(?string $currency): int
    {
        return $currency !== null && in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100;
    }

    /** @param array<string, mixed> $targeting */
    private static function geoLocations(array $targeting): array
    {
        $countries = array_values(array_filter((array) ($targeting['countries'] ?? []), fn ($c) => is_string($c) && $c !== ''));
        $geo = [];

        foreach ((array) ($targeting['locations'] ?? []) as $location) {
            match ($location['type'] ?? null) {
                'country' => $countries[] = strtoupper((string) $location['key']),
                'region' => $geo['regions'][] = ['key' => (string) $location['key']],
                'city' => $geo['cities'][] = ['key' => (string) $location['key']],
                default => null,
            };
        }

        if ($countries !== []) {
            $geo['countries'] = array_values(array_unique($countries));
        }

        return $geo;
    }

    /** @param list<string> $placements */
    private static function placementTargeting(array $placements): array
    {
        if ($placements === []) {
            return [];
        }

        $targeting = [];
        foreach ($placements as $placement) {
            [$platform, $position] = self::PLACEMENTS[$placement];
            $targeting['publisher_platforms'][] = $platform;
            $targeting["{$platform}_positions"][] = $position;
        }
        $targeting['publisher_platforms'] = array_values(array_unique($targeting['publisher_platforms']));

        return $targeting;
    }

    /** The tenant's connected Instagram business account, when the ad may run on Instagram. */
    private function instagramActorFor(Account $account, array $placements): ?string
    {
        $mayRunOnInstagram = $placements === [] || collect($placements)->contains(fn ($p) => str_starts_with((string) $p, 'instagram_'));

        return $mayRunOnInstagram
            ? SocialAccount::query()->forAccount($account->id)->ofAssetType('instagram')->value('provider_id')
            : null;
    }

    /**
     * @return array{spend: float, impressions: int, leads: int, cpl: float|null}
     */
    public function fetchInsights(AdCampaign $campaign): array
    {
        $accessToken = $campaign->socialAccount?->access_token;

        if (! $accessToken) {
            throw new RuntimeException("AdCampaign #{$campaign->id} has no reachable Ad Account access token.");
        }

        // Phase 9 Task 2 — a dead connection is not polled every cycle.
        app(SocialConnectionService::class)->assertUsable($campaign->socialAccount, 'ads.insights');

        try {
            $response = Http::withToken($accessToken)->timeout(15)->get(
                'https://graph.facebook.com/'.self::API_VERSION."/{$campaign->meta_campaign_id}/insights",
                [
                    // 'actions' carries the breakdown of conversion events
                    // (leadgen, link_click, ...) this campaign generated —
                    // leads are extracted from it by action_type below,
                    // since Meta's Insights API has no first-class 'leads'
                    // field, only the generic 'actions' array.
                    'fields' => 'spend,impressions,actions',
                    'date_preset' => 'today',
                ]
            );
        } catch (Throwable $e) {
            throw new RuntimeException("Could not reach Meta to fetch insights for campaign {$campaign->meta_campaign_id}.", previous: $e);
        }

        if ($response->failed()) {
            app(SocialConnectionService::class)->escalate(
                $campaign->socialAccount,
                ProviderRequestFailed::fromResponse($response, 'Meta rejected the insights request.'),
                'ads.insights',
            );
        }

        $row = $response->json('data.0');

        if (! $row) {
            // No spend yet today (e.g. a campaign that just launched) —
            // Meta's Insights API returns an empty data set rather than a
            // zeroed row in this case, not an error.
            return ['spend' => 0.0, 'impressions' => 0, 'leads' => 0, 'cpl' => null];
        }

        $spend = (float) ($row['spend'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);

        $leads = 0;
        foreach ($row['actions'] ?? [] as $action) {
            // [Hypothesis]: 'leadgen.other' / 'onsite_conversion.lead_grouped'
            // are Meta's documented action_type values for Lead Ads form
            // submissions; matched by substring so either naming variant
            // (Meta has renamed this action_type across API versions) is
            // still counted rather than silently missed.
            if (str_contains((string) ($action['action_type'] ?? ''), 'lead')) {
                $leads += (int) ($action['value'] ?? 0);
            }
        }

        $cpl = $leads > 0 ? round($spend / $leads, 2) : null;

        return ['spend' => $spend, 'impressions' => $impressions, 'leads' => $leads, 'cpl' => $cpl];
    }

    /**
     * The tenant's connected Meta Ad Account. `provider_id` was stored
     * by MetaOAuthProvider::fetchAssets() straight from Graph API's
     * /me/adaccounts `id` field, which Meta returns ALREADY prefixed
     * "act_<numeric>" — [Fact], verified by reading that method directly.
     * Every Marketing API call below therefore uses $adAccount->provider_id
     * as-is; prefixing it with "act_" again would double it and 400 every
     * call.
     */
    private function resolveAdAccount(Account $account): SocialAccount
    {
        $adAccount = SocialAccount::query()
            ->forAccount($account->id)
            ->ofAssetType('meta_ad_account')
            ->first();

        if (! $adAccount) {
            throw new RuntimeException('No Meta Ad Account is connected for this tenant. Connect one from the Social Hub first.');
        }

        return $adAccount;
    }

    /** The tenant's connected Facebook Page, if any — used as promoted_object/creative page_id. */
    private function resolvePage(Account $account): ?SocialAccount
    {
        return SocialAccount::query()
            ->forAccount($account->id)
            ->ofAssetType('facebook_page')
            ->first();
    }

    /**
     * Step 4 (Click-to-WhatsApp Ads). The tenant's connected WhatsApp
     * Business Cloud API phone number (WhatsAppSession.meta_phone_number_id
     * — the same field the WhatsApp module itself uses to route inbound
     * Meta webhook traffic, see MetaWebhookController::handleInboundMessages()).
     * Deliberately reuses this existing column rather than inventing a
     * new one — it is already the canonical "this tenant's WhatsApp
     * Business number" value elsewhere in this codebase.
     */
    private function resolveWhatsAppPhoneNumber(Account $account): string
    {
        $session = WhatsAppSession::where('account_id', $account->id)->first();

        if (! $session || ! $session->meta_phone_number_id) {
            throw new RuntimeException(
                'No WhatsApp Business phone number is configured for this tenant. Configure one under WhatsApp > Meta Config first.'
            );
        }

        return $session->meta_phone_number_id;
    }

    private function createCampaign(string $adAccountId, string $accessToken, string $name, string $metaObjective): string
    {
        $response = $this->post($accessToken, "/{$adAccountId}/campaigns", [
            'name' => $name,
            'objective' => $metaObjective,
            'status' => 'ACTIVE',
            // Meta requires special_ad_categories on every campaign since
            // the 2022 special-ad-category rollout; NONE is correct for a
            // generic lead/traffic/engagement campaign with no housing,
            // employment, credit, social-issue, or political content.
            'special_ad_categories' => [],
        ]);

        return $this->createdId($response);
    }

    /**
     * @param array<string, mixed> $payload The full launch() payload — targeting_specs read from it here.
     */
    private function createAdSet(
        string $adAccountId,
        string $accessToken,
        string $metaCampaignId,
        string $objective,
        array $payload,
        ?SocialAccount $page,
        ?string $whatsAppNumber = null
    ): string {
        $targeting = $payload['targeting_specs'];

        $body = [
            'name' => $payload['campaign_name'].' - Ad Set',
            'campaign_id' => $metaCampaignId,
            // Meta's daily_budget is an integer in the ad account's
            // SMALLEST currency unit (e.g. paise/cents) — [Hypothesis],
            // documented Meta behavior. This app stores/accepts whole
            // currency units (see the ad_campaigns migration docblock);
            // *100 is the disclosed conversion, correct for 2-decimal
            // currencies (INR/USD) but NOT for zero-decimal currencies
            // (e.g. JPY) — a disclosed gap, not handled here.
            'daily_budget' => (int) round(((float) $payload['daily_budget']) * ($payload['_currency_offset'] ?? 100)),
            'billing_event' => self::BILLING_EVENT,
            'optimization_goal' => self::OPTIMIZATION_GOAL_MAP[$objective],
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'status' => 'ACTIVE',
            'targeting' => [
                // Owner request (2026-09-30): countries AND regions / cities picked
                // through searchLocations() (Meta keys), see geoLocations().
                'geo_locations' => self::geoLocations($targeting),
                ...self::placementTargeting($payload['placements'] ?? []),
                'age_min' => $targeting['age_min'],
                'age_max' => $targeting['age_max'],
                ...$this->buildInterestTargeting($accessToken, $targeting['interests'] ?? []),
            ],
        ];

        // LEAD_GENERATION and MESSAGES both require a promoted_object
        // naming the Page the leads/conversations attach to.
        // [Hypothesis], documented Meta requirement.
        if (in_array($objective, ['LEAD_GENERATION', 'MESSAGES'], true)) {
            if (! $page) {
                throw new RuntimeException(
                    "A connected Facebook Page is required to launch a {$objective} campaign. Connect one from the Social Hub first."
                );
            }

            $body['promoted_object'] = $objective === 'LEAD_GENERATION'
                ? ['page_id' => $page->provider_id]
                : ['page_id' => $page->provider_id, 'object_store_url' => null];

            $body['destination_type'] = $objective === 'LEAD_GENERATION' ? 'ON_AD' : 'MESSENGER';
        }

        // Step 4 (Click-to-WhatsApp Ads). destination_type => WHATSAPP,
        // with the tenant's connected WhatsApp Business phone number as
        // the promoted object, per this step's explicit instruction.
        //
        // DISCLOSED [Hypothesis] — NOT independently verified against a
        // live Meta call: Meta's most commonly documented CTWA path
        // promotes a Facebook Page whose WhatsApp Business Account is
        // already linked in Business Manager (promoted_object =
        // {"page_id": ...} only, same shape as MESSAGES above), with the
        // actual destination number implied by that Page's own WhatsApp
        // link — NOT passed explicitly in the API call. This
        // implementation instead follows the literal instruction given
        // for this step ("Set the promoted object to the tenant's
        // connected WhatsApp Business phone number") and sends the
        // number explicitly via a 'whatsapp_phone_number' key alongside
        // page_id. If Meta's API rejects an explicit phone number field
        // on a live call, the fix is to drop it and rely on the Page's
        // own WhatsApp Business linkage instead — flagged here rather
        // than silently guessed as correct.
        if ($objective === 'CLICK_TO_WHATSAPP') {
            if (! $page) {
                throw new RuntimeException(
                    'A connected Facebook Page is required to launch a CLICK_TO_WHATSAPP campaign. Connect one from the Social Hub first.'
                );
            }

            // Phase 10 Task 2 — resolved in preflight(), before any Meta write.
            $whatsAppNumber ??= throw new RuntimeException(
                'No WhatsApp Business phone number is configured for this tenant. Configure one under WhatsApp > Meta Config first.'
            );

            $body['promoted_object'] = [
                'page_id' => $page->provider_id,
                'whatsapp_phone_number' => $whatsAppNumber,
            ];
            $body['destination_type'] = 'WHATSAPP';
        }

        $response = $this->post($accessToken, "/{$adAccountId}/adsets", $body);

        return $this->createdId($response);
    }

    /**
     * @return array{flexible_spec?: list<array{interests: list<array{id: string, name: string}>}>}
     */
    private function buildInterestTargeting(string $accessToken, array $interestNames): array
    {
        if ($interestNames === []) {
            return [];
        }

        $resolved = [];

        foreach ($interestNames as $interestName) {
            $match = $this->searchInterest($accessToken, $interestName);

            if ($match) {
                $resolved[] = $match;
            }
        }

        if ($resolved === []) {
            return [];
        }

        return ['flexible_spec' => [['interests' => $resolved]]];
    }

    /**
     * Meta's targeting is by numeric interest ID, never a free-text name —
     * [Fact], documented Marketing API contract. This resolves each
     * tenant-supplied interest keyword via the Targeting Search API and
     * takes the FIRST result, a best-effort heuristic disclosed the same
     * way as MetaLeadWebhookHandler's field-name matching: reasonable,
     * not guaranteed to pick the interest the tenant meant among several
     * similarly-named ones. A keyword with zero matches is silently
     * dropped (logged) rather than failing the whole launch.
     *
     * @return array{id: string, name: string}|null
     */
    private function searchInterest(string $accessToken, string $keyword): ?array
    {
        try {
            $response = Http::withToken($accessToken)->timeout(10)->get(
                'https://graph.facebook.com/'.self::API_VERSION.'/search',
                ['type' => 'adinterest', 'q' => $keyword, 'limit' => 1]
            );
        } catch (Throwable $e) {
            Log::warning("MetaAdsService: interest search unreachable for '{$keyword}'.", ['exception' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning("MetaAdsService: interest search rejected for '{$keyword}'.", ['error' => $response->json('error.message')]);

            return null;
        }

        $first = $response->json('data.0');

        if (! $first) {
            Log::warning("MetaAdsService: no Meta interest match for '{$keyword}' — dropped from targeting.");

            return null;
        }

        return ['id' => (string) $first['id'], 'name' => (string) ($first['name'] ?? $keyword)];
    }

    /**
     * @param array{image_url: string|null, headline: string, primary_text: string} $creative
     */
    private function createAdCreative(string $adAccountId, string $accessToken, ?SocialAccount $page, array $creative, string $objective, ?string $instagramActorId = null): string
    {
        if (! $page) {
            throw new RuntimeException('A connected Facebook Page is required to build ad creative. Connect one from the Social Hub first.');
        }

        // [Hypothesis]: object_story_spec.link_data.picture accepts a
        // public image URL directly per Meta's documented AdCreative
        // contract, avoiding a separate /act_X/adimages upload step.
        // VIDEO creative is a disclosed gap in this phase — Meta's video
        // ads require the file to be uploaded via /act_X/advideos first
        // and referenced by the returned video_id, not a bare URL; that
        // upload pipeline is not implemented here (see the audit report).
        // Step 4: WHATSAPP_MESSAGE is the documented CTA type for
        // Click-to-WhatsApp creatives — [Hypothesis], same disclosure
        // tier as the rest of this method. The 'link' field is left
        // pointing at the Page (same as every other objective here);
        // for a WHATSAPP destination_type, Meta's documented behavior is
        // that the ad's actual click destination is derived from the
        // AdSet's destination_type/promoted_object, not this link —
        // this field is effectively unused for CLICK_TO_WHATSAPP but
        // Meta's AdCreative schema still requires link_data.link to be
        // present, so a harmless placeholder is kept rather than adding
        // creative-shape branching this method doesn't otherwise need.
        $allowedCtas = self::CALL_TO_ACTIONS[$objective] ?? ['LEARN_MORE'];
        $cta = in_array($creative['call_to_action'] ?? null, $allowedCtas, true) ? $creative['call_to_action'] : $allowedCtas[0];

        $linkData = [
            'message' => $creative['primary_text'],
            'name' => $creative['headline'],
            'link' => 'https://www.facebook.com/'.$page->provider_id,
            'call_to_action' => [
                'type' => $cta,
            ],
        ];

        if (! empty($creative['image_url'])) {
            $linkData['picture'] = $creative['image_url'];
        }

        $response = $this->post($accessToken, "/{$adAccountId}/adcreatives", [
            'name' => $creative['headline'],
            'object_story_spec' => array_filter([
                'page_id' => $page->provider_id,
                // [Hypothesis] v19 object_story_spec.instagram_actor_id = the
                // connected Instagram business account, so Instagram placements
                // show the tenant's own IG identity (Meta otherwise falls back to
                // the Page). Sent only when an Instagram asset is connected.
                'instagram_actor_id' => $instagramActorId,
                'link_data' => $linkData,
            ], fn ($v) => $v !== null),
        ]);

        return $this->createdId($response);
    }

    private function createAd(string $adAccountId, string $accessToken, string $metaAdsetId, string $creativeId, string $name): string
    {
        $response = $this->post($accessToken, "/{$adAccountId}/ads", [
            'name' => $name.' - Ad',
            'adset_id' => $metaAdsetId,
            'creative' => ['creative_id' => $creativeId],
            'status' => 'ACTIVE',
        ]);

        return $this->createdId($response);
    }

    private function setCampaignStatus(AdCampaign $campaign, string $status): void
    {
        $accessToken = $campaign->socialAccount?->access_token;

        if (! $accessToken) {
            throw new RuntimeException("AdCampaign #{$campaign->id} has no reachable Ad Account access token.");
        }

        $connections = app(SocialConnectionService::class);
        $connections->assertUsable($campaign->socialAccount, 'ads.status');

        try {
            $this->post($accessToken, "/{$campaign->meta_campaign_id}", ['status' => $status]);
        } catch (AdProviderOutcomeUnknown $e) {
            $this->noteProviderError($campaign, 'Meta did not confirm the status change (no response). Check Meta Ads Manager; repeating a pause/resume is safe.');

            throw $e;
        } catch (ProviderRequestFailed $e) {
            $this->noteProviderError($campaign, self::safeProviderError('status change', $e));

            if (self::isMissingObject($e)) {
                AdCampaign::query()->whereKey($campaign->id)->whereIn('status', [AdCampaign::STATUS_ACTIVE, AdCampaign::STATUS_PAUSED])
                    ->update(['status' => AdCampaign::STATUS_UNAVAILABLE, 'updated_at' => now()]);

                throw new AdCampaignConflict(
                    'Meta reports this campaign no longer exists or cannot be loaded. It was marked unavailable; no further changes are sent for it.',
                    'AD_CAMPAIGN_UNAVAILABLE',
                    409,
                    $campaign->refresh(),
                );
            }

            $connections->escalate($campaign->socialAccount, $e, 'ads.status');
        }
    }

    private function noteProviderError(AdCampaign $campaign, string $error): void
    {
        AdCampaign::query()->whereKey($campaign->id)->update([
            'last_provider_error' => mb_substr($error, 0, 255),
            'last_provider_error_at' => now(),
        ]);
    }

    /**
     * Meta's "object does not exist / cannot be loaded" (code 100, subcode 33)
     * — [Fact] a documented Graph API error; it also covers "missing
     * permissions", so the local state is UNAVAILABLE rather than "deleted".
     */
    private static function isMissingObject(ProviderRequestFailed $e): bool
    {
        $error = $e->errorBody['error'] ?? [];

        return (int) ($error['code'] ?? 0) === 100 && (int) ($error['error_subcode'] ?? 0) === 33;
    }

    /** A stored/returned summary of a Meta rejection: HTTP status + Meta code only, never Meta's raw text. */
    private static function safeProviderError(string $operation, ProviderRequestFailed $e): string
    {
        $code = $e->errorBody['error']['code'] ?? null;

        return sprintf('Meta rejected the %s (HTTP %d%s).', $operation, $e->httpStatus, is_numeric($code) ? ', code '.(int) $code : '');
    }

    /** A 2xx without an id means Meta may or may not have created the object. */
    private function createdId(array $response): string
    {
        $id = $response['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw new AdProviderOutcomeUnknown('Meta did not return an id for the created object.');
        }

        return (string) $id;
    }

    /**
     * Graph API's Marketing endpoints take `application/x-www-form-urlencoded`
     * POSTs, but any non-scalar parameter (targeting, object_story_spec,
     * promoted_object, special_ad_categories, creative, ...) must be a
     * JSON-encoded STRING field, not PHP-style bracket-notation form
     * fields — [Fact], documented Marketing API convention (the same
     * encoding Meta's own official SDKs apply before sending). Every
     * array value in $body is JSON-encoded here for that reason; scalar
     * values (name, status, campaign_id, ...) are sent as-is.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $accessToken, string $path, array $body): array
    {
        $encodedBody = array_map(
            fn (mixed $value) => is_array($value) ? json_encode($value) : $value,
            $body
        );

        try {
            $response = Http::asForm()->withToken($accessToken)->timeout(20)->post(
                'https://graph.facebook.com/'.self::API_VERSION.$path,
                $encodedBody
            );
        } catch (Throwable $e) {
            // Phase 10 Task 2 — a write whose response never arrived may still
            // have been applied: outcome unknown, never retried.
            throw new AdProviderOutcomeUnknown("Could not reach Meta ({$path}).", previous: $e);
        }

        if ($response->serverError()) {
            throw new AdProviderOutcomeUnknown("Meta did not confirm the request to {$path} (HTTP {$response->status()}).");
        }

        if ($response->failed()) {
            // Same message as before; also carries Meta's `error` object so
            // the caller can ask whether the connection itself failed.
            throw ProviderRequestFailed::fromResponse($response, "Meta rejected the request to {$path}.");
        }

        return $response->json() ?? [];
    }
}
