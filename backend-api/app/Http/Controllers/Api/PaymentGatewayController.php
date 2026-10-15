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
     * No GST (owner, 2026-10-14): the customer pays exactly the plan price shown
     * (₹599 / ₹1,999 / ₹7,999 a month) and invoices carry no tax line. Was 0.18.
     * Every plan price, order, manual invoice and the public pricing page read
     * this one constant, so charging GST again is a one-line change.
     */
    public const TAX_RATE = 0.0;

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

    /** GET /api/billing/plans — with, for a tenant user, what each plan's button may do (PlanChangePolicy). */
    public function plans(Request $request): JsonResponse
    {
        /*
         * Only ACTIVE plans, and only the customer-facing fields —
         * toPublicArray() is where that projection lives, so no admin
         * metadata (is_active, capability bundle, ids, timestamps) can
         * leak into a public response by accident. The field names and
         * formatting are unchanged from the PlanCatalog version, so the
         * existing pricing page needs no change at all.
         */
        // A client also sees the custom plan(s) built for it (plan restructure, 2026-10-15).
        $accountId = $request->user()?->account_id;
        $account = $accountId ? Account::query()->with('currentSubscription')->find($accountId) : null;

        $plans = $this->plans->purchasable($account)
            ->map(fn ($plan) => $this->plans->toPublicArray($plan, self::TAX_RATE))
            ->values();

        $availableGateways = PaymentGatewaySetting::enabledGatewaysCached()
            ->filter(fn (PaymentGatewaySetting $s) => $s->isFullyConfigured())
            ->pluck('gateway')
            ->values();

        // Plan purchase rules (owner, 2026-10-14): each plan's action for THIS account, and
        // the running plan + any pending request, so the page shows the right buttons.
        if ($account) {
            $policy = app(\App\Services\Billing\PlanChangePolicy::class);
            $decisions = $policy->forAllPlans($account)->keyBy('key');
            $plans = $plans->map(fn (array $p) => $p + ['purchase' => $decisions[$p['key']] ?? null])->values();
            $state = $policy->state($account);
            // Plan restructure (2026-10-15): each plan limit and how much of it is used.
            $planUsage = app(\App\Services\Billing\PlanLimits::class)->usage($account);
            $planStatus = [
                'running' => $state['running'],
                'expires_at' => $state['expires_at'],
                'plan_key' => $state['plan_key'],
                'plan_label' => $state['plan_label'],
                'pending_invoice' => $state['pending'] ? [
                    'id' => $state['pending']->id,
                    'invoice_number' => $state['pending']->invoice_number,
                    'plan_key' => $state['pending']->plan_key,
                    'plan_label' => $state['pending']->plan_label,
                    'total_amount' => $state['pending']->total_amount,
                    'plan_change' => $state['pending']->plan_change,
                ] : null,
            ];
        }

        return response()->json([
            'plans' => $plans,
            'plan_status' => $planStatus ?? null,
            'plan_usage' => $planUsage ?? null,
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
        $plan = $this->plans->findPurchasable($data['plan_key'], $account);
        abort_if(! $plan, 422, 'Unknown plan.');

        // Plan purchase rules: one request at a time; no re-buying the running plan; a
        // smaller plan only after the running one ends; a bigger one is an upgrade.
        $decision = app(\App\Services\Billing\PlanChangePolicy::class)->decide($account->loadMissing('currentSubscription'), $plan);
        if (! $decision['allowed']) {
            return response()->json(['message' => $decision['message'], 'error_code' => $decision['action']], 409);
        }

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
        $invoice->plan_change = \App\Services\Billing\PlanChangePolicy::invoiceChange($decision['action']);

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
