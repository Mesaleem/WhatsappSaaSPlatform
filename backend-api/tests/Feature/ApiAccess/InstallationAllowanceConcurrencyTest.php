<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\InstallationAllowanceExceededException;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 6 — required "Concurrency" test category.
 *
 * IMPORTANT / explicit disclosure required by the task spec: this
 * environment has no MariaDB/MySQL connection available (phpunit.xml
 * hard-codes DB_CONNECTION=sqlite, DB_DATABASE=:memory: — see this
 * repo's test configuration), and this whole implementation session ran
 * with no `php` binary available at all (confirmed via `which php`
 * returning nothing throughout), so NOTHING in this file has actually
 * been executed. It is reported as UNEXECUTED, not passed, exactly as
 * the task instructs.
 *
 * What this test attempts is the strongest concurrency check this
 * REPOSITORY'S tooling could support if run: two separate PHP processes
 * (pcntl_fork), each with its OWN database connection, racing to consume
 * the last of an account's installation allowance against a real
 * on-disk SQLite file (never :memory:, which is one isolated database
 * per connection and could never show cross-connection contention at
 * all). SQLite's own locking (a single database-wide write lock per
 * file) is the only real inter-connection contention this engine offers
 * — Eloquent's lockForUpdate() compiles to `FOR UPDATE`, which SQLite's
 * grammar silently drops (SQLite has no row-level locking), so this
 * test can only prove "two real OS processes racing for the final slot
 * still end with exactly one success", not that createLiveBindingEnforced()'s
 * specific Account-row `SELECT ... FOR UPDATE` actually serializes two
 * InnoDB transactions the way it is designed to.
 *
 * EXPLICITLY NOT CLAIMED: that this proves MariaDB/MySQL row-lock
 * correctness. MariaDB/MySQL concurrency correctness for
 * createLiveBindingEnforced() remains UNVERIFIED by this test suite —
 * see the Task 6 report's own "MariaDB concurrency verification status"
 * section. A real verification needs an actual MySQL/MariaDB server
 * (e.g. in CI or staging) running this same two-process race against
 * InnoDB, which this sandboxed environment cannot provide.
 */
class InstallationAllowanceConcurrencyTest extends TestCase
{
    /**
     * Deliberately does NOT use RefreshDatabase/the framework's default
     * sqlite :memory: connection — this test builds its own on-disk
     * sqlite file and a fresh two-process race against it, since the
     * whole point is cross-connection contention that :memory: cannot
     * produce.
     */
    public function test_two_concurrent_processes_racing_for_the_final_slot_never_both_succeed(): void
    {
        if (! extension_loaded('pcntl') || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available in this environment — this is the strongest concurrency check this repository can run, and it cannot run without pcntl. MariaDB/MySQL row-lock concurrency for createLiveBindingEnforced() remains UNVERIFIED (see class docblock and the Task 6 report).');
        }

        $dbPath = tempnam(sys_get_temp_dir(), 'task6_concurrency_').'.sqlite';
        touch($dbPath);

        [$accountId, $keyAId, $keyBId] = $this->bootstrapRaceFixture($dbPath);

        $resultFileA = $dbPath.'.result_a';
        $resultFileB = $dbPath.'.result_b';

        $pidA = pcntl_fork();
        if ($pidA === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pidA === 0) {
            // Child A.
            $this->attemptInChildProcess($dbPath, $keyAId, '203.0.113.201', $resultFileA);
            exit(0);
        }

        $pidB = pcntl_fork();
        if ($pidB === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pidB === 0) {
            // Child B.
            $this->attemptInChildProcess($dbPath, $keyBId, '203.0.113.202', $resultFileB);
            exit(0);
        }

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $resultA = file_exists($resultFileA) ? trim(file_get_contents($resultFileA)) : 'missing';
        $resultB = file_exists($resultFileB) ? trim(file_get_contents($resultFileB)) : 'missing';

        @unlink($resultFileA);
        @unlink($resultFileB);
        @unlink($dbPath);

        $outcomes = [$resultA, $resultB];
        $successes = count(array_filter($outcomes, fn ($o) => $o === 'created'));
        $rejections = count(array_filter($outcomes, fn ($o) => $o === 'rejected'));

        $this->assertSame(1, $successes, "Exactly one of the two concurrent attempts must succeed (got: {$resultA} / {$resultB}).");
        $this->assertSame(1, $rejections, "Exactly one of the two concurrent attempts must be rejected for exceeding the allowance (got: {$resultA} / {$resultB}).");
    }

    /** @return array{0: int, 1: int, 2: int} [$accountId, $keyAId, $keyBId] */
    private function bootstrapRaceFixture(string $dbPath): array
    {
        config(['database.connections.task6_race' => [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('task6_race');
        $conn = DB::connection('task6_race');

        // Build only the tables this race actually touches, matching
        // production columns/types exactly rather than running the full
        // migration suite (which assumes the default connection).
        $schema = Schema::connection('task6_race');
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
            $t->timestamps();
        });
        $schema->create('api_key_bindings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('api_key_id');
            $t->string('status');
            $t->unsignedTinyInteger('active_slot')->nullable();
            $t->string('label')->nullable();
            $t->string('ip_policy');
            $t->json('authorized_ips')->nullable();
            $t->string('installation_hash')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['api_key_id', 'active_slot']);
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
            'slug' => 'race-plan', 'label' => 'Race Plan', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Allowance = 1: exactly the final-slot race the task requires.
        $conn->table('plan_entitlements')->insert([
            'plan_id' => $planId, 'capability_id' => $capabilityId, 'usage_limit' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('invoices')->insert([
            'account_id' => $accountId, 'plan_key' => 'race-plan', 'status' => 'paid', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $keyAId = $conn->table('api_keys')->insertGetId([
            'account_id' => $accountId, 'name' => 'A', 'key_prefix' => 'pfx_a', 'key_hash' => hash('sha256', 'a'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $keyBId = $conn->table('api_keys')->insertGetId([
            'account_id' => $accountId, 'name' => 'B', 'key_prefix' => 'pfx_b', 'key_hash' => hash('sha256', 'b'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$accountId, $keyAId, $keyBId];
    }

    /**
     * Runs inside the forked child process: its own fresh sqlite PDO
     * connection to the same on-disk file, then the actual production
     * seam under test. Writes 'created' or 'rejected' to $resultFile —
     * never throws out of the child, so the parent always gets a result
     * file to read even if something unexpected happens.
     */
    private function attemptInChildProcess(string $dbPath, int $keyId, string $ip, string $resultFile): void
    {
        try {
            config(['database.connections.task6_race' => [
                'driver' => 'sqlite',
                'database' => $dbPath,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ]]);
            config(['database.default' => 'task6_race']);
            DB::purge('task6_race');

            // Tiny jitter so both children are, as closely as this
            // environment allows, inside the enforcement seam's
            // transaction at the same moment.
            usleep(random_int(0, 5000));

            $key = ApiKey::on('task6_race')->find($keyId);
            app(ApiKeyBindingService::class)->provision($key, [
                'ip_policy' => 'SINGLE_IP',
                'authorized_ips' => [$ip],
            ]);

            file_put_contents($resultFile, 'created');
        } catch (InstallationAllowanceExceededException $e) {
            file_put_contents($resultFile, 'rejected');
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.$e->getMessage());
        }
    }
}
