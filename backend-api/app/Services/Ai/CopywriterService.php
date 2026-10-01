<?php

namespace App\Services\Ai;

use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use Illuminate\Support\Facades\Log;

/**
 * Social/Ads Launcher Overhaul — Step 1. AI Ad Copywriter
 * (AICopywriterController). Produces 5 ad-copy variants, each a
 * {hook, caption, cta} triple — a tenant picking "variant 3" needs its
 * hook/caption/cta to belong together, which is what the wizard needs to
 * fill headline/primary_text with a coherent option.
 *
 * PHASE 8 TASK 6 — a normal consumer of the central AI system:
 *
 *   Ad Copywriter → MeteredAiService → AiService → AiManager → provider
 *
 * This class no longer knows any vendor, key, model, provider order,
 * credit, hold or settlement. It builds the SAME prompts as before
 * (systemPrompt()/userPrompt(), unchanged), asks for a structured answer,
 * validates it with the SAME rules (parseVariants(), unchanged), and
 * returns the SAME shape: {provider, variants}.
 *
 * The caller passes an AiAuthorization (AiAuthorizer: target account,
 * account/subscription state, the meta_ads module, launch-meta-ads and the
 * `ai` capability) and an operation key (one charge per key). Credits are
 * consumed from the authorization's target account by MeteredAiService.
 *
 * FALLBACK (kept from before): when the AI provider cannot produce usable
 * copy — no provider configured, provider unavailable/failed/timed out,
 * or an answer without a usable variant — the deterministic template
 * engine answers (provider "template"), exactly as it did when every
 * vendor in the old chain failed. MeteredAiService has already released
 * the hold, so template copy is never charged. It is NOT a way around the
 * account's limits: authorization, entitlement, insufficient-credit and
 * duplicate-operation refusals are returned as errors, never templated.
 *
 * REMOVED (Phase 8 Task 6, disclosed): the direct Gemini / OpenAI /
 * Anthropic HTTP calls and the per-tenant Gemini key resolution
 * (accounts.gemini_api_key → platform social_provider_configs 'gemini' →
 * GEMINI_API_KEY). Phase 8 Task 8 added Gemini to the central provider
 * layer: with AI_PROVIDER=gemini the copy is served by it through the same
 * path, with no Gemini-specific code here (platform key only; the per-tenant
 * key is still not read).
 */
class CopywriterService
{
    /** Meta Ads objectives this app supports also gate campaign creation (see AdCampaign::OBJECTIVES); this is a SEPARATE, coarser concept the AI prompt uses to shape tone/CTA. */
    public const TARGET_GOALS = ['ORGANIC_POST', 'PAID_LEAD_AD'];

    /** Logged operation label (not a price — pricing is AiCreditPricing's, token-based). */
    public const OPERATION = 'ads.copywriter';

    /** Provider-side failures that degrade to the template engine (never an entitlement/credit refusal). */
    private const TEMPLATE_FALLBACK = [
        AiException::PROVIDER_NOT_CONFIGURED,
        AiException::PROVIDER_UNAVAILABLE,
        AiException::PROVIDER_FAILED,
        AiException::PROVIDER_TIMEOUT,
        AiException::MALFORMED_RESPONSE,
    ];

    /** Answer budget: the Anthropic value the old chain used (OpenAI's call set none). */
    private const MAX_TOKENS = 1024;

    /** The old primary (Gemini) call's temperature. */
    private const TEMPERATURE = 0.9;

    public function __construct(private readonly MeteredAiService $metered)
    {
    }

    /**
     * @return array{provider: string, variants: list<array{hook: string, caption: string, cta: string}>}
     *
     * @throws AiException authorization / credit / duplicate refusals
     */
    public function generate(
        AiAuthorization $authorization,
        string $operationKey,
        string $businessName,
        string $targetIndustry,
        string $offerDetails,
        string $targetGoal,
        string $tone
    ): array {
        $tokens = compact('businessName', 'targetIndustry', 'offerDetails', 'targetGoal', 'tone');

        $request = new AiRequest(
            prompt: $this->userPrompt($tokens),
            system: $this->systemPrompt(),
            maxTokens: self::MAX_TOKENS,
            temperature: self::TEMPERATURE,
            operation: self::OPERATION,
            requiredKeys: ['variants'],
        );

        try {
            $result = $this->metered->generateStructured(
                $authorization,
                $request,
                $operationKey,
                accept: fn (AiResponse $response) => $this->usableVariants($response->data) !== [],
            );

            return ['provider' => $result->response->provider, 'variants' => $this->usableVariants($result->response->data)];
        } catch (AiException $e) {
            if (! in_array($e->errorCode, self::TEMPLATE_FALLBACK, true)) {
                throw $e;
            }

            // Metadata only — never the vendor's message or the prompt.
            Log::warning('CopywriterService: AI copy unavailable, serving the template engine.', [
                'account_id' => $authorization->account->id,
                'error_code' => $e->errorCode,
            ]);

            return ['provider' => 'template', 'variants' => $this->generateFromTemplates($tokens)];
        }
    }

    private function systemPrompt(): string
    {
        return 'You are an expert Meta Ads copywriter who works across every industry — real estate, gyms, '.
            'coaching, healthcare, e-commerce, or anything else a small business owner describes. You never '.
            'assume a fixed industry template; you infer tone and framing purely from the business context you '.
            'are given. Always reply with ONLY a JSON object of the exact shape '.
            '{"variants": [{"hook": string, "caption": string, "cta": string}, ...]} containing EXACTLY 5 items. '.
            'No prose, no markdown fences, no explanation — JSON only.';
    }

    /**
     * @param array{businessName: string, targetIndustry: string, offerDetails: string, targetGoal: string, tone: string} $tokens
     */
    private function userPrompt(array $tokens): string
    {
        $goalInstruction = $tokens['targetGoal'] === 'ORGANIC_POST'
            ? 'This copy is for a FREE ORGANIC social post (Facebook/Instagram feed) — write for genuine engagement, shares, and comments, not a hard sales pitch. The CTA should invite interaction (comment, share, DM, save) rather than a paid-ad action.'
            : 'This copy is for a PAID Meta Lead Ad — optimize for conversion: create urgency, speak directly to the offer, and make the CTA a clear next step toward submitting their details (e.g. inquire, book, claim, get a quote).';

        return sprintf(
            "Business/Product: \"%s\". Target industry: \"%s\". Current offer or promotion: \"%s\". Tone: \"%s\".\n".
            "%s\n".
            'Write 5 high-converting Meta Ads variants (hook, caption, call-to-action) for this business, '.
            'in the requested tone, tailored to the target industry and weaving in the specific offer described above.',
            $tokens['businessName'],
            $tokens['targetIndustry'],
            $tokens['offerDetails'] !== '' ? $tokens['offerDetails'] : 'No specific promotion — general awareness/lead capture.',
            $tokens['tone'],
            $goalInstruction,
        );
    }

    /**
     * The same validation the vendor calls used (parseVariants()): up to 5
     * variants, each needing hook + caption + cta; [] when none is usable.
     *
     * @param array<string, mixed>|null $decoded
     * @return list<array{hook: string, caption: string, cta: string}>
     */
    private function usableVariants(?array $decoded): array
    {
        $variants = $decoded['variants'] ?? null;

        if (! is_array($variants) || count($variants) === 0) {
            return [];
        }

        $clean = [];
        foreach (array_slice($variants, 0, 5) as $variant) {
            if (! is_array($variant) || ! isset($variant['hook'], $variant['caption'], $variant['cta'])) {
                continue;
            }
            $clean[] = [
                'hook' => (string) $variant['hook'],
                'caption' => (string) $variant['caption'],
                'cta' => (string) $variant['cta'],
            ];
        }

        return $clean;
    }

    /**
     * Deterministic, dependency-free, zero-external-call fallback. Three
     * hand-tuned template sets for the spec's own named examples
     * ("Urgent", "High-Converting", "Professional" — matched
     * case-insensitively), and a generic template set for any other
     * free-text tone a tenant types. {offer} is only woven into captions
     * when offerDetails is non-empty, so a tenant who leaves it blank
     * never sees a literal "with " trailing off into nothing.
     *
     * @param array{businessName: string, targetIndustry: string, offerDetails: string, targetGoal: string, tone: string} $tokens
     * @return list<array{hook: string, caption: string, cta: string}>
     */
    private function generateFromTemplates(array $tokens): array
    {
        $key = strtolower(trim($tokens['tone']));

        $bank = match (true) {
            str_contains($key, 'urgen') => $this->urgentBank(),
            str_contains($key, 'convert') => $this->highConvertingBank(),
            str_contains($key, 'profession') => $this->professionalBank(),
            default => $this->genericBank(),
        };

        $ctaBank = $tokens['targetGoal'] === 'ORGANIC_POST'
            ? ['Comment Below', 'Share With a Friend', 'Save This Post', 'DM Us to Learn More', 'Tag Someone Who Needs This']
            : $bank['ctas'];

        $offerClause = $tokens['offerDetails'] !== ''
            ? ' — '.rtrim($tokens['offerDetails'], '. ').'.'
            : '';

        $replace = fn (string $template) => str_replace(
            ['{business}', '{industry}', '{tone}', '{offer}'],
            [$tokens['businessName'], $tokens['targetIndustry'], $tokens['tone'], $offerClause],
            $template
        );

        $variants = [];
        for ($i = 0; $i < 5; $i++) {
            $variants[] = [
                'hook' => $replace($bank['hooks'][$i]),
                'caption' => $replace($bank['captions'][$i]).$offerClause,
                'cta' => $ctaBank[$i],
            ];
        }

        return $variants;
    }

    /** @return array{hooks: list<string>, captions: list<string>, ctas: list<string>} */
    private function urgentBank(): array
    {
        return [
            'hooks' => [
                "Only a Few Spots Left at {business} — Don't Miss Out!",
                'Hurry! {industry} Customers Are Already Switching to {business}.',
                "Last Chance: {business}'s Offer Ends Soon.",
                'Time Is Running Out to Get This Deal from {business}.',
                "Don't Wait — {industry} Leaders Are Already Choosing {business}.",
            ],
            'captions' => [
                'Limited slots available this month at {business} for {industry} customers ready to act fast',
                'Every day you wait is a day you miss out. {business} is ready when you are',
                "{business}'s offer for {industry} customers won't last — lock in your spot today",
                'Act now: customers are already seeing results with {business} in days, not months',
                'Availability at {business} is filling up fast among {industry} customers like you',
            ],
            'ctas' => ['Claim Your Spot Now', "Get Started Before It's Gone", 'Act Now — Limited Time', "Don't Wait, Sign Up Today", 'Secure Your Offer Now'],
        ];
    }

    /** @return array{hooks: list<string>, captions: list<string>, ctas: list<string>} */
    private function highConvertingBank(): array
    {
        return [
            'hooks' => [
                'The #1 Choice for {industry} — Meet {business}.',
                'See Why {industry} Customers Are Switching to {business}.',
                '{business}: The Smarter Way to Get What You Need in {industry}.',
                'Join Thousands Who Trust {business} for {industry}.',
                'Discover What Makes {business} a Game-Changer for {industry}.',
            ],
            'captions' => [
                '{business} helps {industry} customers get more, spend less, and move faster',
                'See real results: customers using {business} report faster outcomes and real value',
                'Stop settling. {business} gives {industry} customers the edge they need to win',
                '{business} is trusted by {industry} customers who want measurable, repeatable results',
                'From first contact to happy customer — {business} is built to convert',
            ],
            'ctas' => ['See How It Works', 'Get Your Free Quote', 'Start Today', 'Try It Now', 'Book a Consultation'],
        ];
    }

    /** @return array{hooks: list<string>, captions: list<string>, ctas: list<string>} */
    private function professionalBank(): array
    {
        return [
            'hooks' => [
                'Introducing {business} — Built for {industry} Excellence.',
                '{business}: A Trusted Name in {industry}.',
                'Elevate Your Experience with {business}.',
                '{business} — Engineered for {industry} Reliability.',
                'Partner with {business} for Sustainable {industry} Results.',
            ],
            'captions' => [
                '{business} is designed to meet the exacting standards of {industry} customers',
                'Built on reliability and results, {business} supports {industry} customers at every stage',
                '{business} combines proven expertise with modern service tailored for {industry}',
                'Trusted by {industry} customers who expect consistency, quality, and support',
                '{business} — a dependable partner for {industry} customers focused on long-term success',
            ],
            'ctas' => ['Learn More', 'Request a Consultation', 'Explore {business}', 'Get in Touch', 'Schedule a Call'],
        ];
    }

    /** @return array{hooks: list<string>, captions: list<string>, ctas: list<string>} */
    private function genericBank(): array
    {
        return [
            'hooks' => [
                'Meet {business}: {tone} Results for {industry}.',
                '{business} Delivers {tone} Value for {industry} Customers.',
                'Why {industry} Customers Choose {business} for {tone} Outcomes.',
                '{business} — {tone} Solutions Built for {industry}.',
                'Experience {tone} Results in {industry} with {business}.',
            ],
            'captions' => [
                '{business} is built to deliver {tone} results for {industry} customers',
                'See how {business} supports {industry} customers looking for {tone} outcomes',
                '{business}: made for {industry} customers who want {tone} performance',
                'Discover how {industry} customers are using {business} for {tone} results',
                '{business} brings {tone} value to {industry} customers of every size',
            ],
            'ctas' => ['Learn More About {business}', 'Get Started Today', 'Discover {business}', 'Contact Us', 'Request Info'],
        ];
    }
}
