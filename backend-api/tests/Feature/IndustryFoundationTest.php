<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AccountIndustry;
use App\Models\Capability;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Industry\IndustryAuthorizer;
use App\Services\Industry\IndustryRegistry;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 11 Task 1 — the industry foundation: registry → assignment → one authorizer
 * (permission, capability, module, subscription, tenant, target account, hierarchy),
 * with the existing CRM / Journey / Ads / Social / plan management untouched.
 */
class IndustryFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ------------------------------------------------------------------ fixtures

    private function grant(Account $account, string $slug): void
    {
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->firstOrFail()->id],
            ['source' => 'manual_grant'],
        );
    }

    /** @param list<string> $grants */
    private function tenant(array $grants = ['industry_education'], array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach ($grants as $slug) {
            $this->grant($account, $slug);
        }

        return $account->fresh();
    }

    private function user(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function assign(Account $account, string $industry = 'education', ?string $subtype = 'school'): void
    {
        AccountIndustry::create(['account_id' => $account->id, 'industry' => $industry, 'subtype' => $subtype]);
    }

    /** A ready tenant: education assigned, capability granted, admin user. */
    private function ready(): array
    {
        $account = $this->tenant();
        $this->assign($account);

        return [$account, $this->user($account)];
    }

    // ================================================================== registry & assignment

    public function test_the_registry_is_consistent_and_its_capabilities_exist(): void
    {
        $registry = app(IndustryRegistry::class);

        $this->assertEqualsCanonicalizing(['education', 'healthcare', 'ecommerce', 'real_estate', 'financial_services'], $registry->keys());
        $this->assertSame(['school', 'coaching', 'institute'], array_keys($registry->verticals('education')));
        $this->assertSame(['doctor', 'clinic', 'hospital'], array_keys($registry->verticals('healthcare')));

        foreach ($registry->industries() as $key => $industry) {
            $this->assertTrue(Capability::where('slug', $industry['capability'])->exists(), "{$key}: capability row exists");
            foreach ($industry['modules'] as $moduleKey => $module) {
                $this->assertContains($module['crm_anchor'], ['contact', 'lead'], "{$key}.{$moduleKey} hangs off an existing CRM record");
                // Only Education's modules have shipped (Phase 11 Tasks 2-4); every other module is registered only.
                $shipped = $key === 'education' && in_array($moduleKey, ['students', 'batches', 'attendance', 'fees'], true);
                $this->assertSame($shipped, $module['available'], "{$key}.{$moduleKey} availability");
                if (isset($module['requires_capability'])) {
                    $this->assertTrue(Capability::where('slug', $module['requires_capability'])->exists(), "{$key}.{$moduleKey}: shared capability row exists");
                }
            }
        }
    }

    public function test_an_account_can_have_an_industry_and_several(): void
    {
        $admin = $this->superAdmin();
        $account = $this->tenant();

        $this->actingAs($admin)->putJson("/api/admin/accounts/{$account->id}/industries", ['industries' => [
            ['industry' => 'healthcare', 'subtype' => 'clinic'],
            ['industry' => 'ecommerce'],
        ]])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(['ecommerce' => null, 'healthcare' => 'clinic'], AccountIndustry::where('account_id', $account->id)->pluck('subtype', 'industry')->all());
        $this->assertSame([$admin->id], AccountIndustry::where('account_id', $account->id)->pluck('assigned_by_user_id')->unique()->all());

        // The set is replaced, and an empty set clears it.
        $this->actingAs($admin)->putJson("/api/admin/accounts/{$account->id}/industries", ['industries' => [['industry' => 'ecommerce']]])->assertOk();
        $this->assertSame(['ecommerce'], AccountIndustry::where('account_id', $account->id)->pluck('industry')->all());
        $this->actingAs($admin)->putJson("/api/admin/accounts/{$account->id}/industries", ['industries' => []])->assertOk();
        $this->assertSame(0, AccountIndustry::where('account_id', $account->id)->count());
    }

    public function test_unknown_or_invalid_industries_cannot_be_assigned_and_nothing_is_written(): void
    {
        $admin = $this->superAdmin();
        $account = $this->tenant();
        $this->assign($account, 'ecommerce', null);

        foreach ([
            [['industry' => 'education', 'subtype' => 'school'], ['industry' => 'astrology']],
            [['industry' => 'education', 'subtype' => 'clinic']],
            [['industry' => 'ecommerce', 'subtype' => 'store']],
            [['industry' => 'education'], ['industry' => 'education', 'subtype' => 'school']],
        ] as $payload) {
            $this->actingAs($admin)->putJson("/api/admin/accounts/{$account->id}/industries", ['industries' => $payload])
                ->assertStatus(422)->assertJsonPath('error_code', 'INDUSTRY_INVALID');
        }
        $this->actingAs($admin)->putJson("/api/admin/accounts/{$account->id}/industries", [])->assertStatus(422);

        $this->assertSame(['ecommerce'], AccountIndustry::where('account_id', $account->id)->pluck('industry')->all(), 'a rejected call changes nothing');
        $this->assertSame(1, AccountIndustry::count());
    }

    public function test_a_tenant_cannot_assign_its_own_or_anyone_elses_industry(): void
    {
        [$account, $admin] = $this->ready();
        $other = $this->tenant();

        $this->actingAs($admin)->putJson("/api/admin/accounts/{$account->id}/industries", ['industries' => [['industry' => 'ecommerce']]])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/admin/accounts/{$other->id}/industries", ['industries' => [['industry' => 'ecommerce']]])->assertForbidden();
        $this->assertSame(['education'], AccountIndustry::pluck('industry')->all());
    }

    // ================================================================== tenant isolation & target account

    public function test_industry_data_is_tenant_isolated(): void
    {
        [$a, $adminA] = $this->ready();
        $b = $this->tenant(['industry_healthcare']);
        $this->assign($b, 'healthcare', 'clinic');
        $adminB = $this->user($b);

        $this->actingAs($adminA)->getJson('/api/industry/context')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.industry', 'education');
        $this->actingAs($adminB)->getJson('/api/industry/context')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.industry', 'healthcare');

        // A forged account_id never retargets a tenant user.
        $forged = $this->actingAs($adminA)->getJson("/api/industry/context?account_id={$b->id}");
        $this->assertStringNotContainsString('healthcare', $forged->getContent());

        // B's industry is not A's: A holds no healthcare assignment or capability.
        $this->actingAs($adminA)->getJson('/api/industry/healthcare/modules')->assertForbidden();
    }

    public function test_super_admin_must_select_the_target_account_and_never_gets_a_fallback(): void
    {
        $client = $this->tenant();
        $this->assign($client);
        $platform = Account::factory()->create(['account_type' => 'super_admin', 'company_name' => 'Platform (Super Admin)']);
        $this->assign($platform, 'ecommerce', null);
        $admin = $this->superAdmin();
        $this->app['env'] = 'local';

        $this->actingAs($admin)->getJson('/api/industry/context')->assertStatus(422);
        $this->actingAs($admin)->getJson('/api/industry/education/modules')->assertStatus(422)->assertJsonPath('error_code', 'TARGET_ACCOUNT_REQUIRED');

        $this->actingAs($admin)->getJson("/api/industry/context?account_id={$client->id}")->assertOk()->assertJsonPath('data.0.industry', 'education');
        $this->actingAs($admin)->getJson("/api/industry/education/modules?account_id={$client->id}")->assertOk();
        $this->actingAs($admin)->getJson('/api/industry/context?account_id=99999999')->assertNotFound();

        $suspended = $this->tenant(['industry_education'], ['status' => 'suspended']);
        $this->assign($suspended);
        $this->actingAs($admin)->getJson("/api/industry/education/modules?account_id={$suspended->id}")->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
    }

    public function test_an_agent_reaches_only_its_own_clients(): void
    {
        $agent = $this->tenant(['industry_education'], ['account_type' => 'agent']);
        $own = $this->tenant();
        $own->forceFill(['agent_id' => $agent->id])->save();
        $this->assign($own);
        $strangersClient = $this->tenant();
        $otherAgent = $this->tenant([], ['account_type' => 'agent']);
        $strangersClient->forceFill(['agent_id' => $otherAgent->id])->save();
        $this->assign($strangersClient);
        $actor = $this->user($agent);
        $actor->givePermissionTo('manage-accounts');

        $this->actingAs($actor)->getJson("/api/admin/accounts/{$own->id}/industries")->assertOk()->assertJsonPath('data.0.industry', 'education');
        $this->actingAs($actor)->putJson("/api/admin/accounts/{$own->id}/industries", ['industries' => [['industry' => 'education', 'subtype' => 'coaching']]])->assertOk();
        $this->assertSame('coaching', AccountIndustry::where('account_id', $own->id)->value('subtype'));

        // Another Agent's client is the same 404 as a missing account — read and write.
        $this->actingAs($actor)->getJson("/api/admin/accounts/{$strangersClient->id}/industries")->assertNotFound();
        $this->actingAs($actor)->putJson("/api/admin/accounts/{$strangersClient->id}/industries", ['industries' => []])->assertNotFound();
        $this->assertSame(1, AccountIndustry::where('account_id', $strangersClient->id)->count());

        // Switching the target to a sub-client works, to a stranger's does not.
        $this->actingAs($actor)->getJson("/api/industry/context?account_id={$own->id}")->assertOk();
        $this->actingAs($actor)->getJson("/api/industry/context?account_id={$strangersClient->id}")->assertNotFound();
    }

    // ================================================================== the gate: permission / capability / module / subscription

    public function test_permission_denial(): void
    {
        [$account] = $this->ready();
        Role::firstOrCreate(['name' => 'no_industry', 'guard_name' => 'web'])->syncPermissions(['view-analytics']);
        $limited = $this->user($account, 'no_industry');

        $this->actingAs($limited)->getJson('/api/industry/context')->assertForbidden();
        $this->actingAs($limited)->getJson('/api/industry/education/modules')->assertForbidden();
        $this->actingAs($this->user($account, 'user'))->getJson('/api/industry/context')->assertForbidden();
    }

    public function test_capability_denial_and_grant(): void
    {
        $account = $this->tenant([]);
        $this->assign($account);
        $admin = $this->user($account);

        $this->actingAs($admin)->getJson('/api/industry/education/modules')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        // Another industry's capability does not unlock this one.
        $this->grant($account, 'industry_healthcare');
        $this->actingAs($admin)->getJson('/api/industry/education/modules')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $this->grant($account, 'industry_education');
        $this->actingAs($admin)->getJson('/api/industry/education/modules')->assertOk();
    }

    public function test_module_denial(): void
    {
        [$account, $admin] = $this->ready();
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['industry_modules']))])->save();

        $this->actingAs($admin)->getJson('/api/industry/education/modules')->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    public function test_an_unassigned_industry_is_refused_even_with_the_capability(): void
    {
        $account = $this->tenant(['industry_education']);
        $admin = $this->user($account);

        $this->actingAs($admin)->getJson('/api/industry/education/modules')->assertForbidden()->assertJsonPath('error_code', 'INDUSTRY_NOT_ASSIGNED');
        $this->actingAs($admin)->getJson('/api/industry/astrology/modules')->assertNotFound()->assertJsonPath('error_code', 'INDUSTRY_UNKNOWN');
    }

    public function test_subscription_and_entitlement_denial(): void
    {
        [$account, $admin] = $this->ready();

        // A revoked entitlement grants nothing.
        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => now()]);
        $this->actingAs($admin)->getJson('/api/industry/education/modules')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => null]);
        $this->actingAs($admin)->getJson('/api/industry/education/modules')->assertOk();

        // An expired subscription keeps reads (like Ads); a write is refused (see the guarded probe route below).
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);
        $this->actingAs($admin)->getJson('/api/industry/context')->assertOk();
        $this->actingAs($admin)->getJson('/api/industry/education/modules')->assertOk();

        $account->forceFill(['status' => 'suspended'])->save();
        $this->assertContains($this->actingAs($admin)->getJson('/api/industry/education/modules')->status(), [401, 403]);
    }

    // ================================================================== modules: planned vs available, own permission, writes

    public function test_a_planned_module_is_refused_and_an_available_one_follows_every_gate(): void
    {
        [$account, $admin] = $this->ready();
        $authorizer = app(IndustryAuthorizer::class);
        // Every Education module has shipped by Task 4, so register a planned one for this test only.
        config(['industries.industries.education.modules.probe' => ['label' => 'Probe', 'crm_anchor' => 'contact', 'available' => false]]);

        $this->assertSame('INDUSTRY_MODULE_UNAVAILABLE', $authorizer->denial($account, 'education', 'probe', true, $admin)['code']);
        $this->assertSame('INDUSTRY_MODULE_UNKNOWN', $authorizer->denial($account, 'education', 'nope', true, $admin)['code']);
        $context = $this->actingAs($admin)->getJson('/api/industry/context')->assertOk()->json('data.0');
        $this->assertTrue($context['allowed']);
        // Tasks 2-4 shipped students, batches, attendance and fees; the probe is only registered. Fees also needs
        // the generic billing_collections capability, which this account does not hold.
        $this->assertSame(['students' => true, 'batches' => true, 'attendance' => true, 'fees' => false, 'probe' => false], array_column($context['modules'], 'allowed', 'key'));
        $this->assertEqualsCanonicalizing(['education.students', 'education.batches', 'education.attendance'], $this->actingAs($admin)->getJson('/api/auth/me')->json('user.industry_modules'));

        // Ship `fees` (with its own permission) for this test only.
        config(['industries.industries.education.modules.probe.available' => true, 'industries.industries.education.modules.probe.permission' => 'manage-students-probe']);
        Route::middleware(['api', 'auth:sanctum', 'tenant.isolation', 'subscription.guard', 'industry.guard:education,probe'])->group(function () {
            Route::get('/api/_probe/students', fn () => response()->json(['ok' => true]));
            Route::post('/api/_probe/students', fn () => response()->json(['ok' => true]));
        });

        // The module's own permission is required, not just the industry's.
        $this->actingAs($admin)->getJson('/api/_probe/students')->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
        \Spatie\Permission\Models\Permission::findOrCreate('manage-students-probe', 'web');
        $admin->givePermissionTo('manage-students-probe');
        $this->assertContains('education.probe', $this->actingAs($admin->fresh())->getJson('/api/auth/me')->json('user.industry_modules'));
        $this->actingAs($admin->fresh())->getJson('/api/_probe/students')->assertOk();
        $this->actingAs($admin->fresh())->postJson('/api/_probe/students')->assertOk();

        // A write needs an active subscription; the read still works.
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);
        $this->actingAs($admin->fresh())->getJson('/api/_probe/students')->assertOk();
        $this->actingAs($admin->fresh())->postJson('/api/_probe/students')->assertForbidden();
    }

    public function test_the_source_of_a_record_is_never_part_of_the_decision(): void
    {
        [$account, $admin] = $this->ready();
        $authorizer = app(IndustryAuthorizer::class);

        // The authorizer's signature has no notion of origin: manual, Journey, API and import calls are all this one call.
        $this->assertSame(['account', 'industry', 'module', 'read', 'user', 'superAdmin'], array_map(fn ($p) => $p->getName(), (new \ReflectionMethod($authorizer, 'denial'))->getParameters()));
        $this->assertNull($authorizer->denial($account, 'education', null, true, $admin));
    }

    // ================================================================== existing behaviour is untouched

    public function test_existing_crm_journey_ads_and_social_routes_are_unaffected_by_an_industry(): void
    {
        $account = $this->tenant(['industry_education', 'crm', 'ads', 'journey_automation', 'social']);
        $this->assign($account);
        $admin = $this->user($account);

        $this->actingAs($admin)->getJson('/api/crm/leads')->assertOk();
        $this->actingAs($admin)->getJson('/api/crm/contacts')->assertOk();
        $this->actingAs($admin)->getJson('/api/social/ads/dashboard')->assertOk();
        $this->actingAs($admin)->getJson('/api/social/providers')->assertOk();

        // And an account with NO industry behaves exactly as before.
        $plain = $this->tenant(['crm', 'ads']);
        $this->actingAs($this->user($plain))->getJson('/api/crm/leads')->assertOk();
        $this->actingAs($this->user($plain))->getJson('/api/social/ads/dashboard')->assertOk();
    }

    public function test_plan_management_and_entitlements_carry_industry_capabilities_without_auto_bundling(): void
    {
        $admin = $this->superAdmin();
        $industrySlugs = Capability::where('category', 'industry')->pluck('slug')->all();
        $this->assertCount(5, $industrySlugs);

        // No seeded plan sells an industry unless an admin adds it.
        $this->assertSame(0, Plan::query()->whereHas('capabilities', fn ($q) => $q->whereIn('slug', $industrySlugs))->count());

        $this->actingAs($admin)->postJson('/api/admin/plans-management', [
            'slug' => 'clinic_pack', 'label' => 'Clinic Pack', 'price' => 999, 'duration_days' => 30, 'engine_type' => 'meta', 'billing_model' => 'flat_quota',
            'total_allocated_messages' => 1000, 'capabilities' => ['whatsapp_send', 'crm', 'industry_healthcare'],
        ])->assertSuccessful();
        $this->assertEqualsCanonicalizing(['whatsapp_send', 'crm', 'industry_healthcare'], Plan::where('slug', 'clinic_pack')->first()->capabilities->pluck('slug')->all());
        $this->actingAs($admin)->putJson('/api/admin/plans-management/clinic_pack', ['capabilities' => ['whatsapp_send', 'crm', 'industry_healthcare', 'industry_education']])->assertOk();

        $account = $this->tenant([]);
        $this->actingAs($admin)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'industry_real_estate'])->assertSuccessful();
        $this->assertTrue(app(\App\Services\Access\AccessControlService::class)->canTenant($account, 'industry_real_estate'));
        $this->assertFalse(app(\App\Services\Access\AccessControlService::class)->canTenant($account, 'industry_education'));
        $this->assertTrue($this->actingAs($this->user($account))->getJson('/api/auth/me')->json('user.capabilities.industry_real_estate'));
    }

    public function test_the_sidebar_contract_the_frontend_relies_on_is_in_auth_me(): void
    {
        [, $admin] = $this->ready();

        $me = $this->actingAs($admin)->getJson('/api/auth/me')->assertOk()->json();

        $this->assertArrayHasKey('industry_modules', $me['user']);
        $this->assertTrue($me['user']['capabilities']['industry_education']);
        $this->assertContains('view-industry-modules', $me['permissions']);
        $this->assertSame([], $this->actingAs($this->superAdmin())->getJson('/api/auth/me')->json('user.industry_modules'));
    }

    public function test_the_catalog_is_static_metadata(): void
    {
        [, $admin] = $this->ready();

        $catalog = $this->actingAs($admin)->getJson('/api/industries')->assertOk()->json('data');

        $this->assertCount(5, $catalog);
        $this->assertSame('education', $catalog[0]['key']);
        $this->assertStringNotContainsString('account_id', json_encode($catalog));
    }
}
