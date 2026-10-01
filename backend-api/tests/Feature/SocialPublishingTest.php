<?php

namespace Tests\Feature;

use App\Jobs\CompleteInstagramVideoPostJob;
use App\Jobs\PublishScheduledPostJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\OrganicPost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Social\OrganicPublishService;
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
 * Phase 9 Task 3 — social publishing foundation & scheduling: manual and
 * scheduled organic posts share one lifecycle (OrganicPublishService),
 * scheduled posts are claimed exactly once, cancelled posts never run,
 * provider/connection failures settle to failed / reconnect_required,
 * idempotency keys never double-post, and every step is tenant-scoped and
 * re-checks the TARGET account.
 */
class SocialPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAA-page-token-never-exposed';

    /** @var array{0: int, 1: array<string, mixed>}|'down' */
    private array|string $graph = [200, ['id' => 'FB_POST_1']];

    /** Every provider request attempted, including ones that got no answer. */
    private int $attempted = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config(['social.publishing.max_attempts' => 3, 'social.publishing.backoff_minutes' => [1, 5, 15]]);

        Http::fake(function (HttpRequest $request) {
            $this->attempted++;

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

    private function page(Account $account, array $attributes = []): SocialAccount
    {
        return SocialAccount::create($attributes + [
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => 'facebook_page',
            'provider_id' => 'PG'.$account->id, 'name' => 'Acme Page', 'access_token' => self::TOKEN,
            'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);
    }

    private function instagram(Account $account): SocialAccount
    {
        return $this->page($account, ['asset_type' => 'instagram', 'provider_id' => 'IG'.$account->id, 'name' => 'Acme IG']);
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

    private function schedule(Account $account, User $user, array $extra = [])
    {
        return $this->actingAs($user)->postJson('/api/social/organic-posts', $extra + [
            'platform' => 'facebook', 'caption' => 'Launch day', 'scheduled_at' => now()->addHour()->toIso8601String(),
        ]);
    }

    private function scheduledPost(Account $account, SocialAccount $page, array $attributes = []): OrganicPost
    {
        return OrganicPost::create($attributes + [
            'account_id' => $account->id, 'social_account_id' => $page->id, 'provider' => 'meta', 'platform' => 'facebook',
            'caption' => 'Scheduled hello', 'status' => OrganicPost::STATUS_SCHEDULED, 'origin' => OrganicPost::ORIGIN_SCHEDULED,
            'scheduled_at' => now()->subMinute(), 'attempts' => 0,
        ]);
    }

    /** Runs the scheduler once and every job it queued, like cron + the social worker. */
    private function tick(): void
    {
        Queue::fake();
        $this->artisan('social:publish-due')->assertSuccessful();

        foreach (Queue::pushed(PublishScheduledPostJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function providerCalls(): int
    {
        return $this->attempted;
    }

    // ================================================================== manual

    public function test_publish_now_posts_to_the_page_and_records_a_published_manual_post(): void
    {
        $account = $this->tenant();
        $this->page($account);

        $response = $this->actingAs($this->user($account))->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'Hello'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.origin', 'manual')
            ->assertJsonPath('data.external_post_id', 'FB_POST_1')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.can_retry', false);

        $this->assertSame(1, $this->providerCalls());
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString('claim_token', $response->getContent());
        $post = OrganicPost::sole();
        $this->assertNull($post->claim_token);
        $this->assertSame(1, $post->attempts);
    }

    public function test_publish_now_rejected_by_meta_is_a_failed_post_with_a_safe_message(): void
    {
        $account = $this->tenant();
        $this->page($account);
        $this->graph = $this->graphError(100);

        $this->actingAs($this->user($account))->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'Hello'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_code', 'provider_rejected')
            ->assertJsonPath('data.can_retry', true);

        $post = OrganicPost::sole();
        $this->assertStringNotContainsString('fbtrace', $post->error_message);
        $this->assertSame(100, $post->metadata['provider_error_code']);
        $this->assertSame(1, $this->providerCalls(), 'a manual publish is not retried automatically');
    }

    public function test_publish_now_with_no_answer_from_meta_is_outcome_unknown(): void
    {
        $account = $this->tenant();
        $this->page($account);
        $this->graph = 'down';

        $this->actingAs($this->user($account))->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'Hello'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_code', 'outcome_unknown');
    }

    public function test_instagram_without_media_is_refused_before_a_row_or_a_provider_call(): void
    {
        $account = $this->tenant();
        $this->instagram($account);

        $this->actingAs($this->user($account))->postJson('/api/social/organic-posts', ['platform' => 'instagram', 'caption' => 'Text only'])
            ->assertStatus(422);

        $this->assertSame(0, OrganicPost::count());
        Http::assertNothingSent();
    }

    public function test_a_social_account_of_another_tenant_can_never_be_selected(): void
    {
        $account = $this->tenant();
        $this->page($account);
        $foreign = $this->page($this->tenant());

        $this->actingAs($this->user($account))->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'Hi', 'social_account_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected Facebook asset is not connected to this account.');

        $this->assertSame(0, OrganicPost::count());
        Http::assertNothingSent();
    }

    public function test_an_explicit_social_account_is_used_when_the_tenant_has_several_pages(): void
    {
        $account = $this->tenant();
        $this->page($account);
        $second = $this->page($account, ['provider_id' => 'PG-SECOND']);

        $this->actingAs($this->user($account))->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'Hi', 'social_account_id' => $second->id])
            ->assertCreated()->assertJsonPath('data.social_account_id', $second->id);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/PG-SECOND/feed'));
    }

    // ================================================================== scheduling

    public function test_scheduling_creates_a_scheduled_post_without_calling_the_provider(): void
    {
        $account = $this->tenant();
        $this->page($account);

        $this->schedule($account, $this->user($account))
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.origin', 'scheduled')
            ->assertJsonPath('data.can_cancel', true);

        Http::assertNothingSent();
        $this->assertNotNull(OrganicPost::sole()->scheduled_at);
    }

    public function test_a_past_or_too_distant_schedule_is_rejected(): void
    {
        $account = $this->tenant();
        $this->page($account);
        $user = $this->user($account);

        $this->schedule($account, $user, ['scheduled_at' => now()->subMinute()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->schedule($account, $user, ['scheduled_at' => now()->addDays(91)->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('scheduled_at');

        $this->assertSame(0, OrganicPost::count());
    }

    public function test_the_scheduler_publishes_only_due_posts_through_the_social_queue(): void
    {
        $account = $this->tenant();
        $page = $this->page($account);
        $due = $this->scheduledPost($account, $page);
        $later = $this->scheduledPost($account, $page, ['scheduled_at' => now()->addHour()]);

        Queue::fake();
        $this->artisan('social:publish-due')->assertSuccessful();

        Queue::assertPushed(PublishScheduledPostJob::class, 1);
        Queue::assertPushed(PublishScheduledPostJob::class, fn ($job) => $job->organicPostId === $due->id && $job->queue === 'social' && $job->connection === 'database_long');
        $this->assertSame(OrganicPost::STATUS_PUBLISHING, $due->fresh()->status);
        $this->assertSame(OrganicPost::STATUS_SCHEDULED, $later->fresh()->status);
        Http::assertNothingSent(); // the provider call happens in the job, not the scheduler

        app()->call([Queue::pushed(PublishScheduledPostJob::class)->sole(), 'handle']);

        $due->refresh();
        $this->assertSame([OrganicPost::STATUS_PUBLISHED, 'FB_POST_1', 1], [$due->status, $due->external_post_id, $due->attempts]);
        $this->assertNull($due->claim_token);
    }

    public function test_a_due_post_is_claimed_once_however_often_the_scheduler_runs(): void
    {
        $account = $this->tenant();
        $post = $this->scheduledPost($account, $this->page($account));
        $service = app(OrganicPublishService::class);

        $first = $service->claimDue();
        $second = $service->claimDue();

        $this->assertCount(1, $first);
        $this->assertSame([], $second, 'an overlapping run finds nothing to claim');

        // A job carrying a stale / forged token does nothing.
        $service->execute($post, 'not-the-claim-token', viaWorker: true);
        Http::assertNothingSent();
        $this->assertSame(OrganicPost::STATUS_PUBLISHING, $post->fresh()->status);

        // The job is idempotent: running the rightful one twice posts once.
        $job = new PublishScheduledPostJob($post->id, $first[0]['token']);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertSame(1, $this->providerCalls());
        $this->assertSame(OrganicPost::STATUS_PUBLISHED, $post->fresh()->status);
    }

    public function test_a_cancelled_post_never_runs(): void
    {
        $account = $this->tenant();
        $this->page($account);
        $user = $this->user($account);
        $id = $this->schedule($account, $user)->json('data.id');

        $this->actingAs($user)->postJson("/api/social/organic-posts/{$id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.can_cancel', false);

        $this->travel(2)->hours();
        $this->tick();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(OrganicPost::STATUS_CANCELLED, OrganicPost::find($id)->status);
    }

    public function test_cancel_after_claim_is_refused_and_a_late_cancel_does_not_stop_a_running_job(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $post = $this->scheduledPost($account, $this->page($account));
        [$claim] = app(OrganicPublishService::class)->claimDue();

        $this->actingAs($user)->postJson("/api/social/organic-posts/{$post->id}/cancel")
            ->assertStatus(409)->assertJsonPath('error_code', 'POST_NOT_CANCELLABLE');

        app()->call([new PublishScheduledPostJob($post->id, $claim['token']), 'handle']);
        $this->assertSame(OrganicPost::STATUS_PUBLISHED, $post->fresh()->status);

        $this->actingAs($user)->postJson("/api/social/organic-posts/{$post->id}/cancel")->assertStatus(409);
    }

    public function test_a_cancel_between_claim_and_job_is_impossible_but_a_cancelled_row_is_never_sent(): void
    {
        $account = $this->tenant();
        $post = $this->scheduledPost($account, $this->page($account));
        [$claim] = app(OrganicPublishService::class)->claimDue();

        // Simulate any path that moved the row out of `publishing` before the job ran.
        OrganicPost::whereKey($post->id)->update(['status' => OrganicPost::STATUS_CANCELLED, 'claim_token' => null]);
        app()->call([new PublishScheduledPostJob($post->id, $claim['token']), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(OrganicPost::STATUS_CANCELLED, $post->fresh()->status);
    }

    public function test_a_temporary_failure_is_retried_with_back_off_then_fails(): void
    {
        $account = $this->tenant();
        $post = $this->scheduledPost($account, $this->page($account));
        $this->graph = $this->graphError(2, 0, 500);

        $this->tick();
        $post->refresh();
        $this->assertSame([OrganicPost::STATUS_SCHEDULED, 1, 'provider_transient'], [$post->status, $post->attempts, $post->failure_code]);
        $this->assertEqualsWithDelta(now()->addMinute()->timestamp, $post->next_attempt_at->timestamp, 2);

        $this->tick(); // not due yet
        $this->assertSame(1, $this->providerCalls());

        $this->travel(2)->minutes();
        $this->tick();
        $post->refresh();
        $this->assertSame([OrganicPost::STATUS_SCHEDULED, 2], [$post->status, $post->attempts]);
        $this->assertEqualsWithDelta(now()->addMinutes(5)->timestamp, $post->next_attempt_at->timestamp, 2);

        $this->travel(6)->minutes();
        $this->tick();
        $post->refresh();
        $this->assertSame([OrganicPost::STATUS_FAILED, 3, 'provider_transient'], [$post->status, $post->attempts, $post->failure_code]);
        $this->assertSame(3, $this->providerCalls());
    }

    public function test_a_temporary_failure_then_success_publishes_once(): void
    {
        $account = $this->tenant();
        $post = $this->scheduledPost($account, $this->page($account));
        $this->graph = $this->graphError(4, 0, 400); // rate limited

        $this->tick();
        $this->graph = [200, ['id' => 'FB_LATE']];
        $this->travel(2)->minutes();
        $this->tick();

        $post->refresh();
        $this->assertSame([OrganicPost::STATUS_PUBLISHED, 'FB_LATE', 2], [$post->status, $post->external_post_id, $post->attempts]);
        $this->assertNull($post->failure_code);
    }

    public function test_a_permanent_rejection_fails_without_retrying(): void
    {
        $account = $this->tenant();
        $post = $this->scheduledPost($account, $this->page($account));
        $this->graph = $this->graphError(100);

        $this->tick();
        $this->travel(30)->minutes();
        $this->tick();

        $post->refresh();
        $this->assertSame([OrganicPost::STATUS_FAILED, 'provider_rejected'], [$post->status, $post->failure_code]);
        $this->assertSame(1, $this->providerCalls());
    }

    public function test_no_answer_from_the_provider_is_never_resent_automatically(): void
    {
        $account = $this->tenant();
        $post = $this->scheduledPost($account, $this->page($account));
        $this->graph = 'down';

        $this->tick();
        $this->travel(1)->hours();
        $this->tick();

        $post->refresh();
        $this->assertSame([OrganicPost::STATUS_FAILED, 'outcome_unknown'], [$post->status, $post->failure_code]);
        $this->assertSame(1, $this->providerCalls());
    }

    // ================================================================== connection health

    public function test_a_connection_revoked_at_send_time_moves_the_post_to_reconnect_required(): void
    {
        $account = $this->tenant();
        $page = $this->page($account);
        $post = $this->scheduledPost($account, $page);
        $this->graph = $this->graphError(190, 460);

        $this->tick();

        $post->refresh();
        $this->assertSame(OrganicPost::STATUS_RECONNECT_REQUIRED, $post->status);
        $this->assertStringContainsString('Reconnect it in Social Accounts', $post->error_message);
        $this->assertStringNotContainsString('fbtrace', $post->error_message);
        $this->assertNotSame('connected', $page->fresh()->connectionStatus());
    }

    public function test_a_connection_already_known_expired_is_not_called_at_send_time(): void
    {
        $account = $this->tenant();
        $page = $this->page($account, ['token_expires_at' => now()->subDay()]);
        $post = $this->scheduledPost($account, $page);

        $this->tick();

        Http::assertNothingSent();
        $post->refresh();
        $this->assertSame([OrganicPost::STATUS_RECONNECT_REQUIRED, 'social_connection_expired'], [$post->status, $post->failure_code]);
    }

    public function test_scheduling_on_an_expired_connection_is_refused_with_the_reconnect_error(): void
    {
        $account = $this->tenant();
        $this->page($account, ['health_status' => SocialAccount::HEALTH_TOKEN_EXPIRED]);

        $this->schedule($account, $this->user($account))
            ->assertStatus(409)->assertJsonPath('error_code', 'SOCIAL_CONNECTION_EXPIRED')->assertJsonPath('reconnect_path', '/social/accounts');

        $this->assertSame(0, OrganicPost::count());
    }

    public function test_a_reconnect_required_post_can_be_retried_once_reconnected(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $page = $this->page($account, ['health_status' => SocialAccount::HEALTH_TOKEN_EXPIRED]);
        $post = $this->scheduledPost($account, $page, ['status' => OrganicPost::STATUS_RECONNECT_REQUIRED, 'failure_code' => 'social_connection_expired']);

        $this->actingAs($user)->postJson("/api/social/organic-posts/{$post->id}/retry")
            ->assertStatus(409)->assertJsonPath('error_code', 'SOCIAL_CONNECTION_EXPIRED');
        $this->assertSame(OrganicPost::STATUS_RECONNECT_REQUIRED, $post->fresh()->status);

        $page->forceFill(['health_status' => SocialAccount::HEALTH_CONNECTED, 'access_token' => 'EAA-new'])->save();

        $this->actingAs($user)->postJson("/api/social/organic-posts/{$post->id}/retry")
            ->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.failure_code', null);

        $this->tick();
        $this->assertSame(OrganicPost::STATUS_PUBLISHED, $post->fresh()->status);
    }

    public function test_published_and_cancelled_posts_cannot_be_retried(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $page = $this->page($account);
        $published = $this->scheduledPost($account, $page, ['status' => OrganicPost::STATUS_PUBLISHED]);
        $cancelled = $this->scheduledPost($account, $page, ['status' => OrganicPost::STATUS_CANCELLED]);

        foreach ([$published, $cancelled] as $post) {
            $this->actingAs($user)->postJson("/api/social/organic-posts/{$post->id}/retry")
                ->assertStatus(409)->assertJsonPath('error_code', 'POST_NOT_RETRYABLE');
        }
    }

    // ================================================================== target re-checks, past-due, stale

    public function test_the_worker_re_checks_the_target_account_before_sending(): void
    {
        $suspended = $this->tenant();
        $unentitled = $this->tenant();
        $a = $this->scheduledPost($suspended, $this->page($suspended));
        $b = $this->scheduledPost($unentitled, $this->page($unentitled));
        $suspended->forceFill(['status' => 'suspended'])->save();
        $this->revokeSocialCapability($unentitled);

        $this->tick();

        Http::assertNothingSent();
        $this->assertSame([OrganicPost::STATUS_FAILED, 'client_account_suspended'], [$a->fresh()->status, $a->fresh()->failure_code]);
        $this->assertSame([OrganicPost::STATUS_FAILED, 'capability_not_entitled'], [$b->fresh()->status, $b->fresh()->failure_code]);
    }

    public function test_a_disconnected_page_fails_the_post_without_using_another_connection(): void
    {
        $account = $this->tenant();
        $page = $this->page($account);
        $this->page($account, ['provider_id' => 'PG-OTHER']);
        $post = $this->scheduledPost($account, $page);
        $page->delete();

        $this->tick();

        Http::assertNothingSent();
        $this->assertSame([OrganicPost::STATUS_FAILED, 'connection_missing'], [$post->fresh()->status, $post->fresh()->failure_code]);
    }

    public function test_a_slightly_late_post_is_published_but_a_long_missed_one_is_not(): void
    {
        $account = $this->tenant();
        $page = $this->page($account);
        $late = $this->scheduledPost($account, $page, ['scheduled_at' => now()->subHours(3)]);
        $missed = $this->scheduledPost($account, $page, ['scheduled_at' => now()->subHours(30)]);

        $this->tick();

        $this->assertSame(OrganicPost::STATUS_PUBLISHED, $late->fresh()->status);
        $this->assertSame([OrganicPost::STATUS_FAILED, 'missed_schedule'], [$missed->fresh()->status, $missed->fresh()->failure_code]);
        $this->assertSame(1, $this->providerCalls());
        $this->assertTrue($missed->fresh()->isRetryable());
    }

    public function test_stale_claims_are_settled_and_never_resent(): void
    {
        $account = $this->tenant();
        $page = $this->page($account);
        $sent = $this->scheduledPost($account, $page, ['status' => OrganicPost::STATUS_PUBLISHING, 'claim_token' => 'x1', 'claimed_at' => now()->subHour(), 'provider_called_at' => now()->subHour(), 'attempts' => 1]);
        $notSent = $this->scheduledPost($account, $page, ['status' => OrganicPost::STATUS_PUBLISHING, 'claim_token' => 'x2', 'claimed_at' => now()->subHour(), 'attempts' => 1]);
        $fresh = $this->scheduledPost($account, $page, ['status' => OrganicPost::STATUS_PUBLISHING, 'claim_token' => 'x3', 'claimed_at' => now()->subMinute(), 'attempts' => 1]);

        $this->tick();

        Http::assertNothingSent();
        $this->assertSame([OrganicPost::STATUS_FAILED, 'outcome_unknown'], [$sent->fresh()->status, $sent->fresh()->failure_code]);
        $this->assertSame([OrganicPost::STATUS_FAILED, 'interrupted'], [$notSent->fresh()->status, $notSent->fresh()->failure_code]);
        $this->assertSame(OrganicPost::STATUS_PUBLISHING, $fresh->fresh()->status);
    }

    public function test_a_job_that_dies_settles_its_claim(): void
    {
        $account = $this->tenant();
        $post = $this->scheduledPost($account, $this->page($account));
        [$claim] = app(OrganicPublishService::class)->claimDue();

        (new PublishScheduledPostJob($post->id, $claim['token']))->failed(new \RuntimeException('worker killed'));

        $this->assertSame([OrganicPost::STATUS_FAILED, 'interrupted'], [$post->fresh()->status, $post->fresh()->failure_code]);
    }

    public function test_a_scheduled_instagram_video_hands_over_to_the_processing_chain(): void
    {
        $account = $this->tenant();
        $ig = $this->instagram($account);
        $post = $this->scheduledPost($account, $ig, ['platform' => 'instagram', 'media_url' => 'https://x/v.mp4', 'media_type' => 'video']);
        $this->graph = [200, ['id' => 'C9']];

        $this->tick();

        $post->refresh();
        $this->assertSame(OrganicPost::STATUS_PENDING, $post->status);
        $this->assertSame('C9', $post->metadata['container_id']);
        Queue::assertPushed(CompleteInstagramVideoPostJob::class, fn ($job) => $job->organicPostId === $post->id && $job->creationId === 'C9');
    }

    // ================================================================== idempotency

    public function test_the_same_idempotency_key_never_posts_twice(): void
    {
        $account = $this->tenant();
        $this->page($account);
        $user = $this->user($account);
        $body = ['platform' => 'facebook', 'caption' => 'Once only', 'idempotency_key' => 'submit-abc-123'];

        $first = $this->actingAs($user)->postJson('/api/social/organic-posts', $body)->assertCreated()->assertJsonPath('replayed', false);
        $this->actingAs($user)->postJson('/api/social/organic-posts', $body)
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, OrganicPost::count());
        $this->assertSame(1, $this->providerCalls());
    }

    public function test_the_idempotency_key_header_is_accepted_and_reuse_for_another_post_is_a_conflict(): void
    {
        $account = $this->tenant();
        $this->page($account);
        $user = $this->user($account);

        $this->actingAs($user)->withHeader('Idempotency-Key', 'hdr-key-0001')
            ->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'First'])->assertCreated();
        $this->actingAs($user)->withHeader('Idempotency-Key', 'hdr-key-0001')
            ->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'Different'])
            ->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, OrganicPost::count());
    }

    public function test_idempotency_keys_are_scoped_to_the_account(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->page($a);
        $this->page($b);
        $body = ['platform' => 'facebook', 'caption' => 'Same', 'idempotency_key' => 'shared-key-01'];

        $this->actingAs($this->user($a))->postJson('/api/social/organic-posts', $body)->assertCreated();
        $this->actingAs($this->user($b))->postJson('/api/social/organic-posts', $body)->assertCreated();

        $this->assertSame(2, OrganicPost::count());
    }

    public function test_a_concurrent_insert_with_the_same_key_is_returned_not_duplicated(): void
    {
        $account = $this->tenant();
        $page = $this->page($account);
        $service = app(OrganicPublishService::class);
        $payload = ['platform' => 'facebook', 'caption' => 'Race', 'idempotency_key' => 'race-key-001', 'scheduled_at' => now()->addHour()];

        $winner = $service->submit($account, $payload);
        $loser = $service->submit($account, $payload);

        $this->assertFalse($winner['replayed']);
        $this->assertTrue($loser['replayed']);
        $this->assertSame($winner['post']->id, $loser['post']->id);
        $this->assertSame(1, OrganicPost::count());

        // The unique index itself refuses a second row with the key (the race backstop).
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->scheduledPost($account, $page, ['idempotency_key' => 'race-key-001']);
    }

    public function test_losing_the_insert_race_returns_the_winners_post(): void
    {
        $account = $this->tenant();
        $page = $this->page($account);
        $service = app(OrganicPublishService::class);
        $payload = ['platform' => 'facebook', 'caption' => 'Race', 'idempotency_key' => 'race-key-002'];
        $raced = false;

        // A concurrent request inserts the same key after this one checked for it but before its own insert.
        OrganicPost::creating(function (OrganicPost $model) use (&$raced, $account, $page) {
            if ($raced || $model->idempotency_key !== 'race-key-002') {
                return;
            }
            $raced = true;
            OrganicPost::withoutEvents(fn () => OrganicPost::create([
                'account_id' => $account->id, 'social_account_id' => $page->id, 'provider' => 'meta', 'platform' => 'facebook',
                'caption' => 'Race', 'status' => OrganicPost::STATUS_PUBLISHING, 'idempotency_key' => 'race-key-002',
                'metadata' => $model->metadata, 'claim_token' => 'winner', 'claimed_at' => now(), 'attempts' => 1,
            ]));
        });

        $result = $service->submit($account, $payload);

        $this->assertTrue($result['replayed']);
        $this->assertSame('winner', OrganicPost::sole()->claim_token);
        $this->assertSame($result['post']->id, OrganicPost::sole()->id);
        $this->assertSame(0, $this->providerCalls(), 'the loser never calls the provider');
    }

    // ================================================================== authorization

    public function test_route_guards_protect_every_endpoint(): void
    {
        $account = $this->tenant();
        $this->page($account);
        $plain = $this->user($account, 'user');

        foreach ([['get', '/api/social/organic-posts'], ['post', '/api/social/organic-posts'], ['post', '/api/social/organic-posts/1/cancel'], ['post', '/api/social/organic-posts/1/retry'], ['get', '/api/social/organic-posts/1']] as [$method, $uri]) {
            $this->actingAs($plain)->json($method, $uri, ['platform' => 'facebook', 'caption' => 'x'])->assertForbidden();
        }

        $this->revokeSocialCapability($account);
        $this->actingAs($this->user($account))->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'x'])->assertForbidden();

        $noModule = $this->tenant();
        $noModule->forceFill(['allowed_modules' => ['campaigns']])->save();
        $this->actingAs($this->user($noModule))->getJson('/api/social/organic-posts')->assertForbidden();

        $this->assertSame(0, OrganicPost::count());
        Http::assertNothingSent();
    }

    public function test_posts_of_another_tenant_are_invisible_and_untouchable(): void
    {
        $owner = $this->tenant();
        $post = $this->scheduledPost($owner, $this->page($owner), ['scheduled_at' => now()->addHour()]);
        $intruder = $this->tenant();
        $user = $this->user($intruder);

        $this->actingAs($user)->getJson("/api/social/organic-posts/{$post->id}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/social/organic-posts/{$post->id}/cancel")->assertNotFound();
        $this->actingAs($user)->postJson("/api/social/organic-posts/{$post->id}/retry")->assertNotFound();
        $this->actingAs($user)->getJson('/api/social/organic-posts')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(OrganicPost::STATUS_SCHEDULED, $post->fresh()->status);
    }

    public function test_the_list_filters_by_status(): void
    {
        $account = $this->tenant();
        $page = $this->page($account);
        $this->scheduledPost($account, $page, ['scheduled_at' => now()->addHour()]);
        $this->scheduledPost($account, $page, ['status' => OrganicPost::STATUS_FAILED]);

        $this->actingAs($this->user($account))->getJson('/api/social/organic-posts?status=failed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'failed');
        $this->actingAs($this->user($account))->getJson('/api/social/organic-posts?status=bogus')->assertStatus(422);
    }

    public function test_super_admin_must_select_a_target_and_is_not_held_to_its_plan(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenant();
        $this->page($target);

        $this->actingAs($admin)->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'x'])->assertStatus(422);

        // Owner decision (2026-09-30): a client without `social` is not closed to a Super Admin.
        $this->revokeSocialCapability($target);
        $this->actingAs($admin)->postJson("/api/social/organic-posts?account_id={$target->id}", ['platform' => 'facebook', 'caption' => 'x', 'scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertCreated();
        $this->assertSame($target->id, OrganicPost::sole()->account_id);
        $this->assertSame($admin->id, OrganicPost::sole()->created_by_user_id);
        Http::assertNothingSent();
    }

    public function test_super_admin_cannot_schedule_for_a_suspended_target(): void
    {
        $target = $this->tenant();
        $this->page($target);
        $target->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->superAdmin())->postJson("/api/social/organic-posts?account_id={$target->id}", ['platform' => 'facebook', 'caption' => 'x', 'scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
    }

    // ================================================================== guardrails

    public function test_the_lifecycle_only_moves_along_allowed_transitions(): void
    {
        $this->assertTrue(OrganicPost::canTransition('scheduled', 'publishing'));
        $this->assertFalse(OrganicPost::canTransition('cancelled', 'scheduled'));
        $this->assertFalse(OrganicPost::canTransition('published', 'failed'));
        $this->assertFalse(OrganicPost::canTransition('scheduled', 'published'), 'nothing is published without being claimed');
    }

    public function test_provider_calls_stay_inside_the_publisher_layer(): void
    {
        foreach ([
            'Services/Social/OrganicPublishService.php', 'Http/Controllers/Api/OrganicPostController.php',
            'Jobs/PublishScheduledPostJob.php', 'Jobs/CompleteInstagramVideoPostJob.php', 'Console/Commands/PublishDueSocialPosts.php',
        ] as $relative) {
            $source = file_get_contents(app_path($relative));
            $this->assertStringNotContainsString('graph.facebook.com', $source, $relative);
            $this->assertStringNotContainsString('api.linkedin.com', $source, $relative);
            $this->assertStringNotContainsString('Facades\\Http', $source, $relative);
        }

        // Publishing endpoints of the providers appear nowhere else in app/.
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Services/Social/Publishing/')) {
                continue;
            }
            if (preg_match('#/media_publish|/ugcPosts|registerUpload#', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }
        $this->assertSame([], $offenders);
    }
}
