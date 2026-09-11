<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialProviderConfig;
use App\Services\Leads\MetaLeadWebhookHandler;
use App\Services\Comments\CommentAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 *
 * Meta App-level webhook (Lead Ads / Page subscriptions) — deliberately
 * SEPARATE from MetaWebhookController (Module 5's WhatsApp Cloud API
 * webhook, /api/webhooks/meta). Same product (Meta), unrelated
 * subscriptions, unrelated verify tokens, unrelated payload shapes — the
 * literal spec bullet "/api/social/callback/meta" conflated the OAuth
 * redirect callback (SocialAuthController::callback(), which actually
 * lives at that exact path per the spec) with this webhook subscription
 * handshake; they are two different Meta concepts and are implemented as
 * two different endpoints here. See the audit report for this disclosed
 * split.
 *
 * PHASE 1 shipped only the verification handshake + a logging receiver.
 * PHASE 2 adds the actual leadgen processing (MetaLeadWebhookHandler) —
 * see handle()'s docblock for what changed and what's still deferred.
 * PHASE 4 adds the Ad Comment Auto-Responder (CommentAutomationService) —
 * dispatched alongside MetaLeadWebhookHandler on the SAME entries, since
 * one webhook delivery can carry both a leadgen change and a comment
 * change and both handlers independently filter to the `field` values
 * they care about.
 */
class SocialWebhookController extends Controller
{
    /**
     * GET /api/social/webhook/{provider}
     *
     * Unlike MetaWebhookController::verify() (which matches the
     * hub_verify_token against every TENANT's WhatsAppSession row), this
     * checks it against the single APP-LEVEL token on
     * social_provider_configs — see that migration's docblock for why a
     * Lead Ads / Page webhook subscription is owned by the platform's one
     * Meta App, not by any individual tenant.
     */
    public function verify(Request $request, string $provider): Response|JsonResponse
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || ! $token) {
            return response()->json(['message' => 'Invalid webhook verification request.'], 403);
        }

        $config = SocialProviderConfig::findByProviderCached($provider);

        if (! $config || ! $config->webhook_verify_token || ! hash_equals($config->webhook_verify_token, (string) $token)) {
            Log::warning("Social webhook verification failed for provider [{$provider}]: token mismatch.");

            return response()->json(['message' => 'Verification token mismatch.'], 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * POST /api/social/webhook/{provider}
     *
     * PHASE 2: `leadgen` changes are handed to MetaLeadWebhookHandler
     * (Instant Lead Bridge — fetch, dedup, persist, dual WhatsApp send;
     * see that class's docblock for its own disclosed trade-offs).
     * PHASE 4: `feed`/`comments`/`instagram` comment changes are handed
     * to CommentAutomationService (Ad Comment Auto-Responder). Both
     * handlers run on every entry, independently, each filtering to the
     * `field` values it cares about — any other change type this app
     * doesn't act on yet is still just logged above.
     *
     * Each handler call is wrapped in ITS OWN try/catch, per entry, so
     * one bad/failing entry (or one handler failing while the other
     * succeeds) can never stop the rest of the batch, or turn into a
     * non-200 response that would make Meta retry (and duplicate-process)
     * the WHOLE delivery instead of just the one entry/handler that failed.
     *
     * KNOWN GAP (disclosed, carried over from Phase 1, unchanged):
     * X-Hub-Signature-256 verification is still not implemented — no App
     * Secret field exists on social_provider_configs (only client_id/
     * client_secret for the OAuth flow, a different Meta credential).
     * Same disclosed gap as MetaWebhookController's Module 5 report.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        $entries = $request->input('entry', []);

        Log::info("Social webhook payload received for provider [{$provider}]", [
            'entry_count' => count($entries),
        ]);

        if ($provider === 'meta') {
            $leadHandler = app(MetaLeadWebhookHandler::class);
            $commentHandler = app(CommentAutomationService::class);

            foreach ($entries as $entry) {
                try {
                    $leadHandler->handle($entry);
                } catch (Throwable $e) {
                    Log::error('MetaLeadWebhookHandler threw while processing a webhook entry.', [
                        'exception' => $e->getMessage(),
                    ]);
                }

                try {
                    $commentHandler->handle($entry);
                } catch (Throwable $e) {
                    Log::error('CommentAutomationService threw while processing a webhook entry.', [
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Meta requires a fast 200 regardless of processing outcome, same
        // reasoning as MetaWebhookController::handle().
        return response()->json(['received' => true]);
    }
}
