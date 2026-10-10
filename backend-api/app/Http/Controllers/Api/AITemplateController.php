<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\TemplateCopywriterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8 Task 13 — "Generate with AI" for the Template Designer. Sibling
 * of AICopywriterController (same shape: a thin controller, the real
 * work in a *CopywriterService, metered through the central AI system),
 * gated on manage-templates — the SAME permission store()/update() on
 * MessageTemplateController already require, since drafting a template's
 * text is not a distinct capability from authoring the template itself.
 *
 * Returns a DRAFT only — {provider, title, template_body}. It never
 * creates a MessageTemplate row; the caller pre-fills the normal Template
 * Designer form with the result and saves it (if at all) through the
 * existing, untouched MessageTemplateController::store()/update().
 *
 * account_id is REQUIRED here (unlike store(), where it is optional for a
 * Super Admin making a global template): AiAuthorizer deliberately has no
 * "global AI" path (a Super Admin must always select a target account to
 * authorize/charge) — see AiAuthorizer::resolveTarget(). Which account
 * pays for the draft is independent of which account (or none, for a
 * global template) the eventually-saved MessageTemplate belongs to.
 */
class AITemplateController extends Controller
{
    /** Phase 8 Task 13 — the Template Designer's own module and permission, required together with `ai`. */
    public const MODULE = 'templates';

    public const PERMISSION = 'manage-templates';

    /**
     * Phase 8 Task 6's own duplicate-submission window for requests with
     * no Idempotency-Key header — identical input from the same user
     * within this window is the same operation, charged once.
     */
    private const IMPLICIT_KEY_WINDOW_SECONDS = 10;

    /**
     * POST /api/message-templates/ai-generate
     */
    public function generate(Request $request, TemplateCopywriterService $service, AiAuthorizer $authorizer): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'purpose' => ['required', 'string', 'max:1000'],
            'category' => ['required', 'string', Rule::in(MessageTemplate::META_CATEGORIES)],
            'tone' => ['nullable', 'string', 'max:100'],
            'variable_keys' => ['sometimes', 'array', 'max:10'],
            'variable_keys.*' => ['string', 'max:100', 'regex:/^[a-zA-Z0-9_]+$/'],
        ]);

        $authorization = $authorizer->forUser($request->user(), (int) $data['account_id'], self::MODULE, self::PERMISSION, 'templates');

        $result = $service->generate(
            $authorization,
            $this->operationKey($request, $data),
            $data['purpose'],
            $data['category'],
            $data['tone'] ?? null,
            $data['variable_keys'] ?? [],
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Same idempotency contract as AICopywriterController::operationKey():
     * the client's Idempotency-Key header when present (validated the
     * same way), else a key derived from the caller + exact input, stable
     * for IMPLICIT_KEY_WINDOW_SECONDS so an accidental double-submit is
     * one charge, not two.
     */
    private function operationKey(Request $request, array $data): string
    {
        $userId = (int) $request->user()->id;
        $header = trim((string) $request->header('Idempotency-Key', ''));

        if ($header !== '') {
            if (mb_strlen($header) > 100 || preg_match('/^[A-Za-z0-9._:\-]+$/', $header) !== 1) {
                throw ValidationException::withMessages(['Idempotency-Key' => 'The Idempotency-Key header must be 1-100 letters, digits, ".", "_", ":" or "-".']);
            }

            return "template_ai:u{$userId}:{$header}";
        }

        $fingerprint = sha1(json_encode([
            $data['account_id'], $data['purpose'], $data['category'], $data['tone'] ?? '', $data['variable_keys'] ?? [],
        ]));

        return "template_ai:u{$userId}:auto:{$fingerprint}:".intdiv(now()->getTimestamp(), self::IMPLICIT_KEY_WINDOW_SECONDS);
    }
}
