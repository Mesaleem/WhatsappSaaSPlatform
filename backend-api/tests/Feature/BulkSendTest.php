<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MessageBatch;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Bulk send from the typed numbers: only the ticked numbers send, in turn; it appears in the history with them. */
class BulkSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{0: Account, 1: User, 2: MessageTemplate, 3: list<int>} account, admin, template, linked number ids */
    private function client(): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr', 'status' => 'active', 'expires_at' => now()->addMonth()]);

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $template = MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'BULK_'.strtoupper(Str::random(5)), 'title' => 'Reminder',
            'template_body' => 'Hello {{name}}', 'status' => 'approved', 'language' => 'en_US', 'category' => 'UTILITY',
            'meta_template_name' => 'reminder', 'meta_template_status' => 'APPROVED',
        ]);

        $ids = [];
        foreach (['919800000001', '919800000002', '919800000003'] as $i => $phone) {
            $ids[] = WhatsAppNumber::query()->create([
                'account_id' => $account->id, 'phone_number' => $phone, 'is_included' => true,
                'is_default' => $i === 0, 'status' => WhatsAppNumber::STATUS_LINKED,
            ])->id;
        }

        return [$account, $user, $template, $ids];
    }

    public function test_only_the_ticked_numbers_send_and_the_run_is_kept_in_the_history(): void
    {
        [, $user, $template, $ids] = $this->client();

        $this->actingAs($user)->postJson('/api/alerts/bulk-sends', [
            'recipient_phones' => ['919876543210', '919876543211'],
            'template_id' => $template->id,
            'variables' => ['name' => 'Asha'],
            'sender_number_ids' => [$ids[0], $ids[2]],
        ])->assertCreated();

        $batch = MessageBatch::query()->firstOrFail();
        $this->assertSame('bulk', $batch->kind);
        $this->assertSame([$ids[0], $ids[2]], $batch->sender_number_ids);

        $history = $this->actingAs($user)->getJson('/api/alerts/message-batches')->assertOk()->json('data.0');
        $this->assertSame('bulk', $history['kind']);
        $this->assertSame(['919800000001', '919800000003'], $history['sender_numbers']);
    }

    public function test_a_bad_number_is_named_and_nothing_is_queued(): void
    {
        [, $user, $template, $ids] = $this->client();

        $this->actingAs($user)->postJson('/api/alerts/bulk-sends', [
            'recipient_phones' => ['919876543210', 'abc'],
            'template_id' => $template->id,
            'sender_number_ids' => [$ids[0]],
        ])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_NUMBERS');

        $this->assertSame(0, MessageBatch::query()->count());
    }

    public function test_more_than_thirty_numbers_are_refused_with_the_excel_hint(): void
    {
        [, $user, $template, $ids] = $this->client();
        $phones = array_map(fn ($i) => '9199'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 31));

        $this->actingAs($user)->postJson('/api/alerts/bulk-sends', [
            'recipient_phones' => $phones,
            'template_id' => $template->id,
            'sender_number_ids' => [$ids[0]],
        ])->assertStatus(422);

        $this->assertStringContainsString('Bulk allows up to 30 numbers', json_encode($this->actingAs($user)->postJson('/api/alerts/bulk-sends', [
            'recipient_phones' => $phones,
            'template_id' => $template->id,
            'sender_number_ids' => [$ids[0]],
        ])->json()));
    }
}
