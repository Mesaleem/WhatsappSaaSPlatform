<?php

namespace App\Services\Payment;

use App\Models\PaymentGatewaySetting;
use RuntimeException;

/**
 * Strategy Pattern factory — mirrors App\Services\WhatsApp\WhatsAppEngineFactory
 * (Module 5). Resolves a configured, enabled gateway driver by name, using
 * whichever credential set (test/live) that gateway's `mode` currently
 * points to.
 */
class PaymentGatewayFactory
{
    public const SUPPORTED_GATEWAYS = ['razorpay', 'stripe'];

    public static function make(string $gateway): PaymentGatewayInterface
    {
        if (! in_array($gateway, self::SUPPORTED_GATEWAYS, true)) {
            throw new RuntimeException("Unsupported payment gateway '{$gateway}'.");
        }

        $settings = PaymentGatewaySetting::findByGatewayCached($gateway);

        if (! $settings || ! $settings->is_enabled) {
            throw new RuntimeException(ucfirst($gateway).' is not enabled. An account Super Admin must configure and enable it first.');
        }

        if (! $settings->isFullyConfigured()) {
            throw new RuntimeException(ucfirst($gateway)." is enabled but missing its {$settings->mode}-mode API keys.");
        }

        return match ($gateway) {
            'razorpay' => new RazorpayGatewayDriver($settings->activeKeyId(), $settings->activeKeySecret()),
            'stripe' => new StripeGatewayDriver($settings->activeKeyId(), $settings->activeKeySecret()),
        };
    }

    /** Used by PaymentWebhookController, which has no authenticated request to resolve a gateway from. */
    public static function settingsFor(string $gateway): ?PaymentGatewaySetting
    {
        return PaymentGatewaySetting::findByGatewayCached($gateway);
    }
}
