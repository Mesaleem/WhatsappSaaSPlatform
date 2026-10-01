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
 * F-5.3 extends the SAME mechanism (no second secret system):
 *   - credential-bearing NAMES are matched case-insensitively and by
 *     suffix, so vendor headers such as `Ocp-Apim-Subscription-Key` and
 *     `x-functions-key`, and the query parameter `code`, are covered;
 *   - `headers` / `query` may be a list of {key, value} pairs OR a
 *     name => value map; both shapes are protected;
 *   - the `api` node's free-text `body` is inspected: when it is a JSON
 *     document or a form-encoded string, only the values of
 *     credential-named fields are secret (the rest of the body stays
 *     readable); a body that is neither is plain text we cannot parse and
 *     is NOT touched;
 *   - a credential already stored in an `api` URL (saved before URL
 *     credentials were refused) is masked in every serialization.
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

    /** node type => config fields holding a free-text request body that may carry credential-named fields. */
    public const SECRET_BODY_FIELDS = ['api' => ['body']];

    /** A normalized (lowercase, alphanumerics only) name containing one of these is a credential. */
    private const SENSITIVE_FRAGMENTS = [
        'auth', 'token', 'secret', 'password', 'passwd', 'passphrase', 'apikey', 'accesskey',
        'privatekey', 'credential', 'cookie', 'signature', 'session', 'bearer', 'jwt',
    ];

    /** Normalized names that are credentials on their own. */
    private const SENSITIVE_EXACT = ['key', 'pwd', 'pass', 'sig', 'xkey', 'code', 'otp', 'pin'];

    /**
     * A normalized name ENDING in one of these is a credential: it catches
     * vendor-specific key headers (ocpapimsubscriptionkey, xfunctionskey,
     * xapikey, …) without enumerating each vendor. Over-matching (e.g.
     * `Idempotency-Key`) only encrypts + masks a harmless value — the safe
     * direction for a credential rule.
     */
    private const SENSITIVE_SUFFIXES = ['key'];

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

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
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
        $graph = self::mapSecretPairs($graph, static function (array $pair): array {
            if (self::hasValue($pair['value'] ?? null)) {
                $pair['value'] = self::MASK;
                $pair['masked'] = true;
            }

            return $pair;
        });

        $graph = self::mapBodySecrets($graph, static fn (string $path, mixed $value): mixed => self::MASK);

        return self::maskUrls($graph);
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

        $graph = self::mapSecretPairs($graph, static function (array $pair, string $nodeId, string $field) use ($stored): array {
            unset($pair['masked']);
            $pair['value'] = self::protectValue($pair['value'] ?? null, $stored[self::slot($nodeId, $field, $pair['key'])] ?? null);

            return $pair;
        });

        return self::mapBodySecrets($graph, static function (string $path, mixed $value, string $nodeId) use ($stored): mixed {
            return self::protectValue($value, $stored[self::slot($nodeId, 'body', $path)] ?? null);
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
            if (! is_array($node) || ! isset($node['id'])) {
                continue;
            }

            foreach (self::secretPairs($node) as [$field, $j, $pair]) {
                if (($pair['value'] ?? null) === self::MASK && ! isset($stored[self::slot((string) $node['id'], $field, $pair['key'])])) {
                    $errors["graph_data.nodes.{$i}.data.{$field}.{$j}.value"] = ['This secret has no saved value to keep — enter it again.'];
                }
            }

            foreach (self::bodyFields($node) as $field) {
                foreach (self::bodySecretEntries($node['data'][$field] ?? null) as $path => $value) {
                    if ($value === self::MASK && ! isset($stored[self::slot((string) $node['id'], $field, $path)])) {
                        $errors["graph_data.nodes.{$i}.data.{$field}"] = ['A masked value in the request body has no saved secret to keep — enter it again.'];

                        break;
                    }
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

    /** One stored/submitted secret value → its stored form (MASK resolved, plaintext encrypted). */
    private static function protectValue(mixed $value, mixed $kept): mixed
    {
        if ($value === self::MASK) {
            // Unresolvable MASK (refused 422 by the API): never store the mask as the secret.
            $value = $kept ?? '';
        }

        if (self::hasValue($value) && ! self::isEncrypted($value)) {
            $value = self::PREFIX.Crypt::encryptString(is_string($value) ? $value : (string) json_encode($value));
        }

        return $value;
    }

    /** @return array<string, string> slot => stored (encrypted or legacy plaintext) value */
    private static function storedSecrets(?array $previous): array
    {
        $stored = [];

        foreach ((is_array($previous) ? ($previous['nodes'] ?? []) : []) as $node) {
            if (! is_array($node) || ! isset($node['id'])) {
                continue;
            }

            foreach (self::secretPairs($node) as [$field, , $pair]) {
                $value = $pair['value'] ?? null;
                $slot = self::slot((string) $node['id'], $field, $pair['key']);

                if (self::hasValue($value) && $value !== self::MASK && ! isset($stored[$slot])) {
                    $stored[$slot] = $value;
                }
            }

            foreach (self::bodyFields($node) as $field) {
                foreach (self::bodySecretEntries($node['data'][$field] ?? null) as $path => $value) {
                    $slot = self::slot((string) $node['id'], $field, $path);

                    if (self::hasValue($value) && $value !== self::MASK && ! isset($stored[$slot])) {
                        $stored[$slot] = $value;
                    }
                }
            }
        }

        return $stored;
    }

    /**
     * The secret pairs of one node, as [field, index|name, pair]. A field
     * may be a list of {key, value} pairs or a name => value map; a map
     * entry is surfaced as a pair carrying `__map` so it is written back
     * in its own shape.
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

            foreach ($data[$field] as $j => $entry) {
                if (is_array($entry)) {
                    if (self::isSensitiveName($entry['key'] ?? null)) {
                        $pairs[] = [$field, $j, $entry];
                    }
                } elseif (is_string($j) && self::isSensitiveName($j)) {
                    $pairs[] = [$field, $j, ['key' => $j, 'value' => $entry, '__map' => true]];
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
                $result = $fn($pair, (string) $node['id'], $field);

                if (($pair['__map'] ?? false) === true) {
                    // name => value map: only the value is rewritten.
                    $graph['nodes'][$i]['data'][$field][$j] = $result['value'] ?? null;
                } else {
                    $graph['nodes'][$i]['data'][$field][$j] = $result;
                }
            }
        }

        return $graph;
    }

    /** @return list<string> the free-text body fields of this node's type. */
    private static function bodyFields(mixed $node): array
    {
        if (! is_array($node) || ! is_string($node['type'] ?? null) || ! isset($node['id']) || ! is_array($node['data'] ?? null)) {
            return [];
        }

        return self::SECRET_BODY_FIELDS[$node['type']] ?? [];
    }

    // ---- request body (api node) -------------------------------------------------

    /**
     * Apply $fn(path, value, nodeId) to every credential-bearing scalar of
     * every body field in the graph; the field keeps its own type
     * (string stays a string, array stays an array). A body that is not
     * parseable (see parseBody()) is returned untouched.
     *
     * @param  array<string, mixed>  $graph
     * @param  callable(string, mixed, string): mixed  $fn
     * @return array<string, mixed>
     */
    private static function mapBodySecrets(array $graph, callable $fn): array
    {
        if (! is_array($graph['nodes'] ?? null)) {
            return $graph;
        }

        foreach ($graph['nodes'] as $i => $node) {
            foreach (self::bodyFields($node) as $field) {
                $body = $node['data'][$field] ?? null;
                $parsed = self::parseBody($body);

                if ($parsed === null) {
                    continue;
                }

                $changed = false;
                $structure = self::walkParsed($parsed, function (string $path, mixed $value) use ($fn, $node, &$changed) {
                    $new = $fn($path, $value, (string) $node['id']);

                    if ($new !== $value) {
                        $changed = true;
                    }

                    return $new;
                });

                // Re-serialize only when a value actually changed, so an
                // idempotent save never reformats the author's body.
                if (! $changed) {
                    continue;
                }

                $graph['nodes'][$i]['data'][$field] = self::serializeBody($parsed['kind'], $structure, $body);
            }
        }

        return $graph;
    }

    /**
     * path => value of every credential-bearing scalar in a body.
     *
     * @return array<string, mixed>
     */
    private static function bodySecretEntries(mixed $body): array
    {
        $parsed = self::parseBody($body);
        $entries = [];

        if ($parsed !== null) {
            self::walkParsed($parsed, static function (string $path, mixed $value) use (&$entries) {
                $entries[$path] = $value;

                return $value;
            });
        }

        return $entries;
    }

    /**
     * @return array{kind: 'array'|'json'|'form', value: array<int|string, mixed>}|null
     */
    private static function parseBody(mixed $body): ?array
    {
        if (is_array($body)) {
            return ['kind' => 'array', 'value' => $body];
        }

        if (! is_string($body) || trim($body) === '') {
            return null;
        }

        $trimmed = trim($body);

        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);

            return is_array($decoded) ? ['kind' => 'json', 'value' => $decoded] : null;
        }

        // application/x-www-form-urlencoded: name=value&name2=value2
        if (preg_match('/^[^=&\s{}]+=[^&\r\n]*(&[^=&\s{}]+=[^&\r\n]*)*$/', $trimmed) === 1) {
            $fields = [];

            foreach (explode('&', $trimmed) as $piece) {
                [$name, $value] = explode('=', $piece, 2);
                $fields[] = [urldecode($name), $value];
            }

            return ['kind' => 'form', 'value' => $fields];
        }

        return null;
    }

    /**
     * Apply $fn(path, scalar) to every credential-bearing scalar of a parsed
     * body and return the rewritten structure.
     *
     * @param  array{kind: string, value: array<int|string, mixed>}  $parsed
     * @param  callable(string, mixed): mixed  $fn
     * @return array<int|string, mixed>
     */
    private static function walkParsed(array $parsed, callable $fn): array
    {
        if ($parsed['kind'] === 'form') {
            foreach ($parsed['value'] as $k => [$name, $value]) {
                if (self::isSensitiveName($name) && self::isProtectableScalar($value)) {
                    $parsed['value'][$k][1] = $fn(strtolower($name), $value);
                }
            }

            return $parsed['value'];
        }

        return self::walkBody($parsed['value'], '', false, $fn);
    }

    /**
     * Walk a JSON body, calling $fn(path, scalar) for every scalar that sits
     * under a credential-named key (or anywhere beneath one), and writing
     * back what it returns. A `{{variable}}` reference is a pointer, not a
     * secret, and is left alone.
     *
     * @param  array<int|string, mixed>  $node
     * @param  callable(string, mixed): mixed  $fn
     * @return array<int|string, mixed>
     */
    private static function walkBody(array $node, string $path, bool $inSecret, callable $fn): array
    {
        foreach ($node as $k => $v) {
            $segment = strtolower((string) $k);
            $childPath = $path === '' ? $segment : $path.'.'.$segment;
            $secret = $inSecret || (is_string($k) && self::isSensitiveName($k));

            if (is_array($v)) {
                $node[$k] = self::walkBody($v, $childPath, $secret, $fn);
            } elseif ($secret && self::isProtectableScalar($v)) {
                $node[$k] = $fn($childPath, $v);
            }
        }

        return $node;
    }

    private static function isProtectableScalar(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        if (! is_string($value) || $value === '') {
            return false;
        }

        return preg_match('/^\{\{\s*[A-Za-z0-9_.\-]+\s*\}\}$/', $value) !== 1;
    }

    /** @param  array<int|string, mixed>  $structure */
    private static function serializeBody(string $kind, array $structure, mixed $original): mixed
    {
        return match ($kind) {
            'array' => $structure,
            'json' => json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            default => implode('&', array_map(static fn (array $pair) => $pair[0].'='.$pair[1], $structure)),
        };
    }

    // ---- legacy credentials already stored in an `api` URL ---------------------

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    private static function maskUrls(array $graph): array
    {
        if (! is_array($graph['nodes'] ?? null)) {
            return $graph;
        }

        foreach ($graph['nodes'] as $i => $node) {
            if (! is_array($node) || ! isset(self::SECRET_PAIR_FIELDS[$node['type'] ?? null]) || ! is_string($node['data']['url'] ?? null)) {
                continue;
            }

            $graph['nodes'][$i]['data']['url'] = self::maskUrl($node['data']['url']);
        }

        return $graph;
    }

    /** Replace URL userinfo and the value of any credential-named query parameter with MASK. */
    public static function maskUrl(string $url): string
    {
        $url = preg_replace('#^([A-Za-z][A-Za-z0-9+.\-]*://)[^/?\\#@]*@#', '$1'.self::MASK.'@', $url) ?? $url;

        $qPos = strpos($url, '?');

        if ($qPos === false) {
            return $url;
        }

        $fragPos = strpos($url, '#', $qPos);
        $query = substr($url, $qPos + 1, $fragPos === false ? null : $fragPos - $qPos - 1);
        $rest = $fragPos === false ? '' : substr($url, $fragPos);

        $pieces = array_map(static function (string $piece): string {
            if (! str_contains($piece, '=')) {
                return $piece;
            }

            [$name, $value] = explode('=', $piece, 2);

            return self::isSensitiveName(urldecode($name)) && $value !== '' ? $name.'='.self::MASK : $piece;
        }, explode('&', $query));

        return substr($url, 0, $qPos + 1).implode('&', $pieces).$rest;
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
