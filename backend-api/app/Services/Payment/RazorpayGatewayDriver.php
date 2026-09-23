<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Throwable;

class RazorpayGatewayDriver implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
    ) {
    }

    public function createOrder(int $amountInSmallestUnit, string $currency, string $receipt, array $notes = []): array
    {
        try {
            $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                ->timeout(15)
                ->post(self::BASE_URL.'/orders', [
                    'amount' => $amountInSmallestUnit,
                    'currency' => $currency,
                    'receipt' => $receipt,
                    'notes' => $notes,
                ]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Razorpay API is unreachable.'];
        }

        if ($response->failed()) {
            return [
                'success' => false,
                'error' => $response->json('error.description') ?? 'Razorpay rejected the order request.',
                'raw' => $response->json(),
            ];
        }

        return [
            'success' => true,
            'order_id' => $response->json('id'),
            'raw' => $response->json(),
        ];
    }

    /**
     * Razorpay's client-side checkout hands the browser
     * razorpay_order_id + razorpay_payment_id + razorpay_signature; the
     * signature is HMAC-SHA256 of "{order_id}|{payment_id}" keyed with the
     * account's Key Secret. This never calls Razorpay's API — it's a pure
     * local computation, which is *why* it's safe to trust as one of two
     * paths into InvoiceCreditService (the webhook being the other).
     */
    public function verifyPayment(array $input): bool
    {
        $orderId = $input['razorpay_order_id'] ?? null;
        $paymentId = $input['razorpay_payment_id'] ?? null;
        $signature = $input['razorpay_signature'] ?? null;

        if (! $orderId || ! $paymentId || ! $signature) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$orderId}|{$paymentId}", $this->keySecret);

        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret): bool
    {
        if ($signatureHeader === '' || $webhookSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Razorpay webhook payload shape:
     * { event: "payment.captured", payload: { payment: { entity: { id, order_id, status } } } }
     *
     * A refund webhook ('refund.processed') carries BOTH the payment AND
     * refund sub-objects in the same payload shape (Razorpay's own
     * documented behavior), so the existing order_id/payment_id
     * extraction above already works unchanged for a refund event —
     * only the is_refund/reference detection below is new.
     * 'refund.created'/'refund.failed' are deliberately NOT treated as
     * is_refund here: only a settled ('processed') refund should ever
     * reverse an Agent commission.
     */
    public function parseWebhookEvent(array $payload): array
    {
        $entity = $payload['payload']['payment']['entity'] ?? [];
        $refundEntity = $payload['payload']['refund']['entity'] ?? [];
        $event = $payload['event'] ?? '';

        $isRefund = $event === 'refund.processed';

        return [
            'order_id' => $entity['order_id'] ?? null,
            'payment_id' => $entity['id'] ?? null,
            'is_success' => ! $isRefund && (
                in_array($event, ['payment.captured', 'order.paid'], true)
                || ($entity['status'] ?? null) === 'captured'
            ),
            'is_refund' => $isRefund,
            'reference' => $isRefund ? ($refundEntity['id'] ?? null) : null,
        ];
    }
}
