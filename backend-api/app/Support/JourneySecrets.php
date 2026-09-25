<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * P5-6 — Journey secret / configuration protection.
 *
 * WHAT IS SECRET (inspected, not assumed). A journey is one
 * `whatsapp_flows.graph_data` JSON document (copied verbatim into
 * `whatsapp_flow_versions.graph_data` and, via LogsActivity, into
 * `activity_logs.old_values/new_values`). Of the 32 node types only the
 * `api` node has fields whose purpose is to carry request credentials:
 *
 *   - `data.headers`  [{key, value}]  — e.g. Authorization, X-Api-Key
 *   - `data.query`    [{key, value}]  — e.g. ?api_key=, ?token=
 *
 * Only a pair whose NAME is credential-like (isSensitiveName()) is
 * treated as secret; `Content-Type: application/json` stays readable
 * configuration. Every other field is ordinary configuration — message
 * text, media URLs, template ids, and the *reference* fields
 * (`credentialRef`, `gatewayRef`, `agentId`) which name a server-side
 * configuration and hold no secret themselves. Credentials embedded in
 * the `api` URL (userinfo, or a credential-named query parameter) are
 * refused at save time instead (urlCredentialErrors()), because a URL is
 * shown on the canvas and cannot be masked in part.
 *
 * STORAGE. A secret value is stored as PREFIX . Crypt::encryptString()
 * — Laravel's own encrypter (APP_KEY, AES-256-CBC + MAC), the same
 * facility the `encrypted` casts on PaymentGatewaySetting / MailSetting
 * use. No custom cryptography. Encryption is applied by the models
 * (WhatsAppFlow / WhatsAppFlowVersion `saving`), so every writer is
 * covered, not only the API.
 *
 * READ. Every serialization of a journey graph replaces a secret value
 * with MASK and adds `masked: true` to the pair (mask()); audit payloads
 * are masked the same way. Ciphertext never leaves the server either.
 *
 * UPDATE WITHOUT RE-ENTRY. A client that sends back MASK for a pair means
 * "keep the stored value": protect() copies the stored value of the same
 * node id + field + header name from the journey's current graph. A MASK
 * that matches nothing is refused (unresolvedMaskErrors()) — it is never
 * stored as if it were the secret.
 *
 * Decryption for a future runtime goes through reveal(); no current
 * runtime path needs it (the `api` node is not runtime-executable —
 * JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES).
 */
final class JourneySecrets
{
    public const MASK = '********';

    public const PREFIX = 'enc:v1:';

    /** node type => config fields holding [{key, value}] pairs that may carry credentials. */
    public const SECRET_PAIR_FIELDS = ['api' => ['headers', 'query']];

    /** A normalized (lowercase, alphanumerics only) name containing one of these is a credential. */
    private const SENSITIVE_FRAGMENTS = [
        'auth', 'token', 'secret', 'password', 'passwd', 'passphrase', 'apikey', 'accesskey',
        'privatekey', 'credential', 'cookie', 'signature', 'session', 'bearer', 'jwt',
    ];

    /** Normalized names that are credentials on their own. */
    private const SENSITIVE_EXACT = ['key', 'pwd', 'pass', 'sig', 'xkey'];

    public static function isSensitiveName(mixed $name): bool
    {
        if (! is_string($name)) {
            return false;
        }

        $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, self::SENSITIVE_EXACT, true)) {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function isEncrypted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /** The plaintext of a stored secret value (application layer only — never for a response). */
    public static function reveal(mixed $stored): ?string
    {
        if (! is_string($stored)) {
            return $stored === null ? null : (string) $stored;
        }

        if (! self::isEncrypted($stored)) {
            return $stored;
        }

        return Crypt::decryptString(substr($stored, strlen(self::PREFIX)));
    }

    /**
     * The graph as it may be shown to a client or written to an audit row:
     * every secret value replaced by MASK.
     *
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public static function mask(array $graph): array
    {
        return self::mapSecretPairs($graph, static function (array $pair): array {
            if (self::hasValue($pair['value'] ?? null)) {
                $pair['value'] = self::MASK;
                $pair['masked'] = true;
            }

            return $pair;
        });
    }

    /** Same as mask(), for a raw JSON attribute (audit payloads). */
    public static function maskJson(mixed $json): mixed
    {
        if (! is_string($json)) {
            return is_array($json) ? self::mask($json) : $json;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? json_encode(self::mask($decoded)) : $json;
    }

    /**
     * The graph as it is STORED: plaintext secrets encrypted, MASK resolved
     * to the value already stored for the same node/field/name in
     * $previous, `masked` flags dropped. Idempotent: an already-encrypted
     * value is kept as-is.
     *
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public static function protect(array $graph, ?array $previous): array
    {
        $stored = self::storedSecrets($previous);

        return self::mapSecretPairs($graph, static function (array $pair, string $nodeId, string $field) use ($stored): array {
            unset($pair['masked']);
            $value = $pair['value'] ?? null;

            if ($value === self::MASK) {
                $kept = $stored[self::slot($nodeId, $field, $pair['key'])] ?? null;
                // Unresolvable MASK (refused 422 by the API): never store the mask as the secret.
                $value = $kept ?? '';
            }

            if (self::hasValue($value) && ! self::isEncrypted($value)) {
                $value = self::PREFIX.Crypt::encryptString(is_string($value) ? $value : (string) json_encode($value));
            }

            $pair['value'] = $value;

            return $pair;
        });
    }

    /**
     * Validation error keys for MASK values that do not correspond to a
     * stored secret (a new journey, a renamed header, another node's id).
     * Messages never contain a value.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<string, mixed>|null  $previous
     * @return array<string, array<int, string>>
     */
    public static function unresolvedMaskErrors(array $nodes, ?array $previous): array
    {
        $stored = self::storedSecrets($previous);
        $errors = [];

        foreach ($nodes as $i => $node) {
            foreach (self::secretPairs($node) as [$field, $j, $pair]) {
                if (($pair['value'] ?? null) === self::MASK && ! isset($stored[self::slot((string) $node['id'], $field, $pair['key'])])) {
                    $errors["graph_data.nodes.{$i}.data.{$field}.{$j}.value"] = ['This secret has no saved value to keep — enter it again.'];
                }
            }
        }

        return $errors;
    }

    /**
     * Credentials written into an `api` node's URL: userinfo, or a
     * credential-named query parameter. Refused so they are moved to the
     * encrypted header/query fields. Messages never contain a value.
     *
     * @param  array<int, mixed>  $nodes
     * @return array<string, array<int, string>>
     */
    public static function urlCredentialErrors(array $nodes): array
    {
        $errors = [];

        foreach ($nodes as $i => $node) {
            if (! is_array($node) || ! isset(self::SECRET_PAIR_FIELDS[$node['type'] ?? null])) {
                continue;
            }

            $url = $node['data']['url'] ?? null;

            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $parts = parse_url(trim($url));

            if ($parts === false) {
                continue;
            }

            if (isset($parts['user']) || isset($parts['pass'])) {
                $errors["graph_data.nodes.{$i}.data.url"] = ['The URL must not contain a username or password. Put credentials in a header — header secrets are encrypted.'];

                continue;
            }

            parse_str((string) ($parts['query'] ?? ''), $query);

            foreach (array_keys($query) as $name) {
                if (self::isSensitiveName((string) $name)) {
                    $errors["graph_data.nodes.{$i}.data.url"] = ['The URL must not carry a credential query parameter. Use the Query parameters or Headers fields — their secrets are encrypted.'];

                    break;
                }
            }
        }

        return $errors;
    }

    /** @return array<string, string> slot => stored (encrypted or legacy plaintext) value */
    private static function storedSecrets(?array $previous): array
    {
        $stored = [];

        foreach ((is_array($previous) ? ($previous['nodes'] ?? []) : []) as $node) {
            foreach (self::secretPairs($node) as [$field, , $pair]) {
                $value = $pair['value'] ?? null;
                $slot = self::slot((string) $node['id'], $field, $pair['key']);

                if (self::hasValue($value) && $value !== self::MASK && ! isset($stored[$slot])) {
                    $stored[$slot] = $value;
                }
            }
        }

        return $stored;
    }

    /**
     * The secret pairs of one node, as [field, index, pair].
     *
     * @return list<array{0: string, 1: int|string, 2: array<string, mixed>}>
     */
    private static function secretPairs(mixed $node): array
    {
        if (! is_array($node) || ! is_string($node['type'] ?? null) || ! isset($node['id'])) {
            return [];
        }

        $fields = self::SECRET_PAIR_FIELDS[$node['type']] ?? [];
        $data = $node['data'] ?? null;
        $pairs = [];

        if (! is_array($data)) {
            return [];
        }

        foreach ($fields as $field) {
            if (! is_array($data[$field] ?? null)) {
                continue;
            }

            foreach ($data[$field] as $j => $pair) {
                if (is_array($pair) && self::isSensitiveName($pair['key'] ?? null)) {
                    $pairs[] = [$field, $j, $pair];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $graph
     * @param  callable(array<string, mixed>, string, string): array<string, mixed>  $fn
     * @return array<string, mixed>
     */
    private static function mapSecretPairs(array $graph, callable $fn): array
    {
        if (! is_array($graph['nodes'] ?? null)) {
            return $graph;
        }

        foreach ($graph['nodes'] as $i => $node) {
            foreach (self::secretPairs($node) as [$field, $j, $pair]) {
                $graph['nodes'][$i]['data'][$field][$j] = $fn($pair, (string) $node['id'], $field);
            }
        }

        return $graph;
    }

    private static function slot(string $nodeId, string $field, mixed $name): string
    {
        return $nodeId."\0".$field."\0".strtolower(trim((string) $name));
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && ! is_array($value);
    }
}
