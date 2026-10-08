<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ContactGroup;
use App\Models\ScheduledMessage;
use App\Models\Subscription;
use App\Models\WhatsAppNumber;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/v1/send-message with template_code "no_template" (free text, no template) can be scheduled for
 * later, the same as a real template_code on this endpoint -- this was previously silently ignored: a
 * scheduled_at on a "no_template" call always sent at once instead of waiting.
 */
class ExternalApiNoTemplateSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{0: Account, 1: string} account, plain API key */
    private function client(): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr', 'status' => 'active', 'expires_at' => now()->addMonth()]);
        $account->forceFill(['authorized_server_ip' => '127.0.0.1'])->save();

        WhatsAppNumber::query()->create([
            'account_id' => $account->id, 'phone_number' => '919800000001', 'is_included' => true,
            'is_default' => true, 'status' => WhatsAppNumber::STATUS_LINKED,
        ]);

        $plainKey = 'wasaas_live_'.Str::random(40);
        // Phase 4 Task 7 FIX — a valid per-key legacy deadline is now required for the legacy-IP path to
        // authenticate at all; this file tests scheduling behavior, not binding enforcement.
        ApiKey::create([
            'account_id' => $account->id, 'name' => 'Test', 'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey),
            'legacy_binding_grace_expires_at' => now()->addDays(30),
        ]);

        return [$account, $plainKey];
    }

    private function headers(string $key): array
    {
        return ['X-API-KEY' => $key, 'Idempotency-Key' => 'nts-'.Str::random(12)];
    }

    public function test_a_no_template_individual_send_can_be_scheduled_for_later(): void
    {
        [, $key] = $this->client();

        $response = $this->withHeaders($this->headers($key))->postJson('/api/v1/send-message', [
            'recipient_type' => 'individual',
            'template_code' => 'no_template',
            'recipient_phone' => '919876543210',
            'text' => 'Stock update',
            'scheduled_at' => now()->addHours(7)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('scheduled', true)
            ->assertJsonPath('dispatch_id', fn ($id) => $id !== null)
            ->assertJsonPath('scheduled_for', fn ($v) => $v !== null);

        $row = ScheduledMessage::query()->firstOrFail();
        $this->assertSame(ScheduledMessage::KIND_DIRECT_TEXT, $row->kind);
        $this->assertSame('919876543210', $row->payload['recipient_phone']);
        $this->assertSame('Stock update', $row->payload['content']['body']);
    }

    public function test_a_no_template_group_send_can_be_scheduled_for_later(): void
    {
        [$account, $key] = $this->client();
        $defaultNumberId = WhatsAppNumber::query()->where('account_id', $account->id)->value('id');

        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Stock team', 'group_code' => 'STOCK_API_1',
            'is_default' => false, 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL, 'whatsapp_number_id' => $defaultNumberId,
        ]);

        $response = $this->withHeaders($this->headers($key))->postJson('/api/v1/send-message', [
            'recipient_type' => 'group',
            'template_code' => 'no_template',
            'group_code' => $group->group_code,
            'text' => 'Stock update',
            'scheduled_at' => now()->addHours(7)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(202)->assertJsonPath('success', true);

        $row = ScheduledMessage::query()->firstOrFail();
        $this->assertSame(ScheduledMessage::KIND_GROUP_DIRECT_TEXT, $row->kind);
        $this->assertSame($group->id, $row->payload['group_id']);
        $this->assertSame($defaultNumberId, $row->payload['sender_number_id']);
    }

    public function test_a_no_template_group_send_scheduled_from_the_wrong_number_is_refused_before_it_is_stored(): void
    {
        [$account, $key] = $this->client();

        $otherNumber = WhatsAppNumber::query()->create([
            'account_id' => $account->id, 'phone_number' => '919800000002', 'is_included' => true,
            'is_default' => false, 'status' => WhatsAppNumber::STATUS_LINKED,
        ]);

        // On the second number, not the default -- the default is what template_code=no_template resolves to,
        // since this endpoint never accepts a sender_number_id.
        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Other line', 'group_code' => 'OTHER_API_1',
            'is_default' => false, 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL, 'whatsapp_number_id' => $otherNumber->id,
        ]);

        $response = $this->withHeaders($this->headers($key))->postJson('/api/v1/send-message', [
            'recipient_type' => 'group',
            'template_code' => 'no_template',
            'group_code' => $group->group_code,
            'text' => 'Stock update',
            'scheduled_at' => now()->addHours(7)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(422)->assertJsonPath('error_code', 'group_not_on_number');
        $this->assertSame(0, ScheduledMessage::query()->count());
    }
}
