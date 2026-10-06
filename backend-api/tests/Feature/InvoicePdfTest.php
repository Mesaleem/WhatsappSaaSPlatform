<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The invoice PDF: a tax invoice with the items, totals and the payment record. */
class InvoicePdfTest extends TestCase
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
        $account = Account::factory()->create(['company_name' => 'Acme Retail', 'allowed_modules' => ['dashboard', 'billing']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user];
    }

    /** Every object's xref offset must point at "N 0 obj" for that object. */
    private function assertXrefOffsetsAreValid(string $pdf): void
    {
        preg_match('/startxref\n(\d+)\n%%EOF$/', $pdf, $m);
        $this->assertNotEmpty($m, 'the file ends with startxref and %%EOF');
        $xref = (int) $m[1];
        $this->assertSame('xref', substr($pdf, $xref, 4), 'startxref points at the xref table');

        preg_match_all('/^(\d{10}) (\d{5}) (n|f) $/m', substr($pdf, $xref), $entries);
        $this->assertNotEmpty($entries[1]);
        foreach ($entries[1] as $index => $offset) {
            if ($index === 0) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^'.$index.' 0 obj\n/', substr($pdf, (int) $offset, 20), "object {$index} is at its xref offset");
        }
    }

    public function test_a_paid_plan_invoice_shows_the_items_totals_and_the_payment_record(): void
    {
        [$account, $user] = $this->client();
        $admin = User::factory()->create(['account_id' => null]);
        $admin->assignRole('super_admin');

        $invoiceId = $this->actingAs($user)->postJson('/api/billing/manual-checkout', ['plan_key' => 'starter'])->json('invoice.id');
        $invoice = Invoice::query()->findOrFail($invoiceId);

        $this->actingAs($admin)->postJson("/api/admin/billing/invoices/{$invoiceId}/record-payment", [
            'amount' => (float) $invoice->total_amount,
            'method' => 'bank_transfer',
            'transaction_id' => 'UTR-PDF-1',
            'paid_on' => now()->toDateString(),
            'term_starts_on' => now()->toDateString(),
        ])->assertOk();

        $response = $this->actingAs($user)->get("/api/billing/invoices/{$invoiceId}/pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('TAX INVOICE', $pdf);
        $this->assertStringContainsString($invoice->invoice_number, $pdf);
        $this->assertStringContainsString('Acme Retail', $pdf);
        $this->assertStringContainsString('PAID', $pdf);
        $this->assertStringContainsString('UTR-PDF-1', $pdf, 'the payment record is printed');
        $this->assertStringContainsString('Total payable', $pdf);
        $this->assertStringContainsString(number_format((float) $invoice->total_amount, 2), $pdf);
        $this->assertStringContainsString('Term', $pdf);

        $this->assertXrefOffsetsAreValid($pdf);
    }

    public function test_an_unpaid_invoice_is_marked_pending_and_has_no_payment_record(): void
    {
        [, $user] = $this->client();
        $invoiceId = $this->actingAs($user)->postJson('/api/billing/manual-checkout', ['plan_key' => 'starter'])->json('invoice.id');

        $pdf = $this->actingAs($user)->get("/api/billing/invoices/{$invoiceId}/pdf")->assertOk()->getContent();

        $this->assertStringContainsString('PAYMENT PENDING', $pdf);
        $this->assertStringNotContainsString('PAYMENT RECORD', $pdf);
        $this->assertXrefOffsetsAreValid($pdf);
    }
}
