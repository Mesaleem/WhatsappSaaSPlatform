<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** Forgot password: request a link, then set a new password with the token. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_requesting_a_link_creates_a_token_and_never_reveals_whether_the_email_exists(): void
    {
        $user = User::factory()->create(['email' => 'asha@acme.test']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'asha@acme.test'])->assertOk();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@acme.test'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertTrue(DB::table('password_reset_tokens')->where('email', $user->email)->exists());
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', 'nobody@acme.test')->exists());
    }

    public function test_no_link_is_issued_for_a_suspended_user(): void
    {
        User::factory()->create(['email' => 'off@acme.test', 'is_active' => false]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'off@acme.test'])->assertOk();

        $this->assertFalse(DB::table('password_reset_tokens')->where('email', 'off@acme.test')->exists());
    }

    public function test_a_valid_token_sets_the_new_password_and_signs_out_every_session(): void
    {
        $user = User::factory()->create(['email' => 'asha@acme.test']);
        $user->createToken('old-session');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'asha@acme.test',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', $user->email)->exists());
    }

    public function test_a_wrong_or_used_token_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'asha@acme.test']);
        $original = $user->password;

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'asha@acme.test',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token' => 'x', 'email' => 'asha@acme.test', 'password' => 'short', 'password_confirmation' => 'other',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }
}
