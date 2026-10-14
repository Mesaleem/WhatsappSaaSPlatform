<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\SitePageView;
use App\Models\SiteVisit;
use App\Models\User;
use App\Services\SiteAnalytics\SiteTrackingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Website visitor analytics: the public tracker records visits, pages and time; only the Super Admin sees the report. */
class SiteAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

    private function send(array $event, string $ua = self::BROWSER)
    {
        // Sent like navigator.sendBeacon does it: a JSON string as text/plain.
        return $this->call('POST', '/api/public/site-events', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => $ua, 'HTTP_CF_IPCOUNTRY' => 'IN'], json_encode($event));
    }

    private function superAdmin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create(['account_id' => null]);
        $u->assignRole('super_admin');

        return $u;
    }

    public function test_a_visit_records_landing_source_pages_and_time(): void
    {
        $session = (string) Str::uuid();
        $visitor = (string) Str::uuid();
        $home = (string) Str::uuid();
        $pricing = (string) Str::uuid();

        $this->send(['type' => 'view', 'session_id' => $session, 'visitor_id' => $visitor, 'view_id' => $home, 'path' => '/home?utm_x=1', 'title' => 'WapHub | Home',
            'referrer' => 'https://www.google.com/', 'host' => 'waphub.in'])->assertNoContent();
        $this->send(['type' => 'ping', 'session_id' => $session, 'view_id' => $home, 'seconds' => 25])->assertNoContent();
        $this->send(['type' => 'view', 'session_id' => $session, 'visitor_id' => $visitor, 'view_id' => $pricing, 'path' => '/pricing', 'host' => 'waphub.in'])->assertNoContent();
        $this->send(['type' => 'ping', 'session_id' => $session, 'view_id' => $pricing, 'seconds' => 70]);
        // A late, smaller ping never shrinks the time; a repeated view is not counted twice.
        $this->send(['type' => 'ping', 'session_id' => $session, 'view_id' => $pricing, 'seconds' => 10]);
        $this->send(['type' => 'view', 'session_id' => $session, 'visitor_id' => $visitor, 'view_id' => $pricing, 'path' => '/pricing', 'host' => 'waphub.in']);
        $this->send(['type' => 'signup', 'session_id' => $session]);

        $visit = SiteVisit::query()->sole();
        $this->assertSame('Google', $visit->source);
        $this->assertSame('/home', $visit->landing_path);
        $this->assertSame('/pricing', $visit->exit_path);
        $this->assertSame(2, $visit->page_count);
        $this->assertSame(95, $visit->duration_seconds);
        $this->assertSame(['mobile', 'Chrome', 'Android', 'IN'], [$visit->device, $visit->browser, $visit->os, $visit->country]);
        $this->assertTrue($visit->signed_up);
        $this->assertFalse($visit->is_returning);
        $this->assertSame([25, 70], SitePageView::query()->orderBy('id')->pluck('duration_seconds')->all());

        // The same browser coming back later is a returning visitor.
        $this->send(['type' => 'view', 'session_id' => (string) Str::uuid(), 'visitor_id' => $visitor, 'view_id' => (string) Str::uuid(), 'path' => '/home', 'host' => 'waphub.in']);
        $this->assertTrue(SiteVisit::query()->latest('id')->first()->is_returning);
        $this->assertSame('Direct', SiteVisit::query()->latest('id')->first()->source);
    }

    public function test_bots_bad_payloads_and_huge_times_are_ignored_or_capped(): void
    {
        $session = (string) Str::uuid();
        $view = (string) Str::uuid();
        $this->send(['type' => 'view', 'session_id' => (string) Str::uuid(), 'visitor_id' => (string) Str::uuid(), 'view_id' => (string) Str::uuid(), 'path' => '/home'], 'Googlebot/2.1 (+http://www.google.com/bot.html)')->assertNoContent();
        $this->send(['type' => 'view', 'session_id' => 'not-a-uuid', 'path' => '/home'])->assertNoContent();
        $this->assertSame(0, SiteVisit::query()->count());

        $this->send(['type' => 'view', 'session_id' => $session, 'visitor_id' => (string) Str::uuid(), 'view_id' => $view, 'path' => '/home', 'utm' => ['source' => 'NEWSLETTER', 'campaign' => 'diwali']]);
        $this->send(['type' => 'ping', 'session_id' => $session, 'view_id' => $view, 'seconds' => 999999]);
        // A ping for another session's page is ignored.
        $this->send(['type' => 'ping', 'session_id' => (string) Str::uuid(), 'view_id' => $view, 'seconds' => 5]);

        $visit = SiteVisit::query()->sole();
        $this->assertSame('Newsletter', $visit->source);
        $this->assertSame('diwali', $visit->utm_campaign);
        $this->assertSame(SiteTrackingService::MAX_PAGE_SECONDS, $visit->duration_seconds);
    }

    public function test_known_referrers_map_to_a_source(): void
    {
        $this->assertSame('Facebook', SiteTrackingService::source(null, 'https://l.facebook.com/l.php?u=x', 'waphub.in'));
        $this->assertSame('Instagram', SiteTrackingService::source(null, 'https://www.instagram.com/', 'waphub.in'));
        $this->assertSame('WhatsApp', SiteTrackingService::source(null, 'https://wa.me/91999', 'waphub.in'));
        $this->assertSame('Direct', SiteTrackingService::source(null, 'https://www.waphub.in/features', 'waphub.in'));
        $this->assertSame('blog.example.org', SiteTrackingService::source(null, 'https://blog.example.org/post', 'waphub.in'));
        $this->assertSame('Direct', SiteTrackingService::source(null, null, 'waphub.in'));
    }

    public function test_only_the_super_admin_sees_the_report(): void
    {
        $admin = $this->superAdmin();
        $client = User::factory()->create(['account_id' => Account::factory()->create()->id]);
        $client->assignRole('admin');

        $s = (string) Str::uuid();
        $v = (string) Str::uuid();
        $this->send(['type' => 'view', 'session_id' => $s, 'visitor_id' => (string) Str::uuid(), 'view_id' => $v, 'path' => '/pricing', 'referrer' => 'https://www.facebook.com/']);
        $this->send(['type' => 'ping', 'session_id' => $s, 'view_id' => $v, 'seconds' => 40]);
        $this->send(['type' => 'view', 'session_id' => (string) Str::uuid(), 'visitor_id' => (string) Str::uuid(), 'view_id' => (string) Str::uuid(), 'path' => '/home']);

        $this->actingAs($client)->getJson('/api/admin/site-analytics')->assertForbidden();
        $this->actingAs($client)->getJson('/api/admin/site-analytics/visits')->assertForbidden();

        $report = $this->actingAs($admin)->getJson('/api/admin/site-analytics')->assertOk();
        $report->assertJsonPath('summary.visits', 2)
            ->assertJsonPath('summary.visitors', 2)
            ->assertJsonPath('summary.page_views', 2)
            ->assertJsonPath('summary.bounce_rate', 100)
            ->assertJsonPath('summary.avg_duration_seconds', 20);
        $this->assertEqualsCanonicalizing(['Facebook', 'Direct'], array_column($report->json('sources'), 'name'));
        $this->assertSame(40, collect($report->json('pages'))->firstWhere('path', '/pricing')['avg_seconds']);

        $visits = $this->actingAs($admin)->getJson('/api/admin/site-analytics/visits?source=Facebook')->assertOk();
        $this->assertSame(1, $visits->json('total'));
        $this->assertSame('/pricing', $visits->json('data.0.page_views.0.path'));
        $this->assertSame(40, $visits->json('data.0.page_views.0.duration_seconds'));

        $this->actingAs($admin)->getJson('/api/admin/site-analytics?from=2026-10-10&to=2026-10-01')->assertStatus(422);
    }
}
