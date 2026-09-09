<?php

namespace App\Support;

/**
 * Normalizes a user-entered phone number into the bare digit-only,
 * country-code-prefixed form the WhatsApp engines expect (e.g. Baileys
 * builds the JID as "{digits}@s.whatsapp.net" — see BaileysDriver).
 *
 * Applied once, in ProcessPaymentAlertJob right before dispatch to the
 * driver, so every entry point (single-alert send, CSV bulk-upload, the
 * external API) gets the same normalization without duplicating it in
 * three controllers.
 */
class PhoneNumberNormalizer
{
    /** Assumed when a bare 10-digit local number has no country code at all. */
    private const DEFAULT_COUNTRY_CODE = '91';

    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        // Local trunk-prefix form, e.g. "09876543210" -> "9876543210".
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Bare 10-digit mobile number, no country code at all.
        if (strlen($digits) === 10) {
            $digits = self::DEFAULT_COUNTRY_CODE.$digits;
        }

        return $digits;
    }
}
