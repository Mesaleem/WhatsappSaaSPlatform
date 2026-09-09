<?php

namespace App\Services\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The single place an outbound webhook HTTP call actually happens and gets
 * signed — used by BOTH DispatchWebhookJob (real, queued events) and
 * WebhookSubscriptionController::test() (synchronous, "Test Webhook"
 * button), so the signature scheme a tenant validates against in their
 * test click is byte-for-byte the same one real events use.
 */
class WebhookSender
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, status_code: ?int, error: ?string}
     */
    public static function send(WebhookSubscription $subscription, string $event, array $payload, int $attempt = 1): array
    {
        $body = json_encode([
            'event' => $event,
            'data' => $payload,
            'timestamp' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        // HMAC-SHA256 over the exact bytes sent — the receiving tenant
        // must verify against the SAME raw body, not a re-serialized one
        // (identical principle to Module 8's gateway webhook verification).
        $signature = hash_hmac('sha256', $body, $subscription->secret);

        $statusCode = null;
        $error = null;
        $success = false;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-WASAAS-Signature' => $signature,
                'X-WASAAS-Event' => $event,
            ])
                ->withBody($body, 'application/json')
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($subscription->url);

            $statusCode = $response->status();
            $success = $response->successful();
            if (! $success) {
                $error = "Endpoint responded with HTTP {$statusCode}.";
            }
        } catch (Throwable $e) {
            $error = 'Could not reach the webhook URL: '.$e->getMessage();
        }

        WebhookDelivery::create([
            'webhook_subscription_id' => $subscription->id,
            'event' => $event,
            'payload' => $payload,
            'response_code' => $statusCode,
            'status' => $success ? 'success' : 'failed',
            'attempt' => $attempt,
        ]);

        return ['success' => $success, 'status_code' => $statusCode, 'error' => $error];
    }
}
