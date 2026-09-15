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

    /**
     * "Select an existing group" extension — the reverse direction of
     * this class's usual job: a Baileys participant JID (e.g.
     * "919876543210@s.whatsapp.net", or "919876543210:12@s.whatsapp.net"
     * for a multi-device linked companion, or "...@lid" for WhatsApp's
     * newer privacy-preserving group-participant identifiers) back into
     * the same bare-digit form normalize() produces, so an imported
     * native group's members line up with every other ContactGroupMember
     * row (unique(group_id, phone_number), matched against the SAME
     * digit form the outbound JID-building side already uses — see
     * NativeWhatsAppGroupService::toJids()).
     *
     * Returns '' for a JID this app cannot turn into a callable phone
     * number (an @lid identifier has no phone number embedded in it at
     * all — WhatsApp deliberately hides it — so it is not "a phone
     * number formatted oddly", it is not a phone number). Callers must
     * filter out empty results themselves; this method never throws.
     */
    public static function digitsFromJid(string $jid): string
    {
        if (str_ends_with($jid, '@lid')) {
            return '';
        }

        $user = explode('@', $jid, 2)[0] ?? '';
        $user = explode(':', $user, 2)[0] ?? '';

        return self::normalize($user);
    }
}
