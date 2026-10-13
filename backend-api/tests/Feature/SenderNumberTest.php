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

    public function test_creating_a_group_on_a_chosen_number_stores_that_number(): void
    {
        [$account, $user, , $second] = $this->client('919800000001');

        $response = $this->actingAs($user)->postJson('/api/groups/create', [
            'name' => 'Stock updates',
            'whatsapp_number_id' => $second->id,
        ]);

        $response->assertCreated();
        $this->assertSame($second->id, ContactGroup::query()->where('name', 'Stock updates')->value('whatsapp_number_id'));
    }

    public function test_creating_a_group_on_an_unlinked_number_is_refused(): void
    {
        [$account, $user] = $this->client('919800000001');
        $paused = $this->number($account, '919800000009', false, WhatsAppNumber::STATUS_PAUSED);

        $this->actingAs($user)->postJson('/api/groups/create', [
            'name' => 'Should not be created',
            'whatsapp_number_id' => $paused->id,
        ])->assertStatus(422)->assertJsonPath('error_code', 'sender_not_active');

        $this->assertSame(0, ContactGroup::query()->where('name', 'Should not be created')->count());
    }

    public function test_an_agent_can_send_notifications_for_their_own_account(): void
    {
        [$account, , $default] = $this->client('919800000001');
        $agent = User::factory()->create(['account_id' => $account->id]);
        $agent->assignRole('agent');

        $this->actingAs($agent)->getJson('/api/alerts/sender-numbers')
            ->assertOk()
            ->assertJsonPath('data.0.phone_number', $default->phone_number);
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
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE, 'wa_group_jid' => '120363000000000002@g.us', 'whatsapp_number_id' => $default->id,
        ]);

        // A native group belongs to the number it lives on: sending it from another is refused.
        try {
            app(SenderNumberResolver::class)->resolve($account, $second->id, $group);
            $this->fail('a native group must not send from another number');
        } catch (SenderNumberException $e) {
            $this->assertSame('group_not_on_number', $e->errorCode);
            $this->assertStringContainsString($default->phone_number, $e->getMessage());
        }
        $this->assertSame($default->id, app(SenderNumberResolver::class)->resolve($account, $default->id, $group));
    }

    /** A custom (internal) group is a list of contacts: it may go from any connected number. */
    public function test_a_custom_group_sends_from_any_connected_number(): void
    {
        [$account, , $default, $second] = $this->client('919800000001');
        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Customers', 'group_code' => 'CUST_1', 'is_default' => false,
            'group_type' => ContactGroup::GROUP_TYPE_INTERNAL, 'whatsapp_number_id' => $default->id,
        ]);

        $this->assertSame($second->id, app(SenderNumberResolver::class)->resolve($account, $second->id, $group));
        $this->assertSame($default->id, app(SenderNumberResolver::class)->resolve($account, null, $group));
    }

    /** Seat model: once a slot carries another number, the old number's native groups are refused. */
    public function test_a_native_group_is_refused_after_its_slot_changed_number(): void
    {
        [$account, , $default] = $this->client('919800000001');
        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Old line group', 'group_code' => 'OLD_1', 'is_default' => false,
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE, 'wa_group_jid' => '120363000000000009@g.us',
            'whatsapp_number_id' => $default->id, 'wa_owner_phone' => '919800000001',
        ]);
        $default->forceFill(['phone_number' => '919811112222'])->save();

        try {
            app(SenderNumberResolver::class)->resolve($account, null, $group);
            $this->fail('the new number is not a member of the old number\'s group');
        } catch (SenderNumberException $e) {
            $this->assertSame('group_number_changed', $e->errorCode);
        }
        $this->assertSame([], app(SenderNumberResolver::class)->nativeGroupIdsSendableFrom($account, $default->id));

        // The original number back on the slot: sendable again.
        $default->forceFill(['phone_number' => '919800000001'])->save();
        $this->assertSame([$group->id], app(SenderNumberResolver::class)->nativeGroupIdsSendableFrom($account, $default->id));
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

    /**
     * Owner rule (2026-10-13): with no sender chosen (the Developer API never takes one), a
     * native group goes from its OWN number, and is refused when that number is not connected.
     */
    public function test_with_no_sender_chosen_a_native_group_goes_from_its_own_number(): void
    {
        [$account, , , $second] = $this->client('919800000001');
        $group = ContactGroup::query()->create([
            'account_id' => $account->id, 'name' => 'Other line', 'group_code' => 'OTHER_1', 'is_default' => false,
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE, 'wa_group_jid' => '120363000000000003@g.us', 'whatsapp_number_id' => $second->id,
        ]);

        $this->assertSame($second->id, app(SenderNumberResolver::class)->resolve($account, null, $group));

        $second->forceFill(['status' => WhatsAppNumber::STATUS_UNLINKED])->save();
        try {
            app(SenderNumberResolver::class)->resolve($account, null, $group);
            $this->fail('a group whose own number is not connected must not send');
        } catch (SenderNumberException $e) {
            $this->assertSame('sender_not_active', $e->errorCode);
        }
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
