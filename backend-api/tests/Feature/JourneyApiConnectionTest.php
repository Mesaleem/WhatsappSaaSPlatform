<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JourneyApiConnection;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppNumber;
use App\Support\JourneySecrets;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8 Task 15 — "Manage All APIs" (JourneyApiConnectionController +
 * assertApiConnectionsOwned() on WhatsAppFlowController).
 *
 * Covers: account-scoped CRUD, credential-pair encryption/masking
 * (JourneyApiConnectionSecrets, the same convention JourneySecrets uses
 * for an `api` node's own headers/query), the MASK-keep-on-update
 * contract, duplicate-name rejection, and the save-time ownership check
 * an `api` node's `apiConnectionId` goes through (mirrors
 * assertKnowledgeBasesOwned()'s own test coverage).
 *
 * UNVERIFIED: written without a reachable PHP/phpunit runtime in the
 * authoring session — run `php artisan test --filter=JourneyApiConnection`
 * before trusting this file; see Phase 8 Task 15's row in PROJECT_STATE.md.
 */
class JourneyApiConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function account(): Account
    {
        $account = Account::factory()->create();

        // The route group is gated behind capability.guard:journey_automation
        // (routes/api.php) on top of module.guard:chatbot — a plain
        // Account::factory() row has neither an active subscription nor
        // any granted entitlement, so EVERY request below would be
        // refused 403 CAPABILITY_NOT_ENTITLED before ever reaching
        // JourneyApiConnectionController without this. Same grant()
        // pattern JourneyNodeEntitlementTest/JourneyClassifierTest use.
        $this->grant($account, 'journey_automation');

        return $account;
    }

    private function grant(Account $account, string $slug): void
    {
        \App\Models\AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => \App\Models\Capability::where('slug', $slug)->firstOrFail()->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }

    private function user(?Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function url(Account $account, string $suffix = ''): string
    {
        return "/api/whatsapp/api-connections{$suffix}?account_id={$account->id}";
    }

    // ==================================================================

    public function test_a_connection_is_created_with_its_sensitive_header_value_encrypted_and_masked_back(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $response = $this->actingAs($user)->postJson($this->url($account), [
            'name' => 'CRM Lookup',
            'base_url' => 'https://crm.example.com/v1',
            'headers' => [['key' => 'Authorization', 'value' => 'Bearer super-secret-token'], ['key' => 'Content-Type', 'value' => 'application/json']],
        ])->assertCreated();

        $response->assertJsonPath('data.name', 'CRM Lookup');
        $response->assertJsonPath('data.headers.0.key', 'Authorization');
        $response->assertJsonPath('data.headers.0.value', JourneySecrets::MASK);
        $response->assertJsonPath('data.headers.0.masked', true);
        // Content-Type is plain configuration — never masked.
        $response->assertJsonPath('data.headers.1.value', 'application/json');

        $row = JourneyApiConnection::query()->forAccount($account->id)->firstWhere('name', 'CRM Lookup');
        $this->assertTrue(JourneySecrets::isEncrypted($row->headers[0]['value']));
        $this->assertSame('Bearer super-secret-token', JourneySecrets::reveal($row->headers[0]['value']));
        $this->assertSame('application/json', $row->headers[1]['value'], 'non-credential pair stored in plaintext');
    }

    public function test_a_duplicate_name_on_the_same_account_is_rejected(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $this->actingAs($user)->postJson($this->url($account), ['name' => 'Dup', 'base_url' => 'https://a.example.com'])->assertCreated();
        $this->actingAs($user)->postJson($this->url($account), ['name' => 'Dup', 'base_url' => 'https://b.example.com'])->assertStatus(422);
    }

    public function test_the_same_name_is_allowed_on_a_different_account(): void
    {
        $accountA = $this->account();
        $accountB = $this->account();

        $this->actingAs($this->user($accountA))->postJson($this->url($accountA), ['name' => 'Shared Name'])->assertCreated();
        $this->actingAs($this->user($accountB))->postJson($this->url($accountB), ['name' => 'Shared Name'])->assertCreated();

        $this->assertSame(2, JourneyApiConnection::where('name', 'Shared Name')->count());
    }

    public function test_updating_without_resending_a_secret_keeps_the_stored_value_via_mask(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $create = $this->actingAs($user)->postJson($this->url($account), [
            'name' => 'Webhook', 'headers' => [['key' => 'X-Api-Key', 'value' => 'original-secret']],
        ])->assertCreated();
        $id = $create->json('data.id');

        // Client sends back exactly what index()/show() gave it — the MASK — for the field it did not change.
        $this->actingAs($user)->putJson($this->url($account, "/{$id}"), [
            'name' => 'Webhook Renamed', 'headers' => [['key' => 'X-Api-Key', 'value' => JourneySecrets::MASK]],
        ])->assertOk()->assertJsonPath('data.name', 'Webhook Renamed');

        $row = JourneyApiConnection::find($id);
        $this->assertSame('original-secret', JourneySecrets::reveal($row->headers[0]['value']));
    }

    public function test_an_unresolvable_mask_on_update_is_refused(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $create = $this->actingAs($user)->postJson($this->url($account), ['name' => 'NoSecretYet'])->assertCreated();
        $id = $create->json('data.id');

        $this->actingAs($user)->putJson($this->url($account, "/{$id}"), [
            'name' => 'NoSecretYet', 'headers' => [['key' => 'Authorization', 'value' => JourneySecrets::MASK]],
        ])->assertStatus(422);
    }

    public function test_a_connection_is_scoped_to_its_own_account_not_visible_or_editable_from_another(): void
    {
        $owner = $this->account();
        $other = $this->account();
        $connection = JourneyApiConnection::create(['account_id' => $owner->id, 'name' => 'Private']);

        $this->actingAs($this->superAdmin())->getJson($this->url($other))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->superAdmin())->putJson($this->url($other, "/{$connection->id}"), ['name' => 'Hijacked'])->assertStatus(404);
    }

    public function test_deleting_a_connection_removes_it(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $create = $this->actingAs($user)->postJson($this->url($account), ['name' => 'ToDelete'])->assertCreated();

        $this->actingAs($user)->deleteJson($this->url($account, '/'.$create->json('data.id')))->assertOk();

        $this->assertSame(0, JourneyApiConnection::count());
    }

    // ------------------------------------------------------------------
    // assertApiConnectionsOwned() — the save-time guard on an `api` node.
    // ------------------------------------------------------------------

    /**
     * Channel-binding gate (Connexxa parity, Task 21) — every one of these
     * flow-creation payloads now needs a `whatsapp_number_ids` channel, or
     * WhatsAppFlowController::validateFlow() rejects it 422 before this
     * test's own apiConnectionId assertions are ever reached. One slot per
     * account is enough to satisfy the gate; the number itself is
     * incidental to what this test is actually checking.
     */
    private function channelFor(Account $account): WhatsAppNumber
    {
        return WhatsAppNumber::create([
            'account_id' => $account->id,
            'phone_number' => '91900'.str_pad((string) $account->id, 7, '0', STR_PAD_LEFT),
            'status' => WhatsAppNumber::STATUS_LINKED,
        ]);
    }

    private function flowPayload(array $apiNodeData, array $whatsappNumberIds = []): array
    {
        return [
            'name' => 'API Node Journey',
            'trigger_type' => 'keyword',
            'trigger_value' => 'hello',
            'whatsapp_number_ids' => $whatsappNumberIds,
            'graph_data' => [
                'nodes' => [
                    ['id' => 'n1', 'type' => 'trigger', 'data' => []],
                    ['id' => 'n2', 'type' => 'api', 'data' => array_merge(['method' => 'GET', 'url' => 'https://example.com'], $apiNodeData)],
                ],
                'edges' => [['id' => 'e1', 'source' => 'n1', 'target' => 'n2']],
            ],
            'publish' => false,
        ];
    }

    public function test_an_api_node_may_reference_its_own_accounts_connection(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $connection = JourneyApiConnection::create(['account_id' => $account->id, 'name' => 'Usable']);
        $number = $this->channelFor($account);

        $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->flowPayload(['apiConnectionId' => $connection->id], [$number->id]))
            ->assertCreated();
    }

    public function test_an_api_node_referencing_another_accounts_connection_is_rejected_as_not_found(): void
    {
        $account = $this->account();
        $other = $this->account();
        $user = $this->user($account);
        $foreignConnection = JourneyApiConnection::create(['account_id' => $other->id, 'name' => 'NotYours']);
        $number = $this->channelFor($account);

        $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->flowPayload(['apiConnectionId' => $foreignConnection->id], [$number->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['graph_data.nodes.1.data.apiConnectionId']);

        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_an_api_node_with_no_apiConnectionId_is_unaffected(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $number = $this->channelFor($account);

        $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->flowPayload([], [$number->id]))
            ->assertCreated();
    }
}
