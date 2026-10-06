<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ModuleAddonRequest;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "2 of 3 groups used": the client's own custom groups against its paid term's units. */
class GroupUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    public function test_usage_counts_only_the_custom_groups_against_the_paid_units(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'contact_groups']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        ModuleAddonRequest::create([
            'account_id' => $account->id, 'module' => 'contact_groups', 'status' => ModuleAddonRequest::PAID,
            'units' => 3, 'requested_by_user_id' => $user->id, 'term_starts_at' => now(), 'term_ends_at' => now()->addMonth(),
        ]);

        $this->actingAs($user)->getJson('/api/groups')->assertOk()
            ->assertJsonPath('usage.used', 0)
            ->assertJsonPath('usage.limit', 3);

        foreach (['A' => '919876543211', 'B' => '919876543212'] as $name => $phone) {
            $this->actingAs($user)->postJson('/api/groups/create', [
                'name' => $name, 'group_type' => 'internal_segment', 'contacts' => [['phone_number' => $phone]],
            ])->assertCreated();
        }

        $this->actingAs($user)->getJson('/api/groups')->assertOk()
            ->assertJsonPath('usage.used', 2)
            ->assertJsonPath('usage.limit', 3);
    }
}
