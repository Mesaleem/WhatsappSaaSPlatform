<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\ApiKeyCooldownActiveException;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 9 FIX — requestChange()'s cooldown check (and the
 * live-binding read / change-request insert that follow it) now run
 * inside a DB::transaction() holding the same Account -> ApiKey lock
 * order as createLiveBindingEnforced()/revoke()/destroyKey(), instead
 * of a bare unlocked read. This file is the dedicated regression
 * coverage for that fix.
 *
 * "requestChange() is blocked while in cooldown" (fix requirement 1)
 * is NOT duplicated here — it was already covered before this fix,
 * and remains covered unchanged, by
 * ApiKeyCooldownEnforcementTest::test_cooldown_blocks_requestChange().
 * That test's behavior is identical after this fix (same exception,
 * same call), because the fix only changes HOW the check is made
 * concurrency-safe, not what it decides in the single-threaded case.
 *
 * Task 8's own concurrency tests (TransferAtomicityConcurrencyTest.php)
 * are untouched by this file.
 */
class RequestChangeCooldownRaceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function admin(): User
    {
        return User::factory()->create();
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

    private function capability(): Capability
    {
        return Capability::firstOrCreate(
            ['slug' => InstallationAllowanceResolver::CAPABILITY],
            ['label' => 'API Installations', 'category' => 'platform']
        );
    }

    private function grantAllowance(Account $account, int $limit): void
    {
        $capability = $this->capability();

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $plan = Plan::create([
            'slug' => 'task9-fix-plan-'.Str::random(8),
            'label' => 'Task 9 Fix Test Plan',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
        ]);
        $plan->capabilities()->attach($capability->id, ['usage_limit' => $limit]);

        Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.Str::random(10),
            'plan_key' => $plan->slug,
            'plan_label' => $plan->label,
            'amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(10),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    private function accountWithAllowance(int $limit = 5): Account
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, $limit);

        return $account;
    }

    private function provisionedKey(Account $account, string $ip = '203.0.113.60', string $name = 'Key'): ApiKey
    {
        $key = $this->makeKey($account, $name);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => [$ip]]);

        return $key->fresh();
    }

    // ---- Fix regression 2: another key on the same account is unaffected --------------------------------------

    public function test_another_keys_cooldown_does_not_block_this_keys_requestChange(): void
    {
        $account = $this->accountWithAllowance();
        $keyA = $this->provisionedKey($account, '203.0.113.61', 'Key A');
        $keyB = $this->provisionedKey($account, '203.0.113.62', 'Key B');
        $this->service()->setCooldown($keyA, now()->addDays(14));

        $request = $this->service()->requestChange($keyB->fresh(), $this->admin(), [
            'reason' => 'moving servers',
            'ip_policy' => 'SINGLE_IP',
            'requested_ips' => ['203.0.113.63'],
        ]);

        $this->assertSame($keyB->id, $request->api_key_id);
    }

    // ---- Fix regression 4: lock ordering remains Account -> ApiKey ------------------------------------------------

    public function test_requestChange_locks_the_account_row_before_the_api_key_row(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->provisionedKey($account);

        $order = [];
        DB::listen(function ($query) use (&$order, $account, $key) {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "accounts"') && in_array($account->id, $query->bindings, true)) {
                $order[] = 'accounts';
            }
            if (str_contains($sql, 'from "api_keys"') && in_array($key->id, $query->bindings, true)) {
                $order[] = 'api_keys';
            }
        });

        $this->service()->requestChange($key->fresh(), $this->admin(), [
            'reason' => 'moving servers',
            'ip_policy' => 'SINGLE_IP',
            'requested_ips' => ['203.0.113.64'],
        ]);

        $firstAccountIndex = array_search('accounts', $order, true);
        $firstApiKeyIndex = array_search('api_keys', $order, true);

        $this->assertNotFalse($firstAccountIndex, 'requestChange() must lock the accounts row.');
        $this->assertNotFalse($firstApiKeyIndex, 'requestChange() must lock the api_keys row.');
        $this->assertLessThan($firstApiKeyIndex, $firstAccountIndex, 'Account must be locked before ApiKey — same order as createLiveBindingEnforced()/revoke()/destroyKey().');
    }

    // ---- Fix regression 3: a concurrent revoke cannot race requestChange() into an inconsistent result -------------

    /**
     * SCENARIO: a key has a live, active binding and no cooldown.
     * Concurrently: one process calls requestChange() for a new server;
     * another calls revoke() (Task 9's now-locked path) on the SAME
     * key. Before this fix, requestChange() could read "no cooldown,
     * live binding exists" before revoke() committed, then insert its
     * change request AFTER revoke() committed — leaving a PENDING
     * change request that is "inconsistent" with reality: it is for a
     * key that has just entered cooldown and lost its live binding.
     * After this fix, the two now contend for the same ApiKey row lock
     * and are fully serialized, so exactly one of two consistent
     * outcomes is possible, never a third, inconsistent one:
     *   (a) requestChange() wins the lock first -> its change request
     *       is created against the (still valid) pre-revoke binding,
     *       and revoke() then proceeds normally once it acquires the
     *       lock; or
     *   (b) revoke() wins the lock first -> by the time requestChange()
     *       acquires the lock, the key is in cooldown and has no live
     *       binding, so requestChange() must be REJECTED, and no
     *       change request is ever created.
     * What must never happen: requestChange() succeeds AFTER revoke()
     * has already committed (a pending request left behind for a
     * cooled-down, unbound key).
     *
     * Same honest limitation as Task 8/9's other pcntl_fork tests:
     * SQLite's FOR UPDATE is a no-op, so this proves the real OS
     * processes always land in one of the two consistent outcomes
     * above, not that Account::lockForUpdate() specifically serializes
     * two InnoDB transactions on MariaDB/MySQL the way it is designed
     * to — that remains UNVERIFIED by this suite (see the Task 9
     * report's own disclosure).
     */
    public function test_concurrent_revoke_and_requestChange_never_produce_an_inconsistent_result(): void
    {
        if (! extension_loaded('pcntl') || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available in this environment. MariaDB/MySQL row-lock concurrency for this race remains UNVERIFIED (see class docblock and the Task 9 Fix report).');
        }

        $dbPath = tempnam(sys_get_temp_dir(), 'task9fix_race_').'.sqlite';
        touch($dbPath);

        [$accountId, $keyId] = $this->bootstrapRaceFixture($dbPath);

        $resultFileRevoke = $dbPath.'.result_revoke';
        $resultFileRequest = $dbPath.'.result_request';

        $pidRevoke = pcntl_fork();
        if ($pidRevoke === -1) {
            $this->fail('pcntl_fork() failed.');
        }
        if ($pidRevoke === 0) {
            $this->attemptRevokeInChildProcess($dbPath, $keyId, $resultFileRevoke);
            exit(0);
        }

        $pidRequest = pcntl_fork();
        if ($pidRequest === -1) {
            $this->fail('pcntl_fork() failed.');
        }
        if ($pidRequest === 0) {
            $this->attemptRequestChangeInChildProcess($dbPath, $keyId, '203.0.113.99', $resultFileRequest);
            exit(0);
        }

        pcntl_waitpid($pidRevoke, $statusRevoke);
        pcntl_waitpid($pidRequest, $statusRequest);

        $resultRevoke = file_exists($resultFileRevoke) ? trim(file_get_contents($resultFileRevoke)) : 'missing';
        $resultRequest = file_exists($resultFileRequest) ? trim(file_get_contents($resultFileRequest)) : 'missing';

        $this->assertSame('revoked', $resultRevoke, "The revoke must always succeed (got: {$resultRevoke}).");
        $this->assertContains($resultRequest, ['requested', 'blocked_by_cooldown'], "requestChange() must either succeed cleanly or be cleanly rejected, never error out (got: {$resultRequest}).");

        config(['database.connections.task9fix_race_verify' => [
            'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('task9fix_race_verify');
        $conn = DB::connection('task9fix_race_verify');

        $pendingCount = $conn->table('api_key_change_requests')->where('api_key_id', $keyId)->where('status', 'pending')->count();
        $keyRow = $conn->table('api_keys')->where('id', $keyId)->first();
        $liveBindingCount = $conn->table('api_key_bindings')->where('api_key_id', $keyId)->whereIn('status', ['pending_activation', 'active'])->count();

        $this->assertNotNull($keyRow->cooldown_until, 'The revoke must have set the cooldown regardless of ordering.');
        $this->assertSame(0, $liveBindingCount, 'The live binding must have been revoked regardless of ordering.');

        if ($resultRequest === 'requested') {
            // Outcome (a): requestChange() won the lock first. Exactly
            // one pending change request must exist, for THIS key.
            $this->assertSame(1, $pendingCount, 'requestChange() reported success — exactly one pending request must exist.');
        } else {
            // Outcome (b): revoke() won the lock first, so
            // requestChange() must have been rejected and left nothing
            // behind — never a pending request on a cooled-down, unbound key.
            $this->assertSame(0, $pendingCount, 'requestChange() was rejected — it must not have left a pending request behind.');
        }

        @unlink($resultFileRevoke);
        @unlink($resultFileRequest);
        @unlink($dbPath);
    }

    /** @return array{0: int, 1: int} [$accountId, $keyId] */
    private function bootstrapRaceFixture(string $dbPath): array
    {
        config(['database.connections.task9fix_race' => [
            'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('task9fix_race');
        $conn = DB::connection('task9fix_race');
        $schema = Schema::connection('task9fix_race');

        $schema->create('accounts', function ($t) {
            $t->id();
            $t->timestamps();
        });
        $schema->create('api_keys', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('name');
            $t->string('key_prefix');
            $t->string('key_hash');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('legacy_binding_grace_expires_at')->nullable();
            $t->timestamp('cooldown_until')->nullable();
            $t->timestamp('access_disabled_at')->nullable();
            $t->string('access_disabled_reason')->nullable();
            $t->timestamps();
        });
        $schema->create('api_key_bindings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('api_key_id');
            $t->string('status', 24);
            $t->unsignedTinyInteger('active_slot')->nullable();
            $t->string('label', 100)->nullable();
            $t->string('ip_policy', 16);
            $t->json('authorized_ips')->nullable();
            $t->string('installation_prefix', 24)->nullable();
            $t->string('installation_hash', 64)->nullable();
            $t->string('registered_ip', 45)->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->string('last_success_ip', 45)->nullable();
            $t->string('last_success_client', 24)->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoked_reason', 191)->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('revoked_by_user_id')->nullable();
            $t->timestamps();
            $t->unique(['api_key_id', 'active_slot']);
        });
        $schema->create('api_key_change_requests', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('api_key_id');
            $t->unsignedBigInteger('current_binding_id')->nullable();
            $t->string('status', 24);
            $t->unsignedTinyInteger('pending_slot')->nullable();
            $t->string('current_label', 100)->nullable();
            $t->string('current_ip', 45)->nullable();
            $t->string('requested_label', 100)->nullable();
            $t->string('requested_ip_policy', 16)->nullable();
            $t->json('requested_ips')->nullable();
            $t->string('reason', 500)->nullable();
            $t->unsignedBigInteger('requested_by_user_id')->nullable();
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->unsignedBigInteger('new_binding_id')->nullable();
            $t->timestamps();
            $t->unique(['api_key_id', 'pending_slot']);
        });
        $schema->create('api_key_security_events', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->unsignedBigInteger('api_key_id')->nullable();
            $t->unsignedBigInteger('binding_id')->nullable();
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('event', 48);
            $t->string('ip', 45)->nullable();
            $t->json('context')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        $schema->create('capabilities', function ($t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('label')->nullable();
            $t->string('category')->nullable();
            $t->timestamps();
        });
        $schema->create('account_entitlements', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('capability_id');
            $t->string('source');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        $schema->create('plans', function ($t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('label');
            $t->decimal('price', 10, 2)->default(0);
            $t->unsignedInteger('duration_days')->default(30);
            $t->string('engine_type')->default('qr');
            $t->string('billing_model')->default('flat_quota');
            $t->timestamps();
        });
        $schema->create('plan_entitlements', function ($t) {
            $t->id();
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('capability_id');
            $t->unsignedInteger('usage_limit')->nullable();
            $t->timestamps();
        });
        $schema->create('invoices', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('plan_key');
            $t->string('status');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });

        $accountId = $conn->table('accounts')->insertGetId(['created_at' => now(), 'updated_at' => now()]);

        $capabilityId = $conn->table('capabilities')->insertGetId([
            'slug' => InstallationAllowanceResolver::CAPABILITY, 'label' => 'API Installations', 'category' => 'platform',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('account_entitlements')->insert([
            'account_id' => $accountId, 'capability_id' => $capabilityId, 'source' => 'manual_grant',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $planId = $conn->table('plans')->insertGetId([
            'slug' => 'task9fix-race-plan', 'label' => 'Task 9 Fix Race Plan', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('plan_entitlements')->insert([
            'plan_id' => $planId, 'capability_id' => $capabilityId, 'usage_limit' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('invoices')->insert([
            'account_id' => $accountId, 'plan_key' => 'task9fix-race-plan', 'status' => 'paid', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $keyId = $conn->table('api_keys')->insertGetId([
            'account_id' => $accountId, 'name' => 'K', 'key_prefix' => 'pfx_fk', 'key_hash' => hash('sha256', 'fk'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $conn->table('api_key_bindings')->insert([
            'account_id' => $accountId, 'api_key_id' => $keyId, 'status' => 'active', 'active_slot' => 1,
            'ip_policy' => 'SINGLE_IP', 'authorized_ips' => json_encode(['203.0.113.98']),
            'registered_at' => now(), 'last_success_at' => now(), 'last_success_ip' => '203.0.113.98',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$accountId, $keyId];
    }

    /** Runs inside a forked child: its own fresh connection to the same on-disk file, then the real revoke() seam. Never throws out of the child. */
    private function attemptRevokeInChildProcess(string $dbPath, int $keyId, string $resultFile): void
    {
        try {
            config(['database.connections.task9fix_race' => [
                'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
            ]]);
            config(['database.default' => 'task9fix_race']);
            DB::purge('task9fix_race');

            usleep(random_int(0, 5000));

            $key = ApiKey::on('task9fix_race')->find($keyId);
            $admin = new User();
            $admin->id = 1;

            app(ApiKeyBindingService::class)->revoke($key, $admin, 'race test revoke');

            file_put_contents($resultFile, 'revoked');
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.$e->getMessage());
        }
    }

    /** Runs inside a forked child: its own fresh connection to the same on-disk file, then the real requestChange() seam. Never throws out of the child. */
    private function attemptRequestChangeInChildProcess(string $dbPath, int $keyId, string $newIp, string $resultFile): void
    {
        try {
            config(['database.connections.task9fix_race' => [
                'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
            ]]);
            config(['database.default' => 'task9fix_race']);
            DB::purge('task9fix_race');

            usleep(random_int(0, 5000));

            $key = ApiKey::on('task9fix_race')->find($keyId);
            $actor = new User();
            $actor->id = 2;

            app(ApiKeyBindingService::class)->requestChange($key, $actor, [
                'reason' => 'race test request',
                'ip_policy' => 'SINGLE_IP',
                'requested_ips' => [$newIp],
            ]);

            file_put_contents($resultFile, 'requested');
        } catch (ApiKeyCooldownActiveException $e) {
            file_put_contents($resultFile, 'blocked_by_cooldown');
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.$e->getMessage());
        }
    }
}
