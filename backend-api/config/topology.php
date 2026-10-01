<?php

return [
    /*
    | Phase 12 Task 1 — deployment shape, read ONLY by `php artisan ops:check-topology`; it changes no runtime
    | behaviour. false (default) = one app/queue/scheduler instance: single-node drivers (sync queue, file cache,
    | local upload disk, …) are reported as "single-node-only" and are acceptable. true = several API / queue /
    | scheduler instances share one database: the same findings become blocking and the command exits 1.
    | The QR engine stays a single instance in both modes (it is not part of this switch).
    */
    'multi_instance' => (bool) env('TOPOLOGY_MULTI_INSTANCE', false),
];
