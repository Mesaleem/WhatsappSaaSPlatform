<?php

namespace Tests\Feature;

use App\Jobs\CheckSocialConnectionJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\AdCampaign;
use App\Models\Invoice;
use App\Models\OrganicPost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Services\SocialAuth\ConnectionCheck;
use App\Services\SocialAuth\MetaOAuthProvider;
use App\Services\SocialAuth\SocialConnectionService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 9 Task 2 — social connection health & token lifecycle:
 * the scheduled check (`social:check-connections` → CheckSocialConnectionJob
 * → SocialConnectionService → provider driver), its claim/suppression rules,
 * the status transitions, legacy/expired tokens, and how an expired/revoked
 * connection reported by Meta propagates into Ads, Organic publishing and
 * the Social Inbox (persisted + a safe 409 with the reconnect path).
 */
class SocialConnectionHealthTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAA-secret-token-never-logged';

    /** @var array{0: int, 1: array<string, mixed>}|'down' what Graph answers for any call */
    private array|string $graph = [200, ['id' => '1']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config(['social.connection_checks.interval_minutes' => 60]);

        Http::fake(function (HttpRequest $request) {
            if ($this->graph === 'down') {
                throw new ConnectionException('Connection timed out');
            }

            return Http::response($this->graph[1], $this->graph[0]);
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(): Account
    {
        $account = Account::factory()->create(['allow_facebook' => true, 'allow_instagram' => true]);
        $p = PlanCatalog::find('growth');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth',
            'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0,
            'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
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

    private function connection(Account $account, array $attributes = []): SocialAccount
    {
        return SocialAccount::create($attributes + [
            'account_id' => $account->id,
            'provider' => 'meta',
            'asset_type' => 'meta_ad_account',
            'provider_id' => 'act_'.random_int(1000, 9999),
            'name' => 'Acme Ads',
            'access_token' => self::TOKEN,
            'health_status' => SocialAccount::HEALTH_CONNECTED,
            'status_checked_at' => null,
        ]);
    }

    private function graphError(int $code, int $subcode = 0, int $http = 400): array
    {
        return [$http, ['error' => ['message' => 'Raw Meta detail fbtrace_id=XYZ', 'type' => 'OAuthException', 'code' => $code, 'error_subcode' => $subcode ?: null]]];
    }

    private function runJob(SocialAccount $socialAccount): void
    {
        (new CheckSocialConnectionJob($socialAccount->id))->handle(app(SocialConnectionService::class));
    }

    // ------------------------------------------------------------------ scheduler / claims

    public function test_the_command_queues_one_check_per_due_connected_connection(): void
    {
        Queue::fake();
        $account = $this->tenant();
        $due = $this->connection($account);
        $recentlyConfirmed = $this->connection($account, ['status_checked_at' => now()->subMinutes(10)]);
        $expired = $this->connection($account, ['health_status' => SocialAccount::HEALTH_TOKEN_EXPIRED]);
        $revoked = $this->connection($account, ['health_status' => SocialAccount::HEALTH_REAUTH_REQUIRED]);
        $otherProvider = $this->connection($account, ['provider' => 'linkedin', 'asset_type' => 'linkedin_page']);

        $this->artisan('social:check-connections')->assertSuccessful();

        Queue::assertPushed(CheckSocialConnectionJob::class, 1);
        Queue::assertPushed(CheckSocialConnectionJob::class, fn ($job) => $job->socialAccountId === $due->id && $job->queue === 'social' && $job->connection === 'database');
        $this->assertNotNull($due->fresh()->health_check_attempted_at);
        foreach ([$recentlyConfirmed, $expired, $revoked, $otherProvider] as $skipped) {
            $this->assertNull($skipped->fresh()->health_check_attempted_at);
        }
        Http::assertNothingSent();
    }

    public function test_running_more_often_than_the_interval_does_not_recheck(): void
    {
        Queue::fake();
        $this->connection($this->tenant());

        $this->artisan('social:check-connections')->assertSuccessful();
        $this->artisan('social:check-connections')->assertSuccessful();
        $this->travel(30)->minutes();
        $this->artisan('social:check-connections')->assertSuccessful();

        Queue::assertPushed(CheckSocialConnectionJob::class, 1);

        $this->travel(31)->minutes();
        $this->artisan('social:check-connections')->assertSuccessful();
        Queue::assertPushed(CheckSocialConnectionJob::class, 2);
    }

    public function test_concurrent_runs_never_claim_the_same_connection(): void
    {
        $account = $this->tenant();
        $ids = collect(range(1, 5))->map(fn () => $this->connection($account)->id)->all();
        $service = app(SocialConnectionService::class);

        $first = $service->claimDue(3);
        $second = $service->claimDue(10);
        $third = $service->claimDue(10);

        $this->assertCount(3, $first);
        $this->assertCount(2, $second);
        $this->assertSame([], $third);
        $this->assertSame([], array_intersect($first, $second));
        $this->assertEqualsCanonicalizing($ids, array_merge($first, $second));
    }

    public function test_a_row_claimed_by_another_server_between_scan_and_claim_is_not_taken(): void
    {
        $socialAccount = $this->connection($this->tenant());
        // Another server's claim lands first (the conditional UPDATE in claimDue() is what decides).
        SocialAccount::whereKey($socialAccount->id)->update(['health_check_attempted_at' => now()]);

        $this->assertSame([], app(SocialConnectionService::class)->claimDue());
    }

    public function test_the_interval_is_configurable(): void
    {
        config(['social.connection_checks.interval_minutes' => 15]);
        $socialAccount = $this->connection($this->tenant(), ['status_checked_at' => now()->subMinutes(20)]);

        $this->assertSame([$socialAccount->id], app(SocialConnectionService::class)->claimDue());
        $this->assertSame(15, app(SocialConnectionService::class)->intervalMinutes());

        config(['social.connection_checks.interval_minutes' => 1]);
        $this->assertSame(5, app(SocialConnectionService::class)->intervalMinutes(), 'floor of 5 minutes');
    }

    // ------------------------------------------------------------------ transitions

    public function test_a_healthy_connection_stays_connected_and_records_the_check(): void
    {
        $socialAccount = $this->connection($this->tenant(), ['status_reason' => 'old reason']);

        $this->runJob($socialAccount);

        $fresh = $socialAccount->fresh();
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $fresh->health_status);
        $this->assertNull($fresh->status_reason);
        $this->assertNotNull($fresh->status_checked_at);
        $this->assertNotNull($fresh->health_check_attempted_at);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/me'));
    }

    public function test_an_expired_token_reported_by_meta_is_persisted_as_expired(): void
    {
        $socialAccount = $this->connection($this->tenant());
        $this->graph = $this->graphError(190, 463);

        $this->runJob($socialAccount);

        $fresh = $socialAccount->fresh();
        $this->assertSame(SocialAccount::HEALTH_TOKEN_EXPIRED, $fresh->health_status);
        $this->assertSame('expired', $fresh->connectionStatus());
        $this->assertStringContainsString('expired', $fresh->status_reason);
        $this->assertStringNotContainsString('fbtrace', $fresh->status_reason);
        $this->assertNotNull($fresh->status_checked_at);
    }

    public function test_a_revoked_grant_is_persisted_as_revoked(): void
    {
        $socialAccount = $this->connection($this->tenant());
        $this->graph = $this->graphError(190);

        $this->runJob($socialAccount);

        $this->assertSame(SocialAccount::HEALTH_REAUTH_REQUIRED, $socialAccount->fresh()->health_status);
        $this->assertSame('revoked', $socialAccount->fresh()->connectionStatus());

        // A removed permission is also revoked.
        $other = $this->connection($this->tenant());
        $this->graph = $this->graphError(200);
        $this->runJob($other);
        $this->assertSame('revoked', $other->fresh()->connectionStatus());
    }

    public function test_a_network_failure_never_marks_the_connection_revoked_and_is_not_retried_aggressively(): void
    {
        Queue::fake();
        $socialAccount = $this->connection($this->tenant(), ['status_checked_at' => now()->subHours(3)]);
        $confirmedAt = $socialAccount->status_checked_at;
        $this->graph = 'down';

        $this->artisan('social:check-connections');
        $this->runJob($socialAccount);

        $fresh = $socialAccount->fresh();
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $fresh->health_status);
        $this->assertTrue($fresh->status_checked_at->equalTo($confirmedAt), 'last confirmed status unchanged');
        $this->assertNotNull($fresh->health_check_attempted_at);

        // The failed attempt counts: the next scheduler ticks within the interval do not claim it again.
        $this->artisan('social:check-connections');
        $this->travel(59)->minutes();
        $this->artisan('social:check-connections');
        Queue::assertPushed(CheckSocialConnectionJob::class, 1);
    }

    public function test_provider_outages_and_rate_limits_do_not_change_the_state(): void
    {
        $account = $this->tenant();

        foreach ([$this->graphError(4, 0, 400), $this->graphError(2, 0, 503), [500, []]] as $answer) {
            $socialAccount = $this->connection($account);
            $this->graph = $answer;
            $this->runJob($socialAccount);
            $this->assertSame(SocialAccount::HEALTH_CONNECTED, $socialAccount->fresh()->health_status);
            $this->assertNull($socialAccount->fresh()->status_checked_at);
        }
    }

    public function test_a_token_known_to_be_expired_is_marked_expired_without_calling_meta(): void
    {
        $socialAccount = $this->connection($this->tenant(), ['token_expires_at' => now()->subHour()]);

        $this->runJob($socialAccount);

        $fresh = $socialAccount->fresh();
        $this->assertSame(SocialAccount::HEALTH_TOKEN_EXPIRED, $fresh->health_status);
        $this->assertStringContainsString('expired on', $fresh->status_reason);
        Http::assertNothingSent();
    }

    public function test_legacy_connections_are_handled_by_their_token_state(): void
    {
        Queue::fake();
        $account = $this->tenant();
        // Before Task 1: short-lived user token stored with its ~2 h expiry, never checked.
        $legacyShortLived = $this->connection($account, ['token_expires_at' => now()->subDays(40), 'connected_by_user_id' => null]);
        // Before Task 1: no expiry recorded (e.g. a Page token), never checked.
        $legacyNoExpiry = $this->connection($account, ['token_expires_at' => null, 'connected_by_user_id' => null]);

        $this->assertSame('expired', $legacyShortLived->connectionStatus(), 'shown as Expired already, without a check');

        $this->artisan('social:check-connections');
        Queue::assertPushed(CheckSocialConnectionJob::class, 2);

        $this->runJob($legacyShortLived);
        $this->runJob($legacyNoExpiry);

        $this->assertSame(SocialAccount::HEALTH_TOKEN_EXPIRED, $legacyShortLived->fresh()->health_status);
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $legacyNoExpiry->fresh()->health_status, 'still valid → not disconnected');
        Http::assertSentCount(1);
        $this->assertSame(2, SocialAccount::count(), 'nothing deleted');
    }

    public function test_a_result_for_replaced_or_deleted_credentials_is_dropped(): void
    {
        $account = $this->tenant();
        $socialAccount = $this->connection($account);
        $stale = SocialAccount::find($socialAccount->id);

        // The user reconnects while the scheduled check of the old token is in flight.
        $socialAccount->forceFill(['access_token' => 'EAA-new-token', 'health_status' => SocialAccount::HEALTH_CONNECTED])->save();
        app(SocialConnectionService::class)->record($stale, ConnectionCheck::revoked('old token revoked'), 'scheduled');
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $socialAccount->fresh()->health_status);

        // Locally disconnected: the job finds nothing to check.
        $gone = $this->connection($account);
        $id = $gone->id;
        $gone->delete();
        (new CheckSocialConnectionJob($id))->handle(app(SocialConnectionService::class));
        Http::assertNothingSent();
    }

    public function test_tokens_are_never_logged(): void
    {
        Log::spy();
        $socialAccount = $this->connection($this->tenant());
        $this->graph = $this->graphError(190, 463);

        $this->runJob($socialAccount);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) use ($socialAccount) {
            $flat = json_encode($context);

            return $message === 'Social connection checked.' && $context !== []
                && $context['social_account_id'] === $socialAccount->id
                && $context['account_id'] === $socialAccount->account_id
                && $context['previous_status'] === 'connected'
                && $context['new_status'] === 'expired'
                && ! str_contains($flat, self::TOKEN)
                && ! str_contains($flat, 'fbtrace');
        });
    }

    // ------------------------------------------------------------------ manual check + isolation + abstraction

    public function test_manual_check_uses_the_same_service_and_is_tenant_isolated(): void
    {
        $account = $this->tenant();
        $other = $this->tenant();
        $mine = $this->connection($account);
        $theirs = $this->connection($other);
        $this->graph = $this->graphError(190);

        $this->actingAs($this->user($account))->postJson("/api/social/accounts/{$theirs->id}/check")->assertNotFound();
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $theirs->fresh()->health_status);

        $this->actingAs($this->user($account))->postJson("/api/social/accounts/{$mine->id}/check")
            ->assertOk()
            ->assertJsonPath('data.connection_status', 'revoked')
            ->assertJsonMissingPath('data.access_token');
        $this->assertNotNull($mine->fresh()->health_check_attempted_at);
    }

    public function test_the_service_only_talks_to_the_provider_contract(): void
    {
        $fake = new class extends MetaOAuthProvider
        {
            public int $checks = 0;

            public function checkConnection(SocialAccount $socialAccount): ConnectionCheck
            {
                $this->checks++;

                return ConnectionCheck::revoked('fake driver says revoked');
            }
        };
        $this->app->instance(MetaOAuthProvider::class, $fake);
        $socialAccount = $this->connection($this->tenant());

        $this->runJob($socialAccount);

        $this->assertSame(1, $fake->checks);
        $this->assertSame('fake driver says revoked', $socialAccount->fresh()->status_reason);
        Http::assertNothingSent();

        foreach (['SocialAuth/SocialConnectionService.php', '../Jobs/CheckSocialConnectionJob.php', '../Console/Commands/CheckSocialConnections.php'] as $file) {
            $source = file_get_contents(app_path('Services/'.$file));
            $this->assertStringNotContainsString('graph.facebook.com', $source);
            $this->assertStringNotContainsString('190', $source);
            $this->assertStringNotContainsString('MetaOAuthProvider', $source);
        }
    }

    // ------------------------------------------------------------------ Ads / Organic / Inbox propagation

    /**
     * Phase 10 Task 2 — the launcher requires the `ads` capability (Growth does
     * not bundle it) and checks for a Facebook Page before any Meta write.
     */
    private function adsTenant(bool $withPage = true): Account
    {
        $account = $this->tenant();
        AccountEntitlement::create(['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'ads')->value('id'), 'source' => 'manual_grant']);

        if ($withPage) {
            $this->connection($account, ['asset_type' => 'facebook_page', 'provider_id' => 'page-'.random_int(1000, 9999), 'name' => 'Acme Page']);
        }

        return $account;
    }

    private function launchPayload(): array
    {
        return [
            'campaign_name' => 'Spring', 'objective' => 'TRAFFIC', 'daily_budget' => 10,
            'targeting_specs' => ['countries' => ['IN'], 'age_min' => 18, 'age_max' => 40],
            'creative' => ['image_url' => null, 'headline' => 'Hi', 'primary_text' => 'Buy'],
        ];
    }

    public function test_ads_launch_with_an_expired_connection_is_refused_before_calling_meta(): void
    {
        $account = $this->adsTenant();
        $adAccount = $this->connection($account, ['health_status' => SocialAccount::HEALTH_TOKEN_EXPIRED, 'status_reason' => 'Meta reports that the access token has expired.']);

        $this->actingAs($this->user($account))->postJson('/api/social/ads/launch', $this->launchPayload())
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'SOCIAL_CONNECTION_EXPIRED')
            ->assertJsonPath('connection.social_account_id', $adAccount->id)
            ->assertJsonPath('reconnect_path', '/social/accounts');

        Http::assertNothingSent();
        $this->assertSame(0, AdCampaign::count());
    }

    public function test_ads_launch_that_meta_rejects_for_a_revoked_token_persists_the_state(): void
    {
        $account = $this->adsTenant();
        $adAccount = $this->connection($account);
        $this->graph = $this->graphError(190);

        $response = $this->actingAs($this->user($account))->postJson('/api/social/ads/launch', $this->launchPayload())
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'SOCIAL_CONNECTION_REVOKED');

        $this->assertStringNotContainsString('fbtrace', $response->getContent());
        $this->assertSame('revoked', $adAccount->fresh()->connectionStatus());
        $this->assertNotNull($adAccount->fresh()->status_checked_at);

        // The connection is no longer treated as healthy: a retry does not call Meta.
        Http::fake();
        $this->actingAs($this->user($account))->postJson('/api/social/ads/launch', $this->launchPayload())->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_an_ordinary_meta_rejection_keeps_the_previous_behaviour(): void
    {
        $account = $this->adsTenant();
        $adAccount = $this->connection($account);
        $this->graph = $this->graphError(100);

        $this->actingAs($this->user($account))->postJson('/api/social/ads/launch', $this->launchPayload())
            ->assertStatus(422)
            ->assertJsonMissingPath('error_code');

        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $adAccount->fresh()->health_status);
    }

    public function test_pausing_a_campaign_on_an_expired_connection_returns_the_reconnect_error(): void
    {
        $account = $this->adsTenant();
        $adAccount = $this->connection($account);
        $campaign = AdCampaign::create([
            'account_id' => $account->id, 'social_account_id' => $adAccount->id, 'meta_campaign_id' => 'c1',
            'name' => 'C', 'objective' => 'TRAFFIC', 'status' => AdCampaign::STATUS_ACTIVE, 'daily_budget' => 10,
        ]);
        $this->graph = $this->graphError(190, 463);

        $this->actingAs($this->user($account))->postJson("/api/social/ads/{$campaign->id}/pause")
            ->assertStatus(409)->assertJsonPath('error_code', 'SOCIAL_CONNECTION_EXPIRED');

        $this->assertSame(AdCampaign::STATUS_ACTIVE, $campaign->fresh()->status);
        $this->assertSame('expired', $adAccount->fresh()->connectionStatus());
    }

    public function test_organic_post_on_an_expired_page_is_refused_without_a_post_row(): void
    {
        $account = $this->tenant();
        $this->connection($account, ['asset_type' => 'facebook_page', 'provider_id' => 'page-1', 'name' => 'Acme Page', 'token_expires_at' => now()->subDay()]);

        $this->actingAs($this->user($account))->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'Hello'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'SOCIAL_CONNECTION_EXPIRED')
            ->assertJsonPath('message', 'The connected Facebook Page "Acme Page" has expired. Reconnect it in Social Accounts, then try again.');

        $this->assertSame(0, OrganicPost::count());
        Http::assertNothingSent();
    }

    public function test_organic_post_rejected_for_an_expired_token_records_a_safe_failure(): void
    {
        $account = $this->tenant();
        $page = $this->connection($account, ['asset_type' => 'facebook_page', 'provider_id' => 'page-1', 'name' => 'Acme Page']);
        $this->graph = $this->graphError(190, 463);

        $this->actingAs($this->user($account))->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'Hello'])
            ->assertStatus(409)->assertJsonPath('error_code', 'SOCIAL_CONNECTION_EXPIRED');

        $post = OrganicPost::sole();
        // Phase 9 Task 3 — the post waits for a reconnect (retryable) instead of a plain failure.
        $this->assertSame(OrganicPost::STATUS_RECONNECT_REQUIRED, $post->status);
        $this->assertSame('social_connection_expired', $post->failure_code);
        $this->assertStringContainsString('Reconnect it in Social Accounts', $post->error_message);
        $this->assertStringNotContainsString('fbtrace', $post->error_message);
        $this->assertSame('expired', $page->fresh()->connectionStatus());
    }

    public function test_the_inbox_skips_dead_connections_and_offers_reconnect(): void
    {
        $account = $this->tenant();
        $revoked = $this->connection($account, ['asset_type' => 'facebook_page', 'provider_id' => 'page-1', 'health_status' => SocialAccount::HEALTH_REAUTH_REQUIRED]);
        $healthy = $this->connection($account, ['asset_type' => 'instagram', 'provider_id' => 'ig-1']);
        $this->graph = $this->graphError(190, 463);

        $response = $this->actingAs($this->user($account))->getJson('/api/social/inbox/threads')->assertOk();

        $issues = collect($response->json('connection_issues'));
        $this->assertEqualsCanonicalizing([$revoked->id, $healthy->id], $issues->pluck('social_account_id')->all());
        $this->assertSame('expired', $healthy->fresh()->connectionStatus(), 'the Instagram 190/463 was persisted');
        Http::assertSentCount(1); // only the healthy connection was asked

        $this->actingAs($this->user($account))->getJson("/api/social/inbox/threads/facebook:{$revoked->id}:conv-1/messages")
            ->assertStatus(409)->assertJsonPath('error_code', 'SOCIAL_CONNECTION_REVOKED');
    }
}
