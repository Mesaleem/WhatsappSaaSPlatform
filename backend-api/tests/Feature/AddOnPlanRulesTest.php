<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\WhatsApp\WhatsAppAddonService;
use App\Services\WhatsApp\WhatsAppNumberException;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Add-ons are bought while the plan is active. A plan that has lapsed renews first. A paused extra number
 * (its term ended) is reused when the same number is bought again.
 */
class AddOnPlanRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function account(string $status): array
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'whatsapp_setup', 'contact_groups']]);
        Subscription::factory()->for($account)->create([
            'engine_type' => 'qr',
            'status' => $status,
            'expires_at' => $status === 'active' ? now()->addMonth() : now()->subDay(),
        ]);
        WhatsAppNumber::query()->create([
            'account_id' => $account->id, 'phone_number' => '919800000001', 'is_included' => true,
            'is_default' => true, 'status' => WhatsAppNumber::STATUS_UNLINKED,
        ]);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user];
    }

    public function test_a_lapsed_plan_cannot_buy_an_extra_number(): void
    {
        [$account] = $this->account('expired');

        try {
            app(WhatsAppAddonService::class)->purchase($account, ['919800000002']);
            $this->fail('a lapsed plan must not buy a number');
        } catch (WhatsAppNumberException $e) {
            $this->assertSame('plan_not_active', $e->errorCode);
        }
    }

    public function test_a_lapsed_plan_cannot_request_a_group_add_on(): void
    {
        [, $user] = $this->account('expired');

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'plan_not_active');
    }

    public function test_an_active_plan_can_buy_an_extra_number(): void
    {
        [$account] = $this->account('active');

        $invoice = app(WhatsAppAddonService::class)->purchase($account, ['919800000002']);

        $this->assertSame('pending', $invoice->status);
    }

    public function test_a_paused_number_is_reused_when_bought_again(): void
    {
        [$account] = $this->account('active');

        $paused = WhatsAppNumber::query()->create([
            'account_id' => $account->id, 'phone_number' => '919800000003', 'is_included' => false,
            'is_default' => false, 'status' => WhatsAppNumber::STATUS_PAUSED, 'term_ends_at' => now()->subDay(),
        ]);

        $invoice = app(WhatsAppAddonService::class)->purchase($account, ['919800000003']);

        $paused->refresh();
        $this->assertSame($invoice->id, $paused->addon_invoice_id);
        $this->assertSame(WhatsAppNumber::STATUS_PENDING_PAYMENT, $paused->status);
        $this->assertSame(1, WhatsAppNumber::query()->where('phone_number', '919800000003')->count(), 'the same slot is reused, not duplicated');
    }
}
