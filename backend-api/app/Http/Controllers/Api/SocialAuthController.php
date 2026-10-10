<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Services\Social\SocialTargetGate;
use App\Services\SocialAuth\Contracts\SocialOAuthProviderInterface;
use App\Services\SocialAuth\SocialConnectionService;
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
 * Social account connections — provider-agnostic (Phase 1 of the Social
 * expansion built the flow; Phase 9 Task 1 moved every provider-specific
 * decision into the provider drivers, see SocialOAuthProviderInterface).
 * Nothing here knows what "Meta" is.
 *
 * Two-step OAuth-popup flow:
 *  1. redirect()  — authenticated. Builds the provider's consent URL for
 *     the popup. The encrypted `state` carries account, provider, the
 *     initiating user, a one-time nonce and an expiry.
 *  2. callback()  — PUBLIC (the provider redirects the popup; no session).
 *     Verifies `state`, exchanges the code, discovers assets, stashes the
 *     grant server-side under the nonce and postMessages ONLY {nonce,
 *     assets} to the opener — never a token.
 *  3. bind()      — authenticated. Redeems the nonce for the SAME account
 *     AND the SAME user that started the flow and stores the chosen assets.
 *
 * CSRF / account-binding: redirect() returns the nonce to its OWN
 * authenticated caller (the SPA needs it to poll result() — the popup's
 * window.opener postMessage is unreliable once the popup has gone through
 * facebook.com, which severs the opener via Cross-Origin-Opener-Policy).
 * This is safe to hand back over redirect()'s own authenticated response:
 * the actual CSRF/account-binding defense lives in bind() (and result()),
 * which independently re-verifies the nonce's cached grant was started by
 * THIS SAME account_id AND user_id (see bind() below) before redeeming it.
 * So even a nonce an attacker somehow obtained could never bind assets to
 * a different account or user than the one that started that specific
 * authorization — knowing the nonce alone is not enough.
 *
 * AUTHORIZATION: the route group applies auth, tenant.isolation,
 * subscription.guard, permission:manage-social-accounts,
 * module.guard:social_accounts and capability.guard:social. Because those
 * guards bypass a Super Admin and check the CALLER's subscription, every
 * CONNECT step (redirect, bind) also checks the TARGET account itself —
 * active, active subscription, the module, the `social` capability, and
 * the provider being enabled for it (assertTargetMayConnect).
 *
 * Phase 9 Task 6 — the group also runs target.account:social_accounts,social,
 * so a Super Admin acting on a client is held to what that client's own
 * users can do: nothing for a suspended / unentitled / module-off client,
 * reads only for a client whose subscription lapsed. (Before, reads,
 * connection checks and disconnects stayed open to a Super Admin for any
 * selected client.) A Super Admin with no client gets 422
 * (requireTargetAccount — no APP_ENV=local fallback).
 */
class SocialAuthController extends Controller
{
    use ResolvesTenantAccount;

    private const STATE_TTL_MINUTES = 10;

    public const MODULE = 'social_accounts';

    public const CAPABILITY = 'social';

    /** GET /api/social/providers — what this account can connect, and why not. */
    public function providers(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        $providers = array_map(fn (SocialOAuthProviderInterface $driver) => [
            'key' => $driver->key(),
            'label' => $driver->label(),
            'asset_types' => $driver->assetTypes(),
            'capabilities' => $driver->capabilities(),
            'configured' => SocialOAuthProviderFactory::configIfReady($driver->key()) !== null,
            'enabled_for_account' => $driver->isEnabledFor($account),
        ], SocialOAuthProviderFactory::all());

        return response()->json(['data' => $providers]);
    }

    /** GET /api/social/oauth/{provider}/redirect */
    public function redirect(Request $request, string $provider): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $driver = $this->driverOrFail($provider);

        if ($denied = $this->assertTargetMayConnect($account, $driver)) {
            return $denied;
        }

        try {
            $config = SocialOAuthProviderFactory::configFor($provider);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'SOCIAL_PROVIDER_NOT_CONFIGURED', 422);
        }

        $nonce = (string) Str::uuid();

        $state = Crypt::encryptString(json_encode([
            'account_id' => $account->id,
            'provider' => $provider,
            'user_id' => (int) $request->user()->id,
            'nonce' => $nonce,
            'exp' => now()->addMinutes(self::STATE_TTL_MINUTES)->timestamp,
        ], JSON_THROW_ON_ERROR));

        // `nonce` lets the SPA poll result() — the popup's window.opener postMessage is unreliable once the popup has been
        // through facebook.com (Cross-Origin-Opener-Policy severs the opener), so the server keeps the outcome too.
        return response()->json(['url' => $driver->buildAuthorizationUrl($config, $state), 'nonce' => $nonce]);
    }

    /** GET /api/social/callback/{provider} — public; see class docblock. */
    public function callback(Request $request, string $provider): Response
    {
        if (! SocialOAuthProviderFactory::isImplemented($provider)) {
            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'This social provider is not available.']);
        }

        $driver = SocialOAuthProviderFactory::make($provider);

        if ($providerError = $driver->callbackError($request->query())) {
            $this->rememberOutcome(is_string($request->query('state')) ? $request->query('state') : null, $providerError['cancelled'] ? 'cancelled' : 'error', $providerError['message']);

            return $this->popupResponse([
                'type' => $providerError['cancelled'] ? 'social-oauth-cancelled' : 'social-oauth-error',
                'message' => $providerError['message'],
            ]);
        }

        $code = $request->query('code');
        $stateRaw = $request->query('state');

        if (! is_string($code) || $code === '' || ! is_string($stateRaw) || $stateRaw === '') {
            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'The authorization response was incomplete. Please try connecting again.']);
        }

        try {
            $state = json_decode(Crypt::decryptString($stateRaw), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'Invalid or tampered authorization state. Please try connecting again.']);
        }

        if (! is_array($state) || ($state['provider'] ?? null) !== $provider || (int) ($state['exp'] ?? 0) < now()->timestamp || empty($state['nonce'])) {
            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'This authorization link has expired. Please try connecting again.']);
        }

        // Fresh read, not findCached(): the account's current flags decide.
        $account = Account::query()->find((int) ($state['account_id'] ?? 0));

        if (! $account || ! $driver->isEnabledFor($account)) {
            $this->rememberOutcome($stateRaw, 'error', 'This account can no longer connect this provider.');

            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'This account can no longer connect this provider.']);
        }

        try {
            $config = SocialOAuthProviderFactory::configFor($provider);
            $grant = $driver->exchangeCodeForToken($config, $code);
            $assets = $driver->fetchAssets($grant['access_token']);
        } catch (Throwable $e) {
            // Provider error texts are logged for the administrator (they carry
            // no token); the user gets a generic, recoverable message.
            Log::warning('Social OAuth callback failed.', [
                'provider' => $provider, 'account_id' => $account->id,
                'exception' => class_basename($e), 'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            $this->rememberOutcome($stateRaw, 'error', 'The connection could not be completed. Please try again, or ask a Super Admin to check the provider settings.');

            return $this->popupResponse(['type' => 'social-oauth-error', 'message' => 'The connection could not be completed. Please try again, or ask a Super Admin to check the provider settings.']);
        }

        // Only asset types this driver binds AND this tenant's Super Admin enabled.
        $bindableAssets = array_values(array_filter(
            $assets,
            fn (array $asset) => in_array($asset['asset_type'] ?? null, $driver->assetTypes(), true) && $account->hasSocialPlatformEnabled($asset['asset_type'])
        ));

        $nonce = (string) $state['nonce'];
        Cache::put("social_oauth_pending:{$nonce}", [
            'account_id' => $account->id,
            'user_id' => (int) ($state['user_id'] ?? 0),
            'provider' => $provider,
            'access_token' => $grant['access_token'],
            'refresh_token' => $grant['refresh_token'],
            'expires_in' => $grant['expires_in'],
            'assets' => $bindableAssets,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $this->popupResponse([
            'type' => 'social-oauth-success',
            'provider' => $provider,
            'nonce' => $nonce,
            'assets' => array_map(fn (array $a) => array_intersect_key($a, array_flip(['asset_type', 'provider_id', 'name', 'avatar_url'])), $bindableAssets),
        ]);
    }

    /**
     * GET /api/social/oauth/{provider}/result/{nonce} — the SPA polls this while the popup is open. Same outcome the popup
     * tries to postMessage, readable only by the account AND user that started the attempt.
     */
    public function result(Request $request, string $provider, string $nonce): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $userId = (int) $request->user()->id;

        $pending = Cache::get("social_oauth_pending:{$nonce}");

        if (is_array($pending) && (int) $pending['account_id'] === (int) $account->id && (int) ($pending['user_id'] ?? 0) === $userId && ($pending['provider'] ?? null) === $provider) {
            return response()->json([
                'status' => 'success',
                'provider' => $provider,
                'nonce' => $nonce,
                'assets' => array_map(fn (array $a) => array_intersect_key($a, array_flip(['asset_type', 'provider_id', 'name', 'avatar_url'])), $pending['assets'] ?? []),
            ]);
        }

        $outcome = Cache::get("social_oauth_outcome:{$nonce}");

        if (is_array($outcome) && (int) $outcome['account_id'] === (int) $account->id && (int) $outcome['user_id'] === $userId) {
            return response()->json(['status' => $outcome['status'], 'message' => $outcome['message']]);
        }

        return response()->json(['status' => 'pending']);
    }

    /** Keep a failed/cancelled callback outcome for result() — best effort, never throws. */
    private function rememberOutcome(?string $stateRaw, string $status, string $message): void
    {
        try {
            if (! $stateRaw) {
                return;
            }

            $state = json_decode(Crypt::decryptString($stateRaw), true, flags: JSON_THROW_ON_ERROR);

            if (is_array($state) && ! empty($state['nonce'])) {
                Cache::put("social_oauth_outcome:{$state['nonce']}", [
                    'account_id' => (int) ($state['account_id'] ?? 0),
                    'user_id' => (int) ($state['user_id'] ?? 0),
                    'status' => $status,
                    'message' => $message,
                ], now()->addMinutes(self::STATE_TTL_MINUTES));
            }
        } catch (Throwable) {
            // Unreadable state: nothing to key the outcome by.
        }
    }

    /** POST /api/social/accounts/bind */
    public function bind(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        $data = $request->validate([
            'nonce' => ['required', 'string', 'max:64'],
            'selections' => ['required', 'array', 'min:1'],
            'selections.*.asset_type' => ['required', 'string', 'in:'.implode(',', SocialAccount::ASSET_TYPES)],
            'selections.*.provider_id' => ['required', 'string', 'max:191'],
        ]);

        $cacheKey = "social_oauth_pending:{$data['nonce']}";
        $pending = Cache::get($cacheKey);

        // The grant is redeemable only by the account AND the user that started it.
        if (! is_array($pending) || (int) $pending['account_id'] !== (int) $account->id || (int) ($pending['user_id'] ?? 0) !== (int) $request->user()->id) {
            throw ValidationException::withMessages([
                'nonce' => 'This connection attempt has expired or does not belong to you. Please connect again.',
            ]);
        }

        $driver = $this->driverOrFail((string) $pending['provider']);

        if ($denied = $this->assertTargetMayConnect($account, $driver)) {
            return $denied;
        }

        $grant = ['access_token' => $pending['access_token'], 'refresh_token' => $pending['refresh_token'], 'expires_in' => $pending['expires_in']];
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
                    'selections' => 'One of the selected assets was not part of the original authorization — please connect again.',
                ]);
            }

            $credentials = $driver->credentialsForAsset($grant, $offered);

            $socialAccount = SocialAccount::updateOrCreate(
                ['account_id' => $account->id, 'provider' => $driver->key(), 'provider_id' => $offered['provider_id']],
                [
                    'asset_type' => $offered['asset_type'],
                    'name' => $offered['name'],
                    'avatar_url' => $offered['avatar_url'],
                    'access_token' => $credentials['access_token'],
                    'refresh_token' => $credentials['refresh_token'],
                    'token_expires_at' => $credentials['expires_at'],
                    'health_status' => SocialAccount::HEALTH_CONNECTED,
                    'status_reason' => null,
                    'status_checked_at' => now(),
                    'connected_by_user_id' => (int) $request->user()->id,
                    'metadata' => is_array($offered['metadata'] ?? null) ? $offered['metadata'] : null,
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
        $account = $this->requireTargetAccount($request);

        $accounts = SocialAccount::query()->forAccount($account->id)->latest()->get();

        return response()->json(['data' => $accounts->map(fn (SocialAccount $a) => $this->present($a))]);
    }

    /** POST /api/social/accounts/{id}/check — ask the provider whether the connection still works. */
    public function check(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $socialAccount = SocialAccount::query()->forAccount($account->id)->findOrFail($id);

        if (! SocialOAuthProviderFactory::isImplemented($socialAccount->provider)) {
            return $this->error('This provider can no longer be checked.', 'SOCIAL_PROVIDER_UNAVAILABLE', 422);
        }

        // Phase 9 Task 2 — the same check + persistence the scheduled health
        // check uses (SocialConnectionService): an unreachable provider keeps
        // the stored state, a token past its expiry is marked expired without
        // a provider call, and a check of credentials replaced meanwhile is dropped.
        $result = app(SocialConnectionService::class)->check($socialAccount, 'manual');

        return response()->json([
            'data' => $this->present($socialAccount->fresh()),
            'check' => ['status' => $result->status, 'reason' => $result->reason],
        ]);
    }

    /** DELETE /api/social/accounts/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        $socialAccount = SocialAccount::query()->forAccount($account->id)->findOrFail($id);

        $revoked = SocialOAuthProviderFactory::isImplemented($socialAccount->provider)
            && SocialOAuthProviderFactory::make($socialAccount->provider)->revoke($socialAccount);

        // Deleting the row removes its encrypted credentials; records that
        // referenced it (leads, posts, campaigns) keep their data (nullOnDelete).
        $socialAccount->delete();

        return response()->json(['message' => 'Disconnected.', 'revoked_at_provider' => $revoked]);
    }

    private function driverOrFail(string $provider): SocialOAuthProviderInterface
    {
        if (! SocialOAuthProviderFactory::isImplemented($provider)) {
            abort(response()->json([
                'success' => false,
                'message' => 'This social provider is not available.',
                'error_code' => 'SOCIAL_PROVIDER_UNAVAILABLE',
            ], 404));
        }

        return SocialOAuthProviderFactory::make($provider);
    }

    /**
     * The TARGET account itself may connect (see the class docblock): the
     * route guards check the caller's own subscription and bypass a Super
     * Admin, so a Super Admin's selected client or an Agent's sub-client is
     * checked here, fresh from the database.
     */
    private function assertTargetMayConnect(Account $account, SocialOAuthProviderInterface $driver): ?JsonResponse
    {
        // Phase 9 Task 3 — the target checks now live in SocialTargetGate
        // (shared with organic publishing); same order, codes and messages.
        if ($denial = app(SocialTargetGate::class)->denial($account, 'connect social accounts')) {
            $message = match ($denial['code']) {
                'CLIENT_ACCOUNT_SUSPENDED' => 'This account is suspended. Social accounts cannot be connected.',
                default => $denial['message'],
            };

            return $this->error($message, $denial['code'], 403);
        }

        return $driver->isEnabledFor($account)
            ? null
            : $this->error('This account is not permitted to connect '.$driver->label().'. Ask a Super Admin to enable it.', 'SOCIAL_PROVIDER_NOT_ENABLED', 403);
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error_code' => $code], $status);
    }

    /**
     * @return array<string, mixed> the API view — never a token or a refresh token
     */
    private function present(SocialAccount $socialAccount): array
    {
        $capabilities = SocialOAuthProviderFactory::isImplemented($socialAccount->provider)
            ? (SocialOAuthProviderFactory::make($socialAccount->provider)->capabilities()[$socialAccount->asset_type] ?? [])
            : [];

        return [
            'id' => $socialAccount->id,
            'provider' => $socialAccount->provider,
            'asset_type' => $socialAccount->asset_type,
            'provider_id' => $socialAccount->provider_id,
            'name' => $socialAccount->name,
            'avatar_url' => $socialAccount->avatar_url,
            'health_status' => $socialAccount->health_status,
            'connection_status' => $socialAccount->connectionStatus(),
            'status_reason' => $socialAccount->status_reason,
            'status_checked_at' => $socialAccount->status_checked_at?->toIso8601String(),
            'token_expires_at' => $socialAccount->token_expires_at?->toIso8601String(),
            'capabilities' => $capabilities,
            'created_at' => $socialAccount->created_at?->toIso8601String(),
        ];
    }

    /**
     * A tiny static HTML page (loaded by the popup itself) that hands the
     * result to the SPA via postMessage and closes. The target origin is the
     * configured SPA origin (services.frontend.url / FRONTEND_URL). Phase 9
     * Task 1: the '*' fallback is limited to local/testing — elsewhere an
     * unset FRONTEND_URL withholds the payload (logged), because '*' would
     * hand the one-time nonce to whichever page opened the popup.
     */
    private function popupResponse(array $payload): Response
    {
        $targetOrigin = config('services.frontend.url') ?: (app()->environment('local', 'testing') ? '*' : null);

        if ($targetOrigin === null) {
            Log::error('Social OAuth popup: FRONTEND_URL is not configured, so the result cannot be returned to the app safely.');
            $payload = ['type' => 'social-oauth-error', 'message' => 'The platform is not configured to finish social connections (missing frontend URL). Please contact support.'];
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
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
    if (window.opener && targetOrigin) {
      window.opener.postMessage(payload, targetOrigin);
    }
    window.close();
  })();
</script>
</body></html>
HTML;

        return response($html, 200)
            ->header('Content-Type', 'text/html')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
