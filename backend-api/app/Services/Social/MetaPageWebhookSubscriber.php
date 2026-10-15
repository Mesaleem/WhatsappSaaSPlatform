<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * QA fixes (2026-10-14) — subscribes a connected Facebook Page to this app's
 * webhooks (POST /{page-id}/subscribed_apps).
 *
 * Without it Meta never sends the Page's events to /api/social/webhook/meta:
 * lead-ad leads (leadgen), comments (feed, for comment rules) and Messenger
 * DMs (messages) all stayed silent unless someone subscribed the Page by hand.
 * The pages_manage_metadata scope requested at connect time exists for this.
 *
 * Best effort: a failure is recorded on the account's metadata
 * (webhooks_subscribed=false, webhooks_error) and logged, never thrown — the
 * connection itself still works for posting and insights.
 */
class MetaPageWebhookSubscriber
{
    private const GRAPH_API_VERSION = 'v19.0';

    public const FIELDS = ['leadgen', 'feed', 'messages'];

    public function subscribe(SocialAccount $page): bool
    {
        if ($page->provider !== 'meta' || $page->asset_type !== 'facebook_page' || ! filled($page->access_token)) {
            return false;
        }

        $error = null;
        try {
            $response = Http::withToken((string) $page->access_token)
                ->timeout(15)
                ->asForm()
                ->post('https://graph.facebook.com/'.self::GRAPH_API_VERSION."/{$page->provider_id}/subscribed_apps", [
                    'subscribed_fields' => implode(',', self::FIELDS),
                ]);
            $ok = $response->successful() && (bool) $response->json('success', false);
            if (! $ok) {
                $error = (string) ($response->json('error.message') ?? 'Meta refused the subscription.');
            }
        } catch (Throwable $e) {
            $ok = false;
            $error = 'Meta could not be reached.';
        }

        $metadata = is_array($page->metadata) ? $page->metadata : [];
        $metadata['webhooks_subscribed'] = $ok;
        $metadata['webhooks_error'] = $ok ? null : $error;
        $metadata['webhooks_checked_at'] = now()->toIso8601String();
        $page->forceFill(['metadata' => $metadata])->save();

        if (! $ok) {
            Log::warning('Meta Page webhook subscription failed.', ['social_account_id' => $page->id, 'error' => $error]);
        }

        return $ok;
    }
}
