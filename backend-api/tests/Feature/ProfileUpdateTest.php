<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The profile: name and phone can change, the email is the login ID and cannot, a password needs the current one. */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email' => 'owner@example.com', 'password' => 'old-password-123', 'phone_number' => '9000000001']);
    }

    public function test_the_email_cannot_be_changed(): void
    {
        $user = $this->user();

        $this->actingAs($user)->patchJson('/api/auth/profile', ['name' => 'Owner', 'email' => 'new@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'email_locked');

        $this->assertSame('owner@example.com', $user->fresh()->email);
    }

    public function test_the_name_and_phone_can_be_changed(): void
    {
        $user = $this->user();

        $this->actingAs($user)->patchJson('/api/auth/profile', ['name' => 'New Name', 'phone_number' => '9111111111', 'email' => 'owner@example.com'])
            ->assertOk();

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('9111111111', $user->fresh()->phone_number);
    }

    public function test_a_password_change_needs_the_correct_current_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)->patchJson('/api/auth/profile', [
            'name' => 'Owner', 'current_password' => 'wrong-password', 'password' => 'brand-new-123', 'password_confirmation' => 'brand-new-123',
        ])->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'The current password is not correct.');

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password), 'the password is unchanged');
    }

    public function test_a_password_change_with_the_correct_current_password_works(): void
    {
        $user = $this->user();

        $this->actingAs($user)->patchJson('/api/auth/profile', [
            'name' => 'Owner', 'current_password' => 'old-password-123', 'password' => 'brand-new-123', 'password_confirmation' => 'brand-new-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-123', $user->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $user = $this->user();

        $this->actingAs($user)->patchJson('/api/auth/profile', [
            'name' => 'Owner', 'current_password' => 'old-password-123', 'password' => 'brand-new-123', 'password_confirmation' => 'different-123',
        ])->assertUnprocessable();
    }
}
