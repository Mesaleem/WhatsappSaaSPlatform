<?php

namespace Tests\Feature\ApiAccess;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 4 Task 9, required test 19 — "Concurrent operations preserve
 * the existing Account -> ApiKey lock order."
 *
 * Built on the exact same real-two-OS-process pattern as Task 8's
 * tests/Feature/ApiAccess/TransferAtomicityConcurrencyTest.php (itself
 * built on Task 6's InstallationAllowanceConcurrencyTest.php), with the
 * same honest limitation restated below. revoke() had NO locking at
 * all before this task; this test is the concurrency proof for the
 * locking Task 9 adds to it, run against the ALREADY-proven transfer
 * seam concurrently, on the SAME account, to show the two code paths
 * do not deadlock or corrupt shared account state when racing each
 * other for the same Account row lock.
 *
 * SAME DISCLOSURE AS TASK 8: pcntl_fork + a real on-disk SQLite file
 * (never :memory:) is the strongest cross-connection contention this
 * repository's own tooling can exercise. SQLite's `FOR UPDATE` is a
 * no-op, so this proves "two real OS processes racing still end in the
 * one correct final state", not that Account::lockForUpdate()
 * specifically serializes two InnoDB transactions on MariaDB/MySQL the
 * way it is designed to. MariaDB/MySQL row-lock concurrency for
 * revoke()'s new locking remains UNVERIFIED by this suite.
 *
 * SCENARIO: account allowance = 2, already fully occupied by two keys
 * (A and B). Concurrently: Key A is explicitly REVOKED (admin
 * revoke(), Task 9's newly-locked path) while Key B is TRANSFERRED
 * (rebind()). Revoke frees A's slot and sets A's cooldown; the
 * transfer is a pure 1-for-1 replacement for B and sets B's cooldown.
 * Expected final state: exactly 1 live binding total (B, on its new
 * IP), A revoked with a future cooldown_until, B live with a future
 * cooldown_until, A and B's cooldowns are independent values (never a
 * shared/account-wide timestamp).
 */
class CooldownLockOrderConcurrencyTest extends TestCase
{
    public function test_concurrent_revoke_and_rebind_on_the_same_account_preserve_the_account_then_api_key_lock_order(): void
    {
        if (! extension_loaded('pcntl') || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available in this environment — the strongest concurrency check this repository can run. MariaDB/MySQL row-lock concurrency for revoke()\'s new locking remains UNVERIFIED (see class docblock and the Task 9 report).');
        }

        $dbPath = tempnam(sys_get_temp_dir(), 'task9_lockorder_').'.sqlite';
        touch($dbPath);

        [$accountId, $keyAId, $keyBId] = $this->bootstrapRaceFixture($dbPath);

        $resultFileA = $dbPath.'.result_a';
        $resultFileB = $dbPath.'.result_b';

        $pidA = pcntl_fork();
        if ($pidA === -1) {
            $this->fail('pcntl_fork() failed.');
        }
        if ($pidA === 0) {
            $this->attemptRevokeInChildProcess($dbPath, $keyAId, $resultFileA);
            exit(0);
        }

        $pidB = pcntl_fork();
        if ($pidB === -1) {
            $this->fail('pcntl_fork() failed.');
        }
        if ($pidB === 0) {
            $this->attemptTransferInChildProcess($dbPath, $keyBId, '203.0.113.222', $resultFileB);
            exit(0);
        }

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $resultA = file_exists($resultFileA) ? trim(file_get_contents($resultFileA)) : 'missing';
        $resultB = file_exists($resultFileB) ? trim(file_get_contents($resultFileB)) : 'missing';

        $this->assertSame('revoked', $resultA, "Key A's revoke must succeed (got: {$resultA}).");
        $this->assertSame('transferred', $resultB, "Key B's transfer must succeed (got: {$resultB}).");

        config(['database.connections.task9_race_verify' => [
            'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('task9_race_verify');
        $conn = DB::connection('task9_race_verify');

        $liveCount = $conn->table('api_key_bindings')->whereIn('status', ['pending_activation', 'active'])->where('account_id', $accountId)->count();
        $this->assertSame(1, $liveCount, 'exactly 1 live binding total — A revoked (freed), B still live, never both live and never zero.');

        $keyA = $conn->table('api_keys')->where('id', $keyAId)->first();
        $keyB = $conn->table('api_keys')->where('id', $keyBId)->first();

        $this->assertNotNull($keyA->cooldown_until, "Key A's revoke must have set its own cooldown.");
        $this->assertNotNull($keyB->cooldown_until, "Key B's transfer must have set its own cooldown.");
        // Each is read from, and was written to, its OWN api_keys row
        // only (no join, no shared column, no account-level field was
        // ever touched by either operation) — structurally independent
        // by construction, not merely by coincidence of value, so this
        // does not assert the two values differ (they may legitimately
        // compute to the same second).


        $liveForB = $conn->table('api_key_bindings')->where('api_key_id', $keyBId)->whereIn('status', ['pending_activation', 'active'])->first();
        $this->assertNotNull($liveForB, 'Key B must still have exactly one live binding.');
        $this->assertSame(['203.0.113.222'], json_decode($liveForB->authorized_ips, true));

        $liveForA = $conn->table('api_key_bindings')->where('api_key_id', $keyAId)->whereIn('status', ['pending_activation', 'active'])->count();
        $this->assertSame(0, $liveForA, 'Key A must have no live binding left after its revoke.');

        @unlink($resultFileA);
        @unlink($resultFileB);
        @unlink($dbPath);
    }

    /**
     * Same schema as TransferAtomicityConcurrencyTest's
     * bootstrapRaceFixture() (Task 8) — the full production column set
     * for every table the service's revoke()/createLiveBindingEnforced()
     * actually read or write, so the real production methods can run
     * against it without a "no such column" error. Copied rather than
     * shared to keep this file self-contained and never touch Task 8's
     * file.
     *
     * @return array{0: int, 1: int, 2: int} [$accountId, $keyAId, $keyBId]
     */
    private function bootstrapRaceFixture(string $dbPath): array
    {
        config(['database.connections.task9_race' => [
            'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('task9_race');
        $conn = DB::connection('task9_race');
        $schema = Schema::connection('task9_race');

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
            'slug' => 'cooldown-lockorder-plan', 'label' => 'Cooldown Lock Order Plan', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('plan_entitlements')->insert([
            'plan_id' => $planId, 'capability_id' => $capabilityId, 'usage_limit' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('invoices')->insert([
            'account_id' => $accountId, 'plan_key' => 'cooldown-lockorder-plan', 'status' => 'paid', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $keyAId = $conn->table('api_keys')->insertGetId([
            'account_id' => $accountId, 'name' => 'A', 'key_prefix' => 'pfx_la', 'key_hash' => hash('sha256', 'la'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $keyBId = $conn->table('api_keys')->insertGetId([
            'account_id' => $accountId, 'name' => 'B', 'key_prefix' => 'pfx_lb', 'key_hash' => hash('sha256', 'lb'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $conn->table('api_key_bindings')->insert([
            'account_id' => $accountId, 'api_key_id' => $keyAId, 'status' => 'active', 'active_slot' => 1,
            'ip_policy' => 'SINGLE_IP', 'authorized_ips' => json_encode(['203.0.113.201']),
            'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('api_key_bindings')->insert([
            'account_id' => $accountId, 'api_key_id' => $keyBId, 'status' => 'active', 'active_slot' => 1,
            'ip_policy' => 'SINGLE_IP', 'authorized_ips' => json_encode(['203.0.113.202']),
            'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$accountId, $keyAId, $keyBId];
    }

    /** Runs inside a forked child: its own fresh connection to the same on-disk file, then the real revoke() seam. Never throws out of the child. */
    private function attemptRevokeInChildProcess(string $dbPath, int $keyId, string $resultFile): void
    {
        try {
            config(['database.connections.task9_race' => [
                'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
            ]]);
            config(['database.default' => 'task9_race']);
            DB::purge('task9_race');

            usleep(random_int(0, 5000));

            $key = ApiKey::on('task9_race')->find($keyId);
            $admin = new User();
            $admin->id = 1;

            app(ApiKeyBindingService::class)->revoke($key, $admin, 'race test revoke');

            file_put_contents($resultFile, 'revoked');
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.$e->getMessage());
        }
    }

    /** Runs inside a forked child: its own fresh connection to the same on-disk file, then the real rebind() seam. Never throws out of the child. */
    private function attemptTransferInChildProcess(string $dbPath, int $keyId, string $newIp, string $resultFile): void
    {
        try {
            config(['database.connections.task9_race' => [
                'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
            ]]);
            config(['database.default' => 'task9_race']);
            DB::purge('task9_race');

            usleep(random_int(0, 5000));

            $key = ApiKey::on('task9_race')->find($keyId);
            $admin = new User();
            $admin->id = 1;

            app(ApiKeyBindingService::class)->rebind($key, $admin, [
                'ip_policy' => 'SINGLE_IP',
                'authorized_ips' => [$newIp],
            ]);

            file_put_contents($resultFile, 'transferred');
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.$e->getMessage());
        }
    }
}
