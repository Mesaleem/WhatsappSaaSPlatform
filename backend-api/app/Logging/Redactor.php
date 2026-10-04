<?php

namespace App\Logging;

/**
 * Phase 12 Task 3 — scrubs log context/message text before it is written by the `json` channel (and anything
 * else that calls it, e.g. the Meta status log).
 *
 *  - secret-bearing KEYS (password, token, secret, api key, authorization, cookie, signature, card …) → [REDACTED]
 *  - message-CONTENT keys (body, text, caption, message_preview …) → [REDACTED] (full message text is not logged)
 *  - phone-number keys (recipient_id, phone, from, to, wa_id …) → masked, last 4 digits kept for correlation
 *  - free text: Bearer tokens, Sanctum tokens (`12|abc…`), `sk-…`/`Basic …` credentials, and +E.164 numbers
 *
 * `phone_number_id` (Meta's phone-number *object* id, not a phone number) is deliberately not masked: it is the
 * operational key for diagnosing which number a webhook belongs to.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /** A key containing any of these (case-insensitive, `-`/space → `_`) holds a secret. */
    private const SECRET_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'api_key', 'apikey', 'x_api', 'cookie',
        'signature', 'credential', 'private_key', 'client_secret', 'bearer', 'otp', 'cvv', 'card_number',
        'pan', 'session_id', 'jwt',
    ];

    /** Keys whose value is message content. */
    private const CONTENT_KEYS = [
        'body', 'text', 'caption', 'message_body', 'message_preview', 'preview', 'reply_text', 'rendered_message',
        'template_body', 'incoming_message', 'prompt', 'completion',
    ];

    /** Keys whose value is a phone number. */
    private const PHONE_KEYS = [
        'recipient_id', 'recipient_phone', 'phone', 'phone_number', 'sender_phone', 'customer_phone', 'lead_phone',
        'contact_phone', 'from', 'to', 'msisdn', 'wa_id', 'mobile', 'whatsapp', 'whatsapp_number',
    ];

    private const MAX_DEPTH = 6;

    public static function redact(mixed $value, ?string $key = null, int $depth = 0): mixed
    {
        if ($key !== null) {
            $k = strtolower(str_replace(['-', ' '], '_', $key));

            if (self::isSecretKey($k) || in_array($k, self::CONTENT_KEYS, true)) {
                return $value === null || $value === '' ? $value : self::MASK;
            }
            if (in_array($k, self::PHONE_KEYS, true) && (is_string($value) || is_int($value))
                && strlen(preg_replace('/\D+/', '', (string) $value) ?? '') >= 7) {   // `to`/`from` also hold non-phone values
                return self::maskPhone((string) $value);
            }
        }

        if ($depth >= self::MAX_DEPTH) {
            return is_scalar($value) || $value === null ? self::scrubText($value) : '[max-depth]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v, is_string($k) ? $k : null, $depth + 1);
            }

            return $out;
        }

        if ($value instanceof \Throwable) {
            return self::describeThrowable($value, $key === 'exception');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_object($value)) {
            // Models, collections and other Arrayable/Jsonable objects stringify to ALL their attributes — never use that
            $serialisesAttributes = $value instanceof \Illuminate\Contracts\Support\Arrayable
                || $value instanceof \Illuminate\Contracts\Support\Jsonable
                || $value instanceof \JsonSerializable;

            if (! $serialisesAttributes && method_exists($value, '__toString')) {
                return self::scrubText((string) $value);
            }

            // never serialise an object's attributes (a model can hold tokens/PII): class and key only
            return '['.get_class($value).(method_exists($value, 'getKey') && is_scalar($value->getKey()) ? '#'.$value->getKey() : '').']';
        }

        return is_string($value) ? self::scrubText($value) : $value;
    }

    public static function isSecretKey(string $normalizedKey): bool
    {
        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if ($fragment === 'pan' || $fragment === 'otp') {
                // short fragments only match as a whole word-part, so `company`/`expansion`/`footprint` are fine
                if (preg_match('/(^|_)'.$fragment.'(_|$)/', $normalizedKey) === 1) {
                    return true;
                }
                continue;
            }
            if (str_contains($normalizedKey, $fragment)) {
                // `tokens`, `max_tokens`, `token_count`, `tokens_used` are usage counters, not secrets
                if (preg_match('/^(?:(?:max|total|input|output|prompt|completion)_)?tokens(?:_(?:used|count|usage|limit))?$|^token_(?:count|used|usage|limit)$/', $normalizedKey) === 1) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    public static function maskPhone(string $phone): string
    {
        if (preg_match('/\A\*+\d{0,4}\z/', $phone) === 1) {
            return $phone;          // already masked: masking is idempotent
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) <= 4 ? '****' : str_repeat('*', strlen($digits) - 4).substr($digits, -4);
    }

    public static function scrubText(mixed $text): mixed
    {
        if (! is_string($text) || $text === '') {
            return $text;
        }

        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=\-]{8,}/i', 'Bearer '.self::MASK, $text) ?? $text;
        $text = preg_replace('/\bBasic\s+[A-Za-z0-9+\/=]{8,}/', 'Basic '.self::MASK, $text) ?? $text;
        $text = preg_replace('/\b\d{1,10}\|[A-Za-z0-9]{30,}\b/', self::MASK, $text) ?? $text;           // Sanctum token
        $text = preg_replace('/\b(?:sk|pk|rk)[-_](?:live|test|proj)?[-_]?[A-Za-z0-9]{16,}\b/', self::MASK, $text) ?? $text;
        $text = preg_replace('/\bEAA[A-Za-z0-9]{40,}\b/', self::MASK, $text) ?? $text;                 // Meta access token
        $text = preg_replace('/(?<![\w.])\+\d{10,15}\b/', '+***', $text) ?? $text;                    // +E.164 number
        // bare 11–12 digit numbers (country code + number, e.g. 919876543210). 10-digit epoch seconds, 13-digit epoch
        // milliseconds and Meta's 15–16 digit object ids (phone_number_id, waba id) are deliberately not matched.
        $text = preg_replace_callback('/(?<![\w.*])\d{11,12}(?![\w.])/', fn ($m) => self::maskPhone($m[0]), $text) ?? $text;
        $text = preg_replace('/([?&](?:access_token|token|api_key|apikey|key|secret|signature|client_secret)=)[^&\s"\']+/i', '$1'.self::MASK, $text) ?? $text;

        return $text;
    }

    /**
     * A log-safe description of an exception: class, code, scrubbed message, file basename, line, one level of
     * `previous`, and (optionally) up to 30 frames as "file:line class::function" — never arguments.
     *
     * @return array<string, mixed>
     */
    public static function describeThrowable(\Throwable $e, bool $withTrace = false): array
    {
        $info = [
            'class' => get_class($e),
            'message' => mb_substr((string) self::scrubText($e->getMessage()), 0, 500),
            'code' => $e->getCode(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ];

        if (($prev = $e->getPrevious()) !== null) {
            $info['previous'] = ['class' => get_class($prev), 'message' => mb_substr((string) self::scrubText($prev->getMessage()), 0, 300), 'code' => $prev->getCode()];
        }

        if ($withTrace) {
            $frames = [];
            foreach (array_slice($e->getTrace(), 0, 30) as $f) {
                $frames[] = (isset($f['file']) ? basename($f['file']).':'.($f['line'] ?? '?') : '[internal]').' '
                    .(isset($f['class']) ? $f['class'].($f['type'] ?? '::') : '').($f['function'] ?? '');
            }
            $info['trace'] = $frames;
        }

        return $info;
    }
}
