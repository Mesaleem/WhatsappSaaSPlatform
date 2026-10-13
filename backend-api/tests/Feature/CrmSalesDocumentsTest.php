<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmBusinessProfile;
use App\Models\CrmCompany;
use App\Models\CrmDocument;
use App\Models\CrmGatewaySetting;
use App\Models\CrmPayment;
use App\Models\CrmPaymentReminder;
use App\Models\CrmProduct;
use App\Models\CrmReminderSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Crm\Sales\CrmDocumentNumbering;
use App\Services\Crm\Sales\CrmGatewayService;
use App\Services\Crm\Sales\CrmReminderService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM Sales Phases 2–6: products, documents (numbering, GST, snapshots, lifecycle,
 * conversion, credit notes, PDF), payments (manual, partial, multi-invoice, reversal,
 * idempotency, gateway checkout + webhook), delivery, reminders and the sales report.
 */
class CrmSalesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        // The tenant's Razorpay (gateway tests) answers with one order; nothing else may leave the test.
        Http::fake([
            'api.razorpay.com/*' => Http::response(['id' => 'order_T1', 'amount' => 50000, 'currency' => 'INR'], 200),
            '*' => Http::response([], 200),
        ]);
    }

    /** @return array{account: Account, user: User} */
    private function tenant(bool $gst = true): array
    {
        $account = Account::factory()->create(['company_name' => 'Acme Pvt Ltd']);
        Subscription::factory()->create(['account_id' => $account->id]);
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->firstOrFail()->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');
        if ($gst) {
            CrmBusinessProfile::create(['account_id' => $account->id, 'legal_name' => 'Acme Pvt Ltd', 'gstin' => '27AAAAA0000A1Z5', 'round_off' => true, 'default_due_days' => 15]);
        }

        return ['account' => $account->fresh(), 'user' => $user];
    }

    private function contact(Account $account): Contact
    {
        return Contact::create(['account_id' => $account->id, 'phone_number' => '9198'.random_int(10000000, 99999999), 'name' => 'Rahul', 'email' => 'rahul@example.com']);
    }

    private function invoice(array $t, array $items, array $extra = []): array
    {
        $contact = $extra['contact'] ?? $this->contact($t['account']);
        unset($extra['contact']);
        $id = $this->actingAs($t['user'])->postJson('/api/crm/documents', ['type' => 'invoice', 'contact_id' => $contact->id, 'items' => $items] + $extra)
            ->assertCreated()->json('data.id');

        return $this->actingAs($t['user'])->postJson("/api/crm/documents/{$id}/issue")->assertOk()->json('data');
    }

    // ------------------------------------------------------------------ numbering + GST

    public function test_financial_year_numbering_and_intra_state_gst_with_round_off(): void
    {
        $t = $this->tenant();
        Carbon::setTestNow('2026-10-14 10:00:00');

        $doc = $this->invoice($t, [
            ['description' => 'WapHub annual plan', 'quantity' => '1', 'unit_price' => '10000.00', 'tax_rate' => '18'],
            ['description' => 'Setup', 'quantity' => '2.5', 'unit_price' => '333.33', 'discount_amount' => '0.33', 'tax_rate' => '18'],
        ]);

        $this->assertSame('INV/2026-27/0001', $doc['number']);
        $this->assertSame('intra', $doc['tax_mode']);
        // line 2: 2.5 × 333.33 = 833.325 -> 833.33; less 0.33 = 833.00
        $this->assertSame('10833.00', $doc['taxable_total']);
        $this->assertSame('974.97', $doc['cgst_total']);
        $this->assertSame('974.97', $doc['sgst_total']);
        $this->assertSame('1949.94', $doc['tax_total']);
        $this->assertSame('0.06', $doc['round_off']);
        $this->assertSame('12783.00', $doc['grand_total']);
        $this->assertSame('12783.00', $doc['balance_due']);
        $this->assertSame('2026-10-29', $doc['due_date'], 'default due days');

        $second = $this->invoice($t, [['description' => 'x', 'quantity' => '1', 'unit_price' => '1']]);
        $this->assertSame('INV/2026-27/0002', $second['number']);
        $this->assertSame('2026-27', CrmDocumentNumbering::financialYear(Carbon::parse('2027-03-31')));
        $this->assertSame('2027-28', CrmDocumentNumbering::financialYear(Carbon::parse('2027-04-01')));
        Carbon::setTestNow();
    }

    public function test_inter_state_gst_from_the_customer_company_and_no_gst_without_a_gstin(): void
    {
        $t = $this->tenant();
        $company = CrmCompany::create(['account_id' => $t['account']->id, 'name' => 'Delhi Co', 'gstin' => '07BBBBB0000B1Z5']);
        $id = $this->actingAs($t['user'])->postJson('/api/crm/documents', ['type' => 'invoice', 'crm_company_id' => $company->id, 'items' => [['description' => 'Plan', 'quantity' => '1', 'unit_price' => '1000', 'tax_rate' => '18']]])->json('data.id');
        $doc = $this->actingAs($t['user'])->postJson("/api/crm/documents/{$id}/issue")->assertOk()->json('data');
        $this->assertSame('inter', $doc['tax_mode']);
        $this->assertSame('180.00', $doc['igst_total']);
        $this->assertSame('1180.00', $doc['grand_total']);

        $noGst = $this->tenant(false);
        $plain = $this->invoice($noGst, [['description' => 'Plan', 'quantity' => '1', 'unit_price' => '1000', 'tax_rate' => '18']]);
        $this->assertSame('none', $plain['tax_mode']);
        $this->assertSame('1000.00', $plain['grand_total']);
    }

    // ------------------------------------------------------------------ snapshots + lifecycle

    public function test_issued_documents_are_frozen_and_product_changes_never_reach_them(): void
    {
        $t = $this->tenant();
        $product = CrmProduct::create(['account_id' => $t['account']->id, 'name' => 'Gold plan', 'unit_price' => '5000', 'tax_rate' => '18', 'hsn_sac' => '998314']);
        $contact = $this->contact($t['account']);
        $id = $this->actingAs($t['user'])->postJson('/api/crm/documents', ['type' => 'quotation', 'contact_id' => $contact->id, 'items' => [['crm_product_id' => $product->id, 'quantity' => '2']]])
            ->assertCreated()->assertJsonPath('data.items.0.description', 'Gold plan')->assertJsonPath('data.items.0.unit_price', '5000.00')->json('data.id');
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$id}/issue")->assertOk()->assertJsonPath('data.status', 'sent');

        $product->update(['unit_price' => '9999', 'name' => 'Renamed']);
        $contact->update(['name' => 'Someone Else']);
        $this->actingAs($t['user'])->getJson("/api/crm/documents/{$id}")
            ->assertJsonPath('data.items.0.unit_price', '5000.00')
            ->assertJsonPath('data.items.0.description', 'Gold plan')
            ->assertJsonPath('data.customer_snapshot.name', 'Rahul');

        $this->actingAs($t['user'])->patchJson("/api/crm/documents/{$id}", ['title' => 'x'])->assertStatus(422);
        $this->actingAs($t['user'])->deleteJson("/api/crm/documents/{$id}")->assertStatus(422);
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$id}/status", ['status' => 'paid'])->assertStatus(422);
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$id}/status", ['status' => 'accepted'])->assertOk()->assertJsonPath('data.status', 'accepted');

        // quotation -> sales order -> invoice, each a draft with the same lines and a parent link.
        $order = $this->actingAs($t['user'])->postJson("/api/crm/documents/{$id}/convert", ['type' => 'sales_order'])->assertCreated()->json('data');
        $this->assertSame($id, $order['parent_id']);
        $this->assertSame('10000.00', $order['taxable_total']);
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$order['id']}/issue")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $inv = $this->actingAs($t['user'])->postJson("/api/crm/documents/{$order['id']}/convert", ['type' => 'invoice'])->assertCreated()->json('data');
        $this->assertSame('invoice', $inv['type']);
        $this->assertSame('draft', $inv['status']);
    }

    public function test_quotations_expire_and_customer_pos_are_recorded_not_numbered(): void
    {
        $t = $this->tenant();
        $contact = $this->contact($t['account']);
        $id = $this->actingAs($t['user'])->postJson('/api/crm/documents', ['type' => 'quotation', 'contact_id' => $contact->id, 'valid_until' => now()->addDay()->toDateString(), 'items' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10']]])->json('data.id');
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$id}/issue")->assertOk();
        $this->travel(3)->days();
        $this->artisan('crm:expire-quotations')->assertSuccessful();
        $this->assertSame('expired', CrmDocument::find($id)->status);

        $po = $this->actingAs($t['user'])->postJson('/api/crm/documents', ['type' => 'customer_po', 'contact_id' => $contact->id, 'external_reference' => 'PO-7788'])
            ->assertCreated()->assertJsonPath('data.status', 'received')->assertJsonPath('data.number', null)->json('data.id');
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$po}/issue")->assertStatus(422);
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$po}/status", ['status' => 'approved'])->assertOk();
    }

    // ------------------------------------------------------------------ payments

    public function test_partial_and_multi_invoice_payments_reversal_and_void_rules(): void
    {
        $t = $this->tenant(false);
        $contact = $this->contact($t['account']);
        $a = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '1000']], ['contact' => $contact]);
        $b = $this->invoice($t, [['description' => 'B', 'quantity' => '1', 'unit_price' => '500']], ['contact' => $contact]);

        $p1 = $this->actingAs($t['user'])->postJson('/api/crm/payments', [
            'amount' => '1200', 'method' => 'upi', 'reference' => 'UTR1',
            'allocations' => [['crm_document_id' => $a['id'], 'amount' => '1000'], ['crm_document_id' => $b['id'], 'amount' => '150']],
        ])->assertCreated()->assertJsonPath('data.unallocated', '50.00')->json('data');
        $this->assertStringStartsWith('RCPT/', $p1['number']);

        $this->assertSame('paid', CrmDocument::find($a['id'])->status);
        $bDoc = CrmDocument::find($b['id']);
        $this->assertSame('partially_paid', $bDoc->status);
        $this->assertSame('350.00', (string) $bDoc->balance_due);

        // Over-allocation and paying a paid invoice are refused; nothing changes.
        $this->actingAs($t['user'])->postJson('/api/crm/payments', ['amount' => '500', 'method' => 'cash', 'allocations' => [['crm_document_id' => $b['id'], 'amount' => '400']]])->assertStatus(422);
        $this->actingAs($t['user'])->postJson('/api/crm/payments', ['amount' => '10', 'method' => 'cash', 'allocations' => [['crm_document_id' => $a['id'], 'amount' => '10']]])->assertStatus(422);

        // The unallocated 50 is applied later.
        $this->actingAs($t['user'])->postJson("/api/crm/payments/{$p1['id']}/allocate", ['allocations' => [['crm_document_id' => $b['id'], 'amount' => '50']]])
            ->assertOk()->assertJsonPath('data.unallocated', '0.00');
        $this->assertSame('300.00', (string) CrmDocument::find($b['id'])->balance_due);

        // A paid invoice cannot be voided; reversing the payment re-opens both invoices.
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$a['id']}/status", ['status' => 'void'])->assertStatus(422);
        $this->actingAs($t['user'])->postJson("/api/crm/payments/{$p1['id']}/reverse", ['reason' => 'Bounced'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->actingAs($t['user'])->postJson("/api/crm/payments/{$p1['id']}/reverse", ['reason' => 'Again'])->assertStatus(422);
        $this->assertSame('issued', CrmDocument::find($a['id'])->status);
        $this->assertSame('1000.00', (string) CrmDocument::find($a['id'])->balance_due);
        $this->assertSame('500.00', (string) CrmDocument::find($b['id'])->balance_due);
        $this->assertSame(1, CrmPayment::count(), 'a reversal never deletes the payment');
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$a['id']}/status", ['status' => 'void'])->assertOk();
    }

    public function test_a_retried_payment_with_the_same_idempotency_key_is_recorded_once(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '100']]);
        $payload = ['amount' => '100', 'method' => 'cash', 'idempotency_key' => 'submit-1', 'allocations' => [['crm_document_id' => $inv['id'], 'amount' => '100']]];

        $first = $this->actingAs($t['user'])->postJson('/api/crm/payments', $payload)->assertCreated()->json('data.id');
        $second = $this->actingAs($t['user'])->postJson('/api/crm/payments', $payload)->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, CrmPayment::count());
    }

    /** Found by the concurrency probe: a payment with no customer and no invoice must still record. */
    public function test_a_payment_with_no_customer_is_recorded_as_a_credit(): void
    {
        $t = $this->tenant(false);

        $this->actingAs($t['user'])->postJson('/api/crm/payments', ['amount' => '250', 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.unallocated', '250.00');
        $this->assertSame(1, CrmPayment::count());
    }

    public function test_credit_notes_reduce_the_balance_and_cannot_exceed_it(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '1000']]);

        $cn = $this->actingAs($t['user'])->postJson("/api/crm/documents/{$inv['id']}/credit-note", ['reason' => 'Discount', 'items' => [['description' => 'Goodwill', 'quantity' => '1', 'unit_price' => '200']]])->assertCreated()->json('data');
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$cn['id']}/issue")->assertOk()->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'CN/'));
        $fresh = CrmDocument::find($inv['id']);
        $this->assertSame('800.00', (string) $fresh->balance_due);
        $this->assertSame('partially_paid', $fresh->status);

        $big = $this->actingAs($t['user'])->postJson("/api/crm/documents/{$inv['id']}/credit-note", ['items' => [['description' => 'Too much', 'quantity' => '1', 'unit_price' => '900']]])->json('data.id');
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$big}/issue")->assertStatus(422);

        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$cn['id']}/status", ['status' => 'void'])->assertOk();
        $this->assertSame('1000.00', (string) CrmDocument::find($inv['id'])->balance_due, 'voiding the credit note restores the balance');
    }

    // ------------------------------------------------------------------ tenancy + permissions + public link

    public function test_documents_and_payments_are_tenant_scoped_and_permission_gated(): void
    {
        $a = $this->tenant(false);
        $b = $this->tenant(false);
        $inv = $this->invoice($a, [['description' => 'A', 'quantity' => '1', 'unit_price' => '100']]);

        $this->actingAs($b['user'])->getJson("/api/crm/documents/{$inv['id']}")->assertNotFound();
        $this->actingAs($b['user'])->postJson('/api/crm/payments', ['amount' => '100', 'method' => 'cash', 'allocations' => [['crm_document_id' => $inv['id'], 'amount' => '100']]])->assertStatus(422);
        $this->actingAs($b['user'])->postJson('/api/crm/documents', ['type' => 'invoice', 'contact_id' => $inv['contact_id']])->assertStatus(422);

        $marketer = User::factory()->create(['account_id' => $a['account']->id, 'is_active' => true]);
        $marketer->assignRole('social_marketer');
        $this->actingAs($marketer)->getJson('/api/crm/documents')->assertForbidden();
        $this->actingAs($marketer)->getJson('/api/crm/payments')->assertForbidden();
        $this->actingAs($marketer)->getJson('/api/crm/leads')->assertOk();
    }

    public function test_the_public_link_serves_only_issued_documents_without_a_login(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '100']]);
        $token = CrmDocument::find($inv['id'])->public_token;

        $this->getJson("/api/public/crm-documents/{$token}")->assertOk()->assertJsonPath('data.balance_due', '100.00')->assertJsonPath('data.payable_online', false)
            ->assertJsonMissingPath('data.public_token');
        $pdf = $this->get("/api/public/crm-documents/{$token}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $draft = $this->actingAs($t['user'])->postJson('/api/crm/documents', ['type' => 'invoice', 'contact_id' => $inv['contact_id']])->json('data.id');
        $this->getJson('/api/public/crm-documents/'.CrmDocument::find($draft)->public_token)->assertNotFound();
        $this->getJson('/api/public/crm-documents/not-a-real-token-at-all-xxxxxxxxxx')->assertNotFound();
        $this->postJson("/api/public/crm-documents/{$token}/pay")->assertStatus(422);
    }

    // ------------------------------------------------------------------ gateway

    public function test_gateway_checkout_and_webhook_record_one_verified_payment(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '500']]);
        $doc = CrmDocument::find($inv['id']);

        $this->actingAs($t['user'])->putJson('/api/crm/settings/gateway', ['gateway' => 'razorpay', 'mode' => 'test', 'key_id' => 'rzp_test_x', 'key_secret' => 'sec', 'webhook_secret' => 'whsec', 'is_enabled' => true])
            ->assertOk()->assertJsonPath('data.has_key_secret', true)->assertJsonMissingPath('data.key_secret');
        $settings = CrmGatewaySetting::firstOrFail();

        $order = $this->postJson("/api/public/crm-documents/{$doc->public_token}/pay")->assertOk()->assertJsonPath('data.order_id', 'order_T1')->assertJsonPath('data.amount_minor', 50000)->json('data');
        $this->assertArrayNotHasKey('key_secret', $order);

        // A forged checkout signature is refused.
        $this->postJson("/api/public/crm-documents/{$doc->public_token}/confirm", ['razorpay_order_id' => 'order_T1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'bad'])->assertStatus(422);
        $this->assertSame(0, CrmPayment::count());

        $sig = hash_hmac('sha256', 'order_T1|pay_1', 'sec');
        $this->postJson("/api/public/crm-documents/{$doc->public_token}/confirm", ['razorpay_order_id' => 'order_T1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $sig])->assertOk();

        // The webhook for the same payment (and its replay) adds nothing.
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'order_id' => 'order_T1', 'amount' => 50000, 'status' => 'captured']]]]);
        $hook = fn (string $s, string $eventId) => $this->call('POST', "/api/webhooks/crm-payments/{$settings->webhook_token}", [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => $s, 'HTTP_X_RAZORPAY_EVENT_ID' => $eventId, 'CONTENT_TYPE' => 'application/json'], $body);
        $hook('forged', 'evt_0')->assertStatus(400);
        $hook(hash_hmac('sha256', $body, 'whsec'), 'evt_1')->assertOk();
        $hook(hash_hmac('sha256', $body, 'whsec'), 'evt_1')->assertOk()->assertJsonPath('outcome', 'duplicate');

        $this->assertSame(1, CrmPayment::count());
        $this->assertSame('paid', CrmDocument::find($doc->id)->status);
        $this->assertSame('gateway', CrmPayment::first()->source);
    }

    public function test_a_webhook_whose_amount_differs_from_the_order_is_not_recorded(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '500']]);
        app(CrmGatewayService::class)->saveSettings($t['account'], ['gateway' => 'razorpay', 'mode' => 'test', 'key_id' => 'k', 'key_secret' => 's', 'webhook_secret' => 'w', 'is_enabled' => true]);
        app(CrmGatewayService::class)->createOrder(CrmDocument::find($inv['id']));

        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_2', 'order_id' => 'order_T1', 'amount' => 100, 'status' => 'captured']]]]);
        $outcome = app(CrmGatewayService::class)->handleWebhook(CrmGatewaySetting::firstOrFail()->webhook_token, $body, hash_hmac('sha256', $body, 'w'), 'evt_x');

        $this->assertSame('amount_mismatch', $outcome);
        $this->assertSame(0, CrmPayment::count());
    }

    // ------------------------------------------------------------------ delivery + reminders + report

    public function test_manual_delivery_is_recorded_and_drafts_cannot_be_sent(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '100']]);

        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$inv['id']}/send", ['channel' => 'manual', 'recipient' => '9198xxxx', 'how' => 'My phone'])
            ->assertOk()->assertJsonPath('data.status', 'recorded');
        $draft = $this->actingAs($t['user'])->postJson('/api/crm/documents', ['type' => 'invoice', 'contact_id' => $inv['contact_id']])->json('data.id');
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$draft}/send", ['channel' => 'manual', 'recipient' => 'x'])->assertStatus(422);

        // Email without a configured mailer is recorded as failed, never as sent.
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$inv['id']}/send", ['channel' => 'email'])->assertStatus(422)->assertJsonPath('data.status', 'failed');
    }

    public function test_reminders_are_planned_once_skip_paid_invoices_and_respect_approval(): void
    {
        $t = $this->tenant(false);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '100']], ['due_date' => '2026-10-05']);
        CrmReminderSetting::create(['account_id' => $t['account']->id, 'enabled' => true, 'days_before' => [3], 'on_due_date' => true, 'days_after' => [2], 'channel' => 'email', 'auto_send' => false, 'max_attempts' => 2]);

        Carbon::setTestNow('2026-10-07 10:00:00');
        $service = app(CrmReminderService::class);
        $first = $service->run();
        $second = $service->run();

        $this->assertSame(3, $first['planned'], 'before:3, due and after:2 have all come');
        $this->assertSame(0, $second['planned'], 'never planned twice');
        $this->assertSame(3, CrmPaymentReminder::where('status', 'awaiting_approval')->count());

        // Paid -> an approved reminder is skipped, not sent.
        $this->actingAs($t['user'])->postJson('/api/crm/payments', ['amount' => '100', 'method' => 'cash', 'allocations' => [['crm_document_id' => $inv['id'], 'amount' => '100']]])->assertCreated();
        $reminder = CrmPaymentReminder::firstOrFail();
        $this->actingAs($t['user'])->postJson("/api/crm/reminders/{$reminder->id}/approve")->assertOk();
        $this->assertSame('skipped', $reminder->fresh()->status);
        Carbon::setTestNow();
    }

    // ------------------------------------------------------------------ verification follow-up

    public function test_contact_consent_is_editable_and_an_opted_out_contact_is_never_messaged(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '100']]);
        $company = CrmCompany::create(['account_id' => $t['account']->id, 'name' => 'Acme']);

        $this->actingAs($t['user'])->patchJson("/api/crm/contacts/{$inv['contact_id']}", ['opted_out' => true, 'designation' => 'Owner', 'crm_company_id' => $company->id])
            ->assertOk()->assertJsonPath('data.designation', 'Owner')->assertJsonPath('data.crm_company_id', $company->id)
            ->assertJsonPath('data.opted_out_at', fn ($v) => $v !== null);
        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$inv['id']}/send", ['channel' => 'whatsapp'])->assertStatus(422)->assertJsonValidationErrors('recipient');

        $foreign = CrmCompany::create(['account_id' => $this->tenant(false)['account']->id, 'name' => 'Theirs']);
        $this->actingAs($t['user'])->patchJson("/api/crm/contacts/{$inv['contact_id']}", ['crm_company_id' => $foreign->id])->assertStatus(422);
        $this->actingAs($t['user'])->patchJson("/api/crm/contacts/{$inv['contact_id']}", ['opted_out' => false])->assertOk()->assertJsonPath('data.opted_out_at', null);
    }

    public function test_who_decided_is_recorded_and_a_po_file_is_private_to_the_tenant(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $t = $this->tenant(false);
        $other = $this->tenant(false);
        $po = $this->actingAs($t['user'])->postJson('/api/crm/documents', ['type' => 'customer_po', 'contact_id' => $this->contact($t['account'])->id, 'external_reference' => 'PO-1'])->json('data.id');

        $this->actingAs($t['user'])->post("/api/crm/documents/{$po}/attachment", ['file' => \Illuminate\Http\UploadedFile::fake()->create('po.pdf', 100, 'application/pdf')])
            ->assertOk()->assertJsonPath('data.attachment.name', 'po.pdf');
        $this->actingAs($t['user'])->post("/api/crm/documents/{$po}/attachment", ['file' => \Illuminate\Http\UploadedFile::fake()->create('x.exe', 10)])->assertStatus(422);
        $this->actingAs($t['user'])->get("/api/crm/documents/{$po}/attachment")->assertOk();
        $this->actingAs($other['user'])->get("/api/crm/documents/{$po}/attachment")->assertNotFound();

        $this->actingAs($t['user'])->postJson("/api/crm/documents/{$po}/status", ['status' => 'approved'])->assertOk()
            ->assertJsonPath('data.decided_by.id', $t['user']->id);
    }

    public function test_receipts_render_and_are_not_sent_for_a_reversed_payment(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '100']]);
        $pay = $this->actingAs($t['user'])->postJson('/api/crm/payments', ['amount' => '100', 'method' => 'cash', 'allocations' => [['crm_document_id' => $inv['id'], 'amount' => '100']]])->json('data.id');

        $pdf = $this->actingAs($t['user'])->get("/api/crm/payments/{$pay}/receipt")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($t['user'])->postJson("/api/crm/payments/{$pay}/reverse", ['reason' => 'Bounced'])->assertOk();
        $this->actingAs($t['user'])->postJson("/api/crm/payments/{$pay}/send-receipt", ['channel' => 'email', 'recipient' => 'a@example.com'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'reversed'));
    }

    public function test_a_full_gateway_refund_reverses_the_payment_and_a_partial_one_is_left_for_staff(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '500']]);
        $gw = app(CrmGatewayService::class);
        $gw->saveSettings($t['account'], ['gateway' => 'razorpay', 'mode' => 'test', 'key_id' => 'k', 'key_secret' => 's', 'webhook_secret' => 'w', 'is_enabled' => true]);
        $gw->createOrder(CrmDocument::find($inv['id']));
        $token = CrmGatewaySetting::firstOrFail()->webhook_token;
        $send = function (array $payload, string $eventId) use ($gw, $token) {
            $body = json_encode($payload);

            return $gw->handleWebhook($token, $body, hash_hmac('sha256', $body, 'w'), $eventId);
        };

        $this->assertSame('recorded', $send(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_9', 'order_id' => 'order_T1', 'amount' => 50000, 'status' => 'captured']]]], 'e1'));
        $this->assertSame('paid', CrmDocument::find($inv['id'])->status);

        $partial = ['event' => 'refund.processed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_9', 'order_id' => 'order_T1']], 'refund' => ['entity' => ['id' => 'rfnd_1', 'amount' => 10000]]]];
        $this->assertSame('refund_partial_needs_staff', $send($partial, 'e2'));
        $this->assertNull(CrmPayment::first()->reversed_at);

        $full = ['event' => 'refund.processed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_9', 'order_id' => 'order_T1']], 'refund' => ['entity' => ['id' => 'rfnd_2', 'amount' => 50000]]]];
        $this->assertSame('refunded', $send($full, 'e3'));
        $this->assertNotNull(CrmPayment::first()->reversed_at);
        $this->assertSame('issued', CrmDocument::find($inv['id'])->status);
        $this->assertSame('refund_already_reversed', $send($full, 'e4'));
    }

    public function test_a_failed_online_payment_is_shown_on_the_invoice(): void
    {
        $t = $this->tenant(false);
        $inv = $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '500']]);
        $gw = app(CrmGatewayService::class);
        $gw->saveSettings($t['account'], ['gateway' => 'razorpay', 'mode' => 'test', 'key_id' => 'k', 'key_secret' => 's', 'webhook_secret' => 'w', 'is_enabled' => true]);
        $gw->createOrder(CrmDocument::find($inv['id']));
        $body = json_encode(['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_f', 'order_id' => 'order_T1', 'status' => 'failed']]]]);

        $this->assertSame('payment_failed', $gw->handleWebhook(CrmGatewaySetting::firstOrFail()->webhook_token, $body, hash_hmac('sha256', $body, 'w'), 'ef'));
        $this->actingAs($t['user'])->getJson("/api/crm/documents/{$inv['id']}")->assertJsonPath('data.gateway_orders.0.status', 'failed');
    }

    public function test_the_sales_report_sums_receivables_by_age(): void
    {
        $t = $this->tenant(false);
        Carbon::setTestNow('2026-10-01');
        $this->invoice($t, [['description' => 'A', 'quantity' => '1', 'unit_price' => '100.10']], ['due_date' => '2026-10-02']);
        $this->invoice($t, [['description' => 'B', 'quantity' => '1', 'unit_price' => '200.20']], ['due_date' => '2026-12-31']);
        Carbon::setTestNow('2026-11-15');

        $r = $this->actingAs($t['user'])->getJson('/api/crm/reports/sales')->assertOk()->json('data.receivables');
        // Round-off is on by default, so the invoices are 100.00 and 200.00.
        $this->assertSame('300.00', $r['outstanding']);
        $this->assertSame('100.00', $r['overdue']);
        $this->assertSame('100.00', $r['aging']['31_60']);
        $this->assertSame('200.00', $r['aging']['current']);
        $this->assertSame(2, $r['open_invoices']);
        Carbon::setTestNow();
    }
}
