<?php

namespace App\Services\Billing;

use App\Models\PlatformSetting;

/**
 * The Super Admin's price list for building custom plans (owner, 2026-10-15):
 * what each feature and each unit of a limit adds to a custom plan's price,
 * plus Meta's per-message cost so the calculator can show the margin.
 *
 * Stored as one JSON value in platform_settings; missing keys fall back to
 * DEFAULTS. The calculation itself runs in the Plans screen (live, as the
 * Super Admin ticks features) — the price the Super Admin then saves on the
 * plan is the only price that is ever charged.
 *
 * Defaults: CRM (₹899) and Meta Growth (≈ ₹4,800 vs ₹4,999) come out at
 * about their list prices; Starter (₹599) and Meta Pro (₹7,999) add up higher
 * because the public plans are priced as bundles. On QR, templates and
 * broadcasts are included (no Meta cost) and are not priced.
 */
final class CustomPlanPricing
{
    public const SETTING_KEY = 'custom_plan_price_list';

    public const DEFAULTS = [
        // Platform: dashboard, analytics, billing, team, notifications — includes base_users_included users.
        'base_fee' => 299,
        'base_users_included' => 2,
        'per_user' => 100,
        'unlimited_users' => 1500,
        // WhatsApp engine (QR includes unlimited messages).
        'engine' => ['qr' => 300, 'meta' => 499, 'none' => 0],
        // Meta API messages, per message: price charged (we pay Meta).
        'per_message' => 0.20,
        'per_marketing_message' => 1.10,
        // Features (capability slug => price per month). A capability not listed costs 0 until set.
        'features' => [
            'crm' => 500,
            'journey_automation' => 300,
            'ads' => 500,
            'social' => 300,
            'external_api' => 100,
            'ai' => 300,
            'whatsapp_groups' => 200,
            'email' => 100,
            'payments' => 100,
            'commerce' => 300,
            'custom_code' => 200,
            'billing_collections' => 200,
        ],
        // Limits: price per unit, and a flat price when unlimited.
        'per_template' => 10,
        'unlimited_templates' => 500,
        'per_journey' => 50,
        'unlimited_journeys' => 1000,
        'per_broadcast' => 25,
        'unlimited_broadcasts' => 1000,
        // Meta's charges to us (India, per message, before GST) — for the cost / margin line only.
        'meta_marketing_cost' => 0.8631,
        'meta_utility_cost' => 0.115,
        'gst_percent' => 18,
    ];

    /** The price list: stored values over the defaults. */
    public static function get(): array
    {
        $stored = json_decode((string) PlatformSetting::get(self::SETTING_KEY), true);

        return self::merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function put(array $prices): array
    {
        $merged = self::merge(self::DEFAULTS, $prices);
        PlatformSetting::put(self::SETTING_KEY, json_encode($merged));

        return $merged;
    }

    /** Defaults overlaid with $values, one level deep for engine/features (new keys allowed there). */
    private static function merge(array $defaults, array $values): array
    {
        $out = $defaults;
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                continue;
            }
            $out[$key] = is_array($defaults[$key]) && is_array($value)
                ? array_map('floatval', $value + $defaults[$key])
                : (float) $value;
        }

        return $out;
    }
}
