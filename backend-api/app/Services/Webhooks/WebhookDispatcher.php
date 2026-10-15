<?php

namespace App\Services\Webhooks;

use App\Jobs\DispatchWebhookJob;
use App\Models\WebhookSubscription;

/**
 * Entry point called from ProcessPaymentAlertJob (message.sent /
 * message.failed) and MetaWebhookController (message.delivered) whenever
 * a real event happens. Fans out to every active subscription on the
 * account that has opted into this event, queuing one DispatchWebhookJob
 * per subscription — a slow/broken tenant endpoint never blocks another,
 * and never blocks the caller (this only enqueues; it does not make the
 * HTTP call itself).
 */
class WebhookDispatcher
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function fire(int $accountId, string $event, array $payload): void
    {
        // QA fixes (2026-10-14): every send now reaches this (MessageDispatchLog::record),
        // so an account without any webhook is remembered for a minute instead of being
        // asked about on every message.
        if (! \Illuminate\Support\Facades\Cache::remember("webhooks:has:{$accountId}", 60, fn (): bool => WebhookSubscription::query()->forAccount($accountId)->active()->exists())) {
            return;
        }

        WebhookSubscription::query()
            ->forAccount($accountId)
            ->active()
            ->get()
            ->filter(fn (WebhookSubscription $subscription) => $subscription->listensTo($event))
            ->each(fn (WebhookSubscription $subscription) => DispatchWebhookJob::dispatch(
                $subscription->id,
                $event,
                $payload,
            ));
    }
}
