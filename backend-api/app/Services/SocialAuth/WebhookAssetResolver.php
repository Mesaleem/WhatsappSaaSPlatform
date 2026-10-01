<?php

namespace App\Services\SocialAuth;

use App\Models\ActivityLog;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 9 Task 2.2 — the ONE way a provider webhook finds the social
 * connection (and therefore the tenant) an event belongs to.
 *
 * The only identity a Meta webhook carries is the asset's own id (the
 * Page id in `entry.id` / `page_id`, the Instagram account id). The
 * payload is signed with the PLATFORM app secret (SocialWebhookController::
 * assertValidSignature), which proves the event came from Meta for this
 * app — it does not say which tenant it is for, and the platform uses a
 * single Meta app for every tenant. social_accounts is unique per
 * (account_id, provider, provider_id), so the same Page may legitimately be
 * connected by more than one tenant (e.g. an agency and its client).
 *
 * Therefore:
 *   - exactly one connection holds the asset → that connection (its
 *     account_id is the tenant);
 *   - none → nothing to route (the caller logs and drops, as before);
 *   - more than one → AMBIGUOUS: nothing is processed. No tie-break (oldest,
 *     newest, healthiest) is used, because none of them is evidence of
 *     ownership. A safe warning is logged and a platform-level audit row
 *     (activity_logs, account_id NULL → Super Admin only; action "denied",
 *     category "ambiguous_owner") records that manual resolution is needed.
 *     The webhook still answers 200, exactly as for an unknown asset.
 *
 * Nothing from the payload other than the asset id is used, and no
 * account/tenant id is ever read from the request.
 */
class WebhookAssetResolver
{
    public const MODULE = 'Social Webhook Routing';

    public const AMBIGUOUS = 'ambiguous';

    public const NONE = 'none';

    public const FOUND = 'found';

    /**
     * @param  array<string, scalar|null>  $reference  safe event identifiers for the audit row (e.g. leadgen_id, comment_id)
     * @return array{status: 'found'|'none'|'ambiguous', account: SocialAccount|null}
     */
    public function resolve(string $provider, string $assetType, string $providerId, string $event, array $reference = []): array
    {
        // At most two rows are needed to tell "one owner" from "ambiguous".
        $matches = SocialAccount::query()
            ->where('provider', $provider)
            ->where('asset_type', $assetType)
            ->where('provider_id', $providerId)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($matches->count() === 1) {
            return ['status' => self::FOUND, 'account' => $matches->first()];
        }

        if ($matches->isEmpty()) {
            return ['status' => self::NONE, 'account' => null];
        }

        $this->recordAmbiguity($provider, $assetType, $providerId, $event, $reference);

        return ['status' => self::AMBIGUOUS, 'account' => null];
    }

    /** @param array<string, scalar|null> $reference */
    private function recordAmbiguity(string $provider, string $assetType, string $providerId, string $event, array $reference): void
    {
        $owners = SocialAccount::query()
            ->where('provider', $provider)
            ->where('asset_type', $assetType)
            ->where('provider_id', $providerId)
            ->orderBy('id')
            ->get(['id', 'account_id']);

        $details = [
            'decision' => 'denied',
            'category' => 'ambiguous_owner',
            'event' => $event,
            'provider' => $provider,
            'asset_type' => $assetType,
            'provider_id' => $providerId,
            'owner_count' => $owners->count(),
            'social_account_ids' => $owners->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'account_ids' => $owners->pluck('account_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'reference' => array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 191) : null, $reference),
            'resolution' => 'manual: disconnect the asset from all but the owning account',
        ];

        Log::warning('Social webhook not processed: the asset is connected by more than one account.', $details);

        try {
            ActivityLog::create([
                'user_id' => null,
                'account_id' => null, // platform-level: visible to the Super Admin only, never to one of the tenants
                'agent_id' => null,
                'module_name' => self::MODULE,
                'action_type' => 'denied',
                'route_path' => request()?->path() ? mb_substr(request()->path(), 0, 255) : null,
                'ip_address' => null,
                'old_values' => null,
                'new_values' => $details,
            ]);
        } catch (Throwable $e) {
            Log::warning('WebhookAssetResolver: could not record the ambiguity audit row.', ['exception' => class_basename($e)]);
        }
    }
}
