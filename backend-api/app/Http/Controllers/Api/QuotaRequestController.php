<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentGatewaySetting;
use App\Models\QuotaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quota Exhaustion Request Workflow & Custom Invoice Generation.
 *
 * SCHEMA CORRECTION (disclosed): the spec asks to increment
 * `accounts.message_quota_limit` on approval — no such column exists
 * anywhere in this codebase (confirmed by inspection of Account.php and
 * every accounts migration). Message quota is modeled PER-SUBSCRIPTION,
 * not per-account: Subscription::total_allocated_messages (paired with
 * Subscription::used_messages) is this app's actual, already-wired quota
 * field — Account has never carried one, and every existing quota read
 * path (AnalyticsController, ChatbotEngineService, ProcessPaymentAlertJob,
 * BillingPage) already reads it from there. approve() below increments
 * the account's CURRENT SUBSCRIPTION's total_allocated_messages instead
 * of inventing a second, disconnected quota column that nothing else
 * would read. See this refactor's audit report.
 *
 * PRICING ASSUMPTION (disclosed, [Hypothesis] — not stated anywhere in
 * the spec): no top-up rate exists in PlanCatalog (which prices whole
 * plans only) or anywhere else. RATE_PER_EXTRA_MESSAGE is a placeholder,
 * the same disclosed-constant pattern PaymentGatewayController already
 * uses for TAX_RATE — a one-line change once the real business rate is
 * known.
 */
class QuotaRequestController extends Controller
{
    use ResolvesTenantAccount;

    /** INR per extra message — [Hypothesis], see class docblock. */
    private const RATE_PER_EXTRA_MESSAGE = 1.00;

    /** Mirrors PaymentGatewayController::TAX_RATE exactly (same disclosed 18% GST assumption). */
    private const TAX_RATE = 0.18;

    /**
     * POST /api/quota-requests/store — Client Admin submits a top-up
     * request. Deliberately routed OUTSIDE the subscription.guard
     * middleware group (see routes/api.php), for the same reason
     * /billing/* already is: the moment this is most needed is exactly
     * when the account's subscription status has flipped to 'exhausted'
     * (Subscription::computeStatus()), which makes
     * Account::hasActiveSubscription() false. Under the ordinary
     * tenant.isolation + subscription.guard combo every other tenant
     * route uses, this POST would itself 403 with SUBSCRIPTION_EXPIRED
     * at exactly 100% quota usage — the literal trigger case named in
     * the spec. Mirrors the same placement PaymentGatewayController's
     * routes already use and for the identical reason.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->attributes->get('is_super_admin')) {
            return response()->json([
                'message' => 'Super Admin has no tenant account to request quota for.',
            ], 403);
        }

        $account = $this->requireAccount($request, 'Select a client/tenant account to request quota for.');
        $subscription = $account->currentSubscription;
        abort_if(! $subscription, 422, 'This account has no subscription to top up.');

        // Conditional Quota Top-Up — server-side mirror of the frontend's
        // plan-type gate: only a flat_quota subscription has a finite
        // total_allocated_messages to increment. 'unlimited' has nothing
        // to top up; 'per_message' has no cap to hit in the first place.
        if ($subscription->billing_model !== 'flat_quota') {
            return response()->json([
                'message' => 'Extra quota requests are only available for Flat Quota plans.',
            ], 422);
        }

        $data = $request->validate([
            'requested_extra_messages' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $quotaRequest = QuotaRequest::create([
            'account_id' => $account->id,
            'requested_by' => $request->user()->id,
            'requested_extra_messages' => $data['requested_extra_messages'],
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Quota top-up request submitted. The Super Admin will review it shortly.',
            'data' => $quotaRequest,
        ], 201);
    }

    /**
     * GET /api/admin/quota-requests — Super Admin only (route-gated
     * permission:manage-billing-settings, the same tier as this
     * controller's admin/billing siblings — gateway/mail settings).
     * Defaults to pending-only; pass ?status=all (or a specific status)
     * to see the rest.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $perPage = min((int) $request->integer('per_page', 15), 100);

        $requests = QuotaRequest::with(['account:id,company_name', 'requestedBy:id,name,email'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($requests);
    }

    /**
     * POST /api/admin/quota-requests/{id}/approve — Super Admin only.
     * Increments the account's current subscription's
     * total_allocated_messages (see class docblock) and generates the
     * Invoice row in one DB transaction, so a mid-request failure can
     * never leave quota credited without an invoice (or vice versa).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $quotaRequest = QuotaRequest::find($id);
        abort_if(! $quotaRequest, 404, 'Quota request not found.');
        abort_if($quotaRequest->status !== 'pending', 422, 'This request has already been reviewed.');

        $account = $quotaRequest->account;
        abort_if(! $account, 404, 'The requesting account no longer exists.');

        $subscription = $account->currentSubscription;
        abort_if(
            ! $subscription || $subscription->total_allocated_messages === null,
            422,
            'This account no longer has a Flat Quota subscription to top up.'
        );

        [$freshSubscription, $invoice] = DB::transaction(function () use ($quotaRequest, $account, $subscription, $request) {
            $subscription->increment('total_allocated_messages', $quotaRequest->requested_extra_messages);

            $amount = round($quotaRequest->requested_extra_messages * self::RATE_PER_EXTRA_MESSAGE, 2);
            $tax = round($amount * self::TAX_RATE, 2);
            $total = round($amount + $tax, 2);

            // GATEWAY PLACEHOLDER (disclosed): this invoice has no real
            // gateway transaction behind it — it is generated directly by
            // Super Admin approval, not a checkout. payment_gateway is
            // NOT NULL on this table, so this picks whichever gateway is
            // actually enabled/configured (same lookup
            // PaymentGatewayController::plans() uses), falling back to
            // 'razorpay' as a literal placeholder if none is configured
            // yet. See this refactor's audit report: the "Pay Invoice"
            // action this invoice gets on BillingPage downloads its PDF —
            // it does not launch a gateway checkout, since that would
            // require generalizing PaymentGatewayController's plan-key-
            // only createOrder() to accept an arbitrary existing invoice,
            // a separate, payment-critical change out of this task's scope.
            $gateway = PaymentGatewaySetting::enabledGatewaysCached()
                ->first(fn (PaymentGatewaySetting $s) => $s->isFullyConfigured())
                ?->gateway ?? 'razorpay';

            $invoice = Invoice::create([
                'account_id' => $account->id,
                'invoice_number' => $this->generateInvoiceNumber($account->id),
                'plan_key' => 'quota_topup',
                'plan_label' => "Extra Message Top-Up (Count: {$quotaRequest->requested_extra_messages})",
                'amount' => $amount,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'currency' => 'INR',
                'payment_gateway' => $gateway,
                // STATUS CORRECTION (disclosed): the spec says 'unpaid' /
                // 'pending_payment' — this table's actual status enum
                // (the invoices migration, InvoiceCreditService,
                // PaymentGatewayController) is 'pending' | 'paid' |
                // 'failed'. Using 'pending' keeps this row consistent
                // with every other invoice in the table and with
                // BillingPage's existing status badge, rather than
                // introducing a third, incompatible status value.
                'status' => 'pending',
            ]);

            $quotaRequest->forceFill([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'invoice_id' => $invoice->id,
            ])->save();

            return [$subscription->fresh(), $invoice];
        });

        return response()->json([
            'message' => 'Quota request approved. Extra quota added and invoice generated.',
            'data' => [
                'quota_request' => $quotaRequest->fresh(['account:id,company_name', 'requestedBy:id,name,email']),
                'subscription' => $freshSubscription,
                'invoice' => $invoice,
            ],
        ]);
    }

    /** Same format as PaymentGatewayController::generateInvoiceNumber() — kept consistent across both invoice-creating code paths. */
    private function generateInvoiceNumber(int $accountId): string
    {
        return sprintf('INV-%d-%s-%s', $accountId, now()->format('Ymd'), strtoupper(bin2hex(random_bytes(3))));
    }
}
