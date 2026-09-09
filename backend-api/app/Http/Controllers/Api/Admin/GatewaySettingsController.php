<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewaySetting;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 8, requirement 1 — "Zero-Code Admin UI" for the PLATFORM's own
 * Razorpay/Stripe merchant credentials (used to collect payments FROM
 * tenants), not any per-tenant gateway account. Super-Admin-only
 * (permission:manage-billing-settings) — this is platform configuration,
 * not a tenant-scoped resource, so it deliberately does NOT resolve an
 * account like every other controller since Module 5 does.
 */
class GatewaySettingsController extends Controller
{
    /** GET /api/admin/billing/gateway-settings */
    public function index(): JsonResponse
    {
        $settings = collect(PaymentGatewayFactory::SUPPORTED_GATEWAYS)
            ->map(fn (string $gateway) => $this->present(
                PaymentGatewaySetting::firstOrNew(['gateway' => $gateway])
            ))
            ->values();

        return response()->json(['data' => $settings]);
    }

    /** POST /api/admin/billing/gateway-settings/{gateway} — partial update; omitted secret fields are left untouched. */
    public function update(Request $request, string $gateway): JsonResponse
    {
        abort_unless(in_array($gateway, PaymentGatewayFactory::SUPPORTED_GATEWAYS, true), 404, 'Unknown gateway.');

        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(['test', 'live'])],
            'is_enabled' => ['sometimes', 'boolean'],
            'test_key_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'test_key_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'test_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'live_key_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'live_key_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'live_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $settings = PaymentGatewaySetting::firstOrNew(['gateway' => $gateway]);
        $settings->fill($data);
        $settings->save();

        return response()->json([
            'message' => ucfirst($gateway).' settings saved.',
            'data' => $this->present($settings),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PaymentGatewaySetting $settings): array
    {
        return [
            'gateway' => $settings->gateway,
            'mode' => $settings->mode ?? 'test',
            'is_enabled' => (bool) $settings->is_enabled,
            'is_fully_configured' => $settings->exists ? $settings->isFullyConfigured() : false,
            'test_key_id' => $settings->test_key_id,
            'test_key_secret_set' => (bool) $settings->test_key_secret,
            'test_webhook_secret_set' => (bool) $settings->test_webhook_secret,
            'live_key_id' => $settings->live_key_id,
            'live_key_secret_set' => (bool) $settings->live_key_secret,
            'live_webhook_secret_set' => (bool) $settings->live_webhook_secret,
        ];
    }
}
