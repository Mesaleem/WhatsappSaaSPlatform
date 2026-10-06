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

/** The offer price is changed by Super Admin: new approvals use it, issued invoices keep theirs. */
class ModuleAddonPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function client(): array
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
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

    public function test_super_admin_changes_the_price_and_new_approvals_use_it_while_issued_invoices_keep_theirs(): void
    {
        [, $first] = $this->client();
        $firstRequest = $this->actingAs($first)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3])->assertCreated()->json('data.id');
        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$firstRequest}/approve")->assertOk();
        $firstInvoice = Invoice::findOrFail(ModuleAddonRequest::find($firstRequest)->invoice_id);

        // Super Admin raises the 1 to 5 group tier to ₹150. Groups are priced by tier, not the flat price.
        $this->actingAs($this->superAdmin())->putJson('/api/admin/module-offers/contact_groups/tiers', [
            'tiers' => [
                ['from_units' => 1, 'to_units' => 5, 'price' => 150],
                ['from_units' => 6, 'to_units' => null, 'price' => 199],
            ],
        ])->assertOk();

        [, $second] = $this->client();
        $secondRequest = $this->actingAs($second)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3])->assertCreated()->json('data.id');
        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$secondRequest}/approve")->assertOk();
        $secondInvoice = Invoice::findOrFail(ModuleAddonRequest::find($secondRequest)->invoice_id);

        $this->assertSame(150.0, (float) $secondInvoice->total_amount);
        $this->assertSame(99.0, (float) $firstInvoice->fresh()->total_amount, 'an issued invoice keeps its price');
        $this->assertStringContainsString('up to 3 group chats', $secondInvoice->lineItems->first()->description);
    }

    public function test_a_paid_term_limits_the_account_to_its_included_groups_and_no_term_means_no_add_on_limit(): void
    {
        [$account, $user] = $this->client();
        $body = ['name' => 'G', 'group_type' => 'internal_segment', 'contacts' => [['phone_number' => '919876543210']]];

        // Module on (by the plan or the Super Admin) and no paid term: no add-on limit applies.
        $account->forceFill(['allowed_modules' => ['dashboard', 'contact_groups']])->save();
        $this->actingAs($user)->postJson('/api/groups/create', $body)->assertCreated();

        ModuleAddonRequest::create([
            'account_id' => $account->id, 'module' => 'contact_groups', 'status' => ModuleAddonRequest::PAID,
            'requested_by_user_id' => $user->id, 'term_starts_at' => now(), 'term_ends_at' => now()->addWeek(),
        ]);

        // One group exists; the term allows five in total, so four more are fine and a sixth is not.
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user)->postJson('/api/groups/create', ['name' => "G{$i}", 'group_type' => 'internal_segment', 'contacts' => [['phone_number' => '91987654321'.$i]]])->assertCreated();
        }
        $this->actingAs($user)->postJson('/api/groups/create', ['name' => 'Sixth', 'group_type' => 'internal_segment', 'contacts' => [['phone_number' => '919000000009']]])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'group_limit_reached');
    }

    public function test_when_a_term_ends_the_module_is_switched_off_and_a_renewal_invoice_is_issued_at_the_current_price(): void
    {
        [$account, $user] = $this->client();
        $account->forceFill(['allowed_modules' => ['dashboard', 'contact_groups']])->save();
        $ended = ModuleAddonRequest::create([
            'account_id' => $account->id, 'module' => 'contact_groups', 'status' => ModuleAddonRequest::PAID,
            'requested_by_user_id' => $user->id, 'term_starts_at' => now()->subMonth()->subDay(), 'term_ends_at' => now()->subDay(),
        ]);

        $this->actingAs($this->superAdmin())->putJson('/api/admin/module-offers/contact_groups', [
            'label' => 'Custom Contact Groups', 'price' => 110, 'term_months' => 1, 'units_included' => 5, 'is_active' => true,
        ])->assertOk();

        $this->artisan('modules:enforce-addons')->assertSuccessful();

        $this->assertNotContains('contact_groups', $account->fresh()->allowed_modules);
        $renewal = ModuleAddonRequest::where('account_id', $account->id)->where('status', ModuleAddonRequest::INVOICED)->firstOrFail();
        $this->assertNotSame($ended->id, $renewal->id);
        $this->assertSame(110.0, (float) Invoice::find($renewal->invoice_id)->total_amount);
        $this->assertSame('pending', Invoice::find($renewal->invoice_id)->status);
    }
}
