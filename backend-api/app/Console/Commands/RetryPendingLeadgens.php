<?php

namespace App\Console\Commands;

use App\Models\PendingLeadgen;
use App\Services\Leads\MetaLeadWebhookHandler;
use Illuminate\Console\Command;
use Throwable;

/**
 * QA fixes (2026-10-14) — Meta lead-ad leads whose details could not be
 * fetched when their webhook arrived are fetched again until it works.
 */
class RetryPendingLeadgens extends Command
{
    protected $signature = 'leads:retry-pending';

    protected $description = 'Fetch Meta lead-ad leads that could not be read when they arrived.';

    public function handle(MetaLeadWebhookHandler $handler): int
    {
        $done = 0;

        PendingLeadgen::query()
            ->whereNull('resolved_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (PendingLeadgen $pending) use ($handler, &$done) {
                try {
                    if ($handler->retryPending($pending)) {
                        $done++;
                    }
                } catch (Throwable $e) {
                    $pending->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500), 'next_attempt_at' => now()->addHour()])->save();
                }
            });

        $this->info("{$done} pending lead(s) resolved.");

        return self::SUCCESS;
    }
}
