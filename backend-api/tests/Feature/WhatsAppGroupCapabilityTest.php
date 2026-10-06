<?php

namespace Tests\Feature;

use App\Jobs\CreateNativeWhatsAppGroupJob;
use App\Jobs\ProcessGroupDirectMessageJob;
use App\Jobs\ProcessGroupDispatchJob;
use App\Jobs\SyncNativeWhatsAppGroupParticipantsJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Access\EntitlementAuditLogger;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsNativeWhatsAppGroups;
use Tests\TestCase;

/**
 * Phase 5 P5-C — the `whatsapp_groups` capability is enforced on every
 * Native WhatsApp Group action, on the tenant UI API and the Public API:
 * route-level `capability.guard:whatsapp_groups` on available-native /
 * import-native / recreate, and NativeGroupEntitlement (the same
 * AccessControlService::canTenant() decision) where one endpoint also
 * serves contact lists (create, add-contacts, template/direct group sends).
 *
 * Scope (owner decision): contact-list (`internal_segment`) groups are not
 * gated by this capability — they stay behind module + permission.
 */
class WhatsAppGroupCapabilityTest extends TestCase
{
    use \Tests\Concerns\AllowsUnboundApiKeys;

    use RefreshDatabase, GrantsNativeWhatsAppGroups;

    private const MEMBER = '919999999999';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true, 'groups' => []], 200)]);
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function qrTenant(bool $entitled, array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
        Subscription::factory()->create(['account_id' => $account->id]); // QR engine
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
        if ($entitled) {
            $this->grantNativeWhatsAppGroups($account);
        }

        return $account->fresh();
    }

    private function user(Account $account, string|array $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole($role);

        return $user;
    }

    private function nativeGroup(Account $account, string $status = ContactGroup::SYNC_STATUS_SYNCED): ContactGroup
    {
        $group = ContactGroup::create([
            'account_id' => $account->id,
            'name' => 'Native Crew',
            'group_code' => 'NATIVE'.Str::upper(Str::random(4)),
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
            'wa_group_jid' => $status === ContactGroup::SYNC_STATUS_SYNCED ? '120363'.random_int(100000, 999999).'@g.us' : null,
            'sync_status' => $status,
        ]);
        ContactGroupMember::create(['group_id' => $group->id, 'account_id' => $account->id, 'phone_number' => self::MEMBER, 'name' => 'Bob']);

        return $group;
    }

    private function template(Account $account, string $code = 'GROUP_TPL'): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => $code, 'title' => $code,
            'template_body' => 'Hello group.', 'status' => 'approved',
        ]);
    }

    /** @return array{key: string, secret: string} */
    private function issueApiKey(Account $account): array
    {
        $plainKey = 'wasaas_live_'.Str::random(40);
        $plainSecret = 'wasaas_secret_'.Str::random(40);
        ApiKey::create([
            'account_id' => $account->id, 'name' => 'Test Key',
            'key_prefix' => substr($plainKey, 0, 20), 'key_hash' => ApiKey::hashKey($plainKey),
            'secret_prefix' => substr($plainSecret, 0, 20), 'secret_hash' => ApiKey::hashSecret($plainSecret),
        ]);

        return ['key' => $plainKey, 'secret' => $plainSecret];
    }

    private function createNative(User $user, string $query = '')
    {
        return $this->actingAs($user)->postJson('/api/groups/create'.$query, [
            'name' => 'Crew',
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
            'contacts' => [['phone_number' => self::MEMBER, 'name' => 'Bob']],
        ]);
    }

    private function used(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->value('used_messages');
    }

    private function capabilityDenials(Account $account): int
    {
        return ActivityLog::where('module_name', EntitlementAuditLogger::MODULE)
            ->where('account_id', $account->id)->where('action_type', 'denied')->get()
            ->filter(fn ($r) => ($r->new_values['capability'] ?? null) === 'whatsapp_groups')
            ->count();
    }

    // ---------------------------------------------------------------
    // Tenant UI API
    // ---------------------------------------------------------------

    public function test_an_entitled_account_can_create_a_native_group(): void
    {
        $account = $this->qrTenant(entitled: true);

        $this->createNative($this->user($account))->assertCreated()->assertJsonPath('success', true);

        $this->assertSame(1, ContactGroup::where('account_id', $account->id)->where('group_type', ContactGroup::GROUP_TYPE_NATIVE)->count());
        Queue::assertPushed(CreateNativeWhatsAppGroupJob::class);
    }

    public function test_an_account_without_the_capability_is_denied_native_group_creation(): void
    {
        $account = $this->qrTenant(entitled: false);

        $this->createNative($this->user($account))
            ->assertStatus(403)
            ->assertJson(['success' => false, 'error_code' => 'CAPABILITY_NOT_ENTITLED']);

        $this->assertSame(0, ContactGroup::where('account_id', $account->id)->count());
        Queue::assertNotPushed(CreateNativeWhatsAppGroupJob::class);
        $this->assertSame(1, $this->capabilityDenials($account));
    }

    public function test_a_revoked_capability_is_denied(): void
    {
        $account = $this->qrTenant(entitled: true);
        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => now(), 'revoked_reason' => 'manual']);

        $this->createNative($this->user($account))->assertStatus(403);
    }

    public function test_contact_list_groups_do_not_need_the_capability(): void
    {
        $account = $this->qrTenant(entitled: false);
        $admin = $this->user($account);

        $created = $this->actingAs($admin)->postJson('/api/groups/create', ['name' => 'VIP'])->assertCreated();
        $groupId = $created->json('data.id');

        $this->actingAs($admin)->postJson('/api/groups/add-contacts', [
            'group_id' => $groupId, 'contacts' => [['phone_number' => self::MEMBER]],
        ])->assertOk();

        $this->template($account);
        $this->actingAs($admin)->postJson("/api/groups/{$groupId}/send-template", [
            'template_id' => MessageTemplate::where('account_id', $account->id)->value('id'),
        ])->assertOk();
        Queue::assertPushed(ProcessGroupDispatchJob::class);
    }

    public function test_native_only_routes_cannot_be_reached_directly_without_the_capability(): void
    {
        $account = $this->qrTenant(entitled: false);
        $admin = $this->user($account);
        $failed = $this->nativeGroup($account, ContactGroup::SYNC_STATUS_FAILED);

        $this->actingAs($admin)->getJson('/api/groups/available-native')
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->actingAs($admin)->postJson('/api/groups/import-native', ['group_jid' => '120363111@g.us'])
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->actingAs($admin)->postJson("/api/groups/{$failed->id}/recreate")
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $this->assertSame(ContactGroup::SYNC_STATUS_FAILED, $failed->fresh()->sync_status, 'recreate did not run');
        Queue::assertNotPushed(CreateNativeWhatsAppGroupJob::class);
    }

    public function test_native_only_routes_still_work_when_entitled(): void
    {
        $account = $this->qrTenant(entitled: true);
        $admin = $this->user($account);
        $failed = $this->nativeGroup($account, ContactGroup::SYNC_STATUS_FAILED);

        $this->actingAs($admin)->getJson('/api/groups/available-native')->assertOk();
        $this->actingAs($admin)->postJson("/api/groups/{$failed->id}/recreate")->assertOk();
        Queue::assertPushed(CreateNativeWhatsAppGroupJob::class);
    }

    public function test_adding_members_to_a_native_group_needs_the_capability(): void
    {
        $account = $this->qrTenant(entitled: false);
        $group = $this->nativeGroup($account);

        $this->actingAs($this->user($account))->postJson('/api/groups/add-contacts', [
            'group_id' => $group->id, 'contacts' => [['phone_number' => '918888888888']],
        ])->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $this->assertSame(1, ContactGroupMember::where('group_id', $group->id)->count());
        Queue::assertNotPushed(SyncNativeWhatsAppGroupParticipantsJob::class);
    }

    public function test_super_admin_may_send_to_a_native_group_of_a_client_without_the_capability(): void
    {
        $account = $this->qrTenant(entitled: false);
        $group = $this->nativeGroup($account);
        $template = $this->template($account);
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin)->postJson("/api/groups/{$group->id}/send-template?account_id={$account->id}", ['template_id' => $template->id]);

        $this->assertNotSame(403, $response->status(), 'the Super Admin bypass applies to UI sends');
        Queue::assertPushed(ProcessGroupDispatchJob::class);
    }

    public function test_a_native_group_send_from_the_ui_needs_the_capability_and_reserves_nothing(): void
    {
        $account = $this->qrTenant(entitled: false);
        $group = $this->nativeGroup($account);
        $template = $this->template($account);

        $this->actingAs($this->user($account))->postJson("/api/groups/{$group->id}/send-template", ['template_id' => $template->id])
            ->assertStatus(403);

        $this->assertSame(0, $this->used($account));
        $this->assertSame(0, MessageDispatchLog::where('account_id', $account->id)->count());
        Queue::assertNotPushed(ProcessGroupDispatchJob::class);
    }

    public function test_the_existing_permission_check_still_applies(): void
    {
        $account = $this->qrTenant(entitled: true);
        $marketer = $this->user($account, 'social_marketer'); // no send-messages

        $this->createNative($marketer)->assertStatus(403);
        $this->actingAs($marketer)->getJson('/api/groups/available-native')->assertStatus(403);

        $this->assertSame(0, ContactGroup::where('account_id', $account->id)->count());
    }

    public function test_the_existing_module_check_still_applies(): void
    {
        $account = $this->qrTenant(entitled: true, attributes: ['allowed_modules' => ['dashboard', 'send_alert']]);

        $this->createNative($this->user($account))->assertStatus(403)->assertJsonPath('error_code', 'GROUP_MODULE_DISABLED');
    }

    // ---------------------------------------------------------------
    // Tenant isolation
    // ---------------------------------------------------------------

    public function test_the_capability_is_checked_on_the_agents_target_sub_client(): void
    {
        $agent = Account::factory()->agent()->create();
        Subscription::factory()->create(['account_id' => $agent->id]);
        $this->grantNativeWhatsAppGroups($agent); // the Agent's own entitlement must not leak to the client
        $client = Account::factory()->client($agent)->create();
        Subscription::factory()->create(['account_id' => $client->id]);
        WhatsAppSession::create(['account_id' => $client->id, 'status' => 'connected']);
        $agentAdmin = $this->user($agent, ['admin', 'agent']);

        $this->createNative($agentAdmin, "?account_id={$client->id}")
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->assertSame(0, ContactGroup::where('account_id', $client->id)->count());

        $this->grantNativeWhatsAppGroups($client);
        $this->createNative($agentAdmin, "?account_id={$client->id}")->assertCreated();
        $this->assertSame(1, ContactGroup::where('account_id', $client->id)->count());
    }

    public function test_an_agent_cannot_reach_another_accounts_groups(): void
    {
        $agent = Account::factory()->agent()->create();
        Subscription::factory()->create(['account_id' => $agent->id]);
        $foreign = $this->qrTenant(entitled: true); // not one of this Agent's clients
        $group = $this->nativeGroup($foreign, ContactGroup::SYNC_STATUS_FAILED);
        $agentAdmin = $this->user($agent, ['admin', 'agent']);

        $this->createNative($agentAdmin, "?account_id={$foreign->id}")->assertNotFound();
        $this->actingAs($agentAdmin)->postJson("/api/groups/{$group->id}/recreate?account_id={$foreign->id}")->assertNotFound();
        $this->assertSame(1, ContactGroup::where('account_id', $foreign->id)->count());
    }

    public function test_a_client_cannot_use_another_clients_native_group(): void
    {
        $owner = $this->qrTenant(entitled: true);
        $other = $this->qrTenant(entitled: true);
        $group = $this->nativeGroup($owner);
        $template = $this->template($other);

        $this->actingAs($this->user($other))->postJson("/api/groups/{$group->id}/send-template", ['template_id' => $template->id])
            ->assertNotFound();
        $this->assertSame(0, $this->used($owner));
        $this->assertSame(0, $this->used($other));
    }

    public function test_super_admin_keeps_the_existing_capability_bypass(): void
    {
        $client = $this->qrTenant(entitled: false);
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');

        $this->createNative($superAdmin, "?account_id={$client->id}")->assertCreated();
        $this->actingAs($superAdmin)->getJson("/api/groups/available-native?account_id={$client->id}")->assertOk();
    }

    // ---------------------------------------------------------------
    // Public API (/api/v1) — direct access
    // ---------------------------------------------------------------

    public function test_v1_native_group_creation_needs_the_capability(): void
    {
        $denied = $this->qrTenant(entitled: false);
        $issued = $this->issueApiKey($denied);
        $payload = ['name' => 'Crew', 'group_type' => ContactGroup::GROUP_TYPE_NATIVE, 'contacts' => [['phone_number' => self::MEMBER]]];

        $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', $payload)
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->assertSame(0, ContactGroup::where('account_id', $denied->id)->count());

        // A contact list over the same endpoint is unaffected.
        $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'VIP'])
            ->assertCreated();

        $allowed = $this->qrTenant(entitled: true);
        $ok = $this->issueApiKey($allowed);
        $this->withHeaders(['X-API-KEY' => $ok['key'], 'X-API-SECRET' => $ok['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', $payload)
            ->assertCreated();
    }

    public function test_v1_template_send_to_a_native_group_needs_the_capability(): void
    {
        $account = $this->qrTenant(entitled: false);
        $group = $this->nativeGroup($account);
        $this->template($account);
        $issued = $this->issueApiKey($account);

        $this->withHeader('X-API-KEY', $issued['key'])->postJson('/api/v1/send-message', [
            'recipient_type' => 'group', 'template_code' => 'GROUP_TPL', 'group_code' => $group->group_code,
        ])->assertStatus(403)->assertJsonPath('error_code', 'GROUP_ACCESS_DENIED');

        $this->assertSame(0, $this->used($account));
        $this->assertSame(0, MessageDispatchLog::where('account_id', $account->id)->count());
        Queue::assertNotPushed(ProcessGroupDispatchJob::class);
    }

    public function test_v1_direct_send_to_a_native_group_needs_the_capability(): void
    {
        $account = $this->qrTenant(entitled: false);
        $group = $this->nativeGroup($account);
        $issued = $this->issueApiKey($account);

        $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/messages/send', [
                'recipient_type' => 'group', 'message_type' => 'text',
                'group_id' => $group->wa_group_jid, 'text' => ['body' => 'Hi crew'],
            ])->assertStatus(403)->assertJsonPath('error_code', 'GROUP_ACCESS_DENIED');

        $this->assertSame(0, $this->used($account));
        Queue::assertNotPushed(ProcessGroupDirectMessageJob::class);
    }

    public function test_v1_group_sends_work_when_entitled(): void
    {
        $account = $this->qrTenant(entitled: true);
        $group = $this->nativeGroup($account);
        $this->template($account);
        $issued = $this->issueApiKey($account);

        $this->withHeader('X-API-KEY', $issued['key'])->postJson('/api/v1/send-message', [
            'recipient_type' => 'group', 'template_code' => 'GROUP_TPL', 'group_code' => $group->group_code,
        ])->assertSuccessful();

        Queue::assertPushed(ProcessGroupDispatchJob::class);
        $this->assertSame(1, $this->used($account), 'the existing reservation still happens');
    }
}
