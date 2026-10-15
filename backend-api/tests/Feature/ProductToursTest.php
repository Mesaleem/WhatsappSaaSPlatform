<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Product tours (2026-10-15): which tours a user finished, kept per user. */
class ProductToursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_a_new_user_has_no_finished_tours_and_marking_one_is_kept(): void
    {
        $user = $this->user();
        $this->actingAs($user)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.completed_tours', []);

        $this->actingAs($user)->postJson('/api/auth/tours', ['tour' => 'welcome'])->assertOk()->assertJsonPath('completed_tours', ['welcome']);
        $this->actingAs($user)->postJson('/api/auth/tours', ['tour' => 'page:/crm/leads'])->assertOk();
        $this->actingAs($user)->postJson('/api/auth/tours', ['tour' => 'welcome'])->assertOk()
            ->assertJsonPath('completed_tours', ['welcome', 'page:/crm/leads']);

        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('user.completed_tours', ['welcome', 'page:/crm/leads']);
        $this->assertSame(0, DB::table('activity_logs')->where('module', 'like', '%User%')->where('action_type', 'updated')->count());
    }

    public function test_reset_one_or_every_tour(): void
    {
        $user = $this->user();
        $user->forceFill(['completed_tours' => ['welcome', 'page:/billing']])->save();

        $this->actingAs($user)->postJson('/api/auth/tours', ['action' => 'reset', 'tour' => 'welcome'])
            ->assertJsonPath('completed_tours', ['page:/billing']);
        $this->actingAs($user)->postJson('/api/auth/tours', ['action' => 'reset'])->assertJsonPath('completed_tours', []);
    }

    public function test_tour_keys_are_validated_and_login_is_required(): void
    {
        $this->postJson('/api/auth/tours', ['tour' => 'welcome'])->assertUnauthorized();
        $this->actingAs($this->user())->postJson('/api/auth/tours', ['tour' => '<script>'])->assertUnprocessable();
        $this->actingAs($this->user())->postJson('/api/auth/tours', [])->assertUnprocessable();
    }
}
