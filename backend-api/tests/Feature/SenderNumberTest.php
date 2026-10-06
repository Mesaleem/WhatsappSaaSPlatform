<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ContactGroup;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\WhatsApp\SenderNumberException;
use App\Services\WhatsApp\SenderNumberResolver;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sending numbers: a send goes out from a linked number of the account; a group is sent only from its own number;
 * the Developer API uses the default number; the manual bulk list is capped at 30 numbers.
 */
class SenderNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function number(Account $account, string $phone, bool $default = false, string $status = WhatsAppNumber::STATUS_LINKED): WhatsAppNumber
    {
        return WhatsAppNumber::query()->create([
            'account_id' => $account->id, 'phone_number' => $phone, 'is_included' => true,
            'is_default' => $default, 'status' => $status,
        ]);
    }

    /** @return array{0: Account, 1: User, 2: WhatsAppNumber, 3: WhatsAppNumber} account, admin, default number, second number */
    private function client(string $defaultPhone): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr', 'status' => 'active', 'expires_at' => now()->addMonth()]);

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $default = $this->number($account, $defaultPhone, true);
        $second = $this->number($account, '9'.substr($defaultPhone, 1).'2');

        return [$account, $user, $default, $second];
    }

    private function template(Account $account): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'SENDER_'.strtoupper(Str::random(5)), 'title' => 'Reminder',
            'template_body' => 'Hello {{name}}', 'status' => 'approved', 'language' => 'en_US', 'category' => 'UTILITY',
            'meta_template_name' => 'reminder', 'meta_template_status' => 'APPROVED',
        ]);
    }

    public function test_a_group_created_on_a_number_belongs_to_that_number(): void
    {
        [$account, , $default] = $this->client('919800000001');

        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Team', 'group_code' => 'TEAM_1', 'is_default' => false,
            'group_type' => ContactGroup::GROUP_TYPE_INTERNAL, 'whatsapp_number_id' => $default->id,
        ]);

        $this->assertSame($default->id, $group->fresh()->whatsapp_number_id);
    }

    public function test_the_resolver_uses_the_default_number_when_none_is_chosen(): void
    {
        [$account, , $default] = $this->client('919800000001');

        $this->assertSame($default->id, app(SenderNumberResolver::class)->resolve($account, null));
    }

    public function test_a_group_send_from_a_number_the_group_is_not_on_is_refused(): void
    {
        [$account, $user, $default, $second] = $this->client('919800000001');
        $template = $this->template($account);
        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Stock team', 'group_code' => 'STOCK_1', 'is_default' => false,
            'group_type' => ContactGroup::GROUP_TYPE_INTERNAL, 'whatsapp_number_id' => $default->id,
        ]);

        $response = $this->actingAs($user)->postJson("/api/groups/{$group->id}/send-template", [
            'template_id' => $template->id,
            'variables' => ['name' => 'Asha'],
            'sender_number_id' => $second->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('error_code', 'group_not_on_number');
        $this->assertStringContainsString($default->phone_number, $response->json('message'));
    }

    public function test_a_send_from_an_unlinked_number_is_refused(): void
    {
        [$account, , $default] = $this->client('919800000001');
        $paused = $this->number($account, '919800000007', false, WhatsAppNumber::STATUS_PAUSED);

        try {
            app(SenderNumberResolver::class)->resolve($account, $paused->id);
            $this->fail('a paused number must not send');
        } catch (SenderNumberException $e) {
            $this->assertSame('sender_not_active', $e->errorCode);
        }
    }

    public function test_the_developer_api_refuses_a_group_that_is_not_on_the_default_number(): void
    {
        [$account, , $default, $second] = $this->client('919800000001');
        $template = $this->template($account);
        $account->forceFill(['authorized_server_ip' => '127.0.0.1'])->save();

        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Other line', 'group_code' => 'OTHER_1', 'is_default' => false,
            'group_type' => ContactGroup::GROUP_TYPE_INTERNAL, 'whatsapp_number_id' => $second->id,
        ]);

        $plainKey = 'wasaas_live_'.Str::random(40);
        ApiKey::create([
            'account_id' => $account->id, 'name' => 'Test', 'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey),
        ]);

        $this->withHeaders(['X-API-KEY' => $plainKey, 'Idempotency-Key' => 'grp-'.Str::random(8)])
            ->postJson('/api/v1/send-message', [
                'recipient_type' => 'group',
                'template_code' => $template->template_code,
                'group_code' => $group->group_code,
                'variables' => ['name' => 'Asha'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'group_not_on_number');
    }

    public function test_a_manual_bulk_list_of_more_than_30_numbers_is_refused(): void
    {
        [$account, $user] = $this->client('919800000001');
        $template = $this->template($account);

        $phones = array_map(fn ($i) => '9199'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 31));

        $response = $this->actingAs($user)->postJson('/api/alerts/send-template-bulk', [
            'template_id' => $template->id,
            'recipient_phones' => $phones,
            'variables' => ['name' => 'Asha'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Bulk allows up to 30 numbers', json_encode($response->json()));
    }
}
