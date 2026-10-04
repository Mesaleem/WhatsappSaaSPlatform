<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 12 Task 2 (H1) — login throttling: per e-mail + IP lockout, consistent JSON 429, no account-existence
 * disclosure, reset on success, limits from config, and the existing login contract unchanged.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['security.login' => ['max_attempts' => 3, 'decay_minutes' => 5, 'ip_max_attempts' => 100, 'ip_decay_minutes' => 1]]);
        RateLimiter::clear('x');
    }

    private function admin(string $email = 'root@example.test'): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true, 'email' => $email]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function attempt(string $email, string $password = 'wrong-password', string $ip = '203.0.113.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    // ------------------------------------------------------------ existing behavior unchanged

    public function test_successful_login_keeps_its_response_contract(): void
    {
        $this->admin();

        $this->attempt('root@example.test', 'password')
            ->assertOk()
            ->assertJsonStructure(['user', 'token', 'token_type'])
            ->assertJsonPath('token_type', 'Bearer');
    }

    public function test_invalid_credentials_keep_the_422_contract_for_unknown_and_known_accounts_alike(): void
    {
        $this->admin();

        foreach (['root@example.test', 'nobody@example.test'] as $email) {
            $this->attempt($email)
                ->assertStatus(422)
                ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
        }
    }

    public function test_missing_fields_are_still_a_normal_validation_error(): void
    {
        $this->postJson('/api/auth/login', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_a_suspended_user_still_gets_the_403_contract(): void
    {
        $user = $this->admin();
        $user->forceFill(['is_active' => false])->save();

        $this->attempt('root@example.test', 'password')->assertStatus(403)->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');
    }

    // ------------------------------------------------------------ the limiter

    public function test_repeated_failures_are_allowed_up_to_the_configured_threshold_then_429(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->attempt('root@example.test')->assertStatus(422);
        }

        $this->attempt('root@example.test')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'TOO_MANY_LOGIN_ATTEMPTS')
            ->assertJsonStructure(['message', 'error_code', 'retry_after'])
            ->assertHeader('Retry-After');
    }

    public function test_the_correct_password_is_also_refused_while_locked_out(): void
    {
        $this->admin();

        for ($i = 0; $i < 3; $i++) {
            $this->attempt('root@example.test')->assertStatus(422);
        }

        $this->attempt('root@example.test', 'password')->assertStatus(429);
    }

    public function test_the_lockout_response_is_identical_for_existing_and_non_existing_accounts(): void
    {
        $this->admin();
        $bodies = [];

        foreach (['root@example.test', 'ghost@example.test'] as $email) {
            for ($i = 0; $i < 3; $i++) {
                $this->attempt($email);
            }
            $response = $this->attempt($email)->assertStatus(429);
            $bodies[$email] = [$response->json('message'), $response->json('error_code'), array_keys($response->json())];
        }

        $this->assertSame($bodies['root@example.test'], $bodies['ghost@example.test']);
    }

    public function test_successful_login_resets_the_failed_attempts(): void
    {
        $this->admin();

        $this->attempt('root@example.test')->assertStatus(422);
        $this->attempt('root@example.test')->assertStatus(422);
        $this->attempt('root@example.test', 'password')->assertOk();

        // the counter started again: three more failures are tolerated before the 429
        for ($i = 0; $i < 3; $i++) {
            $this->attempt('root@example.test')->assertStatus(422);
        }
        $this->attempt('root@example.test')->assertStatus(429);
    }

    public function test_a_different_ip_does_not_share_the_lockout_bucket(): void
    {
        $this->admin();

        for ($i = 0; $i < 3; $i++) {
            $this->attempt('root@example.test', 'wrong', '203.0.113.10');
        }
        $this->attempt('root@example.test', 'wrong', '203.0.113.10')->assertStatus(429);

        // the account's owner on another address is not locked out by the attacker
        $this->attempt('root@example.test', 'password', '198.51.100.7')->assertOk();
    }

    public function test_a_different_account_from_the_same_ip_does_not_share_the_lockout_bucket(): void
    {
        $this->admin('a@example.test');
        $this->admin('b@example.test');

        for ($i = 0; $i < 3; $i++) {
            $this->attempt('a@example.test');
        }
        $this->attempt('a@example.test')->assertStatus(429);

        $this->attempt('b@example.test', 'password')->assertOk();
    }

    public function test_the_email_is_normalized_so_case_and_spacing_cannot_dodge_the_lockout(): void
    {
        foreach (['Root@Example.test', 'root@example.test', ' ROOT@example.test'] as $email) {
            $this->attempt($email);
        }

        $this->attempt('root@EXAMPLE.test')->assertStatus(429);
    }

    public function test_the_per_ip_bucket_bounds_password_spraying_across_many_emails(): void
    {
        config(['security.login.ip_max_attempts' => 4]);

        for ($i = 0; $i < 4; $i++) {
            $this->attempt("victim{$i}@example.test");
        }

        $this->attempt('victim99@example.test')->assertStatus(429);
        $this->attempt('victim99@example.test', 'wrong', '198.51.100.7')->assertStatus(422);
    }

    public function test_the_limits_come_from_configuration(): void
    {
        config(['security.login.max_attempts' => 1]);

        $this->attempt('root@example.test')->assertStatus(422);
        $this->attempt('root@example.test')->assertStatus(429);
    }

    public function test_the_login_limiter_is_not_applied_to_authenticated_endpoints(): void
    {
        $user = $this->admin();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        for ($i = 0; $i < 8; $i++) {
            $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        }

        $middleware = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('throttle:login', $r->gatherMiddleware(), true))
            ->map(fn ($r) => $r->uri())->values()->all();

        $this->assertSame(['api/auth/login'], $middleware);
    }

    public function test_a_non_string_email_cannot_crash_or_bypass_the_limiter(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->postJson('/api/auth/login', ['email' => ['x'], 'password' => 'p'])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->postJson('/api/auth/login', ['email' => ['x'], 'password' => 'p'])->assertStatus(429);
    }
}
