<?php

return [
    /*
    | Phase 9 Task 2 — scheduled social connection health checks
    | (`social:check-connections`, see routes/console.php). A connection is
    | asked about at most once per interval, however often the command runs
    | and however many servers run it (each row is claimed atomically).
    */
    'connection_checks' => [
        // Minimum minutes between two provider checks of the same connection (floor 5).
        'interval_minutes' => (int) env('SOCIAL_CONNECTION_CHECK_INTERVAL_MINUTES', 60),

        // Maximum connections claimed per command run.
        'batch_size' => (int) env('SOCIAL_CONNECTION_CHECK_BATCH_SIZE', 100),
    ],

    /*
    | Phase 9 Task 3 — scheduled organic publishing (`social:publish-due`,
    | every minute, see routes/console.php). Each due post is claimed with a
    | conditional UPDATE, so it is sent by at most one worker.
    */
    'publishing' => [
        // Provider attempts for a scheduled post before a temporary failure becomes final.
        'max_attempts' => (int) env('SOCIAL_PUBLISH_MAX_ATTEMPTS', 3),

        // Minutes to wait after the 1st, 2nd, ... temporary failure.
        'backoff_minutes' => [1, 5, 15],

        // A claim older than this is settled: "outcome unknown" if the provider
        // was already called (never re-sent automatically), "interrupted" if not.
        'stale_after_minutes' => (int) env('SOCIAL_PUBLISH_STALE_AFTER_MINUTES', 15),

        // A post first picked up later than this after its scheduled time is
        // not published late; it is marked failed (missed_schedule).
        'late_grace_hours' => (int) env('SOCIAL_PUBLISH_LATE_GRACE_HOURS', 24),

        // How far ahead a post may be scheduled.
        'max_schedule_days' => (int) env('SOCIAL_PUBLISH_MAX_SCHEDULE_DAYS', 90),

        // Maximum posts claimed per command run.
        'batch_size' => (int) env('SOCIAL_PUBLISH_BATCH_SIZE', 50),
    ],

    /*
    | Phase 9 Task 4 — organic post insights. Pages read the stored snapshot;
    | the provider is called only by an explicit refresh or by
    | `social:refresh-insights` (every 30 minutes, see routes/console.php).
    */
    'insights' => [
        // A snapshot younger than this is not refreshed by the worker.
        'freshness_minutes' => (int) env('SOCIAL_INSIGHTS_FRESHNESS_MINUTES', 360),

        // An explicit refresh of a snapshot younger than this returns the stored one.
        'min_refresh_minutes' => (int) env('SOCIAL_INSIGHTS_MIN_REFRESH_MINUTES', 5),

        // Minutes to wait after the 1st, 2nd, 3rd+ consecutive rate-limit / temporary failure.
        'backoff_minutes' => [5, 30, 120],

        // The worker keeps refreshing posts published within this many days.
        'max_post_age_days' => (int) env('SOCIAL_INSIGHTS_MAX_POST_AGE_DAYS', 30),

        // An in-flight refresh older than this is considered dead and may be taken over.
        'lease_seconds' => 120,

        // Maximum posts queued per worker run.
        'batch_size' => (int) env('SOCIAL_INSIGHTS_BATCH_SIZE', 50),
    ],
];
