<?php

/*
 * Phase 12 Task 5 — operational data retention. ONE place for every retention period.
 *
 * Only tables whose lifecycle is established and which hold no business, financial, security-audit or
 * conversation history are listed here (see App\Services\Ops\RetentionPruner for the audit notes and the
 * list of tables that are deliberately NOT pruned).
 *
 * `enforce` — master switch for SCHEDULED runs. false (the default) = the daily `ops:prune-retention` run only
 * COUNTS and logs what it would delete; nothing is removed until the owner sets RETENTION_PRUNE_ENFORCE=true
 * (or runs the command by hand with --force). Deleting data is irreversible, so it is opt-in.
 *
 * Per category: `days` is how long a row is kept (by the row's own timestamp). A value <= 0 DISABLES that
 * category — it never means "delete everything". A value below `min_days` is raised to `min_days` (and a warning
 * is logged), so a typo cannot shorten retention below a safe floor.
 */
return [
    'enforce' => (bool) env('RETENTION_PRUNE_ENFORCE', false),

    // Rows deleted per statement, and the most batches one category may run per invocation. One run therefore
    // deletes at most batch_size × max_batches rows per category/scope; the next daily run continues.
    'batch_size' => (int) env('RETENTION_BATCH_SIZE', 1000),
    'max_batches' => (int) env('RETENTION_MAX_BATCHES', 50),

    'categories' => [
        // Developer-API request log (observability only; nothing reads it back). Tenant rows (account_id set) and
        // platform/unattributed rows (account_id NULL) are handled as two explicit scopes.
        'api_request_logs' => [
            'days' => (int) env('RETENTION_API_REQUEST_LOGS_DAYS', 90),
            'platform_days' => (int) env('RETENTION_API_REQUEST_LOGS_PLATFORM_DAYS', 90),
            'min_days' => 14,
        ],

        // Outbound-webhook attempt log; the API only ever shows the latest 50 per subscription.
        'webhook_deliveries' => [
            'days' => (int) env('RETENTION_WEBHOOK_DELIVERIES_DAYS', 30),
            'min_days' => 7,
        ],

        // Journey execution trail. Rows of a still-open session are never removed (same rule as journeys:prune-history).
        'journey_execution_events' => [
            'days' => (int) env('RETENTION_JOURNEY_EVENTS_DAYS', 90),
            'min_days' => 14,
        ],

        // Inbound dedup keys. Floor is above any provider redelivery window (Meta retries for ~7 days).
        'inbound_message_events' => [
            'days' => (int) env('RETENTION_INBOUND_EVENTS_DAYS', 30),
            'min_days' => 14,
        ],

        // Laravel's failed-job store (queue.failed.*). Kept long enough to investigate and `queue:retry`.
        'failed_jobs' => [
            'days' => (int) env('RETENTION_FAILED_JOBS_DAYS', 60),
            'min_days' => 14,
        ],
    ],
];
