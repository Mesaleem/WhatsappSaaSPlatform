<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\CrmTag;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Crm\CrmTagService;
use Carbon\CarbonImmutable;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6 — CRM Task 12. GET /api/crm/analytics.
 */
class CrmAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/crm/analytics';

    private int $phone = 919700000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        CarbonImmutable::setTestNow('2026-09-23 12:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-09-23 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function account(bool $crm = true, bool $active = true, array $attributes = [], ?callable $factory = null): Account
    {
        $builder = $factory ? $factory(Account::factory()) : Account::factory();
        $account = $builder->create($attributes);
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

    private function user(Account $account, ?string $role = 'admin', array $permissions = [], ?string $name = null): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true] + ($name ? ['name' => $name] : []));
        if ($role) {
            $user->assignRole($role);
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** A CRM lead with exact attributes; created_at is set after insert (bypasses timestamps). */
    private function lead(Account $account, array $attributes = [], string $createdAt = '2026-09-20 10:00:00'): CrmLead
    {
        $contact = Contact::factory()->forAccount($account)->create(['phone_number' => (string) $this->phone++]);
        $lead = CrmLead::factory()->forContact($contact)->create();
        // Raw update: analytics reads stored rows; the model's lifecycle and
        // assignee guards are covered by their own tasks' tests.
        DB::table('crm_leads')->where('id', $lead->id)->update($attributes + ['created_at' => $createdAt]);

        return $lead->fresh();
    }

    private function analytics(User $user, array $query = [])
    {
        return $this->actingAs($user)->getJson(self::URL.($query ? '?'.http_build_query($query) : ''));
    }

    private function statusCounts($response): array
    {
        return collect($response->json('data.by_status'))->pluck('count', 'status')->all();
    }

    private function sourceCounts($response): array
    {
        return collect($response->json('data.by_source'))->pluck('count', 'source')->all();
    }

    // =================================================================
    // 1. Metrics & formulas
    // =================================================================

    public function test_every_metric_is_computed_from_the_filtered_lead_set(): void
    {
        $account = $this->account();
        $admin = $this->user($account);
        $asha = $this->user($account, null, ['manage-crm'], 'Asha');
        $ben = $this->user($account, null, ['manage-crm'], 'Ben');

        foreach ([
            ['new', 'manual', null], ['new', 'meta_ad', $asha->id], ['new', 'api', $ben->id],
            ['contacted', 'whatsapp', $asha->id], ['contacted', 'meta_ad', null],
            ['converted', 'meta_ad', $asha->id], ['converted', 'journey', $asha->id], ['converted', 'api', $ben->id], ['converted', 'manual', null],
            ['not_converted', 'meta_ad', $ben->id],
        ] as $i => [$status, $source, $assignee]) {
            $this->lead($account, ['status' => $status, 'source' => $source, 'assigned_user_id' => $assignee], '2026-09-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT).' 09:00:00');
        }

        $r = $this->analytics($admin)->assertOk();

        $r->assertJsonPath('data.totals', [
            'total' => 10, 'new' => 3, 'contacted' => 2, 'converted' => 4, 'not_converted' => 1, 'conversion_rate' => 40,
        ]);
        $this->assertSame(['new' => 3, 'contacted' => 2, 'converted' => 4, 'not_converted' => 1], $this->statusCounts($r));
        $this->assertSame(['manual' => 2, 'whatsapp' => 1, 'meta_ad' => 4, 'api' => 2, 'journey' => 1], $this->sourceCounts($r));
        $this->assertSame([
            ['assigned_user_id' => $asha->id, 'name' => 'Asha', 'count' => 4],
            ['assigned_user_id' => $ben->id, 'name' => 'Ben', 'count' => 3],
            ['assigned_user_id' => null, 'name' => 'Unassigned', 'count' => 3],
        ], $r->json('data.by_assignee'));

        // Open range: from the first lead's day to today, by day.
        $r->assertJsonPath('data.range', ['from' => '2026-09-10', 'to' => '2026-09-23', 'interval' => 'day', 'timezone' => 'UTC']);
        $trend = collect($r->json('data.trend'));
        $this->assertCount(14, $trend);
        $this->assertSame(10, $trend->sum('total'));
        $this->assertSame(4, $trend->sum('converted'));
        $this->assertSame(['period' => '2026-09-15', 'total' => 1, 'converted' => 1], $trend->firstWhere('period', '2026-09-15'));
    }

    public function test_conversion_rate_is_rounded_to_two_decimals(): void
    {
        $account = $this->account();
        $this->lead($account, ['status' => 'converted']);
        $this->lead($account);
        $this->lead($account, ['status' => 'contacted']);

        $this->analytics($this->user($account))->assertOk()->assertJsonPath('data.totals.conversion_rate', 33.33);
    }

    public function test_empty_data_gives_zeros_and_a_null_rate(): void
    {
        $account = $this->account();

        $r = $this->analytics($this->user($account))->assertOk();

        $r->assertJsonPath('data.totals', ['total' => 0, 'new' => 0, 'contacted' => 0, 'converted' => 0, 'not_converted' => 0, 'conversion_rate' => null]);
        $this->assertSame(['new' => 0, 'contacted' => 0, 'converted' => 0, 'not_converted' => 0], $this->statusCounts($r));
        $this->assertSame(0, array_sum($this->sourceCounts($r)));
        $this->assertSame([], $r->json('data.by_assignee'));
        $this->assertSame([['period' => '2026-09-23', 'total' => 0, 'converted' => 0]], $r->json('data.trend'));
    }

    public function test_a_non_canonical_status_is_still_counted(): void
    {
        $account = $this->account();
        $lead = $this->lead($account);
        $this->lead($account, ['status' => 'converted']);
        DB::table('crm_leads')->where('id', $lead->id)->update(['status' => 'legacy_open']);

        $r = $this->analytics($this->user($account))->assertOk();

        $r->assertJsonPath('data.totals.total', 2);
        $r->assertJsonPath('data.totals.conversion_rate', 50);
        $this->assertSame(1, $this->statusCounts($r)['legacy_open']);
        $this->assertSame(2, array_sum($this->statusCounts($r)));
    }

    // =================================================================
    // 2. Accuracy — no capture rows, no double counting, right timestamp
    // =================================================================

    public function test_capture_rows_are_never_counted(): void
    {
        $account = $this->account();
        Lead::create(['account_id' => $account->id, 'provider' => 'meta', 'provider_lead_id' => 'LG-A', 'lead_phone' => '919811111111']);
        Lead::create(['account_id' => $account->id, 'provider' => 'whatsapp_ctwa', 'provider_lead_id' => 'W-A', 'lead_phone' => '919822222222']);
        $promoted = Lead::create(['account_id' => $account->id, 'provider' => 'meta', 'provider_lead_id' => 'LG-B', 'lead_phone' => '919833333333']);
        app(CaptureLeadLinker::class)->link($promoted);

        $r = $this->analytics($this->user($account))->assertOk();

        $r->assertJsonPath('data.totals.total', 1);
        $this->assertSame(1, $this->sourceCounts($r)['meta_ad']);
    }

    public function test_a_lead_with_many_tags_is_counted_once_everywhere(): void
    {
        $account = $this->account();
        $asha = $this->user($account, null, ['manage-crm'], 'Asha');
        $lead = $this->lead($account, ['status' => 'converted', 'source' => 'meta_ad', 'assigned_user_id' => $asha->id]);
        $tags = app(CrmTagService::class);
        $hot = $tags->create($account, 'Hot');
        foreach (['Hot', 'Vip', 'Q3'] as $name) {
            $tags->attach($lead, $name === 'Hot' ? $hot : $tags->create($account, $name));
        }
        $this->lead($account);

        foreach ([[], ['tag_id' => $hot->id], ['tag_ids' => CrmTag::pluck('id')->all()]] as $query) {
            $r = $this->analytics($this->user($account), $query)->assertOk();
            $expected = $query === [] ? 2 : 1;
            $r->assertJsonPath('data.totals.total', $expected);
            $this->assertSame($expected, array_sum($this->statusCounts($r)));
            $this->assertSame($expected, array_sum($this->sourceCounts($r)));
            $this->assertSame($expected, collect($r->json('data.by_assignee'))->sum('count'));
            $this->assertSame($expected, collect($r->json('data.trend'))->sum('total'));
        }
    }

    public function test_date_filtering_uses_crm_lead_created_at_inclusively(): void
    {
        $account = $this->account();
        $this->lead($account, [], '2026-09-09 23:59:59');          // before
        $this->lead($account, [], '2026-09-10 00:00:00');          // first second of `from`
        $this->lead($account, [], '2026-09-15 12:00:00');
        $this->lead($account, [], '2026-09-20 23:59:59');          // last second of `to`
        $this->lead($account, [], '2026-09-21 00:00:00');          // after
        // converted INSIDE the range but created OUTSIDE it — not in the cohort.
        $this->lead($account, ['status' => 'converted', 'converted_at' => '2026-09-15 10:00:00'], '2026-08-01 10:00:00');

        $r = $this->analytics($this->user($account), ['from' => '2026-09-10', 'to' => '2026-09-20'])->assertOk();

        $r->assertJsonPath('data.totals.total', 3);
        $r->assertJsonPath('data.totals.converted', 0);
        $r->assertJsonPath('data.range', ['from' => '2026-09-10', 'to' => '2026-09-20', 'interval' => 'day', 'timezone' => 'UTC']);
        $this->assertCount(11, $r->json('data.trend'));

        $this->analytics($this->user($account), ['from' => '2026-09-21', 'to' => '2026-09-21'])->assertOk()->assertJsonPath('data.totals.total', 1);
        $this->analytics($this->user($account), ['to' => '2026-09-09'])->assertOk()->assertJsonPath('data.totals.total', 2);
        $this->analytics($this->user($account), ['from' => '2026-09-21'])->assertOk()->assertJsonPath('data.totals.total', 1);
    }

    public function test_the_contact_and_capture_dates_are_not_used(): void
    {
        $account = $this->account();
        $lead = $this->lead($account, [], '2026-09-15 10:00:00');
        DB::table('contacts')->where('id', $lead->contact_id)->update(['created_at' => '2020-01-01 00:00:00']);

        $this->analytics($this->user($account), ['from' => '2026-09-15', 'to' => '2026-09-15'])->assertOk()->assertJsonPath('data.totals.total', 1);
        $this->analytics($this->user($account), ['from' => '2020-01-01', 'to' => '2020-01-01'])->assertOk()->assertJsonPath('data.totals.total', 0);
    }

    public function test_the_trend_interval_follows_the_range_length(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $this->lead($account, ['status' => 'converted'], '2026-06-24 10:00:00');
        $this->lead($account, [], '2026-09-23 08:00:00');

        $r = $this->analytics($user, ['from' => '2026-06-24', 'to' => '2026-09-23'])->assertOk(); // 92 days
        $r->assertJsonPath('data.range.interval', 'day');
        $this->assertCount(92, $r->json('data.trend'));

        $r = $this->analytics($user, ['from' => '2026-06-23', 'to' => '2026-09-23'])->assertOk(); // 93 days
        $r->assertJsonPath('data.range.interval', 'week');
        $trend = collect($r->json('data.trend'));
        $this->assertSame('2026-06-23', $trend->first()['period'], 'the first bucket is clamped to `from`');
        $this->assertSame(2, $trend->sum('total'));
        $this->assertSame(1, $trend->sum('converted'));

        $r = $this->analytics($user, ['from' => '2024-01-01', 'to' => '2026-09-23'])->assertOk();
        $r->assertJsonPath('data.range.interval', 'month');
        $this->assertCount(33, $r->json('data.trend'));
        $this->assertSame(2, collect($r->json('data.trend'))->sum('total'));
    }

    // =================================================================
    // 3. Filters
    // =================================================================

    public function test_every_filter_and_their_combination(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $asha = $this->user($account, null, ['manage-crm'], 'Asha');
        $hot = app(CrmTagService::class)->create($account, 'Hot');

        $target = $this->lead($account, ['status' => 'converted', 'source' => 'meta_ad', 'assigned_user_id' => $asha->id], '2026-09-15 10:00:00');
        app(CrmTagService::class)->attach($target, $hot);
        $this->lead($account, ['status' => 'converted', 'source' => 'meta_ad', 'assigned_user_id' => $asha->id], '2026-09-01 10:00:00'); // date
        $this->lead($account, ['status' => 'converted', 'source' => 'api', 'assigned_user_id' => $asha->id], '2026-09-15 10:00:00');      // source
        $this->lead($account, ['status' => 'new', 'source' => 'meta_ad', 'assigned_user_id' => $asha->id], '2026-09-15 10:00:00');        // status
        $this->lead($account, ['status' => 'converted', 'source' => 'meta_ad'], '2026-09-15 10:00:00');                                   // assignee

        $this->analytics($user, ['status' => 'converted'])->assertJsonPath('data.totals.total', 4);
        $this->analytics($user, ['source' => 'meta_ad'])->assertJsonPath('data.totals.total', 4);
        $this->analytics($user, ['assigned_user_id' => $asha->id])->assertJsonPath('data.totals.total', 4);
        $this->analytics($user, ['assigned_user_id' => 'none'])->assertJsonPath('data.totals.total', 1);
        $this->analytics($user, ['tag_id' => $hot->id])->assertJsonPath('data.totals.total', 1);
        $this->analytics($user, ['from' => '2026-09-10'])->assertJsonPath('data.totals.total', 4);

        $r = $this->analytics($user, [
            'status' => 'converted', 'source' => 'meta_ad', 'assigned_user_id' => $asha->id, 'from' => '2026-09-10', 'to' => '2026-09-20',
        ])->assertOk();
        $r->assertJsonPath('data.totals.total', 1);
        $r->assertJsonPath('data.totals.conversion_rate', 100);
        $r->assertJsonPath('data.filters.status', 'converted');
        $r->assertJsonPath('data.filters.source', 'meta_ad');
        $this->assertSame([['assigned_user_id' => $asha->id, 'name' => 'Asha', 'count' => 1]], $r->json('data.by_assignee'));

        $this->analytics($user, ['status' => 'converted', 'source' => 'api', 'assigned_user_id' => 'none'])->assertOk()->assertJsonPath('data.totals.total', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidFilterProvider(): array
    {
        return [
            'bad from format' => [['from' => '23-09-2026'], 'from'],
            'impossible date' => [['from' => '2026-02-30'], 'from'],
            'bad to format' => [['to' => '2026/09/01'], 'to'],
            'to before from' => [['from' => '2026-09-10', 'to' => '2026-09-09'], 'to'],
            'unknown status' => [['status' => 'won'], 'status'],
            'unknown source' => [['source' => 'tiktok'], 'source'],
            'bad assignee' => [['assigned_user_id' => 'abc'], 'assigned_user_id'],
            'zero assignee' => [['assigned_user_id' => '0'], 'assigned_user_id'],
            'missing tag' => [['tag_id' => 999999], 'tag_id'],
            'too many tags' => [['tag_ids' => range(1, 11)], 'tag_ids'],
        ];
    }

    /** @dataProvider invalidFilterProvider */
    public function test_invalid_filters_are_422(array $query, string $field): void
    {
        $account = $this->account();
        $this->analytics($this->user($account), $query)->assertStatus(422)->assertJsonValidationErrors([$field]);
    }

    public function test_a_foreign_tag_is_rejected_like_a_missing_one(): void
    {
        $mine = $this->account();
        $foreignTag = app(CrmTagService::class)->create($this->account(), 'Theirs');
        $user = $this->user($mine);

        $foreign = $this->analytics($user, ['tag_id' => $foreignTag->id])->assertStatus(422);
        $missing = $this->analytics($user, ['tag_id' => 99999999])->assertStatus(422);
        $this->assertSame($missing->json('errors'), $foreign->json('errors'));
    }

    // =================================================================
    // 4. Tenant isolation & hierarchy
    // =================================================================

    public function test_tenant_a_never_sees_tenant_b(): void
    {
        $a = $this->account();
        $b = $this->account();
        $this->lead($a, ['status' => 'converted']);
        foreach (range(1, 5) as $i) {
            $this->lead($b, ['status' => 'converted', 'source' => 'api']);
        }

        $r = $this->analytics($this->user($a))->assertOk();
        $r->assertJsonPath('data.totals.total', 1);
        $this->assertSame(0, $this->sourceCounts($r)['api']);

        // A client admin's ?account_id= is ignored.
        $this->analytics($this->user($a), ['account_id' => $b->id])->assertOk()->assertJsonPath('data.totals.total', 1);
    }

    public function test_a_foreign_assignee_filter_matches_nothing(): void
    {
        $a = $this->account();
        $b = $this->account();
        $theirUser = $this->user($b, null, ['manage-crm']);
        $theirLead = $this->lead($b, ['assigned_user_id' => $theirUser->id]);
        $this->lead($a);

        $r = $this->analytics($this->user($a), ['assigned_user_id' => $theirUser->id])->assertOk();
        $r->assertJsonPath('data.totals.total', 0);
        $this->assertSame([], $r->json('data.by_assignee'));
        $this->assertNotNull($theirLead);
    }

    public function test_super_admin_needs_a_selected_client_and_sees_only_it(): void
    {
        $a = $this->account();
        $b = $this->account(crm: false);
        $this->lead($a);
        $this->lead($b);
        $this->lead($b);
        $platform = Account::factory()->create();
        $sa = $this->user($platform, 'super_admin');

        $this->analytics($sa)->assertStatus(422);
        $this->analytics($sa, ['account_id' => $a->id])->assertOk()->assertJsonPath('data.totals.total', 1);
        // The Super Admin is held to the selected client's own CRM entitlement (EnsureCrmTargetAccount).
        $this->analytics($sa, ['account_id' => $b->id])->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->analytics($sa, ['account_id' => 99999999])->assertNotFound();
    }

    public function test_an_agent_sees_only_its_own_sub_clients(): void
    {
        $agent = $this->account(factory: fn ($f) => $f->agent());
        $sub = $this->account(factory: fn ($f) => $f->client($agent));
        $otherSub = $this->account(crm: false, factory: fn ($f) => $f->client($agent));
        $stranger = $this->account();
        $this->lead($sub);
        $this->lead($sub);
        $this->lead($stranger);
        $agentUser = $this->user($agent, null, ['manage-crm']);

        $this->analytics($agentUser, ['account_id' => $sub->id])->assertOk()->assertJsonPath('data.totals.total', 2);
        $this->analytics($agentUser, ['account_id' => $stranger->id])->assertNotFound();
        $this->analytics($agentUser, ['account_id' => $otherSub->id])->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->analytics($agentUser)->assertOk()->assertJsonPath('data.totals.total', 0);
    }

    // =================================================================
    // 5. Authorization — same answers as the existing CRM routes
    // =================================================================

    /** @return array<string, array{callable}> */
    public static function deniedProvider(): array
    {
        return [
            'no crm capability' => [fn (self $t) => $t->user($t->account(crm: false))],
            'lead_crm module off' => [fn (self $t) => $t->user($t->account(attributes: ['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))]))],
            'user role (no manage-crm)' => [fn (self $t) => $t->user($t->account(), 'user')],
            'manage-social-leads only' => [fn (self $t) => $t->user($t->account(), null, ['manage-social-leads'])],
            'view-analytics only' => [fn (self $t) => $t->user($t->account(), null, ['view-analytics'])],
        ];
    }

    /** @dataProvider deniedProvider */
    public function test_denials_match_the_lead_list_exactly(callable $makeUser): void
    {
        $user = $makeUser($this);

        $analytics = $this->actingAs($user)->getJson(self::URL);
        $list = $this->actingAs($user)->getJson('/api/crm/leads');

        $analytics->assertStatus(403);
        $this->assertSame($list->getStatusCode(), $analytics->getStatusCode());
        $this->assertSame($list->json('message'), $analytics->json('message'));
        $this->assertSame($list->json('error_code'), $analytics->json('error_code'));
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_an_expired_subscription_can_still_read_analytics(): void
    {
        $account = $this->account(active: false);
        $this->lead($account);

        $this->analytics($this->user($account))->assertOk()->assertJsonPath('data.totals.total', 1);
    }

    public function test_a_suspended_account_is_refused(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $account->forceFill(['status' => 'suspended'])->save();

        $this->analytics($user)->assertStatus(403)->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
    }

    public function test_the_developer_api_has_no_analytics_endpoint(): void
    {
        $account = $this->account();
        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create(['account_id' => $account->id, 'name' => 'K', 'key_prefix' => substr($plain, 0, 10), 'key_hash' => ApiKey::hashKey($plain)]);

        $this->withHeader('X-API-KEY', $plain)->getJson('/api/v1/crm/analytics')->assertNotFound();
    }

    // =================================================================
    // 6. Scale — correctness and constant query count
    // =================================================================

    public function test_a_large_dataset_aggregates_exactly_like_php(): void
    {
        $account = $this->account();
        $other = $this->account();
        $users = [$this->user($account, null, ['manage-crm'], 'U1'), $this->user($account, null, ['manage-crm'], 'U2'), $this->user($account, null, ['manage-crm'], 'U3')];
        $this->bulkLeads($other, 300, []);            // noise in another tenant
        $expected = $this->bulkLeads($account, 600, $users);

        $r = $this->analytics($this->user($account), ['from' => '2026-07-01', 'to' => '2026-09-23'])->assertOk();

        $inRange = array_filter($expected, fn ($l) => $l['created_at'] >= '2026-07-01 00:00:00');
        $r->assertJsonPath('data.totals.total', count($inRange));
        $this->assertEquals(array_count_values(array_column($inRange, 'status')), array_filter($this->statusCounts($r)));
        $this->assertEquals(array_count_values(array_column($inRange, 'source')), array_filter($this->sourceCounts($r)));
        $byAssignee = collect($r->json('data.by_assignee'))->mapWithKeys(fn ($row) => [(string) ($row['assigned_user_id'] ?? 'none') => $row['count']])->all();
        $php = array_count_values(array_map(fn ($l) => (string) ($l['assigned_user_id'] ?? 'none'), $inRange));
        ksort($byAssignee);
        ksort($php);
        $this->assertSame($php, $byAssignee);
        $converted = count(array_filter($inRange, fn ($l) => $l['status'] === 'converted'));
        $r->assertJsonPath('data.totals.conversion_rate', round($converted / count($inRange) * 100, 2));
        $this->assertSame(count($inRange), collect($r->json('data.trend'))->sum('total'));
        $this->assertSame($converted, collect($r->json('data.trend'))->sum('converted'));
    }

    public function test_the_query_count_does_not_grow_with_the_data(): void
    {
        $account = $this->account();
        $users = [$this->user($account, null, ['manage-crm'], 'U1'), $this->user($account, null, ['manage-crm'], 'U2')];
        $viewer = $this->user($account);

        $this->bulkLeads($account, 10, $users);
        $small = $this->countQueries(fn () => $this->analytics($viewer)->assertOk());
        $this->bulkLeads($account, 400, $users);
        $large = $this->countQueries(fn () => $this->analytics($viewer)->assertOk());

        $this->assertSame($small, $large);
    }

    private function countQueries(callable $run): int
    {
        $n = 0;
        DB::listen(function ($q) use (&$n) {
            if (str_contains($q->sql, 'crm_leads') || str_contains($q->sql, '"users"') || str_contains($q->sql, '`users`')) {
                $n++;
            }
        });
        $run();
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $n;
    }

    /**
     * Deterministic pseudo-random leads inserted in bulk (faster than
     * factories at this size). Returns what was inserted for PHP-side
     * recomputation.
     *
     * @param list<User> $users
     * @return list<array<string, mixed>>
     */
    private function bulkLeads(Account $account, int $n, array $users): array
    {
        mt_srand($account->id * 7919 + $n);
        $statuses = CrmLead::STATUSES;
        $sources = CrmLead::SOURCES;
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $phone = (string) $this->phone++;
            $contactId = DB::table('contacts')->insertGetId(['account_id' => $account->id, 'phone_number' => $phone, 'created_at' => now(), 'updated_at' => now()]);
            $pick = mt_rand(0, count($users));
            $rows[] = [
                'account_id' => $account->id,
                'contact_id' => $contactId,
                'status' => $statuses[mt_rand(0, 3)],
                'source' => $sources[mt_rand(0, 4)],
                'assigned_user_id' => $pick === count($users) ? null : $users[$pick]->id,
                'created_at' => CarbonImmutable::parse('2026-05-01')->addMinutes(mt_rand(0, 145 * 24 * 60))->min(CarbonImmutable::parse('2026-09-23 11:00:00'))->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('crm_leads')->insert($chunk);
        }

        return $rows;
    }
}
