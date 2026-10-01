<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Social\Insights\SocialAnalyticsService;
use App\Services\Social\Publishing\Exceptions\PublishingDenied;
use App\Services\Social\SocialTargetGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 9 Task 5 — account-level social analytics dashboard, read from the
 * persisted Task 4 insight snapshots only (never calls a provider).
 *
 * Authorization, in order: route middleware checks the CALLER
 * (`view-social-analytics` — NOT manage-social-accounts — the
 * social_accounts module and the `social` capability); requireTargetAccount()
 * resolves the account (a Super Admin must select one with ?account_id=,
 * otherwise 422 — the platform account is never used); SocialTargetGate
 * checks the TARGET account (active, subscription, module, capability; no
 * Super Admin bypass); every query is filtered by that account id.
 *
 * Ranges: `range` = 7d | 30d | 90d (default 30d, ending today), or
 * `range=custom` with `from` / `to` (Y-m-d, inclusive, at most 366 days).
 */
class SocialAnalyticsController extends Controller
{
    use ResolvesTenantAccount;

    public const MAX_CUSTOM_DAYS = 366;

    public function __construct(private readonly SocialAnalyticsService $analytics)
    {
    }

    /** GET /api/social/analytics/dashboard */
    public function dashboard(Request $request): JsonResponse
    {
        $account = $this->targetAccount($request);
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->analytics->dashboard($account->id, $from, $to)]);
    }

    /** GET /api/social/analytics/top-posts?metric=engagement|reach|impressions|video_views */
    public function topPosts(Request $request): JsonResponse
    {
        $account = $this->targetAccount($request);
        [$from, $to] = $this->range($request);
        $data = $request->validate([
            'metric' => ['nullable', 'string', Rule::in(SocialAnalyticsService::TOP_METRICS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $metric = $data['metric'] ?? 'engagement';

        return response()->json(['data' => [
            'metric' => $metric,
            'posts' => $this->analytics->topPosts($account->id, $from, $to, $metric, (int) ($data['limit'] ?? 10)),
        ]]);
    }

    private function targetAccount(Request $request): Account
    {
        // requireTargetAccount(): no APP_ENV=local first-account fallback — a Super
        // Admin with no ?account_id= gets 422 on every environment (Phase 9 Task 6).
        $account = $this->requireTargetAccount($request);

        if ($denial = app(SocialTargetGate::class)->denial($account, 'view social analytics')) {
            throw new HttpResponseException(PublishingDenied::target($denial)->render());
        }

        return $account;
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
            $days = (int) rtrim($range, 'd');

            return [$today->subDays($days - 1), $today];
        }

        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'])->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'])->startOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        if ($from->diffInDays($to) + 1 > self::MAX_CUSTOM_DAYS) {
            throw ValidationException::withMessages(['from' => 'A custom range can cover at most '.self::MAX_CUSTOM_DAYS.' days.']);
        }

        return [$from, $to];
    }
}
