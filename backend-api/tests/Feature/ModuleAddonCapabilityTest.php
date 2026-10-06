<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ModuleAddonRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Native WhatsApp groups is a paid CAPABILITY add-on: approval and payment grant the
 * capability for one term, and the term's end revokes it, but only a grant the add-on made.
 */
class ModuleAddonCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    public function test_native_groups_add_on_grants_the_capability_for_one_term_and_revokes_it_at_the_end(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'contact_groups']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');
        $admin = User::factory()->create(['account_id' => null]);
        $admin->assignRole('super_admin');
        $access = app(AccessControlService::class);

        $this->assertFalse($access->canTenant($account, 'whatsapp_groups'));

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'whatsapp_groups', 'units' => 1])->assertCreated();
        $id = ModuleAddonRequest::where('account_id', $account->id)->where('module', 'whatsapp_groups')->value('id');
        $this->actingAs($admin)->postJson("/api/admin/module-addons/{$id}/approve")->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/module-addons/{$id}/record-payment", [
            'amount' => 99, 'method' => 'cash', 'transaction_id' => 'NG-1', 'paid_on' => now()->toDateString(),
        ])->assertOk();

        $this->assertTrue($access->canTenant($account->fresh(), 'whatsapp_groups'), 'the paid term grants the capability');

        ModuleAddonRequest::find($id)->forceFill(['term_ends_at' => now()->subDay()])->save();
        $this->artisan('modules:enforce-addons')->assertSuccessful();

        $this->assertFalse($access->canTenant($account->fresh(), 'whatsapp_groups'), 'the ended term revokes it');
    }

    public function test_a_capability_the_plan_already_includes_cannot_be_requested(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');
        $capability = \App\Models\Capability::where('slug', 'whatsapp_groups')->firstOrFail();
        $account->entitlements()->create(['capability_id' => $capability->id, 'source' => 'plan']);

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'whatsapp_groups', 'units' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'already_enabled');
    }
}
