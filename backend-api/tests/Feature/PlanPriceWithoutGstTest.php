<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PaymentGatewayController;
use App\Models\Plan;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** No GST on plans (owner, 2026-10-14): the price shown — ₹599 / ₹1,999 / ₹7,999 a month — is the price paid. */
class PlanPriceWithoutGstTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_pricing_shows_the_plan_price_with_no_tax(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        $this->assertSame(0.0, PaymentGatewayController::TAX_RATE);

        $response = $this->getJson('/api/public/plans')->assertOk();
        $this->assertEquals(0, $response->json('tax_rate'));
        $plans = collect($response->json('data'))->keyBy('key');
        foreach (['starter' => '599.00', 'growth' => '1999.00', 'business' => '7999.00'] as $slug => $price) {
            $this->assertSame($price, $plans[$slug]['total_amount'], $slug);
            $this->assertSame('0.00', $plans[$slug]['tax_amount'], $slug);
        }
    }

    public function test_the_migration_restores_a_starter_price_saved_without_gst(): void
    {
        $this->seed(Phase1FoundationSeeder::class);
        Plan::query()->where('slug', 'starter')->update(['price' => 507.63]);
        Plan::query()->where('slug', 'growth')->update(['price' => 1499.00]);

        (require database_path('migrations/2026_10_14_130000_set_plan_prices_without_gst.php'))->up();

        $this->assertEquals(599.00, (float) Plan::query()->where('slug', 'starter')->value('price'));
        // A price the Super Admin chose is left alone.
        $this->assertEquals(1499.00, (float) Plan::query()->where('slug', 'growth')->value('price'));
    }
}
