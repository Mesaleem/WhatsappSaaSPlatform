<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\SocialAuth\SocialConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Phase 9 Task 2 — checks ONE social connection with its provider.
 *
 * Dispatched by `social:check-connections` onto the 'database' connection's
 * 'social' queue (drained by the scheduled worker in routes/console.php),
 * only for a row that command has just CLAIMED (health_check_attempted_at),
 * so no other run dispatches the same row within the interval. Carries only
 * the id — never a token.
 *
 * $tries = 1: an unreachable provider is an `unknown` result (no status
 * change) and the row becomes due again after one interval — no aggressive
 * queue retries. A deleted row (locally disconnected) is a no-op.
 */
class CheckSocialConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $socialAccountId)
    {
    }

    public function handle(SocialConnectionService $connections): void
    {
        $socialAccount = SocialAccount::query()->find($this->socialAccountId);

        if (! $socialAccount || $socialAccount->health_status !== SocialAccount::HEALTH_CONNECTED) {
            return;
        }

        $connections->check($socialAccount, 'scheduled');
    }
}
