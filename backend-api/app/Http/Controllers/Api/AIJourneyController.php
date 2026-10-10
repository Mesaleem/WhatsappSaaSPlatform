<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\JourneyCopywriterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8 Task 14 — "Generate with AI" for the Journey Builder. Sibling
 * of AITemplateController (Task 13): a thin controller, the real work in
 * JourneyCopywriterService, metered through the central AI system.
 *
 * Gated on manage-chatbot — the SAME permission tier
 * WhatsAppFlowController::store() already requires, plus the same
 * module.guard:chatbot + capability.guard:journey_automation pair on the
 * route (see routes/api.php) — drafting a journey's text is not a
 * distinct capability from authoring the journey itself.
 *
 * Returns a DRAFT only — {provider, graph_data: {nodes, edges}}. It
 * never creates or updates a WhatsAppFlow row; the caller
 * (JourneyCanvasEditor) loads the result onto its own canvas and the
 * user reviews/edits it there, saving (if at all) through the existing,
 * untouched WhatsAppFlowController::store()/update() — which revalidates
 * the whole graph independently of anything this controller did.
 *
 * account_id is REQUIRED here for the same reason as AITemplateController:
 * AiAuthorizer has no "global AI" path (see AiAuthorizer::resolveTarget())
 * — a Super Admin must always name the account the draft is generated
 * (and charged) against, even though journeys themselves always belong
 * to exactly one account.
 */
class AIJourneyController extends Controller
{
    /** The Journey Builder's own module and permission, required together with `ai`. */
    public const MODULE = 'chatbot';

    public const PERMISSION = 'manage-chatbot';

    /** Same duplicate-submission window as AITemplateController/AICopywriterController. */
    private const IMPLICIT_KEY_WINDOW_SECONDS = 10;

    /**
     * POST /api/whatsapp/flows/ai-generate
     */
    public function generate(Request $request, JourneyCopywriterService $service, AiAuthorizer $authorizer): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'description' => ['required', 'string', 'max:2000'],
            'goal' => ['nullable', 'string', 'max:300'],
        ]);

        $authorization = $authorizer->forUser($request->user(), (int) $data['account_id'], self::MODULE, self::PERMISSION, 'journey_ai');

        $result = $service->generate(
            $authorization,
            $this->operationKey($request, $data),
            $data['description'],
            $data['goal'] ?? null,
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Same idempotency contract as AITemplateController::operationKey().
     */
    private function operationKey(Request $request, array $data): string
    {
        $userId = (int) $request->user()->id;
        $header = trim((string) $request->header('Idempotency-Key', ''));

        if ($header !== '') {
            if (mb_strlen($header) > 100 || preg_match('/^[A-Za-z0-9._:\-]+$/', $header) !== 1) {
                throw ValidationException::withMessages(['Idempotency-Key' => 'The Idempotency-Key header must be 1-100 letters, digits, ".", "_", ":" or "-".']);
            }

            return "journey_ai:u{$userId}:{$header}";
        }

        $fingerprint = sha1(json_encode([
            $data['account_id'], $data['description'], $data['goal'] ?? '',
        ]));

        return "journey_ai:u{$userId}:auto:{$fingerprint}:".intdiv(now()->getTimestamp(), self::IMPLICIT_KEY_WINDOW_SECONDS);
    }
}
