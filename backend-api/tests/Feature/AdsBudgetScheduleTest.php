<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AdCampaign;
use App\Models\Capability;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ads Phase B (2026-10-15): a total (lifetime) budget with a schedule, editing a
 * running campaign's budget / end date, the launch kept for "Run again", and a
 * clear reason when a campaign cannot run (ended, payment due, spending limit).
 */
class AdsBudgetScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> response body per "METHOD path-regex" */
    private array $graph = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            $key = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
            foreach ($this->graph as $pattern => $body) {
                if (preg_match($pattern, $key)) {
                    return Http::response($body, 200);
                }
            }
            $n = ++$this->seq;
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/campaigns') => Http::response(['id' => "CMP-{$n}"]),
                str_ends_with($path, '/adsets') => Http::response(['id' => "SET-{$n}"]),
                str_ends_with($path, '/adcreatives') => Http::response(['id' => "CR-{$n}"]),
                str_ends_with($path, '/ads') => Http::response(['id' => "AD-{$n}"]),
                default => Http::response(['success' => true]),
            };
        });
    }

    private function tenant(): Account
    {
        $account = Account::factory()->create(['allow_facebook' => true, 'allow_instagram' => true]);
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach (['ads', 'social'] as $slug) {
            AccountEntitlement::firstOrCreate(['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')], ['source' => 'manual_grant']);
        }
        foreach (['meta_ad_account' => 'act_'.$account->id.'00', 'facebook_page' => 'page-'.$account->id] as $type => $id) {
            SocialAccount::create(['account_id' => $account->id, 'provider' => 'meta', 'asset_type' => $type, 'provider_id' => $id, 'name' => $type,
                'access_token' => 'EAA-token', 'health_status' => SocialAccount::HEALTH_CONNECTED]);
        }

        return $account->fresh();
    }

    private function user(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('social_marketer');

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'campaign_name' => 'Diwali', 'objective' => 'LEAD_GENERATION', 'daily_budget' => 500,
            'targeting_specs' => ['countries' => ['IN'], 'age_min' => 18, 'age_max' => 45],
            'creative' => ['image_url' => null, 'headline' => 'Hi', 'primary_text' => 'Buy', 'lead_gen_form_id' => '777000111'],
        ], $overrides);
    }

    private function adsetBody(): array
    {
        $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/adsets'));

        return $request->data();
    }

    private function adAccountIs(array $fields): void
    {
        $this->graph['#^GET /v19\.0/act_\d+$#'] = $fields + ['currency' => 'INR', 'name' => 'Acme INR'];
    }

    private function running(Account $account, array $attributes = []): AdCampaign
    {
        return AdCampaign::create($attributes + [
            'account_id' => $account->id,
            'social_account_id' => SocialAccount::where('account_id', $account->id)->where('asset_type', 'meta_ad_account')->value('id'),
            'meta_campaign_id' => 'CMP-'.uniqid(), 'meta_adset_id' => 'SET-'.uniqid(), 'meta_ad_id' => 'AD-1',
            'name' => 'Diwali', 'objective' => 'LEAD_GENERATION', 'status' => AdCampaign::STATUS_ACTIVE,
            'daily_budget' => 500, 'currency' => 'INR',
        ]);
    }

    // ------------------------------------------------------------------ launch with a total budget / schedule

    public function test_a_total_budget_launch_sends_lifetime_budget_and_its_schedule_and_keeps_the_launch(): void
    {
        $account = $this->tenant();
        $this->adAccountIs(['account_status' => 1, 'spend_cap' => '0', 'amount_spent' => '0']);
        $start = now()->addHour()->startOfMinute();
        $end = now()->addDays(7)->startOfMinute();

        $response = $this->actingAs($this->user($account))->postJson('/api/social/ads/launch', $this->payload([
            'budget_type' => 'lifetime', 'daily_budget' => null, 'lifetime_budget' => 5000,
            'start_time' => $start->toIso8601String(), 'end_time' => $end->toIso8601String(),
        ]))->assertCreated();

        $body = $this->adsetBody();
        $this->assertSame('500000', (string) $body['lifetime_budget'], 'INR 5000 in paise');
        $this->assertArrayNotHasKey('daily_budget', $body);
        $this->assertSame($end->format(DATE_ATOM), $body['end_time']);
        $this->assertSame($start->format(DATE_ATOM), $body['start_time']);

        $response->assertJsonPath('data.budget_type', 'lifetime')
            ->assertJsonPath('data.lifetime_budget', 5000)
            ->assertJsonPath('data.daily_budget', 0)
            ->assertJsonPath('data.has_ended', false)
            ->assertJsonPath('data.launch_spec.targeting_specs.countries', ['IN'])
            ->assertJsonPath('data.launch_spec.creative.headline', 'Hi');
        $this->assertStringNotContainsString('EAA-token', json_encode(AdCampaign::sole()->launch_spec));
    }

    public function test_a_daily_budget_without_dates_is_unchanged(): void
    {
        $account = $this->tenant();

        $this->actingAs($this->user($account))->postJson('/api/social/ads/launch', $this->payload())->assertCreated()->assertJsonPath('data.budget_type', 'daily');

        $body = $this->adsetBody();
        $this->assertSame('50000', (string) $body['daily_budget']);
        $this->assertArrayNotHasKey('end_time', $body);
        $this->assertArrayNotHasKey('lifetime_budget', $body);
    }

    public function test_schedule_rules_follow_meta(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);

        // A total budget needs an end date.
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['budget_type' => 'lifetime', 'lifetime_budget' => 5000]))
            ->assertStatus(422)->assertJsonValidationErrors('end_time');
        // A daily budget with an end date must run for more than 24 hours.
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['end_time' => now()->addHours(20)->toIso8601String()]))
            ->assertStatus(422)->assertJsonValidationErrors('end_time');
        // The end comes after the start, and the start is not in the past.
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['start_time' => now()->addDays(3)->toIso8601String(), 'end_time' => now()->addDays(2)->toIso8601String()]))
            ->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['start_time' => now()->subDay()->toIso8601String()]))
            ->assertStatus(422)->assertJsonValidationErrors('start_time');

        $this->assertSame(0, AdCampaign::count());
    }

    // ------------------------------------------------------------------ edit budget

    public function test_editing_the_budget_changes_the_ad_set_on_meta_and_locally(): void
    {
        $account = $this->tenant();
        $campaign = $this->running($account);

        $this->actingAs($this->user($account))->patchJson("/api/social/ads/{$campaign->id}/budget", ['budget' => 750])
            ->assertOk()->assertJsonPath('data.daily_budget', 750);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), "/{$campaign->meta_adset_id}") && $r->data() == ['daily_budget' => 75000]);
    }

    public function test_a_total_budget_and_its_end_date_can_be_extended(): void
    {
        $account = $this->tenant();
        $campaign = $this->running($account, ['budget_type' => 'lifetime', 'daily_budget' => 0, 'lifetime_budget' => 5000, 'end_time' => now()->addDays(2)]);
        $newEnd = now()->addDays(10)->startOfMinute();

        $this->actingAs($this->user($account))->patchJson("/api/social/ads/{$campaign->id}/budget", ['budget' => 8000, 'end_time' => $newEnd->toIso8601String()])
            ->assertOk()->assertJsonPath('data.lifetime_budget', 8000);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), "/{$campaign->meta_adset_id}") && $r->data() == ['lifetime_budget' => 800000, 'end_time' => $newEnd->format(DATE_ATOM)]);
        $this->assertTrue($campaign->fresh()->end_time->equalTo($newEnd));
    }

    public function test_a_failed_campaign_budget_cannot_be_edited(): void
    {
        $account = $this->tenant();
        $campaign = $this->running($account, ['status' => AdCampaign::STATUS_FAILED]);

        $this->actingAs($this->user($account))->patchJson("/api/social/ads/{$campaign->id}/budget", ['budget' => 750])
            ->assertStatus(409)->assertJsonPath('error_code', 'AD_CAMPAIGN_INVALID_STATE');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ resume: say why it cannot run

    public function test_resume_explains_an_ended_campaign_without_calling_meta(): void
    {
        $account = $this->tenant();
        $campaign = $this->running($account, ['status' => AdCampaign::STATUS_PAUSED, 'end_time' => now()->subDay()]);

        $this->actingAs($this->user($account))->postJson("/api/social/ads/{$campaign->id}/resume")
            ->assertStatus(422)->assertJsonPath('error_code', 'AD_CAMPAIGN_CANNOT_RUN')
            ->assertJsonPath('data.has_ended', true);
        $this->assertStringContainsString('Run again', $this->actingAs($this->user($account))->postJson("/api/social/ads/{$campaign->id}/resume")->json('message'));
        Http::assertNothingSent();
    }

    public function test_resume_explains_a_payment_due_or_a_used_up_spending_limit(): void
    {
        $account = $this->tenant();
        $campaign = $this->running($account, ['status' => AdCampaign::STATUS_PAUSED]);
        $user = $this->user($account);

        $this->adAccountIs(['account_status' => 3, 'spend_cap' => '0', 'amount_spent' => '0']);
        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/resume")->assertStatus(422)
            ->assertJsonPath('error_code', 'AD_CAMPAIGN_CANNOT_RUN')
            ->assertJsonFragment(['message' => 'Your Meta ad account has a payment due, so ads cannot run. Pay the due amount or update the payment method in Meta Billing & payments, then press Resume again.']);

        $this->adAccountIs(['account_status' => 1, 'spend_cap' => '1000000', 'amount_spent' => '1000000']);
        $this->assertStringContainsString('spending limit', $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/resume")->assertStatus(422)->json('message'));

        $this->assertSame(AdCampaign::STATUS_PAUSED, $campaign->fresh()->status);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
    }

    public function test_resume_after_the_problem_is_fixed_runs_and_clears_the_stop_reason(): void
    {
        $account = $this->tenant();
        $campaign = $this->running($account, ['status' => AdCampaign::STATUS_PAUSED, 'meta_stop_status' => 'CAMPAIGN_PAUSED']);
        $this->adAccountIs(['account_status' => 1, 'spend_cap' => '0', 'amount_spent' => '0']);

        $this->actingAs($this->user($account))->postJson("/api/social/ads/{$campaign->id}/resume")->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.meta_stop_status', null);
    }

    // ------------------------------------------------------------------ the guard records why Meta stopped it

    public function test_the_guard_marks_an_ended_campaign_completed_and_records_metas_own_stop(): void
    {
        $account = $this->tenant();
        $ended = $this->running($account, ['end_time' => now()->subHour()]);
        $stopped = $this->running($account);
        $this->graph['#^GET /v19\.0/'.preg_quote($stopped->meta_campaign_id, '#').'$#'] = ['effective_status' => 'CAMPAIGN_PAUSED'];

        $this->artisan('ads:check-performance-rules')->assertSuccessful();

        $this->assertSame(['PAUSED', 'COMPLETED'], [$ended->fresh()->status, $ended->fresh()->meta_stop_status]);
        $this->assertNotNull($ended->fresh()->last_checked_at, 'its final figures were read before it was marked finished');
        $this->assertSame(['PAUSED', 'CAMPAIGN_PAUSED'], [$stopped->fresh()->status, $stopped->fresh()->meta_stop_status]);
    }

    public function test_extending_a_finished_campaign_keeps_it_paused_on_meta_until_resume(): void
    {
        $account = $this->tenant();
        $campaign = $this->running($account, ['status' => AdCampaign::STATUS_PAUSED, 'meta_stop_status' => 'COMPLETED',
            'budget_type' => 'lifetime', 'daily_budget' => 0, 'lifetime_budget' => 5000, 'end_time' => now()->subDay()]);

        $this->actingAs($this->user($account))->patchJson("/api/social/ads/{$campaign->id}/budget", ['budget' => 6000, 'end_time' => now()->addDays(5)->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.status', 'PAUSED')
            ->assertJsonPath('data.meta_stop_status', null)
            ->assertJsonPath('data.has_ended', false);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), "/{$campaign->meta_campaign_id}") && ($r->data()['status'] ?? null) === 'PAUSED');
    }

    public function test_an_active_campaigns_budget_edit_does_not_pause_it(): void
    {
        $account = $this->tenant();
        $campaign = $this->running($account);

        $this->actingAs($this->user($account))->patchJson("/api/social/ads/{$campaign->id}/budget", ['budget' => 600])->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), "/{$campaign->meta_campaign_id}"));
    }

    public function test_the_ended_message_shows_india_time(): void
    {
        $account = $this->tenant();
        $campaign = $this->running($account, ['status' => AdCampaign::STATUS_PAUSED, 'end_time' => '2026-10-01 04:30:00']);

        $this->assertStringContainsString('1 Oct 2026, 10:00 AM IST', $this->actingAs($this->user($account))->postJson("/api/social/ads/{$campaign->id}/resume")->json('message'));
    }
}
