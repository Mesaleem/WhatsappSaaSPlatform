<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Services\Ads\AdsDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10 Task 3 — GET /api/social/ads/dashboard.
 *
 * Route gates (routes/api.php, the Meta Ads launcher group): permission
 * launch-meta-ads|social_ads.view, module.guard:meta_ads, capability.guard:ads,
 * target.account:meta_ads,ads — the same read contract as GET /api/social/ads
 * (a lapsed subscription still reads). A Super Admin must select a client
 * (requireTargetAccount → 422 on every environment). Reads stored data only
 * (AdsDashboardService); never calls Meta.
 *
 * `range` = 7d | 30d (default) | 90d | custom with `from` / `to` (Y-m-d,
 * inclusive, at most 366 days, not after today).
 */
class AdsDashboardController extends Controller
{
    use ResolvesTenantAccount;

    public const MAX_CUSTOM_DAYS = 366;

    public function __construct(private readonly AdsDashboardService $dashboard)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->dashboard->dashboard($account->id, $from, $to)]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'range' => ['nullable', 'string', Rule::in(['7d', '30d', '90d', 'custom'])],
            'from' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d'],
        ]);

        $today = CarbonImmutable::now()->startOfDay();
        $range = $data['range'] ?? '30d';

        if ($range !== 'custom') {
            return [$today->subDays((int) rtrim($range, 'd') - 1), $today];
        }

        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'])->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'])->startOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        if ($to->gt($today)) {
            throw ValidationException::withMessages(['to' => 'The end date cannot be in the future.']);
        }
        if ($from->diffInDays($to) + 1 > self::MAX_CUSTOM_DAYS) {
            throw ValidationException::withMessages(['from' => 'A custom range can cover at most '.self::MAX_CUSTOM_DAYS.' days.']);
        }

        return [$from, $to];
    }
}
