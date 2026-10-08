<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 4 Task 4 — binding credential enforcement foundation.
 *
 * Audit found the credential LIFECYCLE (mint on provision/approve/
 * rebind, re-mint via issueCredential(), store only a sha256 hash) was
 * already fully implemented, but the VERIFICATION half did not exist
 * anywhere: gate() never reads X-Client-Installation or compares
 * installation_hash at all (confirmed by reading AuthenticateApiKey and
 * ApiKeyBindingService::gate() — it only checks
 * Account.authorized_server_ip), and last_success_at/last_success_ip/
 * last_success_client were declared fillable/cast but never written by
 * any code path. This task adds ApiKeyBinding::verifyCredential() and
 * ::recordSuccessfulUse() — the primitive Task 7's gate() rewrite will
 * call — without touching gate() itself or any decision-tree logic.
 *
 * Does NOT test gate()/authentication wiring — Task 7 has since wired
 * gate() to call verifyCredential()/recordSuccessfulUse() (see
 * InstallationBindingAuthenticationTest.php); the sentence above
 * describing gate() as never reading X-Client-Installation describes
 * this task's OWN starting state, not the current one. Also not here:
 * quota (Task 6), legacy backfill (Task 5).
 */
class ApiKeyBindingCredentialTest extends TestCase
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

    /** Credential is generated/stored hashed; plaintext is never persisted. */
    public function test_credential_is_generated_and_stored_only_as_a_sha256_hash(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);

        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $plaintext = $provisioned['credential'];
        $binding = $provisioned['binding']->fresh();

        $this->assertNotNull($binding->installation_hash);
        $this->assertSame(64, strlen($binding->installation_hash), 'sha256 hex digest is 64 chars.');
        $this->assertSame(hash('sha256', $plaintext), $binding->installation_hash);
        $this->assertNotSame($plaintext, $binding->installation_hash);

        // Nowhere in the row (not even the hidden column, read raw) is the plaintext stored verbatim.
        $row = DB::table('api_key_bindings')->where('id', $binding->id)->first();
        foreach ((array) $row as $column => $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString($plaintext, $value, "column {$column} must never contain the plaintext credential.");
            }
        }
        // The prefix is a deliberate, disclosed exception: 20 chars of the plaintext, for display only — not the full secret.
        $this->assertSame(substr($plaintext, 0, 20), $binding->installation_prefix);
    }

    /** Correct credential verifies against the correct binding. */
    public function test_the_correct_credential_verifies_against_its_own_binding(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);

        $this->assertTrue($provisioned['binding']->fresh()->verifyCredential($provisioned['credential']));
    }

    /** Wrong credential fails. */
    public function test_a_wrong_credential_fails_verification(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);

        $this->assertFalse($provisioned['binding']->fresh()->verifyCredential('wasaas_inst_totally-wrong-credential'));
    }

    /** A credential-pending binding (no hash yet) never verifies — never a bypass, just nothing to compare against. */
    public function test_a_credential_pending_binding_never_verifies_any_credential(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        // approve()/rebind() always mint with credential: null — reproduced
        // directly here via rebind(), the simplest real path that does this.
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $rebound = $this->service()->rebind($key->fresh(), $this->admin(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.20']]);

        $this->assertTrue($rebound->isLive());
        $this->assertFalse($rebound->hasCredential(), 'rebind() mints with credential: null — this IS the credential_pending state.');
        $this->assertFalse($rebound->verifyCredential('anything-at-all'), 'nothing to compare against must mean false, never true.');
        $this->assertFalse($rebound->verifyCredential(''), 'even an empty string must not verify.');
    }

    /** Key A's credential must never authenticate Key B. */
    public function test_key_as_credential_cannot_authenticate_key_bs_binding(): void
    {
        $account = Account::factory()->create();
        $keyA = $this->makeKey($account, 'Key A');
        $keyB = $this->makeKey($account, 'Key B');

        $provisionedA = $this->service()->provision($keyA, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $provisionedB = $this->service()->provision($keyB, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.11']]);

        $this->assertFalse($provisionedB['binding']->fresh()->verifyCredential($provisionedA['credential']));
        $this->assertFalse($provisionedA['binding']->fresh()->verifyCredential($provisionedB['credential']));
        // Each still verifies against its own.
        $this->assertTrue($provisionedA['binding']->fresh()->verifyCredential($provisionedA['credential']));
        $this->assertTrue($provisionedB['binding']->fresh()->verifyCredential($provisionedB['credential']));
    }

    /** Rotation invalidates the old credential and accepts the new one, without creating a second live binding. */
    public function test_credential_rotation_invalidates_the_old_credential_accepts_the_new_one_and_creates_no_second_binding(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        // provision() always mints a credential at creation time, regardless
        // of status (pending or active) - capture it, then rotate.
        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $oldCredential = $provisioned['credential'];
        $binding = $provisioned['binding'];

        $newCredential = $this->service()->issueCredential($key->fresh(), $binding->fresh());

        $this->assertNotSame($oldCredential, $newCredential);
        $this->assertFalse($binding->fresh()->verifyCredential($oldCredential), 'the old credential must stop working after rotation.');
        $this->assertTrue($binding->fresh()->verifyCredential($newCredential), 'the new credential must work after rotation.');

        // Still exactly one binding row for this key — rotation must mutate in place, never create a second.
        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->count());
        $this->assertSame($binding->id, $key->fresh()->liveBinding()->id);
    }

    /** issueCredential()'s existing "already in use" guard, now actually reachable via recordSuccessfulUse(). */
    public function test_issuing_a_new_credential_after_a_recorded_successful_use_is_rejected(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $binding = $provisioned['binding']->fresh();

        $binding->recordSuccessfulUse('203.0.113.10');

        $fresh = $binding->fresh();
        $this->assertNotNull($fresh->last_success_at);
        $this->assertSame('203.0.113.10', $fresh->last_success_ip);
        $this->assertSame($fresh->installation_prefix, $fresh->last_success_client);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->issueCredential($key->fresh(), $fresh);
    }

    /** Before any recorded use, rotation is still allowed even though a credential already exists (an unused credential is freely replaceable). */
    public function test_an_unused_credential_can_still_be_rotated(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $binding = $provisioned['binding']->fresh();

        $this->assertTrue($binding->hasCredential());
        $this->assertNull($binding->last_success_at);

        $newCredential = $this->service()->issueCredential($key->fresh(), $binding);
        $this->assertTrue($binding->fresh()->verifyCredential($newCredential));
    }
}
