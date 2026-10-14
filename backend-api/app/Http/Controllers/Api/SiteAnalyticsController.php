<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteVisit;
use App\Services\SiteAnalytics\SiteAnalyticsReport;
use App\Services\SiteAnalytics\SiteTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Website visitor analytics (owner, 2026-10-14).
 *
 * POST /api/public/site-events — the public website's tracker (no login). The body is JSON but
 * may arrive as text/plain: the browser sends it with navigator.sendBeacon / fetch keepalive so
 * the last ping still arrives when a tab closes, and a text/plain body needs no CORS preflight.
 * Always answers 204, so a visitor's page never waits on or reacts to it.
 *
 * GET /api/admin/site-analytics, GET /api/admin/site-analytics/visits — Super Admin only.
 */
class SiteAnalyticsController extends Controller
{
    public function track(Request $request, SiteTrackingService $tracker): Response
    {
        $payload = $request->json()->all() ?: (json_decode((string) $request->getContent(), true) ?: []);

        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'type' => ['required', Rule::in(['view', 'ping', 'signup'])],
            'session_id' => ['required', 'uuid'],
            'visitor_id' => ['required_if:type,view', 'uuid'],
            'view_id' => ['required_unless:type,signup', 'uuid'],
            'path' => ['required_if:type,view', 'string', 'max:500'],
            'title' => ['nullable', 'string', 'max:255'],
            'referrer' => ['nullable', 'string', 'max:1000'],
            'host' => ['nullable', 'string', 'max:255'],
            'seconds' => ['required_if:type,ping', 'integer', 'min:0'],
            'utm' => ['nullable', 'array'],
            'utm.source' => ['nullable', 'string', 'max:100'],
            'utm.medium' => ['nullable', 'string', 'max:100'],
            'utm.campaign' => ['nullable', 'string', 'max:150'],
        ]);

        if (! $validator->fails()) {
            $tracker->record($validator->validated(), $request->ip(), $request->userAgent(), $request->header('CF-IPCountry'));
        }

        return response()->noContent();
    }

    public function report(Request $request, SiteAnalyticsReport $report): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($report->build($from, $to));
    }

    /** The visits themselves, newest first, each with the pages opened and the time on each. */
    public function visits(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $data = $request->validate([
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'signed_up' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $page = SiteVisit::query()
            ->whereBetween('started_at', [$from, $to])
            ->when($data['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when(array_key_exists('signed_up', $data), fn ($q) => $q->where('signed_up', (bool) $data['signed_up']))
            ->with('pageViews:id,site_visit_id,path,title,duration_seconds,entered_at')
            ->orderByDesc('started_at')
            ->paginate($data['per_page'] ?? 25, [
                'id', 'visitor_id', 'is_returning', 'ip', 'country', 'device', 'browser', 'os', 'referrer', 'source',
                'utm_source', 'utm_medium', 'utm_campaign', 'landing_path', 'exit_path', 'page_count', 'duration_seconds', 'signed_up', 'started_at', 'last_seen_at',
            ]);

        return response()->json($page);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        return [$from, $to];
    }
}
