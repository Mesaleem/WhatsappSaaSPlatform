<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\PlanCatalog;
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
            // here first wins; the other becomes a safe, silent no-op.
            if ($invoice->isPaid()) {
                return false;
            }

            $plan = PlanCatalog::find($invoice->plan_key);
            if (! $plan) {
                Log::error("InvoiceCreditService: unknown plan_key '{$invoice->plan_key}' on invoice #{$invoice->id}.");

                return false;
            }

            $invoice->forceFill([
                'status' => 'paid',
                'gateway_payment_id' => $gatewayPaymentId,
                'paid_at' => now(),
                'gateway_raw_response' => $rawResponse,
            ])->save();

            $account = Account::find($invoice->account_id);
            if (! $account) {
                Log::error("InvoiceCreditService: account #{$invoice->account_id} not found for invoice #{$invoice->id}.");

                return true; // invoice IS paid — the money was real — just can't credit a missing account.
            }

            $existingSubscription = $account->currentSubscription;
            $subscription = $existingSubscription
                ? Subscription::query()->lockForUpdate()->find($existingSubscription->id)
                : new Subscription(['account_id' => $account->id]);

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
            $subscription->engine_type = $plan['engine_type'];
            $subscription->billing_model = $plan['billing_model'];
            $subscription->rate_per_message = $plan['rate_per_message'];
            $subscription->total_allocated_messages = $plan['billing_model'] === 'unlimited'
                ? null
                : ($subscription->total_allocated_messages ?? 0) + $plan['total_allocated_messages'];
            $subscription->price_paid = (float) ($subscription->price_paid ?? 0) + (float) $invoice->total_amount;
            $subscription->payment_mode = $invoice->payment_gateway;
            $subscription->starts_at = $subscription->starts_at ?? now();
            $subscription->expires_at = $baseExpiry->copy()->addDays($plan['duration_days']);
            $subscription->status = 'active';
            $subscription->save();

            return true;
        });
    }
}
