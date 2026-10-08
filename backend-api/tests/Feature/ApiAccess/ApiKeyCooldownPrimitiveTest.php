<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Services\ApiAccess\ApiKeyBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 6 — required tests #23–26: the per-key cooldown STORAGE
 * primitive only (api_keys.cooldown_until + ApiKeyBindingService::
 * setCooldown()/clearCooldown() + ApiKey::isInCooldown()). Deliberately
 * does NOT test any cooldown POLICY (when it gets set automatically, or
 * whether gate()/the creation seam consult it) — that wiring is
 * explicitly Task 9's job, not this one's; nothing in this task's own
 * code calls setCooldown() from any lifecycle path.
 */
class ApiKeyCooldownPrimitiveTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function makeKey(Account $account): ApiKey
    {
        $plain = 'wasaas_live_'.Str::random(40);

        return ApiKey::create([
            'account_id' => $account->id,
            'name' => 'Key',
            'key_prefix' => substr($plain, 0, 20),
            'key_hash' => ApiKey::hashKey($plain),
        ]);
    }

    /** 23. cooldown_until is nullable -- a freshly created key has no cooldown by default. */
    public function test_cooldown_until_is_nullable_and_defaults_to_null(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);

        $this->assertNull($key->cooldown_until);
        $this->assertFalse($key->isInCooldown());
    }

    /** 24. A set cooldown value is persisted per API key and read back correctly (datetime cast). */
    public function test_cooldown_value_is_persisted_and_read_back(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);
        $until = now()->addHours(6);

        $this->service()->setCooldown($key, $until);

        $fresh = $key->fresh();
        $this->assertNotNull($fresh->cooldown_until);
        $this->assertSame($until->toDateTimeString(), $fresh->cooldown_until->toDateTimeString());
        $this->assertTrue($fresh->isInCooldown());

        $this->service()->clearCooldown($fresh);
        $this->assertNull($fresh->fresh()->cooldown_until);
        $this->assertFalse($fresh->fresh()->isInCooldown());
    }

    /** 25. Key A's cooldown must never affect Key B, even for the same account. */
    public function test_one_keys_cooldown_does_not_affect_another_key(): void
    {
        $account = Account::factory()->create();
        $keyA = $this->makeKey($account);
        $keyB = $this->makeKey($account);

        $this->service()->setCooldown($keyA, now()->addDays(1));

        $this->assertTrue($keyA->fresh()->isInCooldown());
        $this->assertNull($keyB->fresh()->cooldown_until);
        $this->assertFalse($keyB->fresh()->isInCooldown());
    }

    /** 26. The cooldown column lives on api_keys only -- api_key_bindings (including historical/legacy rows) has no such column. */
    public function test_cooldown_column_does_not_exist_on_api_key_bindings(): void
    {
        $this->assertTrue(Schema::hasColumn('api_keys', 'cooldown_until'));
        $this->assertFalse(Schema::hasColumn('api_key_bindings', 'cooldown_until'));
    }

    /** A cooldown in the past is not "in cooldown" -- isInCooldown() checks isFuture(), not mere presence of a value. */
    public function test_a_past_cooldown_timestamp_is_not_considered_active(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account);

        $this->service()->setCooldown($key, now()->subMinute());

        $this->assertFalse($key->fresh()->isInCooldown());
    }
}
