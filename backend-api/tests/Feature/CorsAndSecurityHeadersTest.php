<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 12 Task 2 (M5) — explicit CORS policy and the API security headers.
 * CORS here is only a browser read-permission; every assertion about authorization lives in the other suites.
 */
class CorsAndSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function preflight(string $origin, string $path = '/api/auth/login', string $method = 'POST')
    {
        return $this->call('OPTIONS', $path, [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
    }

    private function configureCors(array $overrides): void
    {
        config(['cors' => array_merge(config('cors'), $overrides)]);
    }

    // ------------------------------------------------------------ CORS configuration

    public function test_cors_is_configured_explicitly_in_a_config_file(): void
    {
        $this->assertFileExists(config_path('cors.php'));
        $this->assertSame(['api/*', 'sanctum/csrf-cookie'], config('cors.paths'));
    }

    public function test_the_default_keeps_todays_behavior_open_origins_without_credentials(): void
    {
        $this->assertSame(['*'], config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));

        $response = $this->preflight('https://anything.example.org');
        $response->assertSuccessful();
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_a_configured_origin_is_allowed_and_an_other_origin_is_not(): void
    {
        $this->configureCors(['allowed_origins' => ['https://app.example.com'], 'supports_credentials' => false]);

        $allowed = $this->preflight('https://app.example.com');
        $this->assertSame('https://app.example.com', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('POST', (string) $allowed->headers->get('Access-Control-Allow-Methods'));

        // a single configured origin is always echoed as that origin — the browser then refuses the mismatch
        $denied = $this->preflight('https://evil.example.org');
        $this->assertNotSame('https://evil.example.org', $denied->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $denied->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_actual_requests_from_a_disallowed_origin_get_no_cors_grant(): void
    {
        $this->configureCors(['allowed_origins' => ['https://app.example.com']]);

        $evil = $this->withHeaders(['Origin' => 'https://evil.example.org'])->postJson('/api/auth/login', ['email' => 'a@b.test', 'password' => 'x']);
        $evil->assertStatus(422);
        $this->assertNotSame('https://evil.example.org', $evil->headers->get('Access-Control-Allow-Origin'));

        $this->withHeaders(['Origin' => 'https://app.example.com'])->postJson('/api/auth/login', ['email' => 'a@b.test', 'password' => 'x'])
            ->assertStatus(422)
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
    }

    public function test_origin_patterns_are_supported(): void
    {
        $this->configureCors(['allowed_origins' => [], 'allowed_origins_patterns' => ['#^https://[a-z0-9-]+\.example\.com$#']]);

        $this->assertSame('https://tenant-1.example.com', $this->preflight('https://tenant-1.example.com')->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($this->preflight('https://example.com.evil.org')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_credentials_are_allowed_only_with_an_explicit_origin_and_never_with_a_wildcard(): void
    {
        $load = function (array $env): array {
            foreach ($env as $k => $v) {
                putenv("{$k}={$v}");
            }
            try {
                return require base_path('config/cors.php');
            } finally {
                foreach (array_keys($env) as $k) {
                    putenv($k);
                }
            }
        };

        $wildcard = $load(['CORS_SUPPORTS_CREDENTIALS' => 'true']);
        $this->assertSame(['*'], $wildcard['allowed_origins']);
        $this->assertFalse($wildcard['supports_credentials'], 'credentials must never be combined with a wildcard origin');

        $explicitWildcard = $load(['CORS_ALLOWED_ORIGINS' => 'https://a.example.com, *', 'CORS_SUPPORTS_CREDENTIALS' => 'true']);
        $this->assertFalse($explicitWildcard['supports_credentials']);

        $explicit = $load(['CORS_ALLOWED_ORIGINS' => 'https://a.example.com, https://b.example.com', 'CORS_SUPPORTS_CREDENTIALS' => 'true']);
        $this->assertSame(['https://a.example.com', 'https://b.example.com'], $explicit['allowed_origins']);
        $this->assertTrue($explicit['supports_credentials']);

        $this->assertSame(['*'], $load(['CORS_ALLOWED_ORIGINS' => ' , '])['allowed_origins'], 'an empty list falls back to the documented default');
    }

    public function test_cors_does_not_replace_authorization(): void
    {
        $this->configureCors(['allowed_origins' => ['https://app.example.com']]);

        $this->withHeaders(['Origin' => 'https://app.example.com'])->getJson('/api/auth/me')->assertUnauthorized();
    }

    // ------------------------------------------------------------ security headers

    public function test_api_responses_carry_the_baseline_security_headers(): void
    {
        $response = $this->postJson('/api/auth/login', ['email' => 'a@b.test', 'password' => 'x'])->assertStatus(422);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_error_and_unauthenticated_responses_carry_them_too(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/does-not-exist')->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->postJson('/api/auth/login', [])->assertHeaderMissing('Strict-Transport-Security');

        $secure = $this->postJson('https://localhost/api/auth/login', []);
        $secure->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    }

    public function test_hsts_include_subdomains_is_opt_in_and_hsts_can_be_disabled(): void
    {
        config(['security.headers.hsts.include_subdomains' => true]);
        $this->postJson('https://localhost/api/auth/login', [])
            ->assertHeader('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');

        config(['security.headers.hsts.enabled' => false]);
        $this->postJson('https://localhost/api/auth/login', [])->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_headers_are_configurable_and_can_be_switched_off(): void
    {
        config(['security.headers.frame_options' => 'SAMEORIGIN', 'security.headers.referrer_policy' => 'same-origin']);
        $this->getJson('/api/auth/me')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'same-origin');

        config(['security.headers.enabled' => false]);
        $this->getJson('/api/auth/me')->assertHeaderMissing('X-Content-Type-Options');
    }

    public function test_the_headers_do_not_change_api_behavior_or_bodies(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'a@b.test', 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
    }

    public function test_an_existing_header_set_by_a_route_is_not_overwritten(): void
    {
        $middleware = new \App\Http\Middleware\ApiSecurityHeaders();
        $request = \Illuminate\Http\Request::create('/api/x');

        $response = $middleware->handle($request, fn () => response('x')->header('X-Frame-Options', 'SAMEORIGIN'));

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
