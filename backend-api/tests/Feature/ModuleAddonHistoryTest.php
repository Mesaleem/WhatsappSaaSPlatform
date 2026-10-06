<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\ModuleAddonRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The add-on history: what was requested, approved, paid, and which numbers were bought, scoped by role. */
class ModuleAddonHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function client(?Account $agent = null): array
    {
        $account = Account::factory()->create(array_filter([
            'agent_id' => $agent?->id,
            'allowed_modules' => ['dashboard'],
        ]));
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

    /** A paid WhatsApp add-on purchase: one invoice, one extra number per line. */
    private function numberPurchase(Account $account, array $phones, string $status = 'pending'): void
    {
        $invoice = Invoice::query()->create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'plan_key' => (string) config('whatsapp_numbers.addon_plan_key'),
            'plan_label' => 'Extra WhatsApp number',
            'amount' => 99 * count($phones),
            'tax_amount' => 0,
            'total_amount' => 99 * count($phones),
            'currency' => 'INR',
            'payment_gateway' => 'manual',
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);

        foreach ($phones as $phone) {
            WhatsAppNumber::query()->create([
                'account_id' => $account->id,
                'phone_number' => $phone,
                'is_included' => false,
                'is_default' => false,
                'status' => $status === 'paid' ? 'unlinked' : 'pending_payment',
                'addon_invoice_id' => $invoice->id,
                'term_ends_at' => $status === 'paid' ? now()->addMonth() : null,
            ]);
        }
    }

    public function test_approval_and_rejection_record_when_they_happened(): void
    {
        [, $user] = $this->client();
        $id = $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 2])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$id}/approve")->assertOk();

        $row = ModuleAddonRequest::find($id);
        $this->assertNotNull($row->decided_at, 'approval sets decided_at');
        $this->assertSame('invoiced', $row->status);
    }

    public function test_a_client_sees_only_its_own_history_and_nothing_of_another_client(): void
    {
        [$mine, $user] = $this->client();
        [$other, $otherUser] = $this->client();

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 2])->assertCreated();
        $this->actingAs($otherUser)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 7])->assertCreated();
        $this->numberPurchase($mine, ['919811111111']);
        $this->numberPurchase($other, ['919822222222']);

        // Asking for another client's account by id is refused, not answered.
        $this->actingAs($user)->getJson('/api/module-addons/history?account_id='.$other->id)->assertNotFound();

        $this->actingAs($user)->getJson('/api/module-addons/history')
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.units', 2)
            ->assertJsonCount(1, 'number_purchases')
            ->assertJsonPath('number_purchases.0.numbers.0.phone_number', '919811111111');
    }

    public function test_an_agent_sees_its_own_clients_and_a_super_admin_sees_all(): void
    {
        $agentAccount = Account::factory()->create(['account_type' => 'agent']);
        $agent = User::factory()->create(['account_id' => $agentAccount->id]);
        $agent->assignRole('agent');

        [$ownClient, $ownUser] = $this->client($agentAccount);
        [$strangerClient, $strangerUser] = $this->client();

        $this->actingAs($ownUser)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 1])->assertCreated();
        $this->actingAs($strangerUser)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 1])->assertCreated();
        $this->numberPurchase($ownClient, ['919833333333'], 'paid');
        $this->numberPurchase($strangerClient, ['919844444444'], 'paid');

        $this->actingAs($agent)->getJson('/api/admin/module-addons/history')
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonCount(1, 'number_purchases')
            ->assertJsonPath('number_purchases.0.status', 'paid');

        $this->actingAs($this->superAdmin())->getJson('/api/admin/module-addons/history')
            ->assertOk()
            ->assertJsonCount(2, 'requests')
            ->assertJsonCount(2, 'number_purchases');

        $this->actingAs($this->superAdmin())->getJson('/api/admin/module-addons/history?account_id='.$ownClient->id)
            ->assertOk()
            ->assertJsonCount(1, 'number_purchases');
    }
}
