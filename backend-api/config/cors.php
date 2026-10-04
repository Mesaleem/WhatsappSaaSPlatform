<?php

/*
| Phase 12 Task 2 — explicit CORS policy (before this the framework's built-in default applied implicitly).
|
| CORS only tells a BROWSER which other origins may read a response; it is not authorization — every route is
| still protected by its own token / API key / permission checks.
|
| CORS_ALLOWED_ORIGINS   comma-separated origins (e.g. https://app.example.com,https://admin.example.com).
|                        Unset keeps today's behavior (`*`): the API authenticates with Bearer tokens/API keys,
|                        not cookies, so an open origin list exposes nothing a token does not already gate. Set it
|                        to the real front-end origin(s) in production to close the default-open policy.
| CORS_ALLOWED_ORIGIN_PATTERNS  comma-separated regexes, e.g. #^https://[a-z0-9-]+\.example\.com$#
| CORS_SUPPORTS_CREDENTIALS     only honored together with an explicit origin list: credentials are NEVER allowed
|                        with a wildcard origin (browsers reject it, and it would be unsafe).
*/

$list = fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));

$origins = $list((string) env('CORS_ALLOWED_ORIGINS', '*'));
$origins = $origins === [] ? ['*'] : $origins;
$wildcard = in_array('*', $origins, true);

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => $list((string) env('CORS_ALLOWED_METHODS', '*')) ?: ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => $list((string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')),

    'allowed_headers' => $list((string) env('CORS_ALLOWED_HEADERS', '*')) ?: ['*'],

    'exposed_headers' => $list((string) env('CORS_EXPOSED_HEADERS', 'Retry-After,X-RateLimit-Limit,X-RateLimit-Remaining')),

    'max_age' => max(0, (int) env('CORS_MAX_AGE', 0)),

    'supports_credentials' => ! $wildcard && filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN),

];
