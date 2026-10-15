<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CrmLead;
use App\Models\CrmLeadSetting;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Crm\CrmLeadService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * QA fixes (2026-10-14) — WhatsApp inbox, lead routing and the CRM import.
 */
class QaMessagingAndCrmFixesTest extends TestCase
{
    use RefreshDatabase;

    private const INTERNAL = 'qa-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config(['services.qr_engine.internal_secret' => self::INTERNAL]);
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'wamid.X'], 200)]);
    }

    private function clientOn(string $slug): Account
    {
        $account = Account::factory()->create(['account_type' => 'client']);
        $plan = Plan::where('slug', $slug)->firstOrFail();
        $invoice = new Invoice([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $slug, 'plan_label' => $plan->label,
            'amount' => $plan->price, 'tax_amount' => 0, 'total_amount' => $plan->price, 'currency' => 'INR',
            'payment_gateway' => 'manual', 'status' => 'pending',
        ]);
        $invoice->capturePlanTerms($plan)->save();
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return $account->fresh();
    }

    private function member(Account $account, string $role): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    // ---------------------------------------------------------------- WhatsApp inbox

    public function test_an_inbound_message_and_a_send_appear_in_the_inbox(): void
    {
        $account = $this->clientOn('starter');
        $admin = $this->member($account, 'admin');

        $this->withHeader('X-Internal-Secret', self::INTERNAL)->postJson('/api/internal/whatsapp-inbound', [
            'account_id' => $account->id, 'sender_phone' => '919876500001', 'message' => '[Image] my bill',
            'message_id' => 'MSG1', 'message_type' => 'image', 'sender_name' => 'Asha',
        ])->assertOk();

        $threads = $this->actingAs($admin)->getJson('/api/whatsapp/inbox/threads')->assertOk()->json('data');
        $this->assertCount(1, $threads);
        $this->assertSame('Asha', $threads[0]['contact_name']);
        $this->assertSame(1, $threads[0]['unread_count']);

        // A redelivery of the same message is not recorded twice.
        $this->withHeader('X-Internal-Secret', self::INTERNAL)->postJson('/api/internal/whatsapp-inbound', [
            'account_id' => $account->id, 'sender_phone' => '919876500001', 'message' => '[Image] my bill', 'message_id' => 'MSG1', 'message_type' => 'image',
        ])->assertOk();
        $this->assertSame(1, WaMessage::query()->where('direction', 'in')->count());

        // Opening the conversation marks it read.
        $this->actingAs($admin)->getJson('/api/whatsapp/inbox/threads/'.$threads[0]['id'].'/messages')->assertOk()
            ->assertJsonPath('data.0.body', '[Image] my bill');
        $this->assertSame(0, WaConversation::query()->value('unread_count'));
    }

    public function test_meta_free_text_replies_respect_the_24_hour_window(): void
    {
        $account = $this->clientOn('meta_growth');
        $admin = $this->member($account, 'admin');
        $conversation = WaConversation::query()->create([
            'account_id' => $account->id, 'contact_phone' => '919876500002', 'last_inbound_at' => now()->subHours(30),
        ]);

        $this->actingAs($admin)->postJson("/api/whatsapp/inbox/threads/{$conversation->id}/reply", ['text' => 'Hello'])
            ->assertStatus(422)->assertJsonPath('error_code', 'OUTSIDE_SERVICE_WINDOW');
    }

    public function test_the_crm_plan_has_no_whatsapp_inbox(): void
    {
        $account = $this->clientOn('crm');

        $this->actingAs($this->member($account, 'admin'))->getJson('/api/whatsapp/inbox/threads')->assertForbidden();
    }

    // ---------------------------------------------------------------- lead routing

    public function test_a_new_lead_is_assigned_round_robin_and_the_assignee_is_told(): void
    {
        $account = $this->clientOn('meta_pro');
        $admin = $this->member($account, 'admin');
        $salesA = $this->member($account, 'sales');
        CrmLeadSetting::query()->create(['account_id' => $account->id, 'assign_mode' => 'round_robin', 'notify_assignee' => true]);

        $first = app(CrmLeadService::class)->create($account, ['phone_number' => '919811100010', 'source' => CrmLead::SOURCE_API]);
        $second = app(CrmLeadService::class)->create($account, ['phone_number' => '919811100011', 'source' => CrmLead::SOURCE_API]);

        $assignees = [$first->fresh()->assigned_user_id, $second->fresh()->assigned_user_id];
        $this->assertNotContains(null, $assignees);
        $this->assertNotSame($assignees[0], $assignees[1], 'round robin moves to the next person');
        $this->assertEqualsCanonicalizing([$admin->id, $salesA->id], $assignees);
        $this->assertSame(2, InAppNotification::query()->where('title', 'New lead assigned to you')->count());
    }

    public function test_a_lead_created_journey_trigger_is_accepted(): void
    {
        $this->assertContains('lead_created', \App\Models\WhatsAppFlow::TRIGGER_TYPES);
    }

    // ---------------------------------------------------------------- CRM import

    public function test_leads_are_imported_from_a_csv_and_duplicates_are_skipped(): void
    {
        $account = $this->clientOn('crm');
        $admin = $this->member($account, 'admin');
        $csv = "Name,Mobile,Email,Requirement\nRavi,919811100020,ravi@example.com,2BHK\nMeena,919811100021,,\nBad,12,,\n";

        $this->actingAs($admin)->post('/api/crm/leads/import', ['file' => UploadedFile::fake()->createWithContent('leads.csv', $csv)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.created', 2)->assertJsonPath('data.invalid', 1);

        $this->actingAs($admin)->post('/api/crm/leads/import', ['file' => UploadedFile::fake()->createWithContent('leads.csv', $csv)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped', 2);

        $this->assertSame(2, CrmLead::query()->where('account_id', $account->id)->count());
    }

    // ---------------------------------------------------------------- CRM plan delivery

    public function test_the_crm_plan_sends_reminders_by_email_not_whatsapp(): void
    {
        $account = $this->clientOn('crm');

        $this->assertFalse(\App\Services\Crm\Sales\CrmDeliveryService::canUseWhatsApp($account->id));
        $this->assertTrue(\App\Services\Crm\Sales\CrmDeliveryService::canUseWhatsApp($this->clientOn('starter')->id));
    }
}
