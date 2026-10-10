<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppNumber;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 21 — Journey creation: require channel selection (Connexxa parity).
 *
 * ConnexxaIQ will not let a journey be built at all without first picking
 * 1-2 channels on its Create Journey dialog ("Channels (up to 2)*"). This
 * is the wa-saas equivalent of that gate: WhatsAppFlow::MAX_CHANNELS,
 * WhatsAppFlowController::validateFlow()'s `whatsapp_number_ids` rule, and
 * assertWhatsAppNumbersOwned()'s ownership check, backed by the
 * whatsapp_flow_numbers pivot (WhatsAppFlow::whatsappNumbers()).
 *
 * SCOPE NOTE (see whatsapp_flow_numbers' migration docblock and
 * CONNEXXA_JOURNEY_GAP_ANALYSIS_2026_10_10.md): this is a creation/update-time
 * gate and metadata record only. It does NOT yet filter inbound trigger
 * matching — WhatsAppJourneyEngine::matchFlow() is unchanged — because
 * whatsapp_sessions.account_id is UNIQUE (one live engine connection per
 * account today). That is a separate, platform-wide multi-session-per-account
 * project, out of scope here by the user's own decision.
 *
 * UNVERIFIED: written without a reachable PHP/phpunit runtime in the
 * authoring session — run `php artisan test --filter=JourneyChannelGate`
 * before trusting this file.
 */
class JourneyChannelGateTest extends TestCase
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

        // Same reasoning as JourneyApiConnectionTest::account() — the
        // journey routes sit behind capability.guard:journey_automation on
        // top of module.guard:chatbot.
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

    private function user(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function channel(Account $account, string $suffix = ''): WhatsAppNumber
    {
        return WhatsAppNumber::create([
            'account_id' => $account->id,
            'phone_number' => '9190'.str_pad((string) $account->id, 5, '0', STR_PAD_LEFT).$suffix,
            'status' => WhatsAppNumber::STATUS_LINKED,
        ]);
    }

    private function basePayload(array $whatsappNumberIds): array
    {
        return [
            'name' => 'Channel Gate Journey',
            'trigger_type' => 'keyword',
            'trigger_value' => 'hello',
            'whatsapp_number_ids' => $whatsappNumberIds,
            'graph_data' => [
                'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []]],
                'edges' => [],
            ],
            'publish' => false,
        ];
    }

    // ==================================================================
    // Creation gate
    // ==================================================================

    public function test_creation_is_refused_without_any_channel(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $payload = $this->basePayload([]);
        unset($payload['whatsapp_number_ids']);

        $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_number_ids']);

        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_creation_is_refused_with_an_empty_channel_list(): void
    {
        $account = $this->account();
        $user = $this->user($account);

        $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->basePayload([]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_number_ids']);

        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_creation_is_refused_with_a_cross_tenant_channel_id(): void
    {
        $account = $this->account();
        $other = $this->account();
        $user = $this->user($account);
        $foreignNumber = $this->channel($other);

        $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->basePayload([$foreignNumber->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_number_ids']);

        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_creation_is_refused_beyond_max_channels(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $a = $this->channel($account, '1');
        $b = $this->channel($account, '2');
        $c = $this->channel($account, '3');

        $this->assertSame(2, WhatsAppFlow::MAX_CHANNELS, 'this test assumes the current Connexxa-parity cap');

        $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->basePayload([$a->id, $b->id, $c->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_number_ids']);

        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_creation_succeeds_with_one_owned_channel_and_syncs_the_pivot(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $number = $this->channel($account);

        $response = $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->basePayload([$number->id]))
            ->assertCreated();

        $flow = WhatsAppFlow::find($response->json('data.id'));
        $this->assertSame([$number->id], $flow->whatsappNumbers()->pluck('whatsapp_numbers.id')->all());
        // Eloquent's relationsToArray() keeps the relation key exactly as
        // loaded ('whatsappNumbers', not snake_cased) — see
        // WhatsAppFlowController::store()'s ->load('whatsappNumbers').
        $this->assertSame([$number->id], $response->json('data.whatsappNumbers.*.id'));
    }

    public function test_creation_succeeds_with_two_owned_channels_at_the_cap(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $a = $this->channel($account, '1');
        $b = $this->channel($account, '2');

        $response = $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->basePayload([$a->id, $b->id]))
            ->assertCreated();

        $flow = WhatsAppFlow::find($response->json('data.id'));
        $this->assertSame([$a->id, $b->id], $flow->whatsappNumbers()->pluck('whatsapp_numbers.id')->sort()->values()->all());
    }

    // ==================================================================
    // Update: same gate, plus re-sync behaviour
    // ==================================================================

    public function test_update_is_refused_if_it_would_leave_the_journey_with_zero_channels(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $number = $this->channel($account);
        $id = $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->basePayload([$number->id]))
            ->assertCreated()->json('data.id');

        $payload = $this->basePayload([]);
        $this->actingAs($user)->putJson("/api/whatsapp/flows/{$id}?account_id={$account->id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_number_ids']);

        $flow = WhatsAppFlow::find($id);
        $this->assertSame([$number->id], $flow->whatsappNumbers()->pluck('whatsapp_numbers.id')->all(), 'the refused update changed nothing');
    }

    public function test_update_is_refused_with_a_cross_tenant_channel_id(): void
    {
        $account = $this->account();
        $other = $this->account();
        $user = $this->user($account);
        $number = $this->channel($account);
        $foreignNumber = $this->channel($other);
        $id = $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->basePayload([$number->id]))
            ->assertCreated()->json('data.id');

        $this->actingAs($user)->putJson("/api/whatsapp/flows/{$id}?account_id={$account->id}", $this->basePayload([$foreignNumber->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_number_ids']);

        $this->assertSame([$number->id], WhatsAppFlow::find($id)->whatsappNumbers()->pluck('whatsapp_numbers.id')->all());
    }

    public function test_update_can_re_point_the_journey_at_a_different_owned_channel(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $original = $this->channel($account, '1');
        $replacement = $this->channel($account, '2');
        $id = $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->basePayload([$original->id]))
            ->assertCreated()->json('data.id');

        $this->actingAs($user)->putJson("/api/whatsapp/flows/{$id}?account_id={$account->id}", $this->basePayload([$replacement->id]))
            ->assertOk();

        $this->assertSame([$replacement->id], WhatsAppFlow::find($id)->whatsappNumbers()->pluck('whatsapp_numbers.id')->all());
    }

    // ==================================================================
    // Listing exposes the binding
    // ==================================================================

    public function test_index_and_show_eager_load_the_bound_channels(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $number = $this->channel($account);
        $id = $this->actingAs($user)->postJson("/api/whatsapp/flows?account_id={$account->id}", $this->basePayload([$number->id]))
            ->assertCreated()->json('data.id');

        $this->actingAs($user)->getJson("/api/whatsapp/flows?account_id={$account->id}")
            ->assertOk()
            ->assertJsonPath('data.0.whatsappNumbers.0.id', $number->id);

        $this->actingAs($user)->getJson("/api/whatsapp/flows/{$id}?account_id={$account->id}")
            ->assertOk()
            ->assertJsonPath('data.whatsappNumbers.0.id', $number->id);
    }
}
