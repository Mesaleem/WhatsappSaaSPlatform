<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ModuleAddonRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GrantsNativeWhatsAppGroups;
use Tests\TestCase;

/**
 * "2 of 3 groups used": the client's own Native WhatsApp Groups against its paid term's units.
 *
 * [Re-scoped 2026-10-07, disclosed]: this usage figure now counts Native WhatsApp Groups, not
 * internal segment groups -- internal segment groups are free and unlimited (see
 * ContactGroupController's own re-scoping docblock).
 */
class GroupUsageTest extends TestCase
{
    use RefreshDatabase, GrantsNativeWhatsAppGroups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true, 'groups' => []], 200)]);
    }

    public function test_usage_counts_only_the_native_groups_against_the_paid_units(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'contact_groups']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
        $this->grantNativeWhatsAppGroups($account);
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
                'name' => $name, 'group_type' => 'native_wa_group', 'contacts' => [['phone_number' => $phone]],
            ])->assertCreated();
        }

        $this->actingAs($user)->getJson('/api/groups')->assertOk()
            ->assertJsonPath('usage.used', 2)
            ->assertJsonPath('usage.limit', 3);
    }

    public function test_internal_segment_groups_never_count_toward_the_native_group_usage(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'contact_groups']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        ModuleAddonRequest::create([
            'account_id' => $account->id, 'module' => 'contact_groups', 'status' => ModuleAddonRequest::PAID,
            'units' => 3, 'requested_by_user_id' => $user->id, 'term_starts_at' => now(), 'term_ends_at' => now()->addMonth(),
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $this->actingAs($user)->postJson('/api/groups/create', ['name' => "Dept {$i}", 'group_type' => 'internal_segment'])
                ->assertCreated();
        }

        $this->actingAs($user)->getJson('/api/groups')->assertOk()
            ->assertJsonPath('usage.used', 0)
            ->assertJsonPath('usage.limit', 3);
    }
}
