<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\MessageTemplate;
use App\Models\ScheduledMessage;
use App\Services\Scheduling\ScheduledMessageException;
use App\Services\Scheduling\ScheduledMessageService;
use Carbon\Carbon;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Scheduled template messages: stored with a time, sent when due, cancellable until then. */
class ScheduledMessageTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\AllowsUnboundApiKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function account(): Account
    {
        return Account::factory()->create();
    }

    private function payload(): array
    {
        return ['template_id' => 999999, 'recipient_phone' => '919876543210', 'variables' => []];
    }

    public function test_a_time_in_the_past_is_refused(): void
    {
        $this->expectException(ScheduledMessageException::class);
        app(ScheduledMessageService::class)->schedule($this->account(), ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL, $this->payload(), now()->subHour());
    }

    public function test_a_time_more_than_ninety_days_ahead_is_refused(): void
    {
        try {
            app(ScheduledMessageService::class)->schedule($this->account(), ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL, $this->payload(), now()->addDays(91));
            $this->fail('a message more than 90 days ahead must be refused');
        } catch (ScheduledMessageException $e) {
            $this->assertSame('send_at_too_far', $e->errorCode);
        }
    }

    public function test_a_time_without_a_zone_is_read_as_indian_standard_time(): void
    {
        $sendAt = ScheduledMessageService::parseSendAt('2026-10-08 09:35:00');

        $this->assertSame('Asia/Kolkata', $sendAt->timezoneName);
        $this->assertSame('2026-10-08 09:35:00', $sendAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-08T04:05:00Z', $sendAt->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_a_valid_time_is_stored_as_pending(): void
    {
        $row = app(ScheduledMessageService::class)->schedule($this->account(), ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL, $this->payload(), now()->addHour());

        $this->assertSame(ScheduledMessage::PENDING, $row->status);
        $this->assertSame(0, $row->attempts);
    }

    public function test_a_message_that_is_not_due_is_not_sent(): void
    {
        app(ScheduledMessageService::class)->schedule($this->account(), ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL, $this->payload(), now()->addHour());

        $this->assertSame(0, app(ScheduledMessageService::class)->dispatchDue());
    }

    public function test_a_due_message_whose_template_is_missing_is_marked_failed_with_the_reason(): void
    {
        $account = $this->account();
        $row = ScheduledMessage::query()->create([
            'account_id' => $account->id, 'source' => 'api', 'kind' => ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL,
            'payload' => $this->payload(), 'send_at' => now()->subMinute(), 'status' => ScheduledMessage::PENDING, 'attempts' => 0,
        ]);

        $this->assertSame(1, app(ScheduledMessageService::class)->dispatchDue());

        $row->refresh();
        $this->assertSame(ScheduledMessage::FAILED, $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertNotEmpty($row->last_error);
    }

    public function test_only_the_owner_can_cancel_and_only_before_it_is_sent(): void
    {
        $owner = $this->account();
        $other = $this->account();
        $row = app(ScheduledMessageService::class)->schedule($owner, ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL, $this->payload(), now()->addHour());

        try {
            app(ScheduledMessageService::class)->cancel($other, $row->id);
            $this->fail('another account must not cancel it');
        } catch (ScheduledMessageException $e) {
            $this->assertSame('not_found', $e->errorCode);
        }

        $cancelled = app(ScheduledMessageService::class)->cancel($owner, $row->id);
        $this->assertSame(ScheduledMessage::CANCELLED, $cancelled->status);

        $row->forceFill(['status' => ScheduledMessage::SENT])->save();
        try {
            app(ScheduledMessageService::class)->cancel($owner, $row->id);
            $this->fail('a sent message cannot be cancelled');
        } catch (ScheduledMessageException $e) {
            $this->assertSame('not_pending', $e->errorCode);
        }
    }

    public function test_the_api_stores_a_template_send_with_a_time_and_answers_202(): void
    {
        $account = $this->account();
        $capability = Capability::query()->where('slug', 'external_api')->firstOrFail();
        $account->entitlements()->create(['capability_id' => $capability->id, 'source' => 'plan']);

        $plainKey = 'test_'.Str::random(40);
        ApiKey::create([
            'account_id' => $account->id, 'name' => 'Test', 'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey), 'secret_prefix' => null, 'secret_hash' => null,
        ]);

        $template = MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'SCHED_'.strtoupper(Str::random(5)), 'title' => 'Reminder',
            'template_body' => 'Hello {{name}}', 'status' => 'approved', 'language' => 'en_US', 'category' => 'UTILITY',
            'meta_template_name' => 'reminder', 'meta_template_status' => 'APPROVED',
        ]);

        $sendAt = Carbon::now()->addHours(2)->toIso8601String();
        $response = $this->withHeaders(['X-API-KEY' => $plainKey, 'Idempotency-Key' => 'sched-'.Str::random(8)])
            ->postJson('/api/v1/messages/send-template', [
                'template_code' => $template->template_code,
                'recipient_phone' => '919876543210',
                'variables' => ['name' => 'Asha'],
                'scheduled_at' => $sendAt,
            ]);

        $response->assertStatus(202)->assertJsonPath('status', true);
        $id = $response->json('data.id');
        $this->assertSame(ScheduledMessage::PENDING, ScheduledMessage::query()->findOrFail($id)->status);

        $this->withHeaders(['X-API-KEY' => $plainKey])
            ->withHeader('Idempotency-Key', 'cancel-'.Str::random(8))
            ->deleteJson("/api/v1/scheduled-messages/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', ScheduledMessage::CANCELLED);
    }
}
