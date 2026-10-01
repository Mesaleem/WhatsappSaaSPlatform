<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\CommentAutomationRule;
use App\Models\Invoice;
use App\Models\OrganicPost;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 9 Task 6 — Social authorization consistency:
 *  - every Social controller resolves its target with requireTargetAccount():
 *    a Super Admin with no selected client gets 422 on EVERY environment
 *    (requireAccount()'s APP_ENV=local first-account fallback is gone from
 *    Social), and nothing is read from or written to the first client;
 *  - target.account: a Super Admin acting on a selected client on the
 *    pre-Phase-9 Social routes is checked like that client's own users
 *    (active, route module, subscription for writes);
 *  - tenants stay isolated and unchanged; view-only analytics users read
 *    analytics but reach no management endpoint.
 */
class SocialAuthorizationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /** Target-account Social endpoints (method, uri). */
    private const TARGET_ENDPOINTS = [
        ['GET', '/api/social/providers'],
        ['GET', '/api/social/accounts'],
        ['GET', '/api/social/oauth/meta/redirect'],
        ['GET', '/api/social/organic-posts'],
        ['POST', '/api/social/organic-posts'],
        ['GET', '/api/social/organic-posts/insights/summary'],
        ['GET', '/api/social/analytics/dashboard'],
        ['GET', '/api/social/analytics/top-posts'],
        ['GET', '/api/social/ads'],
        ['POST', '/api/social/ads/launch'],
        ['GET', '/api/social/ads/attribution'],
        ['GET', '/api/social/ads/attribution/summary'],
        ['GET', '/api/social/ads/dashboard'],
        ['GET', '/api/social/comment-rules'],
        ['POST', '/api/social/comment-rules'],
        ['GET', '/api/social/inbox/threads'],
        ['GET', '/api/social/leads'],
        ['GET', '/api/social/reports/summary'],
        ['POST', '/api/social/media/upload'],
        ['POST', '/api/social/ai/generate'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake();
    }

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

    private function viewer(Account $account): User
    {
        Role::firstOrCreate(['name' => 'analytics_viewer', 'guard_name' => 'web'])->syncPermissions(['view-social-analytics']);

        return $this->user($account, 'analytics_viewer');
    }

    // ================================================================== account resolution

    public function test_super_admin_without_a_client_gets_422_on_every_social_endpoint_even_on_local(): void
    {
        $first = $this->tenant(); // the account requireAccount() used to fall back to
        $admin = $this->superAdmin();
        $this->app['env'] = 'local';

        foreach (self::TARGET_ENDPOINTS as [$method, $uri]) {
            $response = $this->actingAs($admin)->json($method, $uri, ['platform' => 'facebook', 'caption' => 'x', 'keyword' => 'price']);
            $this->assertSame(422, $response->status(), "{$method} {$uri} must require an explicit client, got {$response->status()}");
        }

        $this->app['env'] = 'testing';
        $this->assertSame(0, OrganicPost::where('account_id', $first->id)->count());
        $this->assertSame(0, CommentAutomationRule::where('account_id', $first->id)->count());
        Http::assertNothingSent();
    }

    public function test_no_social_controller_uses_the_fallback_resolver(): void
    {
        foreach ([
            'AdAttributionController', 'AdCampaignController', 'AdsDashboardController', 'AICopywriterController', 'CommentAutomationRuleController', 'LeadController', 'OrganicPostController',
            'OrganicPostInsightsController', 'SocialAnalyticsController', 'SocialAuthController', 'SocialInboxController',
            'SocialMediaController', 'SocialReportController',
        ] as $controller) {
            $source = file_get_contents(app_path("Http/Controllers/Api/{$controller}.php"));
            $this->assertStringNotContainsString('$this->requireAccount(', $source, $controller);
        }
    }

    public function test_a_tenant_cannot_switch_to_another_account_with_account_id(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        CommentAutomationRule::create(['account_id' => $theirs->id, 'keyword' => 'secret', 'public_reply_template' => 'x', 'private_dm_template' => 'y', 'is_active' => true]);
        $user = $this->user($mine);

        $this->actingAs($user)->getJson("/api/social/comment-rules?account_id={$theirs->id}")->assertOk()->assertJsonMissing(['keyword' => 'secret']);
        $this->actingAs($user)->postJson("/api/social/comment-rules?account_id={$theirs->id}", [
            'keyword' => 'mine', 'public_reply_template' => 'a', 'private_dm_template' => 'b',
        ])->assertSuccessful();

        $this->assertSame($mine->id, CommentAutomationRule::where('keyword', 'mine')->value('account_id'));
        $this->actingAs($user)->getJson("/api/social/analytics/dashboard?account_id={$theirs->id}")->assertOk()->assertJsonPath('data.summary.published', 0);
    }

    // ================================================================== target.account on pre-Phase-9 routes

    public function test_super_admin_cannot_act_for_a_suspended_client(): void
    {
        $target = $this->tenant();
        $target->forceFill(['status' => 'suspended'])->save();
        $admin = $this->superAdmin();

        foreach (['/api/social/ads', '/api/social/comment-rules', '/api/social/inbox/threads', '/api/social/leads', '/api/social/reports/summary'] as $uri) {
            $this->actingAs($admin)->getJson("{$uri}?account_id={$target->id}")->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
        }
        $this->actingAs($admin)->postJson("/api/social/ads/launch?account_id={$target->id}", [])->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
        $this->actingAs($admin)->postJson("/api/social/ai/generate?account_id={$target->id}", [])->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
    }

    public function test_an_expired_client_is_read_only_for_its_users_but_not_for_super_admin(): void
    {
        $target = $this->tenant();
        Subscription::where('account_id', $target->id)->update(['expires_at' => now()->subDay()]);
        $admin = $this->superAdmin();
        $body = ['keyword' => 'price', 'public_reply_template' => 'a', 'private_dm_template' => 'b'];

        $this->actingAs($this->user($target))->getJson('/api/social/comment-rules')->assertOk();
        $this->actingAs($this->user($target))->postJson('/api/social/comment-rules', $body)->assertForbidden()->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');

        // Owner decision (2026-09-30): a lapsed subscription does not stop a Super Admin (suspension and module switches still do).
        $this->actingAs($admin)->postJson("/api/social/comment-rules?account_id={$target->id}", $body)->assertSuccessful();

        $this->assertSame($target->id, CommentAutomationRule::sole()->account_id);
    }

    public function test_a_module_switched_off_for_the_client_blocks_the_super_admin_too(): void
    {
        $target = $this->tenant();
        $target->forceFill(['allowed_modules' => ['campaigns', 'meta_ads']])->save();
        // Phase 10 Task 2 — the launcher also requires the `ads` capability (Growth does not bundle it).
        AccountEntitlement::create(['account_id' => $target->id, 'capability_id' => Capability::where('slug', 'ads')->value('id'), 'source' => 'manual_grant']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->getJson("/api/social/inbox/threads?account_id={$target->id}")->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');
        $this->actingAs($admin)->getJson("/api/social/comment-rules?account_id={$target->id}")->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');
        $this->actingAs($admin)->getJson("/api/social/ads?account_id={$target->id}")->assertOk();
    }

    public function test_super_admin_with_an_eligible_client_works_and_writes_to_that_client(): void
    {
        $target = $this->tenant();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson("/api/social/comment-rules?account_id={$target->id}", [
            'keyword' => 'price', 'public_reply_template' => 'a', 'private_dm_template' => 'b',
        ])->assertSuccessful();

        $this->assertSame($target->id, CommentAutomationRule::sole()->account_id);
    }

    public function test_phase_9_routes_still_apply_the_full_target_gate(): void
    {
        $admin = $this->superAdmin();
        $noCapability = $this->tenant();
        AccountEntitlement::where('account_id', $noCapability->id)->where('capability_id', Capability::where('slug', 'social')->value('id'))->delete();
        $expired = $this->tenant();
        Subscription::where('account_id', $expired->id)->update(['expires_at' => now()->subDay()]);
        $noModule = $this->tenant();
        $noModule->forceFill(['allowed_modules' => ['campaigns']])->save();

        foreach (['/api/social/analytics/dashboard', '/api/social/organic-posts/insights/summary', '/api/social/organic-posts'] as $uri) {
            // Owner decision (2026-09-30): the client's plan does not bind a Super Admin.
            $this->actingAs($admin)->getJson("{$uri}?account_id={$noCapability->id}")->assertOk();
            $this->actingAs($admin)->getJson("{$uri}?account_id={$noModule->id}")->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');
        }
        $this->actingAs($admin)->getJson("/api/social/analytics/dashboard?account_id={$expired->id}")->assertOk();
    }

    public function test_social_accounts_and_organic_posts_hold_the_super_admin_to_suspension_only(): void
    {
        $admin = $this->superAdmin();
        $expired = $this->tenant();
        Subscription::where('account_id', $expired->id)->update(['expires_at' => now()->subDay()]);
        $connection = \App\Models\SocialAccount::create(['account_id' => $expired->id, 'provider' => 'meta', 'asset_type' => 'facebook_page',
            'provider_id' => 'PG_E', 'name' => 'Page', 'access_token' => 'tok', 'health_status' => 'connected']);
        $post = OrganicPost::create(['account_id' => $expired->id, 'social_account_id' => $connection->id, 'provider' => 'meta', 'platform' => 'facebook',
            'caption' => 'x', 'status' => OrganicPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay(), 'attempts' => 0]);

        // Owner decision (2026-09-30): a lapsed subscription does not stop a Super Admin — writes work.
        $this->actingAs($admin)->getJson("/api/social/accounts?account_id={$expired->id}")->assertOk();
        $this->actingAs($admin)->getJson("/api/social/organic-posts?account_id={$expired->id}")->assertOk();
        $this->actingAs($admin)->postJson("/api/social/organic-posts/{$post->id}/cancel?account_id={$expired->id}")->assertOk();
        $this->assertSame(OrganicPost::STATUS_CANCELLED, $post->fresh()->status);
        // The client's own user stays read-only.
        $this->actingAs($this->user($expired))->deleteJson("/api/social/accounts/{$connection->id}")->assertForbidden()->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        $this->assertNotNull($connection->fresh());

        // Suspended: nothing at all. Without the social capability: allowed for a Super Admin (2026-09-30).
        $suspended = $this->tenant();
        $suspended->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($admin)->getJson("/api/social/accounts?account_id={$suspended->id}")->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');

        $unentitled = $this->tenant();
        AccountEntitlement::where('account_id', $unentitled->id)->where('capability_id', Capability::where('slug', 'social')->value('id'))->delete();
        $this->actingAs($admin)->getJson("/api/social/accounts?account_id={$unentitled->id}")->assertOk();
    }

    // ================================================================== view-only analytics

    public function test_a_view_only_analytics_user_reads_analytics_and_reaches_no_management_endpoint(): void
    {
        $account = $this->tenant();
        $viewer = $this->viewer($account);

        $this->actingAs($viewer)->getJson('/api/social/analytics/dashboard')->assertOk();
        $this->actingAs($viewer)->getJson('/api/social/analytics/top-posts')->assertOk();
        $this->actingAs($viewer)->getJson('/api/social/organic-posts/insights/summary')->assertOk();

        foreach ([
            ['GET', '/api/social/accounts'], ['GET', '/api/social/providers'], ['GET', '/api/social/organic-posts'],
            ['POST', '/api/social/organic-posts'], ['POST', '/api/social/organic-posts/1/cancel'], ['POST', '/api/social/organic-posts/1/retry'],
            ['GET', '/api/social/ads'], ['GET', '/api/social/comment-rules'], ['GET', '/api/social/inbox/threads'], ['POST', '/api/social/media/upload'],
        ] as [$method, $uri]) {
            $this->actingAs($viewer)->json($method, $uri)->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_the_task_4_refresh_contract_is_unchanged_for_view_only_users(): void
    {
        $account = $this->tenant();
        $post = OrganicPost::create([
            'account_id' => $account->id, 'provider' => 'meta', 'platform' => 'facebook', 'caption' => 'x',
            'status' => OrganicPost::STATUS_PUBLISHED, 'external_post_id' => 'PG_1', 'published_at' => now()->subDay(), 'attempts' => 1,
        ]);

        // view-social-analytics may refresh (the connection is missing here, so nothing is sent).
        $this->actingAs($this->viewer($account))->postJson("/api/social/organic-posts/{$post->id}/insights/refresh")
            ->assertOk()->assertJsonPath('data.state', 'reconnect_required');
        Http::assertNothingSent();
    }
}
