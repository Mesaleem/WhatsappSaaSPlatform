<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Groups\GroupMessageDispatcher;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A downgrade leaves the client to choose which groups stay open for the new term; the others are
 * locked until the next term. An upgrade only raises the allowance and locks nothing.
 */
class GroupDowngradeTest extends TestCase
{
    use RefreshDatabase;

    private int $txn = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function client(): array
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
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

    /** Buys a term for $units groups: request, approve, and record the payment starting today. */
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

    /** Creates custom groups while the module is on, so the client can have more than the next term allows. */
    private function makeGroups(User $user, int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->actingAs($user)->postJson('/api/groups/create', [
                'name' => "Group {$i}", 'group_type' => 'internal_segment',
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
}
