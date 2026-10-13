<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\Access\AccessControlService;
use App\Services\Crm\PlatformCrmAccount;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule (2026-10-13): Super Admin has no restriction in any module. With no client
 * selected it works on its own "Platform (Super Admin)" account, which holds every
 * capability and an unlimited subscription.
 */
class SuperAdminOwnAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Account $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        $this->superAdmin = User::factory()->create(['account_id' => null]);
        $this->superAdmin->assignRole('super_admin');
        $this->platform = app(PlatformCrmAccount::class)->ensure()['account'];
    }

    public function test_the_platform_account_has_every_capability_and_an_unlimited_subscription(): void
    {
        $this->assertTrue(app(AccessControlService::class)->canTenant($this->platform, 'whatsapp_groups'));
        $this->assertTrue(app(AccessControlService::class)->canTenant($this->platform, 'journey_automation'));
        $this->assertTrue($this->platform->fresh()->hasActiveSubscription());
        $this->assertNull($this->platform->fresh()->currentSubscription->remainingQuota(), 'no message cap');
    }

    public function test_a_super_admin_with_no_client_selected_creates_its_own_custom_group(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/groups/create', ['name' => 'Team promo', 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL])
            ->assertCreated();

        $this->assertSame($this->platform->id, ContactGroup::where('name', 'Team promo')->value('account_id'));
    }

    public function test_a_selected_client_still_gets_the_record(): void
    {
        $client = Account::factory()->create();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/groups/create?account_id='.$client->id, ['name' => 'Client list', 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL])
            ->assertCreated();

        $this->assertSame($client->id, ContactGroup::where('name', 'Client list')->value('account_id'));
    }

    public function test_the_super_admin_adds_its_own_numbers_free_and_ready_to_connect(): void
    {
        $this->actingAs($this->superAdmin)->postJson('/api/whatsapp/numbers', ['phone_number' => '919800000001'])->assertCreated();
        $this->actingAs($this->superAdmin)
            ->postJson('/api/whatsapp/numbers/purchase', ['phone_numbers' => ['919800000002']])
            ->assertCreated()
            ->assertJsonPath('invoice', null);

        $slots = WhatsAppNumber::where('account_id', $this->platform->id)->orderBy('id')->get();
        $this->assertSame(['919800000001', '919800000002'], $slots->pluck('phone_number')->all());
        $this->assertSame([WhatsAppNumber::STATUS_UNLINKED, WhatsAppNumber::STATUS_UNLINKED], $slots->pluck('status')->all());
    }

    public function test_a_client_user_never_falls_back_to_the_platform_account(): void
    {
        $client = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $client->id]);
        $user->assignRole('admin');

        $this->actingAs($user)->postJson('/api/groups/create', ['name' => 'Mine', 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL]);

        $this->assertSame(0, ContactGroup::where('account_id', $this->platform->id)->count());
    }
}
