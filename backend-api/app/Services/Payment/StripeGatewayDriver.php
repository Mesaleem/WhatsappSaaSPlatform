<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Throwable;

class StripeGatewayDriver implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://api.stripe.com/v1';

    /** Stripe-Signature timestamps older than this are rejected (anti-replay). */
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly string $publishableKey,
        private readonly string $secretKey,
    ) {
    }

    /**
     * Creates a PaymentIntent (not a "Checkout Session") — the frontend
     * confirms it client-side via Stripe Elements using the returned
     * client_secret + the publishable key. Stripe's form-encoded API needs
     * asForm(); Http's form_params body format handles metadata's nested
     * array as metadata[key]=value automatically.
     */
    public function createOrder(int $amountInSmallestUnit, string $currency, string $receipt, array $notes = []): array
    {
        try {
            $response = Http::asForm()
                ->withToken($this->secretKey)
                ->timeout(15)
                ->post(self::BASE_URL.'/payment_intents', [
                    'amount' => $amountInSmallestUnit,
                    'currency' => strtolower($currency),
                    'metadata' => array_merge($notes, ['receipt' => $receipt]),
                    'automatic_payment_methods' => ['enabled' => 'true'],
                ]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Stripe API is unreachable.'];
        }

        if ($response->failed()) {
            return [
                'success' => false,
                'error' => $response->json('error.message') ?? 'Stripe rejected the payment intent request.',
                'raw' => $response->json(),
            ];
        }

        return [
            'success' => true,
            'order_id' => $response->json('id'), // pi_...
            'client_secret' => $response->json('client_secret'),
            'raw' => $response->json(),
        ];
    }

    /**
     * Unlike Razorpay, Stripe never hands the browser something we can
     * verify by local computation — Elements confirms the PaymentIntent
     * client-side, but the only trustworthy confirmation is asking Stripe
     * directly. So this makes a real API call (GET the PaymentIntent) and
     * checks its status, rather than validating a client-supplied value.
     */
    public function verifyPayment(array $input): bool
    {
        $paymentIntentId = $input['payment_intent_id'] ?? null;
        if (! $paymentIntentId) {
            return false;
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->timeout(15)
                ->get(self::BASE_URL.'/payment_intents/'.$paymentIntentId);
        } catch (Throwable $e) {
            return false;
        }

        return $response->successful() && $response->json('status') === 'succeeded';
    }

    /**
     * Stripe-Signature header format: "t=<unix ts>,v1=<hmac>[,v0=<hmac>]".
     * Signed payload is "{t}.{raw body}", HMAC-SHA256 keyed with the
     * webhook secret. A timestamp outside WEBHOOK_TOLERANCE_SECONDS is
     * rejected even if the signature matches (Stripe's own documented
     * anti-replay recommendation).
     */
    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret): bool
    {
        if ($signatureHeader === '' || $webhookSecret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $piece) {
            [$key, $value] = array_pad(explode('=', $piece, 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        $timestamp = $parts['t'] ?? null;
        $v1 = $parts['v1'] ?? null;

        if (! $timestamp || ! $v1 || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $webhookSecret);

        return hash_equals($expected, $v1);
    }

    /**
     * Stripe webhook payload shape:
     * { type: "payment_intent.succeeded", data: { object: { id, status } } }
     *
     * Stripe has no separate order-id/payment-id pair the way Razorpay
     * does — the PaymentIntent's own id serves as both (it was stored as
     * gateway_order_id at creation, and is reused here as the
     * gateway_payment_id once it succeeds).
     *
     * A completed refund arrives as 'charge.refunded', whose $object is
     * the CHARGE, not the PaymentIntent — its own 'id' is a "ch_..." id
     * that would never match a stored gateway_order_id ("pi_..."). The
     * Charge object's own 'payment_intent' field is what links it back
     * to the PaymentIntent id our Invoice rows actually store, so
     * order_id is resolved from that field for a refund event
     * specifically. 'reference' is the refund's own id when Stripe
     * includes it (object.refunds.data[0].id), falling back to the
     * charge id otherwise.
     */
    public function parseWebhookEvent(array $payload): array
    {
        $object = $payload['data']['object'] ?? [];
        $type = $payload['type'] ?? '';
        $id = $object['id'] ?? null;

        $isRefund = $type === 'charge.refunded';

        return [
            'order_id' => $isRefund ? ($object['payment_intent'] ?? $id) : $id,
            'payment_id' => $id,
            'is_success' => ! $isRefund && (
                $type === 'payment_intent.succeeded' || ($object['status'] ?? null) === 'succeeded'
            ),
            'is_refund' => $isRefund,
            'reference' => $isRefund ? ($object['refunds']['data'][0]['id'] ?? $id) : null,
        ];
    }
}
