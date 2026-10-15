<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AdCampaign;
use App\Models\AdCampaignDailyMetric;
use App\Models\Capability;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The monthly social report shows money in INR, never "$" (2026-10-15 owner request). */
class SocialReportCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pdf_report_writes_amounts_in_inr(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach (['ads', 'social'] as $slug) {
            AccountEntitlement::firstOrCreate(['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')], ['source' => 'manual_grant']);
        }
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        $campaign = AdCampaign::create(['account_id' => $account->id, 'meta_campaign_id' => 'CMP-1', 'name' => 'Diwali', 'objective' => 'LEAD_GENERATION',
            'status' => 'ACTIVE', 'daily_budget' => 500, 'currency' => 'INR', 'last_spend' => 1234.5, 'last_leads' => 5]);
        AdCampaignDailyMetric::create(['ad_campaign_id' => $campaign->id, 'account_id' => $account->id, 'metric_date' => now()->toDateString(),
            'spend' => 1234.5, 'impressions' => 900, 'leads' => 5, 'cpl' => 246.9]);

        $pdf = $this->actingAs($user)->get('/api/social/reports/generate?month='.now()->format('Y-m'))->assertOk()->getContent();

        $this->assertStringContainsString('Total Ad Spend: INR 1,234.50', $pdf);
        $this->assertStringContainsString('Spend INR 1,234.50', $pdf);
        $this->assertStringNotContainsString('$', $pdf);
    }
}
