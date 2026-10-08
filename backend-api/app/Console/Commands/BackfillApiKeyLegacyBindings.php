<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\InstallationAllowanceExceededException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Phase 4 Task 5 — legacy API-key binding backfill.
 *
 * WHY THIS EXISTS. Before the server-binding feature, a key's only
 * authorization factor was the account's single authorized_server_ip.
 * A key is "legacy-IP-dependent" (API-KEY-level, never account-level)
 * iff: account.authorized_server_ip IS NOT NULL AND that specific key
 * has no live ApiKeyBinding (status pending_activation/active —
 * ApiKeyBinding::LIVE_STATUSES, Task 3). This command gives such a key
 * a real binding row of its own, so a later task can stop reading
 * Account.authorized_server_ip at all without breaking every existing
 * integration overnight.
 *
 * DECISION MATRIX, evaluated per account, then per key:
 *   - authorized_server_ip IS NULL                    -> nothing to do, no key considered.
 *   - a specific key already has exactly one live binding -> do nothing for that key (already resolved).
 *   - a specific key has MORE than one live binding   -> data-integrity anomaly; reported, never touched
 *                                                         (the schema's own akb_one_live_binding_per_key unique
 *                                                         index should make this impossible going forward; this
 *                                                         branch exists only to surface it if it is ever found).
 *   - exactly ONE key of the account has no live binding  -> create ONE credential-less binding for that key,
 *                                                         preserving the account's IP, and set its
 *                                                         legacy_binding_grace_expires_at (only if not already set).
 *   - MORE THAN ONE key of the account has no live binding -> ambiguous; report every such key's id, create
 *                                                         NOTHING, set no deadline for any of them.
 *
 * CORRECTION (this task was returned once): an earlier version of this
 * command excluded any ApiKey with api_keys.revoked_at set from the
 * decision entirely. That was a deviation from the finalized
 * architecture, not something it asked for: the legacy-IP-dependent
 * definition is purely structural (account.authorized_server_ip IS NOT
 * NULL and the key has no live binding) and says nothing about the
 * key's own revoked_at. That AuthenticateApiKey separately refuses a
 * revoked key at authentication time does not change what this
 * migration/backfill classifies — Task 5 classifies BINDING STATE, not
 * current authenticatability. Every ApiKey of the account is now
 * evaluated identically, revoked or not; a revoked key with no live
 * binding is a legacy candidate exactly like any other, and if it is
 * the account's only such candidate, it gets a fresh credential-less
 * binding and a deadline the same as any other single-candidate
 * account. (A revoked key that already carries a live binding — e.g.
 * via the pre-existing destroy()-does-not-revoke-the-binding gap noted
 * in earlier tasks — is still case C, untouched, same as any other key
 * with a live binding; revoked_at plays no special role anywhere in
 * this matrix now.)
 *
 * IDEMPOTENT. Rerunning changes nothing for an already-bound key (it is
 * found via the ordinary "already has a live binding" branch, which
 * never touches legacy_binding_grace_expires_at at all) and nothing for
 * an already-reported ambiguous account (still ambiguous, still
 * reported, still nothing created). The deadline-set guard
 * (`=== null` check) is additional defense for the one path that COULD
 * reach it twice — a lost concurrency race — even though the normal
 * single-process rerun path never reaches it a second time at all.
 *
 * CONCURRENCY. `ApiKey::lockForUpdate()` on the specific key row
 * serializes two concurrent runs of THIS command against the same key
 * under MySQL/MariaDB's real row-level locking (InnoDB): the second
 * transaction blocks until the first commits, then re-reads and finds
 * the binding already created, and skips. That lock does NOT protect
 * against a race with a completely different code path (a buyer's own
 * registerServer() call happening at the same moment, which never takes
 * this lock) — the actual, final backstop for that case is the
 * `akb_one_live_binding_per_key` unique index itself: the losing
 * INSERT throws a unique-constraint QueryException, which this command
 * catches via ApiKeyBindingService::isUniqueViolation() and treats as
 * "someone else already resolved it", not an error. This is reasoned
 * against MySQL/MariaDB's actual unique-index + row-lock behavior, not
 * merely observed to pass under SQLite — SQLite serializes the whole
 * connection by default, which proves nothing about production.
 */
class BackfillApiKeyLegacyBindings extends Command
{
    protected $signature = 'api-keys:backfill-legacy-bindings
        {--dry-run : Report what would change without writing anything}
        {--account= : Restrict the run to a single account id}';

    protected $description = 'Phase 4 Task 5 — create a credential-less legacy binding for the one unambiguous legacy-IP-dependent key per account; report (never auto-resolve) accounts with more than one such key.';

    public function __construct(private readonly ApiKeyBindingService $bindings)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyAccount = $this->option('account');
        $graceDays = (int) config('api_binding.legacy_binding_grace_days', 30);

        $stats = [
            'accounts_with_ip_set' => 0,
            'accounts_no_ip_skipped' => 0,
            'keys_already_live_skipped' => 0,
            'bindings_created' => 0,
            'bindings_would_be_created_dry_run' => 0,
            'grace_deadlines_set' => 0,
            'grace_deadlines_already_set_untouched' => 0,
            'races_lost_skipped' => 0,
            'allowance_exceeded_skipped' => 0,
            'multi_key_ambiguous_accounts' => 0,
            'multi_live_binding_anomalies' => 0,
            'errors' => 0,
        ];

        $ambiguousReport = [];
        $anomalyReport = [];

        if ($dryRun) {
            $this->warn('DRY RUN — no rows will be written.');
        }

        $ipQuery = Account::query()->whereNotNull('authorized_server_ip')->orderBy('id');
        $noIpQuery = Account::query()->whereNull('authorized_server_ip');

        if ($onlyAccount) {
            $ipQuery->where('id', (int) $onlyAccount);
            $noIpQuery->where('id', (int) $onlyAccount);
        }

        $stats['accounts_no_ip_skipped'] = $noIpQuery->count();

        $ipQuery->chunkById(100, function ($accounts) use (&$stats, &$ambiguousReport, &$anomalyReport, $dryRun, $graceDays) {
            foreach ($accounts as $account) {
                try {
                    $this->processAccount($account, $dryRun, $graceDays, $stats, $ambiguousReport, $anomalyReport);
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $this->error("Account #{$account->id}: {$e->getMessage()}");
                }
            }
        });

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($stats)->map(
            fn ($value, $key) => [$key, $value]
        )->values()->all());

        if ($ambiguousReport !== []) {
            $this->newLine();
            $this->warn('AMBIGUOUS — multiple legacy-IP-dependent keys, no automatic binding created. Explicit key selection required:');
            foreach ($ambiguousReport as $accountId => $keyIds) {
                $this->line("  account_id={$accountId} api_key_ids=[".implode(',', $keyIds).']');
            }
        }

        if ($anomalyReport !== []) {
            $this->newLine();
            $this->warn('ANOMALY — key has more than one live binding row (should be impossible under the unique constraint). Not modified:');
            foreach ($anomalyReport as $keyId => $bindingIds) {
                $this->line("  api_key_id={$keyId} live_binding_ids=[".implode(',', $bindingIds).']');
            }
        }

        if ($dryRun) {
            $this->warn('DRY RUN complete — nothing was written.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, int> $stats */
    private function processAccount(Account $account, bool $dryRun, int $graceDays, array &$stats, array &$ambiguousReport, array &$anomalyReport): void
    {
        $stats['accounts_with_ip_set']++;

        // Every key of the account is evaluated — revoked_at plays no
        // role in this matrix (see class docblock "CORRECTION" note).
        $keys = ApiKey::forAccount($account->id)->get();

        $legacyDependent = [];

        foreach ($keys as $key) {
            $liveBindings = ApiKeyBinding::query()->where('api_key_id', $key->id)->live()->get();

            if ($liveBindings->count() > 1) {
                $stats['multi_live_binding_anomalies']++;
                $anomalyReport[$key->id] = $liveBindings->pluck('id')->all();

                continue; // case F — data-integrity anomaly, never touched here.
            }

            if ($liveBindings->count() === 1) {
                $stats['keys_already_live_skipped']++;

                continue; // case C — already resolved, do nothing.
            }

            $legacyDependent[] = $key; // no live binding at all -> legacy-IP-dependent.
        }

        if ($legacyDependent === []) {
            return;
        }

        if (count($legacyDependent) > 1) {
            $stats['multi_key_ambiguous_accounts']++;
            $ambiguousReport[$account->id] = array_map(fn (ApiKey $k) => $k->id, $legacyDependent);

            return; // case B — ambiguous, report only, create nothing.
        }

        // Exactly one — case A.
        $key = $legacyDependent[0];

        if ($dryRun) {
            $stats['bindings_would_be_created_dry_run']++;

            return;
        }

        $this->bindOneLegacyKey($key, (string) $account->authorized_server_ip, $graceDays, $stats);
    }

    /**
     * @param array<string, int> $stats
     *
     * Phase 4 Task 6: createLegacyBackfillBinding() now routes through
     * ApiKeyBindingService::createLiveBindingEnforced(), which already
     * locks the Account row then the ApiKey row (same order this method
     * used to apply manually) and already re-checks "does this key have
     * a live binding" under that lock before creating anything — so the
     * old lockForUpdate()/live()->exists() pre-check here would just be
     * redundant, not an additional safety property. What this method
     * still owns, and keeps its own outer transaction for, is the
     * legacy_binding_grace_expires_at write: it must commit atomically
     * with the binding the seam just created, which the outer
     * DB::transaction() below still guarantees (the seam's own
     * transaction nests as a savepoint inside it).
     *
     * This does NOT duplicate a second allowance algorithm — it is the
     * one authoritative seam's own caller, same as provision()/approve()/
     * rebind(). An account already at its installation allowance simply
     * skips this key (allowance_exceeded_skipped) rather than erroring
     * the whole backfill run.
     */
    private function bindOneLegacyKey(ApiKey $key, string $ip, int $graceDays, array &$stats): void
    {
        try {
            DB::transaction(function () use ($key, $ip, $graceDays, &$stats) {
                $this->bindings->createLegacyBackfillBinding($key, $ip);
                $stats['bindings_created']++;

                $locked = ApiKey::query()->find($key->id);

                if ($locked->legacy_binding_grace_expires_at === null) {
                    $locked->forceFill(['legacy_binding_grace_expires_at' => now()->addDays($graceDays)])->save();
                    $stats['grace_deadlines_set']++;
                } else {
                    // Defense-in-depth only — see class docblock: a normal
                    // rerun never reaches this branch at all.
                    $stats['grace_deadlines_already_set_untouched']++;
                }
            });
        } catch (InstallationAllowanceExceededException $e) {
            $stats['allowance_exceeded_skipped']++;
            $this->warn("Skipped key #{$key->id}: account #{$e->accountId} is already at its installation allowance ({$e->currentCount}/{$e->allowance}).");

            return;
        } catch (QueryException $e) {
            if ($this->bindings->isUniqueViolation($e)) {
                $stats['races_lost_skipped']++;

                return;
            }

            throw $e;
        } catch (InvalidArgumentException $e) {
            // createLiveBindingEnforced() throws this (not the allowance
            // exception) when the key already has a live binding by the
            // time its own lock is acquired — the same "someone else
            // resolved it first" race the old manual pre-check used to
            // catch, now surfaced from inside the seam instead.
            $stats['races_lost_skipped']++;
        }
    }
}
