<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AdAttribution;
use App\Models\AdCampaign;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Ads\AdAttributionService;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 10 Task 2 — Ads entitlement enforcement & campaign lifecycle.
 *
 * Every launcher route requires the Ads permission + meta_ads module + the
 * `ads` capability (tenant and Super Admin target alike; reads survive an
 * expired subscription, writes do not). A launch is recorded before the
 * first Meta write and settles to ACTIVE / FAILED / UNCONFIRMED; a repeated
 * Idempotency-Key never calls Meta again; pause/resume are guarded, single,
 * conditional transitions; an unknown outcome is never retried.
 */
class AdCampaignLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{0: int, 1: array<string, mixed>}|'down'> path regex => what Meta answers */
    private array $meta = [];

    /** @var list<string> "METHOD path" of every Graph call attempted (including ones that "time out") */
    private array $calls = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = $request->method().' '.$path;

            foreach ($this->meta as $pattern => $answer) {
                if (preg_match($pattern, $request->method().' '.$path)) {
                    if ($answer === 'down') {
                        throw new ConnectionException('Operation timed out');
                    }

                    return Http::response($answer[1], $answer[0]);
                }
            }

            $n = ++$this->seq;

            return match (true) {
                str_ends_with($path, '/campaigns') => Http::response(['id' => "CMP-{$n}"]),
                str_ends_with($path, '/adsets') => Http::response(['id' => "SET-{$n}"]),
                str_ends_with($path, '/adcreatives') => Http::response(['id' => "CR-{$n}"]),
                str_ends_with($path, '/ads') => Http::response(['id' => "AD-{$n}"]),
                str_ends_with($path, '/insights') => Http::response(['data' => []]),
                default => Http::response(['success' => true]),
            };
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function grant(Account $account, string $slug): void
    {
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant'],
        );
    }

    /** A tenant with an active subscription, the Ads grant, a Meta Ad Account and a Facebook Page. */
    private function tenant(array $grants = ['ads'], bool $assets = true): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach ($grants as $slug) {
            $this->grant($account, $slug);
        }

        if ($assets) {
            $this->asset($account, 'meta_ad_account', 'act_'.$account->id.'00');
            $this->asset($account, 'facebook_page', 'page-'.$account->id);
        }

        return $account->fresh();
    }

    private function asset(Account $account, string $type, string $providerId): SocialAccount
    {
        return SocialAccount::create([
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => $type, 'provider_id' => $providerId,
            'name' => $type, 'access_token' => 'EAA-token-'.$account->id, 'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);
    }

    private function user(Account $account, string $role = 'social_marketer'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function viewer(Account $account): User
    {
        Role::firstOrCreate(['name' => 'ads_viewer', 'guard_name' => 'web'])->syncPermissions(['social_ads.view']);

        return $this->user($account, 'ads_viewer');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function campaign(Account $account, string $status = AdCampaign::STATUS_ACTIVE, array $attributes = []): AdCampaign
    {
        $n = ++$this->seq;

        return AdCampaign::create($attributes + [
            'account_id' => $account->id,
            'social_account_id' => SocialAccount::forAccount($account->id)->where('asset_type', 'meta_ad_account')->value('id'),
            'meta_campaign_id' => "EXIST-CMP-{$n}", 'meta_adset_id' => "EXIST-SET-{$n}", 'meta_ad_id' => "EXIST-AD-{$n}",
            'name' => 'Existing', 'objective' => 'TRAFFIC', 'status' => $status, 'daily_budget' => 10,
        ]);
    }

    private function payload(string $objective = 'TRAFFIC'): array
    {
        return [
            'campaign_name' => 'Monsoon', 'objective' => $objective, 'daily_budget' => 25, 'cpl_threshold' => 5,
            'targeting_specs' => ['countries' => ['IN'], 'age_min' => 18, 'age_max' => 45],
            'creative' => ['image_url' => null, 'headline' => 'Hi', 'primary_text' => 'Buy now'],
        ];
    }

    private function launch(User $actor, array $headers = [], string $query = '', ?array $payload = null)
    {
        return $this->actingAs($actor)->postJson('/api/social/ads/launch'.$query, $payload ?? $this->payload(), $headers);
    }

    /** @return list<string> Graph API writes only */
    private function writes(): array
    {
        return array_values(array_filter($this->calls, fn (string $c) => str_starts_with($c, 'POST /v19.0/')));
    }

    /** @return list<array{0: string, 1: string}> */
    private function adRoutes(AdCampaign $campaign): array
    {
        return [
            ['GET', '/api/social/ads'],
            ['POST', '/api/social/ads/launch'],
            ['POST', "/api/social/ads/{$campaign->id}/pause"],
            ['POST', "/api/social/ads/{$campaign->id}/resume"],
            ['PATCH', "/api/social/ads/{$campaign->id}/cpl-threshold"],
            ['GET', '/api/social/ads/attribution'],
            ['GET', '/api/social/ads/attribution/summary'],
        ];
    }

    // ================================================================== entitlement (tenant)

    public function test_a_tenant_without_the_ads_capability_is_refused_on_every_ads_route(): void
    {
        $account = $this->tenant(grants: []);
        $campaign = $this->campaign($account);
        $user = $this->user($account);

        foreach ($this->adRoutes($campaign) as [$method, $uri]) {
            $this->actingAs($user)->json($method, $uri, $this->payload())
                ->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        }

        $this->assertSame([], $this->calls);
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $campaign->fresh()->status);
        $this->assertSame(1, AdCampaign::count());
    }

    public function test_the_social_capability_does_not_unlock_ads(): void
    {
        $account = $this->tenant(grants: ['social']);

        $this->actingAs($this->user($account))->getJson('/api/social/ads')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->launch($this->user($account))->assertForbidden();
        $this->assertSame([], $this->calls);
    }

    public function test_the_meta_ads_module_switched_off_blocks_ads_even_with_the_capability(): void
    {
        $account = $this->tenant();
        $account->forceFill(['allowed_modules' => ['campaigns']])->save();
        $user = $this->user($account);

        $this->actingAs($user)->getJson('/api/social/ads')->assertForbidden();
        $this->launch($user)->assertForbidden();
        $this->assertSame(0, AdCampaign::count());
        $this->assertSame([], $this->calls);
    }

    public function test_a_view_only_user_can_list_but_not_launch_pause_or_resume(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        $viewer = $this->viewer($account);

        $this->actingAs($viewer)->getJson('/api/social/ads')->assertOk()->assertJsonPath('data.0.id', $campaign->id);
        $this->launch($viewer)->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/social/ads/{$campaign->id}/pause")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/social/ads/{$campaign->id}/resume")->assertForbidden();
        $this->assertSame([], $this->calls);
    }

    public function test_an_expired_subscription_keeps_reads_and_blocks_every_write(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);
        $user = $this->user($account);

        $this->actingAs($user)->getJson('/api/social/ads')->assertOk();
        // Phase 10 Task 1's attribution contract (kept unchanged): the target check denies an expired subscription on reads too.
        $this->actingAs($user)->getJson('/api/social/ads/attribution/summary')->assertForbidden()->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        $this->launch($user)->assertForbidden()->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/pause")->assertForbidden()->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        $this->actingAs($user)->patchJson("/api/social/ads/{$campaign->id}/cpl-threshold", ['cpl_threshold' => 1])->assertForbidden();

        $this->assertSame([], $this->calls);
        $this->assertSame(1, AdCampaign::count());
    }

    // ================================================================== entitlement (Super Admin target)

    public function test_super_admin_must_select_a_client_on_every_ads_route(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        $admin = $this->superAdmin();

        foreach ($this->adRoutes($campaign) as [$method, $uri]) {
            $this->actingAs($admin)->json($method, $uri, $this->payload())->assertStatus(422);
        }

        $this->assertSame([], $this->calls);
        $this->assertSame(1, AdCampaign::count());
    }

    public function test_super_admin_skips_the_clients_plan_but_not_suspension_or_module(): void
    {
        $admin = $this->superAdmin();
        $noAds = $this->tenant(grants: []);
        $moduleOff = $this->tenant();
        $moduleOff->forceFill(['allowed_modules' => ['campaigns']])->save();
        $suspended = $this->tenant();
        $suspended->forceFill(['status' => 'suspended'])->save();

        // Owner decision (2026-09-30): the client's plan (`ads`) does not bind a Super Admin — suspension and module switches still do.
        $this->actingAs($admin)->getJson("/api/social/ads?account_id={$noAds->id}")->assertOk();

        foreach ([[$moduleOff, 'MODULE_DISABLED'], [$suspended, 'CLIENT_ACCOUNT_SUSPENDED']] as [$client, $code]) {
            $this->actingAs($admin)->getJson("/api/social/ads?account_id={$client->id}")->assertForbidden()->assertJsonPath('error_code', $code);
            $this->launch($admin, [], "?account_id={$client->id}")->assertForbidden()->assertJsonPath('error_code', $code);
        }

        $this->assertSame([], $this->calls);
        $this->assertSame(0, AdCampaign::count());
    }

    public function test_super_admin_on_an_expired_client_can_read_and_write(): void
    {
        $admin = $this->superAdmin();
        $client = $this->tenant();
        $campaign = $this->campaign($client);
        Subscription::where('account_id', $client->id)->update(['expires_at' => now()->subDay()]);

        $this->actingAs($admin)->getJson("/api/social/ads?account_id={$client->id}")->assertOk()->assertJsonPath('data.0.id', $campaign->id);
        // Owner decision (2026-09-30): a lapsed subscription does not stop a Super Admin (suspension and module switches still do).
        $this->launch($admin, [], "?account_id={$client->id}")->assertCreated();
        $this->actingAs($admin)->postJson("/api/social/ads/{$campaign->id}/pause?account_id={$client->id}")->assertOk()->assertJsonPath('data.status', 'PAUSED');
        // Its own users stay read-only.
        $this->launch($this->user($client))->assertForbidden()->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_super_admin_launches_for_the_selected_client_with_that_clients_assets_only(): void
    {
        $admin = $this->superAdmin();
        $client = $this->tenant();
        $other = $this->tenant();

        $this->launch($admin, [], "?account_id={$client->id}")->assertCreated()->assertJsonPath('data.status', 'ACTIVE');

        $campaign = AdCampaign::sole();
        $this->assertSame($client->id, $campaign->account_id);
        $this->assertSame($client->id, $campaign->socialAccount->account_id);
        $this->assertStringContainsString('/act_'.$client->id.'00/campaigns', $this->writes()[0]);
        $this->assertSame([], array_filter($this->calls, fn ($c) => str_contains($c, 'act_'.$other->id.'00')));
    }

    // ================================================================== ownership / isolation

    public function test_a_tenant_cannot_touch_another_tenants_campaign(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $campaign = $this->campaign($theirs);
        $user = $this->user($mine);

        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/pause")->assertNotFound();
        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/resume?account_id={$theirs->id}")->assertNotFound();
        $this->actingAs($user)->patchJson("/api/social/ads/{$campaign->id}/cpl-threshold", ['cpl_threshold' => 1])->assertNotFound();
        $this->actingAs($user)->getJson("/api/social/ads?account_id={$theirs->id}")->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame([], $this->calls);
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $campaign->fresh()->status);
    }

    public function test_a_launch_never_uses_another_tenants_ad_account(): void
    {
        $mine = $this->tenant(assets: false);
        $this->tenant(); // has an Ad Account and a Page

        $this->launch($this->user($mine))->assertStatus(422);

        $this->assertSame([], $this->calls);
        $this->assertSame(0, AdCampaign::count());
    }

    public function test_a_campaign_cannot_be_saved_against_another_tenants_ad_account(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $foreignAdAccount = SocialAccount::forAccount($theirs->id)->where('asset_type', 'meta_ad_account')->value('id');

        $this->expectException(LogicException::class);
        $this->campaign($mine, attributes: ['social_account_id' => $foreignAdAccount]);
    }

    public function test_the_same_meta_ad_id_in_two_tenants_never_crosses_attribution(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $campaignA = $this->campaign($a, attributes: ['meta_ad_id' => 'AD-SHARED-1', 'meta_campaign_id' => 'C-A']);
        $this->campaign($b, attributes: ['meta_ad_id' => 'AD-SHARED-1', 'meta_campaign_id' => 'C-B']);

        $attribution = app(AdAttributionService::class)->recordReferral(
            $a->id, AdAttribution::PROVIDER_META, AdAttribution::CHANNEL_WHATSAPP_CTWA, 'wamid.shared', '919800000001',
            ['source_id' => 'AD-SHARED-1', 'source_type' => 'ad'], null, null, null,
        );

        $this->assertSame($campaignA->id, $attribution->ad_campaign_id);
    }

    // ================================================================== launch lifecycle

    public function test_a_successful_launch_goes_active_with_every_meta_id_and_no_attribution_row(): void
    {
        $account = $this->tenant();

        $response = $this->launch($this->user($account))->assertCreated()->assertJsonPath('data.status', 'ACTIVE');

        $campaign = AdCampaign::sole();
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $campaign->status);
        $this->assertNotNull($campaign->meta_campaign_id);
        $this->assertNotNull($campaign->meta_adset_id);
        $this->assertNotNull($campaign->meta_ad_id);
        $this->assertCount(4, $this->writes());
        $this->assertSame(0, AdAttribution::count());
        $this->assertStringNotContainsString('EAA-token', $response->getContent());

        // A later click-to-WhatsApp referral for this ad still resolves to it (attribution compatibility).
        $attribution = app(AdAttributionService::class)->recordReferral(
            $account->id, AdAttribution::PROVIDER_META, AdAttribution::CHANNEL_WHATSAPP_CTWA, 'wamid.after-launch', '919800000002',
            ['source_id' => $campaign->meta_ad_id, 'source_type' => 'ad'], null, null, null,
        );
        $this->assertSame($campaign->id, $attribution->ad_campaign_id);
    }

    public function test_a_rejection_at_the_first_step_is_failed_with_no_meta_ids_and_no_raw_provider_text(): void
    {
        $account = $this->tenant();
        $this->meta['#/campaigns$#'] = [400, ['error' => ['message' => 'Raw Meta detail fbtrace_id=XYZ', 'code' => 100]]];

        $this->launch($this->user($account))->assertStatus(422);

        $campaign = AdCampaign::sole();
        $this->assertSame(AdCampaign::STATUS_FAILED, $campaign->status);
        $this->assertNull($campaign->meta_campaign_id);
        $this->assertSame('Meta rejected the launch (HTTP 400, code 100).', $campaign->last_provider_error);
        $this->assertCount(1, $this->writes());
    }

    public function test_a_rejection_after_the_campaign_exists_keeps_the_meta_objects_for_review(): void
    {
        $account = $this->tenant();
        $this->meta['#^POST .*/ads$#'] = [400, ['error' => ['message' => 'bad creative', 'code' => 100]]];

        $this->launch($this->user($account))->assertStatus(422);

        $campaign = AdCampaign::sole();
        $this->assertSame(AdCampaign::STATUS_FAILED, $campaign->status);
        $this->assertNotNull($campaign->meta_campaign_id);
        $this->assertNotNull($campaign->meta_adset_id);
        $this->assertNull($campaign->meta_ad_id);
        $this->assertCount(4, $this->writes());
    }

    public function test_a_lost_response_is_unconfirmed_and_never_resent(): void
    {
        $account = $this->tenant();
        $this->meta['#/adsets$#'] = 'down';

        $this->launch($this->user($account))
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'AD_PROVIDER_OUTCOME_UNKNOWN')
            ->assertJsonPath('data.status', 'UNCONFIRMED');

        $campaign = AdCampaign::sole();
        $this->assertSame(AdCampaign::STATUS_UNCONFIRMED, $campaign->status);
        $this->assertNotNull($campaign->meta_campaign_id);
        $this->assertCount(2, $this->writes(), 'campaign + one adset attempt, no retry');
    }

    public function test_a_meta_server_error_is_treated_as_an_unknown_outcome(): void
    {
        $account = $this->tenant();
        $this->meta['#/campaigns$#'] = [503, ['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]]];

        $this->launch($this->user($account))->assertStatus(502)->assertJsonPath('error_code', 'AD_PROVIDER_OUTCOME_UNKNOWN');

        $this->assertSame(AdCampaign::STATUS_UNCONFIRMED, AdCampaign::sole()->status);
        $this->assertCount(1, $this->writes());
    }

    public function test_missing_local_prerequisites_are_refused_before_any_meta_write(): void
    {
        $noPage = $this->tenant(assets: false);
        $this->asset($noPage, 'meta_ad_account', 'act_nopage');
        $this->launch($this->user($noPage))->assertStatus(422)->assertJsonPath('message', 'A connected Facebook Page is required to build ad creative. Connect one from the Social Hub first.');

        $noNumber = $this->tenant();
        $this->launch($this->user($noNumber), [], '', $this->payload('CLICK_TO_WHATSAPP'))->assertStatus(422)
            ->assertJsonPath('message', 'No WhatsApp Business phone number is configured for this tenant. Configure one under WhatsApp > Meta Config first.');

        $this->assertSame([], $this->calls);
        $this->assertSame(0, AdCampaign::count());
    }

    public function test_a_ctwa_launch_promotes_the_tenants_own_number(): void
    {
        $account = $this->tenant();
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected', 'meta_phone_number_id' => '109811112222', 'meta_webhook_verify_token' => 'V'.uniqid()]);

        $this->launch($this->user($account), [], '', $this->payload('CLICK_TO_WHATSAPP'))->assertCreated();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/adsets')
            && str_contains((string) $r['promoted_object'], '109811112222'));
    }

    // ================================================================== idempotency

    public function test_a_repeated_idempotency_key_replays_without_calling_meta(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);

        $first = $this->launch($user, ['Idempotency-Key' => 'launch-abc-1'])->assertCreated();
        $writes = count($this->writes());

        $this->launch($user, ['Idempotency-Key' => 'launch-abc-1'])
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame($writes, count($this->writes()));
        $this->assertSame(1, AdCampaign::count());
    }

    public function test_a_repeated_key_after_a_failed_or_unconfirmed_launch_is_not_resent(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);

        $this->meta['#/campaigns$#'] = [400, ['error' => ['message' => 'x', 'code' => 100]]];
        $this->launch($user, ['Idempotency-Key' => 'k-failed'])->assertStatus(422);
        $this->meta['#/campaigns$#'] = 'down';
        $this->launch($user, ['Idempotency-Key' => 'k-lost'])->assertStatus(502);
        $this->meta = [];
        $writes = count($this->writes());

        $this->launch($user, ['Idempotency-Key' => 'k-failed'])->assertStatus(422)->assertJsonPath('error_code', 'AD_LAUNCH_FAILED');
        $this->launch($user, ['Idempotency-Key' => 'k-lost'])->assertStatus(502)->assertJsonPath('error_code', 'AD_PROVIDER_OUTCOME_UNKNOWN');

        $this->assertSame($writes, count($this->writes()));
        $this->assertSame(2, AdCampaign::count());
    }

    public function test_a_key_whose_launch_is_still_running_is_refused_as_in_progress(): void
    {
        $account = $this->tenant();
        $this->campaign($account, AdCampaign::STATUS_LAUNCHING, ['launch_request_id' => 'k-running', 'meta_campaign_id' => null, 'meta_adset_id' => null, 'meta_ad_id' => null]);

        $this->launch($this->user($account), ['Idempotency-Key' => 'k-running'])->assertStatus(409)->assertJsonPath('error_code', 'AD_LAUNCH_IN_PROGRESS');
        $this->assertSame([], $this->calls);
    }

    public function test_idempotency_keys_are_scoped_per_tenant(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->launch($this->user($a), ['Idempotency-Key' => 'same-key'])->assertCreated();
        $this->launch($this->user($b), ['Idempotency-Key' => 'same-key'])->assertCreated();

        $this->assertSame(1, AdCampaign::forAccount($a->id)->count());
        $this->assertSame(1, AdCampaign::forAccount($b->id)->count());
    }

    public function test_a_malformed_idempotency_key_is_rejected_before_meta(): void
    {
        $account = $this->tenant();

        $this->launch($this->user($account), ['Idempotency-Key' => 'bad key!'])->assertStatus(422);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, AdCampaign::count());
    }

    // ================================================================== pause / resume

    public function test_pause_and_resume_are_single_confirmed_idempotent_transitions(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account, attributes: []);
        $campaign->forceFill(['auto_paused_at' => null])->save();
        $user = $this->user($account);

        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/pause")->assertOk()->assertJsonPath('message', 'Campaign paused.')->assertJsonPath('data.status', 'PAUSED');
        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/pause")->assertOk()->assertJsonPath('message', 'Campaign is already paused.');
        $this->assertCount(1, $this->writes());

        $campaign->forceFill(['auto_paused_at' => now(), 'auto_pause_reason' => 'CPL'])->save();
        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/resume")->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.auto_paused_at', null);
        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/resume")->assertOk()->assertJsonPath('message', 'Campaign is already active.');
        $this->assertCount(2, $this->writes());
        $this->assertSame(['POST /v19.0/'.$campaign->meta_campaign_id, 'POST /v19.0/'.$campaign->meta_campaign_id], $this->writes());
    }

    public function test_pause_or_resume_of_a_campaign_that_never_launched_is_refused_without_meta(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);

        foreach ([AdCampaign::STATUS_LAUNCHING, AdCampaign::STATUS_FAILED, AdCampaign::STATUS_UNCONFIRMED] as $status) {
            $campaign = $this->campaign($account, $status, ['meta_campaign_id' => null, 'meta_adset_id' => null, 'meta_ad_id' => null]);
            $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/pause")->assertStatus(409)->assertJsonPath('error_code', 'AD_CAMPAIGN_INVALID_STATE');
            $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/resume")->assertStatus(409)->assertJsonPath('error_code', 'AD_CAMPAIGN_INVALID_STATE');
            $this->assertSame($status, $campaign->fresh()->status);
        }

        $this->assertSame([], $this->calls);
    }

    public function test_a_concurrent_change_is_refused_instead_of_calling_meta_twice(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        $held = Cache::lock("ad-campaign-status:{$campaign->id}", 60);
        $this->assertTrue($held->get());

        $this->actingAs($this->user($account))->postJson("/api/social/ads/{$campaign->id}/pause")->assertStatus(409)->assertJsonPath('error_code', 'AD_CAMPAIGN_BUSY');

        $held->release();
        $this->assertSame([], $this->calls);
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $campaign->fresh()->status);
    }

    public function test_a_lost_pause_response_leaves_the_status_unchanged_and_is_not_retried(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        $this->meta['#^POST /v19.0/EXIST-CMP-\d+$#'] = 'down';

        $this->actingAs($this->user($account))->postJson("/api/social/ads/{$campaign->id}/pause")
            ->assertStatus(502)->assertJsonPath('error_code', 'AD_PROVIDER_OUTCOME_UNKNOWN')->assertJsonPath('data.status', 'ACTIVE');

        $this->assertSame(AdCampaign::STATUS_ACTIVE, $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->last_provider_error);
        $this->assertCount(1, $this->writes());
    }

    public function test_an_ordinary_rejection_of_a_pause_keeps_the_status(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        $this->meta['#^POST /v19.0/EXIST-CMP-\d+$#'] = [400, ['error' => ['message' => 'Raw detail fbtrace_id=Q', 'code' => 2635]]];

        $this->actingAs($this->user($account))->postJson("/api/social/ads/{$campaign->id}/pause")->assertStatus(422);

        $this->assertSame(AdCampaign::STATUS_ACTIVE, $campaign->fresh()->status);
        $this->assertSame('Meta rejected the status change (HTTP 400, code 2635).', $campaign->fresh()->last_provider_error);
    }

    public function test_a_campaign_deleted_at_meta_becomes_unavailable_and_is_left_alone(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        $user = $this->user($account);
        $this->meta['#^POST /v19.0/EXIST-CMP-\d+$#'] = [400, ['error' => ['message' => 'Object does not exist', 'code' => 100, 'error_subcode' => 33]]];

        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/pause")->assertStatus(409)->assertJsonPath('error_code', 'AD_CAMPAIGN_UNAVAILABLE')->assertJsonPath('data.status', 'UNAVAILABLE');
        $this->assertSame(AdCampaign::STATUS_UNAVAILABLE, $campaign->fresh()->status);

        $this->actingAs($user)->postJson("/api/social/ads/{$campaign->id}/resume")->assertStatus(409)->assertJsonPath('error_code', 'AD_CAMPAIGN_UNAVAILABLE');
        $this->assertCount(1, $this->writes());

        // A deleted Ad Account connection is also a safe state: no token, no Meta call.
        $other = $this->campaign($account);
        $other->socialAccount->delete();
        $this->actingAs($user)->postJson("/api/social/ads/{$other->id}/pause")->assertStatus(422);
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $other->fresh()->status);
        $this->assertCount(1, $this->writes());
    }

    // ================================================================== auto-pause rule

    public function test_the_auto_pause_rule_uses_the_guarded_transition(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account, attributes: ['daily_budget' => 5]);
        $locked = $this->campaign($account, attributes: ['daily_budget' => 5]);
        $this->meta['#/insights$#'] = [200, ['data' => [['spend' => '9.00', 'impressions' => '100', 'actions' => []]]]];
        $held = Cache::lock("ad-campaign-status:{$locked->id}", 60);
        $held->get();

        $this->artisan('ads:check-performance-rules')->assertSuccessful();
        $held->release();

        $this->assertSame(AdCampaign::STATUS_PAUSED, $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->auto_paused_at);
        $this->assertStringContainsString('Zero conversions', $campaign->fresh()->auto_pause_reason);
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $locked->fresh()->status, 'a campaign mid-change is skipped this cycle');
        $this->assertSame(['POST /v19.0/'.$campaign->meta_campaign_id], $this->writes());
    }

    // ================================================================== plan management

    public function test_enabling_and_disabling_ads_in_the_plan_drives_enforcement(): void
    {
        $account = Account::factory()->create();
        $plan = PlanCatalog::find('business');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'business',
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        $user = $this->user($account->fresh());
        $admin = $this->superAdmin();
        $bundle = Plan::where('slug', 'business')->firstOrFail()->capabilities->pluck('slug')->all();

        $this->actingAs($user)->getJson('/api/social/ads')->assertOk();
        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('user.capabilities.ads', true);

        $this->actingAs($admin)->putJson('/api/admin/plans-management/business', ['capabilities' => array_values(array_diff($bundle, ['ads']))])->assertOk();
        $this->actingAs($user)->getJson('/api/social/ads')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('user.capabilities.ads', false);

        $this->actingAs($admin)->putJson('/api/admin/plans-management/business', ['capabilities' => $bundle])->assertOk();
        $this->actingAs($user)->getJson('/api/social/ads')->assertOk();
        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('user.capabilities.ads', true);
    }

    public function test_ads_cannot_be_granted_to_a_qr_engine_account(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]); // QR engine

        $this->actingAs($this->superAdmin())->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'ads'])->assertStatus(422);
        $this->assertFalse(AccountEntitlement::where('account_id', $account->id)->exists());
    }
}
