<?php

namespace Tests\Feature\ApiAccess;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\InstallationAllowanceExceededException;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 4 Task 8 — required "concurrent transfers/provisioning cannot
 * exceed the allowance" scenario, built on the same real two-OS-process
 * pattern as tests/Feature/ApiAccess/InstallationAllowanceConcurrencyTest.php
 * (Task 6), with the same honest limitation: pcntl_fork + a real on-disk
 * SQLite file (never :memory:) is the strongest cross-connection
 * contention this repository's own tooling can exercise.
 * SQLite's `FOR UPDATE` is a no-op (no row-level locking engine), so
 * this proves "two real OS processes racing still end in the one
 * correct final state", not that createLiveBindingEnforced()'s Account-
 * row `SELECT ... FOR UPDATE` specifically serializes two InnoDB
 * transactions on MariaDB/MySQL the way it is designed to.
 * MariaDB/MySQL row-lock concurrency for the transfer path remains
 * UNVERIFIED by this suite — same disclosure as the Task 6 report.
 *
 * SCENARIO CHOSEN AND WHY: account allowance = 2, already fully
 * occupied by two keys (A and B), each with a live binding. Both keys
 * are concurrently TRANSFERRED (rebind()) to a new server at the same
 * time. Each transfer is a pure 1-for-1 replacement — net zero capacity
 * change — chosen deliberately over "one transfer vs. one fresh
 * provision" (which this file's sibling scenario below also covers):
 * a transfer of an ALREADY-live key, racing against a FRESH create for
 * an unrelated key, is decided correctly by the allowance check alone
 * regardless of interleaving or locking (the pre-existing live row is
 * visible to every reader except inside the other transaction's own
 * uncommitted window), so it is a weak proof of lock-dependent
 * correctness even though it IS a real scenario the task asks to
 * cover — included as a plain (non-forked) test in
 * TransferAtomicityTest for that reason, not here. Two SIMULTANEOUS
 * replacements against the SAME shared account allowance is the
 * genuinely contested case: without correct account-level
 * serialization, both processes could independently read a stale
 * pre-transfer count and something could, in principle, end up over
 * capacity or with a key left bindingless. This test proves the
 * correct, bounded outcome holds under real concurrent OS processes.
 */
class TransferAtomicityConcurrencyTest extends TestCase
{
    public function test_two_concurrent_transfers_for_two_different_keys_never_exceed_the_shared_account_allowance(): void
    {
        if (! extension_loaded('pcntl') || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available in this environment — this is the strongest concurrency check this repository can run, and it cannot run without pcntl. MariaDB/MySQL row-lock concurrency for the transfer path remains UNVERIFIED (see class docblock and the Task 8 report).');
        }

        $dbPath = tempnam(sys_get_temp_dir(), 'task8_transfer_').'.sqlite';
        touch($dbPath);

        [$accountId, $keyAId, $keyBId] = $this->bootstrapRaceFixture($dbPath);

        $resultFileA = $dbPath.'.result_a';
        $resultFileB = $dbPath.'.result_b';

        $pidA = pcntl_fork();
        if ($pidA === -1) {
            $this->fail('pcntl_fork() failed.');
        }
        if ($pidA === 0) {
            $this->attemptTransferInChildProcess($dbPath, $keyAId, '203.0.113.211', $resultFileA);
            exit(0);
        }

        $pidB = pcntl_fork();
        if ($pidB === -1) {
            $this->fail('pcntl_fork() failed.');
        }
        if ($pidB === 0) {
            $this->attemptTransferInChildProcess($dbPath, $keyBId, '203.0.113.212', $resultFileB);
            exit(0);
        }

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $resultA = file_exists($resultFileA) ? trim(file_get_contents($resultFileA)) : 'missing';
        $resultB = file_exists($resultFileB) ? trim(file_get_contents($resultFileB)) : 'missing';

        // Both transfers are pure 1-for-1 replacements against an
        // account that is already exactly at its allowance — neither
        // should ever be rejected (a correct implementation never
        // treats a replacement as needing a second slot), and the
        // final persisted state must never show more than the
        // account's allowance of 2 live bindings, nor either key left
        // with zero live bindings.
        $this->assertSame('transferred', $resultA, "Key A's transfer must succeed (got: {$resultA}).");
        $this->assertSame('transferred', $resultB, "Key B's transfer must succeed (got: {$resultB}).");

        config(['database.connections.task8_race_verify' => [
            'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('task8_race_verify');
        $conn = DB::connection('task8_race_verify');

        $liveCount = $conn->table('api_key_bindings')->whereIn('status', ['pending_activation', 'active'])->where('account_id', $accountId)->count();
        $this->assertSame(2, $liveCount, 'exactly 2 live bindings total — the shared allowance of 2, never exceeded.');

        $liveForA = $conn->table('api_key_bindings')->where('api_key_id', $keyAId)->whereIn('status', ['pending_activation', 'active'])->count();
        $liveForB = $conn->table('api_key_bindings')->where('api_key_id', $keyBId)->whereIn('status', ['pending_activation', 'active'])->count();
        $this->assertSame(1, $liveForA, 'Key A must end with exactly one live binding, not zero and not two.');
        $this->assertSame(1, $liveForB, 'Key B must end with exactly one live binding, not zero and not two.');

        $liveIpForA = $conn->table('api_key_bindings')->where('api_key_id', $keyAId)->whereIn('status', ['pending_activation', 'active'])->value('authorized_ips');
        $liveIpForB = $conn->table('api_key_bindings')->where('api_key_id', $keyBId)->whereIn('status', ['pending_activation', 'active'])->value('authorized_ips');
        $this->assertSame(['203.0.113.211'], json_decode($liveIpForA, true), "Key A's live binding must point at its NEW server, not the old one.");
        $this->assertSame(['203.0.113.212'], json_decode($liveIpForB, true), "Key B's live binding must point at its NEW server, not the old one.");

        @unlink($resultFileA);
        @unlink($resultFileB);
        @unlink($dbPath);
    }

    /** @return array{0: int, 1: int, 2: int} [$accountId, $keyAId, $keyBId] */
    private function bootstrapRaceFixture(string $dbPath): array
    {
        config(['database.connections.task8_race' => [
            'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('task8_race');
        $conn = DB::connection('task8_race');
        $schema = Schema::connection('task8_race');

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
        // Full production column set (unlike the Task 6 concurrency
        // fixture, which omits several columns createLive()/
        // revokeBinding() actually write — see this task's report) so
        // the real production methods can execute against it without
        // a "no such column" error.
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
            'slug' => 'transfer-race-plan', 'label' => 'Transfer Race Plan', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Allowance = 2, exactly matched by the two keys below — the
        // account starts and must end fully occupied, never over.
        $conn->table('plan_entitlements')->insert([
            'plan_id' => $planId, 'capability_id' => $capabilityId, 'usage_limit' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('invoices')->insert([
            'account_id' => $accountId, 'plan_key' => 'transfer-race-plan', 'status' => 'paid', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $keyAId = $conn->table('api_keys')->insertGetId([
            'account_id' => $accountId, 'name' => 'A', 'key_prefix' => 'pfx_ta', 'key_hash' => hash('sha256', 'ta'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $keyBId = $conn->table('api_keys')->insertGetId([
            'account_id' => $accountId, 'name' => 'B', 'key_prefix' => 'pfx_tb', 'key_hash' => hash('sha256', 'tb'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Both keys already hold the account's full allowance BEFORE
        // the race starts — inserted directly, bypassing the service,
        // since this is pre-race fixture setup, not part of what is
        // being tested.
        $conn->table('api_key_bindings')->insert([
            'account_id' => $accountId, 'api_key_id' => $keyAId, 'status' => 'active', 'active_slot' => 1,
            'ip_policy' => 'SINGLE_IP', 'authorized_ips' => json_encode(['203.0.113.1']),
            'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('api_key_bindings')->insert([
            'account_id' => $accountId, 'api_key_id' => $keyBId, 'status' => 'active', 'active_slot' => 1,
            'ip_policy' => 'SINGLE_IP', 'authorized_ips' => json_encode(['203.0.113.2']),
            'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$accountId, $keyAId, $keyBId];
    }

    /** Runs inside the forked child: its own fresh connection to the same on-disk file, then the real rebind() seam. Never throws out of the child. */
    private function attemptTransferInChildProcess(string $dbPath, int $keyId, string $newIp, string $resultFile): void
    {
        try {
            config(['database.connections.task8_race' => [
                'driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '', 'foreign_key_constraints' => false,
            ]]);
            config(['database.default' => 'task8_race']);
            DB::purge('task8_race');

            usleep(random_int(0, 5000));

            $key = ApiKey::on('task8_race')->find($keyId);
            // Never persisted — a direct property set bypasses mass-
            // assignment ($fillable) protection, which would otherwise
            // silently drop 'id'; only ->id is ever read by the
            // service (createLive()'s created_by_user_id, record()'s
            // actor_user_id), never a DB lookup against users.
            $admin = new User();
            $admin->id = 1;

            app(ApiKeyBindingService::class)->rebind($key, $admin, [
                'ip_policy' => 'SINGLE_IP',
                'authorized_ips' => [$newIp],
            ]);

            file_put_contents($resultFile, 'transferred');
        } catch (InstallationAllowanceExceededException $e) {
            file_put_contents($resultFile, 'rejected');
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.$e->getMessage());
        }
    }
}
