<?php

namespace App\Services\Payment;

/**
 * Strategy Pattern for payment gateways — mirrors
 * App\Services\WhatsApp\WhatsAppDriverInterface (Module 5). Deliberately
 * array-in/array-out (not typed DTOs), consistent with that interface's
 * established convention in this codebase.
 *
 * No official Razorpay/Stripe SDK is used: neither razorpay/razorpay nor
 * stripe/stripe-php is in composer.json, and none can be installed from
 * this session (no php/composer binary reachable — see the Module 8
 * report). Both gateways expose a plain REST API, so both drivers call it
 * directly via Laravel's Http facade, the same approach already used for
 * MetaCloudApiDriver (Module 5).
 */
interface PaymentGatewayInterface
{
    /**
     * Create an order/payment-intent with the gateway.
     *
     * @param int $amountInSmallestUnit Amount in the currency's smallest
     *        unit (paise for INR) — both gateways require this, never a
     *        decimal major-unit amount.
     * @param array<string, string> $notes Arbitrary key/value metadata
     *        attached to the gateway order (e.g. invoice_number).
     * @return array{success: bool, order_id?: string, client_secret?: string, error?: string, raw?: mixed}
     */
    public function createOrder(int $amountInSmallestUnit, string $currency, string $receipt, array $notes = []): array;

    /**
     * Synchronous, client-triggered verification (called from
     * POST /api/billing/verify-payment). This is a fast, OPTIMISTIC check
     * for immediate UI feedback — the webhook handler is the source of
     * truth that actually gets trusted for money movement in production;
     * both paths funnel into the same idempotent InvoiceCreditService, so
     * whichever fires first wins and the other becomes a safe no-op.
     *
     * @param array<string, string> $input Gateway-specific verification
     *        input (Razorpay: order_id/payment_id/signature triple;
     *        Stripe: payment_intent_id, looked up server-side).
     */
    public function verifyPayment(array $input): bool;

    /**
     * Verify a webhook request's authenticity using this gateway's own
     * signature scheme (Razorpay: HMAC-SHA256 of the raw body; Stripe:
     * HMAC-SHA256 of "{timestamp}.{raw body}" with a replay-tolerance
     * window). $rawBody MUST be the exact, unparsed request body — both
     * schemes are byte-sensitive.
     */
    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret): bool;

    /**
     * Extract {order_id, payment_id, is_success} from an already-decoded
     * webhook JSON payload, gateway-shape-specific.
     *
     * @param array<string, mixed> $payload
     * @return array{order_id: ?string, payment_id: ?string, is_success: bool}
     */
    public function parseWebhookEvent(array $payload): array;
}
