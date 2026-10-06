<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\WhatsApp\WhatsAppNumberService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WhatsApp number slots: included number + paid add-ons (agreed 2026-10-05).
 * Covers the uniqueness rule across tenants, the included/default rules, the
 * country-code check, and the lock that holds until the plan expires.
 */
class WhatsAppNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{0: Account, 1: User} */
    private function tenant(): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user];
    }

    public function test_the_first_number_is_the_included_default_and_later_ones_are_not(): void
    {
        [$account, $user] = $this->tenant();

        $first = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])
            ->assertCreated()->json('data');
        $second = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '917236062374'])
            ->assertCreated()->json('data');

        $this->assertTrue($first['is_included']);
        $this->assertTrue($first['is_default']);
        $this->assertFalse($second['is_included']);
        $this->assertFalse($second['is_default']);
        $this->assertSame('pending_payment', $second['status']);
    }

    public function test_the_same_number_cannot_be_added_twice_on_one_account(): void
    {
        [, $user] = $this->tenant();
        $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])->assertCreated();

        $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '+91 98765-43210'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'number_already_used');

        $this->assertSame(1, WhatsAppNumber::count());
    }

    public function test_a_number_already_used_by_another_tenant_is_refused_without_naming_that_tenant(): void
    {
        [, $owner] = $this->tenant();
        [, $other] = $this->tenant();
        $this->actingAs($owner)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])->assertCreated();

        $response = $this->actingAs($other)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'number_already_used');

        $this->assertStringNotContainsString('account', strtolower($response->json('message')));
        $this->assertSame(1, WhatsAppNumber::where('phone_number', '919876543210')->count());
    }

    public function test_the_database_refuses_a_duplicate_even_without_the_service(): void
    {
        [$account] = $this->tenant();
        WhatsAppNumber::create(['account_id' => $account->id, 'phone_number' => '919876543210']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        WhatsAppNumber::create(['account_id' => Account::factory()->create()->id, 'phone_number' => '919876543210']);
    }

    public function test_a_ten_digit_number_without_a_country_code_is_refused(): void
    {
        [, $user] = $this->tenant();

        $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '7236062374'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'missing_country_code');
    }

    public function test_a_malformed_number_is_refused(): void
    {
        [, $user] = $this->tenant();

        $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '0919876543210'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'invalid_phone_number');
        $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => 'call me'])
            ->assertUnprocessable();
    }

    public function test_the_default_moves_only_when_every_number_is_linked_and_the_subscription_is_active(): void
    {
        [$account, $user] = $this->tenant();
        $first = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])->json('data');
        $second = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '917236062374'])->json('data');

        // Not linked yet: the change is refused, and the rule is shown on each row.
        $this->actingAs($user)->putJson("/api/whatsapp/numbers/{$second['id']}/default")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'default_not_allowed');
        $this->actingAs($user)->getJson('/api/whatsapp/numbers')
            ->assertJsonPath('data.1.can_set_default', false);

        // Everything linked with a running term: the change is allowed.
        WhatsAppNumber::where('account_id', $account->id)->update([
            'status' => WhatsAppNumber::STATUS_LINKED,
        ]);
        WhatsAppNumber::whereKey($second['id'])->update(['term_ends_at' => now()->addWeek()]);

        $this->actingAs($user)->getJson('/api/whatsapp/numbers')
            ->assertJsonPath('data.1.can_set_default', true);
        $this->actingAs($user)->putJson("/api/whatsapp/numbers/{$second['id']}/default")
            ->assertOk()
            ->assertJsonPath('data.1.is_default', true);

        $this->assertSame(1, WhatsAppNumber::where('account_id', $account->id)->where('is_default', true)->count());
        $this->assertTrue((bool) WhatsAppNumber::whereKey($first['id'])->value('is_default') === false);
    }

    public function test_the_default_cannot_move_to_a_number_whose_term_has_ended(): void
    {
        [$account, $user] = $this->tenant();
        $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])->assertCreated();
        $second = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '917236062374'])->json('data');

        WhatsAppNumber::where('account_id', $account->id)->update(['status' => WhatsAppNumber::STATUS_LINKED]);
        WhatsAppNumber::whereKey($second['id'])->update(['term_ends_at' => now()->subDay()]);

        $this->actingAs($user)->putJson("/api/whatsapp/numbers/{$second['id']}/default")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'default_not_allowed');
    }

    public function test_the_default_cannot_move_while_the_subscription_is_not_active(): void
    {
        [$account, $user] = $this->tenant();
        $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])->assertCreated();
        $second = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '917236062374'])->json('data');
        WhatsAppNumber::where('account_id', $account->id)->update(['status' => WhatsAppNumber::STATUS_LINKED]);
        WhatsAppNumber::whereKey($second['id'])->update(['term_ends_at' => now()->addWeek()]);
        \App\Models\Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);

        $this->actingAs($user)->putJson("/api/whatsapp/numbers/{$second['id']}/default")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'default_not_allowed');
    }

    public function test_an_included_number_cannot_be_removed_but_an_unpaid_extra_can(): void
    {
        [, $user] = $this->tenant();
        $included = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])->json('data');
        $extra = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '917236062374'])->json('data');

        $this->actingAs($user)->deleteJson("/api/whatsapp/numbers/{$included['id']}")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'included_number');

        $this->actingAs($user)->deleteJson("/api/whatsapp/numbers/{$extra['id']}")->assertOk();
        $this->assertDatabaseMissing('whatsapp_numbers', ['id' => $extra['id']]);
    }

    public function test_a_locked_account_cannot_add_remove_or_change_the_default(): void
    {
        [$account, $user] = $this->tenant();
        $included = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])->json('data');
        $extra = $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '917236062374'])->json('data');

        app(WhatsAppNumberService::class)->lockAll($account);

        $this->actingAs($user)->postJson('/api/whatsapp/numbers', ['phone_number' => '918888888888'])
            ->assertStatus(409)->assertJsonPath('error_code', 'numbers_locked');
        $this->actingAs($user)->deleteJson("/api/whatsapp/numbers/{$extra['id']}")
            ->assertStatus(409)->assertJsonPath('error_code', 'numbers_locked');
        // A locked account can still change the default under the linked-and-active rule.
        // Here the numbers are not linked, so the change is refused for that reason.
        $this->actingAs($user)->putJson("/api/whatsapp/numbers/{$extra['id']}/default")
            ->assertStatus(409)->assertJsonPath('error_code', 'default_not_allowed');

        $this->actingAs($user)->getJson('/api/whatsapp/numbers')
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('data.0.id', $included['id'])
            ->assertJsonPath('data.0.locked', true);
    }

    public function test_another_tenants_number_id_is_reported_as_not_found(): void
    {
        [, $owner] = $this->tenant();
        [, $other] = $this->tenant();
        $number = $this->actingAs($owner)->postJson('/api/whatsapp/numbers', ['phone_number' => '919876543210'])->json('data');

        $this->actingAs($other)->putJson("/api/whatsapp/numbers/{$number['id']}/default")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'not_found');
        $this->actingAs($other)->deleteJson("/api/whatsapp/numbers/{$number['id']}")->assertNotFound();

        $this->assertDatabaseHas('whatsapp_numbers', ['id' => $number['id']]);
    }
}
