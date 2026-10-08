<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\MessageTemplate;
use App\Models\ScheduledMessage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\Scheduling\ScheduledMessageService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session-authenticated list/cancel for a scheduled message (Send Notification page) -- previously a
 * scheduled send could only be seen or cancelled through the Developer API; there was no page in the
 * app for a user who scheduled something from the dashboard to see or cancel it again.
 */
class ScheduledMessageListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{0: Account, 1: User} */
    private function client(): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr', 'status' => 'active', 'expires_at' => now()->addMonth()]);

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user];
    }

    public function test_a_scheduled_direct_text_send_shows_in_the_list(): void
    {
        [$account, $user] = $this->client();

        $row = app(ScheduledMessageService::class)->schedule(
            $account,
            ScheduledMessage::KIND_DIRECT_TEXT,
            ['recipient_phone' => '919876543210', 'message_type' => 'text', 'content' => ['body' => 'Stock update']],
            now()->addHours(2),
            'web_ui',
        );

        $response = $this->actingAs($user)->getJson('/api/alerts/scheduled-messages');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $row->id);
        $response->assertJsonPath('data.0.recipient', '919876543210');
        $response->assertJsonPath('data.0.preview', 'Stock update');
        $response->assertJsonPath('data.0.can_cancel', true);
    }

    public function test_a_scheduled_template_group_send_shows_the_group_name_and_template_title(): void
    {
        [$account, $user] = $this->client();
        $template = MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'TPL_SCHED_1', 'industry_type' => 'general',
            'title' => 'Payment Reminder', 'template_body' => 'Hi {{name}}', 'status' => 'approved',
            'variables_schema' => [['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true]],
        ]);
        $number = WhatsAppNumber::query()->create([
            'account_id' => $account->id, 'phone_number' => '919800000001', 'is_included' => true,
            'is_default' => true, 'status' => WhatsAppNumber::STATUS_LINKED,
        ]);
        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'VIP customers', 'group_code' => 'VIP_SCHED_1',
            'is_default' => false, 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL, 'whatsapp_number_id' => $number->id,
        ]);

        app(ScheduledMessageService::class)->schedule(
            $account,
            ScheduledMessage::KIND_TEMPLATE_GROUP,
            ['group_id' => $group->id, 'template_id' => $template->id, 'variables' => ['name' => 'Asha'], 'sender_number_id' => $number->id],
            now()->addHours(2),
            'web_ui',
        );

        $response = $this->actingAs($user)->getJson('/api/alerts/scheduled-messages');

        $response->assertOk();
        $response->assertJsonPath('data.0.recipient', 'VIP customers');
        $response->assertJsonPath('data.0.preview', 'Payment Reminder');
        $response->assertJsonPath('data.0.sender_number', '919800000001');
        $response->assertJsonPath('data.0.is_template', true);
    }

    public function test_cancelling_a_pending_message_stops_it_from_being_sent(): void
    {
        [$account, $user] = $this->client();

        $row = app(ScheduledMessageService::class)->schedule(
            $account,
            ScheduledMessage::KIND_DIRECT_TEXT,
            ['recipient_phone' => '919876543210', 'message_type' => 'text', 'content' => ['body' => 'Stock update']],
            now()->addHours(2),
            'web_ui',
        );

        $response = $this->actingAs($user)->postJson("/api/alerts/scheduled-messages/{$row->id}/cancel");

        $response->assertOk()->assertJsonPath('data.status', ScheduledMessage::CANCELLED);
        $this->assertSame(ScheduledMessage::CANCELLED, $row->refresh()->status);

        $sent = app(ScheduledMessageService::class)->dispatchDue();
        $this->assertSame(0, $sent);
    }

    public function test_a_message_already_sent_cannot_be_cancelled(): void
    {
        [$account, $user] = $this->client();

        $row = app(ScheduledMessageService::class)->schedule(
            $account,
            ScheduledMessage::KIND_DIRECT_TEXT,
            ['recipient_phone' => '919876543210', 'message_type' => 'text', 'content' => ['body' => 'Stock update']],
            now()->addHours(2),
            'web_ui',
        );
        $row->forceFill(['status' => ScheduledMessage::SENT])->save();

        $this->actingAs($user)->postJson("/api/alerts/scheduled-messages/{$row->id}/cancel")
            ->assertStatus(409)->assertJsonPath('error_code', 'not_pending');
    }

    public function test_an_account_cannot_see_or_cancel_another_accounts_scheduled_message(): void
    {
        [$ownerAccount] = $this->client();
        [, $otherUser] = $this->client();

        $row = app(ScheduledMessageService::class)->schedule(
            $ownerAccount,
            ScheduledMessage::KIND_DIRECT_TEXT,
            ['recipient_phone' => '919876543210', 'message_type' => 'text', 'content' => ['body' => 'Stock update']],
            now()->addHours(2),
            'web_ui',
        );

        $this->actingAs($otherUser)->getJson('/api/alerts/scheduled-messages')->assertJsonCount(0, 'data');
        $this->actingAs($otherUser)->postJson("/api/alerts/scheduled-messages/{$row->id}/cancel")
            ->assertStatus(404)->assertJsonPath('error_code', 'not_found');
    }
}
