<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Services\SocialAuth\MetaOAuthProvider;
use App\Services\SocialAuth\SocialOAuthProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 *
 * Two-step OAuth-popup flow, split across three actions:
 *  1. redirect()  — authenticated (sanctum + tenant.isolation). Builds the
 *     provider's consent-screen URL and hands it to the frontend, which
 *     opens it in a popup (window.open), never navigates the SPA itself.
 *  2. callback()  — PUBLIC. The provider (Meta) redirects the POPUP here
 *     directly, with no Laravel session and no Bearer token — tenant
 *     identity travels in the encrypted `state` param instead. Exchanges
 *     the code, fetches the account's bindable assets, stashes them in
 *     cache keyed by a one-time nonce, and responds with a minimal HTML
 *     page that postMessages {nonce, assets} to window.opener and closes
 *     itself — the SAME "backend owns the OAuth redirect_uri" shape as
 *     MetaWebhookController's GET handshake, applied to a login flow
 *     instead of a webhook subscription.
 *  3. bind()      — authenticated. The Asset Selection Modal's chosen
 *     assets, redeemed against the cached nonce (never re-trusting a
 *     token or account_id supplied directly by the client) and persisted
 *     as SocialAccount rows.
 */
class SocialAuthController extends Controller
{
    use ResolvesTenantAccount;

    private const STATE_TTL_MINUTES = 10;

    /** GET /api/social/oauth/{provider}/redirect */
    public function redirect(Request $request, string $provider): JsonResponse
    {
        $account = $this->requireAccount($request);

        $this->assertProviderReachableFor($account, $provider);

        $config = SocialOAuthProviderFactory::configFor($provider);
        $driver = SocialOAuthProviderFactory::make($provider);

        $nonce = (string) Str::uuid();
        $state = Crypt::encryptString(json_encode([
            'account_id' => $account->id,
            'provider' => $provider,
            'nonce' => $nonce,
            'exp' => now()->addMinutes(self::STATE_TTL_MINUTES)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json([
            'url' => $driver->buildAuthorizationUrl($config, $state),
            'nonce' => $nonce,
        ]);
    }

    /** GET /api/social/callback/{provider} — public; see class docblock. */
    public function callback(Request $request, string $provider): Response
    {
        $code = $request->query('code');
        $stateRaw = $request->query('state');
        $error = $request->query('error_description', $request->query('error'));

        if ($error) {
            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => (string) $error]);
        }

        if (! $code || ! $stateRaw) {
            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'Missing authorization code.']);
        }

        try {
            $state = json_decode(Crypt::decryptString($stateRaw), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'Invalid or tampered OAuth state.']);
        }

        if (($state['provider'] ?? null) !== $provider || ($state['exp'] ?? 0) < now()->timestamp) {
            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'This authorization link has expired. Please try connecting again.']);
        }

        $account = Account::findCached((int) $state['account_id']);
        if (! $account) {
            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'Tenant account no longer exists.']);
        }

        try {
            $config = SocialOAuthProviderFactory::configFor($provider);
            $driver = SocialOAuthProviderFactory::make($provider);

            $token = $driver->exchangeCodeForToken($config, (string) $code);
            $assets = $driver->fetchAssets($token['access_token']);
        } catch (Throwable $e) {
            Log::warning('Social OAuth callback failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => $e->getMessage()]);
        }

        // Only present assets this tenant's Super Admin has actually
        // switched on (Account::hasSocialPlatformEnabled) — defense in
        // depth on top of the provider-level check in redirect().
        $bindableAssets = array_values(array_filter(
            $assets,
            fn (array $asset) => $account->hasSocialPlatformEnabled($asset['asset_type'])
        ));

        $nonce = (string) $state['nonce'];
        Cache::put("social_oauth_pending:{$nonce}", [
            'account_id' => $account->id,
            'provider' => $provider,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_in' => $token['expires_in'],
            'assets' => $bindableAssets,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $this->popupResponse([
            'type' => 'social-oauth-success',
            'provider' => $provider,
            'nonce' => $nonce,
            'assets' => $bindableAssets,
        ]);
    }

    /** POST /api/social/accounts/bind */
    public function bind(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'nonce' => ['required', 'string'],
            'selections' => ['required', 'array', 'min:1'],
            'selections.*.asset_type' => ['required', 'string', 'in:'.implode(',', SocialAccount::ASSET_TYPES)],
            'selections.*.provider_id' => ['required', 'string'],
        ]);

        $cacheKey = "social_oauth_pending:{$data['nonce']}";
        $pending = Cache::get($cacheKey);

        if (! $pending || (int) $pending['account_id'] !== $account->id) {
            throw ValidationException::withMessages([
                'nonce' => 'This connection attempt has expired or does not belong to your account. Please reconnect.',
            ]);
        }

        $bound = [];

        foreach ($data['selections'] as $selection) {
            if (! $account->hasSocialPlatformEnabled($selection['asset_type'])) {
                throw ValidationException::withMessages([
                    'selections' => "Your account is not permitted to connect a {$selection['asset_type']} asset. Ask a Super Admin to enable it.",
                ]);
            }

            $offered = collect($pending['assets'])->first(
                fn (array $a) => $a['asset_type'] === $selection['asset_type'] && $a['provider_id'] === $selection['provider_id']
            );

            if (! $offered) {
                throw ValidationException::withMessages([
                    'selections' => 'One of the selected assets was not part of the original authorization — please reconnect.',
                ]);
            }

            // Social Media Marketing & Meta Ads Automation Expansion —
            // Phase 2: a Facebook Page asset gets its OWN long-lived Page
            // Access Token (distinct from, and longer-lived than, the
            // User Access Token everything else in this loop stores) —
            // see MetaOAuthProvider::exchangePageAccessToken()'s
            // docblock. Falls back to the user token, logged, rather than
            // failing the whole bind() call over one Page's exchange —
            // Instagram/Ad Account rows in the same request must still
            // bind successfully.
            $accessToken = $pending['access_token'];

            if ($pending['provider'] === 'meta' && $offered['asset_type'] === 'facebook_page') {
                $pageAccessToken = app(MetaOAuthProvider::class)->exchangePageAccessToken(
                    $pending['access_token'],
                    $offered['provider_id']
                );

                if ($pageAccessToken) {
                    $accessToken = $pageAccessToken;
                } else {
                    Log::warning('Meta Page Access Token exchange failed; falling back to the User Access Token.', [
                        'account_id' => $account->id,
                        'page_id' => $offered['provider_id'],
                    ]);
                }
            }

            $socialAccount = SocialAccount::updateOrCreate(
                [
                    'account_id' => $account->id,
                    'provider' => $pending['provider'],
                    'provider_id' => $offered['provider_id'],
                ],
                [
                    'asset_type' => $offered['asset_type'],
                    'name' => $offered['name'],
                    'avatar_url' => $offered['avatar_url'],
                    'access_token' => $accessToken,
                    'refresh_token' => $pending['refresh_token'],
                    'token_expires_at' => $pending['expires_in'] ? now()->addSeconds((int) $pending['expires_in']) : null,
                    'health_status' => SocialAccount::HEALTH_CONNECTED,
                ]
            );

            $bound[] = $this->present($socialAccount);
        }

        Cache::forget($cacheKey);

        return response()->json(['message' => 'Social account(s) connected.', 'data' => $bound], 201);
    }

    /** GET /api/social/accounts */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $accounts = SocialAccount::query()->forAccount($account->id)->latest()->get();

        return response()->json(['data' => $accounts->map(fn (SocialAccount $a) => $this->present($a))]);
    }

    /** DELETE /api/social/accounts/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $socialAccount = SocialAccount::query()->forAccount($account->id)->findOrFail($id);
        $socialAccount->delete();

        return response()->json(['message' => 'Disconnected.']);
    }

    private function assertProviderReachableFor(Account $account, string $provider): void
    {
        $enabled = match ($provider) {
            'meta' => $account->allow_facebook || $account->allow_instagram,
            'linkedin' => $account->allow_linkedin,
            'google' => $account->allow_youtube,
            default => false,
        };

        abort_unless($enabled, 403, 'This account is not permitted to connect a '.ucfirst($provider).' account. Ask a Super Admin to enable it.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SocialAccount $socialAccount): array
    {
        return [
            'id' => $socialAccount->id,
            'provider' => $socialAccount->provider,
            'asset_type' => $socialAccount->asset_type,
            'provider_id' => $socialAccount->provider_id,
            'name' => $socialAccount->name,
            'avatar_url' => $socialAccount->avatar_url,
            'health_status' => $socialAccount->health_status,
            'token_expires_at' => $socialAccount->token_expires_at?->toIso8601String(),
            'created_at' => $socialAccount->created_at?->toIso8601String(),
        ];
    }

    /**
     * A tiny static HTML page (not JSON — this response is loaded directly
     * by the OAuth popup window, not fetched by axios) that hands the
     * result back to the SPA via postMessage and closes itself. targetOrigin
     * is locked to this request's own scheme+host, i.e. wherever
     * backend-api itself is served from — frontend-app must be configured
     * to listen for messages from that origin (see socialService.ts).
     */
    private function popupResponse(array $payload): Response
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        // Locked to the configured SPA origin (config/services.php ->
        // services.frontend.url, set via FRONTEND_URL in .env) so a
        // malicious page cannot receive this payload merely by being the
        // one that happened to open the popup. Falls back to '*' only
        // when FRONTEND_URL is unset, so local/dev setups that never
        // configured it don't silently break the whole connect flow —
        // the compensating control either way is that `nonce` is
        // single-use and re-validated against the authenticated caller's
        // own account_id in bind(), so a leaked nonce alone cannot bind
        // an asset to a different tenant.
        $targetOrigin = config('services.frontend.url') ?: '*';
        $targetOriginJson = json_encode($targetOrigin, JSON_THROW_ON_ERROR);

        $html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>Connecting…</title></head>
<body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;color:#4B5563;">
<p>You can close this window.</p>
<script>
  (function () {
    var payload = {$json};
    var targetOrigin = {$targetOriginJson};
    if (window.opener) {
      window.opener.postMessage(payload, targetOrigin);
    }
    window.close();
  })();
</script>
</body></html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html');
    }
}
