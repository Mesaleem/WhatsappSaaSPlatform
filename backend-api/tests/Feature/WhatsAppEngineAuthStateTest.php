<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppEngineAuthState;
use App\Models\WhatsAppNumber;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Baileys credential storage for the QR engine, keyed by WhatsApp NUMBER SLOT
 * (Phase 2), plus the tenant start-session path and the status webhook.
 */
class WhatsAppEngineAuthStateTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config(['services.qr_engine.internal_secret' => self::SECRET]);
    }

    private function internal(): static
    {
        return $this->withHeader('X-Internal-Secret', self::SECRET);
    }

    /** A tenant account with one WhatsApp number slot (the included, default one). */
    private function slotFor(Account $account, string $phone = '919876543210', bool $default = true): WhatsAppNumber
    {
        return WhatsAppNumber::create([
            'account_id' => $account->id,
            'phone_number' => $phone,
            'is_included' => $default,
            'is_default' => $default,
            'status' => WhatsAppNumber::STATUS_UNLINKED,
        ]);
    }

    private function newAccountSlot(string $phone = '919876543210'): WhatsAppNumber
    {
        return $this->slotFor(Account::factory()->create(), $phone);
    }

    public function test_internal_routes_reject_a_missing_or_wrong_secret(): void
    {
        $slot = $this->newAccountSlot();

        $this->getJson("/api/internal/whatsapp-auth/{$slot->id}")->assertUnauthorized();
        $this->withHeader('X-Internal-Secret', 'wrong')
            ->getJson("/api/internal/whatsapp-auth/{$slot->id}")
            ->assertUnauthorized();
        $this->withHeader('X-Internal-Secret', 'wrong')
            ->getJson('/api/internal/whatsapp-auth/paired')
            ->assertUnauthorized();
        $this->withHeader('X-Internal-Secret', 'wrong')
            ->getJson("/api/internal/whatsapp-numbers/{$slot->id}")
            ->assertUnauthorized();
    }

    public function test_credentials_are_stored_and_returned_for_the_number_slot(): void
    {
        $slot = $this->newAccountSlot();

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$slot->id}", [
            'set' => [
                'creds.json' => '{"registered":true}',
                'pre-key-1.json' => '{"keyId":1}',
            ],
            'delete' => [],
        ])->assertOk()->assertJsonPath('stored', 2);

        $entries = $this->internal()->getJson("/api/internal/whatsapp-auth/{$slot->id}")
            ->assertOk()
            ->json('entries');

        $this->assertSame('{"registered":true}', $entries['creds.json']);
        $this->assertSame('{"keyId":1}', $entries['pre-key-1.json']);
    }

    public function test_a_key_name_with_dots_and_symbols_is_accepted(): void
    {
        $slot = $this->newAccountSlot();
        $name = 'session-919876543210.0-45@s.whatsapp.net.json';

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$slot->id}", [
            'set' => [$name => '{"v":1}'],
            'delete' => [],
        ])->assertOk();

        $entries = $this->internal()->getJson("/api/internal/whatsapp-auth/{$slot->id}")->json('entries');
        $this->assertSame('{"v":1}', $entries[$name]);
    }

    public function test_a_name_with_a_space_or_a_path_separator_is_rejected(): void
    {
        $slot = $this->newAccountSlot();

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$slot->id}", [
            'set' => ['bad name.json' => '{}'],
            'delete' => [],
        ])->assertUnprocessable();

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$slot->id}", [
            'set' => [],
            'delete' => ['../etc/passwd'],
        ])->assertUnprocessable();
    }

    public function test_values_are_encrypted_at_rest(): void
    {
        $slot = $this->newAccountSlot();
        $plaintext = '{"registered":true,"noiseKey":"very-secret-material"}';

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$slot->id}", [
            'set' => ['creds.json' => $plaintext],
            'delete' => [],
        ])->assertOk();

        $raw = DB::table('whatsapp_engine_auth_states')->where('whatsapp_number_id', $slot->id)->value('value');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('very-secret-material', $raw, 'credential material must not be stored in plaintext');
    }

    public function test_the_model_never_serialises_the_credential_value(): void
    {
        $slot = $this->newAccountSlot();
        WhatsAppEngineAuthState::create(['whatsapp_number_id' => $slot->id, 'name' => 'creds.json', 'value' => '{"x":1}']);

        $row = WhatsAppEngineAuthState::where('whatsapp_number_id', $slot->id)->first();

        $this->assertArrayNotHasKey('value', $row->toArray());
        $this->assertSame('{"x":1}', $row->value, 'the value is still readable in code, only hidden from output');
    }

    public function test_a_slot_only_sees_its_own_keys(): void
    {
        $first = $this->newAccountSlot('919876543210');
        $second = $this->newAccountSlot('917236062374');

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$first->id}", [
            'set' => ['creds.json' => '{"owner":"first"}'],
            'delete' => [],
        ])->assertOk();

        $this->internal()->getJson("/api/internal/whatsapp-auth/{$second->id}")
            ->assertOk()
            ->assertJsonPath('entries', []);
    }

    public function test_delete_removes_only_the_named_keys(): void
    {
        $slot = $this->newAccountSlot();

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$slot->id}", [
            'set' => ['a.json' => '1', 'b.json' => '2'],
            'delete' => [],
        ])->assertOk();

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$slot->id}", [
            'set' => [],
            'delete' => ['a.json'],
        ])->assertOk();

        $entries = $this->internal()->getJson("/api/internal/whatsapp-auth/{$slot->id}")->json('entries');
        $this->assertArrayNotHasKey('a.json', $entries);
        $this->assertSame('2', $entries['b.json']);
    }

    public function test_destroy_forgets_every_key_for_the_slot_only(): void
    {
        $slot = $this->newAccountSlot('919876543210');
        $other = $this->newAccountSlot('917236062374');

        foreach ([$slot, $other] as $owner) {
            $this->internal()->putJson("/api/internal/whatsapp-auth/{$owner->id}", [
                'set' => ['creds.json' => '{"registered":true}'],
                'delete' => [],
            ])->assertOk();
        }

        $this->internal()->deleteJson("/api/internal/whatsapp-auth/{$slot->id}")->assertOk();

        $this->internal()->getJson("/api/internal/whatsapp-auth/{$slot->id}")->assertJsonPath('entries', []);
        $otherEntries = $this->internal()->getJson("/api/internal/whatsapp-auth/{$other->id}")->json('entries');
        $this->assertSame('{"registered":true}', $otherEntries['creds.json']);
    }

    public function test_a_qr_paired_slot_is_listed_even_though_registered_stays_false(): void
    {
        // Baileys 7 rc14 leaves registered=false after a QR pairing; the device identity (me) is the signal.
        $slot = $this->newAccountSlot();

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$slot->id}", [
            'set' => ['creds.json' => '{"registered":false,"me":{"id":"919222222222:7@s.whatsapp.net"}}'],
            'delete' => [],
        ])->assertOk();

        $this->internal()->getJson('/api/internal/whatsapp-auth/paired')
            ->assertOk()
            ->assertJsonPath('number_ids', [$slot->id]);
    }

    public function test_paired_lists_only_slots_whose_credentials_completed_pairing(): void
    {
        $paired = $this->newAccountSlot('919876543210');
        $midPairing = $this->newAccountSlot('917236062374');

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$paired->id}", [
            'set' => ['creds.json' => '{"registered":true}'],
            'delete' => [],
        ])->assertOk();

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$midPairing->id}", [
            'set' => ['creds.json' => '{"registered":false}'],
            'delete' => [],
        ])->assertOk();

        $this->internal()->getJson('/api/internal/whatsapp-auth/paired')
            ->assertOk()
            ->assertJsonPath('number_ids', [$paired->id]);
    }

    public function test_an_unknown_slot_is_refused_before_anything_is_written(): void
    {
        $this->internal()->putJson('/api/internal/whatsapp-auth/999999', [
            'set' => ['creds.json' => '{}'],
            'delete' => [],
        ])->assertNotFound();

        $this->assertDatabaseCount('whatsapp_engine_auth_states', 0);
    }

    /* ---- Super Admin's own "test WhatsApp" device: lazily-created slot, adopted number ---- */

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    /**
     * [Bug fix regression test]: whatsapp_engine_auth_states.whatsapp_number_id has a
     * hard foreign key to whatsapp_numbers, so the platform device's auth-state calls
     * (keyed by session_id, same as any tenant slot now -- see
     * WhatsAppController::platformDeviceSlot()) 404'd ("WhatsApp number not found")
     * until that slot actually existed as a real row, lazily created on first connect.
     */
    public function test_the_platform_devices_first_connect_creates_a_real_slot_auth_state_storage_then_works(): void
    {
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/whatsapp/self-device/start-session')
            ->assertOk();

        $platform = \App\Models\Account::platformDevice();
        $slot = WhatsAppNumber::where('account_id', $platform->id)->firstOrFail();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/qr/start-session')
            && $request['session_id'] === $slot->id
            && $request['account_id'] === $platform->id);

        $this->internal()->putJson("/api/internal/whatsapp-auth/{$slot->id}", [
            'set' => ['creds.json' => '{"registered":true}'],
            'delete' => [],
        ])->assertOk()->assertJsonPath('stored', 1);

        $entries = $this->internal()->getJson("/api/internal/whatsapp-auth/{$slot->id}")->assertOk()->json('entries');
        $this->assertSame('{"registered":true}', $entries['creds.json']);
    }

    /** A second self-device start-session reuses the same slot rather than creating another. */
    public function test_a_second_self_device_start_session_reuses_the_same_slot(): void
    {
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);
        $this->actingAs($this->superAdmin())->postJson('/api/admin/whatsapp/self-device/start-session')->assertOk();
        $this->actingAs($this->superAdmin())->postJson('/api/admin/whatsapp/self-device/start-session')->assertOk();

        $platform = \App\Models\Account::platformDevice();
        $this->assertSame(1, WhatsAppNumber::where('account_id', $platform->id)->count());
    }

    /**
     * [Owner instruction, disclosed]: the platform device's slot supports pairing-code
     * (phone number) login too, not just QR -- same as any other account's default
     * slot. Previously selfDeviceStartSession() took no phone_number at all and the
     * frontend hid the "Phone number" tab whenever a custom startSession was passed.
     */
    public function test_a_typed_number_starts_a_pairing_code_session_on_the_platform_device(): void
    {
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/whatsapp/self-device/start-session', ['phone_number' => '919876543210'])
            ->assertOk();

        $platform = \App\Models\Account::platformDevice();
        $slot = WhatsAppNumber::where('account_id', $platform->id)->firstOrFail();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/qr/start-session')
            && $request['session_id'] === $slot->id
            && $request['phone_number'] === '919876543210'
            && ! array_key_exists('expected_phone', $request->data()));
    }

    /**
     * Once the platform device's slot has adopted a real number (see
     * test_the_platform_devices_connected_number_is_adopted_not_refused_as_a_mismatch()),
     * it behaves like any other pre-typed slot: a DIFFERENT typed number is refused
     * before any engine call, same as test_a_typed_number_that_is_not_the_slot_number_
     * is_refused_before_any_engine_call() for an ordinary tenant.
     */
    public function test_a_typed_number_that_differs_from_the_platform_devices_adopted_number_is_refused(): void
    {
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);
        $this->actingAs($this->superAdmin())->postJson('/api/admin/whatsapp/self-device/start-session')->assertOk();

        $platform = \App\Models\Account::platformDevice();
        $slot = WhatsAppNumber::where('account_id', $platform->id)->firstOrFail();

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $platform->id,
            'session_id' => $slot->id,
            'status' => 'connected',
            'phone_number' => '919876543210',
        ])->assertOk();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/whatsapp/self-device/start-session', ['phone_number' => '917236062374'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'number_mismatch');
    }

    /**
     * [Owner instruction, disclosed]: "duplicate na ho" -- a real WhatsApp number must
     * not end up adopted onto two different slots. phone_number is globally UNIQUE
     * (see the whatsapp_numbers migration), so without this check the second adoption
     * would throw a raw SQL unique-constraint exception instead of a clean refusal.
     */
    public function test_a_number_already_adopted_by_another_slot_is_refused_not_adopted_twice(): void
    {
        [$accountA] = $this->tenantWithSlot('919876543210');
        $slotA = WhatsAppNumber::where('account_id', $accountA->id)->firstOrFail();
        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $accountA->id,
            'session_id' => $slotA->id,
            'status' => 'connected',
            'phone_number' => '919876543210',
        ])->assertOk();

        $accountB = Account::factory()->create();
        Subscription::factory()->for($accountB)->create(['engine_type' => 'qr']);
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);
        $user = User::factory()->create(['account_id' => $accountB->id]);
        $user->assignRole('admin');
        $this->actingAs($user)->postJson('/api/whatsapp/start-session')->assertOk();
        $slotB = WhatsAppNumber::where('account_id', $accountB->id)->firstOrFail();
        $this->assertTrue($slotB->hasPendingPlaceholderNumber());

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $accountB->id,
            'session_id' => $slotB->id,
            'status' => 'connected',
            'phone_number' => '919876543210',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'number_already_used');

        $this->assertSame(WhatsAppNumber::STATUS_UNLINKED, $slotB->fresh()->status);
        $this->assertTrue($slotB->fresh()->hasPendingPlaceholderNumber(), 'slot B must not have adopted account A\'s number');
    }

    /**
     * The platform device's slot starts with a placeholder phone_number (nothing was
     * typed in advance); WhatsAppStatusController::update() must adopt whatever number
     * actually connects for this ONE account instead of refusing it as a mismatch --
     * the way an ordinary tenant's own pre-typed slot still correctly does (see
     * test_a_slot_that_links_to_a_different_number_is_refused_and_stays_unlinked()).
     */
    public function test_the_platform_devices_connected_number_is_adopted_not_refused_as_a_mismatch(): void
    {
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);
        $this->actingAs($this->superAdmin())->postJson('/api/admin/whatsapp/self-device/start-session')->assertOk();

        $platform = \App\Models\Account::platformDevice();
        $slot = WhatsAppNumber::where('account_id', $platform->id)->firstOrFail();
        $this->assertNotSame('919876543210', $slot->phone_number, 'the slot must start on a placeholder, not the real number');

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $platform->id,
            'session_id' => $slot->id,
            'status' => 'connected',
            'phone_number' => '919876543210',
        ])->assertOk();

        $slot->refresh();
        $this->assertSame('919876543210', $slot->phone_number);
        $this->assertSame(WhatsAppNumber::STATUS_LINKED, $slot->status);
    }

    public function test_the_slot_owner_lookup_names_the_account_and_number(): void
    {
        $account = Account::factory()->create();
        $slot = $this->slotFor($account, '917236062374', false);

        $this->internal()->getJson("/api/internal/whatsapp-numbers/{$slot->id}")
            ->assertOk()
            ->assertJsonPath('account_id', $account->id)
            ->assertJsonPath('phone_number', '917236062374');

        $this->internal()->getJson('/api/internal/whatsapp-numbers/999999')->assertNotFound();
    }

    /* ---- tenant start-session: slot-keyed, pairing-code login ---- */

    /** @return array{0: Account, 1: User, 2: WhatsAppNumber} */
    private function tenantWithSlot(string $phone = '919876543210'): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $slot = $this->slotFor($account, $phone);

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user, $slot];
    }

    public function test_start_session_forwards_the_default_slot_and_a_normalised_number(): void
    {
        [$account, $user, $slot] = $this->tenantWithSlot('919876543210');
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);

        $this->actingAs($user)
            ->postJson('/api/whatsapp/start-session', ['phone_number' => '+91 98765-43210'])
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/qr/start-session')
            && $request['session_id'] === $slot->id
            && $request['expected_phone'] === '919876543210'
            && $request['phone_number'] === '919876543210'
            && $request['account_id'] === $account->id);
    }

    public function test_start_session_without_a_number_keeps_the_qr_flow_on_the_default_slot(): void
    {
        [$account, $user, $slot] = $this->tenantWithSlot();
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);

        $this->actingAs($user)->postJson('/api/whatsapp/start-session')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/qr/start-session')
            && $request['session_id'] === $slot->id
            && ! array_key_exists('phone_number', $request->data()));
    }

    public function test_a_typed_number_that_is_not_the_slot_number_is_refused_before_any_engine_call(): void
    {
        [, $user] = $this->tenantWithSlot('919876543210');
        Http::fake();

        $this->actingAs($user)
            ->postJson('/api/whatsapp/start-session', ['phone_number' => '917236062374'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'number_mismatch');

        Http::assertNothingSent();
    }

    /**
     * [Owner instruction, disclosed]: an account's default slot no longer requires a
     * number typed in advance -- an account with zero WhatsApp number rows gets one
     * auto-created here (WhatsAppController::ensureDefaultSlot()), with a placeholder
     * phone_number, and the QR flow proceeds immediately with no expected_phone sent
     * to qr-engine-service (so any real WhatsApp account can connect to it) -- the
     * same mechanism the platform device already used, generalised to every account.
     */
    public function test_an_account_without_any_number_gets_a_default_slot_auto_created_and_starts_a_session(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);

        $this->actingAs($user)->postJson('/api/whatsapp/start-session')->assertOk();

        $slot = WhatsAppNumber::where('account_id', $account->id)->firstOrFail();
        $this->assertTrue($slot->is_default);
        $this->assertTrue($slot->hasPendingPlaceholderNumber());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/qr/start-session')
            && $request['session_id'] === $slot->id
            && ! array_key_exists('expected_phone', $request->data())
            && $request['account_id'] === $account->id);
    }

    /**
     * The auto-created default slot's placeholder number is adopted on connect for an
     * ORDINARY tenant too, exactly like the platform device's own slot (see
     * test_the_platform_devices_connected_number_is_adopted_not_refused_as_a_mismatch()) --
     * an already-verified slot (tenantWithSlot()) still refuses a mismatch unchanged,
     * see test_a_slot_that_links_to_a_different_number_is_refused_and_stays_unlinked().
     */
    public function test_an_ordinary_accounts_auto_created_default_slot_adopts_whatever_number_connects(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');
        Http::fake(['*/api/qr/start-session' => Http::response(['status' => 'connecting'], 202)]);
        $this->actingAs($user)->postJson('/api/whatsapp/start-session')->assertOk();

        $slot = WhatsAppNumber::where('account_id', $account->id)->firstOrFail();

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'session_id' => $slot->id,
            'status' => 'connected',
            'phone_number' => '919876543210',
        ])->assertOk();

        $slot->refresh();
        $this->assertSame('919876543210', $slot->phone_number);
        $this->assertSame(WhatsAppNumber::STATUS_LINKED, $slot->status);
    }

    public function test_a_ten_digit_number_without_a_country_code_is_refused_with_a_clear_message(): void
    {
        [, $user] = $this->tenantWithSlot();
        Http::fake();

        $this->actingAs($user)
            ->postJson('/api/whatsapp/start-session', ['phone_number' => '7236062374'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone_number');

        Http::assertNothingSent();
    }

    public function test_start_session_rejects_a_too_short_number_a_leading_zero_or_letters(): void
    {
        [, $user] = $this->tenantWithSlot();
        Http::fake();

        $this->actingAs($user)
            ->postJson('/api/whatsapp/start-session', ['phone_number' => '12345'])
            ->assertUnprocessable();
        $this->actingAs($user)
            ->postJson('/api/whatsapp/start-session', ['phone_number' => '0919876543210'])
            ->assertUnprocessable();
        $this->actingAs($user)
            ->postJson('/api/whatsapp/start-session', ['phone_number' => 'call-me-now'])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    /* ---- status webhook: legacy account-level and slot-level ---- */

    public function test_a_connected_status_reports_the_linked_number_to_the_tenant(): void
    {
        [$account, $user] = $this->tenantWithSlot();

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'status' => 'connected',
            'phone_number' => '919876543210',
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/whatsapp/status')
            ->assertOk()
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('phone_number', '919876543210');
    }

    public function test_disconnecting_clears_the_linked_number(): void
    {
        [$account, $user] = $this->tenantWithSlot();

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'status' => 'connected',
            'phone_number' => '919876543210',
        ])->assertOk();

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'status' => 'disconnected',
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/whatsapp/status')
            ->assertOk()
            ->assertJsonPath('status', 'disconnected')
            ->assertJsonPath('phone_number', null);
    }

    public function test_a_malformed_linked_number_is_refused(): void
    {
        [$account] = $this->tenantWithSlot();

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'status' => 'connected',
            'phone_number' => '+91 98765',
        ])->assertUnprocessable();
    }

    public function test_a_slot_that_links_to_a_different_number_is_refused_and_stays_unlinked(): void
    {
        [$account] = $this->tenantWithSlot('919876543210');
        $slot = WhatsAppNumber::where('account_id', $account->id)->firstOrFail();

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'session_id' => $slot->id,
            'status' => 'connected',
            'phone_number' => '917236062374',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'number_mismatch');

        $this->assertSame(WhatsAppNumber::STATUS_UNLINKED, $slot->fresh()->status);
    }

    public function test_a_slot_connection_on_its_own_number_marks_the_slot_linked(): void
    {
        [$account] = $this->tenantWithSlot('919876543210');
        $slot = WhatsAppNumber::where('account_id', $account->id)->firstOrFail();

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'session_id' => $slot->id,
            'status' => 'connected',
            'phone_number' => '919876543210',
        ])->assertOk();

        $this->assertSame(WhatsAppNumber::STATUS_LINKED, $slot->fresh()->status);
    }

    public function test_a_disconnect_reported_for_a_slot_marks_it_not_linked_again(): void
    {
        [$account] = $this->tenantWithSlot('919876543210');
        $slot = WhatsAppNumber::where('account_id', $account->id)->firstOrFail();

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'session_id' => $slot->id,
            'status' => 'connected',
            'phone_number' => '919876543210',
        ])->assertOk();
        $this->assertSame(WhatsAppNumber::STATUS_LINKED, $slot->fresh()->status);

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'session_id' => $slot->id,
            'status' => 'disconnected',
        ])->assertOk();

        $this->assertSame(WhatsAppNumber::STATUS_UNLINKED, $slot->fresh()->status);
    }

    public function test_a_status_for_a_slot_of_another_account_is_refused(): void
    {
        [$account] = $this->tenantWithSlot('919876543210');
        $otherSlot = $this->newAccountSlot('917236062374');

        $this->internal()->postJson('/api/internal/whatsapp-status', [
            'account_id' => $account->id,
            'session_id' => $otherSlot->id,
            'status' => 'connected',
        ])->assertNotFound();
    }
}
