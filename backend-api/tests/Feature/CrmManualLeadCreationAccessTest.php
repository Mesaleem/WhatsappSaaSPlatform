<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\CrmLead;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Crm\PlatformCrmAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRM UI fix (post Phase 6) — manual lead creation through the existing
 * POST /api/crm/leads, for every caller the CRM Leads page serves:
 * Super Admin acting on the client picked in the header switcher
 * (?account_id=, TenantIsolationMiddleware), an Agent on its own
 * sub-client, and a client Admin on its own account. No new endpoint:
 * these tests pin the existing contract the "Add lead" button relies on.
 */
class CrmManualLeadCreationAccessTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/crm/leads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function account(bool $crm = true, bool $active = true, array $attributes = [], ?callable $factory = null): Account
    {
        $account = ($factory ? $factory(Account::factory()) : Account::factory())->create($attributes);
        $active
            ? Subscription::factory()->create(['account_id' => $account->id])
            : Subscription::factory()->expired()->create(['account_id' => $account->id]);
        if ($crm) {
            AccountEntitlement::firstOrCreate(
                ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->firstOrFail()->id],
                ['source' => 'manual_grant', 'granted_by_account_id' => null],
            );
        }

        return $account->fresh();
    }

    private function user(Account $account, ?string $role = 'admin', array $permissions = []): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        if ($role) {
            $user->assignRole($role);
        }
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }

        return $user;
    }

    /** Like the real platform: the Super Admin user has no account (users.account_id NULL). */
    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function body(array $extra = []): array
    {
        return $extra + ['phone_number' => '9811111111', 'name' => 'Meera', 'source' => 'manual', 'status' => 'new', 'assigned_user_id' => null];
    }

    // --- Super Admin -------------------------------------------------

    /** Without a platform CRM account (e.g. a fresh install before `crm:platform-account`), unchanged: 422. */
    public function test_super_admin_without_a_platform_account_and_no_selection_gets_422(): void
    {
        $this->account();

        $this->actingAs($this->superAdmin())->postJson(self::URL, $this->body())->assertStatus(422);
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_super_admin_creates_a_lead_for_the_selected_client(): void
    {
        $client = $this->account();
        $other = $this->account();

        $r = $this->actingAs($this->superAdmin())->postJson(self::URL.'?account_id='.$client->id, $this->body())->assertCreated();

        $lead = CrmLead::findOrFail($r->json('data.id'));
        $this->assertSame($client->id, $lead->account_id);
        $this->assertSame($client->id, $lead->contact->account_id);
        $this->assertSame('919811111111', $lead->contact->phone_number);
        $this->assertSame(['manual', 'new'], [$lead->source, $lead->status]);
        $this->assertSame(0, CrmLead::where('account_id', $other->id)->count());
    }

    public function test_a_body_account_id_never_overrides_the_selected_client(): void
    {
        $client = $this->account();
        $other = $this->account();

        $r = $this->actingAs($this->superAdmin())
            ->postJson(self::URL.'?account_id='.$client->id, $this->body(['account_id' => $other->id]))
            ->assertCreated();

        $this->assertSame($client->id, CrmLead::findOrFail($r->json('data.id'))->account_id);
        $this->assertSame(0, CrmLead::where('account_id', $other->id)->count());
    }

    public function test_super_admin_cannot_target_an_account_that_does_not_exist(): void
    {
        $this->actingAs($this->superAdmin())->postJson(self::URL.'?account_id=99999999', $this->body())->assertNotFound();
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_super_admin_cannot_assign_the_lead_to_another_tenants_user(): void
    {
        $client = $this->account();
        $foreignUser = $this->user($this->account(), 'admin');

        $this->actingAs($this->superAdmin())
            ->postJson(self::URL.'?account_id='.$client->id, $this->body(['assigned_user_id' => $foreignUser->id]))
            ->assertStatus(422);
        $this->assertDatabaseCount('crm_leads', 0);
    }

    // --- Client Admin / Agent ----------------------------------------

    public function test_a_client_admin_creates_on_its_own_account_and_cannot_redirect_it(): void
    {
        $mine = $this->account();
        $theirs = $this->account();

        $r = $this->actingAs($this->user($mine))
            ->postJson(self::URL.'?account_id='.$theirs->id, $this->body(['account_id' => $theirs->id]))
            ->assertCreated();

        $this->assertSame($mine->id, CrmLead::findOrFail($r->json('data.id'))->account_id);
        $this->assertSame(0, CrmLead::where('account_id', $theirs->id)->count());
    }

    public function test_an_agent_creates_for_its_own_sub_client_but_not_for_a_stranger(): void
    {
        $agent = $this->account(factory: fn ($f) => $f->agent());
        $sub = $this->account(factory: fn ($f) => $f->client($agent));
        $stranger = $this->account();
        $agentUser = $this->user($agent, null, ['manage-crm']);

        $r = $this->actingAs($agentUser)->postJson(self::URL.'?account_id='.$sub->id, $this->body())->assertCreated();
        $this->assertSame($sub->id, CrmLead::findOrFail($r->json('data.id'))->account_id);

        $this->actingAs($agentUser)->postJson(self::URL.'?account_id='.$stranger->id, $this->body(['phone_number' => '9822222222']))->assertNotFound();
        $this->assertSame(0, CrmLead::where('account_id', $stranger->id)->count());
    }

    // --- Gates ---------------------------------------------------------

    /** @return array<string, array{callable, int, ?string}> */
    public static function deniedProvider(): array
    {
        return [
            'no crm capability' => [fn (self $t) => $t->user($t->account(crm: false)), 403, 'CAPABILITY_NOT_ENTITLED'],
            'lead_crm module off' => [fn (self $t) => $t->user($t->account(attributes: ['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))])), 403, 'MODULE_DISABLED'],
            'no manage-crm' => [fn (self $t) => $t->user($t->account(), 'user'), 403, null],
            'expired subscription' => [fn (self $t) => $t->user($t->account(active: false)), 403, 'SUBSCRIPTION_EXPIRED'],
        ];
    }

    /** @dataProvider deniedProvider */
    public function test_creation_is_refused_without_every_crm_gate(callable $makeUser, int $status, ?string $code): void
    {
        $response = $this->actingAs($makeUser($this))->postJson(self::URL, $this->body())->assertStatus($status);
        if ($code) {
            $response->assertJsonPath('error_code', $code);
        }
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_unauthenticated_creation_is_401(): void
    {
        $this->postJson(self::URL, $this->body())->assertUnauthorized();
    }

    // --- No Meta / Social / WhatsApp connection needed ---------------

    public function test_manual_creation_needs_no_meta_social_or_whatsapp_connection(): void
    {
        $client = $this->account();
        $this->assertSame(0, SocialAccount::where('account_id', $client->id)->count());
        $this->assertSame(0, WhatsAppSession::where('account_id', $client->id)->count());

        $this->actingAs($this->user($client))->postJson(self::URL, $this->body())->assertCreated();
        $this->actingAs($this->superAdmin())->postJson(self::URL.'?account_id='.$client->id, $this->body(['phone_number' => '9833333333']))->assertCreated();

        $this->assertSame(2, CrmLead::where('account_id', $client->id)->count());
    }

    // --- Super Admin's own platform CRM account ------------------------

    private function platform(): Account
    {
        return app(PlatformCrmAccount::class)->ensure()['account'];
    }

    public function test_super_admin_with_no_client_selected_creates_under_the_platform_account(): void
    {
        $platform = $this->platform();
        $client = $this->account();
        $sa = $this->superAdmin();

        $r = $this->actingAs($sa)->postJson(self::URL, $this->body())->assertCreated();

        $lead = CrmLead::findOrFail($r->json('data.id'));
        $this->assertSame($platform->id, $lead->account_id);
        $this->assertSame($platform->id, $lead->contact->account_id);
        $this->assertSame(0, CrmLead::where('account_id', $client->id)->count());
        $this->assertNull($sa->fresh()->account_id, 'the Super Admin user stays tenant-less');
    }

    public function test_the_no_selection_list_shows_only_the_platform_accounts_leads(): void
    {
        $this->platform();
        $client = $this->account();
        $sa = $this->superAdmin();
        $this->actingAs($this->user($client))->postJson(self::URL, $this->body(['phone_number' => '9844444444']))->assertCreated();
        $mine = $this->actingAs($sa)->postJson(self::URL, $this->body())->assertCreated()->json('data.id');

        $this->actingAs($sa)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
    }

    public function test_request_parameters_cannot_redirect_the_platform_target(): void
    {
        $platform = $this->platform();
        $client = $this->account();
        $sa = $this->superAdmin();

        $r = $this->actingAs($sa)->postJson(self::URL, $this->body(['account_id' => $client->id, 'tenant_id' => $client->id]))->assertCreated();

        $this->assertSame($platform->id, CrmLead::findOrFail($r->json('data.id'))->account_id);
        $this->assertSame(0, CrmLead::where('account_id', $client->id)->count());
    }

    public function test_a_selected_entitled_client_still_wins_over_the_platform_account(): void
    {
        $platform = $this->platform();
        $client = $this->account();

        $r = $this->actingAs($this->superAdmin())->postJson(self::URL.'?account_id='.$client->id, $this->body())->assertCreated();

        $this->assertSame($client->id, CrmLead::findOrFail($r->json('data.id'))->account_id);
        $this->assertSame(0, CrmLead::where('account_id', $platform->id)->count());
    }

    public function test_a_selected_client_without_crm_is_blocked_for_super_admin(): void
    {
        $this->platform();
        $noCrm = $this->account(crm: false);
        $moduleOff = $this->account(attributes: ['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))]);
        $sa = $this->superAdmin();

        $this->actingAs($sa)->postJson(self::URL.'?account_id='.$noCrm->id, $this->body())
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->actingAs($sa)->postJson(self::URL.'?account_id='.$moduleOff->id, $this->body())
            ->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        $this->actingAs($sa)->getJson(self::URL.'?account_id='.$noCrm->id)->assertStatus(403);

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_a_platform_account_whose_crm_was_revoked_is_blocked_too(): void
    {
        $platform = $this->platform();
        AccountEntitlement::where('account_id', $platform->id)->update(['revoked_at' => now()]);

        $this->actingAs($this->superAdmin())->postJson(self::URL, $this->body())
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->assertDatabaseCount('crm_leads', 0);

        // ensure() never silently re-grants a revoked entitlement.
        app(PlatformCrmAccount::class)->ensure();
        $this->assertNotNull(AccountEntitlement::where('account_id', $platform->id)->value('revoked_at'));
    }

    public function test_client_admin_and_agent_are_unaffected_by_the_platform_account(): void
    {
        $platform = $this->platform();
        $mine = $this->account();
        $agent = $this->account(factory: fn ($f) => $f->agent());
        $sub = $this->account(factory: fn ($f) => $f->client($agent));
        $agentUser = $this->user($agent, null, ['manage-crm']);

        $a = $this->actingAs($this->user($mine))->postJson(self::URL.'?account_id='.$platform->id, $this->body())->assertCreated();
        $this->assertSame($mine->id, CrmLead::findOrFail($a->json('data.id'))->account_id);

        $this->actingAs($agentUser)->postJson(self::URL.'?account_id='.$platform->id, $this->body())->assertNotFound();
        $b = $this->actingAs($agentUser)->postJson(self::URL.'?account_id='.$sub->id, $this->body())->assertCreated();
        $this->assertSame($sub->id, CrmLead::findOrFail($b->json('data.id'))->account_id);
        $this->assertSame(0, CrmLead::where('account_id', $platform->id)->count());
    }

    public function test_auth_me_exposes_the_platform_account_to_super_admin_only(): void
    {
        $this->actingAs($this->superAdmin())->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.platform_crm_account', null);

        $platform = $this->platform();
        $this->actingAs($this->superAdmin())->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('user.platform_crm_account', ['id' => $platform->id, 'company_name' => 'Platform (Super Admin)'])
            ->assertJsonPath('user.account_id', null);
        $this->actingAs($this->user($this->account()))->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.platform_crm_account', null);
    }

    public function test_ensure_is_idempotent_and_the_command_reports(): void
    {
        $first = app(PlatformCrmAccount::class)->ensure();
        $second = app(PlatformCrmAccount::class)->ensure();

        $this->assertTrue($first['created']);
        $this->assertTrue($first['crm_granted']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['account']->id, $second['account']->id);
        $this->assertSame(1, Account::where('account_type', 'super_admin')->count());
        $this->assertSame('super_admin', $first['account']->account_type);
        $this->assertNull($first['account']->allowed_modules);

        $this->assertSame(0, Artisan::call('crm:platform-account'));
        $this->assertStringContainsString('already existed', Artisan::output());
    }

    public function test_the_platform_account_is_not_listed_as_a_client(): void
    {
        $platform = $this->platform();
        $this->account();

        $ids = collect($this->actingAs($this->superAdmin())->getJson('/api/admin/accounts?account_type=client&per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($platform->id, $ids->all());
    }

    public function test_crm_target_is_attached_to_crm_routes_only(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $has = in_array('crm.target', $route->gatherMiddleware(), true);
            $this->assertSame(str_starts_with($route->uri(), 'api/crm'), $has, $route->uri());
        }
    }

    public function test_the_data_migration_creates_the_account_only_when_a_super_admin_exists(): void
    {
        $migration = require database_path('migrations/2026_09_23_130000_create_super_admin_platform_crm_account.php');

        $migration->up();
        $this->assertSame(0, Account::where('account_type', 'super_admin')->count(), 'no Super Admin yet: no-op');

        $this->superAdmin();
        $migration->up();
        $migration->up();
        $this->assertSame(1, Account::where('account_type', 'super_admin')->count());

        $migration->down();
        $this->assertSame(0, Account::where('account_type', 'super_admin')->count(), 'empty platform account is removed');

        $migration->up();
        $this->actingAs($this->superAdmin())->postJson(self::URL, $this->body())->assertCreated();
        $migration->down();
        $this->assertSame(1, Account::where('account_type', 'super_admin')->count(), 'kept while it holds CRM data');
    }
}
