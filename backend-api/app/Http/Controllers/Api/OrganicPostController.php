<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\OrganicPost;
use App\Services\Social\OrganicPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Social/Ads Launcher Overhaul — Step 3 (Organic Multi-Channel Publishing
 * Engine). Same ResolvesTenantAccount / never-trust-client-account_id
 * security model as AdCampaignController — see that controller's
 * docblock for the full reasoning.
 */
class OrganicPostController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/social/organic-posts — post history for the Mode Toggle's "Organic Post" view. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $posts = OrganicPost::query()->forAccount($account->id)->latest()->limit(50)->get();

        return response()->json(['data' => $posts->map(fn (OrganicPost $p) => $this->present($p))]);
    }

    /**
     * POST /api/social/organic-posts — publish now.
     *
     * Unlike AdCampaignController::launch(), a RuntimeException from
     * OrganicPublishService::publish() itself (not a driver failure —
     * those are caught internally and logged onto the returned row,
     * see that service's docblock) means no connected asset exists for
     * the platform at all; that pre-flight case is still returned as a
     * 422, consistent with the rest of this codebase's error handling.
     */
    public function store(Request $request, OrganicPublishService $service): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'platform' => ['required', 'string', Rule::in(OrganicPost::PLATFORMS)],
            'caption' => ['required', 'string', 'max:5000'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'media_type' => ['nullable', 'string', Rule::in(['image', 'video'])],
        ]);

        try {
            $post = $service->publish($account, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // 201 regardless of whether the actual publish succeeded or
        // failed — the resource (the log row) was created either way;
        // the tenant reads success/failure off `status`/`error_message`
        // in the response body, same "log everything, surface via the
        // row" pattern the spec asked for.
        return response()->json(['data' => $this->present($post)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrganicPost $post): array
    {
        return [
            'id' => $post->id,
            'provider' => $post->provider,
            'platform' => $post->platform,
            'caption' => $post->caption,
            'media_url' => $post->media_url,
            'media_type' => $post->media_type,
            'status' => $post->status,
            'external_post_id' => $post->external_post_id,
            'error_message' => $post->error_message,
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }
}
