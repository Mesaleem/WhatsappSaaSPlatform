<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\PaymentGatewaySetting;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Billing\PlanRepository;
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

    /*
     * Phase 5 Task 11 — checkout resolves plans from the DATABASE now,
     * through the one repository, never from the static PlanCatalog and
     * never with an ad-hoc Plan::where() of its own. A plan created or
     * repriced through the Plan Management API is therefore purchasable
     * immediately, which is the whole point of the cutover.
     */
    public function __construct(private readonly PlanRepository $plans)
    {
    }

    /** GET /api/billing/plans */
    public function plans(): JsonResponse
    {
        /*
         * Only ACTIVE plans, and only the customer-facing fields —
         * toPublicArray() is where that projection lives, so no admin
         * metadata (is_active, capability bundle, ids, timestamps) can
         * leak into a public response by accident. The field names and
         * formatting are unchanged from the PlanCatalog version, so the
         * existing pricing page needs no change at all.
         */
        $plans = $this->plans->purchasable()
            ->map(fn ($plan) => $this->plans->toPublicArray($plan, self::TAX_RATE))
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

        /*
         * The ONLY two things the client may choose. Price, quota,
         * engine, duration and the capability bundle are deliberately
         * absent from this rule set, so a request carrying them has
         * them dropped by validate() before any code sees them — the
         * server reads every one of those from the database plan below.
         */
        $data = $request->validate([
            'plan_key' => ['required', 'string'],
            'gateway' => ['required', Rule::in(PaymentGatewayFactory::SUPPORTED_GATEWAYS)],
        ]);

        // Unknown slug and deactivated plan are the same 422 on purpose:
        // a retired plan must not be buyable, and the response must not
        // reveal which retired plans exist.
        $plan = $this->plans->findPurchasable($data['plan_key']);
        abort_if(! $plan, 422, 'Unknown plan.');

        try {
            $driver = PaymentGatewayFactory::make($data['gateway']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Straight from the database row — never from the request.
        $planPrice = (float) $plan->price;
        $tax = round($planPrice * self::TAX_RATE, 2);
        $total = round($planPrice + $tax, 2);
        $currency = 'INR';
        $invoiceNumber = $this->generateInvoiceNumber($account->id);

        $invoice = new Invoice([
            'account_id' => $account->id,
            'invoice_number' => $invoiceNumber,
            'plan_key' => $data['plan_key'],
            'plan_label' => $plan->label,
            // The invoice snapshots the price at purchase time. A later
            // repricing of the plan never rewrites this row — see
            // Task 11's "existing invoices are historical" rule.
            'amount' => $planPrice,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'currency' => $currency,
            'payment_gateway' => $data['gateway'],
            'status' => 'pending',
        ]);

        // Phase 5 fix P5-4 — and the rest of the purchased terms (engine,
        // billing model, rate, quota, duration), from the same database
        // row, in the same INSERT. Fulfilment reads these, not the plan,
        // so an admin edit after this point cannot change this order.
        $invoice->capturePlanTerms($plan)->save();

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
