<?php

namespace App\Support\Security;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phase 12 Task 2 (H1) — the named `login` rate limiter (registered in AppServiceProvider, applied ONLY to
 * POST /api/auth/login as `throttle:login`; never to authenticated endpoints).
 *
 *   per email + IP   the lockout bucket. Keyed by the normalized e-mail AND the client IP, so one attacker cannot
 *                    lock an account out for its owner connecting from another address, and one IP's failures on
 *                    account A never block account B. The same key is built whether or not the account exists, and the
 *                    429 body is identical, so the limiter reveals nothing about which e-mails are registered.
 *   per IP           a much higher bucket that bounds password spraying across many e-mails.
 *
 * The middleware counts every request before the controller runs (so a correct password sent after the limit is
 * still refused); a successful login calls clear() so honest users who mistyped do not stay penalized.
 * Limits come from config/security.php. State lives in the shared cache store.
 */
class LoginThrottle
{
    public const NAME = 'login';

    /** @return list<Limit> */
    public static function limits(Request $request): array
    {
        $cfg = config('security.login');
        $respond = fn (Request $r, array $headers) => self::tooManyAttempts($headers);

        return [
            Limit::perMinutes($cfg['decay_minutes'], $cfg['max_attempts'])->by(self::emailIpKey($request))->response($respond),
            Limit::perMinutes($cfg['ip_decay_minutes'], $cfg['ip_max_attempts'])->by('ip:'.$request->ip())->response($respond),
        ];
    }

    public static function emailIpKey(Request $request): string
    {
        $email = $request->input('email');
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';

        return 'email-ip:'.sha1($email.'|'.$request->ip());
    }

    /** Forget the failed attempts of this e-mail + IP (called after a successful authentication). */
    public static function clear(Request $request): void
    {
        RateLimiter::clear(self::bucketKey(self::emailIpKey($request)));
    }

    private static function bucketKey(string $limitKey): string
    {
        // Mirrors ThrottleRequests::handleRequestUsingNamedLimiter().
        return md5(self::NAME.$limitKey);
    }

    /** @param array<string, mixed> $headers */
    private static function tooManyAttempts(array $headers): \Illuminate\Http\JsonResponse
    {
        $retryAfter = (int) ($headers['Retry-After'] ?? 60);

        return response()->json([
            'message' => 'Too many login attempts. Please try again later.',
            'error_code' => 'TOO_MANY_LOGIN_ATTEMPTS',
            'retry_after' => $retryAfter,
        ], 429, $headers);
    }
}
