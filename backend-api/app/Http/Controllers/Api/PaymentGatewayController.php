<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\PaymentGatewaySetting;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Support\PlanCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class PaymentGatewayController extends Controller
{
    /**
     * 18% GST — India's standard SaaS/digital-services rate. [Inference —
     * disclosed assumption, not stated anywhere in the spec: no tax rate
     * or jurisdiction was given. This is a constant specifically so it's a
     * one-line change if the real rate differs.]
     */
    private const TAX_RATE = 0.18;

    /** GET /api/billing/plans */
    public function plans(): JsonResponse
    {
        $plans = collect(PlanCatalog::all())
            ->map(function (array $plan, string $key) {
                $tax = round($plan['price'] * self::TAX_RATE, 2);

                return [
                    'key' => $key,
                    'label' => $plan['label'],
                    'description' => $plan['description'],
                    'engine_type' => $plan['engine_type'],
                    'billing_model' => $plan['billing_model'],
                    'total_allocated_messages' => $plan['total_allocated_messages'],
                    'duration_days' => $plan['duration_days'],
                    'price' => number_format($plan['price'], 2, '.', ''),
                    'tax_amount' => number_format($tax, 2, '.', ''),
                    'total_amount' => number_format($plan['price'] + $tax, 2, '.', ''),
                ];
            })
            ->values();

        $availableGateways = PaymentGatewaySetting::enabledGatewaysCached()
            ->filter(fn (PaymentGatewaySetting $s) => $s->isFullyConfigured())
            ->pluck('gateway')
            ->values();

        return response()->json([
            'plans' => $plans,
            'available_gateways' => $availableGateways,
            'tax_rate' => self::TAX_RATE,
        ]);
    }

    /**
     * POST /api/billing/create-order — creates the Invoice row (status
     * 'pending') and the matching gateway order/PaymentIntent in one go.
     * Nothing is credited here — only verifyPayment() (below) or a
     * webhook does that.
     */
    public function createOrder(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'plan_key' => ['required', 'string'],
            'gateway' => ['required', Rule::in(PaymentGatewayFactory::SUPPORTED_GATEWAYS)],
        ]);

        $plan = PlanCatalog::find($data['plan_key']);
        abort_if(! $plan, 422, 'Unknown plan.');

        try {
            $driver = PaymentGatewayFactory::make($data['gateway']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tax = round($plan['price'] * self::TAX_RATE, 2);
        $total = round($plan['price'] + $tax, 2);
        $currency = 'INR';
        $invoiceNumber = $this->generateInvoiceNumber($account->id);

        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => $invoiceNumber,
            'plan_key' => $data['plan_key'],
            'plan_label' => $plan['label'],
            'amount' => $plan['price'],
            'tax_amount' => $tax,
            'total_amount' => $total,
            'currency' => $currency,
            'payment_gateway' => $data['gateway'],
            'status' => 'pending',
        ]);

        // Both gateways require the smallest currency unit (paise for INR),
        // never a decimal major-unit amount.
        $amountInSmallestUnit = (int) round($total * 100);

        $result = $driver->createOrder($amountInSmallestUnit, $currency, $invoiceNumber, [
            'invoice_id' => (string) $invoice->id,
            'account_id' => (string) $account->id,
            'plan_key' => $data['plan_key'],
        ]);

        if (empty($result['success'])) {
            $invoice->forceFill(['status' => 'failed', 'gateway_raw_response' => $result['raw'] ?? null])->save();

            return response()->json([
                'message' => $result['error'] ?? 'Could not create the payment order.',
            ], 502);
        }

        $invoice->forceFill([
            'gateway_order_id' => $result['order_id'],
            'gateway_raw_response' => $result['raw'] ?? null,
        ])->save();

        $settings = PaymentGatewayFactory::settingsFor($data['gateway']);

        return response()->json([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'gateway' => $data['gateway'],
            'order_id' => $result['order_id'],
            // Stripe only — Razorpay's Checkout.js needs only key_id + order_id.
            'client_secret' => $result['client_secret'] ?? null,
            // Public key/Key ID — safe to hand to the frontend, never the secret.
            'key_id' => $settings?->activeKeyId(),
            'amount' => $amountInSmallestUnit,
            'currency' => $currency,
            'plan' => ['key' => $data['plan_key'], 'label' => $plan['label']],
        ], 201);
    }

    /**
     * POST /api/billing/verify-payment — fast, client-triggered check for
     * immediate UI feedback. SECURITY: the submitted order/PaymentIntent
     * id is checked against the invoice's OWN gateway_order_id before any
     * signature/status check runs — without this, a client could replay a
     * valid signature from a DIFFERENT (their own, genuinely paid) order
     * against an unrelated invoice_id and get it marked paid for free,
     * since a Razorpay signature only proves "this order+payment pair is
     * genuine", not "...and belongs to the invoice you're claiming".
     *
     * The webhook (PaymentWebhookController) is the actual source of
     * truth for production; this and the webhook both funnel into the
     * same idempotent InvoiceCreditService.
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'razorpay_order_id' => ['sometimes', 'string'],
            'razorpay_payment_id' => ['sometimes', 'string'],
            'razorpay_signature' => ['sometimes', 'string'],
            'payment_intent_id' => ['sometimes', 'string'],
        ]);

        $invoice = Invoice::forAccount($account->id)->find($data['invoice_id']);
        abort_if(! $invoice, 404, 'Invoice not found.');

        if ($invoice->isPaid()) {
            return response()->json([
                'message' => 'This invoice was already confirmed.',
                'invoice' => $invoice,
                'subscription' => $account->fresh()->currentSubscription,
            ]);
        }

        $submittedOrderId = $data['razorpay_order_id'] ?? $data['payment_intent_id'] ?? null;
        if ($submittedOrderId !== $invoice->gateway_order_id) {
            return response()->json(['message' => 'This payment does not match this invoice.'], 422);
        }

        try {
            $driver = PaymentGatewayFactory::make($invoice->payment_gateway);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $driver->verifyPayment($data)) {
            return response()->json(['message' => 'Payment could not be verified.'], 422);
        }

        $paymentId = $data['razorpay_payment_id'] ?? $data['payment_intent_id'];
        $credited = app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, $paymentId);

        return response()->json([
            'message' => $credited ? 'Payment verified — quota credited.' : 'Payment already processed.',
            'invoice' => $invoice->fresh(),
            'subscription' => $account->fresh()->currentSubscription,
        ]);
    }

    private function generateInvoiceNumber(int $accountId): string
    {
        return sprintf('INV-%d-%s-%s', $accountId, now()->format('Ymd'), strtoupper(bin2hex(random_bytes(3))));
    }

    private function account(Request $request): Account
    {
        $accountId = $request->user()->account_id;
        abort_if(! $accountId, 422, 'Super Admin has no tenant account to bill.');

        return Account::findOrFail($accountId);
    }
}
