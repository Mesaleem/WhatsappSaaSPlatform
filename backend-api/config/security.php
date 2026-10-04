<?php

/*
| Phase 12 Task 2 — edge security hardening. Every value is an operational knob read from the environment; none
| of them changes who may do what (authorization, tenancy, plans and entitlements are untouched).
*/

return [

    /*
    | Login throttling (routes/api.php: `throttle:login`, AppServiceProvider: RateLimiter::for('login')).
    | The primary bucket is keyed by normalized email + IP, so an attacker on one IP cannot lock the same
    | account out for its owner on another IP, and the same IP cannot lock out other accounts. The secondary,
    | much higher, per-IP bucket bounds password spraying across many emails. Both use the shared cache
    | (CACHE_STORE) — on `file`/`array` they are per instance (see `php artisan ops:check-topology`).
    */
    'login' => [
        'max_attempts' => max(1, (int) env('LOGIN_MAX_ATTEMPTS', 5)),
        'decay_minutes' => max(1, (int) env('LOGIN_DECAY_MINUTES', 5)),
        'ip_max_attempts' => max(1, (int) env('LOGIN_IP_MAX_ATTEMPTS', 30)),
        'ip_decay_minutes' => max(1, (int) env('LOGIN_IP_DECAY_MINUTES', 1)),
    ],

    /*
    | Runtime SSRF protection (App\Support\Security\OutboundUrlGuard) for URLs a tenant controls and that our
    | servers (or the QR engine, on our network) fetch: webhook endpoints, media_url, social media downloads.
    |
    | enabled        OUTBOUND_GUARD_ENABLED=false switches the guard off entirely (local development against
    |                a webhook receiver on localhost). Keep it on everywhere else.
    | allow_http     plain http:// URLs are accepted (as before); false = https only.
    | allowed_hosts  comma-separated host names that bypass the address checks (an explicit, audited escape hatch
    |                for a trusted internal receiver). Empty by default.
    | max_redirects  redirects a guarded request may follow; every hop is re-validated.
    */
    'outbound' => [
        'enabled' => filter_var(env('OUTBOUND_GUARD_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'allow_http' => filter_var(env('OUTBOUND_ALLOW_HTTP', true), FILTER_VALIDATE_BOOLEAN),
        'allowed_hosts' => array_values(array_filter(array_map(fn ($h) => strtolower(trim($h)), explode(',', (string) env('OUTBOUND_ALLOWED_HOSTS', ''))))),
        'max_redirects' => max(0, (int) env('OUTBOUND_MAX_REDIRECTS', 3)),
    ],

    /*
    | API response headers (App\Http\Middleware\ApiSecurityHeaders). Deliberately no Content-Security-Policy:
    | the React SPA is served separately and a CSP for it is out of scope here.
    | HSTS is only sent on requests that arrived over HTTPS (so local http development is unaffected).
    */
    'headers' => [
        'enabled' => filter_var(env('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'no-referrer'),
        'hsts' => [
            'enabled' => filter_var(env('SECURITY_HSTS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'max_age' => max(0, (int) env('SECURITY_HSTS_MAX_AGE', 15552000)),
            'include_subdomains' => filter_var(env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

];
