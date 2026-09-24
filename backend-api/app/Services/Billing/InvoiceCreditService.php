<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AgentCommission;
use App\Models\AgentCommissionRule;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Access\ProviderCapabilityService;
use App\Services\Billing\PlanRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place money becomes quota. Called from BOTH
 * PaymentGatewayController::verifyPayment() (fast, client-triggered,
 * optimistic) AND PaymentWebhookController's two handlers (authoritative,
 * async, the one actually meant to be trusted) — a real production race
 * between "the browser calls verify-payment" and "the gateway's webhook
 * arrives" is expected, not an edge case, so this MUST be idempotent
 * under concurrent calls for the same invoice. That's what the row lock
 * and the "already paid -> no-op" check below are for.
 */
class InvoiceCreditService
{
    public function __construct(
        private readonly ProviderCapabilityService $providerCapabilities,
        // Phase 5 Task 10 — the one canonical plan reconciliation.
        private readonly PlanEntitlementReconciliationService $reconciler,
        // Phase 5 Task 11 — the database plan, replacing PlanCatalog.
        private readonly PlanRepository $plans,
    ) {
    }

    /**
     * @param array<string, mixed>|null $rawResponse
     * @return bool true if this call actually credited the account (first
     *         to win the race); false if it was a no-op (already paid, or
     *         the invoice/plan couldn't be resolved).
     */
    public function markPaidAndCreditQuota(int $invoiceId, string $gatewayPaymentId, ?array $rawResponse = null): bool
    {
        return DB::transaction(function () use ($invoiceId, $gatewayPaymentId, $rawResponse) {
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);

            if (! $invoice) {
                Log::warning("InvoiceCreditService: invoice #{$invoiceId} not found.");

                return false;
            }

            // Idempotency guard: whichever of verify-payment / webhook gets
            // here first wins; the other becomes a safe, silent no-op. This
            // is also what makes Agent Commission generation below
            // idempotent under the same duplicate-callback race — a second
            // call for an already-paid invoice never reaches that code at
            // all.
            if ($invoice->isPaid()) {
                return false;
            }

            /*
             * Phase 5 fix P5-4 — the terms THIS ORDER bought, captured on
             * the invoice from the database plan when the order was
             * created (PaymentGatewayController::createOrder()). The live
             * plan is not consulted: an admin changing, deactivating or
             * retiring the plan after checkout cannot change what an
             * already-placed order delivers.
             */
            $terms = $invoice->purchasedPlanTerms();

            if ($terms === null) {
                /*
                 * No captured terms: an order placed before P5-4 (the
                 * migration invents none) — fulfilled exactly as before,
                 * from the plan row.
                 *
                 * Phase 5 Task 11 — the DATABASE plan, not PlanCatalog.
                 * findForFulfilment(), not findPurchasable(): a customer who
                 * already paid must be credited even if the plan has since
                 * been deactivated. Activation gates NEW purchases
                 * (PaymentGatewayController), never the fulfilment of money
                 * that has already changed hands.
                 */
                $plan = $this->plans->findForFulfilment($invoice->plan_key);
                if (! $plan) {
                    Log::error("InvoiceCreditService: unknown plan_key '{$invoice->plan_key}' on invoice #{$invoice->id}.");

                    return false;
                }

                Log::info("InvoiceCreditService: invoice #{$invoice->id} predates captured plan terms; fulfilling from the current plan row.");

                $terms = [
                    'engine_type' => $plan->engine_type,
                    'billing_model' => $plan->billing_model,
                    'rate_per_message' => $plan->rate_per_message,
                    'total_allocated_messages' => $plan->total_allocated_messages,
                    'duration_days' => (int) $plan->duration_days,
                ];
            }

            /*
             * Phase 5 fix P5-5 — one fulfilment at a time PER ACCOUNT.
             *
             * The invoice lock above makes one invoice exactly-once, but two
             * DIFFERENT invoices of the same account (a double checkout, a
             * renewal paid while another order is in flight) used to be
             * fulfilled side by side: both read "no subscription yet" and
             * both ran the plan reconciliation, so the loser hit the
             * account_entitlements unique key, rolled back, and a PAID order
             * stayed pending — the customer charged and not credited
             * (reproduced on MariaDB by
             * tests/Probes/payment_fulfillment_concurrency_probe.php). With
             * an existing subscription the two credits only stayed correct
             * because of the subscription row lock.
             *
             * Locking the invoice owner's account row serializes every
             * fulfilment of that account; the second waits, then reads the
             * first one's committed subscription and entitlements. Lock
             * order is always invoice → account → subscription (no other
             * code path takes these the other way round). The owner is the
             * invoice's account_id — never anything from the request.
             *
             * Taken BEFORE the invoice row is written: that write makes
             * MariaDB/InnoDB take a shared lock on the parent account row
             * (foreign-key check), and two fulfilments each holding that
             * shared lock and then asking for the exclusive one deadlock
             * (observed with the probe) — one paid order rolled back.
             */
            $account = Account::query()->lockForUpdate()->find($invoice->account_id);

            $invoice->forceFill([
                'status' => 'paid',
                'gateway_payment_id' => $gatewayPaymentId,
                'paid_at' => now(),
                'gateway_raw_response' => $rawResponse,
            ])->save();

            if (! $account) {
                Log::error("InvoiceCreditService: account #{$invoice->account_id} not found for invoice #{$invoice->id}.");

                return true; // invoice IS paid — the money was real — just can't credit a missing account.
            }

            // The current subscription (Account::currentSubscription: latest
            // starts_at), read with a row lock so it is the committed state
            // of whatever fulfilment ran before this one.
            $subscription = Subscription::query()
                ->where('account_id', $account->id)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first()
                ?? new Subscription(['account_id' => $account->id]);

            // "Extend expiration date" (spec, literal): a renewal on a still-
            // active plan stacks on top of the current expiry rather than
            // resetting the clock to "now + duration"; a lapsed/absent
            // subscription starts a fresh period from now.
            $baseExpiry = ($subscription->expires_at && $subscription->expires_at->isFuture())
                ? $subscription->expires_at
                : now();

            // "Credit allocated message quotas" (spec, literal): additive,
            // not a reset — a tenant upgrading mid-period keeps whatever
            // unused balance they already had. [Inference — disclosed
            // architectural call in the Module 8 report; a "replace on
            // upgrade" model is equally defensible and would be a one-line
            // change here if that's what's actually wanted.]
            $subscription->engine_type = $terms['engine_type'];
            $subscription->billing_model = $terms['billing_model'];
            $subscription->rate_per_message = $terms['rate_per_message'];
            $subscription->total_allocated_messages = $terms['billing_model'] === 'unlimited'
                ? null
                : ($subscription->total_allocated_messages ?? 0) + (int) $terms['total_allocated_messages'];
            $subscription->price_paid = (float) ($subscription->price_paid ?? 0) + (float) $invoice->total_amount;
            $subscription->payment_mode = $invoice->payment_gateway;
            $subscription->starts_at = $subscription->starts_at ?? now();
            $subscription->expires_at = $baseExpiry->copy()->addDays($terms['duration_days']);
            $subscription->status = 'active';
            $subscription->save();

            // Phase 1 Foundation — Plan -> Entitlement auto-grant, inside
            // this SAME transaction: a payment can never be marked
            // successful while entitlement granting silently fails (any
            // exception here rolls back the invoice/subscription changes
            // above too, same as every other line in this method).
            $this->reconcilePlanEntitlements($account);

            // Agent Commission Foundation — same transaction boundary and
            // idempotency guard as the entitlement grant above: generated
            // only once per successfully-paid Invoice, and only when this
            // customer actually belongs to an Agent.
            $this->grantAgentCommission($account, $invoice);

            return true;
        });
    }

    /**
     * Looked up by Plan::slug === Invoice::plan_key — Phase1FoundationSeeder's
     * Phase 5 Task 11 — the `plans` table IS the source of truth now,
     * for pricing/quota/engine as well as for the capability bundle, so
     * the old "PlanCatalog is authoritative, plans mirrors it" framing
     * in this docblock no longer holds. Checkout, fulfilment and
     * reconciliation all read the same database rows.
     *
     * firstOrCreate, not updateOrCreate: an entitlement this account
     * already holds for ANY reason (manual Super-Admin grant, Agent
     * delegation, an earlier purchase) is left completely untouched —
     * its original `source`/`granted_by_account_id` survives a later
     * plan purchase that happens to also cover the same capability.
     * Never duplicates a row (unique(account_id, capability_id) plus
     * firstOrCreate's own existence check), and is safe to "re-run" —
     * in practice unreachable without also re-entering the invoice-paid
     * branch above, itself already guarded by Invoice::isPaid().
     *
     * Provider-compatibility reuses ProviderCapabilityService — the
     * exact same data-driven check Task 7/12's grantEntitlement() use —
     * never duplicated here: a plan capability the account's NEW
     * engine_type can't actually support (e.g. a bundled Meta-only
     * capability on a QR-engine plan) is silently skipped, not granted.
     */
    private function reconcilePlanEntitlements(Account $account): void
    {
        /*
         * Phase 5 Task 10 — this was grantPlanEntitlements(), which only
         * ever ADDED. That was correct while a payment could only ever
         * be an initial purchase, and wrong the moment a customer can
         * DOWNGRADE: paying for a smaller plan left every capability the
         * larger one had granted silently in place, so the customer kept
         * what they had stopped paying for.
         *
         * Reconciliation is the same grant logic plus the missing
         * direction, and it lives in one service so this path, the
         * artisan commands and the plan-edit job cannot disagree. The
         * provider-compatibility skip, the firstOrCreate idempotency and
         * the never-touch-manual/agent-rows rules are all preserved
         * inside it — see PlanEntitlementReconciliationService.
         *
         * Still inside markPaidAndCreditQuota()'s transaction, so a
         * payment can never be marked successful while entitlement
         * reconciliation silently fails. The account's plan is re-read
         * from its latest PAID invoice, which this method's caller has
         * just written.
         */
        $result = $this->reconciler->reconcile($account->fresh());

        if ($result['reason'] === 'unknown_plan') {
            // Unchanged tolerance: a plan_key with no Plan row is a
            // disclosed configuration gap, never a reason to fail a real,
            // already-credited payment.
            Log::warning("InvoiceCreditService: no Plan row for slug '{$result['plan']}' -- skipping entitlement reconciliation for account #{$account->id}.");
        }
    }

    /**
     * Agent Commission Foundation — generates the auditable commission
     * ledger row for this Invoice, if (and only if) the paying customer
     * belongs to an Agent. Direct customers (agent_id === null) never
     * reach the AgentCommissionRule lookup at all — no rule to apply, no
     * row created, by construction rather than a status check.
     *
     * rule_type/rule_value are copied onto the AgentCommission row as a
     * point-in-time snapshot of the AgentCommissionRule that existed at
     * this exact moment — the row's own `amount` is computed from that
     * snapshot, never re-derived from the (possibly since-changed) rule.
     * This is what satisfies "historical commission must not change when
     * the Agent's commission rule changes later" without needing to
     * version the rule table itself.
     *
     * firstOrCreate keyed on invoice_id mirrors grantPlanEntitlements()'s
     * own idempotency idiom above, on top of (not instead of) the
     * agent_commissions.invoice_id UNIQUE constraint and the
     * Invoice::isPaid() guard already covering this whole method — three
     * independent layers, matching how duplicate-callback safety is
     * already layered elsewhere in this class.
     */
    private function grantAgentCommission(Account $account, Invoice $invoice): void
    {
        if ($account->agent_id === null) {
            return;
        }

        $rule = AgentCommissionRule::where('agent_account_id', $account->agent_id)->first();

        if (! $rule) {
            // No commission rule configured for this Agent yet — a
            // disclosed, non-fatal configuration gap, same treatment as
            // grantPlanEntitlements()'s "no Plan row" branch above: never
            // a reason to fail an already-credited payment.
            Log::warning("InvoiceCreditService: no AgentCommissionRule for agent #{$account->agent_id} -- skipping commission for invoice #{$invoice->id}.");

            return;
        }

        $baseAmount = (float) $invoice->total_amount;
        $amount = $rule->type === 'percentage'
            ? round($baseAmount * ((float) $rule->value) / 100, 2)
            : (float) $rule->value;

        AgentCommission::firstOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'agent_account_id' => $account->agent_id,
                'customer_account_id' => $account->id,
                'agent_commission_rule_id' => $rule->id,
                'rule_type' => $rule->type,
                'rule_value' => $rule->value,
                'base_amount' => $baseAmount,
                'amount' => $amount,
                'status' => 'confirmed',
            ],
        );
    }

    /**
     * Agent Commission Foundation — refund/reversal lifecycle. Called only
     * from PaymentWebhookController when a gateway webhook reports a
     * settled refund/reversal event for a previously-paid Invoice; never
     * part of the payment-success path above.
     *
     * "If the invoice has no Agent commission, do nothing" (spec, literal):
     * a direct customer, or an Agent customer whose purchase predates any
     * AgentCommissionRule, simply has no agent_commissions row for this
     * invoice_id — this is then a silent no-op, exactly like
     * grantAgentCommission()'s own "no rule configured" branch above.
     *
     * Idempotency mirrors markPaidAndCreditQuota()'s own pattern:
     * lockForUpdate() the one row for this invoice_id inside a transaction,
     * then check its CURRENT status rather than trusting the caller not to
     * have sent this twice — a commission already 'reversed' is left
     * completely untouched, so a duplicate/replayed refund webhook can
     * never reverse it twice. Only the lifecycle columns
     * (status/reversed_at/reversal_reference) are ever written here — the
     * original snapshot columns (rule_type, rule_value, base_amount,
     * amount, agent_commission_rule_id) are never touched, which is what
     * "historical commission must not change" means at the row level.
     *
     * @return bool true if this call actually performed the reversal
     *         (first to win the race); false if it was a no-op (no
     *         commission for this invoice, or already reversed).
     */
    public function reverseAgentCommission(int $invoiceId, ?string $reference = null): bool
    {
        return DB::transaction(function () use ($invoiceId, $reference) {
            $commission = AgentCommission::query()->lockForUpdate()->where('invoice_id', $invoiceId)->first();

            if (! $commission) {
                return false;
            }

            if ($commission->status === 'reversed') {
                return false;
            }

            $commission->forceFill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversal_reference' => $reference,
            ])->save();

            return true;
        });
    }
}
