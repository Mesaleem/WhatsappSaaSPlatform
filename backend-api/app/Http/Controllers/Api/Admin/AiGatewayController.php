<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProviderSettings;
use App\Services\Ai\AiManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 8 Task 12 (continued) — "Zero-Code Admin UI" for the platform's
 * AI provider/model settings. Same shape as SocialGatewayController
 * (platform configuration, not tenant-scoped — deliberately does not
 * resolve an account), permission:manage-ai-settings instead of
 * manage-social-settings. See AiProviderSettings and the
 * create_ai_provider_settings_table migration for the full design.
 */
class AiGatewayController extends Controller
{
    /** GET /api/admin/ai/provider-settings */
    public function index(): JsonResponse
    {
        $settings = collect(AiProviderSettings::PROVIDERS)
            ->map(fn (string $provider) => $this->present(
                AiProviderSettings::firstOrNew(['provider' => $provider]),
                $provider
            ))
            ->values();

        return response()->json(['data' => $settings]);
    }

    /** POST /api/admin/ai/provider-settings/{provider} — partial update; an omitted api_key is left untouched. */
    public function update(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, AiProviderSettings::PROVIDERS, true), 404, 'Unknown provider.');

        $data = $request->validate([
            'is_enabled' => ['sometimes', 'nullable', 'boolean'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'allowed_models' => ['sometimes', 'nullable', 'array'],
            'allowed_models.*' => ['string', 'max:255'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'tokens_per_credit_override' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $settings = AiProviderSettings::firstOrNew(['provider' => $provider]);
        $settings->fill($data);
        $settings->updated_by = Auth::id();
        $settings->save();

        app(AiManager::class)->flush();

        return response()->json([
            'message' => ucfirst($provider).' AI settings saved.',
            'data' => $this->present($settings, $provider),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(AiProviderSettings $settings, string $provider): array
    {
        return [
            'provider' => $provider,
            'is_enabled' => $settings->is_enabled,
            'model' => $settings->model,
            'allowed_models' => $settings->allowed_models ?? [],
            'api_key_set' => (bool) $settings->api_key,
            'base_url' => $settings->base_url,
            'tokens_per_credit_override' => $settings->tokens_per_credit_override,
            'notes' => $settings->notes,
            'updated_by' => $settings->updated_by,
            'updated_at' => $settings->updated_at,
        ];
    }
}
