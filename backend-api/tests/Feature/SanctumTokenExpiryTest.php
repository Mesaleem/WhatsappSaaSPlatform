<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Phase 12 Task 2 (H2) — configurable Sanctum token lifetime and pruning of expired tokens.
 * The Guard reads `sanctum.expiration` when the request is authenticated (the sanctum guard is built per request
 * from config), so each test sets it before calling the API.
 */
class SanctumTokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function adminWithToken(): array
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');
        $token = $user->createToken('api-token', ['*']);

        return [$user, $token->plainTextToken, $token->accessToken];
    }

    private function me(string $plain)
    {
        // a fresh app per call so a previously resolved guard (and its cached user) is never reused
        $this->app['auth']->forgetGuards();

        return $this->withToken($plain)->getJson('/api/auth/me');
    }

    public function test_the_default_configuration_keeps_tokens_non_expiring(): void
    {
        $this->assertNull(config('sanctum.expiration'));

        [, $plain, $row] = $this->adminWithToken();
        $row->forceFill(['created_at' => now()->subYears(5)])->save();

        $this->me($plain)->assertOk();
    }

    public function test_the_expiration_is_read_from_the_environment_and_empty_or_zero_means_none(): void
    {
        $load = function (?string $value): mixed {
            $key = 'SANCTUM_TOKEN_EXPIRATION_MINUTES';
            $value === null ? putenv($key) : putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;

            return (require base_path('config/sanctum.php'))['expiration'];
        };

        try {
            $this->assertNull($load(null));
            $this->assertNull($load(''));
            $this->assertNull($load('0'));
            $this->assertNull($load('-5'));
            $this->assertSame(720, $load('720'));
        } finally {
            $load(null);
            unset($_ENV['SANCTUM_TOKEN_EXPIRATION_MINUTES'], $_SERVER['SANCTUM_TOKEN_EXPIRATION_MINUTES']);
        }
    }

    public function test_a_token_inside_its_lifetime_authenticates(): void
    {
        config(['sanctum.expiration' => 60]);
        [, $plain, $row] = $this->adminWithToken();
        $row->forceFill(['created_at' => now()->subMinutes(59)])->save();

        $this->me($plain)->assertOk();
    }

    public function test_an_expired_token_cannot_authenticate(): void
    {
        config(['sanctum.expiration' => 60]);
        [, $plain, $row] = $this->adminWithToken();
        $row->forceFill(['created_at' => now()->subMinutes(61)])->save();

        $this->me($plain)->assertUnauthorized();
    }

    public function test_a_token_with_a_past_expires_at_is_refused_even_without_a_configured_lifetime(): void
    {
        [$user, , ] = $this->adminWithToken();
        $expired = $user->createToken('short', ['*'], now()->subMinute());

        $this->me($expired->plainTextToken)->assertUnauthorized();
    }

    public function test_login_still_issues_a_working_token_and_logout_still_revokes_it(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = User::factory()->create(['account_id' => null, 'is_active' => true, 'email' => 'root@example.test']);
        $user->assignRole('super_admin');

        $token = $this->postJson('/api/auth/login', ['email' => 'root@example.test', 'password' => 'password'])->assertOk()->json('token');

        $this->me($token)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertSame(0, PersonalAccessToken::count());
        $this->me($token)->assertUnauthorized();
    }

    public function test_pruning_removes_only_tokens_expired_beyond_the_grace_period(): void
    {
        config(['sanctum.expiration' => 60]);
        [$user] = $this->adminWithToken();
        PersonalAccessToken::query()->delete();

        $fresh = $user->createToken('fresh', ['*'])->accessToken;
        $justExpired = $user->createToken('just-expired', ['*'])->accessToken;
        $longExpired = $user->createToken('long-expired', ['*'])->accessToken;
        $explicitOld = $user->createToken('explicit', ['*'], now()->subDays(3))->accessToken;
        $explicitFuture = $user->createToken('future', ['*'], now()->addDays(3))->accessToken;

        $justExpired->forceFill(['created_at' => now()->subMinutes(61)])->save();          // expired, inside the 24 h grace
        $longExpired->forceFill(['created_at' => now()->subMinutes(60 + 25 * 60)])->save(); // expired > 24 h ago

        $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertExitCode(0);

        $left = PersonalAccessToken::pluck('name')->sort()->values()->all();
        $this->assertSame(['fresh', 'future', 'just-expired'], $left);
    }

    public function test_pruning_with_no_configured_lifetime_deletes_nothing_that_has_no_expiry(): void
    {
        [$user] = $this->adminWithToken();
        $old = $user->createToken('ancient', ['*'])->accessToken;
        $old->forceFill(['created_at' => now()->subYears(3)])->save();

        $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertExitCode(0);

        $this->assertTrue(PersonalAccessToken::where('name', 'ancient')->exists());
    }

    public function test_pruning_is_scheduled_daily_on_one_server(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'sanctum:prune-expired'));

        $this->assertNotNull($event);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}
