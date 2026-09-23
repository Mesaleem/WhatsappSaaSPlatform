<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Access\ProviderCapabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 5 Task 9 — grants the confirmed plan bundle to tenants who
 * already paid, and who would otherwise wait until their NEXT invoice.
 *
 * WHY A COMMAND. InvoiceCreditService::grantPlanEntitlements() is the
 * only code path that turns a plan into account entitlements, and it
 * runs inside a payment callback. A one-off bulk write over every
 * existing tenant does not belong in a web request, so this is an
 * artisan command with a dry run.
 *
 * IT REUSES, IT DOES NOT REIMPLEMENT:
 *   - the account -> plan link is the plan_key on the account's most
 *     recent PAID invoice, the same value grantPlanEntitlements()
 *     resolves its Plan by (subscriptions carry no plan reference —
 *     see AccountController::listEntitlements());
 *   - the bundle is plan_entitlements, never a plan NAME;
 *   - provider compatibility is ProviderCapabilityService::supports(),
 *     the identical call grantPlanEntitlements() makes, so a capability
 *     the account's engine cannot run is skipped here exactly as it is
 *     skipped there.
 *
 * SAFETY PROPERTIES, each of them tested:
 *   - IDEMPOTENT. firstOrCreate on (account_id, capability_id), which is
 *     a unique index. A second run creates nothing.
 *   - NON-DESTRUCTIVE. It only ever INSERTs. No update, no delete. A
 *     manual_grant or agent_delegated row is never touched, and its
 *     source is never rewritten to 'plan'.
 *   - REVOCATION-AWARE. A row revoked by an administrator (revoked_at
 *     set — Phase 5 Task 9's explicit state) is left revoked. This is
 *     the whole reason that column exists: without it, "revoked" and
 *     "never granted" look identical and this command would silently
 *     reverse an administrator's decision.
 *   - TRANSACTIONAL PER ACCOUNT. One account's grants commit or roll
 *     back together; one account's failure never aborts the run.
 *   - SCOPED. Only accounts with a real paid invoice for a plan that
 *     exists. No invoice, unknown plan slug, or suspended account: skipped.
 */
class BackfillPlanEntitlements extends Command
{
    protected $signature = 'entitlements:backfill-plan
        {--dry-run : Report what would change without writing anything}
        {--account= : Restrict the run to a single account id}';

    protected $description = 'Grant the confirmed plan capability bundle to existing paid tenants (idempotent, non-destructive).';

    public function __construct(private readonly ProviderCapabilityService $providerCapabilities)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyAccount = $this->option('account');

        $stats = [
            'accounts_processed' => 0,
            'accounts_skipped_no_paid_invoice' => 0,
            'accounts_skipped_unknown_plan' => 0,
            'accounts_skipped_inactive' => 0,
            'grants_created' => 0,
            'already_present' => 0,
            'skipped_revoked' => 0,
            'skipped_provider_incompatible' => 0,
            'errors' => 0,
        ];

        if ($dryRun) {
            $this->warn('DRY RUN — no rows will be written.');
        }

        $query = Account::query()->orderBy('id');

        if ($onlyAccount) {
            $query->where('id', (int) $onlyAccount);
        }

        $query->chunkById(100, function ($accounts) use (&$stats, $dryRun) {
            foreach ($accounts as $account) {
                try {
                    $this->backfillAccount($account, $dryRun, $stats);
                } catch (Throwable $e) {
                    $stats['errors']++;
                    // The account id is operational data, not tenant
                    // content — no name, no contact, no credential.
                    $this->error("Account #{$account->id}: {$e->getMessage()}");
                }
            }
        });

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($stats)->map(
            fn ($value, $key) => [$key, $value]
        )->values()->all());

        if ($dryRun) {
            $this->warn('DRY RUN complete — nothing was written.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, int> $stats */
    private function backfillAccount(Account $account, bool $dryRun, array &$stats): void
    {
        // A suspended account keeps whatever it holds; a bulk grant is
        // not the moment to widen it.
        if (! $account->isAdministrativelyActive()) {
            $stats['accounts_skipped_inactive']++;

            return;
        }

        $planSlug = Invoice::forAccount($account->id)
            ->where('status', 'paid')
            ->latest('paid_at')
            ->value('plan_key');

        if (! $planSlug) {
            $stats['accounts_skipped_no_paid_invoice']++;

            return;
        }

        $plan = Plan::where('slug', $planSlug)->with('capabilities')->first();

        if (! $plan) {
            $stats['accounts_skipped_unknown_plan']++;

            return;
        }

        $stats['accounts_processed']++;

        // The engine this account actually runs, from its own
        // subscription — never from a plan name and never from input.
        $providerSlug = $account->currentSubscription?->engine_type ?? 'none';

        $existing = AccountEntitlement::where('account_id', $account->id)
            ->pluck('revoked_at', 'capability_id');

        $toCreate = [];

        foreach ($plan->capabilities as $capability) {
            if ($existing->has($capability->id)) {
                // Present already — held or revoked. Either way this
                // command leaves it exactly as it is.
                if ($existing->get($capability->id) !== null) {
                    $stats['skipped_revoked']++;
                } else {
                    $stats['already_present']++;
                }

                continue;
            }

            if (! $this->providerCapabilities->supports($providerSlug, $capability->slug)) {
                $stats['skipped_provider_incompatible']++;

                continue;
            }

            $toCreate[] = $capability->id;
        }

        if ($toCreate === []) {
            return;
        }

        $stats['grants_created'] += count($toCreate);

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($account, $toCreate) {
            foreach ($toCreate as $capabilityId) {
                // firstOrCreate, matching grantPlanEntitlements()'s own
                // idiom, on top of unique(account_id, capability_id).
                AccountEntitlement::firstOrCreate(
                    ['account_id' => $account->id, 'capability_id' => $capabilityId],
                    ['source' => 'plan', 'granted_by_account_id' => null],
                );
            }
        });

        Cache::forget(Account::cacheKey($account->id));
    }
}
