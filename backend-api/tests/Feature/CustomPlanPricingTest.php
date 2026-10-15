<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\Billing\CustomPlanPricing;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Super Admin's custom-plan price list behind the Plans price calculator (2026-10-15). */
class CustomPlanPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_the_defaults_are_served_until_changed(): void
    {
        $this->actingAs($this->superAdmin())->getJson('/api/admin/custom-plan-pricing')->assertOk()
            ->assertJsonPath('data.base_fee', 299)
            ->assertJsonPath('data.features.crm', 500)
            ->assertJsonPath('data.engine.meta', 499)
            ->assertJsonPath('data.meta_marketing_cost', 0.8631);
    }

    public function test_a_partial_change_keeps_the_other_prices_and_ignores_unknown_keys(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson('/api/admin/custom-plan-pricing', [
            'base_fee' => 399, 'features' => ['crm' => 700, 'industry_education' => 450], 'engine' => ['qr' => 350], 'nope' => 5,
        ])->assertOk()->assertJsonPath('data.base_fee', 399);

        $prices = CustomPlanPricing::get();
        $this->assertSame([399.0, 700.0, 450.0, 300.0, 350.0, 499.0], [
            $prices['base_fee'], $prices['features']['crm'], $prices['features']['industry_education'],
            $prices['features']['journey_automation'], $prices['engine']['qr'], $prices['engine']['meta'],
        ]);
        $this->assertArrayNotHasKey('nope', $prices);
    }

    public function test_only_the_super_admin_and_only_valid_numbers(): void
    {
        $client = Account::factory()->create(['account_type' => 'client']);
        $admin = User::factory()->create(['account_id' => $client->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->getJson('/api/admin/custom-plan-pricing')->assertForbidden();
        $this->actingAs($this->superAdmin())->putJson('/api/admin/custom-plan-pricing', ['per_user' => -5, 'gst_percent' => 150, 'features' => ['crm' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['per_user', 'gst_percent', 'features.crm']);
    }
}
