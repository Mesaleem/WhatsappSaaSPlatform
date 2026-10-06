<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\WhatsAppNumber;
use App\Models\PaymentGatewaySetting;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\WhatsApp\WhatsAppAddonService;
use App\Services\WhatsApp\WhatsAppNumberException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Paid extra WhatsApp numbers: buying them (tenant) and recording the manual payment
 * (Super Admin or Agent, until a payment gateway is configured).
 */
class WhatsAppAddonController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly WhatsAppAddonService $addons)
    {
    }

    /** GET /api/whatsapp/numbers/addon-price — the price shown before buying. */
    public function price(): JsonResponse
    {
        return response()->json([
            'price' => $this->addons->price(),
            'currency' => 'INR',
            'includes_gst' => true,
            'term_months' => (int) config('whatsapp_numbers.addon_term_months'),
            'max_per_purchase' => (int) config('whatsapp_numbers.max_addons_per_purchase'),
        ]);
    }

    /** POST /api/whatsapp/numbers/purchase  body: { phone_numbers: [...] } */
    public function purchase(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate([
            'phone_numbers' => ['required', 'array', 'min:1', 'max:'.config('whatsapp_numbers.max_addons_per_purchase')],
            'phone_numbers.*' => ['required', 'string', 'max:25'],
        ]);

        try {
            $invoice = $this->addons->purchase($account, $data['phone_numbers']);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json([
            'message' => 'Invoice created. The numbers are connected after payment.',
            'invoice' => $this->presentInvoice($invoice),
        ], 201);
    }

    /** DELETE /api/whatsapp/addon-invoices/{id} — cancel an unpaid purchase. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');

        try {
            $this->addons->cancelPending($account, $id);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Purchase cancelled. The reserved numbers were released.']);
    }

    /**
     * POST /api/admin/whatsapp/addon-invoices/{id}/record-payment?account_id=…
     * body: { reference } — cash or manual payment. Super Admin or Agent only; an
     * Agent reaches only its own clients (requireTargetAccount applies that scope).
     */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request, 'Select the client account this invoice belongs to (pass ?account_id=).');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['required', 'string', 'in:'.implode(',', \App\Models\ManualPayment::METHODS)],
            'transaction_id' => ['required', 'string', 'max:120'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'term_starts_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $invoice = Invoice::query()->where('account_id', $account->id)->whereKey($id)->first();
        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.', 'error_code' => 'not_found'], 404);
        }

        try {
            $paid = $this->addons->recordManualPayment($invoice, $request->user(), $data);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json([
            'message' => 'Payment recorded. The numbers now have their one-month term.',
            'invoice' => $this->presentInvoice($paid),
        ]);
    }

    /**
     * GET /api/admin/whatsapp/addon-invoices/pending — unpaid extra-number invoices the
     * caller may act on: all accounts for a Super Admin, the caller's own clients for an Agent.
     */
    public function pending(Request $request): JsonResponse
    {
        try {
            $invoices = $this->addons->pendingInvoicesFor($request->user());
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json([
            'data' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'account' => [
                    'id' => $invoice->account_id,
                    'name' => $invoice->account?->company_name,
                ],
                'total_amount' => (float) $invoice->total_amount,
                'currency' => $invoice->currency,
                'created_at' => $invoice->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * POST /api/whatsapp/addon-invoices/{id}/pay  body: { gateway }
     * Online payment for an add-on invoice. Refused unless the Super Admin has
     * enabled and fully configured that gateway. Creates the gateway order for this
     * invoice and returns the same fields the plan checkout returns, so the screen
     * opens the same checkout. Activation happens when the gateway confirms the
     * payment (verify or webhook).
     */
    public function pay(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate([
            'gateway' => ['required', Rule::in(PaymentGatewayFactory::SUPPORTED_GATEWAYS)],
        ]);

        try {
            $invoice = $this->addons->payableInvoice($account, $id);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        $ready = PaymentGatewaySetting::enabledGatewaysCached()
            ->filter(fn (PaymentGatewaySetting $s) => $s->isFullyConfigured())
            ->pluck('gateway')
            ->all();

        if (! in_array($data['gateway'], $ready, true)) {
            return response()->json([
                'message' => 'Online payment is not available yet: no payment gateway has been configured.',
                'error_code' => 'gateway_not_configured',
            ], 422);
        }

        try {
            $driver = PaymentGatewayFactory::make($data['gateway']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => 'gateway_unavailable'], 422);
        }

        // Smallest currency unit (paise for INR), from the stored total: the total is never taken from the request.
        $amount = (int) round((float) $invoice->total_amount * 100);

        $result = $driver->createOrder($amount, 'INR', $invoice->invoice_number, [
            'invoice_id' => (string) $invoice->id,
            'account_id' => (string) $account->id,
            'plan_key' => $invoice->plan_key,
        ]);

        if (empty($result['success'])) {
            return response()->json([
                'message' => $result['error'] ?? 'Could not create the payment order.',
                'error_code' => 'order_failed',
            ], 502);
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
        ]);
    }

    private function presentInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('lineItems');

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            // GST-inclusive total only; no tax line is shown.
            'total_amount' => (float) $invoice->total_amount,
            'currency' => $invoice->currency,
            'items' => $invoice->lineItems->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'amount' => (float) $item->amount,
            ])->values(),
            'numbers' => WhatsAppNumber::query()
                ->where('addon_invoice_id', $invoice->id)
                ->get(['id', 'phone_number', 'status', 'term_ends_at'])
                ->values(),
        ];
    }

    private function error(WhatsAppNumberException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
    }
}
