<?php

namespace Tests\Feature;

use App\Jobs\RefreshPostInsightsJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\OrganicPost;
use App\Models\OrganicPostInsight;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Social\Insights\PostInsightsService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 9 Task 4 — organic post insights: Meta Facebook / Instagram metrics
 * normalised into organic_post_insights, unavailable vs zero vs not
 * fetched, the controlled refresh (cache window, back-off, lease, worker),
 * connection health (expired / revoked vs rate limit / 5xx / network), and
 * tenant / target-account authorization on every endpoint.
 */
class SocialPostInsightsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAA-page-token-never-exposed';

    /**
     * Graph answers by URL fragment (first match wins); '*' is the default.
     *
     * @var array<string, array{0: int, 1: array<string, mixed>}|'down'|callable>
     */
    private array $graph = [];

    private int $attempted = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config(['social.insights.min_refresh_minutes' => 5, 'social.insights.freshness_minutes' => 360, 'social.insights.backoff_minutes' => [5, 30, 120]]);

        Http::fake(function (HttpRequest $request) {
            $this->attempted++;

            foreach ($this->graph as $fragment => $answer) {
                if ($fragment === '*' || str_contains($request->url(), $fragment)) {
                    if (is_callable($answer)) {
                        $answer = $answer($request);
                    }
                    if ($answer === 'down') {
                        throw new ConnectionException('Connection timed out');
                    }

                    return Http::response($answer[1], $answer[0]);
                }
            }

            return Http::response([], 404);
        });
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
            'provider_id' => strtoupper($assetType).$account->id, 'name' => 'Acme', 'access_token' => self::TOKEN,
            'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);
    }

    private function organicPost(Account $account, SocialAccount $connection, array $attributes = []): OrganicPost
    {
        return OrganicPost::create($attributes + [
            'account_id' => $account->id, 'social_account_id' => $connection->id, 'provider' => 'meta',
            'platform' => $connection->asset_type === 'instagram' ? 'instagram' : 'facebook',
            'caption' => 'Hello', 'status' => OrganicPost::STATUS_PUBLISHED, 'external_post_id' => 'PG_111',
            'published_at' => now()->subDay(), 'attempts' => 1,
        ]);
    }

    private function revokeSocialCapability(Account $account): void
    {
        AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', Capability::where('slug', 'social')->value('id'))
            ->delete();
    }

    private function graphError(int $code, int $subcode = 0, int $http = 400): array
    {
        return [$http, ['error' => ['message' => 'Raw Meta detail fbtrace_id=XYZ', 'type' => 'OAuthException', 'code' => $code, 'error_subcode' => $subcode ?: null]]];
    }

    private function facebookPostGraph(): void
    {
        $this->graph = [
            '/PG_111/insights' => [200, ['data' => [
                ['name' => 'post_impressions', 'period' => 'lifetime', 'values' => [['value' => 500]]],
                ['name' => 'post_impressions_unique', 'period' => 'lifetime', 'values' => [['value' => 300]]],
                ['name' => 'post_clicks', 'period' => 'lifetime', 'values' => [['value' => 0]]],
            ]]],
            '/PG_111' => [200, ['id' => 'PG_111', 'reactions' => ['summary' => ['total_count' => 12]], 'comments' => ['summary' => ['total_count' => 3]], 'shares' => ['count' => 2]]],
        ];
    }

    private function refresh(User $user, OrganicPost $post, string $query = '')
    {
        return $this->actingAs($user)->postJson("/api/social/organic-posts/{$post->id}/insights/refresh{$query}");
    }

    // ================================================================== Meta metrics

    public function test_facebook_post_insights_are_normalised(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->facebookPostGraph();

        $response = $this->refresh($this->user($account), $post)
            ->assertOk()
            ->assertJsonPath('outcome', 'refreshed')
            ->assertJsonPath('data.state', 'ok')
            ->assertJsonPath('data.metrics.impressions', ['value' => 500, 'status' => 'available', 'reason' => null])
            ->assertJsonPath('data.metrics.reach.value', 300)
            ->assertJsonPath('data.metrics.reactions.value', 12)
            ->assertJsonPath('data.metrics.comments.value', 3)
            ->assertJsonPath('data.metrics.shares.value', 2)
            ->assertJsonPath('data.metrics.clicks', ['value' => 0, 'status' => 'available', 'reason' => null])
            ->assertJsonPath('data.metrics.saves', ['value' => null, 'status' => 'unavailable', 'reason' => 'not_supported'])
            ->assertJsonPath('data.metrics.video_views.status', 'unavailable');

        $this->assertNotNull($response->json('data.fetched_at'));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString('claim_token', $response->getContent());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/PG_111/insights') && str_contains(urldecode($r->url()), 'metric=post_impressions,post_impressions_unique,post_clicks'));
        $this->assertSame(2, $this->attempted);

        $row = OrganicPostInsight::sole();
        $this->assertSame([$account->id, $post->id, $page->id, 'PG_111'], [$row->account_id, $row->organic_post_id, $row->social_account_id, $row->provider_post_id]);
        $this->assertSame(0, $row->clicks, 'a real zero is stored as 0');
        $this->assertNull($row->saves, 'an unavailable metric is stored as NULL');
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $page->fresh()->health_status);
    }

    public function test_facebook_video_uses_video_insights(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page, ['external_post_id' => 'VID_9', 'media_type' => 'video', 'media_url' => 'https://x/v.mp4']);
        $this->graph = [
            '/VID_9/video_insights' => [200, ['data' => [
                ['name' => 'total_video_impressions', 'values' => [['value' => 900]]],
                ['name' => 'total_video_impressions_unique', 'values' => [['value' => 700]]],
                ['name' => 'total_video_views', 'values' => [['value' => 250]]],
                ['name' => 'total_video_avg_time_watched', 'values' => [['value' => 8200]]],
            ]]],
            '/VID_9' => [200, ['id' => 'VID_9', 'reactions' => ['summary' => ['total_count' => 5]], 'comments' => ['summary' => ['total_count' => 0]]]],
        ];

        $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.state', 'ok')
            ->assertJsonPath('data.metrics.video_views.value', 250)
            ->assertJsonPath('data.metrics.video_avg_watch_time_ms.value', 8200)
            ->assertJsonPath('data.metrics.impressions.value', 900)
            ->assertJsonPath('data.metrics.comments.value', 0)
            ->assertJsonPath('data.metrics.shares.reason', 'not_supported')
            ->assertJsonPath('data.metrics.clicks.reason', 'not_supported');
    }

    public function test_instagram_image_insights(): void
    {
        $account = $this->tenant();
        $ig = $this->connection($account, 'instagram');
        $post = $this->organicPost($account, $ig, ['external_post_id' => 'IGM_1', 'media_type' => 'image', 'media_url' => 'https://x/i.jpg']);
        $this->graph = [
            '/IGM_1/insights' => [200, ['data' => [
                ['name' => 'impressions', 'values' => [['value' => 1200]]],
                ['name' => 'reach', 'values' => [['value' => 800]]],
                ['name' => 'saved', 'values' => [['value' => 14]]],
                ['name' => 'shares', 'total_value' => ['value' => 6]],
            ]]],
            '/IGM_1' => [200, ['id' => 'IGM_1', 'like_count' => 40, 'comments_count' => 7, 'media_type' => 'IMAGE', 'media_product_type' => 'FEED']],
        ];

        $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.state', 'ok')
            ->assertJsonPath('data.metrics.reactions.value', 40)
            ->assertJsonPath('data.metrics.comments.value', 7)
            ->assertJsonPath('data.metrics.impressions.value', 1200)
            ->assertJsonPath('data.metrics.reach.value', 800)
            ->assertJsonPath('data.metrics.saves.value', 14)
            ->assertJsonPath('data.metrics.shares.value', 6)
            ->assertJsonPath('data.metrics.clicks.reason', 'not_supported');
    }

    public function test_instagram_reel_reports_views_and_watch_time_and_hidden_likes_stay_unavailable(): void
    {
        $account = $this->tenant();
        $ig = $this->connection($account, 'instagram');
        $post = $this->organicPost($account, $ig, ['external_post_id' => 'IGR_1', 'media_type' => 'video', 'media_url' => 'https://x/v.mp4']);
        $this->graph = [
            '/IGR_1/insights' => [200, ['data' => [
                ['name' => 'reach', 'values' => [['value' => 60]]],
                ['name' => 'saved', 'values' => [['value' => 0]]],
                ['name' => 'shares', 'values' => [['value' => 1]]],
                ['name' => 'views', 'values' => [['value' => 90]]],
                ['name' => 'ig_reels_avg_watch_time', 'values' => [['value' => 4100]]],
            ]]],
            '/IGR_1' => [200, ['id' => 'IGR_1', 'comments_count' => 2, 'media_type' => 'VIDEO', 'media_product_type' => 'REELS']],
        ];

        $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.state', 'partial')
            ->assertJsonPath('data.metrics.video_views.value', 90)
            ->assertJsonPath('data.metrics.video_avg_watch_time_ms.value', 4100)
            ->assertJsonPath('data.metrics.saves.value', 0)
            ->assertJsonPath('data.metrics.reactions', ['value' => null, 'status' => 'unavailable', 'reason' => 'not_reported'])
            ->assertJsonPath('data.metrics.impressions.reason', 'not_supported');
    }

    // ================================================================== partial / unavailable

    public function test_a_missing_insights_permission_is_partial_and_never_marks_the_connection_revoked(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->facebookPostGraph();
        $this->graph = ['/PG_111/insights' => $this->graphError(10)] + $this->graph;

        $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.state', 'partial')
            ->assertJsonPath('data.metrics.reactions.value', 12)
            ->assertJsonPath('data.metrics.impressions', ['value' => null, 'status' => 'unavailable', 'reason' => 'permission'])
            ->assertJsonPath('data.metrics.clicks.reason', 'permission');

        $this->assertSame('connected', $page->fresh()->connectionStatus());
    }

    public function test_one_unsupported_metric_does_not_hide_the_others(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->facebookPostGraph();
        $this->graph = ['/PG_111/insights' => function (HttpRequest $r) {
            $metric = urldecode((string) parse_url($r->url(), PHP_URL_QUERY));

            if (str_contains($metric, 'post_clicks')) {
                return $this->graphError(100);
            }

            $name = str_contains($metric, 'post_impressions_unique') ? 'post_impressions_unique' : 'post_impressions';

            return [200, ['data' => [['name' => $name, 'values' => [['value' => $name === 'post_impressions' ? 50 : 40]]]]]];
        }] + $this->graph;

        $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.state', 'partial')
            ->assertJsonPath('data.metrics.impressions.value', 50)
            ->assertJsonPath('data.metrics.reach.value', 40)
            ->assertJsonPath('data.metrics.clicks.reason', 'unsupported_metric');
    }

    public function test_an_absent_share_count_is_unavailable_not_zero(): void
    {
        $account = $this->tenant();
        $post = $this->organicPost($account, $page = $this->connection($account));
        $this->facebookPostGraph();
        $this->graph['/PG_111'] = [200, ['id' => 'PG_111', 'reactions' => ['summary' => ['total_count' => 0]], 'comments' => ['summary' => ['total_count' => 0]]]];

        $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.metrics.shares', ['value' => null, 'status' => 'unavailable', 'reason' => 'not_reported'])
            ->assertJsonPath('data.metrics.reactions', ['value' => 0, 'status' => 'available', 'reason' => null]);
    }

    public function test_before_any_fetch_the_snapshot_is_not_fetched_and_reading_never_calls_meta(): void
    {
        $account = $this->tenant();
        $post = $this->organicPost($account, $this->connection($account));

        $this->actingAs($this->user($account))->getJson("/api/social/organic-posts/{$post->id}/insights")
            ->assertOk()
            ->assertJsonPath('data.state', 'not_fetched')
            ->assertJsonPath('data.fetched_at', null)
            ->assertJsonPath('data.can_refresh', true)
            ->assertJsonPath('data.metrics.reach', ['value' => null, 'status' => 'not_fetched', 'reason' => null]);

        $this->actingAs($this->user($account))->getJson('/api/social/organic-posts/insights/summary')->assertOk();
        Http::assertNothingSent();
    }

    // ================================================================== ineligible posts

    public function test_posts_that_cannot_have_insights_say_why_and_never_call_meta(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $user = $this->user($account);
        $cases = [
            'failed' => ['status' => OrganicPost::STATUS_FAILED, 'external_post_id' => null, 'failure_code' => 'provider_rejected'],
            'outcome_unknown' => ['status' => OrganicPost::STATUS_FAILED, 'external_post_id' => null, 'failure_code' => 'outcome_unknown'],
            'cancelled' => ['status' => OrganicPost::STATUS_CANCELLED, 'external_post_id' => null],
            'not_published' => ['status' => OrganicPost::STATUS_SCHEDULED, 'external_post_id' => null, 'scheduled_at' => now()->addDay()],
            'missing_provider_post_id' => ['external_post_id' => null],
        ];

        foreach ($cases as $reason => $attributes) {
            $post = $this->organicPost($account, $page, $attributes);

            $this->actingAs($user)->getJson("/api/social/organic-posts/{$post->id}/insights")
                ->assertOk()->assertJsonPath('data.state', 'not_applicable')->assertJsonPath('data.not_applicable_reason', $reason)->assertJsonPath('data.can_refresh', false);
            $this->refresh($user, $post)->assertStatus(422)->assertJsonPath('error_code', 'INSIGHTS_NOT_AVAILABLE')->assertJsonPath('reason', $reason);
        }

        $linkedin = $this->organicPost($account, $page, ['provider' => 'linkedin', 'platform' => 'linkedin']);
        $this->refresh($user, $linkedin)->assertStatus(422)->assertJsonPath('reason', 'platform_unsupported');

        Http::assertNothingSent();
        $this->assertSame(0, OrganicPostInsight::count());
    }

    public function test_a_deleted_post_is_post_not_found_and_keeps_its_last_metrics(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $post = $this->organicPost($account, $this->connection($account));
        $this->facebookPostGraph();
        $this->refresh($user, $post)->assertOk();

        $this->travel(10)->minutes();
        $this->graph = ['/PG_111' => $this->graphError(100, 33)];
        $this->refresh($user, $post)->assertOk()
            ->assertJsonPath('data.state', 'post_not_found')
            ->assertJsonPath('data.metrics.reactions.value', 12);

        Queue::fake();
        $this->artisan('social:refresh-insights')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    // ================================================================== refresh / cache

    public function test_a_recent_snapshot_is_returned_without_calling_meta_again(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $post = $this->organicPost($account, $this->connection($account));
        $this->facebookPostGraph();

        $this->refresh($user, $post)->assertOk()->assertJsonPath('outcome', 'refreshed');
        $this->refresh($user, $post)->assertOk()->assertJsonPath('outcome', 'recently_refreshed')->assertJsonPath('data.metrics.reach.value', 300);
        $this->assertSame(2, $this->attempted);

        $this->travel(6)->minutes();
        $this->graph['/PG_111'][1]['reactions']['summary']['total_count'] = 20;
        $this->refresh($user, $post)->assertOk()->assertJsonPath('outcome', 'refreshed')->assertJsonPath('data.metrics.reactions.value', 20);
        $this->assertSame(4, $this->attempted);
    }

    public function test_a_refresh_already_in_flight_is_not_duplicated_and_a_dead_lease_is_taken_over(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->facebookPostGraph();
        OrganicPostInsight::create([
            'account_id' => $account->id, 'organic_post_id' => $post->id, 'social_account_id' => $page->id, 'provider' => 'meta',
            'platform' => 'facebook', 'provider_post_id' => 'PG_111', 'claim_token' => 'other-worker', 'claimed_at' => now(),
        ]);

        $this->refresh($user, $post)->assertOk()->assertJsonPath('outcome', 'in_progress')->assertJsonPath('data.refresh_in_progress', true);
        Http::assertNothingSent();

        $this->travel(3)->minutes();
        $this->refresh($user, $post)->assertOk()->assertJsonPath('outcome', 'refreshed')->assertJsonPath('data.state', 'ok');
        $this->assertNull(OrganicPostInsight::sole()->claim_token);
    }

    public function test_two_first_refreshes_racing_create_one_snapshot(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->facebookPostGraph();
        $raced = false;

        OrganicPostInsight::creating(function (OrganicPostInsight $model) use (&$raced) {
            if ($raced) {
                return;
            }
            $raced = true;
            OrganicPostInsight::withoutEvents(fn () => OrganicPostInsight::create($model->getAttributes()));
        });

        $result = app(PostInsightsService::class)->refresh($post);

        $this->assertSame('refreshed', $result['outcome']);
        $this->assertSame(1, OrganicPostInsight::count());
        $this->assertSame(OrganicPostInsight::STATE_OK, OrganicPostInsight::sole()->state);
    }

    // ================================================================== connection health & provider failures

    public function test_an_expired_connection_is_reconnect_required_without_calling_meta(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account, 'facebook_page', ['token_expires_at' => now()->subDay()]);
        $post = $this->organicPost($account, $page);

        $response = $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.state', 'reconnect_required')
            ->assertJsonPath('data.error_code', 'social_connection_expired')
            ->assertJsonPath('data.reconnect_path', '/social/accounts');

        Http::assertNothingSent();
        $this->assertStringContainsString('Reconnect it in Social Accounts', $response->json('data.error_message'));
        $this->assertSame('expired', $page->fresh()->connectionStatus());
    }

    public function test_a_connection_revoked_at_meta_is_persisted_and_reported(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->graph = ['*' => $this->graphError(190, 460)];

        $response = $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.state', 'reconnect_required')
            ->assertJsonPath('data.error_code', 'connection_revoked');

        $this->assertSame('revoked', $page->fresh()->connectionStatus());
        $this->assertStringNotContainsString('fbtrace', $response->getContent());
    }

    public function test_a_token_expiring_on_the_insights_edge_is_still_a_connection_problem(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->facebookPostGraph();
        $this->graph = ['/PG_111/insights' => $this->graphError(190, 463)] + $this->graph;

        $this->refresh($this->user($account), $post)->assertOk()->assertJsonPath('data.state', 'reconnect_required');
        $this->assertSame('expired', $page->fresh()->connectionStatus());
    }

    public function test_rate_limit_backs_off_without_touching_the_connection(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->graph = ['*' => $this->graphError(4)];

        $this->refresh($user, $post)->assertOk()->assertJsonPath('data.state', 'rate_limited')->assertJsonPath('data.error_code', 'rate_limited');
        $row = OrganicPostInsight::sole();
        $this->assertEqualsWithDelta(now()->addMinutes(5)->timestamp, $row->next_refresh_at->timestamp, 2);
        $this->assertSame('connected', $page->fresh()->connectionStatus());

        $calls = $this->attempted;
        $this->refresh($user, $post)->assertOk()->assertJsonPath('outcome', 'backing_off');
        $this->assertSame($calls, $this->attempted, 'no provider call during the back-off');

        $this->travel(6)->minutes();
        $this->graph = ['*' => [429, []]];
        $this->refresh($user, $post)->assertOk()->assertJsonPath('data.state', 'rate_limited');
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, OrganicPostInsight::sole()->next_refresh_at->timestamp, 2);
    }

    public function test_server_errors_and_network_failures_are_temporary_and_keep_the_last_metrics(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->facebookPostGraph();
        $this->refresh($user, $post)->assertOk();

        $this->travel(10)->minutes();
        $this->graph = ['*' => $this->graphError(2, 0, 503)];
        $this->refresh($user, $post)->assertOk()
            ->assertJsonPath('data.state', 'provider_error')
            ->assertJsonPath('data.error_code', 'transient')
            ->assertJsonPath('data.metrics.reach.value', 300);

        $this->travel(10)->minutes();
        $this->graph = ['*' => 'down'];
        $this->refresh($user, $post)->assertOk()->assertJsonPath('data.state', 'provider_error')->assertJsonPath('data.metrics.reactions.value', 12);

        $this->assertSame('connected', $page->fresh()->connectionStatus());
    }

    public function test_a_permanent_rejection_is_a_provider_error_with_a_safe_message(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $post = $this->organicPost($account, $page);
        $this->graph = ['*' => $this->graphError(1500)];

        $response = $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.state', 'provider_error')
            ->assertJsonPath('data.error_code', 'permanent');

        $this->assertStringNotContainsString('fbtrace', $response->getContent());
        $this->assertSame(1500, OrganicPostInsight::sole()->metadata['provider_error_code']);
        $this->assertSame('connected', $page->fresh()->connectionStatus());
    }

    public function test_a_connection_of_another_account_is_never_used(): void
    {
        $account = $this->tenant();
        $foreign = $this->connection($this->tenant());
        $post = $this->organicPost($account, $foreign);

        $this->refresh($this->user($account), $post)->assertOk()
            ->assertJsonPath('data.state', 'reconnect_required')
            ->assertJsonPath('data.error_code', 'connection_missing');
        Http::assertNothingSent();
    }

    // ================================================================== worker

    public function test_the_worker_refreshes_only_due_posts_and_a_duplicate_job_does_nothing(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $due = $this->organicPost($account, $page);
        $this->organicPost($account, $page, ['external_post_id' => 'OLD_1', 'published_at' => now()->subDays(40)]);
        $this->organicPost($account, $page, ['status' => OrganicPost::STATUS_FAILED, 'external_post_id' => null]);
        $this->organicPost($account, $page, ['provider' => 'linkedin', 'platform' => 'linkedin', 'external_post_id' => 'urn:li:1']);
        $fresh = $this->organicPost($account, $page, ['external_post_id' => 'FRESH_1']);
        OrganicPostInsight::create([
            'account_id' => $account->id, 'organic_post_id' => $fresh->id, 'provider' => 'meta', 'platform' => 'facebook',
            'provider_post_id' => 'FRESH_1', 'state' => 'ok', 'metrics_fetched_at' => now(), 'next_refresh_at' => now()->addHours(5),
        ]);
        $this->facebookPostGraph();

        Queue::fake();
        $this->artisan('social:refresh-insights')->assertSuccessful();

        Queue::assertPushed(RefreshPostInsightsJob::class, 1);
        Queue::assertPushed(RefreshPostInsightsJob::class, fn ($job) => $job->organicPostId === $due->id && $job->queue === 'social' && $job->connection === 'database');
        Http::assertNothingSent();

        $job = Queue::pushed(RefreshPostInsightsJob::class)->sole();
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertSame(2, $this->attempted, 'the second run finds the snapshot fresh');
        $this->assertSame('ok', OrganicPostInsight::where('organic_post_id', $due->id)->value('state'));
    }

    public function test_the_worker_retries_after_the_back_off(): void
    {
        $account = $this->tenant();
        $post = $this->organicPost($account, $this->connection($account));
        $this->graph = ['*' => $this->graphError(2, 0, 500)];
        app(PostInsightsService::class)->refresh($post, explicit: false);

        Queue::fake();
        $this->artisan('social:refresh-insights');
        Queue::assertNothingPushed();

        $this->travel(6)->minutes();
        $this->artisan('social:refresh-insights');
        Queue::assertPushed(RefreshPostInsightsJob::class, 1);
    }

    // ================================================================== authorization

    public function test_foreign_posts_are_404_and_never_fetched(): void
    {
        $owner = $this->tenant();
        $post = $this->organicPost($owner, $this->connection($owner));
        $intruder = $this->user($this->tenant());

        $this->actingAs($intruder)->getJson("/api/social/organic-posts/{$post->id}/insights")->assertNotFound();
        $this->refresh($intruder, $post)->assertNotFound();
        $this->actingAs($intruder)->getJson('/api/social/organic-posts/insights/summary')->assertOk()->assertJsonCount(0, 'data.posts');

        Http::assertNothingSent();
        $this->assertSame(0, OrganicPostInsight::count());
    }

    public function test_route_guards_permission_capability_and_module(): void
    {
        $account = $this->tenant();
        $post = $this->organicPost($account, $this->connection($account));
        $plain = $this->user($account, 'user'); // no view-social-analytics

        $this->actingAs($plain)->getJson("/api/social/organic-posts/{$post->id}/insights")->assertForbidden();
        $this->refresh($plain, $post)->assertForbidden();
        $this->actingAs($plain)->getJson('/api/social/organic-posts/insights/summary')->assertForbidden();

        $noModule = $this->tenant();
        $noModule->forceFill(['allowed_modules' => ['campaigns']])->save();
        $this->actingAs($this->user($noModule))->getJson('/api/social/organic-posts/insights/summary')->assertForbidden();

        $this->revokeSocialCapability($account);
        $this->refresh($this->user($account), $post)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_super_admin_must_select_an_entitled_target(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenant();
        $post = $this->organicPost($target, $this->connection($target));
        $this->facebookPostGraph();

        $this->actingAs($admin)->getJson('/api/social/organic-posts/insights/summary')->assertStatus(422);

        $other = $this->tenant();
        $this->actingAs($admin)->getJson("/api/social/organic-posts/{$post->id}/insights?account_id={$other->id}")->assertNotFound();

        $target->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($admin)->postJson("/api/social/organic-posts/{$post->id}/insights/refresh?account_id={$target->id}")
            ->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
        Http::assertNothingSent();

        // Owner decision (2026-09-30): the client's plan does not bind a Super Admin.
        $unentitled = $this->tenant();
        $unentitledPost = $this->organicPost($unentitled, $this->connection($unentitled));
        $this->revokeSocialCapability($unentitled);
        $this->actingAs($admin)->postJson("/api/social/organic-posts/{$unentitledPost->id}/insights/refresh?account_id={$unentitled->id}")->assertOk();

        $entitled = $this->tenant();
        $entitledPost = $this->organicPost($entitled, $this->connection($entitled));
        $this->actingAs($admin)->postJson("/api/social/organic-posts/{$entitledPost->id}/insights/refresh?account_id={$entitled->id}")
            ->assertOk()->assertJsonPath('data.state', 'ok');
    }

    public function test_a_suspended_target_is_refused(): void
    {
        $target = $this->tenant();
        $post = $this->organicPost($target, $this->connection($target));
        $target->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->superAdmin())->getJson("/api/social/organic-posts/{$post->id}/insights?account_id={$target->id}")
            ->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
    }

    // ================================================================== summary

    public function test_the_summary_totals_only_reported_metrics(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account);
        $a = $this->organicPost($account, $page);
        $b = $this->organicPost($account, $page, ['external_post_id' => 'PG_222']);
        $this->organicPost($account, $page, ['status' => OrganicPost::STATUS_FAILED, 'external_post_id' => null]);
        foreach ([[$a, 10, 100], [$b, 5, null]] as [$post, $reactions, $reach]) {
            OrganicPostInsight::create([
                'account_id' => $account->id, 'organic_post_id' => $post->id, 'provider' => 'meta', 'platform' => 'facebook',
                'provider_post_id' => $post->external_post_id, 'state' => 'ok', 'metrics_fetched_at' => now(),
                'reactions' => $reactions, 'reach' => $reach,
            ]);
        }

        $this->actingAs($this->user($account))->getJson('/api/social/organic-posts/insights/summary')->assertOk()
            ->assertJsonPath('data.posts_published', 2)
            ->assertJsonPath('data.posts_with_insights', 2)
            ->assertJsonPath('data.totals.reactions', ['value' => 15, 'posts' => 2])
            ->assertJsonPath('data.totals.reach', ['value' => 100, 'posts' => 1])
            ->assertJsonPath('data.totals.saves', ['value' => null, 'posts' => 0])
            ->assertJsonCount(3, 'data.posts');
    }

    // ================================================================== guardrails

    public function test_insights_code_outside_the_provider_makes_no_provider_calls(): void
    {
        foreach ([
            'Services/Social/Insights/PostInsightsService.php', 'Http/Controllers/Api/OrganicPostInsightsController.php',
            'Jobs/RefreshPostInsightsJob.php', 'Console/Commands/RefreshSocialPostInsights.php', 'Models/OrganicPostInsight.php',
        ] as $relative) {
            $source = file_get_contents(app_path($relative));
            $this->assertStringNotContainsString('graph.facebook.com', $source, $relative);
            $this->assertStringNotContainsString('Facades\\Http', $source, $relative);
            $this->assertStringNotContainsString('video_insights', $source, $relative);
        }

        $this->assertStringContainsString('/video_insights', file_get_contents(app_path('Services/Social/Publishing/MetaPublisher.php')));
    }
}
