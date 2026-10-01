<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\CopywriterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Social/Ads Launcher Overhaul — Step 1 (Gemini Pro Engine & Multi-Token
 * Prompt Refactor). AI Ad Copywriter & Creative Builder — embedded as
 * the "Generate with AI" button on MetaAdsPage.tsx's Ad Creation
 * Wizard (creative step). Gated on launch-meta-ads (same permission as
 * the wizard itself), not a new permission — generating draft copy is
 * not a distinct capability from launching the campaign it's for.
 *
 * Phase 8 Task 6: generation is metered AI — it runs through
 * MeteredAiService for the target account (AiAuthorizer), consumes that
 * account's AI credits and records a metadata-only ai_operations row.
 * The per-tenant Gemini key resolution is gone (see CopywriterService).
 */
class AICopywriterController extends Controller
{
    use ResolvesTenantAccount;

    /**
     * Phase 8 Task 6 — duplicate-submission window for requests that carry
     * no Idempotency-Key header (older clients): identical input from the
     * same user within this window is the same operation, charged once.
     */
    private const IMPLICIT_KEY_WINDOW_SECONDS = 10;

    /** Phase 8 Task 6 — the Ads feature's own module and permission, required together with `ai`. */
    public const MODULE = 'meta_ads';

    public const PERMISSION = 'launch-meta-ads';

    /**
     * POST /api/social/ai/generate
     *
     * Phase 8 Task 6 — authorization is the existing route permission
     * (launch-meta-ads) PLUS AiAuthorizer on the target account the tenant
     * middleware resolved: account active, subscription current, the
     * meta_ads module, launch-meta-ads again, and the `ai` capability.
     * Credits are consumed from that target account (MeteredAiService),
     * never from a Super Admin's or an Agent's own account.
     */
    public function generate(Request $request, CopywriterService $service, AiAuthorizer $authorizer): JsonResponse
    {
        // Unchanged first step: a Super Admin with no client selected gets the
        // same 422 as before.
        $this->requireTargetAccount($request);

        $data = $request->validate([
            // Renamed from the prior 'product_name' — the frontend was
            // actually sending the CAMPAIGN name under that key, not a
            // real business/product name; this refactor gives it its own
            // honest field instead of perpetuating that mislabeling.
            'business_name' => ['required', 'string', 'max:255'],
            'target_industry' => ['required', 'string', 'max:255'],
            // New. Optional — a tenant with no specific promotion running
            // still gets useful copy (see CopywriterService::userPrompt()'s
            // "No specific promotion" fallback phrasing).
            'offer_details' => ['nullable', 'string', 'max:500'],
            // New. Shapes tone/CTA framing (organic engagement vs a paid
            // lead ad's conversion push) — see CopywriterService::TARGET_GOALS.
            'target_goal' => ['required', 'string', Rule::in(CopywriterService::TARGET_GOALS)],
            'tone' => ['required', 'string', 'max:100'],
        ]);

        $authorization = $authorizer->forRequest($request, self::MODULE, self::PERMISSION, 'ads');

        $result = $service->generate(
            $authorization,
            $this->operationKey($request, $data),
            $data['business_name'],
            $data['target_industry'],
            $data['offer_details'] ?? '',
            $data['target_goal'],
            $data['tone'],
        );

        return response()->json(['data' => $result]);
    }

    /**
     * One operation = one charge (MeteredAiService refuses a key already run).
     *
     *   Idempotency-Key header (the frontend sends one per click; its own
     *   retries reuse it)  → "adcopy:u{user}:{key}"
     *   no header          → "adcopy:u{user}:auto:{sha1(input)}:{10-second window}"
     *
     * Scoped by user (keys are unique per billed account, and two users of one
     * account must not collide). Never read as authorization.
     *
     * @param array<string, mixed> $data
     */
    private function operationKey(Request $request, array $data): string
    {
        $userId = (int) $request->user()->id;
        $header = trim((string) $request->header('Idempotency-Key', ''));

        if ($header !== '') {
            if (mb_strlen($header) > 100 || preg_match('/^[A-Za-z0-9._:\-]+$/', $header) !== 1) {
                throw ValidationException::withMessages(['Idempotency-Key' => 'The Idempotency-Key header must be 1-100 letters, digits, ".", "_", ":" or "-".']);
            }

            return "adcopy:u{$userId}:{$header}";
        }

        $fingerprint = sha1(json_encode([
            $data['business_name'], $data['target_industry'], $data['offer_details'] ?? '', $data['target_goal'], $data['tone'],
        ]));

        return "adcopy:u{$userId}:auto:{$fingerprint}:".intdiv(now()->getTimestamp(), self::IMPLICIT_KEY_WINDOW_SECONDS);
    }
}
