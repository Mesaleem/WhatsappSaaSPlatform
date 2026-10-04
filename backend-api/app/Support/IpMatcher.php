<?php

namespace App\Support;

/** IPv4/IPv6 normalisation and CIDR matching with no dependency beyond inet_pton. IPv4-mapped IPv6 is compared as IPv4. */
final class IpMatcher
{
    /** Canonical text form, or null when $ip is not an IP address. */
    public static function normalize(?string $ip): ?string
    {
        $packed = self::pack($ip);

        return $packed === null ? null : inet_ntop($packed);
    }

    /** "ip" or "ip/bits" in canonical form, or null when invalid. */
    public static function normalizeEntry(string $entry): ?string
    {
        $entry = trim($entry);
        if (! str_contains($entry, '/')) {
            return self::normalize($entry);
        }
        [$ip, $bits] = explode('/', $entry, 2);
        $packed = self::pack($ip);
        if ($packed === null || ! ctype_digit($bits)) {
            return null;
        }
        $max = strlen($packed) * 8;
        if ((int) $bits > $max) {
            return null;
        }

        return inet_ntop($packed).'/'.(int) $bits;
    }

    /** @param list<string> $entries canonical entries from normalizeEntry() */
    public static function matches(?string $ip, array $entries): bool
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return false;
        }
        foreach ($entries as $entry) {
            [$net, $bits] = array_pad(explode('/', (string) $entry, 2), 2, null);
            $netPacked = self::pack($net);
            if ($netPacked === null || strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : (int) $bits;
            if (self::prefixEquals($packed, $netPacked, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function pack(?string $ip): ?string
    {
        if ($ip === null || $ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        // ::ffff:a.b.c.d is the same host as a.b.c.d
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    private static function prefixEquals(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
