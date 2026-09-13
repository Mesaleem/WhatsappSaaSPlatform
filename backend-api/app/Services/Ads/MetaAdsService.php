<?php

namespace App\Services\Ads;

use App\Models\Account;
use App\Models\AdCampaign;
use App\Models\SocialAccount;
use App\Models\WhatsAppSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * Launches Campaign -> AdSet -> AdCreative -> Ad on Meta for the given
     * tenant Account, then persists the result as an AdCampaign row.
     * Nothing is written to the database unless ALL four Meta calls
     * succeed — a partial failure midway (e.g. AdSet created but Ad
     * creation rejected) leaves an orphaned Campaign/AdSet object on
     * Meta's side (logged as a warning with its id for manual cleanup)
     * rather than a half-populated, misleading local row. This mirrors
     * this codebase's existing "don't persist on partial external
     * success" discipline (see MetaLeadWebhookHandler's early-return
     * pattern) rather than inventing a new one.
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
    public function launch(Account $account, array $payload): AdCampaign
    {
        $adAccount = $this->resolveAdAccount($account);
        $page = $this->resolvePage($account);
        $accessToken = $adAccount->access_token;

        if (! $accessToken) {
            throw new RuntimeException('The connected Meta Ad Account has no stored access token. Please reconnect it.');
        }

        $objective = $payload['objective'];
        $metaObjective = self::OBJECTIVE_MAP[$objective]
            ?? throw new RuntimeException("Unsupported objective '{$objective}'.");

        $metaCampaignId = $this->createCampaign($adAccount->provider_id, $accessToken, $payload['campaign_name'], $metaObjective);

        try {
            $metaAdsetId = $this->createAdSet(
                $account,
                $adAccount->provider_id,
                $accessToken,
                $metaCampaignId,
                $objective,
                $payload,
                $page
            );

            $creativeId = $this->createAdCreative(
                $adAccount->provider_id,
                $accessToken,
                $page,
                $payload['creative'],
                $objective
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
                'meta_campaign_id' => $metaCampaignId,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        return AdCampaign::create([
            'account_id' => $account->id,
            'social_account_id' => $adAccount->id,
            'meta_campaign_id' => $metaCampaignId,
            'meta_adset_id' => $metaAdsetId,
            'meta_ad_id' => $metaAdId,
            'name' => $payload['campaign_name'],
            'objective' => $objective,
            'status' => AdCampaign::STATUS_ACTIVE,
            'daily_budget' => $payload['daily_budget'],
            'cpl_threshold' => $payload['cpl_threshold'] ?? null,
        ]);
    }

    public function pause(AdCampaign $campaign): void
    {
        $this->setCampaignStatus($campaign, 'PAUSED');
    }

    public function resume(AdCampaign $campaign): void
    {
        $this->setCampaignStatus($campaign, 'ACTIVE');
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
            throw new RuntimeException($response->json('error.message') ?? 'Meta rejected the insights request.');
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

        return (string) $response['id'];
    }

    /**
     * @param array<string, mixed> $payload The full launch() payload — targeting_specs read from it here.
     */
    private function createAdSet(
        Account $account,
        string $adAccountId,
        string $accessToken,
        string $metaCampaignId,
        string $objective,
        array $payload,
        ?SocialAccount $page
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
            'daily_budget' => (int) round(((float) $payload['daily_budget']) * 100),
            'billing_event' => self::BILLING_EVENT,
            'optimization_goal' => self::OPTIMIZATION_GOAL_MAP[$objective],
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'status' => 'ACTIVE',
            'targeting' => [
                'geo_locations' => [
                    // [Hypothesis]: country-code targeting only. City/region
                    // targeting requires resolving free-text location names
                    // to Meta's numeric "key" via the Targeting Search API
                    // (/search?type=adgeolocation) — a disclosed gap; this
                    // phase accepts ISO country codes in targeting_specs.countries.
                    'countries' => $targeting['countries'],
                ],
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

            $whatsAppNumber = $this->resolveWhatsAppPhoneNumber($account);

            $body['promoted_object'] = [
                'page_id' => $page->provider_id,
                'whatsapp_phone_number' => $whatsAppNumber,
            ];
            $body['destination_type'] = 'WHATSAPP';
        }

        $response = $this->post($accessToken, "/{$adAccountId}/adsets", $body);

        return (string) $response['id'];
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
    private function createAdCreative(string $adAccountId, string $accessToken, ?SocialAccount $page, array $creative, string $objective): string
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
        $ctaTypeMap = [
            'LEAD_GENERATION' => 'SIGN_UP',
            'CLICK_TO_WHATSAPP' => 'WHATSAPP_MESSAGE',
        ];

        $linkData = [
            'message' => $creative['primary_text'],
            'name' => $creative['headline'],
            'link' => 'https://www.facebook.com/'.$page->provider_id,
            'call_to_action' => [
                'type' => $ctaTypeMap[$objective] ?? 'LEARN_MORE',
            ],
        ];

        if (! empty($creative['image_url'])) {
            $linkData['picture'] = $creative['image_url'];
        }

        $response = $this->post($accessToken, "/{$adAccountId}/adcreatives", [
            'name' => $creative['headline'],
            'object_story_spec' => [
                'page_id' => $page->provider_id,
                'link_data' => $linkData,
            ],
        ]);

        return (string) $response['id'];
    }

    private function createAd(string $adAccountId, string $accessToken, string $metaAdsetId, string $creativeId, string $name): string
    {
        $response = $this->post($accessToken, "/{$adAccountId}/ads", [
            'name' => $name.' - Ad',
            'adset_id' => $metaAdsetId,
            'creative' => ['creative_id' => $creativeId],
            'status' => 'ACTIVE',
        ]);

        return (string) $response['id'];
    }

    private function setCampaignStatus(AdCampaign $campaign, string $status): void
    {
        $accessToken = $campaign->socialAccount?->access_token;

        if (! $accessToken) {
            throw new RuntimeException("AdCampaign #{$campaign->id} has no reachable Ad Account access token.");
        }

        $this->post($accessToken, "/{$campaign->meta_campaign_id}", ['status' => $status]);
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
            throw new RuntimeException("Could not reach Meta ({$path}).", previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('error.message') ?? "Meta rejected the request to {$path}."
            );
        }

        return $response->json() ?? [];
    }
}
