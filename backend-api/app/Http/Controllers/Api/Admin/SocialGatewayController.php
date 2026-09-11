<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SocialProviderConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * "Zero-Code Admin UI" for the PLATFORM's own Meta/LinkedIn/Google OAuth
 * App credentials — same shape as Admin\GatewaySettingsController
 * (Module 8), permission:manage-social-settings instead of
 * manage-billing-settings. Platform configuration, not tenant-scoped, so
 * this deliberately does NOT resolve an account.
 */
class SocialGatewayController extends Controller
{
    /** GET /api/admin/social/provider-configs */
    public function index(): JsonResponse
    {
        $configs = collect(SocialProviderConfig::PROVIDERS)
            ->map(fn (string $provider) => $this->present(
                SocialProviderConfig::firstOrNew(['provider' => $provider])
            ))
            ->values();

        return response()->json(['data' => $configs]);
    }

    /** POST /api/admin/social/provider-configs/{provider} — partial update; omitted secret fields are left untouched. */
    public function update(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, SocialProviderConfig::PROVIDERS, true), 404, 'Unknown provider.');

        $data = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'redirect_uri' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            // Phase 2: Super Admin may paste a token they already
            // registered with the provider's dashboard, instead of only
            // being able to rotate a backend-generated one.
            'webhook_verify_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Rotate the shared App-level webhook verify token to a fresh
            // random value; frontend shows this once so Super Admin can
            // paste it into Meta's App Dashboard webhook subscription
            // screen. Ignored when `webhook_verify_token` is also present
            // in the same request — an explicit value always wins over
            // "generate me one".
            'regenerate_webhook_verify_token' => ['sometimes', 'boolean'],
        ]);

        $config = SocialProviderConfig::firstOrNew(['provider' => $provider]);
        $config->fill(collect($data)->except(['regenerate_webhook_verify_token', 'webhook_verify_token'])->all());

        if (array_key_exists('webhook_verify_token', $data) && $data['webhook_verify_token'] !== null) {
            $config->webhook_verify_token = $data['webhook_verify_token'];
        } elseif (! empty($data['regenerate_webhook_verify_token']) || ! $config->webhook_verify_token) {
            $config->webhook_verify_token = Str::random(40);
        }

        $config->save();

        return response()->json([
            'message' => ucfirst($provider).' settings saved.',
            'data' => $this->present($config),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SocialProviderConfig $config): array
    {
        return [
            'provider' => $config->provider,
            'is_active' => (bool) $config->is_active,
            'is_fully_configured' => $config->exists ? $config->isFullyConfigured() : false,
            'client_id_set' => (bool) $config->client_id,
            'client_secret_set' => (bool) $config->client_secret,
            'redirect_uri' => $config->redirect_uri,
            'webhook_verify_token_set' => (bool) $config->webhook_verify_token,
            'webhook_url' => url("/api/social/webhook/{$config->provider}"),
        ];
    }
}
