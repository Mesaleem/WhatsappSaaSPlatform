<?php

/**
 * Phase 5 Task 4 -- test-suite pacing override.
 *
 * ProcessGroupDispatchJob / ProcessGroupDirectMessageJob call
 * sleep(random_int(3, 8)) between consecutive recipients as anti-ban
 * jitter. That pacing is REQUIRED production behaviour and is deliberately
 * unchanged by this task -- but it makes a 10-recipient batch take ~50
 * wall-clock seconds, which would put the group refund tests (and the
 * full regression run they sit in) into the minutes.
 *
 * PHP resolves an unqualified call to a global function only when no
 * function of that name exists in the CALLING file's namespace. Both jobs
 * live in App\Jobs and call `sleep(...)` unqualified, so defining
 * App\Jobs\sleep() here shadows the global one for those files ONLY while
 * this file is loaded -- i.e. inside the test suite. No production file is
 * touched, the real sleep() is untouched everywhere else, and nothing
 * about the jobs' own source changes.
 *
 * [Disclosed side effect]: ProcessPaymentAlertJob also lives in App\Jobs
 * and also sleeps, so its tests get faster too. No assertion anywhere
 * depends on real elapsed time.
 */

namespace App\Jobs;

if (! \function_exists('App\Jobs\sleep')) {
    function sleep(int $seconds): int
    {
        return 0;
    }
}
