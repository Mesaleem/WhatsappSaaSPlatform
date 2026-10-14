<?php

namespace App\Services\SiteAnalytics;

use App\Models\SitePageView;
use App\Models\SiteVisit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Website visitor analytics — records what the public-site tracker sends (see
 * frontend website/useSiteTracking.ts):
 *  - view:   a page was opened. The first view of a session creates the visit (landing page,
 *            source, device); every view adds a page row.
 *  - ping:   the seconds a page has been visible so far (sent every 15 s and when the tab is
 *            hidden or closed). Only ever raises the stored time, so a late or repeated ping
 *            cannot shrink it.
 *  - signup: the visitor created an account during this session.
 * Bots are ignored. Times are capped so one forgotten tab cannot distort the averages.
 */
class SiteTrackingService
{
    /** A page counted as open for longer than this is treated as left open in the background. */
    public const MAX_PAGE_SECONDS = 1800;

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|whatsapp\/|curl|wget|python|monitor/i';

    /** @param array<string, mixed> $event */
    public function record(array $event, ?string $ip, ?string $userAgent, ?string $country): void
    {
        if ($userAgent === null || $userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent)) {
            return;
        }

        match ($event['type']) {
            'view' => $this->view($event, $ip, $userAgent, $country),
            'ping' => $this->ping($event),
            'signup' => SiteVisit::query()->where('session_id', $event['session_id'])->update(['signed_up' => true, 'last_seen_at' => now()]),
            default => null,
        };
    }

    /** @param array<string, mixed> $e */
    private function view(array $e, ?string $ip, string $userAgent, ?string $country): void
    {
        $path = $this->path($e['path']);

        DB::transaction(function () use ($e, $ip, $userAgent, $country, $path) {
            $visit = SiteVisit::query()->where('session_id', $e['session_id'])->lockForUpdate()->first();

            if (! $visit) {
                try {
                    $visit = SiteVisit::query()->create([
                        'session_id' => $e['session_id'],
                        'visitor_id' => $e['visitor_id'],
                        'is_returning' => SiteVisit::query()->where('visitor_id', $e['visitor_id'])->exists(),
                        'ip' => $ip,
                        'country' => $country !== null && preg_match('/^[A-Z]{2}$/', $country) && $country !== 'XX' ? $country : null,
                        'user_agent' => mb_substr($userAgent, 0, 512),
                        ...self::device($userAgent),
                        'referrer' => isset($e['referrer']) ? mb_substr((string) $e['referrer'], 0, 512) : null,
                        'source' => self::source($e['utm']['source'] ?? null, $e['referrer'] ?? null, $e['host'] ?? null),
                        'utm_source' => $e['utm']['source'] ?? null,
                        'utm_medium' => $e['utm']['medium'] ?? null,
                        'utm_campaign' => $e['utm']['campaign'] ?? null,
                        'landing_path' => $path,
                        'started_at' => now(),
                        'last_seen_at' => now(),
                    ]);
                } catch (QueryException) {
                    // Two first views of the same session raced; the other one created the visit.
                    $visit = SiteVisit::query()->where('session_id', $e['session_id'])->lockForUpdate()->firstOrFail();
                }
            }

            $created = SitePageView::query()->firstOrCreate(
                ['view_id' => $e['view_id']],
                ['site_visit_id' => $visit->id, 'path' => $path, 'title' => isset($e['title']) ? mb_substr((string) $e['title'], 0, 255) : null, 'entered_at' => now()],
            );

            if ($created->wasRecentlyCreated) {
                $visit->forceFill(['page_count' => $visit->page_count + 1, 'exit_path' => $path, 'last_seen_at' => now()])->save();
            }
        });
    }

    /** @param array<string, mixed> $e */
    private function ping(array $e): void
    {
        $seconds = max(0, min(self::MAX_PAGE_SECONDS, (int) $e['seconds']));

        DB::transaction(function () use ($e, $seconds) {
            $view = SitePageView::query()->where('view_id', $e['view_id'])->lockForUpdate()->first();
            if (! $view || $view->visit()->value('session_id') !== $e['session_id'] || $seconds <= $view->duration_seconds) {
                return;
            }

            $view->forceFill(['duration_seconds' => $seconds])->save();
            $total = (int) SitePageView::query()->where('site_visit_id', $view->site_visit_id)->sum('duration_seconds');
            SiteVisit::query()->whereKey($view->site_visit_id)->update(['duration_seconds' => $total, 'exit_path' => $view->path, 'last_seen_at' => now()]);
        });
    }

    private function path(string $raw): string
    {
        $path = (string) parse_url($raw, PHP_URL_PATH);

        return mb_substr($path !== '' ? $path : '/', 0, 255);
    }

    /** Where the visit came from: the UTM source when given, else the referring site, else Direct. */
    public static function source(?string $utmSource, ?string $referrer, ?string $ownHost): string
    {
        if ($utmSource !== null && trim($utmSource) !== '') {
            return mb_substr(ucfirst(strtolower(trim($utmSource))), 0, 64);
        }

        $host = $referrer ? strtolower((string) parse_url($referrer, PHP_URL_HOST)) : '';
        $host = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $host);
        if ($host === '' || ($ownHost && $host === preg_replace('/^www\./', '', strtolower($ownHost)))) {
            return 'Direct';
        }

        $known = [
            'Google' => '/(^|\.)google\./', 'Bing' => '/(^|\.)bing\.com$/', 'Yahoo' => '/(^|\.)yahoo\./', 'DuckDuckGo' => '/duckduckgo\.com$/',
            'Facebook' => '/(^|\.)(facebook\.com|fb\.com|fb\.me)$/', 'Instagram' => '/(^|\.)instagram\.com$/',
            'WhatsApp' => '/(^|\.)(whatsapp\.com|wa\.me)$/', 'YouTube' => '/(^|\.)(youtube\.com|youtu\.be)$/',
            'LinkedIn' => '/(^|\.)(linkedin\.com|lnkd\.in)$/', 'X (Twitter)' => '/(^|\.)(twitter\.com|x\.com|t\.co)$/',
            'ChatGPT' => '/(^|\.)(chatgpt\.com|openai\.com)$/',
        ];
        foreach ($known as $name => $pattern) {
            if (preg_match($pattern, $host)) {
                return $name;
            }
        }

        return mb_substr($host, 0, 64);
    }

    /** @return array{device: string, browser: string, os: string} */
    public static function device(string $ua): array
    {
        $device = preg_match('/ipad|tablet|(android(?!.*mobile))/i', $ua) ? 'tablet' : (preg_match('/mobi|iphone|android/i', $ua) ? 'mobile' : 'desktop');

        $browser = match (true) {
            (bool) preg_match('/edg\//i', $ua) => 'Edge',
            (bool) preg_match('/opr\/|opera/i', $ua) => 'Opera',
            (bool) preg_match('/samsungbrowser/i', $ua) => 'Samsung Internet',
            (bool) preg_match('/firefox|fxios/i', $ua) => 'Firefox',
            (bool) preg_match('/chrome|crios/i', $ua) => 'Chrome',
            (bool) preg_match('/safari/i', $ua) => 'Safari',
            default => 'Other',
        };

        $os = match (true) {
            (bool) preg_match('/windows/i', $ua) => 'Windows',
            (bool) preg_match('/iphone|ipad|ios/i', $ua) => 'iOS',
            (bool) preg_match('/android/i', $ua) => 'Android',
            (bool) preg_match('/mac os|macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/linux/i', $ua) => 'Linux',
            default => 'Other',
        };

        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }
}
