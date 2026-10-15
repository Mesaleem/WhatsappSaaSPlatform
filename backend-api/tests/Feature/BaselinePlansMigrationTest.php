<?php

namespace Tests\Feature;

use App\Models\Plan;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 2026_10_13_120000: creates the agreed plans on a server that lacks them, never overwrites one that exists. */
class BaselinePlansMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        (require database_path('migrations/2026_10_13_120000_create_baseline_plans_if_missing.php'))->up();
    }

    public function test_a_missing_plan_is_created_with_its_bundle_and_an_existing_one_is_left_alone(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        \App\Models\Account::factory()->create(); // a running installation

        Plan::where('slug', 'growth')->firstOrFail()->delete();
        Plan::where('slug', 'starter')->update(['price' => 777]); // a Super Admin's own change

        $this->runMigration();

        $growth = Plan::with('capabilities')->where('slug', 'growth')->firstOrFail();
        $this->assertSame('unlimited', $growth->billing_model);
        $this->assertEqualsWithDelta(1999.00, (float) $growth->price, 0.001);
        $this->assertEqualsCanonicalizing(
            ['whatsapp_send', 'whatsapp_groups', 'crm', 'journey_automation', 'social', 'ai', 'payments', 'external_api', 'email'],
            $growth->capabilities->pluck('slug')->all(),
        );
        $this->assertEqualsWithDelta(777.00, (float) Plan::where('slug', 'starter')->value('price'), 0.001);
        $this->assertSame(7, Plan::count()); // 3 baseline plans + the 2026-10-15 line-up (crm, meta_growth, meta_pro) + the hidden trial (2026-10-14 QA fixes).
    }

    public function test_on_a_fresh_database_it_does_nothing(): void
    {
        $this->runMigration();

        $this->assertSame(0, Plan::count());
    }
}
