<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\OrganicPost;
use App\Services\Social\OrganicPublishService;
use App\Services\Social\Publishing\Exceptions\PublishingDenied;
use App\Services\SocialAuth\Exceptions\SocialConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Organic posts — publish now, schedule, list, cancel, retry.
 *
 * Same ResolvesTenantAccount / never-trust-client-account_id model as
 * AdCampaignController: the account is the caller's own (tenant) or the
 * selected target (Super Admin; 422 when none). Every post lookup is
 * forAccount(<that account>) — another account's id is a 404. Route
 * middleware checks the caller (permission, module, capability);
 * OrganicPublishService re-checks the TARGET account (SocialTargetGate)
 * and the connection health on create and retry.
 */
class OrganicPostController extends Controller
{
    use ResolvesTenantAccount;

    private const STATUSES = [
        OrganicPost::STATUS_SCHEDULED, OrganicPost::STATUS_PUBLISHING, OrganicPost::STATUS_PENDING,
        OrganicPost::STATUS_PUBLISHED, OrganicPost::STATUS_FAILED, OrganicPost::STATUS_RECONNECT_REQUIRED,
        OrganicPost::STATUS_CANCELLED,
    ];

    /** GET /api/social/organic-posts[?status=] — latest 50. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        $data = $request->validate(['status' => ['nullable', 'string', Rule::in(self::STATUSES)]]);

        $posts = OrganicPost::query()
            ->forAccount($account->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $posts->map(fn (OrganicPost $p) => $this->present($p))]);
    }

    /** GET /api/social/organic-posts/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        return response()->json(['data' => $this->present(OrganicPost::query()->forAccount($account->id)->findOrFail($id))]);
    }

    /**
     * POST /api/social/organic-posts — publish now, or schedule when
     * `scheduled_at` is given. 201 with the post (its `status` says what
     * happened); 200 with the same post when the idempotency key was
     * already used for this exact request.
     */
    public function store(Request $request, OrganicPublishService $service): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        if (! $request->filled('idempotency_key') && $request->hasHeader('Idempotency-Key')) {
            $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        }

        $maxDays = max(1, (int) config('social.publishing.max_schedule_days', 90));

        $data = $request->validate([
            'platform' => ['required', 'string', Rule::in(OrganicPost::PLATFORMS)],
            'caption' => ['required', 'string', 'max:5000'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'media_type' => ['nullable', 'string', Rule::in(['image', 'video'])],
            'social_account_id' => ['nullable', 'integer', 'min:1'],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays($maxDays)->toIso8601String()],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ], [
            'scheduled_at.after' => 'The scheduled time must be in the future.',
            'scheduled_at.before_or_equal' => "Posts can be scheduled up to {$maxDays} days ahead.",
        ]);

        $data['created_by'] = $request->user()?->id;

        try {
            $result = $service->submit($account, $data);
        } catch (SocialConnectionException $e) {
            // Expired/revoked connection — known beforehand (no row) or reported
            // by the provider mid-publish (the row is reconnect_required): 409.
            return $e->render();
        } catch (PublishingDenied $e) {
            return $e->render();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($result['post']), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    /** POST /api/social/organic-posts/{id}/cancel — only before publishing starts. */
    public function cancel(Request $request, OrganicPublishService $service, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $post = OrganicPost::query()->forAccount($account->id)->findOrFail($id);

        if (! $service->cancel($post)) {
            return response()->json([
                'success' => false,
                'error_code' => 'POST_NOT_CANCELLABLE',
                'message' => 'This post can no longer be cancelled (it is '.str_replace('_', ' ', $post->fresh()->status).').',
            ], 409);
        }

        return response()->json(['data' => $this->present($post->fresh())]);
    }

    /** POST /api/social/organic-posts/{id}/retry — failed / reconnect-required only; queued to send now. */
    public function retry(Request $request, OrganicPublishService $service, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $post = OrganicPost::query()->forAccount($account->id)->findOrFail($id);

        try {
            $post = $service->retry($account, $post);
        } catch (SocialConnectionException $e) {
            return $e->render();
        } catch (PublishingDenied $e) {
            return $e->render();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($post)]);
    }

    /**
     * @return array<string, mixed> never the claim token, the idempotency key or raw provider data
     */
    private function present(OrganicPost $post): array
    {
        return [
            'id' => $post->id,
            'social_account_id' => $post->social_account_id,
            'provider' => $post->provider,
            'platform' => $post->platform,
            'caption' => $post->caption,
            'media_url' => $post->media_url,
            'media_type' => $post->media_type,
            'status' => $post->status,
            'origin' => $post->origin ?? OrganicPost::ORIGIN_MANUAL,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'external_post_id' => $post->external_post_id,
            'error_message' => $post->error_message,
            'failure_code' => $post->failure_code,
            'attempts' => (int) $post->attempts,
            'next_attempt_at' => $post->next_attempt_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'cancelled_at' => $post->cancelled_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
            'can_cancel' => $post->isCancellable(),
            'can_retry' => $post->isRetryable(),
        ];
    }
}
