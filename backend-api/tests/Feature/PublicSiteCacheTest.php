<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PublicSiteController;
use App\Models\Plan;
use App\Models\SiteContent;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scale (2026-10-14): the public website's content and plans are served from the cache — no DB
 * read per visitor — and an edit by the Super Admin shows at once.
 */
class PublicSiteCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_content_is_cached_and_an_edit_shows_at_once(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['account_id' => null]);
        $admin->assignRole('super_admin');

        $this->getJson('/api/public/site')->assertOk();
        $this->assertTrue(Cache::has(PublicSiteController::CONTENT_CACHE_KEY));

        // Served from the cache: no site_contents query on the next visit.
        DB::enableQueryLog();
        $this->getJson('/api/public/site')->assertOk();
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'site_contents')));
        DB::disableQueryLog();

        $brand = PublicSiteController::content()['brand'];
        $this->actingAs($admin)->putJson('/api/admin/site-content/brand', ['value' => ['name' => 'Cached Co'] + $brand])->assertOk();
        $this->assertSame('Cached Co', $this->getJson('/api/public/site')->json('data.brand.name'));

        $this->actingAs($admin)->deleteJson('/api/admin/site-content/brand')->assertOk();
        $this->assertNotSame('Cached Co', $this->getJson('/api/public/site')->json('data.brand.name'));
        $this->assertSame(0, SiteContent::query()->where('key', 'brand')->count());
    }

    public function test_public_plans_are_cached_and_a_plan_edit_shows_at_once(): void
    {
        $this->seed(Phase1FoundationSeeder::class);

        $this->assertSame('599.00', collect($this->getJson('/api/public/plans')->json('data'))->firstWhere('key', 'starter')['total_amount']);
        $this->assertTrue(Cache::has(PublicSiteController::PLANS_CACHE_KEY));

        Plan::query()->where('slug', 'starter')->firstOrFail()->update(['price' => 649]);

        $this->assertSame('649.00', collect($this->getJson('/api/public/plans')->json('data'))->firstWhere('key', 'starter')['total_amount']);
    }
}
