<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ModuleAddonRequest;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A one-month add-on ends one calendar month after its start, also when the start is a month end. */
class ModuleAddonTermTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_monthly_add_on_started_on_the_31st_ends_on_the_last_day_of_next_month(): void
    {
        Carbon::setTestNow('2026-03-31 10:00:00');

        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $admin = User::factory()->create(['account_id' => null]);
        $admin->assignRole('super_admin');

        $id = $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 2])
            ->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson("/api/admin/module-addons/{$id}/approve")->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/module-addons/{$id}/record-payment", [
            'amount' => 99, 'method' => 'cash', 'transaction_id' => 'T-MONTH-END',
            'paid_on' => '2026-03-31', 'term_starts_on' => '2026-03-31',
        ])->assertOk();

        $row = ModuleAddonRequest::query()->findOrFail($id);
        $this->assertSame('2026-03-31', $row->term_starts_at->toDateString());
        $this->assertSame('2026-04-30', $row->term_ends_at->toDateString(), 'one month from 31 March is 30 April, not 1 May');
    }
}
