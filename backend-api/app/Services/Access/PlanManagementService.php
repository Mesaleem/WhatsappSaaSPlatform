<?php

namespace App\Services\Access;

use App\Jobs\ReconcilePlanAccountsJob;
use App\Models\Capability;
use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 Task 10 — plan creation and modification, with the capability
 * bundle as a first-class dimension.
 *
 * WHY THIS EXISTS: before Task 10 the platform had no way to create or
 * change a plan at all. `PlanCatalog` is a static PHP constant (engine,
 * price, quota, duration) and the `plans`/`plan_entitlements` tables were
 * only ever written by Phase1FoundationSeeder. Editing what a plan sells
 * meant editing code and re-seeding — and nothing then reconciled the
 * customers already on it.
 *
 * PHASE 5 TASK 11 UPDATE: this service now owns the billing dimensions
 * too (engine_type, billing_model, rate_per_message,
 * total_allocated_messages, is_active), because the `plans` table became
 * the runtime source of truth for checkout. A plan created here with
 * is_active = true is purchasable immediately — which was the entire
 * point of the cutover. PlanCatalog is no longer read by any runtime
 * path; it survives only as a seed/bootstrap constant.
 *
 * THE SEPARATION Task 10 asks for, enforced by construction:
 *   - a call that passes no `capabilities` key cannot alter the bundle;
 *   - a call that passes only `capabilities` cannot alter price,
 *     duration or label.
 * Each dimension is written only when it is explicitly supplied, so a
 * price change can never silently re-bundle capabilities and a bundle
 * change can never silently re-price.
 *
 * RECONCILIATION IS NOT OPTIONAL. Changing a bundle changes what every
 * customer on that plan is entitled to, so modify() dispatches
 * ReconcilePlanAccountsJob rather than leaving the fleet inconsistent
 * until someone remembers to run a command.
 *
 * PHASE 4 TASK 2 UPDATE: every capability in the bundle used to be
 * attached with `usage_limit => null` unconditionally — the pivot
 * column existed but nothing ever wrote a real number into it.
 * create()/modify() now accept an optional `$capabilityLimits` map
 * (slug => int|null) so a metered capability (api_installations first,
 * following the same pivot the seeder already uses for whatsapp_send)
 * can carry a concrete, non-negative usage_limit. A capability NOT
 * present in that map still gets `usage_limit => null` on a bundle
 * write, so `external_api` and every other unmetered capability keeps
 * its exact prior behavior.
 *
 * modify() also supports a LIMIT-ONLY update: calling it with
 * `$capabilities === null` (bundle untouched) but a non-empty
 * `$capabilityLimits` updates the usage_limit pivot value of whichever
 * named capabilities are ALREADY in the plan's bundle, without
 * resending (and risking silently altering) the whole membership list.
 */
class PlanManagementService
{
    /**
     * Create a plan, or return the existing one unchanged.
     *
     * Transactional and duplicate-safe: `plans.slug` is unique and
     * `plan_entitlements` is unique on (plan_id, capability_id), so a
     * repeated call cannot produce a second plan or a duplicate bundle
     * row. Idempotent in the useful sense — calling it twice with the
     * same arguments leaves the same state.
     *
     * @param array<int, string> $capabilities capability SLUGS
     * @param array<string, int|null> $capabilityLimits slug => usage_limit; a slug not in the bundle is ignored; a slug in the bundle but absent here gets usage_limit = null (unchanged default)
     */
    public function create(string $slug, array $attributes, array $capabilities = [], array $capabilityLimits = []): Plan
    {
        $this->assertNoNegativeLimits($capabilityLimits);
        $idsBySlug = $this->resolveCapabilityIdsBySlug($capabilities);

        return DB::transaction(function () use ($slug, $attributes, $idsBySlug, $capabilityLimits) {
            $plan = Plan::firstOrCreate(['slug' => $slug], [
                'label' => $attributes['label'] ?? $slug,
                'price' => $attributes['price'] ?? 0,
                'duration_days' => $attributes['duration_days'] ?? 30,
                'description' => $attributes['description'] ?? null,
                // Phase 5 Task 11 — the billing dimensions that make a
                // plan actually PURCHASABLE. Without them a plan created
                // here could be reconciled but never bought.
                'engine_type' => $attributes['engine_type'] ?? 'qr',
                'billing_model' => $attributes['billing_model'] ?? 'flat_quota',
                'rate_per_message' => $attributes['rate_per_message'] ?? null,
                'total_allocated_messages' => $attributes['total_allocated_messages'] ?? null,
                // Phase 8 Task 2 — explicit 0 unless supplied.
                'included_credits' => (int) ($attributes['included_credits'] ?? 0),
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            if ($idsBySlug->isNotEmpty()) {
                // syncWithoutDetaching, never sync(): additive, and the
                // unique index is the real duplicate guard.
                $plan->capabilities()->syncWithoutDetaching(
                    $this->pivotPayload($idsBySlug, $capabilityLimits)
                );
            }

            return $plan->fresh('capabilities');
        });
    }

    /**
     * Modify a plan. Only the dimensions actually present in $attributes
     * are written; `capabilities` is handled separately and only when
     * the key is present (an explicit empty array clears the bundle;
     * an absent key leaves it untouched — those are different requests).
     *
     * @param array<string, mixed> $attributes may contain label/price/duration_days/description
     * @param array<int, string>|null $capabilities NULL = do not touch bundle MEMBERSHIP
     * @param array<string, int|null> $capabilityLimits slug => usage_limit. When $capabilities is given, applied as part of the bundle rewrite (same as create()). When $capabilities is null, applied as a LIMIT-ONLY update to whichever of these slugs are already bundled; a slug here that is not currently bundled is silently skipped (the caller — PlanManagementController — is expected to have already rejected that case).
     * @return array{plan: Plan, bundle_changed: bool, added: array<int,string>, removed: array<int,string>}
     */
    public function modify(Plan $plan, array $attributes = [], ?array $capabilities = null, ?int $actorUserId = null, array $capabilityLimits = []): array
    {
        $this->assertNoNegativeLimits($capabilityLimits);
        $idsBySlug = $capabilities === null ? null : $this->resolveCapabilityIdsBySlug($capabilities);

        $before = $plan->capabilities()->pluck('slug')->sort()->values()->all();

        $result = DB::transaction(function () use ($plan, $attributes, $idsBySlug, $capabilityLimits) {
            // Core plan data — pricing dimension. Written only for keys
            // that were actually supplied.
            $core = array_intersect_key($attributes, array_flip([
                'label', 'price', 'duration_days', 'description',
                // Phase 5 Task 11 — still the PRICING dimension, still
                // written only for keys actually supplied, so a
                // capability edit cannot touch any of them.
                'engine_type', 'billing_model', 'rate_per_message', 'total_allocated_messages', 'is_active',
                // Phase 8 Task 2 — applies to orders placed from now on
                // (invoices capture it); never re-allocates past periods.
                'included_credits',
            ]));

            if ($core !== []) {
                // ->update() on a model instance fires Eloquent events, so
                // LogsActivity records the plan change. That is required:
                // a plan edit is authorization-impacting.
                $plan->update($core);
            }

            if ($idsBySlug !== null) {
                // Capability dimension, membership change — only when
                // explicitly supplied.
                $plan->capabilities()->sync(
                    $this->pivotPayload($idsBySlug, $capabilityLimits)
                );
            } elseif ($capabilityLimits !== []) {
                // Phase 4 Task 2 — limit-only update: membership is left
                // untouched, but any of these slugs that are ALREADY
                // bundled get their usage_limit pivot value updated in
                // place, without resending the whole membership list.
                $currentIdsBySlug = $plan->capabilities()->pluck('capabilities.id', 'capabilities.slug');
                foreach ($capabilityLimits as $slug => $limit) {
                    if ($currentIdsBySlug->has($slug)) {
                        $plan->capabilities()->updateExistingPivot($currentIdsBySlug[$slug], ['usage_limit' => $limit]);
                    }
                }
            }

            return $plan->fresh('capabilities');
        });

        $after = $result->capabilities->pluck('slug')->sort()->values()->all();

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        $bundleChanged = $added !== [] || $removed !== [];

        if ($bundleChanged) {
            /*
             * Dispatched, not run inline: a popular plan can have any
             * number of customers, and an unbounded tenant loop inside
             * an HTTP request is exactly what Task 10 forbids. The job
             * chunks and reconciles each account in its own transaction.
             */
            ReconcilePlanAccountsJob::dispatch($result->slug, $actorUserId);
        }

        return [
            'plan' => $result,
            'bundle_changed' => $bundleChanged,
            'added' => $added,
            'removed' => $removed,
        ];
    }

    /**
     * Builds the sync()/syncWithoutDetaching() pivot payload: every
     * capability id in the bundle maps to ['usage_limit' => ...], where
     * the value is the caller-supplied limit for that slug if one was
     * given, and null otherwise — exactly the previous unconditional-null
     * behavior for any slug not present in $capabilityLimits.
     *
     * @param Collection<string, int> $idsBySlug slug => capability id
     * @param array<string, int|null> $capabilityLimits slug => usage_limit
     * @return array<int, array{usage_limit: int|null}>
     */
    private function pivotPayload(Collection $idsBySlug, array $capabilityLimits): array
    {
        return $idsBySlug->mapWithKeys(
            fn (int $id, string $slug) => [$id => ['usage_limit' => $capabilityLimits[$slug] ?? null]]
        )->all();
    }

    /**
     * Phase 4 Task 2, requirement 6 — a negative usage_limit must be
     * rejected when creating/updating a plan. The HTTP layer
     * (PlanManagementController) already validates this with
     * `min:0` on each `capability_limits.*` entry; this is defense in
     * depth for any other caller of this service (artisan command,
     * future job, a test) that does not go through that validation.
     *
     * @param array<string, int|null> $capabilityLimits
     */
    private function assertNoNegativeLimits(array $capabilityLimits): void
    {
        $negative = array_keys(array_filter($capabilityLimits, fn ($limit) => $limit !== null && $limit < 0));

        if ($negative !== []) {
            throw ValidationException::withMessages([
                'capability_limits' => ['usage_limit cannot be negative for: '.implode(', ', $negative).'.'],
            ]);
        }
    }

    /**
     * Slugs -> ids, rejecting anything unseeded. Deduplicates, so a
     * payload listing the same capability twice cannot produce two rows
     * (the unique index would stop it anyway; this makes the intent
     * explicit and the error message useful).
     *
     * @param array<int, string> $slugs
     * @return Collection<string, int> slug => id
     */
    private function resolveCapabilityIdsBySlug(array $slugs): Collection
    {
        $slugs = array_values(array_unique(array_filter($slugs)));

        if ($slugs === []) {
            return collect();
        }

        $found = Capability::whereIn('slug', $slugs)->pluck('id', 'slug');

        $unknown = array_diff($slugs, $found->keys()->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'capabilities' => ['Unknown capability: '.implode(', ', $unknown).'.'],
            ]);
        }

        return $found;
    }
}
