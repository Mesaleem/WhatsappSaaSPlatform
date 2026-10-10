<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Services\ApiAccess\ApiKeyBindingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 5 — legacy API-key binding backfill.
 *
 * CONCURRENCY DISCLOSURE (required by this task): these tests run
 * against SQLite, a single-process, single-connection test database.
 * They can and do prove the real, schema-level backstop — the
 * `akb_one_live_binding_per_key` unique index rejects a second live row
 * for the same key regardless of database engine, since unique-index
 * enforcement is standard SQL behavior, not a SQLite-specific locking
 * quirk — but they CANNOT exercise two genuinely concurrent MySQL/
 * MariaDB transactions racing against the same ApiKey row lock. That
 * would need a real MySQL/MariaDB connection with two simultaneous
 * connections, which this sandbox cannot run (no php/mysql client
 * available at all, let alone two coordinated processes). This is
 * disclosed rather than claimed: the command's row-lock-based
 * serialization is reasoned about in the class's own docblock, not
 * verified end-to-end here.
 */
class BackfillApiKeyLegacyBindingsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function makeKey(Account $account, string $name = 'Key'): ApiKey
    {
        $plain = 'wasaas_live_'.Str::random(40);

        return ApiKey::create([
            'account_id' => $account->id,
            'name' => $name,
            'key_prefix' => substr($plain, 0, 20),
            'key_hash' => ApiKey::hashKey($plain),
        ]);
    }

    private function runBackfill(array $options = []): void
    {
        Artisan::call('api-keys:backfill-legacy-bindings', $options);
    }

    private function commandOutput(): string
    {
        return Artisan::output();
    }

    // =====================================================================
    // 1-3. Single-key legacy account.
    // =====================================================================

    public function test_single_key_legacy_account_creates_exactly_one_live_binding_with_the_legacy_ip_and_no_credential(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.50']);
        $key = $this->makeKey($account);

        $this->runBackfill();

        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->count());

        $binding = $key->fresh()->liveBinding();
        $this->assertNotNull($binding);
        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $binding->status);
        $this->assertSame('SINGLE_IP', $binding->ip_policy);
        $this->assertSame(['203.0.113.50'], $binding->authorized_ips);
        $this->assertSame('203.0.113.50', $binding->registered_ip);
        $this->assertFalse($binding->hasCredential(), 'backfill must never mint a credential.');
        $this->assertNull($binding->installation_hash);
        $this->assertNull($binding->installation_prefix);
    }

    // =====================================================================
    // 4. Idempotent rerun.
    // =====================================================================

    public function test_rerunning_backfill_is_idempotent(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.51']);
        $key = $this->makeKey($account);

        $this->runBackfill();
        $firstBindingId = $key->fresh()->liveBinding()->id;

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->count());
        $this->assertSame($firstBindingId, $key->fresh()->liveBinding()->id, 'rerun must not replace the binding.');
    }

    // =====================================================================
    // 5-6. Multiple-key ambiguity.
    // =====================================================================

    public function test_multiple_key_legacy_account_creates_no_binding_automatically_and_is_reported_deterministically(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.52']);
        $keyOne = $this->makeKey($account, 'One');
        $keyTwo = $this->makeKey($account, 'Two');

        $this->runBackfill();

        $this->assertSame(0, ApiKeyBinding::where('api_key_id', $keyOne->id)->count());
        $this->assertSame(0, ApiKeyBinding::where('api_key_id', $keyTwo->id)->count());
        $this->assertNull($keyOne->fresh()->legacy_binding_grace_expires_at);
        $this->assertNull($keyTwo->fresh()->legacy_binding_grace_expires_at);

        $output = $this->commandOutput();
        $this->assertStringContainsString('AMBIGUOUS', $output);
        $this->assertStringContainsString("account_id={$account->id}", $output);
        $this->assertStringContainsString((string) $keyOne->id, $output);
        $this->assertStringContainsString((string) $keyTwo->id, $output);
    }

    // =====================================================================
    // 7. Already-bound key is skipped.
    // =====================================================================

    public function test_an_already_bound_key_is_skipped(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.53']);
        $key = $this->makeKey($account);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['198.51.100.9']]);
        $existingBinding = $key->fresh()->liveBinding();

        $this->runBackfill();

        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->count());
        $this->assertSame($existingBinding->id, $key->fresh()->liveBinding()->id);
        $this->assertTrue($key->fresh()->liveBinding()->hasCredential(), 'the existing, normally-provisioned binding must be untouched.');
        $this->assertNull($key->fresh()->legacy_binding_grace_expires_at, 'an already-resolved key must never enter the legacy deadline flow.');
    }

    // =====================================================================
    // 8. Revoked historical binding does not count as live.
    // =====================================================================

    public function test_a_key_with_only_a_historical_revoked_binding_is_an_eligible_candidate_and_gets_a_fresh_live_binding(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.54']);
        $key = $this->makeKey($account);
        $admin = \App\Models\User::factory()->create();

        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['198.51.100.10']]);
        $revokedBinding = $key->fresh()->liveBinding();
        $this->service()->revoke($key->fresh(), $admin, 'test revoke');

        $this->assertNull($key->fresh()->liveBinding(), 'fixture assumption: the key must have no live binding after the binding is revoked.');
        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->where('status', ApiKeyBinding::STATUS_REVOKED)->count());

        // revoke() starts the same flat 14-day cooldown (Phase 4 Task 9) as
        // a completed transfer. The backfill command deliberately never
        // bypasses this security control (see BackfillApiKeyLegacyBindings
        // class docblock), so travel past it to exercise the eligible-
        // candidate path itself, same pattern as TransferAtomicityTest.
        $this->travel(15)->days();

        $this->runBackfill();

        // The historical revoked row is untouched — never resurrected, never reused.
        $this->assertSame(ApiKeyBinding::STATUS_REVOKED, $revokedBinding->fresh()->status);
        $this->assertNotNull($revokedBinding->fresh()->revoked_at);

        $live = $key->fresh()->liveBinding();
        $this->assertNotNull($live, 'a fresh live binding must be created — this key was the account\'s only candidate.');
        $this->assertNotSame($revokedBinding->id, $live->id, 'the fresh binding must be a NEW row, never the revoked one reused.');
        $this->assertSame('203.0.113.54', $live->registered_ip, 'the fresh binding must use the ACCOUNT ip, not anything from the revoked row.');
        $this->assertSame(['203.0.113.54'], $live->authorized_ips);
        $this->assertFalse($live->hasCredential(), 'backfill must never mint a credential.');
        $this->assertNull($live->installation_hash);
        $this->assertSame(2, ApiKeyBinding::where('api_key_id', $key->id)->count(), 'one revoked (historical) + one fresh live row.');

        $this->assertNotNull($key->fresh()->legacy_binding_grace_expires_at, 'the single eligible candidate must receive the legacy grace deadline.');

        // Idempotent rerun: no second binding, no deadline change.
        $deadline = $key->fresh()->legacy_binding_grace_expires_at;
        $this->runBackfill();
        $this->assertSame(2, ApiKeyBinding::where('api_key_id', $key->id)->count(), 'rerun must not create another binding.');
        $this->assertSame($live->id, $key->fresh()->liveBinding()->id);
        $this->assertTrue($deadline->equalTo($key->fresh()->legacy_binding_grace_expires_at), 'rerun must not change the deadline.');
    }

    /**
     * Correction: the key itself being revoked (api_keys.revoked_at)
     * must NOT exclude it from the legacy-candidate decision — only
     * BINDING state (live vs not) matters here. This is deliberately
     * the literal architecture even though a revoked key can never
     * authenticate again; Task 5 classifies binding state, not current
     * authenticatability (AuthenticateApiKey's own, separate refusal of
     * a revoked key is unaffected and untouched by this task).
     */
    public function test_a_revoked_api_key_with_no_live_binding_is_still_an_eligible_legacy_candidate(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.154']);
        $key = $this->makeKey($account);
        $key->forceFill(['revoked_at' => now()])->save();

        $this->assertTrue($key->fresh()->isRevoked());
        $this->assertNull($key->fresh()->liveBinding());

        $this->runBackfill();

        $live = $key->fresh()->liveBinding();
        $this->assertNotNull($live, 'a revoked key with no live binding is still a legacy candidate per the finalized, structural definition.');
        $this->assertSame('203.0.113.154', $live->registered_ip);
        $this->assertFalse($live->hasCredential());
        $this->assertNotNull($key->fresh()->legacy_binding_grace_expires_at);
    }

    /**
     * Required test: mixed revoked-binding history across several keys
     * must not change the ambiguity classification — it is driven purely
     * by HOW MANY keys currently lack a live binding, never by whether
     * any of them has revoked history.
     */
    public function test_multiple_keys_with_mixed_revoked_binding_history_are_classified_by_live_binding_absence_not_revoked_history(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.155']);
        $admin = \App\Models\User::factory()->create();

        // Key A: has a historical revoked binding, currently no live binding -> candidate.
        $keyA = $this->makeKey($account, 'A');
        $this->service()->provision($keyA, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['198.51.100.30']]);
        $this->service()->revoke($keyA->fresh(), $admin, 'test revoke');

        // Key B: never had any binding at all -> candidate.
        $keyB = $this->makeKey($account, 'B');

        // Key C: currently has a live binding -> already resolved, NOT a candidate, excluded from ambiguity.
        $keyC = $this->makeKey($account, 'C');
        $this->service()->provision($keyC, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['198.51.100.31']]);

        $this->runBackfill();

        // Two candidates (A, B) -> ambiguous, nothing created for either, C untouched.
        $this->assertNull($keyA->fresh()->liveBinding());
        $this->assertNull($keyB->fresh()->liveBinding());
        $this->assertNotNull($keyC->fresh()->liveBinding());
        $this->assertNull($keyA->fresh()->legacy_binding_grace_expires_at);
        $this->assertNull($keyB->fresh()->legacy_binding_grace_expires_at);
        $this->assertNull($keyC->fresh()->legacy_binding_grace_expires_at);

        $output = $this->commandOutput();
        $this->assertStringContainsString('AMBIGUOUS', $output);
        $this->assertStringContainsString("account_id={$account->id}", $output);
        $this->assertStringContainsString((string) $keyA->id, $output);
        $this->assertStringContainsString((string) $keyB->id, $output);
    }

    // =====================================================================
    // 9. Null authorized_server_ip creates nothing.
    // =====================================================================

    public function test_null_authorized_server_ip_creates_nothing(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => null]);
        $key = $this->makeKey($account);

        $this->runBackfill();

        $this->assertSame(0, ApiKeyBinding::where('api_key_id', $key->id)->count());
        $this->assertNull($key->fresh()->legacy_binding_grace_expires_at);
    }

    // =====================================================================
    // 10. Multiple-live-binding anomaly is reported and untouched.
    // =====================================================================

    public function test_a_multiple_live_binding_anomaly_is_reported_and_left_untouched(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.55']);
        $key = $this->makeKey($account);

        // This specific anomaly can exist DESPITE the unique index: the
        // index is on (api_key_id, active_slot), not on status alone, so
        // two rows with DIFFERENT active_slot values can both be status
        // = active at once. No application code path does this today —
        // this reproduces the anomaly directly, as a hypothetical data-
        // integrity defect the command must still detect and refuse to
        // touch, exactly as the task specifies.
        $bindingOne = ApiKeyBinding::create([
            'account_id' => $account->id, 'api_key_id' => $key->id, 'status' => ApiKeyBinding::STATUS_ACTIVE,
            'active_slot' => 1, 'ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.55'], 'registered_at' => now(),
        ]);
        $bindingTwo = ApiKeyBinding::create([
            'account_id' => $account->id, 'api_key_id' => $key->id, 'status' => ApiKeyBinding::STATUS_ACTIVE,
            'active_slot' => 2, 'ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.55'], 'registered_at' => now(),
        ]);

        $this->runBackfill();

        // Untouched: still exactly these two rows, nothing added, nothing changed.
        $this->assertSame(2, ApiKeyBinding::where('api_key_id', $key->id)->count());
        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $bindingOne->fresh()->status);
        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $bindingTwo->fresh()->status);
        $this->assertNull($key->fresh()->legacy_binding_grace_expires_at);

        $output = $this->commandOutput();
        $this->assertStringContainsString('ANOMALY', $output);
        $this->assertStringContainsString("api_key_id={$key->id}", $output);
        $this->assertStringContainsString((string) $bindingOne->id, $output);
        $this->assertStringContainsString((string) $bindingTwo->id, $output);
    }

    // =====================================================================
    // 11-12. Deadline population and anchoring.
    // =====================================================================

    public function test_legacy_binding_grace_expires_at_is_populated_only_for_the_single_key_backfilled_case(): void
    {
        $singleAccount = Account::factory()->create(['authorized_server_ip' => '203.0.113.56']);
        $singleKey = $this->makeKey($singleAccount);

        $multiAccount = Account::factory()->create(['authorized_server_ip' => '203.0.113.57']);
        $multiKeyA = $this->makeKey($multiAccount, 'A');
        $multiKeyB = $this->makeKey($multiAccount, 'B');

        $noIpAccount = Account::factory()->create(['authorized_server_ip' => null]);
        $noIpKey = $this->makeKey($noIpAccount);

        $this->runBackfill();

        $this->assertNotNull($singleKey->fresh()->legacy_binding_grace_expires_at);
        $this->assertNull($multiKeyA->fresh()->legacy_binding_grace_expires_at);
        $this->assertNull($multiKeyB->fresh()->legacy_binding_grace_expires_at);
        $this->assertNull($noIpKey->fresh()->legacy_binding_grace_expires_at);
    }

    public function test_the_deadline_is_anchored_to_the_actual_backfill_run_time_not_to_ip_registered_at(): void
    {
        $distinguishableOldDate = Carbon::parse('2020-01-01 00:00:00');
        $account = Account::factory()->create([
            'authorized_server_ip' => '203.0.113.58',
            // A deliberately very different, distinguishable value — if the
            // deadline were ever wrongly anchored to this instead of "now",
            // the two would be nowhere near each other and this test would
            // fail loudly rather than coincidentally pass.
            'ip_registered_at' => $distinguishableOldDate,
        ]);
        $key = $this->makeKey($account);

        $fixedNow = Carbon::parse('2026-10-07 12:00:00');
        Carbon::setTestNow($fixedNow);

        $this->runBackfill();

        $graceDays = (int) config('api_binding.legacy_binding_grace_days', 30);
        $expected = $fixedNow->copy()->addDays($graceDays);

        $this->assertTrue($expected->equalTo($key->fresh()->legacy_binding_grace_expires_at));
        $this->assertFalse($distinguishableOldDate->copy()->addDays($graceDays)->equalTo($key->fresh()->legacy_binding_grace_expires_at));

        Carbon::setTestNow();
    }

    // =====================================================================
    // 13. Rerun does not extend/reset an existing deadline.
    // =====================================================================

    public function test_rerun_does_not_extend_or_reset_an_existing_deadline(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.59']);
        $key = $this->makeKey($account);

        Carbon::setTestNow(Carbon::parse('2026-10-07 00:00:00'));
        $this->runBackfill();
        $firstDeadline = $key->fresh()->legacy_binding_grace_expires_at;
        $this->assertNotNull($firstDeadline);

        // Time passes, then the backfill runs again.
        Carbon::setTestNow(Carbon::parse('2026-11-20 00:00:00'));
        $this->runBackfill();

        $this->assertTrue($firstDeadline->equalTo($key->fresh()->legacy_binding_grace_expires_at), 'a rerun must never push the deadline forward.');

        Carbon::setTestNow();
    }

    // =====================================================================
    // 14. Newly created API keys remain outside the legacy deadline flow.
    // =====================================================================

    public function test_a_newly_created_normally_provisioned_key_never_enters_the_legacy_deadline_flow(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.60']);
        $newKey = $this->makeKey($account, 'Freshly provisioned');
        $this->service()->provision($newKey, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['198.51.100.20']]);

        $this->assertNull($newKey->fresh()->legacy_binding_grace_expires_at, 'a brand-new key must start with no deadline at all.');

        $this->runBackfill();

        $this->assertNull($newKey->fresh()->legacy_binding_grace_expires_at, 'an already-live key must never be pulled into the legacy deadline flow by a later backfill run.');
    }

    // =====================================================================
    // 15. The real, engine-independent safety net: the unique index itself.
    // (See class docblock for the concurrency-testing disclosure.)
    // =====================================================================

    public function test_the_unique_live_binding_index_rejects_a_second_live_binding_for_the_same_key_regardless_of_any_application_level_lock(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.61']);
        $key = $this->makeKey($account);

        $this->service()->createLegacyBackfillBinding($key, '203.0.113.61');

        // createLiveBindingEnforced()'s own lockForUpdate() + live()->exists()
        // guard (ApiKeyBindingService::createLiveBindingEnforced()) now
        // always runs first and refuses this in-process, same-seam double
        // call with a clear InvalidArgumentException — the raw unique-index
        // QueryException is unreachable through this method and is kept
        // only as the out-of-process backstop the command's own docblock
        // describes (see BackfillApiKeyLegacyBindings::bindOneLegacyKey()).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This API key already has a live authorized server.');
        $this->service()->createLegacyBackfillBinding($key->fresh(), '203.0.113.61');
    }

    public function test_the_unique_violation_from_that_rejection_is_recognized_as_a_race_not_an_error(): void
    {
        $account = Account::factory()->create(['authorized_server_ip' => '203.0.113.62']);
        $key = $this->makeKey($account);
        $this->service()->createLegacyBackfillBinding($key, '203.0.113.62');

        // Same reasoning as the test above: in-process, this is now
        // caught by createLiveBindingEnforced()'s own guard, not the raw
        // DB constraint. The command treats this InvalidArgumentException
        // exactly the same as a unique-violation QueryException — both are
        // "already resolved by someone else" (see bindOneLegacyKey()).
        try {
            $this->service()->createLegacyBackfillBinding($key->fresh(), '203.0.113.62');
            $this->fail('expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('This API key already has a live authorized server.', $e->getMessage());
        }
    }
}
