<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\SocialAccount;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ads Phase C (2026-10-15): a client without a Meta ad account creates one from
 * WapHub — in one of their own Business portfolios, in INR on India time — inside
 * the open connect attempt, and connects it with the rest of that attempt.
 */
class MetaAdAccountCreateTest extends TestCase
{
    use RefreshDatabase;

    private const NONCE = 'nonce-123';

    /** @var array{0: int, 1: array<string, mixed>} */
    private array $create = [200, ['id' => 'act_555', 'account_id' => '555']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app', 'client_secret' => 'secret', 'redirect_uri' => 'https://api.example.test/api/social/callback/meta', 'is_active' => true]);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/me/businesses') => Http::response(['data' => [['id' => '9001', 'name' => 'Shana Trends'], ['id' => '9002', 'name' => 'Side Biz']]]),
                str_ends_with($path, '/adaccount') => Http::response($this->create[1], $this->create[0]),
                default => Http::response(['success' => true]),
            };
        });
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

    private function user(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function pending(Account $account, User $user, bool $planHasAds = true): void
    {
        Cache::put('social_oauth_pending:'.self::NONCE, [
            'account_id' => $account->id, 'user_id' => $user->id, 'provider' => 'meta',
            'access_token' => 'USER-TOKEN', 'refresh_token' => null, 'expires_in' => 5184000,
            'assets' => [['asset_type' => 'facebook_page', 'provider_id' => 'page-1', 'name' => 'Shana', 'avatar_url' => null, 'access_token' => 'PAGE-TOKEN']],
            'summary' => ['found' => ['facebook_page' => 1, 'instagram' => 0, 'meta_ad_account' => 0], 'enabled' => ['facebook_page', 'instagram', 'meta_ad_account'], 'plan_has_ads' => $planHasAds],
        ], now()->addMinutes(10));
    }

    public function test_it_lists_the_users_business_portfolios_for_the_open_attempt(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $this->pending($account, $user);

        $this->actingAs($user)->getJson('/api/social/oauth/meta/businesses?nonce='.self::NONCE)->assertOk()
            ->assertExactJson(['data' => [['id' => '9001', 'name' => 'Shana Trends'], ['id' => '9002', 'name' => 'Side Biz']]]);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/me/businesses') && $r->hasHeader('Authorization', 'Bearer USER-TOKEN'));
    }

    public function test_it_creates_an_inr_india_time_ad_account_and_offers_it_for_connecting(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $this->pending($account, $user);

        $this->actingAs($user)->postJson('/api/social/oauth/meta/ad-accounts', ['nonce' => self::NONCE, 'business_id' => '9001', 'name' => 'Shana Ads'])
            ->assertCreated()
            ->assertJsonPath('data', ['asset_type' => 'meta_ad_account', 'provider_id' => 'act_555', 'name' => 'Shana Ads', 'avatar_url' => null]);

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn (HttpRequest $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/9001/adaccount'));
        $this->assertNotNull($sent);
        $this->assertSame('POST', $sent->method());
        $this->assertEquals(['name' => 'Shana Ads', 'currency' => 'INR', 'timezone_id' => 71, 'end_advertiser' => 'NONE', 'media_agency' => 'NONE', 'partner' => 'NONE'], $sent->data());

        // The attempt now offers it, and the normal bind connects it with the Page.
        $this->actingAs($user)->getJson('/api/social/oauth/meta/result/'.self::NONCE)->assertOk()
            ->assertJsonPath('summary.found.meta_ad_account', 1)
            ->assertJsonFragment(['asset_type' => 'meta_ad_account', 'provider_id' => 'act_555']);
        $this->actingAs($user)->postJson('/api/social/accounts/bind', ['nonce' => self::NONCE, 'selections' => [
            ['asset_type' => 'facebook_page', 'provider_id' => 'page-1'],
            ['asset_type' => 'meta_ad_account', 'provider_id' => 'act_555'],
        ]])->assertCreated();

        $adAccount = SocialAccount::where('account_id', $account->id)->where('asset_type', 'meta_ad_account')->sole();
        $this->assertSame('act_555', $adAccount->provider_id);
        $this->assertSame('USER-TOKEN', $adAccount->access_token);
    }

    public function test_only_the_user_who_started_the_attempt_and_their_own_business_can_be_used(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $this->pending($account, $user);

        $this->actingAs($this->user($account))->postJson('/api/social/oauth/meta/ad-accounts', ['nonce' => self::NONCE, 'business_id' => '9001', 'name' => 'Someone else'])
            ->assertStatus(422)->assertJsonValidationErrors('nonce');
        $this->actingAs($user)->postJson('/api/social/oauth/meta/ad-accounts', ['nonce' => self::NONCE, 'business_id' => '7777', 'name' => 'Shana Ads'])
            ->assertStatus(422)->assertJsonValidationErrors('business_id');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/adaccount'));
    }

    public function test_metas_refusals_are_explained(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $this->pending($account, $user);

        $this->create = [400, ['error' => ['code' => 3979, 'message' => 'raw']]];
        $this->actingAs($user)->postJson('/api/social/oauth/meta/ad-accounts', ['nonce' => self::NONCE, 'business_id' => '9001', 'name' => 'Shana Ads'])
            ->assertStatus(422)->assertJsonPath('error_code', 'META_AD_ACCOUNT_NOT_CREATED')
            ->assertJsonPath('message', 'Meta does not allow more ad accounts for this Business portfolio right now (limit reached). Use an existing ad account, or ask Meta support to raise the limit.');

        $this->create = [400, ['error' => ['code' => 200, 'message' => 'raw']]];
        $this->assertStringContainsString('admin of the Business portfolio', $this->actingAs($user)->postJson('/api/social/oauth/meta/ad-accounts', ['nonce' => self::NONCE, 'business_id' => '9001', 'name' => 'Shana Ads'])->json('message'));

        $this->assertFalse(collect(Cache::get('social_oauth_pending:'.self::NONCE)['assets'])->contains('asset_type', 'meta_ad_account'));
    }

    public function test_an_attempt_bound_meanwhile_is_not_brought_back(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $this->pending($account, $user);
        // The user presses Connect while Meta is still creating the account.
        $this->create = [200, ['id' => 'act_555', 'account_id' => '555']];
        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/adaccount')) {
                Cache::forget('social_oauth_pending:'.self::NONCE);

                return Http::response(['id' => 'act_555', 'account_id' => '555']);
            }

            return Http::response(['data' => [['id' => '9001', 'name' => 'Shana Trends']]]);
        });

        $this->actingAs($user)->postJson('/api/social/oauth/meta/ad-accounts', ['nonce' => self::NONCE, 'business_id' => '9001', 'name' => 'Shana Ads'])
            ->assertStatus(409)->assertJsonPath('error_code', 'SOCIAL_CONNECT_ATTEMPT_ENDED');
        $this->assertNull(Cache::get('social_oauth_pending:'.self::NONCE), 'the used grant (and its token) stays gone');
    }

    public function test_a_plan_without_ads_cannot_create_one(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $this->pending($account, $user, planHasAds: false);

        $this->actingAs($user)->postJson('/api/social/oauth/meta/ad-accounts', ['nonce' => self::NONCE, 'business_id' => '9001', 'name' => 'Shana Ads'])
            ->assertForbidden()->assertJsonPath('error_code', 'META_ADS_NOT_ENABLED');
        Http::assertNothingSent();
    }
}
