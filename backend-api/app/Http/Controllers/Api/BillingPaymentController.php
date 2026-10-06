<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\ManualPaymentService;
use App\Services\Billing\PlanRepository;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\WhatsApp\WhatsAppNumberException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Plan payments that are not online: a client asks for a plan without a gateway (an invoice
 * waits for a manual payment), and a Super Admin or agent records the payment and chooses
 * the plan's start date. Online payment of an existing plan invoice is here too, so every
 * payment type has the same two ways in.
 */
class BillingPaymentController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(
        private readonly ManualPaymentService $payments,
        private readonly PlanRepository $plans,
    ) {
    }

    /** POST /api/billing/manual-checkout  body: { plan_key } — a pending invoice paid by hand. */
    public function manualCheckout(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate(['plan_key' => ['required', 'string']]);

        $plan = $this->plans->findPurchasable($data['plan_key']);
        if ($plan === null) {
            return response()->json(['message' => 'Unknown plan.', 'error_code' => 'unknown_plan'], 422);
        }

        $waiting = Invoice::query()
            ->where('account_id', $account->id)
            ->where('plan_key', $plan->slug)
            ->where('payment_gateway', 'manual')
            ->where('status', 'pending')
            ->exists();
        if ($waiting) {
            return response()->json([
                'message' => 'An invoice for this plan is already waiting for payment.',
                'error_code' => 'request_pending',
            ], 409);
        }

        // The same GST-inclusive figures the online checkout charges, from the database plan row.
        $planPrice = (float) $plan->price;
        $tax = round($planPrice * PaymentGatewayController::TAX_RATE, 2);
        $total = round($planPrice + $tax, 2);

        $invoice = new Invoice([
            'account_id' => $account->id,
            'invoice_number' => sprintf('INV-%d-%s-%s', $account->id, now()->format('Ymd'), strtoupper(bin2hex(random_bytes(3)))),
            'plan_key' => $plan->slug,
            'plan_label' => $plan->label,
            'amount' => $planPrice,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'currency' => 'INR',
            'payment_gateway' => 'manual',
            'status' => 'pending',
        ]);
        $invoice->capturePlanTerms($plan)->save();

        return response()->json([
            'message' => 'Invoice created. Pay it by bank transfer, UPI or cash. Your Super Admin or agent will record the payment and start the plan.',
            'invoice' => $invoice->fresh(),
        ], 201);
    }

    /**
     * POST /api/billing/invoices/{id}/pay  body: { gateway } — online payment of a pending plan
     * invoice. Refused (422) until that gateway is configured; the same steps as checkout.
     */
    public function payInvoice(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate(['gateway' => ['required', Rule::in(PaymentGatewayFactory::SUPPORTED_GATEWAYS)]]);

        $invoice = Invoice::query()->where('account_id', $account->id)->whereKey($id)->first();
        if ($invoice === null || $this->payments->kindOf($invoice) !== 'plan') {
            return response()->json(['message' => 'Invoice not found.', 'error_code' => 'not_found'], 404);
        }
        if ($invoice->status !== 'pending') {
            return response()->json(['message' => 'This invoice is already '.$invoice->status.'.', 'error_code' => 'invoice_not_pending'], 409);
        }

        try {
            $driver = PaymentGatewayFactory::make($data['gateway']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'Online payment is not available yet: '.$e->getMessage(), 'error_code' => 'gateway_not_configured'], 422);
        }

        $amount = (int) round((float) $invoice->total_amount * 100);
        $result = $driver->createOrder($amount, 'INR', $invoice->invoice_number, [
            'invoice_id' => (string) $invoice->id,
            'account_id' => (string) $account->id,
            'plan_key' => $invoice->plan_key,
        ]);

        if (empty($result['success'])) {
            return response()->json(['message' => $result['error'] ?? 'Could not create the payment order.', 'error_code' => 'order_failed'], 502);
        }

        $invoice->forceFill([
            'payment_gateway' => $data['gateway'],
            'gateway_order_id' => $result['order_id'],
            'gateway_raw_response' => $result['raw'] ?? null,
        ])->save();

        $settings = PaymentGatewayFactory::settingsFor($data['gateway']);

        return response()->json([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'gateway' => $data['gateway'],
            'order_id' => $result['order_id'],
            'client_secret' => $result['client_secret'] ?? null,
            'key_id' => $settings?->activeKeyId(),
            'amount' => $amount,
            'currency' => 'INR',
            'plan' => ['key' => $invoice->plan_key, 'label' => $invoice->plan_label],
        ], 201);
    }

    /** GET /api/admin/billing/pending-invoices — the pending invoices the caller may act on. */
    public function pending(Request $request): JsonResponse
    {
        try {
            $rows = $this->payments->pendingFor($request->user());
        } catch (WhatsAppNumberException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => $rows, 'gateways' => $this->payments->readyGateways()]);
    }

    /**
     * POST /api/admin/billing/invoices/{id}/record-payment — a manual payment for any invoice
     * (plan, WhatsApp number or module add-on). Same details and rules for all of them.
     */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['required', 'string', 'in:'.implode(',', \App\Models\ManualPayment::METHODS)],
            'transaction_id' => ['required', 'string', 'max:120'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'term_starts_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $invoice = Invoice::query()->whereKey($id)->first();
        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.', 'error_code' => 'not_found'], 404);
        }

        try {
            $this->payments->record($invoice, $request->user(), $data);
        } catch (WhatsAppNumberException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        return response()->json([
            'message' => 'Payment recorded. The plan or add-on is active from the chosen start date.',
            'invoice' => $invoice->fresh(),
        ]);
    }
}
