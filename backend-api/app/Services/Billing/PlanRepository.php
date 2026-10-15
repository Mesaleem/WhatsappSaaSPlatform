<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Phase 5 Task 11 — THE lookup for purchasable plans.
 *
 * Before this, checkout read App\Support\PlanCatalog (a static PHP
 * constant) while plan management wrote the `plans` table, so a plan
 * created through Task 10's API could be reconciled but never bought.
 * This repository closes that: the DATABASE is the runtime source of
 * truth, and every checkout path goes through here rather than reaching
 * for `Plan::where(...)` of its own.
 *
 * WHAT "PURCHASABLE" MEANS, in one place: a plan row that is
 * `is_active = true`. That flag is new in Task 11 (the table had no
 * activation concept at all — no is_active, no status, no soft
 * deletes), and it defaults to true so every pre-existing plan stays
 * purchasable exactly as before.
 *
 * NO CACHING, DELIBERATELY. Plans were never cached — only the gateway
 * list is (PaymentGatewaySetting::enabledGatewaysCached()) — and Task 11
 * says not to introduce caching where none exists. That is also the
 * safer default here: an administrator who edits a price expects the
 * next checkout to use it, and a stale cached price is a billing bug,
 * not a performance trade-off.
 *
 * NOT AN ENTITLEMENT PATH. This resolves what a plan COSTS and GRANTS
 * at purchase time. Turning a payment into account entitlements stays
 * with InvoiceCreditService -> PlanEntitlementReconciliationService, as
 * Task 10 established; nothing here writes account_entitlements.
 */
class PlanRepository
{
    /**
     * Every plan a customer may buy right now, cheapest first — the
     * shape the customer-facing listing renders.
     *
     * @return Collection<int, Plan>
     */
    public function purchasable(?Account $account = null): Collection
    {
        // Plan restructure (2026-10-15): the public line-up is the active, public plans; a
        // client also sees the active custom plan(s) built for it.
        return Plan::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->whereNull('account_id')->where('is_public', true))
                ->when($account !== null, fn ($p) => $p->orWhere('account_id', $account->id)))
            ->orderBy('price')
            ->get();
    }

    /**
     * One purchasable plan by its slug, or null.
     *
     * Returns null for an unknown slug AND for a deactivated one, on
     * purpose: checkout must treat "no such plan" and "that plan is
     * retired" the same way — a 422 — rather than leaking which
     * retired plans exist.
     */
    public function findPurchasable(string $slug, ?Account $account = null): ?Plan
    {
        $plan = Plan::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        // A custom plan can be bought only by the client it was built for. A hidden (not public)
        // plan stays purchasable by slug, e.g. for renewals of a plan sold privately.
        if ($plan !== null && $plan->account_id !== null && $plan->account_id !== $account?->id) {
            return null;
        }

        return $plan;
    }

    /**
     * A plan by slug regardless of activation — for paths that must
     * still resolve a plan a customer ALREADY bought, such as crediting
     * an invoice for a plan that has since been retired. Never used to
     * authorize a new purchase.
     */
    public function findForFulfilment(string $slug): ?Plan
    {
        return Plan::query()->where('slug', $slug)->first();
    }

    /**
     * The customer-facing representation of a plan.
     *
     * Field-for-field the same contract the pricing page already
     * consumes (key/label/description/engine_type/billing_model/
     * total_allocated_messages/duration_days/price/tax_amount/
     * total_amount), so the cutover is invisible to the frontend.
     *
     * Only these fields: no id, no timestamps, no capability bundle, no
     * is_active, no audit or admin metadata. A customer sees what a plan
     * costs and gives them, nothing about how it is administered.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(Plan $plan, float $taxRate): array
    {
        $price = (float) $plan->price;
        $tax = round($price * $taxRate, 2);

        return [
            'key' => $plan->slug,
            'label' => $plan->label,
            'description' => $plan->description,
            'engine_type' => $plan->engine_type,
            'billing_model' => $plan->billing_model,
            'total_allocated_messages' => $plan->total_allocated_messages,
            'duration_days' => $plan->duration_days,
            'price' => number_format($price, 2, '.', ''),
            'tax_amount' => number_format($tax, 2, '.', ''),
            'total_amount' => number_format($price + $tax, 2, '.', ''),
            // Plan restructure (2026-10-15) — users / templates / flows / broadcasts / marketing caps (null = unlimited).
            'limits' => $plan->limits(),
            'is_custom' => $plan->isCustom(),
            // QA fixes (2026-10-14): what the plan includes besides messages, for the plan cards.
            'features' => $plan->capabilities()
                ->whereNotIn('slug', ['whatsapp_send', 'api_installations'])
                ->orderBy('label')
                ->pluck('label')
                ->values()
                ->all(),
        ];
    }
}
