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
