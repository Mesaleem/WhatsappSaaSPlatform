<?php

namespace App\Services\Access;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Invoice;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 Task 10 — THE canonical plan-entitlement reconciliation.
 *
 * One service answers one question: given what this account's current
 * plan bundles right now, which of its `source='plan'` entitlements
 * should exist, and which should not?
 *
 *     current plan (latest PAID invoice's plan_key)
 *            ↓
 *     plan_entitlements
 *            ↓
 *     provider compatibility (ProviderCapabilityService)
 *            ↓
 *     account_entitlements  — source='plan' rows ONLY
 *
 * Everything that changes a plan relationship routes through here:
 * InvoiceCreditService on payment, ReconcilePlanAccountsJob after an
 * administrator edits a plan's bundle, and the
 * `entitlements:reconcile-plan` command. There is deliberately no
 * second implementation — a downgrade that forgot one of the rules
 * below is an authorization bug, not a cosmetic one.
 *
 * THE FOUR RULES, in the order they matter:
 *
 *  1. SOURCE='PLAN' ONLY. A manual_grant or agent_delegated row is
 *     never read as "the plan gave this", never revoked by a downgrade,
 *     never rewritten to source='plan', and never has its
 *     granted_by_account_id touched. A capability the plan dropped but
 *     an Agent delegated stays delegated and stays available.
 *
 *  2. A MANUAL REVOCATION IS FINAL. revoked_reason='manual' means a
 *     person took it away. No automated path here restores it, even
 *     when the plan plainly includes it. Only
 *     AccountController::grantEntitlement() clears that.
 *
 *  3. A PLAN REVOCATION IS REVERSIBLE. revoked_reason='plan_downgrade'
 *     is this service's own bookkeeping, so the same service may undo
 *     it when the plan includes the capability again. That is what
 *     makes Business -> Growth -> Business restore correctly instead of
 *     stranding the customer.
 *
 *  4. PROVIDER COMPATIBILITY IS INDEPENDENT. A plan entitlement is not
 *     provider support. The same ProviderCapabilityService::supports()
 *     call InvoiceCreditService already made still gates every grant,
 *     so a QR account never receives a Meta-only capability just
 *     because its plan bundles one. This service does not change, and
 *     must not change, any provider rule.
 *
 * NO PLAN NAMES. The bundle is read from plan_entitlements. This class
 * contains no plan slug at all.
 */
class PlanEntitlementReconciliationService
{
    public function __construct(private readonly ProviderCapabilityService $providerCapabilities)
    {
    }

    /**
     * Reconcile one account against its current plan.
     *
     * Transactional: an account's grants and revocations commit together
     * or not at all, so a half-reconciled account is not a reachable
     * state. No external call happens inside the transaction.
     *
     * Idempotent: a second run computes the same target set and finds
     * nothing to change.
     *
     * @return array{granted: array<int,string>, revoked: array<int,string>, restored: array<int,string>, skipped_provider: array<int,string>, skipped_manual_revoked: array<int,string>, plan: ?string, reason: ?string}
     */
    public function reconcile(Account $account, ?int $actorUserId = null, bool $dryRun = false): array
    {
        $result = [
            'granted' => [],
            'revoked' => [],
            'restored' => [],
            'skipped_provider' => [],
            'skipped_manual_revoked' => [],
            'plan' => null,
            'reason' => null,
        ];

        if (! $account->isAdministrativelyActive()) {
            // A suspended account keeps exactly what it holds. Widening
            // or narrowing it is not this service's call.
            $result['reason'] = 'account_inactive';

            return $result;
        }

        $planSlug = $this->currentPlanSlug($account);

        if ($planSlug === null) {
            $result['reason'] = 'no_paid_invoice';

            return $result;
        }

        $result['plan'] = $planSlug;

        $plan = Plan::where('slug', $planSlug)->with('capabilities')->first();

        if (! $plan) {
            // Mirrors InvoiceCreditService's own tolerance for a plan_key
            // with no Plan row: a disclosed configuration gap, never a
            // reason to start revoking a paying customer's capabilities.
            $result['reason'] = 'unknown_plan';

            return $result;
        }

        $provider = $account->currentSubscription?->engine_type ?? 'none';

        /** @var array<int, int> capability ids the plan bundles */
        $bundled = $plan->capabilities->pluck('id')->all();

        /** @var array<string, int> slug => id, for messages */
        $slugById = $plan->capabilities->pluck('slug', 'id')->all();

        $existing = AccountEntitlement::where('account_id', $account->id)
            ->with('capability')
            ->get()
            ->keyBy('capability_id');

        $toGrant = [];
        $toRestore = [];
        $toRevoke = [];

        // ---- Direction 1: what the plan bundles but the account lacks.
        foreach ($bundled as $capabilityId) {
            $slug = $slugById[$capabilityId] ?? (string) $capabilityId;
            $row = $existing->get($capabilityId);

            if ($row && ! $row->isRevoked()) {
                continue; // Already held, whatever its source. Rule 1.
            }

            if ($row && $row->isManuallyRevoked()) {
                $result['skipped_manual_revoked'][] = $slug; // Rule 2.

                continue;
            }

            // Provider compatibility gates BOTH a fresh grant and a
            // restore — Rule 4. supports() is the same call
            // InvoiceCreditService makes, so behaviour is identical.
            if (! $this->providerCapabilities->supports($provider, $slug)) {
                $result['skipped_provider'][] = $slug;

                continue;
            }

            if ($row) {
                // Plan-revoked and the plan includes it again. Rule 3.
                $toRestore[] = $row->id;
                $result['restored'][] = $slug;

                continue;
            }

            $toGrant[] = $capabilityId;
            $result['granted'][] = $slug;
        }

        /*
         * ---- Direction 2: what the account holds from the plan but
         * should no longer hold. source='plan' only — Rule 1.
         *
         * TWO reasons a held plan entitlement must go, and the second
         * one was a real bug caught by the downgrade test:
         *
         *   a) the plan no longer bundles it (an ordinary downgrade);
         *   b) the plan still bundles it, but the account's PROVIDER
         *      changed and can no longer support it.
         *
         * (b) happens on exactly the move this task is about: Business
         * is a Meta plan and Growth is a QR one, so downgrading also
         * changes the engine. Without this branch a Meta-only capability
         * (journey_automation) stayed held on a QR account — granted
         * under the old engine, never re-checked under the new one.
         * Provider compatibility is an independent gate (Rule 4), and a
         * gate that is only applied at grant time is not a gate.
         *
         * Both cases record 'plan_downgrade', because both are automatic
         * and both are reversible: moving back to Meta restores it,
         * exactly as re-upgrading restores a dropped capability.
         */
        foreach ($existing as $capabilityId => $row) {
            if ($row->source !== AccountEntitlement::SOURCE_PLAN || $row->isRevoked()) {
                continue;
            }

            $slug = $row->capability?->slug ?? (string) $capabilityId;

            $stillBundled = in_array($capabilityId, $bundled, true);
            $providerSupports = $this->providerCapabilities->supports($provider, $slug);

            if ($stillBundled && $providerSupports) {
                continue;
            }

            $toRevoke[] = $row->id;
            $result['revoked'][] = $slug;
        }

        sort($result['granted']);
        sort($result['revoked']);
        sort($result['restored']);
        sort($result['skipped_provider']);
        sort($result['skipped_manual_revoked']);

        if ($dryRun || ($toGrant === [] && $toRestore === [] && $toRevoke === [])) {
            return $result;
        }

        DB::transaction(function () use ($account, $toGrant, $toRestore, $toRevoke, $actorUserId) {
            foreach ($toGrant as $capabilityId) {
                // firstOrCreate on the unique (account_id, capability_id)
                // index — the same idiom every other grant path uses.
                AccountEntitlement::firstOrCreate(
                    ['account_id' => $account->id, 'capability_id' => $capabilityId],
                    ['source' => AccountEntitlement::SOURCE_PLAN, 'granted_by_account_id' => null],
                );
            }

            /*
             * Restores and revokes go through the MODEL, one row at a
             * time, not a mass query-builder update. That is deliberate:
             * LogsActivity hooks Eloquent's saved/updated events, so a
             * mass update would change authorization silently and leave
             * no audit row — and these are authorization-impacting
             * operations. The row counts here are bounded by the number
             * of capabilities (12), never by tenant count.
             */
            foreach (AccountEntitlement::whereIn('id', $toRestore)->get() as $row) {
                $row->forceFill([
                    'revoked_at' => null,
                    'revoked_by_user_id' => null,
                    'revoked_reason' => null,
                ])->save();
            }

            foreach (AccountEntitlement::whereIn('id', $toRevoke)->get() as $row) {
                $row->forceFill([
                    'revoked_at' => now(),
                    // NULL for an automatic reconciliation: this codebase
                    // has no system-user row, and inventing a fake human
                    // would be worse than an honest NULL. The reason
                    // column below is what carries the meaning.
                    'revoked_by_user_id' => $actorUserId,
                    'revoked_reason' => AccountEntitlement::REVOKED_PLAN_DOWNGRADE,
                ])->save();
            }
        });

        Cache::forget(Account::cacheKey($account->id));

        return $result;
    }

    /**
     * The account's current plan slug.
     *
     * REUSED, NOT REINVENTED: `subscriptions` carries no plan reference
     * (the Phase 1 plan deferred that column), so the established link
     * is the plan_key on the most recent PAID invoice — the exact value
     * InvoiceCreditService::grantPlanEntitlements() resolves its Plan by
     * and the one AccountController::listEntitlements() displays. Adding
     * a second source of truth here is precisely what Task 10 forbids.
     */
    public function currentPlanSlug(Account $account): ?string
    {
        return Invoice::forAccount($account->id)
            ->where('status', 'paid')
            // id as the tiebreaker: two invoices paid in the same second
            // must still resolve deterministically, and in tests they
            // routinely are.
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->value('plan_key');
    }

    /**
     * Accounts that may be affected by a change to $planSlug's bundle:
     * every account that has ever paid for it.
     *
     * Deliberately a SUPERSET of "accounts currently on this plan", not
     * an exact set. Computing "the latest paid invoice per account" as
     * one portable query is awkward across SQLite and MySQL, and it is
     * unnecessary: reconcile() re-resolves each account's own current
     * plan through currentPlanSlug() and reconciles against THAT, so an
     * account that has since moved to a different plan is reconciled
     * correctly against its real plan rather than this one. Including it
     * is therefore harmless (reconcile is idempotent), while excluding
     * it by a second, subtly different definition of "current plan"
     * would be the actual risk.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function candidateAccountIdsForPlan(string $planSlug): \Illuminate\Support\Collection
    {
        return Invoice::query()
            ->where('status', 'paid')
            ->where('plan_key', $planSlug)
            ->distinct()
            ->pluck('account_id');
    }
}
