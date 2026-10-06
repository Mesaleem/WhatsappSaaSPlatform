<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\WhatsApp\WhatsAppAddonService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The extra WhatsApp number price is set by the Super Admin; issued invoices keep their price. */
class WhatsAppNumberPriceTest extends TestCase
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

    public function test_the_super_admin_changes_the_price_and_only_new_purchases_use_it(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'whatsapp_setup']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        WhatsAppNumber::query()->create([
            'account_id' => $account->id, 'phone_number' => '919800000001', 'is_included' => true,
            'is_default' => true, 'status' => WhatsAppNumber::STATUS_UNLINKED,
        ]);

        $before = app(WhatsAppAddonService::class)->purchase($account, ['919800000002']);
        $this->assertSame(99.0, (float) $before->total_amount);

        $this->actingAs($this->superAdmin())->putJson('/api/admin/whatsapp/addon-price', ['price' => 149])
            ->assertOk()->assertJsonPath('price', 149);

        $after = app(WhatsAppAddonService::class)->purchase($account, ['919800000003']);
        $this->assertSame(149.0, (float) $after->total_amount, 'a new purchase uses the new price');
        $this->assertSame(99.0, (float) Invoice::query()->findOrFail($before->id)->total_amount, 'an issued invoice keeps its price');
    }

    public function test_an_agent_or_a_client_cannot_change_the_price(): void
    {
        $client = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $client->id]);
        $user->assignRole('admin');

        $this->actingAs($user)->putJson('/api/admin/whatsapp/addon-price', ['price' => 1])->assertForbidden();
    }
}
