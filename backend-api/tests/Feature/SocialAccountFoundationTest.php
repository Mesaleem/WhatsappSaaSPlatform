<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\SocialAccount;
use App\Models\SocialProviderConfig;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Services\SocialAuth\Contracts\SocialOAuthProviderInterface;
use App\Services\SocialAuth\MetaOAuthProvider;
use App\Services\SocialAuth\SocialConnectionStatus;
use App\Services\SocialAuth\SocialOAuthProviderFactory;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 9 Task 1 — provider-agnostic social account foundation: provider
 * resolution through SocialOAuthProviderInterface, the connect → callback →
 * bind flow (success, provider failure, user cancellation, tampered/expired
 * state), CSRF/account binding (nonce only via the popup; the initiating
 * user and account must redeem it), target-account authorization (module,
 * `social` capability, provider flags, subscription — also for a Super
 * Admin's selected client), tenant isolation, encrypted credentials never
 * returned, the connection lifecycle (check → expired / revoked) and
 * disconnect. Meta's Graph API is faked at the HTTP boundary.
 */
class SocialAccountFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const SHORT_TOKEN = 'EAA-short-lived-token';

    private const LONG_TOKEN = 'EAA-long-lived-token-60d';

    private const PAGE_TOKEN = 'EAA-page-token';

    /** @var array{me: array{0: int, 1: array<string, mixed>}, code_fails: bool, long_fails: bool} */
    private array $graph;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        config(['services.frontend.url' => 'https://app.example.test']);
        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app-id', 'client_secret' => 'app-secret', 'redirect_uri' => 'https://api.example.test/api/social/callback/meta', 'is_active' => true]);

        $this->graph = ['me' => [200, ['id' => '1']], 'code_fails' => false, 'long_fails' => false];
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/oauth/access_token')) {
                if (($query['grant_type'] ?? null) === 'fb_exchange_token') {
                    return $this->graph['long_fails'] ? Http::response(['error' => ['message' => 'nope']], 400) : Http::response(['access_token' => self::LONG_TOKEN, 'expires_in' => 5184000]);
                }

                return $this->graph['code_fails']
                    ? Http::response(['error' => ['message' => 'Error validating verification code. Secret detail 12345.']], 400)
                    : Http::response(['access_token' => self::SHORT_TOKEN, 'expires_in' => 5400]);
            }

            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [['id' => 'page-1', 'name' => 'Acme Page', 'instagram_business_account' => ['id' => 'ig-1', 'username' => 'acme']]]]);
            }

            if (str_contains($url, '/me/adaccounts')) {
                return Http::response(['data' => [['id' => 'act_1', 'name' => 'Acme Ads', 'account_id' => '1']]]);
            }

            if (str_contains($url, '/page-1')) {
                return Http::response(['access_token' => self::PAGE_TOKEN, 'id' => 'page-1']);
            }

            if (preg_match('#/v18\.0/me\?#', $url) || str_ends_with(parse_url($url, PHP_URL_PATH) ?? '', '/me')) {
                return Http::response($this->graph['me'][1], $this->graph['me'][0]);
            }

            return Http::response([], 404);
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(array $attributes = [], string $plan = 'growth'): Account
    {
        $account = Account::factory()->create($attributes + ['allow_facebook' => true, 'allow_instagram' => true]);
        $p = PlanCatalog::find($plan);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $plan,
            'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0,
            'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return $account->fresh();
    }

    private function user(?Account $account, string|array $roles = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole((array) $roles);

        return $user->fresh();
    }

    private function revokeSocial(Account $account): void
    {
        AccountEntitlement::where('account_id', $account->id)->where('capability_id', Capability::where('slug', 'social')->value('id'))->delete();
    }

    /** @return array{url: string, state: array<string, mixed>, raw: string} */
    private function startConnect(User $actor, string $query = ''): array
    {
        $response = $this->actingAs($actor)->getJson('/api/social/oauth/meta/redirect'.$query)->assertOk();
        parse_str((string) parse_url($response->json('url'), PHP_URL_QUERY), $params);

        return ['url' => $response->json('url'), 'raw' => $params['state'], 'state' => json_decode(Crypt::decryptString($params['state']), true)];
    }

    /** @return array<string, mixed> the payload the popup page posts to the SPA */
    private function popup(array $query): array
    {
        $html = $this->get('/api/social/callback/meta?'.http_build_query($query))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/var payload = (\{.*?\});/s', $html);
        preg_match('/var payload = (\{.*?\});/s', $html, $m);

        return json_decode($m[1], true);
    }

    private function connected(Account $account, array $attributes = []): SocialAccount
    {
        return SocialAccount::create($attributes + [
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => 'meta_ad_account', 'provider_id' => 'act_'.uniqid(),
            'name' => 'Ads', 'access_token' => 'stored-token', 'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);
    }

    // ================================================================== provider resolution

    public function test_providers_resolve_through_the_contract(): void
    {
        $this->assertTrue(SocialOAuthProviderFactory::isImplemented('meta'));
        $this->assertFalse(SocialOAuthProviderFactory::isImplemented('linkedin'));
        $this->assertFalse(SocialOAuthProviderFactory::isImplemented('evil'));
        $this->assertInstanceOf(MetaOAuthProvider::class, SocialOAuthProviderFactory::make('meta'));
        $this->assertContainsOnlyInstancesOf(SocialOAuthProviderInterface::class, SocialOAuthProviderFactory::all());
        $this->assertSame(['facebook_page', 'instagram', 'meta_ad_account'], SocialOAuthProviderFactory::make('meta')->assetTypes());

        $admin = $this->user($this->tenant());
        $this->actingAs($admin)->getJson('/api/social/oauth/linkedin/redirect')->assertNotFound()->assertJsonPath('error_code', 'SOCIAL_PROVIDER_UNAVAILABLE');
        $this->actingAs($admin)->getJson('/api/social/oauth/evil/redirect')->assertNotFound();

        $providers = $this->actingAs($admin)->getJson('/api/social/providers')->assertOk()->json('data');
        $this->assertSame([['meta', true, true]], array_map(fn ($p) => [$p['key'], $p['configured'], $p['enabled_for_account']], $providers));
    }

    public function test_generic_controller_code_holds_no_provider_specific_logic(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/SocialAuthController.php'));

        foreach (['MetaOAuthProvider', 'graph.facebook.com', 'facebook_page', "'meta'", 'allow_facebook', 'exchangePageAccessToken'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "SocialAuthController must not reference {$needle}");
        }
    }

    // ================================================================== connect + callback + bind

    public function test_a_full_connection_stores_encrypted_long_lived_credentials_and_never_returns_them(): void
    {
        $account = $this->tenant();
        $admin = $this->user($account);

        $connect = $this->actingAs($admin)->getJson('/api/social/oauth/meta/redirect')->assertOk();
        $this->assertArrayNotHasKey('nonce', $connect->json(), 'the nonce only ever reaches the SPA through the popup');
        $flow = $this->startConnect($admin);
        $this->assertSame([$account->id, $admin->id, 'meta'], [$flow['state']['account_id'], $flow['state']['user_id'], $flow['state']['provider']]);
        $this->assertStringStartsWith('https://www.facebook.com/', $flow['url']);

        $payload = $this->popup(['code' => 'auth-code', 'state' => $flow['raw']]);
        $this->assertSame('social-oauth-success', $payload['type']);
        $this->assertSame($flow['state']['nonce'], $payload['nonce']);
        $this->assertSame(['facebook_page', 'instagram', 'meta_ad_account'], array_column($payload['assets'], 'asset_type'));
        $this->assertStringNotContainsString('EAA', json_encode($payload), 'no token in the popup payload');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'fb_exchange_token'));

        $bound = $this->actingAs($admin)->postJson('/api/social/accounts/bind', ['nonce' => $payload['nonce'], 'selections' => [
            ['asset_type' => 'facebook_page', 'provider_id' => 'page-1'],
            ['asset_type' => 'meta_ad_account', 'provider_id' => 'act_1'],
        ]])->assertCreated();

        $this->assertStringNotContainsString('EAA', $bound->getContent());
        $this->assertSame(['connected', 'connected'], array_column($bound->json('data'), 'connection_status'));

        $page = SocialAccount::where('provider_id', 'page-1')->sole();
        $ads = SocialAccount::where('provider_id', 'act_1')->sole();
        $this->assertSame([self::PAGE_TOKEN, null], [$page->access_token, $page->token_expires_at], 'the Page keeps its own non-expiring token');
        $this->assertSame(self::LONG_TOKEN, $ads->access_token);
        $this->assertTrue($ads->token_expires_at->between(now()->addDays(59), now()->addDays(61)), 'the long-lived (~60 day) token, not the 90-minute one');
        $this->assertSame([$admin->id, '1'], [(int) $ads->connected_by_user_id, $ads->metadata['ad_account_number']]);

        $raw = DB::table('social_accounts')->where('id', $ads->id)->value('access_token');
        $this->assertNotSame(self::LONG_TOKEN, $raw);
        $this->assertSame(self::LONG_TOKEN, Crypt::decryptString($raw), 'stored encrypted');

        $this->assertStringNotContainsString('EAA', $this->actingAs($admin)->getJson('/api/social/accounts')->assertOk()->getContent());
        $this->assertNull(Cache::get('social_oauth_pending:'.$payload['nonce']), 'the grant is single-use');
    }

    public function test_the_short_lived_token_is_kept_if_the_long_lived_exchange_fails(): void
    {
        $this->graph['long_fails'] = true;
        $account = $this->tenant();
        $admin = $this->user($account);
        $flow = $this->startConnect($admin);
        $payload = $this->popup(['code' => 'auth-code', 'state' => $flow['raw']]);

        $this->actingAs($admin)->postJson('/api/social/accounts/bind', ['nonce' => $payload['nonce'], 'selections' => [['asset_type' => 'meta_ad_account', 'provider_id' => 'act_1']]])->assertCreated();

        $ads = SocialAccount::sole();
        $this->assertSame(self::SHORT_TOKEN, $ads->access_token);
        $this->assertTrue($ads->token_expires_at->lessThan(now()->addHours(2)));
    }

    public function test_a_cancelled_authorization_is_reported_as_cancelled_and_stores_nothing(): void
    {
        $flow = $this->startConnect($this->user($this->tenant()));

        $payload = $this->popup(['error' => 'access_denied', 'error_reason' => 'user_denied', 'state' => $flow['raw']]);

        $this->assertSame('social-oauth-cancelled', $payload['type']);
        $this->assertStringContainsString('cancelled', $payload['message']);
        $this->assertNull(Cache::get('social_oauth_pending:'.$flow['state']['nonce']));
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_a_provider_failure_is_recoverable_and_leaks_no_provider_detail(): void
    {
        $this->graph['code_fails'] = true;
        $flow = $this->startConnect($this->user($this->tenant()));

        $payload = $this->popup(['code' => 'bad-code', 'state' => $flow['raw']]);

        $this->assertSame('social-oauth-error', $payload['type']);
        $this->assertStringContainsString('could not be completed', $payload['message']);
        $this->assertStringNotContainsString('12345', $payload['message']);
        $this->assertNull(Cache::get('social_oauth_pending:'.$flow['state']['nonce']));

        $error = $this->popup(['error' => 'server_error', 'error_description' => '<script>alert(1)</script>']);
        $this->assertSame('social-oauth-error', $error['type']);
        $this->assertStringNotContainsString('script', $error['message']);
    }

    public function test_tampered_expired_or_mismatched_state_is_refused(): void
    {
        $admin = $this->user($this->tenant());
        $flow = $this->startConnect($admin);

        $this->assertSame('social-oauth-error', $this->popup(['code' => 'c', 'state' => $flow['raw'].'x'])['type']);
        $this->assertSame('social-oauth-error', $this->popup(['code' => 'c'])['type']);

        $expired = Crypt::encryptString(json_encode(['exp' => now()->subMinute()->timestamp] + $flow['state']));
        $this->assertStringContainsString('expired', $this->popup(['code' => 'c', 'state' => $expired])['message']);

        $otherProvider = Crypt::encryptString(json_encode(['provider' => 'linkedin'] + $flow['state']));
        $this->assertSame('social-oauth-error', $this->popup(['code' => 'c', 'state' => $otherProvider])['type']);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/oauth/access_token'));
    }

    public function test_the_grant_can_only_be_redeemed_by_the_initiating_user_and_account(): void
    {
        $account = $this->tenant();
        $initiator = $this->user($account);
        $colleague = $this->user($account);
        $stranger = $this->user($this->tenant());
        $payload = $this->popup(['code' => 'c', 'state' => $this->startConnect($initiator)['raw']]);
        $body = ['nonce' => $payload['nonce'], 'selections' => [['asset_type' => 'meta_ad_account', 'provider_id' => 'act_1']]];

        $this->actingAs($stranger)->postJson('/api/social/accounts/bind', $body)->assertStatus(422);
        $this->actingAs($colleague)->postJson('/api/social/accounts/bind', $body)->assertStatus(422);
        $this->actingAs($initiator)->postJson('/api/social/accounts/bind', ['nonce' => $payload['nonce'], 'selections' => [['asset_type' => 'meta_ad_account', 'provider_id' => 'act_OTHER']]])->assertStatus(422);
        $this->assertSame(0, SocialAccount::count());

        $this->actingAs($initiator)->postJson('/api/social/accounts/bind', $body)->assertCreated();
        $this->assertSame([$account->id], SocialAccount::pluck('account_id')->map(fn ($v) => (int) $v)->all());
    }

    // ================================================================== authorization / tenant isolation

    public function test_connecting_requires_permission_module_capability_provider_flag_and_configuration(): void
    {
        $this->actingAs($this->user($this->tenant(), 'user'))->getJson('/api/social/oauth/meta/redirect')->assertForbidden();

        $noModule = $this->tenant(['allowed_modules' => array_values(array_diff(Account::MODULES, ['social_accounts']))]);
        $this->actingAs($this->user($noModule))->getJson('/api/social/oauth/meta/redirect')->assertForbidden();

        $noCapability = $this->tenant();
        $this->revokeSocial($noCapability);
        $this->actingAs($this->user($noCapability))->getJson('/api/social/oauth/meta/redirect')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $noFlag = $this->tenant(['allow_facebook' => false, 'allow_instagram' => false]);
        $this->actingAs($this->user($noFlag))->getJson('/api/social/oauth/meta/redirect')->assertForbidden()->assertJsonPath('error_code', 'SOCIAL_PROVIDER_NOT_ENABLED');

        SocialProviderConfig::query()->update(['is_active' => false]);
        Cache::flush();
        $this->actingAs($this->user($this->tenant()))->getJson('/api/social/oauth/meta/redirect')->assertStatus(422)->assertJsonPath('error_code', 'SOCIAL_PROVIDER_NOT_CONFIGURED');
    }

    public function test_super_admin_needs_a_selected_client_and_skips_only_its_plan_when_connecting_for_it(): void
    {
        $superAdmin = $this->user(null, 'super_admin');
        $client = $this->tenant();
        $unentitled = $this->tenant();
        $this->revokeSocial($unentitled);
        $expired = $this->tenant();
        $expired->currentSubscription->forceFill(['expires_at' => now()->subDay(), 'status' => 'expired'])->save();

        $this->actingAs($superAdmin)->getJson('/api/social/oauth/meta/redirect')->assertStatus(422);
        // Owner decision (2026-09-30): the client's plan does not bind a Super Admin; its subscription rule still does.
        $this->actingAs($superAdmin)->getJson('/api/social/oauth/meta/redirect?account_id='.$unentitled->id)->assertOk();
        $this->actingAs($superAdmin)->getJson('/api/social/oauth/meta/redirect?account_id='.$expired->id)->assertOk();

        $flow = $this->startConnect($superAdmin, '?account_id='.$client->id);
        $this->assertSame($client->id, $flow['state']['account_id']);

        $payload = $this->popup(['code' => 'c', 'state' => $flow['raw']]);
        $this->actingAs($superAdmin)->postJson('/api/social/accounts/bind?account_id='.$client->id, ['nonce' => $payload['nonce'], 'selections' => [['asset_type' => 'meta_ad_account', 'provider_id' => 'act_1']]])->assertCreated();
        $this->assertSame($client->id, (int) SocialAccount::sole()->account_id);
    }

    public function test_an_agent_reaches_only_its_own_clients_social_accounts(): void
    {
        $agent = $this->tenant(['account_type' => 'agent']);
        $client = $this->tenant(['agent_id' => $agent->id]);
        $stranger = $this->tenant();
        $agentUser = $this->user($agent, ['admin', 'agent']);
        $foreign = $this->connected($stranger);

        $this->assertSame($client->id, $this->startConnect($agentUser, '?account_id='.$client->id)['state']['account_id']);
        $this->actingAs($agentUser)->getJson('/api/social/oauth/meta/redirect?account_id='.$stranger->id)->assertNotFound();
        $this->actingAs($agentUser)->getJson('/api/social/accounts?account_id='.$stranger->id)->assertNotFound();
        $this->actingAs($agentUser)->deleteJson("/api/social/accounts/{$foreign->id}?account_id={$client->id}")->assertNotFound();
        $this->assertNotNull($foreign->fresh());
    }

    public function test_foreign_social_account_ids_are_unreachable(): void
    {
        $mine = $this->tenant();
        $other = $this->tenant();
        $admin = $this->user($mine);
        $foreign = $this->connected($other);
        $own = $this->connected($mine);

        $this->assertSame([$own->id], array_column($this->actingAs($admin)->getJson('/api/social/accounts?account_id='.$other->id)->assertOk()->json('data'), 'id'));
        $this->actingAs($admin)->postJson("/api/social/accounts/{$foreign->id}/check")->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/social/accounts/{$foreign->id}")->assertNotFound();
        $this->assertNotNull($foreign->fresh());
    }

    // ================================================================== lifecycle

    public function test_a_connection_check_records_expired_revoked_or_healthy_and_ignores_an_unreachable_provider(): void
    {
        $account = $this->tenant();
        $admin = $this->user($account);
        $social = $this->connected($account);

        $this->graph['me'] = [400, ['error' => ['code' => 190, 'error_subcode' => 463, 'message' => 'Session has expired']]];
        $this->actingAs($admin)->postJson("/api/social/accounts/{$social->id}/check")->assertOk()
            ->assertJsonPath('data.connection_status', 'expired')->assertJsonPath('check.status', 'expired');
        $this->assertSame(SocialAccount::HEALTH_TOKEN_EXPIRED, $social->fresh()->health_status);

        $this->graph['me'] = [400, ['error' => ['code' => 190, 'error_subcode' => 460]]];
        $this->actingAs($admin)->postJson("/api/social/accounts/{$social->id}/check")->assertOk()->assertJsonPath('data.connection_status', 'revoked');
        $this->assertStringContainsString('Reconnect', (string) $social->fresh()->status_reason);

        $this->graph['me'] = [500, ['error' => ['code' => 2]]];
        $this->actingAs($admin)->postJson("/api/social/accounts/{$social->id}/check")->assertOk()
            ->assertJsonPath('check.status', 'unknown')->assertJsonPath('data.connection_status', 'revoked');

        $this->graph['me'] = [200, ['id' => '1']];
        $this->actingAs($admin)->postJson("/api/social/accounts/{$social->id}/check")->assertOk()
            ->assertJsonPath('data.connection_status', 'connected')->assertJsonPath('data.status_reason', null);
    }

    public function test_a_passed_token_expiry_is_reported_as_expired(): void
    {
        $account = $this->tenant();
        $this->connected($account, ['token_expires_at' => now()->subDay()]);

        $row = $this->actingAs($this->user($account))->getJson('/api/social/accounts')->assertOk()->json('data.0');

        $this->assertSame([SocialConnectionStatus::EXPIRED, 'connected'], [$row['connection_status'], $row['health_status']]);
        $this->assertArrayNotHasKey('access_token', $row);
    }

    public function test_disconnect_removes_the_connection_and_its_credentials(): void
    {
        $account = $this->tenant();
        $social = $this->connected($account);

        $this->actingAs($this->user($account))->deleteJson("/api/social/accounts/{$social->id}")->assertOk()
            ->assertJsonPath('revoked_at_provider', false);

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_the_popup_withholds_the_result_outside_local_when_no_frontend_origin_is_configured(): void
    {
        $flow = $this->startConnect($this->user($this->tenant()));
        config(['services.frontend.url' => null]);
        $env = app()['env'];
        app()['env'] = 'production';

        try {
            $html = $this->get('/api/social/callback/meta?'.http_build_query(['code' => 'c', 'state' => $flow['raw']]))->getContent();
        } finally {
            app()['env'] = $env;
        }

        $this->assertStringNotContainsString($flow['state']['nonce'], $html);
        $this->assertStringContainsString('var targetOrigin = null', $html);
    }
}
