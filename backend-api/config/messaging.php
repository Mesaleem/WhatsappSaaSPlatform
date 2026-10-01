<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound anti-ban pacing (Phase 5 fix P5-9)
    |--------------------------------------------------------------------------
    |
    | WhatsApp (especially the unofficial QR/Baileys engine) flags accounts
    | that send in a fixed, mechanical cadence. Consecutive group sends and
    | queued payment alerts are therefore spaced by a random number of
    | seconds in [min_seconds, max_seconds] — the same 3-8 s range the
    | previous blocking sleep(random_int(3, 8)) used.
    |
    | Since P5-9 the spacing is a QUEUE DELAY (a delayed job / delayed
    | continuation slice), never a sleep() inside a worker or a request.
    | max_seconds = 0 turns pacing off (the test suite does this, which is
    | what its former App\Jobs\sleep() no-op shadow amounted to).
    |
    */

    'outbound_pacing' => [
        'min_seconds' => (int) env('MESSAGING_PACING_MIN_SECONDS', 3),
        'max_seconds' => (int) env('MESSAGING_PACING_MAX_SECONDS', 8),
    ],

];
