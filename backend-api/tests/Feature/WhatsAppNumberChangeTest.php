<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppNumberChangeRequest;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A slot holding a wrongly entered number: the client asks for a change, a Super Admin or agent decides. */
class WhatsAppNumberChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function client(?Account $agent = null, string $phone = '919800000001'): array
    {
        $account = Account::factory()->create(['agent_id' => $agent?->id, 'allowed_modules' => ['dashboard', 'whatsapp_setup']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $slot = WhatsAppNumber::query()->create([
            'account_id' => $account->id,
            'phone_number' => $phone,
            'is_included' => true,
            'is_default' => true,
            'status' => WhatsAppNumber::STATUS_UNLINKED,
        ]);

        return [$account, $user, $slot];
    }

    private function agent(): array
    {
        $account = Account::factory()->create(['account_type' => 'agent']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');
        $user->assignRole('agent');

        return [$account, $user];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function askChange(User $user, WhatsAppNumber $slot, string $newPhone = '919800000002'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson("/api/whatsapp/numbers/{$slot->id}/change-requests", [
            'new_phone' => $newPhone,
            'reason' => 'I typed the wrong number',
        ]);
    }

    public function test_a_client_asks_for_a_change_and_the_slot_does_not_change_yet(): void
    {
        [, $user, $slot] = $this->client();

        $this->askChange($user, $slot)->assertCreated()->assertJsonPath('data.status', 'requested');

        $this->assertSame('919800000001', $slot->fresh()->phone_number, 'nothing changes until an admin approves');
    }

    public function test_the_same_number_or_a_number_used_elsewhere_is_refused(): void
    {
        [, $user, $slot] = $this->client();
        [, $otherUser, $otherSlot] = $this->client(null, '919800000005');

        $this->askChange($user, $slot, '919800000001')->assertStatus(422)->assertJsonPath('error_code', 'same_number');
        $this->askChange($user, $slot, '919800000001')->assertStatus(422);
        $this->askChange($user, $slot, $otherSlot->phone_number)->assertStatus(409)->assertJsonPath('error_code', 'number_already_used');
    }

    public function test_a_second_open_request_for_the_same_slot_waits_for_the_first(): void
    {
        [, $user, $slot] = $this->client();
        $this->askChange($user, $slot, '919800000002')->assertCreated();

        $this->askChange($user, $slot, '919800000003')->assertStatus(409)->assertJsonPath('error_code', 'request_pending');
    }

    public function test_a_reason_is_required(): void
    {
        [, $user, $slot] = $this->client();

        $this->actingAs($user)->postJson("/api/whatsapp/numbers/{$slot->id}/change-requests", ['new_phone' => '919800000002'])
            ->assertUnprocessable();
    }

    public function test_a_super_admin_approves_and_the_slot_takes_the_new_number_and_must_be_connected_again(): void
    {
        [$account, $user, $slot] = $this->client();
        $slot->forceFill(['status' => WhatsAppNumber::STATUS_LINKED])->save();
        $id = $this->askChange($user, $slot)->json('data.id');

        $this->actingAs($this->superAdmin())->postJson("/api/admin/whatsapp/number-change-requests/{$id}/approve")->assertOk();

        $slot->refresh();
        $this->assertSame('919800000002', $slot->phone_number);
        $this->assertSame(WhatsAppNumber::STATUS_UNLINKED, $slot->status);
        $this->assertSame('approved', WhatsAppNumberChangeRequest::query()->findOrFail($id)->status);
        $this->assertTrue(InAppNotification::query()->where('user_id', $user->id)->where('title', 'WhatsApp number changed')->exists());
    }

    public function test_an_agent_decides_only_for_its_own_clients_and_never_for_a_direct_one(): void
    {
        [$agentAccount, $agentUser] = $this->agent();
        [, $mine, $mySlot] = $this->client($agentAccount);
        [, $direct, $directSlot] = $this->client(null, '919800000009');

        $mineId = $this->askChange($mine, $mySlot)->json('data.id');
        $directId = $this->askChange($direct, $directSlot)->json('data.id');

        $this->actingAs($agentUser)->getJson('/api/admin/whatsapp/number-change-requests')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mineId);

        $this->actingAs($agentUser)->postJson("/api/admin/whatsapp/number-change-requests/{$directId}/approve")
            ->assertForbidden()->assertJsonPath('error_code', 'self_signup_needs_super_admin');

        $this->actingAs($agentUser)->postJson("/api/admin/whatsapp/number-change-requests/{$mineId}/approve")->assertOk();
        $this->assertSame('919800000002', $mySlot->fresh()->phone_number);
    }

    public function test_a_rejected_change_leaves_the_number_as_it_was_with_the_note(): void
    {
        [, $user, $slot] = $this->client();
        $id = $this->askChange($user, $slot)->json('data.id');

        $this->actingAs($this->superAdmin())->postJson("/api/admin/whatsapp/number-change-requests/{$id}/reject", ['note' => 'Please send the number from your WhatsApp profile'])
            ->assertOk();

        $this->assertSame('919800000001', $slot->fresh()->phone_number);
        $row = WhatsAppNumberChangeRequest::query()->findOrFail($id);
        $this->assertSame('rejected', $row->status);
        $this->assertSame('Please send the number from your WhatsApp profile', $row->decision_note);
    }

    public function test_a_client_cannot_decide_its_own_request(): void
    {
        [, $user, $slot] = $this->client();
        $id = $this->askChange($user, $slot)->json('data.id');

        $this->actingAs($user)->postJson("/api/admin/whatsapp/number-change-requests/{$id}/approve")->assertForbidden();
        $this->assertSame('919800000001', $slot->fresh()->phone_number);
    }
}
