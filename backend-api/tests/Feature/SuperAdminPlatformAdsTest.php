<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AdCampaign;
use App\Models\Capability;
use App\Models\OrganicPost;
use App\Models\SocialAccount;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Crm\PlatformCrmAccount;
use App\Services\Social\SocialTargetGate;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner requests of 2026-09-30:
 *  - a Super Admin with no client selected works on its own "Platform (Super
 *    Admin)" account (connect its own Meta assets, post, launch ads);
 *  - with a client selected, a Super Admin is not held to the client's plan
 *    or subscription (suspension and module switches still block);
 *  - ads: Meta location search (country / region / city), manual placements
 *    (Facebook / Instagram feed, stories, reels), the button (CTA) chosen per
 *    ad, budgets in the ad account's own currency, and the ad account's
 *    status / spend cap checked before any Meta write.
 */
class SuperAdminPlatformAdsTest extends TestCase
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

    // ------------------------------------------------------------------ fixtures

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function platform(): Account
    {
        return app(PlatformCrmAccount::class)->ensure()['account'];
    }

    private function tenant(array $grants = ['ads', 'social']): Account
    {
        $account = Account::factory()->create(['allow_facebook' => true, 'allow_instagram' => true]);
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach ($grants as $slug) {
            AccountEntitlement::firstOrCreate(['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')], ['source' => 'manual_grant']);
        }

        return $account->fresh();
    }

    private function metaAssets(Account $account, bool $instagram = false): void
    {
        foreach (['meta_ad_account' => 'act_'.$account->id.'00', 'facebook_page' => 'page-'.$account->id] + ($instagram ? ['instagram' => 'IG-'.$account->id] : []) as $type => $id) {
            SocialAccount::create(['account_id' => $account->id, 'provider' => 'meta', 'asset_type' => $type, 'provider_id' => $id, 'name' => $type,
                'access_token' => 'EAA-token', 'health_status' => SocialAccount::HEALTH_CONNECTED]);
        }
    }

    private function user(Account $account, string $role = 'social_marketer'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'campaign_name' => 'Diwali', 'objective' => 'LEAD_GENERATION', 'daily_budget' => 500,
            'targeting_specs' => ['countries' => ['IN'], 'age_min' => 18, 'age_max' => 45],
            'creative' => ['image_url' => null, 'headline' => 'Hi', 'primary_text' => 'Buy'],
        ], $overrides);
    }

    private function sentTo(string $suffix): ?HttpRequest
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), $suffix));
    }

    private function adAccountIs(array $fields): void
    {
        $this->graph['#^GET /v19\.0/act_\d+$#'] = $fields;
    }

    // ================================================================== Super Admin platform account

    public function test_super_admin_without_a_client_works_on_the_platform_account(): void
    {
        $platform = $this->platform();
        $this->metaAssets($platform);
        $admin = $this->superAdmin();
        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app', 'client_secret' => 'secret', 'redirect_uri' => 'https://api.example.test/api/social/callback/meta', 'is_active' => true]);

        $this->actingAs($admin)->getJson('/api/social/accounts')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($admin)->getJson('/api/social/oauth/meta/redirect')->assertOk();
        $this->actingAs($admin)->postJson('/api/social/ads/launch', $this->payload())->assertCreated();
        $this->actingAs($admin)->postJson('/api/social/organic-posts', ['platform' => 'facebook', 'caption' => 'Hello', 'scheduled_at' => now()->addHour()->toIso8601String()])->assertCreated();
        $this->actingAs($admin)->getJson('/api/social/ads/dashboard')->assertOk()->assertJsonPath('data.campaigns_summary.total', 1);

        $this->assertSame($platform->id, AdCampaign::sole()->account_id);
        $this->assertSame($platform->id, OrganicPost::sole()->account_id);
    }

    public function test_without_a_platform_account_a_super_admin_still_has_to_pick_a_client(): void
    {
        $this->tenant();

        $this->actingAs($this->superAdmin())->getJson('/api/social/ads')->assertStatus(422);
    }

    public function test_the_platform_account_may_connect_every_meta_asset(): void
    {
        $platform = $this->platform();

        $this->assertTrue($platform->hasSocialPlatformEnabled('facebook_page'));
        $this->assertTrue($platform->hasSocialPlatformEnabled('instagram'));
        $this->assertTrue($platform->hasSocialPlatformEnabled('meta_ad_account'));
    }

    // ================================================================== Super Admin on a client

    public function test_super_admin_is_not_held_to_a_clients_plan_or_subscription_but_is_to_suspension_and_modules(): void
    {
        $admin = $this->superAdmin();
        $noPlan = $this->tenant(grants: []);
        $this->metaAssets($noPlan);
        $expired = $this->tenant();
        $this->metaAssets($expired);
        Subscription::where('account_id', $expired->id)->update(['expires_at' => now()->subDay()]);
        $suspended = $this->tenant();
        $suspended->forceFill(['status' => 'suspended'])->save();
        $moduleOff = $this->tenant();
        $moduleOff->forceFill(['allowed_modules' => ['campaigns']])->save();

        $this->actingAs($admin)->postJson("/api/social/ads/launch?account_id={$noPlan->id}", $this->payload())->assertCreated();
        $this->actingAs($admin)->postJson("/api/social/ads/launch?account_id={$expired->id}", $this->payload())->assertCreated();
        $this->actingAs($admin)->postJson("/api/social/ads/launch?account_id={$suspended->id}", $this->payload())->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
        $this->actingAs($admin)->postJson("/api/social/ads/launch?account_id={$moduleOff->id}", $this->payload())->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');

        // Tenants keep every check.
        $this->actingAs($this->user($noPlan))->getJson('/api/social/ads')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->actingAs($this->user($expired))->postJson('/api/social/ads/launch', $this->payload())->assertForbidden()->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_a_post_a_super_admin_scheduled_keeps_the_plan_bypass_in_the_worker(): void
    {
        $client = $this->tenant(grants: []);
        $gate = app(SocialTargetGate::class);

        $this->assertNull($gate->denial($client, 'publish social posts', true));
        $this->assertSame('CAPABILITY_NOT_ENTITLED', $gate->denial($client, 'publish social posts', false)['code']);

        $client->forceFill(['status' => 'suspended'])->save();
        $this->assertSame('CLIENT_ACCOUNT_SUSPENDED', $gate->denial($client, 'publish social posts', true)['code']);
    }

    // ================================================================== locations, placements, CTA

    public function test_location_search_returns_meta_keys_and_needs_a_launch_permission(): void
    {
        $account = $this->tenant();
        $this->metaAssets($account);
        $this->graph['#^GET /v19\.0/search$#'] = ['data' => [
            ['key' => '2295411', 'name' => 'Mumbai', 'type' => 'city', 'country_code' => 'IN', 'country_name' => 'India', 'region' => 'Maharashtra'],
            ['key' => '1740', 'name' => 'Maharashtra', 'type' => 'region', 'country_code' => 'IN', 'country_name' => 'India'],
            ['key' => 'x', 'name' => 'Zip', 'type' => 'zip'],
        ]];

        $this->actingAs($this->user($account))->getJson('/api/social/ads/locations?q=Mum')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', ['key' => '2295411', 'name' => 'Mumbai', 'type' => 'city', 'country_code' => 'IN', 'country_name' => 'India', 'region' => 'Maharashtra']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'type=adgeolocation') && str_contains($r->url(), 'q=Mum'));

        $this->actingAs($this->user($account))->getJson('/api/social/ads/locations?q=M')->assertStatus(422);
        Role::firstOrCreate(['name' => 'ads_viewer', 'guard_name' => 'web'])->syncPermissions(['social_ads.view']);
        $this->actingAs($this->user($account, 'ads_viewer'))->getJson('/api/social/ads/locations?q=Mum')->assertForbidden();
    }

    public function test_locations_and_manual_placements_reach_the_ad_set(): void
    {
        $account = $this->tenant();
        $this->metaAssets($account);

        $this->actingAs($this->user($account))->postJson('/api/social/ads/launch', array_merge($this->payload(), [
            'targeting_specs' => ['age_min' => 18, 'age_max' => 45, 'locations' => [
                ['key' => '2295411', 'type' => 'city', 'name' => 'Mumbai'],
                ['key' => '1740', 'type' => 'region', 'name' => 'Maharashtra'],
                ['key' => 'AE', 'type' => 'country', 'name' => 'UAE'],
            ]],
            'placements' => ['instagram_reels', 'instagram_stories', 'facebook_stories'],
        ]))->assertCreated();

        $targeting = json_decode($this->sentTo('/adsets')['targeting'], true);
        $this->assertEquals(['countries' => ['AE'], 'regions' => [['key' => '1740']], 'cities' => [['key' => '2295411']]], $targeting['geo_locations']);
        $this->assertSame(['instagram', 'facebook'], $targeting['publisher_platforms']);
        $this->assertSame(['reels', 'story'], $targeting['instagram_positions']);
        $this->assertSame(['story'], $targeting['facebook_positions']);
    }

    public function test_automatic_placements_send_no_placement_fields_and_a_location_is_required(): void
    {
        $account = $this->tenant();
        $this->metaAssets($account);
        $user = $this->user($account);

        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload())->assertCreated();
        $targeting = json_decode($this->sentTo('/adsets')['targeting'], true);
        $this->assertArrayNotHasKey('publisher_platforms', $targeting);
        $this->assertSame(['IN'], $targeting['geo_locations']['countries']);

        $this->actingAs($user)->postJson('/api/social/ads/launch', array_merge($this->payload(), ['targeting_specs' => ['countries' => [], 'age_min' => 18, 'age_max' => 45]]))->assertStatus(422);
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['placements' => ['tiktok_feed']]))->assertStatus(422);
        $this->assertSame(1, AdCampaign::count());
    }

    public function test_the_chosen_button_is_sent_and_must_suit_the_objective(): void
    {
        $account = $this->tenant();
        $this->metaAssets($account);
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected', 'meta_phone_number_id' => '1098', 'meta_webhook_verify_token' => 'V'.uniqid()]);
        $user = $this->user($account);

        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['creative' => ['call_to_action' => 'GET_QUOTE']]))->assertCreated();
        $spec = json_decode($this->sentTo('/adcreatives')['object_story_spec'], true);
        $this->assertSame('GET_QUOTE', $spec['link_data']['call_to_action']['type']);

        Http::fake(); // nothing further may reach Meta
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['objective' => 'CLICK_TO_WHATSAPP', 'creative' => ['call_to_action' => 'SIGN_UP']]))
            ->assertStatus(422)->assertJsonPath('message', "The button 'SIGN_UP' is not available for this objective.");
        $this->assertSame(1, AdCampaign::count());
    }

    public function test_the_default_button_stays_the_objectives_previous_one(): void
    {
        $account = $this->tenant();
        $this->metaAssets($account);

        $this->actingAs($this->user($account))->postJson('/api/social/ads/launch', $this->payload())->assertCreated();

        $spec = json_decode($this->sentTo('/adcreatives')['object_story_spec'], true);
        $this->assertSame('SIGN_UP', $spec['link_data']['call_to_action']['type']);
    }

    public function test_the_instagram_identity_is_used_only_when_the_ad_may_run_on_instagram(): void
    {
        $account = $this->tenant();
        $this->metaAssets($account, instagram: true);
        $user = $this->user($account);

        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload())->assertCreated();
        $this->assertSame('IG-'.$account->id, json_decode($this->sentTo('/adcreatives')['object_story_spec'], true)['instagram_actor_id']);

        Http::fake(fn (HttpRequest $r) => Http::response(['id' => 'X'.uniqid()]));
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['campaign_name' => 'FB only', 'placements' => ['facebook_feed']]))->assertCreated();
        $this->assertArrayNotHasKey('instagram_actor_id', json_decode($this->sentTo('/adcreatives')['object_story_spec'], true));
    }

    // ================================================================== currency, status, spend cap

    public function test_ad_account_info_is_reported_in_major_units_of_its_currency(): void
    {
        $account = $this->tenant();
        $this->metaAssets($account);
        $this->adAccountIs(['name' => 'Acme INR', 'currency' => 'INR', 'account_status' => 1, 'amount_spent' => '123456', 'spend_cap' => '0', 'balance' => '5000']);

        $this->actingAs($this->user($account))->getJson('/api/social/ads/account')->assertOk()
            ->assertJsonPath('data.currency', 'INR')
            ->assertJsonPath('data.amount_spent', 1234.56)
            ->assertJsonPath('data.spend_cap', null)
            ->assertJsonPath('data.balance', 50)
            ->assertJsonPath('data.runnable', true)
            ->assertJsonPath('data.account_status_label', 'active');
    }

    public function test_the_budget_is_sent_in_the_ad_accounts_currency_units(): void
    {
        $account = $this->tenant();
        $this->metaAssets($account);
        $user = $this->user($account);

        $this->adAccountIs(['currency' => 'INR', 'account_status' => 1, 'amount_spent' => '0', 'spend_cap' => '0']);
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['daily_budget' => 500]))->assertCreated();
        $this->assertSame('50000', (string) $this->sentTo('/adsets')['daily_budget']);
        $this->assertSame('INR', AdCampaign::sole()->currency);
        $this->actingAs($user)->getJson('/api/social/ads')->assertJsonPath('data.0.currency', 'INR');
        $this->actingAs($user)->getJson('/api/social/ads/dashboard')->assertJsonPath('data.currency', 'INR');

        $this->adAccountIs(['currency' => 'JPY', 'account_status' => 1, 'amount_spent' => '0', 'spend_cap' => '0']);
        Http::fake(function (HttpRequest $r) {
            return str_starts_with($r->method().' '.parse_url($r->url(), PHP_URL_PATH), 'GET /v19.0/act_')
                ? Http::response(['currency' => 'JPY', 'account_status' => 1, 'amount_spent' => '0', 'spend_cap' => '0'])
                : Http::response(['id' => 'J'.uniqid()]);
        });
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['campaign_name' => 'JPY', 'daily_budget' => 500]))->assertCreated();
        $this->assertSame('500', (string) $this->sentTo('/adsets')['daily_budget']);
    }

    public function test_a_disabled_ad_account_or_an_exceeded_spend_cap_is_refused_before_any_meta_write(): void
    {
        $account = $this->tenant();
        $this->metaAssets($account);
        $user = $this->user($account);

        $this->adAccountIs(['currency' => 'INR', 'account_status' => 2]);
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload())->assertStatus(422)
            ->assertJsonPath('message', 'The connected Meta ad account is disabled, so campaigns cannot run. Resolve it in Meta Ads Manager first.');

        $this->adAccountIs(['currency' => 'INR', 'account_status' => 1, 'amount_spent' => '900000', 'spend_cap' => '1000000']);
        $this->actingAs($user)->postJson('/api/social/ads/launch', $this->payload(['daily_budget' => 2000]))->assertStatus(422)
            ->assertJsonPath('message', 'The daily budget (INR 2,000.00) is more than the INR 1,000.00 left before the ad account reaches its spend cap. Lower the budget or raise the spend cap in Meta Ads Manager.');

        $this->assertNull($this->sentTo('/campaigns'));
        $this->assertSame(0, AdCampaign::count());
    }
}
