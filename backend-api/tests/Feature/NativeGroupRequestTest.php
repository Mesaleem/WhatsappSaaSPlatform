<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\ModuleAddonRequest;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Native WhatsApp Groups: the client says how many groups it needs, and that count sets the price. */
class NativeGroupRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function client(): User
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_a_native_group_request_needs_the_number_of_groups(): void
    {
        $this->actingAs($this->client())->postJson('/api/module-addons/request', ['module' => 'whatsapp_groups'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'units_required');
    }

    public function test_the_count_sets_the_tier_price_for_native_groups(): void
    {
        $user = $this->client();
        $id = $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'whatsapp_groups', 'units' => 3])
            ->assertCreated()->json('data.id');

        $admin = User::factory()->create(['account_id' => null]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin)->postJson("/api/admin/module-addons/{$id}/approve")->assertOk();

        $invoice = Invoice::query()->findOrFail(ModuleAddonRequest::query()->findOrFail($id)->invoice_id);
        $this->assertSame(99.0, (float) $invoice->total_amount, '1 to 5 groups is the first tier');
    }
}
