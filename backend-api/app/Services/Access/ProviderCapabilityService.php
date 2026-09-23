<?php

namespace App\Services\Access;

use App\Models\Capability;
use App\Models\Provider;
use App\Models\ProviderCapability;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 1 Foundation — the single, data-driven answer to "is this
 * capability even possible/allowed on this provider". Replaces
 * scattered `engine_type === 'qr'` string checks. See the Phase 1 plan,
 * Step 6.
 *
 * No row for a given (provider, capability) pair means "no restriction
 * stated" -> true, matching this codebase's own allowed_modules "null =
 * everything enabled" convention (Account::effectiveModules()) rather
 * than failing closed for capabilities nobody has ever restricted
 * (e.g. 'crm'/'social'/'ai' against most providers).
 */
class ProviderCapabilityService
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Phase 5 Task 2 -- the send-time gate for Native WhatsApp Groups,
     * replacing the `engine_type !== 'qr'` literal that was repeated at
     * nine call sites (ContactGroupController x4, Api\V1\GroupController,
     * GroupMessageDispatcher, GroupDirectMessageDispatcher,
     * ProcessGroupDispatchJob, ProcessGroupDirectMessageJob).
     *
     * WHY THIS IS NOT JUST supports($engineType, 'whatsapp_groups'):
     * supports() answers "no row stated" with TRUE -- correct for the
     * grant-time use it was written for (see this class's own docblock:
     * an unrestricted capability should not be blocked just because
     * nobody wrote a row about it), but FAIL-OPEN for a send-time gate.
     * provider_capabilities is populated by Phase1FoundationSeeder, which
     * is part of DatabaseSeeder but is NOT guaranteed to have been run on
     * any given deployment, and is not run by most of this project's test
     * suites. On an unseeded database a bare supports() call would report
     * that Meta supports WhatsApp groups -- it does not, and cannot.
     *
     * So the catalog is authoritative WHEN IT STATES THE PAIRING, and the
     * previous hard-coded behaviour is the fallback when it does not.
     * That makes this byte-identical to the nine literals it replaces on
     * a seeded AND an unseeded database, while consolidating the rule
     * into one named place that the catalog can now override. The 'qr'
     * literal survives exactly once, here, instead of nine times.
     *
     * Deliberately NOT extended to the other engine_type comparisons in
     * this codebase: TemplateMessageDispatcher's `!== 'meta'` guard is
     * about a template being registered inside a WABA, resolveMediaMetaData()'s
     * `!== 'qr'` is about media-template support, and
     * PaymentAlertDispatcher::isWhatsAppDisconnected()'s `=== 'qr'` is
     * about whether an engine has a connection concept at all. None of
     * those three is expressed by a provider_capabilities row, so
     * routing them through this service would be inventing a mapping,
     * not replacing one.
     */
    public function supportsNativeWhatsAppGroups(?string $engineType): bool
    {
        if ($engineType === null || $engineType === '') {
            // No engine configured at all: nothing is supported, and this
            // matches the old literal (null !== 'qr').
            return false;
        }

        return $this->supportsOrNull($engineType, 'whatsapp_groups') ?? ($engineType === 'qr');
    }

    /**
     * supports(), but distinguishing "the catalog says yes" (true), "the
     * catalog says no" (false) and "the catalog has nothing to say"
     * (null). supports() collapses the last two cases into true; a gate
     * that must fail safe needs to tell them apart.
     */
    public function supportsOrNull(Provider|string $provider, Capability|string $capability): ?bool
    {
        $providerSlug = $provider instanceof Provider ? $provider->slug : $provider;
        $capabilitySlug = $capability instanceof Capability ? $capability->slug : $capability;

        $row = ProviderCapability::query()
            ->whereHas('provider', fn ($q) => $q->where('slug', $providerSlug))
            ->whereHas('capability', fn ($q) => $q->where('slug', $capabilitySlug))
            ->first();

        return $row === null ? null : (bool) $row->supported;
    }

    public function supports(Provider|string $provider, Capability|string $capability): bool
    {
        $providerSlug = $provider instanceof Provider ? $provider->slug : $provider;
        $capabilitySlug = $capability instanceof Capability ? $capability->slug : $capability;

        return Cache::remember(
            "provider_capability:{$providerSlug}:{$capabilitySlug}",
            self::CACHE_TTL_SECONDS,
            function () use ($providerSlug, $capabilitySlug) {
                $row = ProviderCapability::query()
                    ->whereHas('provider', fn ($q) => $q->where('slug', $providerSlug))
                    ->whereHas('capability', fn ($q) => $q->where('slug', $capabilitySlug))
                    ->first();

                return $row === null ? true : (bool) $row->supported;
            }
        );
    }

    /**
     * The reason string for a false/blocked pairing, or null when
     * supported (or unrestricted). Lets callers surface WHY a grant was
     * rejected — see AccountEntitlementController.
     */
    public function reasonIfUnsupported(Provider|string $provider, Capability|string $capability): ?string
    {
        $providerSlug = $provider instanceof Provider ? $provider->slug : $provider;
        $capabilitySlug = $capability instanceof Capability ? $capability->slug : $capability;

        $row = ProviderCapability::query()
            ->whereHas('provider', fn ($q) => $q->where('slug', $providerSlug))
            ->whereHas('capability', fn ($q) => $q->where('slug', $capabilitySlug))
            ->first();

        if ($row === null || $row->supported) {
            return null;
        }

        return $row->reason ?? 'This capability is not supported on this provider.';
    }
}
