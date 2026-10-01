<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Social/Ads Launcher Overhaul — Step 2 (Multipart Media Upload API).
 * Lets a tenant upload the actual image/video for an ad/post creative,
 * replacing the free-text "Image URL" field MetaAdsPage.tsx's wizard
 * previously shipped with (see the module audit's gap analysis, item
 * [C]-1). Returns a durable, PUBLICLY fetchable URL — required because
 * Meta's own servers (Graph API's adcreatives `picture` field) must be
 * able to reach it directly, the same way they'd reach any
 * externally-hosted image URL a tenant might have pasted before.
 *
 * DISCLOSED STORAGE/SERVING DECISION: files are written to the `public`
 * disk by default (storage/app/public/...; configurable since Phase 12
 * Task 1 via social.media.disk / SOCIAL_MEDIA_DISK) regardless of this app's FILESYSTEM_DISK
 * default (which is `local`, i.e. NOT web-servable — see config/
 * filesystems.php), but they are served through this controller's own
 * show() action rather than through Laravel's conventional `storage:link`
 * symlink + direct static serving. Reasoning: this app's primary
 * development target is XAMPP on Windows (per the repo path), where
 * `storage:link` requires an elevated/Developer-Mode symlink that is not
 * guaranteed to succeed silently — a broken symlink would make every
 * uploaded creative 404 with no obvious cause. Serving through a normal
 * Laravel route works identically on every OS/host with zero extra setup
 * step, at the cost of one extra PHP process per file fetch (acceptable
 * for ad-creative media, which Meta fetches once at ad-creation time, not
 * on every impression).
 *
 * DISCLOSED SCOPE BOUNDARY: this endpoint stores and serves the file.
 * It does NOT extend MetaAdsService's ad-creative builder to support
 * Meta's video-ad upload flow (POST /act_X/advideos) — that remains the
 * same disclosed gap the module audit already flagged; a video uploaded
 * here gets a durable URL and renders in AdPreview.tsx, but
 * MetaAdsService::createAdCreative() still only consumes a static image
 * `picture` URL when the tenant actually launches a paid campaign.
 */
class SocialMediaController extends Controller
{
    use ResolvesTenantAccount;

    private const MAX_KILOBYTES = 51200; // 50 MB

    private const IMAGE_MIMES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private const VIDEO_MIMES = ['mp4', 'mov', 'webm', 'm4v'];

    /**
     * POST /api/social/media/upload — multipart/form-data, field `media`.
     * Tenant-scoped storage path (social-media/{account_id}/...) purely
     * for organization/cleanup — the served URL carries no auth check
     * (see show()'s docblock for why that's an accepted, disclosed
     * trade-off, not an oversight).
     */
    public function upload(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        $request->validate([
            'media' => [
                'required',
                'file',
                'mimes:'.implode(',', [...self::IMAGE_MIMES, ...self::VIDEO_MIMES]),
                'max:'.self::MAX_KILOBYTES,
            ],
        ]);

        $file = $request->file('media');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $type = in_array($extension, self::VIDEO_MIMES, true) ? 'video' : 'image';

        $filename = Str::uuid()->toString().'.'.$extension;
        $directory = "social-media/{$account->id}";

        $path = $file->storeAs($directory, $filename, $this->mediaDiskName());

        return response()->json([
            'data' => [
                // Built with url(), NOT route('social.media.show', [...]):
                // Laravel's route() helper percent-encodes '/' inside a
                // {path}->where('path','.*') parameter (e.g. "a%2Fb"), and
                // Apache's default AllowEncodedSlashes=Off (XAMPP's default
                // — this app's own documented dev target, see the repo
                // README) 404s on an encoded slash before the request ever
                // reaches Laravel's router. Plain string concatenation via
                // url() avoids that entirely — same pattern already used by
                // SocialGatewayController's webhook_url.
                'url' => url('/api/media/social/'.$path),
                'path' => $path,
                'type' => $type,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ],
        ], 201);
    }

    /**
     * GET /api/media/social/{path} — deliberately public/unauthenticated
     * (see routes/api.php: NOT inside the auth:sanctum group). An ad
     * creative's image/video URL must be fetchable by Meta's own crawler,
     * which carries no Sanctum bearer token and cannot complete an OAuth
     * or session flow — the same trust model as any externally-hosted
     * image URL a tenant could otherwise have pasted directly. This is
     * NOT a tenant-data leak: the path is an unguessable UUID filename
     * under a per-account directory, never enumerable, and the files it
     * serves are creative assets the tenant explicitly uploaded to make
     * public in an ad/post in the first place — not private records.
     */
    public function show(string $path): \Symfony\Component\HttpFoundation\Response
    {
        // Laravel's {path}->where('path', '.*') already URL-decodes the
        // parameter; reject any attempt to escape the intended directory
        // (defense in depth — Storage's own path resolution already
        // normalizes '..' segments, this is a second, explicit check).
        abort_if(str_contains($path, '..'), 404);
        abort_unless(str_starts_with($path, 'social-media/'), 404);
        $diskName = $this->mediaDiskName();
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);

        // Local-driver disk (the default `public`): response()->file() (a Symfony BinaryFileResponse) rather
        // than Storage::response() — guarantees standard HTTP Range-request support out of the box, so a
        // video creative can be scrubbed/seeked in AdPreview.tsx instead of only played from byte 0.
        // Unchanged from before the disk became configurable.
        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return response()->file($disk->path($path));
        }

        // Phase 12 Task 1 — a shared (non-local) disk has no filesystem path; stream it through the
        // disk. The URL, the authorization model and the path layout are the same.
        return $disk->response($path);
    }

    /**
     * Phase 12 Task 1 — the disk social uploads live on: `social.media.disk` (env SOCIAL_MEDIA_DISK),
     * default `public`, so nothing changes until an operator points it at a shared disk (for example an
     * s3 disk, or a `local`-driver disk on a volume every instance mounts). Never a request value.
     */
    private function mediaDiskName(): string
    {
        return (string) config('social.media.disk', 'public');
    }
}
