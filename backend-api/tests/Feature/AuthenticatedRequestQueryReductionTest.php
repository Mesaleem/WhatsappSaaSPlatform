<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AccountIndustry;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\Education\EducationStudent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\DenialScope;
use App\Services\Industry\IndustryAuthorizer;
use App\Services\Industry\IndustryModuleResolver;
use App\Services\Industry\IndustryRegistry;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CountsQueries;
use Tests\TestCase;

/**
 * Phase 12 Task 4 — authenticated request-path query reduction.
 *
 * Baseline (warm, SQLite, before the change): /auth/me industry admin 40 queries, education
 * fee-items 16, student fees 18, students list 16, /auth/me agent 11, super admin 7.
 *
 * UPPER BOUNDS, not exact counts. Each bound sits a few queries above the measured value so an
 * unrelated added eager-load does not break the suite, and well below the baseline so the
 * regression this task fixed (per-module re-running of the account/subscription/capability/
 * industry lookups) cannot return. The /me bound is safe because the module-denial work no longer
 * grows with the number of registry modules: see test_me_authorization_queries_do_not_repeat.
 */
class AuthenticatedRequestQueryReductionTest extends TestCase
{
    use CountsQueries;
    use RefreshDatabase;

    private Account $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        $this->school = $this->tenant('education', 'school', ['industry_education', 'billing_collections']);
        $this->admin = $this->userFor($this->school);
        for ($i = 1; $i <= 5; $i++) {
            $c = Contact::create(['account_id' => $this->school->id, 'phone_number' => '91990000000'.$i, 'name' => 'S'.$i]);
            EducationStudent::create(['account_id' => $this->school->id, 'contact_id' => $c->id]);
        }
    }

    private function grant(Account $a, string $slug): void
    {
        AccountEntitlement::firstOrCreate(['account_id' => $a->id, 'capability_id' => Capability::where('slug', $slug)->firstOrFail()->id], ['source' => 'manual_grant']);
    }

    /** @param list<string> $caps */
    private function tenant(string $industry, string $subtype, array $caps, array $attrs = []): Account
    {
        $a = Account::factory()->create($attrs);
        Subscription::factory()->create(['account_id' => $a->id]);
        foreach ($caps as $c) {
            $this->grant($a, $c);
        }
        AccountIndustry::create(['account_id' => $a->id, 'industry' => $industry, 'subtype' => $subtype]);

        return $a;
    }

    private function userFor(Account $a, string $role = 'admin'): User
    {
        $u = User::factory()->create(['account_id' => $a->id, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $u->assignRole('super_admin');

        return $u;
    }

    /** Real token, fresh guard per request (the app container is shared across requests in one test). */
    private function req(User $u, string $url, string $method = 'getJson', array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($u->createToken('t', ['*'])->plainTextToken)->{$method}($url, $body);
    }

    /** Warm once (permission cache, Account::findCached), then measure a second identical request. */
    private function measure(User $u, string $url): array
    {
        $this->req($u, $url);
        $r = null;
        $res = $this->captureQueries(function () use (&$r, $u, $url) {
            $r = $this->req($u, $url);
        });
        $res['response'] = $r;

        return $res;
    }

    private function matching(array $res, string $needle): int
    {
        return count(array_filter($res['queries'], fn ($q) => str_contains($q['sql'], $needle)));
    }

    private const EDU = '/api/industry/education';

    // ---- query-count regressions ------------------------------------------------------------

    public function test_me_for_an_industry_tenant_stays_far_below_the_baseline(): void
    {
        $res = $this->measure($this->admin, '/api/auth/me');
        $res['response']->assertOk();
        $this->assertLessThanOrEqual(20, $res['count'], "baseline was 40; got {$res['count']}");
    }

    public function test_me_authorization_queries_do_not_repeat(): void
    {
        $res = $this->measure($this->admin, '/api/auth/me');

        // One fresh target load for the whole module pass (+ the eager one for the response) — never one per module.
        $this->assertLessThanOrEqual(2, $this->matching($res, 'from "subscriptions" inner join'));
        $this->assertLessThanOrEqual(1, $this->matching($res, 'from "account_industries" where "account_id" = ? and "industry"'), 'assignment already read; must not be re-queried per module');
        // Each capability is checked at most once per pass.
        $this->assertLessThanOrEqual(2, $this->matching($res, 'from "account_entitlements" where "account_entitlements"."account_id"'));
    }

    public function test_me_super_admin_and_agent_bounds(): void
    {
        $super = $this->measure($this->superAdmin(), '/api/auth/me');
        $this->assertLessThanOrEqual(9, $super['count']);

        $agentAcc = Account::factory()->agent()->create();
        Subscription::factory()->create(['account_id' => $agentAcc->id]);
        $agent = $this->userFor($agentAcc);
        $agent->assignRole('agent');
        $a = $this->measure($agent, '/api/auth/me');
        $this->assertLessThanOrEqual(13, $a['count']);
    }

    public function test_education_paths_stay_below_their_baselines(): void
    {
        $sid = EducationStudent::query()->value('id');
        // baselines: students 16, fee-items 16, student fees 18
        foreach ([
            self::EDU.'/students' => 18,
            self::EDU.'/fee-items' => 15,
            self::EDU."/students/{$sid}/fees" => 17,
        ] as $url => $bound) {
            $res = $this->measure($this->admin, $url);
            $res['response']->assertOk();
            $this->assertLessThanOrEqual($bound, $res['count'], "{$url}: {$res['count']} > {$bound}");
        }
    }

    public function test_student_list_has_no_n_plus_one(): void
    {
        $few = $this->measure($this->admin, self::EDU.'/students')['count'];
        for ($i = 6; $i <= 25; $i++) {
            $c = Contact::create(['account_id' => $this->school->id, 'phone_number' => '9199100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'name' => 'S'.$i]);
            EducationStudent::create(['account_id' => $this->school->id, 'contact_id' => $c->id]);
        }
        $many = $this->measure($this->admin, self::EDU.'/students')['count'];
        $this->assertSame($few, $many, '5 → 25 students must not add queries');
    }

    // ---- response / authorization equivalence ----------------------------------------------

    public function test_scoped_module_context_equals_the_per_call_unscoped_decisions(): void
    {
        $resolver = app(IndustryModuleResolver::class);
        $authorizer = app(IndustryAuthorizer::class);
        $registry = app(IndustryRegistry::class);

        // A mix of outcomes: one capability revoked, so some modules deny and some allow.
        AccountEntitlement::where('account_id', $this->school->id)
            ->where('capability_id', Capability::where('slug', 'billing_collections')->value('id'))
            ->update(['revoked_at' => now()]);

        $context = $resolver->contextFor($this->school->fresh(), $this->admin);
        $this->assertNotEmpty($context);

        foreach ($context as $industry) {
            $expected = $authorizer->denial($this->school->fresh(), $industry['industry'], null, true, $this->admin);
            $this->assertSame($expected['code'] ?? null, $industry['denial_code']);
            foreach ($industry['modules'] as $m) {
                // Each unscoped call builds its own fresh scope — the pre-change behaviour.
                $d = $authorizer->denial($this->school->fresh(), $industry['industry'], $m['key'], true, $this->admin);
                $this->assertSame($d === null, $m['allowed'], $m['key']);
                $this->assertSame($d['code'] ?? null, $m['denial_code'], $m['key']);
            }
        }
        $this->assertTrue(collect($context)->flatMap(fn ($i) => $i['modules'])->contains(fn ($m) => ! $m['allowed']), 'fixture must include a denied module');
        $this->assertTrue(collect($context)->flatMap(fn ($i) => $i['modules'])->contains(fn ($m) => $m['allowed']), 'fixture must include an allowed module');
        $this->assertNotNull($registry->find('education'));
    }

    public function test_me_payload_contract_is_unchanged(): void
    {
        $json = $this->req($this->admin, '/api/auth/me')->assertOk()->json();
        $user = $json['user'] ?? $json['data'] ?? $json;
        foreach (['id', 'email', 'permissions', 'capabilities', 'industry_modules', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $user, $key);
        }
        $this->assertTrue($user['capabilities']['industry_education']);
        $this->assertTrue($user['capabilities']['billing_collections']);
        $this->assertContains('education.fees', $user['industry_modules']);
        $this->assertSame(['education.students'], array_values(array_filter($user['industry_modules'], fn ($k) => $k === 'education.students')));
    }

    // ---- negative / isolation: restrictions still bite --------------------------------------

    public function test_revoked_capability_is_seen_on_the_very_next_request(): void
    {
        $this->req($this->admin, self::EDU.'/fee-items')->assertOk();
        $this->req($this->admin, '/api/auth/me')->assertOk();

        AccountEntitlement::where('account_id', $this->school->id)
            ->where('capability_id', Capability::where('slug', 'billing_collections')->value('id'))
            ->update(['revoked_at' => now()]);

        $this->req($this->admin, self::EDU.'/fee-items')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $keys = $this->req($this->admin, '/api/auth/me')->json('user.industry_modules') ?? $this->req($this->admin, '/api/auth/me')->json('industry_modules');
        $this->assertNotContains('education.fees', $keys);
        $this->assertContains('education.students', $keys);
    }

    public function test_industry_capability_removed_denies_everything(): void
    {
        $this->req($this->admin, self::EDU.'/students')->assertOk();
        AccountEntitlement::where('account_id', $this->school->id)
            ->where('capability_id', Capability::where('slug', 'industry_education')->value('id'))
            ->update(['revoked_at' => now()]);
        $this->req($this->admin, self::EDU.'/students')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
    }

    public function test_expired_subscription_blocks_writes_but_not_industry_reads_unchanged(): void
    {
        $this->req($this->admin, self::EDU.'/students')->assertOk();
        Subscription::where('account_id', $this->school->id)->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        $this->school->unsetRelations();

        $write = $this->req($this->admin, self::EDU.'/students', 'postJson', ['phone_number' => '919800000999']);
        $this->assertSame(403, $write->status());
        $this->assertSame(0, EducationStudent::whereHas('contact', fn ($q) => $q->where('phone_number', '919800000999'))->count());
    }

    public function test_module_switched_off_is_seen_on_the_next_request(): void
    {
        $this->req($this->admin, self::EDU.'/students')->assertOk();
        $this->school->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, [config('industries.module')]))])->save();
        \Illuminate\Support\Facades\Cache::flush();
        $this->req($this->admin, self::EDU.'/students')->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    public function test_user_without_the_module_permission_is_denied_and_not_listed(): void
    {
        $viewer = User::factory()->create(['account_id' => $this->school->id, 'is_active' => true]);
        $viewer->assignRole('admin');
        $viewer->revokePermissionTo($viewer->getAllPermissions()->pluck('name')->all());
        $viewer->syncRoles([]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->req($viewer, self::EDU.'/students')->assertForbidden();
        $payload = $this->req($viewer, '/api/auth/me')->assertOk()->json();
        $this->assertSame([], ($payload['user'] ?? $payload)['industry_modules']);
    }

    public function test_tenant_isolation_between_two_schools(): void
    {
        $other = $this->tenant('education', 'school', ['industry_education', 'billing_collections']);
        $otherAdmin = $this->userFor($other);
        $otherContact = Contact::create(['account_id' => $other->id, 'phone_number' => '919877700001', 'name' => 'Other kid']);
        $otherStudent = EducationStudent::create(['account_id' => $other->id, 'contact_id' => $otherContact->id]);

        // Warm both tenants in the same app instance, then confirm neither leaks into the other.
        $this->req($this->admin, self::EDU.'/students')->assertOk();
        $mine = $this->req($otherAdmin, self::EDU.'/students')->assertOk()->json('data');
        $this->assertSame([$otherStudent->id], array_column($mine, 'id'));
        $this->req($this->admin, self::EDU."/students/{$otherStudent->id}")->assertNotFound();
        $this->req($this->admin, self::EDU."/students/{$otherStudent->id}/fees")->assertNotFound();
    }

    public function test_a_tenant_without_the_industry_assignment_is_refused(): void
    {
        $plain = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $plain->id]);
        $this->grant($plain, 'industry_education');
        $u = $this->userFor($plain);
        $this->req($u, self::EDU.'/students')->assertForbidden()->assertJsonPath('error_code', 'INDUSTRY_NOT_ASSIGNED');
    }

    public function test_agent_and_super_admin_target_selection_stay_scoped(): void
    {
        $agentAcc = Account::factory()->agent()->create();
        Subscription::factory()->create(['account_id' => $agentAcc->id]);
        $agent = $this->userFor($agentAcc);
        $agent->assignRole('agent');

        $own = $this->tenant('education', 'school', ['industry_education', 'billing_collections'], ['agent_id' => $agentAcc->id]);
        $oc = Contact::create(['account_id' => $own->id, 'phone_number' => '919866600001', 'name' => 'Own']);
        $os = EducationStudent::create(['account_id' => $own->id, 'contact_id' => $oc->id]);

        // The agent's own sub-client is reachable; another agent's / unrelated tenant is a 404, never a 403 leak or data.
        $this->req($agent, self::EDU.'/students?account_id='.$own->id)->assertOk()->assertJsonPath('data.0.id', $os->id);
        $this->req($agent, self::EDU.'/students?account_id='.$this->school->id)->assertNotFound();

        // Super Admin must name a target, and gets that target's data only.
        $super = $this->superAdmin();
        $r = $this->req($super, self::EDU.'/students?account_id='.$own->id)->assertOk();
        $this->assertSame([$os->id], array_column($r->json('data'), 'id'));
        $this->assertGreaterThanOrEqual(400, $this->req($super, self::EDU.'/students')->status(), 'no target selected: never falls back to the platform account');
    }

    // ---- the memo cannot outlive or cross its scope -----------------------------------------

    public function test_denial_scope_is_per_instance_and_account_keyed(): void
    {
        $a = $this->school;
        $b = $this->tenant('education', 'school', []);

        $s1 = new DenialScope();
        $this->assertSame($a->id, $s1->target($a)->id);
        $this->assertSame($b->id, $s1->target($b)->id, 'one scope never answers for a different account');

        // A new scope re-reads the database: a change after the first scope is visible.
        $a->update(['status' => 'suspended']);
        $this->assertSame('active', $s1->target($a)->status, 'within one scope the load is reused');
        $this->assertSame('suspended', (new DenialScope())->target($a)->status, 'a fresh scope sees current state');

        $calls = 0;
        $s = new DenialScope();
        $s->remember("cap:{$a->id}:x", function () use (&$calls) { $calls++; return true; });
        $s->remember("cap:{$a->id}:x", function () use (&$calls) { $calls++; return true; });
        $s->remember("cap:{$b->id}:x", function () use (&$calls) { $calls++; return false; });
        $this->assertSame(2, $calls);
    }

    public function test_separate_denial_calls_do_not_share_state(): void
    {
        $auth = app(IndustryAuthorizer::class);
        $this->assertNull($auth->denial($this->school->fresh(), 'education', 'students', true, $this->admin));
        $this->school->update(['status' => 'suspended']);
        $this->assertSame('CLIENT_ACCOUNT_SUSPENDED', $auth->denial($this->school->fresh(), 'education', 'students', true, $this->admin)['code']);
    }
}
