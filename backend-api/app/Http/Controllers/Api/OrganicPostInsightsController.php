<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\OrganicPost;
use App\Models\OrganicPostInsight;
use App\Services\Social\Insights\PostInsightsService;
use App\Services\Social\Publishing\Exceptions\PublishingDenied;
use App\Services\Social\SocialTargetGate;
use App\Services\SocialAuth\Exceptions\SocialConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 Task 4 — organic post insights (read the stored snapshot,
 * explicit refresh, account summary).
 *
 * Authorization, in order: route middleware checks the CALLER (auth,
 * tenant isolation, `view-social-analytics`, social_accounts module,
 * `social` capability); requireAccount() resolves the account (Super Admin
 * must pass ?account_id=, else 422); SocialTargetGate checks the TARGET
 * account (active, subscription, module, capability — no Super Admin
 * bypass); the post is looked up forAccount() (another account's id → 404,
 * whatever post or provider id the caller knows); the service only uses a
 * connection owned by the post's account. Pages never call the provider.
 */
class OrganicPostInsightsController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly PostInsightsService $insights)
    {
    }

    /** GET /api/social/organic-posts/{id}/insights — stored snapshot only. */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->targetAccount($request);
        $post = OrganicPost::query()->forAccount($account->id)->with('insight')->findOrFail($id);

        return response()->json(['data' => $this->view($post, $post->insight)]);
    }

    /** POST /api/social/organic-posts/{id}/insights/refresh — one controlled provider fetch. */
    public function refresh(Request $request, int $id): JsonResponse
    {
        $account = $this->targetAccount($request);
        $post = OrganicPost::query()->forAccount($account->id)->findOrFail($id);

        if ($reason = $this->insights->ineligibility($post)) {
            return response()->json([
                'success' => false,
                'error_code' => 'INSIGHTS_NOT_AVAILABLE',
                'reason' => $reason,
                'message' => $this->ineligibleMessage($reason),
            ], 422);
        }

        $result = $this->insights->refresh($post, explicit: true);

        return response()->json(['data' => $this->view($post, $result['insight']), 'outcome' => $result['outcome']]);
    }

    /** GET /api/social/organic-posts/insights/summary — account totals + the latest 50 posts' snapshots. */
    public function summary(Request $request): JsonResponse
    {
        $account = $this->targetAccount($request);

        $select = ['COUNT(*) as posts_with_insights', 'MAX(metrics_fetched_at) as last_fetched_at'];
        foreach (OrganicPostInsight::METRICS as $metric) {
            $select[] = "SUM({$metric}) as sum_{$metric}";
            $select[] = "COUNT({$metric}) as count_{$metric}";
        }

        $aggregate = OrganicPostInsight::query()
            ->forAccount($account->id)
            ->whereNotNull('metrics_fetched_at')
            ->whereHas('post', fn ($q) => $q->where('account_id', $account->id)->where('status', OrganicPost::STATUS_PUBLISHED))
            ->selectRaw(implode(', ', $select))
            ->first();

        $totals = [];
        foreach (OrganicPostInsight::METRICS as $metric) {
            $count = (int) ($aggregate?->{"count_{$metric}"} ?? 0);
            // No post reported the metric → null (unavailable), never 0.
            $totals[$metric] = ['value' => $count > 0 ? (int) $aggregate->{"sum_{$metric}"} : null, 'posts' => $count];
        }

        $posts = OrganicPost::query()->forAccount($account->id)->with('insight')->latest()->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => [
            'posts_published' => OrganicPost::query()->forAccount($account->id)->where('status', OrganicPost::STATUS_PUBLISHED)->count(),
            'posts_with_insights' => (int) ($aggregate?->posts_with_insights ?? 0),
            'last_fetched_at' => $aggregate?->last_fetched_at ? \Illuminate\Support\Carbon::parse($aggregate->last_fetched_at)->toIso8601String() : null,
            'totals' => $totals,
            'posts' => $posts->map(fn (OrganicPost $p) => $this->view($p, $p->insight))->values(),
        ]]);
    }

    private function targetAccount(Request $request): Account
    {
        $account = $this->requireTargetAccount($request);

        if ($denial = app(SocialTargetGate::class)->denial($account, 'view social insights')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(PublishingDenied::target($denial)->render());
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function view(OrganicPost $post, ?OrganicPostInsight $insight): array
    {
        $view = $this->insights->present($post, $insight);

        if ($view['state'] === OrganicPostInsight::STATE_RECONNECT_REQUIRED) {
            $view['reconnect_path'] = SocialConnectionException::RECONNECT_PATH;
        }

        return $view;
    }

    private function ineligibleMessage(string $reason): string
    {
        return match ($reason) {
            'missing_provider_post_id' => 'This post has no platform post id, so the platform cannot report insights for it.',
            'outcome_unknown' => 'It is not known whether this post was published, so there are no insights to fetch.',
            'cancelled' => 'This post was cancelled and never published.',
            'failed' => 'This post was not published.',
            'platform_unsupported' => 'Insights are not available for this platform yet.',
            default => 'Insights are available once the post is published.',
        };
    }
}
