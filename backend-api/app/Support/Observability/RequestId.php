<?php

namespace App\Support\Observability;

use Illuminate\Support\Str;

/**
 * Request-correlation id rules. An id supplied by the caller is only ever echoed/logged when it is short and
 * made of harmless characters (no whitespace, control characters, quotes or separators that could forge a
 * log line or a header); anything else is dropped and replaced by a generated id.
 */
final class RequestId
{
    /** 8–64 chars, alphanumeric first, then alphanumerics . _ - (UUIDs, ULIDs and typical gateway ids fit). */
    public const PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._\-]{7,63}\z/';

    public static function accept(mixed $candidate): ?string
    {
        return is_string($candidate) && preg_match(self::PATTERN, $candidate) === 1 ? $candidate : null;
    }

    /** UUID v4 — built from random_bytes(), so unguessable. */
    public static function generate(): string
    {
        return (string) Str::uuid();
    }
}
