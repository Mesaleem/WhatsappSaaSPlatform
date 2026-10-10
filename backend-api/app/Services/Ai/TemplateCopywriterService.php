<?php

namespace App\Services\Ai;

use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 8 Task 13 — "Generate with AI" for the Template Designer
 * (AITemplateController). A normal consumer of the central AI system,
 * exactly like CopywriterService (its sibling and the pattern this
 * mirrors):
 *
 *   Template Designer → MeteredAiService → AiService → AiManager → provider
 *
 * SCOPE, DELIBERATELY NARROW: this returns a DRAFT {title, template_body}
 * only. It never creates or touches a MessageTemplate row — the caller
 * (AITemplateController::generate()) hands the draft back to the SAME
 * Template Designer form a human fills in by hand, and the EXISTING
 * MessageTemplateController::store()/update() path (with its unchanged
 * assertMetaTemplateFields()/assertVariableStructure()/
 * assertComponentStructure() validation and approval workflow) is what
 * actually saves it. Nothing about template creation, approval, or
 * variables_schema/component structure is touched by this class.
 *
 * WHY THIS SHAPE (disclosed, not incidental): the ConnexxaIQ Template
 * AI-generate feature this task was inspired by has a disclosed bug —
 * its `generate_with_groq_fast()` treats the user's prompt as only a
 * "reference example" and then silently REPLACES the generated body via
 * a >50%-word-overlap "anti-copy" heuristic, so what the user asked for
 * is not reliably what they get back. This class has no such step: the
 * provider's answer (validated only for "is it a usable template body",
 * same tier of check as CopywriterService::usableVariants()) IS what is
 * returned, and the user reviews/edits it themselves before anything is
 * saved — there is no silent rewrite anywhere in this path.
 *
 * {{key}} PLACEHOLDERS: the prompt instructs the model to use ONLY the
 * caller-supplied variable keys (if any) as `{{key}}` tokens — the exact
 * token syntax `MessageTemplate::undeclaredVariableNamesFor()` already
 * parses — so a generated body that is copied straight into the Template
 * Designer and saved with those same keys declared in variables_schema
 * passes store()'s existing assertVariableStructure() check unmodified.
 * This class does not enforce that the model actually only used those
 * keys (the user reviews before saving either way); it is prompt
 * guidance, not a new validation layer.
 */
class TemplateCopywriterService
{
    /** Logged operation label (not a price — pricing is AiCreditPricing's, token-based). */
    public const OPERATION = 'templates.ai_generate';

    /** Provider-side failures that degrade to the deterministic fallback (never an entitlement/credit refusal). */
    private const TEMPLATE_FALLBACK = [
        AiException::PROVIDER_NOT_CONFIGURED,
        AiException::PROVIDER_UNAVAILABLE,
        AiException::PROVIDER_FAILED,
        AiException::PROVIDER_TIMEOUT,
        AiException::MALFORMED_RESPONSE,
    ];

    private const MAX_TOKENS = 700;

    private const TEMPERATURE = 0.7;

    /** MessageTemplate.template_body's own DB column cap (store()'s own validation rule). */
    private const MAX_BODY_LENGTH = 4000;

    public function __construct(private readonly MeteredAiService $metered)
    {
    }

    /**
     * @param list<string> $variableKeys caller-supplied `{{key}}` names the body should use, if any — e.g. ['customer_name', 'order_id']
     * @return array{provider: string, title: string, template_body: string}
     *
     * @throws AiException authorization / credit / duplicate refusals
     */
    public function generate(
        AiAuthorization $authorization,
        string $operationKey,
        string $purpose,
        string $category,
        ?string $tone,
        array $variableKeys = [],
    ): array {
        $tokens = compact('purpose', 'category', 'tone', 'variableKeys');

        $request = new AiRequest(
            prompt: $this->userPrompt($tokens),
            system: $this->systemPrompt(),
            maxTokens: self::MAX_TOKENS,
            temperature: self::TEMPERATURE,
            operation: self::OPERATION,
            requiredKeys: ['title', 'template_body'],
        );

        try {
            $result = $this->metered->generateStructured(
                $authorization,
                $request,
                $operationKey,
                accept: fn (AiResponse $response) => $this->usableDraft($response->data) !== null,
            );

            return ['provider' => $result->response->provider, ...$this->usableDraft($result->response->data)];
        } catch (AiException $e) {
            if (! in_array($e->errorCode, self::TEMPLATE_FALLBACK, true)) {
                throw $e;
            }

            // Metadata only — never the vendor's message or the prompt.
            Log::warning('TemplateCopywriterService: AI draft unavailable, serving the deterministic fallback.', [
                'account_id' => $authorization->account->id,
                'error_code' => $e->errorCode,
            ]);

            return ['provider' => 'template', ...$this->generateFromTemplate($tokens)];
        }
    }

    private function systemPrompt(): string
    {
        return 'You write WhatsApp Business message templates for Meta\'s template system. Always reply with ONLY '.
            'a JSON object of the exact shape {"title": string, "template_body": string} — no prose, no markdown '.
            'fences, no explanation. "title" is a short internal name (max 60 chars). "template_body" is the actual '.
            'message text WhatsApp customers will receive: concise (WhatsApp templates read best under roughly '.
            '1024 characters), plain and professional, with no markdown formatting. '.
            'If the request lists variable names, use them as placeholders in the EXACT form {{variable_name}} '.
            '(double curly braces, the exact name given, nothing else) at the natural point in the message — '.
            'never invent a placeholder name that was not given, and never use numeric placeholders like {{1}}.';
    }

    /**
     * @param array{purpose: string, category: string, tone: ?string, variableKeys: list<string>} $tokens
     */
    private function userPrompt(array $tokens): string
    {
        $categoryInstruction = match ($tokens['category']) {
            'AUTHENTICATION' => 'This is an AUTHENTICATION template: Meta requires these to be short, contain no marketing language, and center on a one-time code or verification step.',
            'UTILITY' => 'This is a UTILITY template: a transactional, non-promotional update about an existing order, account, or request the customer already has with this business.',
            default => 'This is a MARKETING template: it may be persuasive and promotional, but must stay truthful and avoid anything Meta would flag (no fake urgency, no misleading claims).',
        };

        $variableInstruction = $tokens['variableKeys'] !== []
            ? 'Use exactly these variable names as {{placeholders}}, each at least once, in a natural position: '.implode(', ', $tokens['variableKeys']).'.'
            : 'No specific variables were requested — write a static message with no {{placeholders}} unless the purpose clearly needs a personalized detail, in which case choose a short, obvious variable name yourself.';

        return sprintf(
            "Business purpose for this message: \"%s\". Tone: \"%s\".\n%s\n%s\n".
            'Write the WhatsApp template title and body for this purpose.',
            $tokens['purpose'],
            $tokens['tone'] ?? 'Professional',
            $categoryInstruction,
            $variableInstruction,
        );
    }

    /**
     * @param array<string, mixed>|null $decoded
     * @return array{title: string, template_body: string}|null
     */
    private function usableDraft(?array $decoded): ?array
    {
        $title = $decoded['title'] ?? null;
        $body = $decoded['template_body'] ?? null;

        if (! is_string($title) || trim($title) === '' || ! is_string($body) || trim($body) === '') {
            return null;
        }

        $body = mb_substr($body, 0, self::MAX_BODY_LENGTH);

        return ['title' => mb_substr(trim($title), 0, 255), 'template_body' => trim($body)];
    }

    /**
     * Deterministic, dependency-free, zero-external-call fallback —
     * mirrors CopywriterService::generateFromTemplates()'s role: a
     * provider outage never blocks a Super Admin/Agent from working.
     *
     * @param array{purpose: string, category: string, tone: ?string, variableKeys: list<string>} $tokens
     * @return array{title: string, template_body: string}
     */
    private function generateFromTemplate(array $tokens): array
    {
        $placeholders = implode(' ', array_map(fn (string $key) => "{{{$key}}}", $tokens['variableKeys']));
        $body = trim(sprintf('Hello%s. %s', $placeholders !== '' ? ' '.$placeholders : '', rtrim($tokens['purpose'], '. ').'.'));

        return [
            'title' => Str::limit(Str::title($tokens['purpose']), 60, ''),
            'template_body' => mb_substr($body, 0, self::MAX_BODY_LENGTH),
        ];
    }
}
