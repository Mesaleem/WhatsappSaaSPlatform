<?php

namespace App\Services\Crm\Sales;

use App\Models\Account;
use App\Models\CrmDocument;
use App\Models\CrmGatewaySetting;
use App\Models\CrmPayment;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\RazorpayGatewayDriver;
use App\Services\Payment\StripeGatewayDriver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * CRM Sales Phase 3 — the tenant's OWN online payments (its customers pay the tenant,
 * never WapHub). Reuses the existing gateway drivers (RazorpayGatewayDriver /
 * StripeGatewayDriver: order creation, payment verification, webhook signature
 * verification, event parsing) with the TENANT's keys instead of the platform's.
 * WapHub's own PaymentWebhookController / invoices are not touched.
 *
 * TRUST: a payment is recorded only from a server-side proof — the gateway's signed
 * checkout response (Razorpay HMAC / Stripe intent lookup) or a webhook whose signature
 * verifies with the tenant's webhook secret. Amount, currency, tenant and invoice come
 * from OUR order row, never from the browser. Duplicate / replayed events are ignored
 * (crm_webhook_events unique) and the payment itself is unique per gateway payment id
 * (CrmPaymentService), so callback + webhook record ONE payment.
 */
class CrmGatewayService
{
    public function __construct(
        private readonly CrmPaymentService $payments,
        private readonly CrmDocumentCalculator $calc,
    ) {
    }

    public function settingsFor(Account $account): CrmGatewaySetting
    {
        return CrmGatewaySetting::query()->firstOrNew(['account_id' => $account->id], ['gateway' => 'razorpay', 'mode' => 'test', 'is_enabled' => false]);
    }

    /**
     * A blank secret keeps the stored one (secrets are never sent back to the browser).
     *
     * @param array{gateway: string, mode: string, key_id?: string|null, key_secret?: string|null, webhook_secret?: string|null, is_enabled: bool} $data
     */
    public function saveSettings(Account $account, array $data): CrmGatewaySetting
    {
        $settings = $this->settingsFor($account);
        $settings->fill([
            'gateway' => $data['gateway'],
            'mode' => $data['mode'],
            'key_id' => $data['key_id'] ?? $settings->key_id,
            'is_enabled' => (bool) $data['is_enabled'],
        ]);
        foreach (['key_secret', 'webhook_secret'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $settings->{$secret} = $data[$secret];
            }
        }
        $settings->webhook_token ??= Str::random(48);

        if ($settings->is_enabled && (blank($settings->key_id) || blank($settings->key_secret))) {
            throw ValidationException::withMessages(['key_id' => ['Enter the key id and secret before turning online payments on.']]);
        }
        $settings->save();

        return $settings;
    }

    /**
     * Create the gateway order for paying an invoice's CURRENT balance.
     *
     * @return array<string, mixed> what the checkout needs (never a secret)
     */
    public function createOrder(CrmDocument $invoice): array
    {
        if (! $invoice->isPayable()) {
            throw ValidationException::withMessages(['invoice' => ['This invoice has nothing left to pay.']]);
        }
        $settings = CrmGatewaySetting::query()->forAccount($invoice->account_id)->first();
        if (! $settings || ! $settings->isUsable()) {
            throw ValidationException::withMessages(['gateway' => ['Online payment is not set up for this business. Please pay by another method.']]);
        }

        $minor = $this->calc->toMinor((string) $invoice->balance_due);
        $result = $this->driver($settings)->createOrder($minor, $invoice->currency, (string) $invoice->number, [
            'crm_document_id' => (string) $invoice->id,
            'account_id' => (string) $invoice->account_id,
        ]);
        if (! ($result['success'] ?? false) || empty($result['order_id'])) {
            Log::warning('CrmGatewayService: the gateway refused the order.', ['account_id' => $invoice->account_id, 'gateway' => $settings->gateway]);
            throw ValidationException::withMessages(['gateway' => ['The payment gateway could not start this payment. Please try again later.']]);
        }

        DB::table('crm_gateway_orders')->insert([
            'account_id' => $invoice->account_id,
            'crm_document_id' => $invoice->id,
            'gateway' => $settings->gateway,
            'gateway_order_id' => $result['order_id'],
            'amount' => $this->calc->format($minor),
            'currency' => $invoice->currency,
            'status' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'gateway' => $settings->gateway,
            'key_id' => $settings->key_id,
            'order_id' => $result['order_id'],
            'client_secret' => $result['client_secret'] ?? null,
            'amount_minor' => $minor,
            'amount' => $this->calc->format($minor),
            'currency' => $invoice->currency,
            'invoice_number' => $invoice->number,
            'seller_name' => $invoice->seller_snapshot['name'] ?? null,
        ];
    }

    /**
     * The browser's "payment done" callback for one of OUR orders. Verified with the
     * gateway (signature / intent lookup) before anything is recorded.
     *
     * @param array<string, mixed> $input razorpay_order_id + razorpay_payment_id + razorpay_signature, or payment_intent_id
     */
    public function confirmCheckout(CrmDocument $invoice, array $input): ?CrmPayment
    {
        $orderId = (string) ($input['razorpay_order_id'] ?? $input['payment_intent_id'] ?? '');
        $order = DB::table('crm_gateway_orders')->where('account_id', $invoice->account_id)->where('crm_document_id', $invoice->id)->where('gateway_order_id', $orderId)->first();
        $settings = CrmGatewaySetting::query()->forAccount($invoice->account_id)->first();
        if (! $order || ! $settings || ! $settings->isUsable()) {
            throw ValidationException::withMessages(['payment' => ['This payment could not be matched to the invoice.']]);
        }
        if (! $this->driver($settings)->verifyPayment($input)) {
            throw ValidationException::withMessages(['payment' => ['The payment could not be verified with the gateway.']]);
        }

        $paymentId = (string) ($input['razorpay_payment_id'] ?? $input['payment_intent_id']);

        return $this->recordForOrder($order, $settings->gateway, $paymentId);
    }

    /**
     * POST /api/webhooks/crm-payments/{token}. Returns a short outcome for the log;
     * the HTTP layer answers 400 only for a bad signature (so the gateway retries
     * nothing else).
     */
    public function handleWebhook(string $token, string $rawBody, string $signature, ?string $eventIdHeader): string
    {
        $settings = CrmGatewaySetting::query()->where('webhook_token', $token)->first();
        if (! $settings || ! $settings->is_enabled || blank($settings->webhook_secret)) {
            return 'not_configured';
        }

        $driver = $this->driver($settings);
        if (! $driver->verifyWebhookSignature($rawBody, $signature, (string) $settings->webhook_secret)) {
            throw new RuntimeException('invalid_signature');
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return 'unreadable';
        }
        $event = $driver->parseWebhookEvent($payload);
        $eventId = $eventIdHeader ?: ($payload['id'] ?? null) ?: hash('sha256', $rawBody);

        try {
            DB::table('crm_webhook_events')->insert([
                'account_id' => $settings->account_id, 'gateway' => $settings->gateway, 'event_id' => mb_substr((string) $eventId, 0, 191),
                'outcome' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException) {
            return 'duplicate';
        }

        $eventRow = DB::table('crm_webhook_events')->where('gateway', $settings->gateway)->where('event_id', mb_substr((string) $eventId, 0, 191));

        $outcome = 'ignored';
        try {
            if ($event['is_refund'] && ! empty($event['order_id'])) {
                $outcome = $this->handleRefund($settings, $payload, (string) $event['order_id'], (string) ($event['reference'] ?? ''));
            } elseif (in_array($payload['event'] ?? $payload['type'] ?? '', ['payment.failed', 'payment_intent.payment_failed'], true) && ! empty($event['order_id'])) {
                DB::table('crm_gateway_orders')->where('account_id', $settings->account_id)->where('gateway', $settings->gateway)
                    ->where('gateway_order_id', $event['order_id'])->where('status', 'created')->update(['status' => 'failed', 'updated_at' => now()]);
                $outcome = 'payment_failed';
            } elseif ($event['is_success'] && ! empty($event['order_id']) && ! empty($event['payment_id'])) {
                $order = DB::table('crm_gateway_orders')->where('account_id', $settings->account_id)->where('gateway', $settings->gateway)->where('gateway_order_id', $event['order_id'])->first();
                if ($order === null) {
                    $outcome = 'unknown_order';
                } elseif (! $this->amountMatches($settings->gateway, $payload, $order)) {
                    Log::warning('CrmGatewayService: webhook amount does not match the order; not recorded.', ['account_id' => $settings->account_id, 'order' => $order->id]);
                    $outcome = 'amount_mismatch';
                } else {
                    $this->recordForOrder($order, $settings->gateway, (string) $event['payment_id']);
                    $outcome = 'recorded';
                }
            }
        } catch (\Throwable $e) {
            // Not processed: forget the event so the gateway's retry is handled, not skipped as a duplicate.
            (clone $eventRow)->delete();
            throw $e;
        }

        (clone $eventRow)->update(['outcome' => $outcome, 'updated_at' => now()]);

        return $outcome;
    }

    /**
     * Record the payment of one of our orders (idempotent per gateway payment id). It is
     * applied to the invoice up to its balance at this moment; anything above stays on
     * the payment as an unallocated credit.
     */
    private function recordForOrder(object $order, string $gateway, string $paymentId): CrmPayment
    {
        $invoice = CrmDocument::query()->findOrFail($order->crm_document_id);
        $account = Account::query()->findOrFail($order->account_id);
        $orderMinor = $this->calc->toMinor((string) $order->amount);
        $apply = min($orderMinor, $invoice->isPayable() ? $this->calc->toMinor((string) $invoice->balance_due) : 0);

        $payment = $this->payments->record($account, [
            'amount' => $this->calc->format($orderMinor),
            'method' => 'gateway',
            'reference' => $paymentId,
            'source' => 'gateway',
            'gateway' => $gateway,
            'gateway_payment_id' => $paymentId,
            'contact_id' => $invoice->contact_id,
            'crm_company_id' => $invoice->crm_company_id,
            'allocations' => $apply > 0 ? [['crm_document_id' => $invoice->id, 'amount' => $this->calc->format($apply)]] : [],
        ], null);

        DB::table('crm_gateway_orders')->where('id', $order->id)->update(['status' => 'paid', 'crm_payment_id' => $payment->id, 'updated_at' => now()]);

        return $payment;
    }

    /**
     * A refund made in the gateway. A FULL refund reverses the recorded payment (the
     * invoices are open again — the same reversal staff can do by hand). A partial
     * refund cannot be split automatically: it is logged and left for staff, who can
     * reverse and re-record. Never double-reverses.
     *
     * @param array<string, mixed> $payload
     */
    private function handleRefund(CrmGatewaySetting $settings, array $payload, string $orderId, string $refundRef): string
    {
        $order = DB::table('crm_gateway_orders')->where('account_id', $settings->account_id)->where('gateway', $settings->gateway)->where('gateway_order_id', $orderId)->first();
        $payment = $order?->crm_payment_id ? CrmPayment::query()->find($order->crm_payment_id) : null;
        if (! $payment) {
            return 'refund_unknown_payment';
        }
        if ($payment->reversed_at !== null) {
            return 'refund_already_reversed';
        }

        $refunded = $settings->gateway === 'razorpay'
            ? ($payload['payload']['refund']['entity']['amount'] ?? null)
            : ($payload['data']['object']['amount_refunded'] ?? null);
        if ($refunded === null || (int) $refunded !== $this->calc->toMinor((string) $payment->amount)) {
            Log::warning('CrmGatewayService: partial or unknown refund — left for staff.', ['account_id' => $settings->account_id, 'payment_id' => $payment->id]);

            return 'refund_partial_needs_staff';
        }

        $this->payments->reverse($payment, 'Refunded in the gateway'.($refundRef !== '' ? " ({$refundRef})" : ''), null);
        DB::table('crm_gateway_orders')->where('id', $order->id)->update(['status' => 'refunded', 'updated_at' => now()]);

        return 'refunded';
    }

    /** @param array<string, mixed> $payload */
    private function amountMatches(string $gateway, array $payload, object $order): bool
    {
        $paid = $gateway === 'razorpay'
            ? ($payload['payload']['payment']['entity']['amount'] ?? null)
            : ($payload['data']['object']['amount_received'] ?? $payload['data']['object']['amount'] ?? null);

        return $paid !== null && (int) $paid === $this->calc->toMinor((string) $order->amount);
    }

    private function driver(CrmGatewaySetting $settings): PaymentGatewayInterface
    {
        return match ($settings->gateway) {
            'razorpay' => new RazorpayGatewayDriver((string) $settings->key_id, (string) $settings->key_secret),
            'stripe' => new StripeGatewayDriver((string) $settings->key_id, (string) $settings->key_secret),
            default => throw new RuntimeException('Unsupported gateway.'),
        };
    }
}
