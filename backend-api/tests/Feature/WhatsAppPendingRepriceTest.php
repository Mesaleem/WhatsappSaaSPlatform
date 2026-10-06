<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Subscription;
use App\Models\WhatsAppNumber;
use App\Services\WhatsApp\WhatsAppAddonService;
use App\Services\WhatsApp\WhatsAppNumberService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Removing a number from an unpaid purchase reprices its invoice to the numbers that remain. */
class WhatsAppPendingRepriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function accountWithPlanNumber(): Account
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'whatsapp_setup']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        WhatsAppNumber::query()->create([
            'account_id' => $account->id,
            'phone_number' => '919800000001',
            'is_included' => true,
            'is_default' => true,
            'status' => WhatsAppNumber::STATUS_UNLINKED,
        ]);

        return $account;
    }

    public function test_removing_one_of_two_pending_numbers_reprices_the_invoice_to_one_number(): void
    {
        $account = $this->accountWithPlanNumber();
        $invoice = app(WhatsAppAddonService::class)->purchase($account, ['919800000002', '919800000003']);
        $this->assertSame(198.0, (float) $invoice->fresh()->total_amount);

        $removeId = WhatsAppNumber::query()->where('phone_number', '919800000003')->value('id');
        app(WhatsAppNumberService::class)->remove($account, $removeId);

        $invoice->refresh();
        $this->assertSame('pending', $invoice->status);
        $this->assertSame(99.0, (float) $invoice->total_amount, 'one number left is one number\'s price');
        $this->assertSame(99.0, (float) $invoice->amount);
        $this->assertSame(1, InvoiceLineItem::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame('WhatsApp extra number', $invoice->plan_label);
    }

    public function test_removing_the_last_pending_number_cancels_the_purchase(): void
    {
        $account = $this->accountWithPlanNumber();
        $invoice = app(WhatsAppAddonService::class)->purchase($account, ['919800000002']);

        $removeId = WhatsAppNumber::query()->where('phone_number', '919800000002')->value('id');
        app(WhatsAppNumberService::class)->remove($account, $removeId);

        $this->assertSame('failed', Invoice::query()->findOrFail($invoice->id)->status);
    }
}
