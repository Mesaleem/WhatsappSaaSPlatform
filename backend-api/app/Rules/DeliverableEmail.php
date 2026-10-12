<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Website signup email check: not a temporary/disposable address and, unless
 * security.signup.check_mx is off, a domain that can receive mail (MX record,
 * or an A record as the mail fallback). Format is checked by the 'email' rule.
 *
 * Disposable domains come from three places, merged:
 *  - resources/data/disposable_email_domains.txt — the community list from
 *    github.com/disposable-email-domains (about 10,000 domains, shipped with the code),
 *  - storage/app/disposable_email_domains.txt — a fresher copy written by
 *    `php artisan security:refresh-disposable-domains` (scheduled weekly), when present,
 *  - config/disposable_email_domains.php — our own additions.
 * A subdomain of a listed domain is refused too (x.mailinator.com).
 */
class DeliverableEmail implements ValidationRule
{
    /** @var array<string, true>|null */
    private static ?array $blocked = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = strtolower(trim((string) substr(strrchr((string) $value, '@') ?: '', 1)));
        if ($domain === '') {
            return; // the 'email' rule reports a malformed address
        }

        if (self::isDisposable($domain)) {
            $fail('Temporary or disposable email addresses are not allowed. Please use your real email.');

            return;
        }

        if (config('security.signup.check_mx') && ! $this->receivesMail($domain)) {
            $fail('This email domain cannot receive mail. Please check the address.');
        }
    }

    public static function isDisposable(string $domain): bool
    {
        $blocked = self::blocked();
        $parts = explode('.', $domain);

        // example.mailinator.com → check "example.mailinator.com", "mailinator.com".
        for ($i = 0; $i < count($parts) - 1; $i++) {
            if (isset($blocked[implode('.', array_slice($parts, $i))])) {
                return true;
            }
        }

        return false;
    }

    /** Forget the loaded list (after a refresh, and in tests). */
    public static function flush(): void
    {
        self::$blocked = null;
    }

    /** @return array<string, true> */
    private static function blocked(): array
    {
        if (self::$blocked !== null) {
            return self::$blocked;
        }

        $domains = (array) config('disposable_email_domains', []);
        foreach ([resource_path('data/disposable_email_domains.txt'), storage_path('app/disposable_email_domains.txt')] as $file) {
            if (is_readable($file)) {
                $domains = array_merge($domains, file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
            }
        }

        $set = [];
        foreach ($domains as $d) {
            $d = strtolower(trim((string) $d));
            if ($d !== '' && $d[0] !== '#') {
                $set[$d] = true;
            }
        }

        return self::$blocked = $set;
    }

    private function receivesMail(string $domain): bool
    {
        $ascii = function_exists('idn_to_ascii') ? (idn_to_ascii($domain) ?: $domain) : $domain;

        return checkdnsrr($ascii.'.', 'MX') || checkdnsrr($ascii.'.', 'A');
    }
}
