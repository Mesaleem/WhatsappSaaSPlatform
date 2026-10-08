<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Groups\GroupMessageDispatcher;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GrantsNativeWhatsAppGroups;
use Tests\TestCase;

/**
 * [Re-scoped 2026-10-07, disclosed]: this term/lock mechanism (CustomGroupAccessService, the
 * `contact_groups` module-addon) now governs Native WhatsApp Groups, not internal segment groups --
 * internal segment groups are a free, always-on, unlimited feature (see ContactGroupController's own
 * re-scoping docblock). This file was entirely about internal_segment groups before this re-scoping;
 * it is rewritten here to exercise the exact same downgrade/lock/choose behavior against native
 * groups instead, which is the one that actually has a term/allowance to lock now.
 *
 * A downgrade leaves the client to choose which groups stay open for the new term; the others are
 * locked until the next term. An upgrade only raises the allowance and locks nothing.
 */
class GroupDowngradeTest extends TestCase
{
    use RefreshDatabase, GrantsNativeWhatsAppGroups;

    private int $txn = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true, 'groups' => []], 200)]);
    }

    private function client(): array
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
        // Native groups also need the `whatsapp_groups` capability (on/off) -- separate from, and
        // untouched by, the unit-count term this file actually tests.
        $this->grantNativeWhatsAppGroups($account);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** Buys a term for $units native groups: request, approve, and record the payment starting today. */
    private function buyTerm(User $user, int $units): void
    {
        $id = $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => $units])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$id}/approve")->assertOk();
        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$id}/record-payment", [
            'amount' => $units <= 3 ? 99 : 199, 'method' => 'cash', 'transaction_id' => 'G-'.(++$this->txn),
            'paid_on' => now()->toDateString(), 'term_starts_on' => now()->toDateString(),
        ])->assertOk();
    }

    /** Creates native WhatsApp groups while the term is on, so the client can have more than the next term allows. */
    private function makeGroups(User $user, int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->actingAs($user)->postJson('/api/groups/create', [
                'name' => "Group {$i}", 'group_type' => 'native_wa_group',
                'contacts' => [['phone_number' => '9198765'.str_pad((string) (40000 + $i), 5, '0', STR_PAD_LEFT)]],
            ])->assertCreated()->json('data.id');
        }

        return $ids;
    }

    public function test_a_downgrade_locks_the_groups_beyond_the_new_term_until_the_client_chooses(): void
    {
        [$account, $user] = $this->client();
        $this->buyTerm($user, 6);
        $ids = $this->makeGroups($user, 6);

        // The 6-group term ends and a 3-group term starts: a fresh term over the allowance.
        ContactGroup::query()->where('account_id', $account->id)->update(['locked_at' => null]);
        $account->fresh();
        \App\Models\ModuleAddonRequest::query()->where('account_id', $account->id)->update(['term_ends_at' => now()->subDay()]);
        // The hourly job ends the term and switches the module off, as in production.
        $this->artisan('modules:enforce-addons')->assertSuccessful();
        $this->buyTerm($user, 3);

        $this->actingAs($user)->getJson('/api/groups')->assertOk()
            ->assertJsonPath('usage.selection_required', true)
            ->assertJsonPath('usage.limit', 3);
        $this->assertSame(6, ContactGroup::query()->where('account_id', $account->id)->whereNotNull('locked_at')->count());

        // A locked group cannot be sent to.
        $result = GroupMessageDispatcher::dispatch($account->id, $ids[0], 999, []);
        $this->assertSame('group_locked', $result['status']);

        // Choosing the wrong number is refused, then three groups are kept.
        $this->actingAs($user)->postJson('/api/groups/keep', ['keep_ids' => [$ids[0], $ids[1]]])
            ->assertUnprocessable()->assertJsonPath('error_code', 'invalid_selection');

        $this->actingAs($user)->postJson('/api/groups/keep', ['keep_ids' => [$ids[0], $ids[2], $ids[4]]])->assertOk();

        $this->assertSame(3, ContactGroup::query()->where('account_id', $account->id)->whereNull('locked_at')->count());
        $this->actingAs($user)->getJson('/api/groups')->assertOk()->assertJsonPath('usage.selection_required', false);
    }

    public function test_a_client_cannot_keep_another_clients_group(): void
    {
        [$account, $user] = $this->client();
        $this->buyTerm($user, 3);
        $this->makeGroups($user, 3);

        [$other, $otherUser] = $this->client();
        $this->buyTerm($otherUser, 3);
        $foreign = $this->makeGroups($otherUser, 1)[0];

        $this->actingAs($user)->postJson('/api/groups/keep', ['keep_ids' => [$foreign, 1, 2]])
            ->assertUnprocessable();
    }

    public function test_an_upgrade_locks_nothing_and_raises_the_allowance(): void
    {
        [$account, $user] = $this->client();
        $this->buyTerm($user, 3);
        $this->makeGroups($user, 3);

        $this->buyTerm($user, 6);

        $this->actingAs($user)->getJson('/api/groups')->assertOk()
            ->assertJsonPath('usage.selection_required', false)
            ->assertJsonPath('usage.limit', 6);
        $this->assertSame(0, ContactGroup::query()->where('account_id', $account->id)->whereNotNull('locked_at')->count());

        $this->makeGroups($user, 1);
        $this->assertSame(4, ContactGroup::query()->where('account_id', $account->id)->where('is_default', false)->count());
    }

    public function test_a_smaller_tier_cannot_start_while_the_current_term_runs(): void
    {
        [, $user] = $this->client();
        $this->buyTerm($user, 6);

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'downgrade_after_term');
    }

    public function test_creating_one_more_native_group_than_the_term_allows_is_refused(): void
    {
        [, $user] = $this->client();
        $this->buyTerm($user, 2);
        $this->makeGroups($user, 2);

        $this->actingAs($user)->postJson('/api/groups/create', [
            'name' => 'One too many', 'group_type' => 'native_wa_group',
            'contacts' => [['phone_number' => '919876500099']],
        ])->assertUnprocessable()->assertJsonPath('error_code', 'group_limit_reached');
    }

    public function test_internal_segment_groups_are_unlimited_and_free_regardless_of_the_native_term(): void
    {
        [$account, $user] = $this->client();
        // No module-addon term bought at all -- internal segment groups still work, without limit.
        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($user)->postJson('/api/groups/create', ['name' => "Dept {$i}", 'group_type' => 'internal_segment'])
                ->assertCreated();
        }

        $this->assertSame(5, ContactGroup::query()->where('account_id', $account->id)->where('group_type', 'internal_segment')->where('is_default', false)->count());
        $this->actingAs($user)->getJson('/api/groups')->assertOk()->assertJsonPath('usage.limit', null);
    }
}
