<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ScheduledMessage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "No template" (free text) send follows the same sender-number and schedule rules as a template send:
 * it goes out from the chosen (or default) linked number, a group must be on that number, and an optional
 * scheduled_at defers the send instead of sending at once.
 */
class SendDirectSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function number(Account $account, string $phone, bool $default = false): WhatsAppNumber
    {
        return WhatsAppNumber::query()->create([
            'account_id' => $account->id, 'phone_number' => $phone, 'is_included' => true,
            'is_default' => $default, 'status' => WhatsAppNumber::STATUS_LINKED,
        ]);
    }

    /** @return array{0: Account, 1: User, 2: WhatsAppNumber, 3: WhatsAppNumber} account, admin, default number, second number */
    private function client(): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr', 'status' => 'active', 'expires_at' => now()->addMonth()]);

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $default = $this->number($account, '919800000001', true);
        $second = $this->number($account, '919800000002');

        return [$account, $user, $default, $second];
    }

    public function test_an_individual_no_template_send_can_be_scheduled_for_later(): void
    {
        [, $user, , $second] = $this->client();

        $response = $this->actingAs($user)->postJson('/api/alerts/send-direct', [
            'recipient_type' => 'individual',
            'recipient_phone' => '919876543210',
            'message' => 'Stock update',
            'sender_number_id' => $second->id,
            'scheduled_at' => now()->addHours(7)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(202)->assertJsonPath('scheduled.id', fn ($id) => $id !== null);

        $row = ScheduledMessage::query()->firstOrFail();
        $this->assertSame(ScheduledMessage::KIND_DIRECT_TEXT, $row->kind);
        $this->assertSame($second->id, $row->payload['sender_number_id']);
    }

    public function test_a_group_no_template_send_from_a_number_the_group_is_not_on_is_refused(): void
    {
        [$account, $user, $default, $second] = $this->client();
        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Stock team', 'group_code' => 'STOCK_DIRECT_1',
            'is_default' => false, 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL, 'whatsapp_number_id' => $default->id,
        ]);

        $response = $this->actingAs($user)->postJson('/api/alerts/send-direct', [
            'recipient_type' => 'group',
            'group_ids' => [$group->id],
            'message' => 'Stock update',
            'sender_number_id' => $second->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('error_code', 'group_not_on_number');
        $this->assertStringContainsString($default->phone_number, $response->json('message'));
    }

    public function test_a_group_no_template_send_can_be_scheduled_for_later(): void
    {
        [$account, $user, $default] = $this->client();
        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Stock team', 'group_code' => 'STOCK_DIRECT_2',
            'is_default' => false, 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL, 'whatsapp_number_id' => $default->id,
        ]);

        $response = $this->actingAs($user)->postJson('/api/alerts/send-direct', [
            'recipient_type' => 'group',
            'group_ids' => [$group->id],
            'message' => 'Stock update',
            'scheduled_at' => now()->addHours(7)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(202);
        $row = ScheduledMessage::query()->firstOrFail();
        $this->assertSame(ScheduledMessage::KIND_GROUP_DIRECT_TEXT, $row->kind);
        $this->assertSame($group->id, $row->payload['group_id']);
        $this->assertSame($default->id, $row->payload['sender_number_id']);
    }

    public function test_a_bulk_send_without_a_template_sends_the_typed_text(): void
    {
        [, $user, $default] = $this->client();

        $response = $this->actingAs($user)->postJson('/api/alerts/bulk-sends', [
            'recipient_phones' => ['919876543210', '919876543211'],
            'message_text' => 'Stock is running low, please reorder.',
            'sender_number_ids' => [$default->id],
        ]);

        $response->assertCreated();
        $batch = \App\Models\MessageBatch::query()->firstOrFail();
        $this->assertNull($batch->template_id);
        $this->assertSame('Stock is running low, please reorder.', $batch->message_text);
    }
}
