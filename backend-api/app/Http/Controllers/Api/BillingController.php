<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Invoice;
use App\Services\Pdf\SimplePdfWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Module 8, requirement 3 — invoicing & transaction history. Read-only:
 * invoices are created/paid exclusively by PaymentGatewayController and
 * PaymentWebhookController via InvoiceCreditService; this controller never
 * mutates one.
 *
 * clientSummary() is the one Super-Admin-only exception — see its own
 * docblock — everything else here stays tenant-scoped exactly as before.
 */
class BillingController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/billing/invoices — paginated, newest first, scoped to the caller's account. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $perPage = (int) $request->integer('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $invoices = Invoice::forAccount($account->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($invoices);
    }

    /**
     * GET /api/billing/invoices/{id}/pdf — one invoice per PDF, using the
     * same dependency-free SimplePdfWriter as Module 7's analytics export
     * (see ExportController::pdf() for why: no PDF library is installed
     * or installable in this environment).
     */
    public function pdf(Request $request, int $id): Response
    {
        $account = $this->account($request);

        $invoice = Invoice::forAccount($account->id)->find($id);
        abort_if(! $invoice, 404, 'Invoice not found.');

        $lines = [
            'WhatsApp SaaS Platform - Invoice',
            'Invoice Number: '.$invoice->invoice_number,
            'Account: '.$account->company_name.' (ID '.$account->id.')',
            '',
            'Plan: '.$invoice->plan_label.' ('.$invoice->plan_key.')',
            'Payment Gateway: '.ucfirst($invoice->payment_gateway),
            'Status: '.strtoupper($invoice->status),
            '',
            'Amount: '.$invoice->currency.' '.number_format((float) $invoice->amount, 2),
            'Tax: '.$invoice->currency.' '.number_format((float) $invoice->tax_amount, 2),
            'Total: '.$invoice->currency.' '.number_format((float) $invoice->total_amount, 2),
            '',
            $invoice->gateway_order_id ? 'Gateway Order ID: '.$invoice->gateway_order_id : null,
            $invoice->gateway_payment_id ? 'Gateway Payment ID: '.$invoice->gateway_payment_id : null,
            $invoice->paid_at ? 'Paid At: '.$invoice->paid_at->toDateTimeString() : 'Paid At: -',
            '',
            'Invoice Created: '.$invoice->created_at->toDateTimeString(),
            'Generated: '.now()->toDateTimeString(),
        ];

        $pdf = SimplePdfWriter::render($lines);
        $filename = sprintf('invoice-%s.pdf', $invoice->invoice_number);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdf),
        ]);
    }

    /**
     * GET /api/billing/client-summary — Absolute Super Admin Control /
     * Billing & Plans Module Overhaul: replaces the old "No tenant account
     * to bill" error a Super Admin used to hit on this page. Super Admin,
     * or an Agent scoped to its own Sub-Client tree (Wallet Visibility,
     * disclosed) — every other action on this controller stays
     * tenant-scoped via account(). Honors the same ?account_id= the
     * Header's client switcher already sets (via TenantIsolationMiddleware)
     * to narrow the table to one client instead of swapping to a different
     * UI — the same global-vs-one-account pattern already used by
     * AnalyticsController, TeamController and MessageLogController.
     *
     * There is no stored "plan name"/billing cadence for accounts
     * provisioned directly via AccountController::store() (only
     * billing_model/engine_type/price_paid) — plan_label is derived from
     * those fields rather than assumed to match a PlanCatalog entry, and
     * 'amount' is whatever price_paid was set to (there's no separate
     * monthly/annual cadence field in the schema).
     */
    public function clientSummary(Request $request): JsonResponse
    {
        // 3-Tier Hierarchy Wallet Visibility, disclosed: an Agent may view
        // this same summary, scoped to its own Sub-Client tree, via the
        // agent_scope_id attribute TenantIsolationMiddleware already sets
        // on every tenant.isolation-wrapped request (the exact attribute
        // AccountController::callerAgentScopeId() re-derives for the
        // identical purpose — see that method's docblock). An Agent
        // reaches this route today via the 'admin' role its primary user
        // is assigned at account creation (AccountController::store()),
        // which already includes manage-subscriptions — no permission
        // seeding change needed for this.
        $isSuperAdmin = (bool) $request->attributes->get('is_super_admin');
        $agentScopeId = $request->attributes->get('agent_scope_id');

        abort_unless(
            $isSuperAdmin || $agentScopeId !== null,
            403,
            'Only the Super Admin or an Agent can view the client billing summary.'
        );

        // Universal Table & Filter Standardization — this table's Search/
        // Status/Date-range filters now run SERVER-SIDE against the full
        // dataset. Previously the frontend filtered only the current
        // page's rows in memory, so a match on a different page silently
        // read as "no results" — a real bug, fixed here rather than
        // carried forward into the standardized toolbar.
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'overdue'])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $account = $this->resolveAccount($request);

        $perPage = min((int) $request->integer('per_page', 15), 100);

        $query = Account::query()->with('currentSubscription')->orderBy('company_name');

        // Agent Client-Switcher: same ownedByAgent() base scope
        // AccountController::index() applies for the identical caller
        // type — an Agent's default "nothing selected" view lists every
        // Sub-Client it owns, not the platform-wide list Super Admin
        // sees. Applied BEFORE the single-account narrowing below since
        // that narrowing must operate on top of this scope, not replace it.
        if ($agentScopeId !== null) {
            $query->ownedByAgent($agentScopeId);
        }

        // Narrow to exactly one client when the header's switcher
        // actually selected one. For Super Admin, $account being
        // non-null always means a real selection (TenantIsolationMiddleware
        // resolves Super Admin's default "nothing selected" to a null
        // account_id, never a resolvable Account). For an Agent, though,
        // $account defaults to the AGENT'S OWN account (Tenant Isolation
        // always gives an Agent a non-null tenant, unlike Super Admin) —
        // that is never one of its own Sub-Clients (agent_id never points
        // to itself), so it must be excluded here or the ownedByAgent()
        // list above would be wrongly collapsed to zero rows on every
        // default page load.
        $isRealSelection = $account && ($isSuperAdmin || (int) $account->agent_id === (int) $agentScopeId);
        if ($isRealSelection) {
            $query->whereKey($account->id);
        }

        if (! empty($filters['search'])) {
            $query->where('company_name', 'like', '%'.$filters['search'].'%');
        }

        // 'active'/'overdue' mirrors this method's own payment_status
        // bucket below exactly: 'active' = a currentSubscription with
        // status 'active'; 'overdue' = everything else, including no
        // subscription at all. Filtered against Subscription.status as
        // currently stored — the same lazy-refresh caveat every other
        // status filter in this codebase already carries (see
        // AccountController::index()): rows are refreshed for DISPLAY
        // below, but a subscription that lapsed since its last read and
        // hasn't been refreshed yet could still filter as 'active' here.
        if (! empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->whereHas('currentSubscription', fn ($q) => $q->where('status', 'active'));
            } else {
                $query->where(function ($q) {
                    $q->whereDoesntHave('currentSubscription')
                        ->orWhereHas('currentSubscription', fn ($qq) => $qq->where('status', '!=', 'active'));
                });
            }
        }

        if (! empty($filters['from'])) {
            $from = Carbon::parse($filters['from'])->startOfDay();
            $query->whereHas('currentSubscription', fn ($q) => $q->where('expires_at', '>=', $from));
        }

        if (! empty($filters['to'])) {
            $to = Carbon::parse($filters['to'])->endOfDay();
            $query->whereHas('currentSubscription', fn ($q) => $q->where('expires_at', '<=', $to));
        }

        $accounts = $query->paginate($perPage);
        $accounts->getCollection()->each(fn (Account $a) => $a->currentSubscription?->refreshStatus());

        $accounts->getCollection()->transform(function (Account $a) {
            $sub = $a->currentSubscription;
            $isPerMessage = $sub?->billing_model === 'per_message';

            // Wallet breakdown — rupee-denominated view of the same
            // used_messages/total_allocated_messages quota already shown
            // above, meaningful only for 'per_message' (the only billing
            // model with a real rate_per_message — flat_quota/unlimited
            // are paid as a flat price_paid regardless of usage, so
            // "amount used" has no per-message meaning for them and stays
            // null rather than a misleading 0 or price_paid). Balance is
            // computed from the plan's own quota × rate (not price_paid
            // minus spent) since a per_message plan's price_paid is only
            // ever an initial top-up amount, not a running wallet ledger
            // this schema tracks separately.
            $rate = $isPerMessage ? $sub->rate_per_message : null;
            $amountUsed = $rate !== null ? round((float) $rate * $sub->used_messages, 2) : null;
            $amountRemaining = ($rate !== null && $sub->total_allocated_messages !== null)
                ? round((float) $rate * max($sub->total_allocated_messages - $sub->used_messages, 0), 2)
                : null;

            return [
                'account_id' => $a->id,
                'company_name' => $a->company_name,
                'plan_label' => $sub
                    ? ucwords(str_replace('_', ' ', $sub->billing_model)).' ('.strtoupper($sub->engine_type).')'
                    : null,
                'billing_model' => $sub?->billing_model,
                'amount' => $sub?->price_paid,
                'used_messages' => $sub?->used_messages,
                'total_allocated_messages' => $sub?->total_allocated_messages,
                'rate_per_message' => $rate,
                'amount_used' => $amountUsed,
                'amount_remaining' => $amountRemaining,
                'payment_status' => ! $sub ? 'overdue' : ($sub->status === 'active' ? 'active' : 'overdue'),
                'renewal_date' => $sub?->expires_at,
            ];
        });

        return response()->json(array_merge($accounts->toArray(), [
            'scope' => $isRealSelection ? 'account' : 'global',
        ]));
    }

    private function account(Request $request): Account
    {
        $accountId = $request->user()->account_id;
        abort_if(! $accountId, 422, 'Super Admin has no tenant account to bill.');

        return Account::findOrFail($accountId);
    }
}
