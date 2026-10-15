<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Social\SocialTargetGate;
use App\Services\SocialAuth\MetaOAuthProvider;
use App\Services\SocialAuth\SocialOAuthProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Ads Phase C (2026-10-15) — a client with no Meta ad account creates one from
 * WapHub instead of in Meta Business Suite. It works inside the open connect
 * attempt (SocialAuthController's `social_oauth_pending:{nonce}` grant): the
 * Facebook user's token from that attempt lists their Business portfolios and
 * creates the ad account (INR, Asia/Kolkata) in one of them; the new account is
 * added to the attempt's offered assets, so the usual bind connects it with the
 * Page. Kept out of SocialAuthController, which holds no provider-specific code.
 * Meta's API cannot add a card or funds — that stays in Meta billing.
 */
class MetaAdAccountController extends Controller
{
    use ResolvesTenantAccount;

    /** Same lifetime as SocialAuthController's pending grant. */
    private const PENDING_TTL_MINUTES = 10;

    /** GET /api/social/oauth/meta/businesses?nonce= */
    public function businesses(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate(['nonce' => ['required', 'string', 'max:64']]);
        [$pending, $driver] = $this->pendingGrant($request, $account, $data['nonce']);

        try {
            return response()->json(['data' => $driver->listBusinesses((string) $pending['access_token'])]);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'META_BUSINESSES_UNAVAILABLE', 422);
        }
    }

    /** POST /api/social/oauth/meta/ad-accounts {nonce, business_id, name} */
    public function store(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate([
            'nonce' => ['required', 'string', 'max:64'],
            'business_id' => ['required', 'string', 'max:32', 'regex:/^[0-9]+$/'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        [$pending, $driver] = $this->pendingGrant($request, $account, $data['nonce']);

        if ($denial = app(SocialTargetGate::class)->denial($account, 'connect social accounts')) {
            return $this->error($denial['message'], $denial['code'], 403);
        }
        if (! $driver->isEnabledFor($account) || ! $account->hasSocialPlatformEnabled('meta_ad_account') || ! (bool) ($pending['summary']['plan_has_ads'] ?? false)) {
            return $this->error('This account cannot connect a Meta ad account (not in its plan, or not enabled by a Super Admin).', 'META_ADS_NOT_ENABLED', 403);
        }

        try {
            // Only a Business portfolio this Facebook user manages (never an id taken on trust).
            $businessIds = array_column($driver->listBusinesses((string) $pending['access_token']), 'id');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'META_BUSINESSES_UNAVAILABLE', 422);
        }
        if (! in_array($data['business_id'], $businessIds, true)) {
            throw ValidationException::withMessages(['business_id' => 'Choose one of your own Business portfolios.']);
        }

        try {
            $asset = $driver->createAdAccount((string) $pending['access_token'], $data['business_id'], trim($data['name']));
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'META_AD_ACCOUNT_NOT_CREATED', 422);
        }

        Log::info('Meta ad account created from WapHub.', ['account_id' => $account->id, 'user_id' => $request->user()->id, 'business_id' => $data['business_id'], 'ad_account' => $asset['provider_id']]);

        // Re-read under a lock: a bind that consumed the attempt meanwhile must not be brought back to life.
        $key = "social_oauth_pending:{$data['nonce']}";
        $stillOpen = Cache::lock("{$key}:lock", 10)->block(5, function () use ($key, $asset) {
            $current = Cache::get($key);
            if (! is_array($current)) {
                return false;
            }
            $current['assets'][] = $asset;
            $current['summary']['found']['meta_ad_account'] = (int) ($current['summary']['found']['meta_ad_account'] ?? 0) + 1;
            Cache::put($key, $current, now()->addMinutes(self::PENDING_TTL_MINUTES));

            return true;
        });

        if (! $stillOpen) {
            return $this->error("The ad account \"{$asset['name']}\" was created on Meta, but this connection attempt has ended. Connect again and select it.", 'SOCIAL_CONNECT_ATTEMPT_ENDED', 409);
        }

        return response()->json([
            'message' => 'Ad account created. Add a payment method in Meta billing before ads can run.',
            'data' => array_intersect_key($asset, array_flip(['asset_type', 'provider_id', 'name', 'avatar_url'])),
        ], 201);
    }

    /**
     * The open Meta connect attempt for this nonce — only for the account AND user that started it.
     *
     * @return array{0: array<string, mixed>, 1: MetaOAuthProvider}
     */
    private function pendingGrant(Request $request, Account $account, string $nonce): array
    {
        $pending = Cache::get("social_oauth_pending:{$nonce}");

        if (! is_array($pending) || (int) $pending['account_id'] !== (int) $account->id || (int) ($pending['user_id'] ?? 0) !== (int) $request->user()->id) {
            throw ValidationException::withMessages(['nonce' => 'This connection attempt has expired or does not belong to you. Please connect again.']);
        }

        $driver = SocialOAuthProviderFactory::isImplemented((string) $pending['provider']) ? SocialOAuthProviderFactory::make((string) $pending['provider']) : null;
        if (! $driver instanceof MetaOAuthProvider) {
            abort($this->error('Ad accounts can only be created for a Meta connection.', 'SOCIAL_PROVIDER_UNAVAILABLE', 422));
        }

        return [$pending, $driver];
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error_code' => $code], $status);
    }
}
