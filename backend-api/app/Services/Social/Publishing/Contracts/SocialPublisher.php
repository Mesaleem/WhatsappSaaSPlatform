<?php

namespace App\Services\Social\Publishing\Contracts;

use App\Models\SocialAccount;
use App\Services\Social\Publishing\PublishOutcome;
use App\Services\Social\Publishing\PublishRequest;
use App\Services\Social\Publishing\PublishUnreachable;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;

/**
 * Phase 9 Task 3 — the provider-agnostic publishing contract. Every
 * provider API call for publishing (URLs, payload shapes, error codes)
 * lives in an implementation of this interface; OrganicPublishService,
 * the controller and the jobs only talk to it.
 *
 * Implementations must never log or return a credential. Failures are
 * reported as:
 *   - ProviderRequestFailed — the provider answered with an error (the
 *     caller asks SocialConnectionService / isTransient() what it means);
 *   - PublishUnreachable    — no answer at all: the post may or may not
 *     exist at the provider, so it is never re-sent automatically.
 */
interface SocialPublisher
{
    /** The social_accounts.provider value this publisher serves (e.g. "meta"). */
    public function key(): string;

    /** @return array<string, string> platform => the social_accounts.asset_type it publishes through */
    public function platforms(): array;

    /** A reason the request cannot be published on its platform (checked before anything is stored), or null. */
    public function validationError(PublishRequest $request): ?string;

    /**
     * Publishes to $target (the persisted, ownership-checked connection).
     *
     * @throws ProviderRequestFailed|PublishUnreachable
     */
    public function publish(SocialAccount $target, PublishRequest $request): PublishOutcome;

    /**
     * One check of a post the provider is still processing (e.g. an
     * Instagram video container); publishes it once ready.
     *
     * @throws ProviderRequestFailed|PublishUnreachable
     */
    public function checkProcessing(SocialAccount $target, string $containerId): PublishOutcome;

    /** Is this provider error worth retrying later (rate limit, outage), as opposed to permanent? */
    public function isTransient(ProviderRequestFailed $failure): bool;

    /** A user-safe sentence for a permanent provider rejection (no raw provider payload). */
    public function failureMessage(ProviderRequestFailed $failure): string;
}
