<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\OrganicPost;
use App\Models\OrganicPostInsight;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 9 Task 5 — account-level social analytics dashboard over the
 * persisted Task 4 snapshots: aggregation rules (sum of reported values
 * only; unavailable ≠ zero; view-weighted watch time; engagement =
 * reactions + comments + shares + saves), published-in-range population,
 * platform breakdown without double counting, trends, top posts, empty
 * states, authorization (view-only analytics users, Super Admin target
 * selection, target gate) and "no provider call, constant query count".
 */
class SocialAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake();
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(): Account
    {
        $account = Account::factory()->create(['allow_facebook' => true, 'allow_instagram' => true]);
        $plan = PlanCatalog::find('growth');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth',
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return $account->fresh();
    }

    private function user(Account $account, string $role = 'social_marketer'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** A user who can ONLY view social analytics (no manage-social-accounts). */
    private function viewer(Account $account): User
    {
        $role = Role::firstOrCreate(['name' => 'analytics_viewer', 'guard_name' => 'web']);
        $role->syncPermissions(['view-social-analytics']);

        return $this->user($account, 'analytics_viewer');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function connection(Account $account, string $assetType = 'facebook_page', array $attributes = []): SocialAccount
    {
        return SocialAccount::create($attributes + [
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => $assetType,
            'provider_id' => strtoupper($assetType).$account->id.uniqid(), 'name' => 'Acme', 'access_token' => 'EAA-token',
            'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);
    }

    /**
     * A published post and (optionally) its snapshot.
     *
     * @param  array<string, int|null>|null  $metrics  null = never fetched
     */
    private function published(Account $account, string $platform, string $publishedAt, ?array $metrics, array $post = [], array $insight = []): OrganicPost
    {
        $model = OrganicPost::create($post + [
            'account_id' => $account->id, 'social_account_id' => null, 'provider' => 'meta', 'platform' => $platform,
            'caption' => "Post on {$platform} {$publishedAt}", 'status' => OrganicPost::STATUS_PUBLISHED,
            'external_post_id' => 'X'.uniqid(), 'published_at' => $publishedAt, 'attempts' => 1,
        ]);

        if ($metrics !== null) {
            OrganicPostInsight::create($insight + $metrics + [
                'account_id' => $account->id, 'organic_post_id' => $model->id, 'provider' => 'meta', 'platform' => $platform,
                'provider_post_id' => (string) $model->external_post_id, 'state' => 'ok', 'metrics_fetched_at' => now()->subHour(),
            ]);
        }

        return $model;
    }

    private function dashboard(User $user, string $query = '')
    {
        return $this->actingAs($user)->getJson('/api/social/analytics/dashboard'.$query);
    }

    // ================================================================== aggregation

    public function test_totals_sum_only_reported_values_and_keep_zero_distinct_from_unavailable(): void
    {
        $account = $this->tenant();
        $this->published($account, 'facebook', '2026-09-20 10:00:00', ['reactions' => 10, 'comments' => 0, 'shares' => 2, 'reach' => 100, 'impressions' => 150, 'clicks' => 0]);
        $this->published($account, 'facebook', '2026-09-21 10:00:00', ['reactions' => 5, 'comments' => 3, 'reach' => 50]); // shares not reported
        $this->published($account, 'instagram', '2026-09-22 10:00:00', ['reactions' => 7, 'comments' => 1, 'saves' => 4, 'shares' => 1, 'reach' => 70]);

        $this->dashboard($this->user($account))->assertOk()
            ->assertJsonPath('data.summary.published', 3)
            ->assertJsonPath('data.summary.metrics.reactions', ['value' => 22, 'status' => 'available', 'posts_reporting' => 3])
            ->assertJsonPath('data.summary.metrics.comments.value', 4)
            ->assertJsonPath('data.summary.metrics.shares', ['value' => 3, 'status' => 'available', 'posts_reporting' => 2])
            ->assertJsonPath('data.summary.metrics.saves', ['value' => 4, 'status' => 'available', 'posts_reporting' => 1])
            ->assertJsonPath('data.summary.metrics.reach.value', 220)
            ->assertJsonPath('data.summary.metrics.impressions', ['value' => 150, 'status' => 'available', 'posts_reporting' => 1])
            ->assertJsonPath('data.summary.metrics.clicks', ['value' => 0, 'status' => 'available', 'posts_reporting' => 1])
            ->assertJsonPath('data.summary.metrics.video_views', ['value' => null, 'status' => 'unavailable', 'posts_reporting' => 0])
            ->assertJsonPath('data.summary.metrics.video_avg_watch_time_ms.status', 'unavailable')
            ->assertJsonPath('data.summary.engagement.value', 22 + 4 + 3 + 4)
            ->assertJsonPath('data.summary.engagement.components_included', ['reactions', 'comments', 'shares', 'saves']);
    }

    public function test_average_watch_time_is_view_weighted_not_summed(): void
    {
        $account = $this->tenant();
        $this->published($account, 'instagram', '2026-09-20 10:00:00', ['video_views' => 100, 'video_avg_watch_time_ms' => 2000], ['media_type' => 'video']);
        $this->published($account, 'facebook', '2026-09-21 10:00:00', ['video_views' => 300, 'video_avg_watch_time_ms' => 6000], ['media_type' => 'video']);
        $this->published($account, 'facebook', '2026-09-22 10:00:00', ['video_views' => 0, 'video_avg_watch_time_ms' => 0], ['media_type' => 'video']);

        $this->dashboard($this->user($account))->assertOk()
            ->assertJsonPath('data.summary.metrics.video_views', ['value' => 400, 'status' => 'available', 'posts_reporting' => 3])
            ->assertJsonPath('data.summary.metrics.video_avg_watch_time_ms', ['value' => 5000, 'status' => 'available', 'posts_reporting' => 2]);
    }

    public function test_platform_breakdown_counts_each_publication_once(): void
    {
        $account = $this->tenant();
        // The same caption published to both platforms = two publications, one per platform.
        $this->published($account, 'facebook', '2026-09-20 10:00:00', ['reactions' => 10, 'reach' => 100], ['caption' => 'Launch']);
        $this->published($account, 'instagram', '2026-09-20 10:00:00', ['reactions' => 4, 'reach' => 40, 'saves' => 2], ['caption' => 'Launch']);

        $response = $this->dashboard($this->user($account))->assertOk();
        $platforms = collect($response->json('data.platforms'))->keyBy('platform');

        $this->assertSame(['facebook', 'instagram'], $platforms->keys()->all());
        $this->assertSame(1, $platforms['facebook']['published']);
        $this->assertSame(10, $platforms['facebook']['metrics']['reactions']['value']);
        $this->assertSame('unavailable', $platforms['facebook']['metrics']['saves']['status'], 'no invented Facebook saves');
        $this->assertSame(2, $platforms['instagram']['metrics']['saves']['value']);
        $this->assertSame(140, $response->json('data.summary.metrics.reach.value'));
        $this->assertSame(2, $response->json('data.summary.published'));
    }

    public function test_only_facebook_data_has_no_instagram_row(): void
    {
        $account = $this->tenant();
        $this->published($account, 'facebook', '2026-09-20 10:00:00', ['reactions' => 1]);

        $platforms = $this->dashboard($this->user($account))->assertOk()->json('data.platforms');
        $this->assertSame(['facebook'], array_column($platforms, 'platform'));
    }

    public function test_only_instagram_data_is_aggregated_on_its_own(): void
    {
        $account = $this->tenant();
        $this->published($account, 'instagram', '2026-09-20 10:00:00', ['reactions' => 8, 'comments' => 2, 'reach' => 90]);

        $response = $this->dashboard($this->user($account))->assertOk();
        $this->assertSame(['instagram'], array_column($response->json('data.platforms'), 'platform'));
        $this->assertSame(90, $response->json('data.platforms.0.metrics.reach.value'));
        $this->assertSame(10, $response->json('data.summary.engagement.value'));
    }

    // ================================================================== population & range

    public function test_failed_cancelled_and_unpublished_posts_are_excluded_from_metrics(): void
    {
        $account = $this->tenant();
        $this->published($account, 'facebook', '2026-09-20 10:00:00', ['reactions' => 3]);
        foreach ([OrganicPost::STATUS_FAILED, OrganicPost::STATUS_CANCELLED, OrganicPost::STATUS_SCHEDULED, OrganicPost::STATUS_RECONNECT_REQUIRED] as $status) {
            // Even with a (stale) snapshot row, a non-published post never counts.
            $this->published($account, 'facebook', '2026-09-20 10:00:00', ['reactions' => 1000], ['status' => $status]);
        }

        $this->dashboard($this->user($account))->assertOk()
            ->assertJsonPath('data.summary.published', 1)
            ->assertJsonPath('data.summary.metrics.reactions.value', 3)
            ->assertJsonPath('data.status_summary.failed', 1)
            ->assertJsonPath('data.status_summary.cancelled', 1)
            ->assertJsonPath('data.status_summary.scheduled', 1)
            ->assertJsonPath('data.status_summary.reconnect_required', 1);
    }

    public function test_the_range_uses_published_at_not_created_at(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $old = $this->published($account, 'facebook', '2026-08-01 10:00:00', ['reactions' => 100]);
        $old->forceFill(['created_at' => now()])->save(); // created recently, published long ago
        $this->published($account, 'facebook', '2026-09-25 10:00:00', ['reactions' => 5]);
        $this->published($account, 'facebook', '2026-09-24 00:00:00', ['reactions' => 1]); // first second of the 7-day window

        $this->dashboard($user, '?range=7d')->assertOk()
            ->assertJsonPath('data.range.from', '2026-09-24')
            ->assertJsonPath('data.range.to', '2026-09-30')
            ->assertJsonPath('data.summary.published', 2)
            ->assertJsonPath('data.summary.metrics.reactions.value', 6);

        $this->dashboard($user, '?range=90d')->assertOk()->assertJsonPath('data.summary.metrics.reactions.value', 106);
        $this->dashboard($user, '?range=custom&from=2026-08-01&to=2026-08-01')->assertOk()
            ->assertJsonPath('data.summary.published', 1)->assertJsonPath('data.range.days', 1);
    }

    public function test_invalid_ranges_are_rejected(): void
    {
        $user = $this->user($this->tenant());

        $this->dashboard($user, '?range=365d')->assertStatus(422)->assertJsonValidationErrors('range');
        $this->dashboard($user, '?range=custom')->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
        $this->dashboard($user, '?range=custom&from=2026-09-10&to=2026-09-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->dashboard($user, '?range=custom&from=2025-01-01&to=2026-09-01')->assertStatus(422)->assertJsonValidationErrors('from');
    }

    // ================================================================== trend / top posts

    public function test_trend_buckets_every_day_and_leaves_unreported_days_null(): void
    {
        $account = $this->tenant();
        $this->published($account, 'facebook', '2026-09-28 09:00:00', ['reach' => 10, 'reactions' => 2, 'comments' => 1]);
        $this->published($account, 'instagram', '2026-09-28 18:00:00', ['reach' => 5, 'saves' => 1]);
        $this->published($account, 'facebook', '2026-09-29 09:00:00', null); // never fetched

        $trend = $this->dashboard($this->user($account), '?range=7d')->assertOk()->json('data.trend');

        $this->assertSame('day', $trend['interval']);
        $this->assertCount(7, $trend['points']);
        $byDay = collect($trend['points'])->keyBy('period');
        $this->assertSame(['period' => '2026-09-28', 'published' => 2, 'reach' => 15, 'impressions' => null, 'video_views' => null, 'engagement' => 4], $byDay['2026-09-28']);
        $this->assertSame(['period' => '2026-09-29', 'published' => 1, 'reach' => null, 'impressions' => null, 'video_views' => null, 'engagement' => null], $byDay['2026-09-29']);
        $this->assertSame(0, $byDay['2026-09-24']['published']);
        $this->assertNull($byDay['2026-09-24']['reach']);
    }

    public function test_long_ranges_are_bucketed_by_week(): void
    {
        $account = $this->tenant();
        $this->published($account, 'facebook', '2026-09-01 09:00:00', ['reach' => 1]);
        $this->published($account, 'facebook', '2026-09-02 09:00:00', ['reach' => 2]);

        $trend = $this->dashboard($this->user($account), '?range=90d')->assertOk()->json('data.trend');

        $this->assertSame('week', $trend['interval']);
        $this->assertSame(3, collect($trend['points'])->sum(fn ($p) => $p['reach'] ?? 0));
        $this->assertSame(2, collect($trend['points'])->sum('published'));
        $this->assertLessThanOrEqual(14, count($trend['points']));
    }

    public function test_top_posts_rank_only_posts_that_reported_the_metric(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $a = $this->published($account, 'facebook', '2026-09-20 10:00:00', ['reactions' => 10, 'comments' => 5, 'reach' => 50]);
        $b = $this->published($account, 'instagram', '2026-09-21 10:00:00', ['reactions' => 30, 'saves' => 2, 'reach' => null]);
        $this->published($account, 'facebook', '2026-09-22 10:00:00', ['impressions' => 999]); // no engagement component
        $this->published($account, 'facebook', '2026-09-23 10:00:00', null); // never fetched
        $this->published($account, 'facebook', '2026-09-24 10:00:00', ['reactions' => 500], ['status' => OrganicPost::STATUS_FAILED]);

        $top = $this->dashboard($user)->assertOk()->json('data.top_posts');
        $this->assertSame([$b->id, $a->id], array_column($top, 'post_id'));
        $this->assertSame(32, $top[0]['value']);
        $this->assertSame(['reactions' => 30, 'comments' => null, 'shares' => null, 'saves' => 2], $top[0]['engagement_components']);
        $this->assertStringContainsString('Post on instagram', $top[0]['caption']);

        $this->actingAs($user)->getJson('/api/social/analytics/top-posts?metric=reach')->assertOk()
            ->assertJsonPath('data.metric', 'reach')
            ->assertJsonCount(1, 'data.posts')
            ->assertJsonPath('data.posts.0.post_id', $a->id);
        $this->actingAs($user)->getJson('/api/social/analytics/top-posts?metric=clicks')->assertStatus(422);
    }

    // ================================================================== empty / unavailable states

    public function test_an_account_with_nothing_published_reports_no_data_not_zero(): void
    {
        $account = $this->tenant();

        $response = $this->dashboard($this->user($account))->assertOk()
            ->assertJsonPath('data.summary.published', 0)
            ->assertJsonPath('data.summary.metrics.reach', ['value' => null, 'status' => 'no_data', 'posts_reporting' => 0])
            ->assertJsonPath('data.summary.engagement.value', null)
            ->assertJsonPath('data.summary.engagement.status', 'no_data')
            ->assertJsonPath('data.platforms', [])
            ->assertJsonPath('data.top_posts', [])
            ->assertJsonPath('data.freshness.last_fetched_at', null)
            ->assertJsonPath('data.connections.facebook_pages', 0)
            ->assertJsonPath('data.connections.instagram_accounts', 0);

        $this->assertCount(30, $response->json('data.trend.points'));
    }

    public function test_insights_never_fetched_and_missing_provider_ids_are_reported_as_such(): void
    {
        $account = $this->tenant();
        $this->published($account, 'facebook', '2026-09-20 10:00:00', null);
        $this->published($account, 'facebook', '2026-09-21 10:00:00', null, ['external_post_id' => null]);

        $this->dashboard($this->user($account))->assertOk()
            ->assertJsonPath('data.summary.published', 2)
            ->assertJsonPath('data.summary.metrics.reactions.status', 'not_fetched')
            ->assertJsonPath('data.summary.metrics.reactions.value', null)
            ->assertJsonPath('data.coverage.never_fetched', 2)
            ->assertJsonPath('data.coverage.without_provider_id', 1)
            ->assertJsonPath('data.freshness.never_fetched', 2);
    }

    public function test_all_metrics_unavailable_and_partial_and_reconnect_states(): void
    {
        $account = $this->tenant();
        $this->connection($account, 'facebook_page', ['health_status' => SocialAccount::HEALTH_REAUTH_REQUIRED]);
        $this->connection($account, 'instagram');
        $this->published($account, 'facebook', '2026-09-20 10:00:00', [], [], ['state' => 'partial']);
        $this->published($account, 'facebook', '2026-09-21 10:00:00', ['reactions' => 2], [], ['state' => 'reconnect_required']);
        $this->published($account, 'instagram', '2026-09-22 10:00:00', [], [], ['state' => 'post_not_found']);

        $this->dashboard($this->user($account))->assertOk()
            ->assertJsonPath('data.summary.metrics.reach.status', 'unavailable')
            ->assertJsonPath('data.summary.metrics.reactions.value', 2)
            ->assertJsonPath('data.coverage.partial', 1)
            ->assertJsonPath('data.coverage.reconnect_required', 1)
            ->assertJsonPath('data.coverage.post_not_found', 1)
            ->assertJsonPath('data.connections', ['facebook_pages' => 1, 'instagram_accounts' => 1, 'needing_reconnect' => 1]);
    }

    public function test_the_last_refresh_time_is_the_newest_snapshot_in_range(): void
    {
        $account = $this->tenant();
        $this->published($account, 'facebook', '2026-09-20 10:00:00', ['reach' => 1], [], ['metrics_fetched_at' => '2026-09-29 08:00:00']);
        $this->published($account, 'facebook', '2026-09-21 10:00:00', ['reach' => 1], [], ['metrics_fetched_at' => '2026-09-30 07:30:00']);
        $this->published($account, 'facebook', '2026-06-01 10:00:00', ['reach' => 1], [], ['metrics_fetched_at' => '2026-09-30 11:00:00']); // out of range

        $response = $this->dashboard($this->user($account))->assertOk();
        $this->assertSame('2026-09-30 07:30:00', \Illuminate\Support\Carbon::parse($response->json('data.freshness.last_fetched_at'))->format('Y-m-d H:i:s'));
    }

    // ================================================================== no provider calls / performance

    public function test_dashboard_reads_never_call_meta_and_use_a_constant_number_of_queries(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $this->published($account, 'facebook', '2026-09-20 10:00:00', ['reach' => 1]);

        $count = function () use ($user): int {
            $n = 0;
            DB::listen(function ($q) use (&$n) {
                if (str_contains($q->sql, 'organic_post')) {
                    $n++;
                }
            });
            $this->dashboard($user, '?range=90d')->assertOk();
            $this->actingAs($user)->getJson('/api/social/analytics/top-posts?metric=reach')->assertOk();

            return $n;
        };

        $small = $count();
        for ($i = 0; $i < 40; $i++) {
            $this->published($account, $i % 2 ? 'facebook' : 'instagram', '2026-09-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT).' 10:00:00', ['reach' => $i, 'reactions' => 1]);
        }
        DB::flushQueryLog();
        $large = $count();

        $this->assertSame($small, $large, 'no per-post queries');
        $this->assertLessThanOrEqual(6, $small);
        Http::assertNothingSent();
    }

    // ================================================================== authorization

    public function test_a_view_only_analytics_user_can_read_the_dashboard(): void
    {
        $account = $this->tenant();
        $viewer = $this->viewer($account);
        $this->published($account, 'facebook', '2026-09-20 10:00:00', ['reach' => 9]);

        $this->assertFalse($viewer->can('manage-social-accounts'));
        $this->dashboard($viewer)->assertOk()->assertJsonPath('data.summary.metrics.reach.value', 9);
        $this->actingAs($viewer)->getJson('/api/social/analytics/top-posts')->assertOk();

        // …and still cannot manage anything.
        $this->actingAs($viewer)->getJson('/api/social/organic-posts')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'x'])->assertForbidden();
    }

    public function test_without_view_social_analytics_access_is_refused(): void
    {
        $account = $this->tenant();

        $this->dashboard($this->user($account, 'user'))->assertForbidden();
        $this->actingAs($this->user($account, 'user'))->getJson('/api/social/analytics/top-posts')->assertForbidden();
    }

    public function test_module_capability_and_subscription_are_enforced(): void
    {
        $noModule = $this->tenant();
        $noModule->forceFill(['allowed_modules' => ['campaigns']])->save();
        $this->dashboard($this->user($noModule))->assertForbidden();

        $noCapability = $this->tenant();
        AccountEntitlement::where('account_id', $noCapability->id)->where('capability_id', Capability::where('slug', 'social')->value('id'))->delete();
        $this->dashboard($this->user($noCapability))->assertForbidden();

        $lapsed = $this->tenant();
        Subscription::where('account_id', $lapsed->id)->update(['expires_at' => now()->subDay()]);
        $this->dashboard($this->user($lapsed))->assertForbidden();
    }

    public function test_accounts_only_ever_see_their_own_posts(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $this->published($mine, 'facebook', '2026-09-20 10:00:00', ['reach' => 1]);
        $this->published($theirs, 'facebook', '2026-09-20 10:00:00', ['reach' => 1000]);
        // A snapshot row pointing at my post but carrying another account id never joins.
        $crossed = $this->published($mine, 'facebook', '2026-09-21 10:00:00', null);
        OrganicPostInsight::create(['account_id' => $theirs->id, 'organic_post_id' => $crossed->id, 'provider' => 'meta', 'platform' => 'facebook',
            'provider_post_id' => 'Z', 'state' => 'ok', 'metrics_fetched_at' => now(), 'reach' => 5000]);

        $response = $this->dashboard($this->user($mine))->assertOk()
            ->assertJsonPath('data.summary.published', 2)
            ->assertJsonPath('data.summary.metrics.reach.value', 1);
        $this->assertStringNotContainsString('1000', json_encode($response->json('data.top_posts')));

        // A tenant user cannot switch to another account with ?account_id= (ignored: own data only).
        $this->dashboard($this->user($mine), "?account_id={$theirs->id}")->assertOk()->assertJsonPath('data.summary.metrics.reach.value', 1);
    }

    public function test_super_admin_must_select_an_entitled_target_and_each_target_sees_its_own_data(): void
    {
        $admin = $this->superAdmin();
        $a = $this->tenant();
        $b = $this->tenant();
        $this->published($a, 'facebook', '2026-09-20 10:00:00', ['reach' => 11]);
        $this->published($b, 'instagram', '2026-09-20 10:00:00', ['reach' => 22]);

        // No target: refused — the platform account is never used as an analytics tenant.
        $this->dashboard($admin)->assertStatus(422);
        $this->actingAs($admin)->getJson('/api/social/analytics/top-posts')->assertStatus(422);

        // …on every environment: requireAccount()'s APP_ENV=local first-account fallback is not used here.
        $this->app['env'] = 'local';
        $this->dashboard($admin)->assertStatus(422);
        $this->app['env'] = 'testing';

        // Switching targets switches every figure.
        $this->dashboard($admin, "?account_id={$a->id}")->assertOk()->assertJsonPath('data.summary.metrics.reach.value', 11)->assertJsonPath('data.platforms.0.platform', 'facebook');
        $this->dashboard($admin, "?account_id={$b->id}")->assertOk()->assertJsonPath('data.summary.metrics.reach.value', 22)->assertJsonPath('data.platforms.0.platform', 'instagram');

        // The selected target must pass suspension / subscription / module checks; its plan does not bind a Super Admin (2026-09-30).
        AccountEntitlement::where('account_id', $b->id)->where('capability_id', Capability::where('slug', 'social')->value('id'))->delete();
        $this->dashboard($admin, "?account_id={$b->id}")->assertOk();

        Subscription::where('account_id', $a->id)->update(['expires_at' => now()->subDay()]);
        // Owner decision (2026-09-30): a lapsed subscription does not stop a Super Admin (suspension and module switches still do).
        $this->dashboard($admin, "?account_id={$a->id}")->assertOk();

        $a->forceFill(['status' => 'suspended'])->save();
        $this->dashboard($admin, "?account_id={$a->id}")->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');

        $this->dashboard($admin, '?account_id=999999')->assertNotFound();
    }

    public function test_the_analytics_code_makes_no_provider_calls(): void
    {
        foreach (['Services/Social/Insights/SocialAnalyticsService.php', 'Http/Controllers/Api/SocialAnalyticsController.php'] as $relative) {
            $source = file_get_contents(app_path($relative));
            $this->assertStringNotContainsString('graph.facebook.com', $source);
            $this->assertStringNotContainsString('Facades\\Http', $source);
            $this->assertStringNotContainsString('PostInsightsService', $source, 'reads never trigger a refresh');
        }
    }
}
