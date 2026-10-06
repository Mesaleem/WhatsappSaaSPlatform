<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Capability;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The API key comes with the plan (external_api capability). It does not depend on the module list,
 * and an expired plan keeps the key while sending from it is refused.
 */
class ApiKeyPlanAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function client(bool $planIncludesApi, string $subscriptionStatus = 'active'): User
    {
        // The Developer API module is deliberately NOT in the list: the plan alone decides.
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create([
            'engine_type' => 'qr',
            'status' => $subscriptionStatus,
            'expires_at' => $subscriptionStatus === 'active' ? now()->addMonth() : now()->subDay(),
        ]);

        if ($planIncludesApi) {
            $capability = Capability::query()->where('slug', 'external_api')->firstOrFail();
            $account->entitlements()->create(['capability_id' => $capability->id, 'source' => 'plan']);
        }

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_a_plan_with_the_api_gets_the_key_without_the_module_switch(): void
    {
        $user = $this->client(true);

        $this->actingAs($user)->getJson('/api/account/api-key')->assertOk();
        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.account.api_access', true)
            ->assertJsonPath('user.account.subscription_active', true);
    }

    public function test_buying_a_plan_gives_the_key_with_no_extra_entitlement_row(): void
    {
        // No external_api row at all: the plan alone is enough.
        $user = $this->client(false);

        $this->actingAs($user)->getJson('/api/account/api-key')->assertOk();
        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.account.api_access', true);
    }

    public function test_an_account_that_never_bought_a_plan_is_refused_the_key(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $this->actingAs($user)->getJson('/api/account/api-key')->assertForbidden();
        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.account.api_access', false);
    }

    public function test_an_expired_plan_keeps_the_key_visible_and_reports_it_inactive(): void
    {
        $user = $this->client(true, 'expired');

        $this->actingAs($user)->getJson('/api/account/api-key')->assertOk();
        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.account.api_access', true)
            ->assertJsonPath('user.account.subscription_active', false);
    }
}
