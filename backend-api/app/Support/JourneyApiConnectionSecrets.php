<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Phase 8 Task 15 — the same credential-pair protection JourneySecrets
 * applies to an `api` node's `headers`/`query`, applied to a
 * JourneyApiConnection's own `headers`/`query` (a flat list of
 * {key, value} pairs — no nested graph, so JourneySecrets' own methods,
 * which walk a whole `graph_data` document, do not fit this shape
 * directly). This class is a sibling, not a duplicate: it reuses
 * JourneySecrets::isSensitiveName() / ::PREFIX / ::MASK verbatim, so a
 * value protected here is read back exactly the same way a journey
 * node's own secret pair already is (JourneySecrets::reveal()).
 */
final class JourneyApiConnectionSecrets
{
    /**
     * Pairs as they may be shown to a client: every credential-named
     * pair's value replaced with JourneySecrets::MASK.
     *
     * @param  array<int, mixed>|null  $pairs
     * @return array<int, array<string, mixed>>
     */
    public static function mask(?array $pairs): array
    {
        $out = [];

        foreach ($pairs ?? [] as $pair) {
            if (! is_array($pair)) {
                continue;
            }

            $key = $pair['key'] ?? null;
            $value = $pair['value'] ?? null;

            if (JourneySecrets::isSensitiveName($key) && $value !== null && $value !== '') {
                $pair['value'] = JourneySecrets::MASK;
                $pair['masked'] = true;
            }

            $out[] = ['key' => $key, 'value' => $pair['value'], 'masked' => $pair['masked'] ?? false];
        }

        return $out;
    }

    /**
     * Pairs as they are STORED: a credential-named plaintext value
     * encrypted; JourneySecrets::MASK resolved to the value already
     * stored under the same (case-insensitive, trimmed) key in
     * $storedPairs; an already-encrypted value kept as-is. A MASK that
     * resolves to nothing throws — never stored as if it were the
     * secret (mirrors JourneySecrets::unresolvedMaskErrors()'s refusal,
     * just raised immediately rather than collected since this is a
     * single flat list, not a whole graph of nodes).
     *
     * @param  array<int, mixed>|null  $pairs
     * @param  array<int, mixed>|null  $storedPairs
     * @return array<int, array<string, mixed>>
     *
     * @throws \InvalidArgumentException a MASK with no saved value to keep
     */
    public static function protect(?array $pairs, ?array $storedPairs): array
    {
        $stored = [];

        foreach ($storedPairs ?? [] as $pair) {
            if (is_array($pair) && is_string($pair['key'] ?? null)) {
                $stored[self::slot($pair['key'])] = $pair['value'] ?? null;
            }
        }

        $out = [];

        foreach ($pairs ?? [] as $pair) {
            if (! is_array($pair) || ! is_string($pair['key'] ?? null) || trim($pair['key']) === '') {
                continue;
            }

            $key = $pair['key'];
            $value = $pair['value'] ?? null;

            if (! JourneySecrets::isSensitiveName($key)) {
                // Plain configuration (e.g. Content-Type) — never encrypted.
                $out[] = ['key' => $key, 'value' => is_scalar($value) ? $value : null];

                continue;
            }

            if ($value === JourneySecrets::MASK) {
                if (! isset($stored[self::slot($key)])) {
                    throw new \InvalidArgumentException("The value for '{$key}' has no saved secret to keep — enter it again.");
                }

                $out[] = ['key' => $key, 'value' => $stored[self::slot($key)]];

                continue;
            }

            if ($value === null || $value === '') {
                $out[] = ['key' => $key, 'value' => $value];

                continue;
            }

            $out[] = ['key' => $key, 'value' => JourneySecrets::isEncrypted($value) ? $value : JourneySecrets::PREFIX.Crypt::encryptString((string) $value)];
        }

        return $out;
    }

    private static function slot(string $key): string
    {
        return strtolower(trim($key));
    }
}
