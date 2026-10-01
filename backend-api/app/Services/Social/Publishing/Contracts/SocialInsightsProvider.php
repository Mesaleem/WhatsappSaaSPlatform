<?php

namespace App\Services\Social\Publishing\Contracts;

use App\Models\SocialAccount;
use App\Services\Social\Publishing\InsightsRequest;
use App\Services\Social\Publishing\InsightsResult;
use App\Services\Social\Publishing\PublishUnreachable;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;

/**
 * Phase 9 Task 4 — the insights capability of a publishing provider,
 * separate from SocialPublisher because not every provider that publishes
 * can report metrics (LinkedIn: not implemented).
 *
 * The provider owns every provider URL and response shape and returns
 * normalised metrics (OrganicPostInsight::METRICS keys). A metric the
 * provider did not report is listed in InsightsResult::$unavailable with a
 * reason — never returned as 0.
 */
interface SocialInsightsProvider
{
    /** @return list<string> platforms this provider can report insights for */
    public function insightsPlatforms(): array;

    /**
     * @throws ProviderRequestFailed a failure the caller must classify (connection, rate limit, transient, permanent)
     * @throws PublishUnreachable    no answer from the provider
     */
    public function fetchInsights(SocialAccount $target, InsightsRequest $request): InsightsResult;

    /** One of InsightsFailure::* — never used for connection problems (SocialConnectionService::observe() decides those first). */
    public function classifyInsightsFailure(ProviderRequestFailed $failure): string;
}
