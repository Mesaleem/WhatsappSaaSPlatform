<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Developer API refusals: one number per request, a clear English message, and the error envelope the caller reads. */
class ApiErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private const ONE_NUMBER = 'The Developer API sends to one number per request.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{0: string, 1: MessageTemplate} plain API key of an account with a plan and an approved template */
    private function apiClient(): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr', 'status' => 'active', 'expires_at' => now()->addMonth()]);
        $account->forceFill(['authorized_server_ip' => '127.0.0.1'])->save();

        $plainKey = 'wasaas_live_'.Str::random(40);
        // Phase 4 Task 7 FIX — this file tests error MESSAGES, not binding enforcement; without a valid per-key
        // legacy deadline, every call below would now be refused with INSTALLATION_BINDING_REQUIRED before ever
        // reaching the behavior under test (Task 7 made the legacy-IP path never implicit).
        ApiKey::create([
            'account_id' => $account->id, 'name' => 'Test', 'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey),
            'legacy_binding_grace_expires_at' => now()->addDays(30),
        ]);

        $template = MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'API_ERR_'.strtoupper(Str::random(5)), 'title' => 'Reminder',
            'template_body' => 'Hello {{name}}', 'status' => 'approved', 'language' => 'en_US', 'category' => 'UTILITY',
            'meta_template_name' => 'reminder', 'meta_template_status' => 'APPROVED',
        ]);

        return [$plainKey, $template];
    }

    public function test_a_comma_list_in_one_template_request_is_refused_in_english(): void
    {
        [$key, $template] = $this->apiClient();

        $response = $this->withHeaders(['X-API-KEY' => $key, 'Idempotency-Key' => 'e1-'.Str::random(8)])
            ->postJson('/api/v1/messages/send-template', [
                'template_code' => $template->template_code,
                'recipient_phone' => '919876543210, 919876543211',
                'variables' => ['name' => 'Asha'],
            ])
            ->assertStatus(422);
        $this->assertStringContainsString(self::ONE_NUMBER, json_encode($response->json()));
    }

    public function test_a_comma_list_in_one_send_message_request_is_refused_in_english(): void
    {
        [$key, $template] = $this->apiClient();

        $response = $this->withHeaders(['X-API-KEY' => $key, 'Idempotency-Key' => 'e2-'.Str::random(8)])
            ->postJson('/api/v1/send-message', [
                'recipient_type' => 'individual',
                'template_code' => $template->template_code,
                'recipient_phone' => '919876543210,919876543211',
                'variables' => ['name' => 'Asha'],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(self::ONE_NUMBER, json_encode($response->json()));
    }

    public function test_one_number_per_request_still_goes_through_the_gate(): void
    {
        [$key, $template] = $this->apiClient();

        // A single number passes validation; the send then stops at the WhatsApp session (not a validation error).
        $response = $this->withHeaders(['X-API-KEY' => $key, 'Idempotency-Key' => 'e3-'.Str::random(8)])
            ->postJson('/api/v1/messages/send-template', [
                'template_code' => $template->template_code,
                'recipient_phone' => '919876543210',
                'variables' => ['name' => 'Asha'],
            ]);

        $this->assertFalse(isset($response->json()['errors']['recipient_phone']), 'a single number must pass the phone rule');
    }
}
