<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
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

/** Native WhatsApp Groups is priced by how many groups the client needs (tiers set by Super Admin). */
class ModuleAddonTierTest extends TestCase
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

    private function client(?Account $agent = null): array
    {
        $account = $agent
            ? Account::factory()->create(['agent_id' => $agent->id, 'allowed_modules' => ['dashboard']])
            : Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_a_tiered_request_needs_the_number_of_units(): void
    {
        [, $user] = $this->client();

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'units_required');
    }

    public function test_the_price_follows_the_tier_the_units_fall_in(): void
    {
        [, $small] = $this->client();
        [, $large] = $this->client();

        $smallId = $this->actingAs($small)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3])
            ->assertCreated()->json('data.id');
        $largeId = $this->actingAs($large)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 8])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$smallId}/approve")->assertOk();
        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$largeId}/approve")->assertOk();

        $this->assertSame(99.0, (float) Invoice::find(ModuleAddonRequest::find($smallId)->invoice_id)->total_amount, '1 to 5 groups is ₹99');
        $this->assertSame(199.0, (float) Invoice::find(ModuleAddonRequest::find($largeId)->invoice_id)->total_amount, '6 or more groups is ₹199');
    }

    public function test_the_group_limit_is_the_units_of_the_paid_term(): void
    {
        // Native WhatsApp Groups needs the `whatsapp_groups` capability too -- the paid term here
        // only narrows the COUNT, it is not what turns the feature on at all (see
        // CustomGroupAccessService's own re-scoping docblock).
        [$account, $user] = $this->client();
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
        $this->grantNativeWhatsAppGroups($account);

        $id = $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 2])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$id}/approve")->assertOk();
        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$id}/record-payment", [
            'amount' => 99, 'method' => 'cash', 'transaction_id' => 'G-2', 'paid_on' => now()->toDateString(),
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/groups/create', ['name' => 'A', 'group_type' => 'native_wa_group', 'contacts' => [['phone_number' => '919876543210']]])->assertCreated();
        $this->actingAs($user)->postJson('/api/groups/create', ['name' => 'B', 'group_type' => 'native_wa_group', 'contacts' => [['phone_number' => '919876543211']]])->assertCreated();
        $this->actingAs($user)->postJson('/api/groups/create', ['name' => 'C', 'group_type' => 'native_wa_group', 'contacts' => [['phone_number' => '919876543212']]])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'group_limit_reached');
    }

    public function test_super_admin_sets_the_tiers_and_overlapping_tiers_are_refused(): void
    {
        [, $user] = $this->client();

        $this->actingAs($this->superAdmin())->putJson('/api/admin/module-offers/contact_groups/tiers', [
            'tiers' => [
                ['from_units' => 1, 'to_units' => 10, 'price' => 149],
                ['from_units' => 11, 'to_units' => null, 'price' => 249],
            ],
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/module-addons')
            ->assertOk()
            ->assertJsonPath('offers.0.tiers.0.to_units', 10)
            ->assertJsonPath('offers.0.tiers.0.price', 149);

        $this->actingAs($this->superAdmin())->putJson('/api/admin/module-offers/contact_groups/tiers', [
            'tiers' => [
                ['from_units' => 1, 'to_units' => 10, 'price' => 149],
                ['from_units' => 5, 'to_units' => null, 'price' => 249],
            ],
        ])->assertUnprocessable()->assertJsonPath('error_code', 'overlapping_tiers');
    }

    public function test_a_free_tier_starts_on_approval_without_any_payment(): void
    {
        $this->actingAs($this->superAdmin())->putJson('/api/admin/module-offers/contact_groups/tiers', [
            'tiers' => [
                ['from_units' => 1, 'to_units' => 5, 'price' => 0],
                ['from_units' => 6, 'to_units' => null, 'price' => 199],
            ],
        ])->assertOk();

        [$account, $user] = $this->client();
        $id = $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 2])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$id}/approve")->assertOk();

        $row = ModuleAddonRequest::query()->findOrFail($id);
        $this->assertSame(ModuleAddonRequest::PAID, $row->status, 'a free add-on is on at once');
        $this->assertNotNull($row->term_ends_at);
        $this->assertContains('contact_groups', $account->fresh()->allowed_modules);
        $this->assertSame('paid', Invoice::query()->findOrFail($row->invoice_id)->status);
    }

    public function test_an_agent_cannot_change_the_tiers(): void
    {
        $agentAccount = Account::factory()->create(['account_type' => 'agent']);
        $agent = User::factory()->create(['account_id' => $agentAccount->id]);
        $agent->assignRole('agent');

        $this->actingAs($agent)->putJson('/api/admin/module-offers/contact_groups/tiers', [
            'tiers' => [['from_units' => 1, 'to_units' => null, 'price' => 1]],
        ])->assertForbidden();
    }
}
