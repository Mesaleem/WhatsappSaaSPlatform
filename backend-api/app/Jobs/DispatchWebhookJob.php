<?php

namespace App\Jobs;

use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delivers ONE outbound webhook event to ONE subscription. Dispatched by
 * App\Services\Webhooks\WebhookDispatcher — one job per subscription, so a
 * slow or dead tenant endpoint only ever delays/retries its own delivery,
 * never anyone else's.
 *
 * Retried up to 3 times with backoff — UNLIKE ProcessPaymentAlertJob
 * (tries=1, because a WhatsApp send is not safely repeatable), an outbound
 * webhook POST to a tenant's own endpoint is expected to be safely
 * retryable: standard webhook practice puts the onus of idempotent
 * handling (e.g. by watching for a repeated event id) on the RECEIVER,
 * exactly like Stripe/Razorpay/GitHub's own webhook retry behavior that
 * Module 8's PaymentWebhookController itself had to tolerate.
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $webhookSubscriptionId,
        public readonly string $event,
        public readonly array $payload,
    ) {
    }

    /** Seconds to wait before each retry (index 0 = before the 2nd attempt). */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $subscription = WebhookSubscription::find($this->webhookSubscriptionId);

        if (! $subscription) {
            Log::warning("DispatchWebhookJob: webhook_subscriptions#{$this->webhookSubscriptionId} no longer exists.");

            return;
        }

        // Deleted/deactivated between enqueue and execution — nothing to do,
        // and definitely not something worth retrying.
        if (! $subscription->is_active) {
            return;
        }

        $result = WebhookSender::send($subscription, $this->event, $this->payload, $this->attempts());

        if (! $result['success']) {
            // Throwing (rather than a manual release()) lets Laravel's own
            // queue retry machinery apply $tries/backoff() — after the
            // final attempt, Laravel marks the job permanently failed
            // instead of retrying forever against a dead endpoint.
            throw new RuntimeException(
                "Webhook delivery to subscription #{$subscription->id} failed: ".($result['error'] ?? 'unknown error')
            );
        }
    }
}
