<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Module 8 — asynchronous, gateway-triggered webhook receiver. Deliberately
 * PUBLIC (see routes/api.php: no auth:sanctum) — Razorpay/Stripe call these
 * directly with no Laravel session and no bearer token; authenticity comes
 * ONLY from the HMAC signature header, verified against the raw request
 * body before anything in the payload is trusted.
 *
 * This is the SOURCE OF TRUTH path for production money movement — unlike
 * PaymentGatewayController::verifyPayment() (fast, optimistic, client
 * triggered — the browser could simply never call it if the tab is closed
 * mid-checkout), the gateway guarantees this fires once the payment
 * actually settles. Both paths funnel into the same idempotent
 * InvoiceCreditService, so whichever arrives first wins and the other is a
 * safe no-op — this file never has to reason about ordering.
 *
 * Every branch below returns a 2xx {"received": true} except an outright
 * signature failure (400) — gateways retry on non-2xx with backoff, so an
 * "invoice not found" or "gateway not configured" case is deliberately
 * ack'd rather than rejected, to avoid an infinite retry storm for an
 * event this deployment will never be able to resolve.
 */
class PaymentWebhookController extends Controller
{
    /** POST /api/webhooks/razorpay */
    public function razorpay(Request $request): JsonResponse
    {
        return $this->handle('razorpay', $request->getContent(), $request->header('X-Razorpay-Signature', ''));
    }

    /** POST /api/webhooks/stripe */
    public function stripe(Request $request): JsonResponse
    {
        return $this->handle('stripe', $request->getContent(), $request->header('Stripe-Signature', ''));
    }

    private function handle(string $gateway, string $rawBody, string $signatureHeader): JsonResponse
    {
        $settings = PaymentGatewayFactory::settingsFor($gateway);
        $webhookSecret = $settings?->activeWebhookSecret();

        if (! $settings || ! $settings->is_enabled || ! $webhookSecret) {
            Log::warning("PaymentWebhookController: {$gateway} webhook received but the gateway is not enabled or has no webhook secret configured — ignoring.");

            // Ack anyway: this is a platform-configuration gap, not something
            // repeating the delivery will ever fix, so don't trigger retries.
            return response()->json(['received' => true]);
        }

        try {
            $driver = PaymentGatewayFactory::make($gateway);
        } catch (RuntimeException $e) {
            Log::warning("PaymentWebhookController: {$gateway} webhook received but the driver could not be built — {$e->getMessage()}");

            return response()->json(['received' => true]);
        }

        if (! $driver->verifyWebhookSignature($rawBody, $signatureHeader, $webhookSecret)) {
            Log::warning("PaymentWebhookController: {$gateway} webhook signature verification FAILED — request rejected, nothing trusted or processed.");

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            Log::warning("PaymentWebhookController: {$gateway} webhook had a valid signature but an unparseable JSON body.");

            return response()->json(['received' => true]);
        }

        $event = $driver->parseWebhookEvent($payload);

        // Agent Commission Foundation — refund/reversal lifecycle.
        // Checked BEFORE the payment-success branch below: is_refund and
        // is_success are never both true (see each driver's
        // parseWebhookEvent()), so this ordering doesn't change outcome,
        // but keeps the refund path legible as its own branch rather
        // than folded into the "not successful" no-op below.
        if ($event['is_refund']) {
            if (! $event['order_id']) {
                return response()->json(['received' => true]);
            }

            $invoice = Invoice::where('gateway_order_id', $event['order_id'])->first();

            if (! $invoice) {
                Log::warning("PaymentWebhookController: {$gateway} refund webhook for order_id '{$event['order_id']}' matches no invoice — ignoring.");

                return response()->json(['received' => true]);
            }

            // No-op when this Invoice never had an Agent commission (a
            // direct customer, or an Agent customer with no commission
            // rule configured at purchase time) — see
            // InvoiceCreditService::reverseAgentCommission()'s own
            // docblock. Also idempotent: a commission already 'reversed'
            // is left exactly as it is on a duplicate refund webhook.
            app(InvoiceCreditService::class)->reverseAgentCommission($invoice->id, $event['reference']);

            return response()->json(['received' => true]);
        }

        if (! $event['is_success'] || ! $event['order_id']) {
            // Signature-valid but not a "payment succeeded" event (e.g.
            // payment.failed, a non-payment Stripe event type) —
            // correctly nothing to credit. Not an error.
            return response()->json(['received' => true]);
        }

        $invoice = Invoice::where('gateway_order_id', $event['order_id'])->first();

        if (! $invoice) {
            Log::warning("PaymentWebhookController: {$gateway} webhook for order_id '{$event['order_id']}' matches no invoice — ignoring.");

            return response()->json(['received' => true]);
        }

        app(InvoiceCreditService::class)->markPaidAndCreditQuota(
            $invoice->id,
            $event['payment_id'] ?? $event['order_id'],
            $payload,
        );

        return response()->json(['received' => true]);
    }
}
