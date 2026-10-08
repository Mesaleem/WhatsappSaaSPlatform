<?php

namespace Tests\Feature\ApiAccess;

use App\Http\Controllers\Api\ApiKeyController;
use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 6 — requirement #8 / required tests #19–22.
 *
 * ApiKeyController::regenerateSecret() must remain completely
 * binding-neutral: it only ever touches api_keys.secret_prefix/
 * secret_hash. Calls the controller method directly (no HTTP
 * kernel/middleware) — regenerateSecret() needs only ResolvesTenantAccount's
 * requireAccount(), which reads the 'account_id' request attribute that
 * TenantIsolationMiddleware would normally set; this test sets it
 * directly, same as calling the real action with that attribute already
 * resolved. This keeps the test focused purely on the method's own
 * logic, independent of route middleware (permission/module guards),
 * which this task does not touch.
 */
class RegenerateSecretBindingNeutralityTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function controller(): ApiKeyController
    {
        return app(ApiKeyController::class);
    }

    private function requestFor(Account $account, User $user): Request
    {
        $request = Request::create('/api/developer/api-keys/1/regenerate-secret', 'POST');
        $request->attributes->set('account_id', $account->id);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function makeKey(Account $account): ApiKey
    {
        $plain = 'wasaas_live_'.Str::random(40);

        return ApiKey::create([
            'account_id' => $account->id,
            'name' => 'Key',
            'key_prefix' => substr($plain, 0, 20),
            'key_hash' => ApiKey::hashKey($plain),
            'secret_prefix' => 'wasaas_secret_'.Str::random(6),
            'secret_hash' => ApiKey::hashSecret('wasaas_secret_'.Str::random(40)),
        ]);
    }

    /** 19. Regenerating the secret must not create a new installation / touch the binding row at all. */
    public function test_regenerate_secret_does_not_alter_the_binding(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $key = $this->makeKey($account);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);
        $bindingBefore = $key->fresh()->liveBinding();

        $this->controller()->regenerateSecret($this->requestFor($account, $user), $key->id);

        $bindingAfter = $key->fresh()->liveBinding();
        $this->assertSame($bindingBefore->id, $bindingAfter->id);
        $this->assertSame($bindingBefore->status, $bindingAfter->status);
        $this->assertSame($bindingBefore->installation_hash, $bindingAfter->installation_hash);
        $this->assertSame($bindingBefore->active_slot, $bindingAfter->active_slot);
        $this->assertSame($bindingBefore->updated_at->toDateTimeString(), $bindingAfter->updated_at->toDateTimeString());
    }

    /** 20. Regenerating the secret must not alter the account's live installation count. */
    public function test_regenerate_secret_does_not_alter_installation_count(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $key = $this->makeKey($account);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);

        $before = $this->service()->countLiveInstallations($account->id);
        $this->controller()->regenerateSecret($this->requestFor($account, $user), $key->id);
        $after = $this->service()->countLiveInstallations($account->id);

        $this->assertSame($before, $after);
        $this->assertSame(1, $after);
        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->count(), 'no second binding row created.');
    }

    /** 21. Regenerating the secret must not reset/alter the installation credential (installation_hash on the binding). */
    public function test_regenerate_secret_does_not_alter_the_installation_credential(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $key = $this->makeKey($account);
        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);
        $hashBefore = $key->fresh()->liveBinding()->installation_hash;

        $this->controller()->regenerateSecret($this->requestFor($account, $user), $key->id);

        $this->assertSame($hashBefore, $key->fresh()->liveBinding()->installation_hash);
        // The installation credential minted at provision() time must still verify.
        $this->assertTrue($key->fresh()->liveBinding()->verifyCredential($provisioned['credential']));
    }

    /** 22. Regenerating the secret must not modify cooldown_until or any legacy-binding state. */
    public function test_regenerate_secret_does_not_alter_cooldown_or_legacy_state(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $key = $this->makeKey($account);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);

        $cooldownUntil = now()->addDays(3);
        $this->service()->setCooldown($key->fresh(), $cooldownUntil);
        $graceExpires = now()->addDays(10);
        $key->fresh()->forceFill(['legacy_binding_grace_expires_at' => $graceExpires])->save();

        $this->controller()->regenerateSecret($this->requestFor($account, $user), $key->id);

        $fresh = $key->fresh();
        $this->assertSame($cooldownUntil->toDateTimeString(), $fresh->cooldown_until->toDateTimeString());
        $this->assertSame($graceExpires->toDateTimeString(), $fresh->legacy_binding_grace_expires_at->toDateTimeString());
    }
}
