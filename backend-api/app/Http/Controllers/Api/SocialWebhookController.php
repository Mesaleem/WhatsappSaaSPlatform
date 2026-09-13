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
     * SIGNATURE VERIFICATION (fix, disclosed — closes the gap previously
     * documented here as "no App Secret field exists"; that claim was
     * stale as of MetaWebhookController's own X-Hub-Signature-256
     * hardening, which proved social_provider_configs.client_secret
     * already serves as the Meta App Secret for HMAC purposes and reads
     * it via the same SocialProviderConfig::findByProviderCached()
     * helper this controller already calls in verify() above — no new
     * schema was needed):
     *
     * For provider === 'meta' (the only provider this method actually
     * processes below), every POST is now verified against
     * X-Hub-Signature-256 by assertValidSignature(), mirroring
     * MetaWebhookController::assertValidSignature() exactly (same
     * fail-closed rules, same local-environment bypass, same
     * hash_equals() comparison — see that method's docblock for the full
     * reasoning, including the [Inference, load-bearing] note about one
     * Meta App Secret serving both OAuth and webhook HMAC purposes).
     *
     * Deliberately NOT extended to the other registered providers
     * (linkedin, google, gemini — see SocialProviderConfig::PROVIDERS):
     * X-Hub-Signature-256 is Meta's own webhook-signing convention, not a
     * generic standard, and none of those providers' payloads are acted
     * on by this method today (the `if ($provider === 'meta')` guard
     * below is unchanged) — verifying a signature scheme that may not
     * even apply to those providers, for payloads nothing here processes
     * yet, would risk rejecting legitimate future webhook traffic this
     * controller doesn't understand rather than closing a real gap. A
     * future module adding real processing for another provider should
     * add that provider's own signature scheme at the same time.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        if ($provider === 'meta') {
            $this->assertValidSignature($request, $provider);
        }

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

    /**
     * X-Hub-Signature-256 verification — adapted from
     * MetaWebhookController::assertValidSignature() for this
     * controller's dynamic $provider route parameter instead of a
     * hardcoded 'meta' literal. See that method's docblock (unchanged
     * here) for the full verification/bypass rules and the
     * [Inference, load-bearing] note this relies on. Only ever called
     * for $provider === 'meta' by handle() above.
     */
    private function assertValidSignature(Request $request, string $provider): void
    {
        if (app()->environment('local')) {
            return;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if ($header === '') {
            Log::warning("Social webhook rejected for provider [{$provider}]: missing X-Hub-Signature-256 header.", [
                'ip' => $request->ip(),
            ]);

            abort(403, 'Missing webhook signature.');
        }

        $appSecret = (string) (SocialProviderConfig::findByProviderCached($provider)?->client_secret ?? '');

        if ($appSecret === '') {
            Log::warning("Social webhook rejected for provider [{$provider}]: signature verification could not run — no App Secret configured (social_provider_configs.client_secret is empty).", [
                'ip' => $request->ip(),
            ]);

            abort(403, 'Webhook signature verification is misconfigured.');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! hash_equals($expected, $header)) {
            Log::warning("Social webhook rejected for provider [{$provider}]: X-Hub-Signature-256 signature mismatch.", [
                'ip' => $request->ip(),
                'header_present' => true,
                'header_length' => strlen($header),
            ]);

            abort(403, 'Invalid webhook signature.');
        }
    }
}
