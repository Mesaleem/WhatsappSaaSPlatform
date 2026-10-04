<?php

/*
 * Phase 12 Task 3 — operational observability baseline. Everything here is operational logging/diagnostics:
 * none of it writes to activity_logs or any audit/financial history, and none of it needs a migration.
 */
return [
    // Request correlation (every /api/* request). An inbound header is accepted only if it matches
    // App\Support\Observability\RequestId::PATTERN; otherwise a fresh id is generated.
    'request_id' => [
        'header' => 'X-Request-Id',
    ],

    // The `json` log channel (config/logging.php). Select it with LOG_CHANNEL=json or LOG_STACK=...,json.
    'logging' => [
        // Exception frames (file:line + function, never arguments). Off by default: traces are large.
        'json_include_trace' => (bool) env('LOG_JSON_TRACE', false),
    ],

    // GET /ready — see App\Http\Controllers\ReadinessController.
    'readiness' => [
        // Header `X-Ops-Token`. Unset => the per-check detail is never returned (public body is status only).
        'detail_token' => env('READINESS_DETAIL_TOKEN'),
        'scheduler' => [
            // true => a missing/stale scheduler heartbeat makes /ready answer 503. Default false: an API
            // instance can serve traffic without the scheduler, so taking every API node out of a load
            // balancer because the scheduler stopped would turn a background fault into an outage.
            'enforce' => (bool) env('READINESS_SCHEDULER_ENFORCE', false),
            'max_age_seconds' => (int) env('READINESS_SCHEDULER_MAX_AGE_SECONDS', 180),
        ],
    ],

    // Slow-query listener. OFF by default; when off no listener is registered at all (zero overhead).
    'slow_query' => [
        'enabled' => (bool) env('SLOW_QUERY_LOG_ENABLED', false),
        'threshold_ms' => (int) env('SLOW_QUERY_THRESHOLD_MS', 500),
        // The same query shape is logged at most this many times per minute per process (noise control).
        'max_per_shape_per_minute' => (int) env('SLOW_QUERY_MAX_PER_SHAPE_PER_MINUTE', 3),
    ],
];
