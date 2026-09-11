<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * AI Ad Copywriter (AICopywriterController). Produces 5 ad-copy variants,
 * each a {hook, caption, cta} triple — [Assumption]: the spec's "Returns
 * 5 High-Converting Ad Hooks, Captions, and Call-to-Actions" is read as 5
 * COMPLETE variants (one hook+caption+cta each), not 15 independent
 * strings — a tenant picking "variant 3" needs its hook/caption/cta to
 * belong together, and this is what the wizard actually needs to fill
 * headline/primary_text with a coherent option.
 *
 * PROVIDER SELECTION: OpenAI is tried first if OPENAI_API_KEY is set
 * (config('services.openai.key')), else Anthropic if ANTHROPIC_API_KEY is
 * set, else the deterministic template engine below runs with NO
 * external call at all — this is the literal "structured fallback
 * template engine if API keys are unset" the spec asks for, and it is
 * also what handles a real provider call THROWING (timeout, invalid key,
 * malformed response) — this method never lets a flaky AI provider turn
 * into a 500 for the tenant; it degrades to the deterministic templates
 * instead and logs the failure.
 *
 * DISCLOSED — NOT INTEGRATION-TESTED: no OpenAI/Anthropic API key is
 * configured anywhere in this environment (.env.example has neither),
 * and this dev environment has no network path to test one even if it
 * did (unrelated to whether the PRODUCTION server can reach these APIs —
 * it uses the exact same Illuminate\Support\Facades\Http client already
 * used, and already disclosed as untested-here, for every Meta Graph API
 * call in MetaAdsService/CommentAutomationService/SocialInboxController).
 * The request/response shapes below follow each provider's own published
 * API docs as closely as possible from training knowledge but are
 * [Hypothesis] until exercised against a real key.
 */
class CopywriterService
{
    /**
     * @return array{provider: string, variants: list<array{hook: string, caption: string, cta: string}>}
     */
    public function generate(string $productName, string $targetIndustry, string $tone): array
    {
        if (config('services.openai.key')) {
            try {
                return ['provider' => 'openai', 'variants' => $this->generateWithOpenAi($productName, $targetIndustry, $tone)];
            } catch (Throwable $e) {
                Log::warning('CopywriterService: OpenAI call failed, falling back to templates.', ['exception' => $e->getMessage()]);
            }
        } elseif (config('services.anthropic.key')) {
            try {
                return ['provider' => 'anthropic', 'variants' => $this->generateWithAnthropic($productName, $targetIndustry, $tone)];
            } catch (Throwable $e) {
                Log::warning('CopywriterService: Anthropic call failed, falling back to templates.', ['exception' => $e->getMessage()]);
            }
        }

        return ['provider' => 'template', 'variants' => $this->generateFromTemplates($productName, $targetIndustry, $tone)];
    }

    /**
     * @return list<array{hook: string, caption: string, cta: string}>
     */
    private function generateWithOpenAi(string $productName, string $targetIndustry, string $tone): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(20)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($productName, $targetIndustry, $tone)],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException($response->json('error.message') ?? 'OpenAI rejected the request.');
        }

        $raw = $response->json('choices.0.message.content');

        return $this->parseVariants($raw);
    }

    /**
     * @return list<array{hook: string, caption: string, cta: string}>
     */
    private function generateWithAnthropic(string $productName, string $targetIndustry, string $tone): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(20)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-3-5-haiku-latest'),
                'max_tokens' => 1024,
                'system' => $this->systemPrompt(),
                'messages' => [
                    ['role' => 'user', 'content' => $this->userPrompt($productName, $targetIndustry, $tone)],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException($response->json('error.message') ?? 'Anthropic rejected the request.');
        }

        $raw = $response->json('content.0.text');

        return $this->parseVariants($raw);
    }

    private function systemPrompt(): string
    {
        return 'You are an expert Meta Ads copywriter. Always reply with ONLY a JSON object of the exact shape '.
            '{"variants": [{"hook": string, "caption": string, "cta": string}, ...]} containing EXACTLY 5 items. '.
            'No prose, no markdown fences, no explanation — JSON only.';
    }

    private function userPrompt(string $productName, string $targetIndustry, string $tone): string
    {
        return sprintf(
            'Product/service: "%s". Target industry: "%s". Tone: "%s". '.
            'Write 5 high-converting Meta Ads variants (hook, caption, call-to-action) for this product, '.
            'in the requested tone, tailored to the target industry.',
            $productName,
            $targetIndustry,
            $tone
        );
    }

    /**
     * @return list<array{hook: string, caption: string, cta: string}>
     */
    private function parseVariants(?string $raw): array
    {
        if (! $raw) {
            throw new \RuntimeException('Empty AI response.');
        }

        $decoded = json_decode($raw, true);
        $variants = $decoded['variants'] ?? null;

        if (! is_array($variants) || count($variants) === 0) {
            throw new \RuntimeException('AI response was not in the expected shape.');
        }

        $clean = [];
        foreach (array_slice($variants, 0, 5) as $variant) {
            if (! isset($variant['hook'], $variant['caption'], $variant['cta'])) {
                continue;
            }
            $clean[] = [
                'hook' => (string) $variant['hook'],
                'caption' => (string) $variant['caption'],
                'cta' => (string) $variant['cta'],
            ];
        }

        if (count($clean) === 0) {
            throw new \RuntimeException('AI response contained no usable variants.');
        }

        return $clean;
    }

    /**
     * Deterministic, dependency-free, zero-external-call fallback. Three
     * hand-tuned template sets for the spec's own named examples
     * ("Urgent", "High-Converting", "Professional" — matched
     * case-insensitively), and a generic template set for any other
     * free-text tone a tenant types (the spec's "(e.g. ...)" phrasing
     * reads as illustrative, not an exhaustive enum, so this never
     * hard-rejects an unrecognized tone).
     *
     * @return list<array{hook: string, caption: string, cta: string}>
     */
    private function generateFromTemplates(string $productName, string $targetIndustry, string $tone): array
    {
        $key = strtolower(trim($tone));

        $bank = match (true) {
            str_contains($key, 'urgen') => $this->urgentBank(),
            str_contains($key, 'convert') => $this->highConvertingBank(),
            str_contains($key, 'profession') => $this->professionalBank(),
            default => $this->genericBank(),
        };

        $replace = fn (string $template) => str_replace(
            ['{product}', '{industry}', '{tone}'],
            [$productName, $targetIndustry, $tone],
            $template
        );

        $variants = [];
        for ($i = 0; $i < 5; $i++) {
            $variants[] = [
                'hook' => $replace($bank['hooks'][$i]),
                'caption' => $replace($bank['captions'][$i]),
                'cta' => $replace($bank['ctas'][$i]),
            ];
        }

        return $variants;
    }

    /** @return array{hooks: list<string>, captions: list<string>, ctas: list<string>} */
    private function urgentBank(): array
    {
        return [
            'hooks' => [
                "Only a Few Spots Left for {product} — Don't Miss Out!",
                'Hurry! {industry} Businesses Are Already Switching to {product}.',
                "Last Chance: {product} Offer Ends Soon.",
                'Time Is Running Out to Get {product} at This Price.',
                "Don't Wait — {industry} Leaders Are Already Using {product}.",
            ],
            'captions' => [
                'Limited slots available this month for {industry} businesses ready to grow fast with {product}.',
                'Every day you wait is a day your competitors get ahead. {product} is ready when you are.',
                "Our {product} offer for {industry} businesses won't last — lock in your spot today.",
                'Act now: {industry} teams are already seeing results with {product} in days, not months.',
                'Availability for {product} is filling up fast among {industry} businesses like yours.',
            ],
            'ctas' => ['Claim Your Spot Now', "Get Started Before It's Gone", 'Act Now — Limited Time', "Don't Wait, Sign Up Today", 'Secure Your Offer Now'],
        ];
    }

    /** @return array{hooks: list<string>, captions: list<string>, ctas: list<string>} */
    private function highConvertingBank(): array
    {
        return [
            'hooks' => [
                'The #1 {product} {industry} Businesses Trust.',
                'See Why {industry} Teams Are Switching to {product}.',
                '{product}: The Smarter Way to Grow Your {industry} Business.',
                'Join Thousands of {industry} Businesses Using {product}.',
                'Discover What Makes {product} a Game-Changer for {industry}.',
            ],
            'captions' => [
                '{product} helps {industry} businesses convert more leads, close more deals, and grow faster.',
                'See real results: {industry} businesses using {product} report faster growth and happier customers.',
                'Stop losing leads. {product} gives {industry} teams the edge they need to win more business.',
                '{product} is trusted by {industry} businesses that want measurable, repeatable growth.',
                'From more leads to more sales — {product} is built to convert for {industry} businesses.',
            ],
            'ctas' => ['See How It Works', 'Get Your Free Demo', 'Start Growing Today', 'Try It Free', 'Book a Demo Now'],
        ];
    }

    /** @return array{hooks: list<string>, captions: list<string>, ctas: list<string>} */
    private function professionalBank(): array
    {
        return [
            'hooks' => [
                'Introducing {product} — Built for {industry} Excellence.',
                '{product}: A Trusted Solution for {industry} Professionals.',
                'Elevate Your {industry} Operations with {product}.',
                '{product} — Engineered for {industry} Reliability.',
                'Partner with {product} for Sustainable {industry} Growth.',
            ],
            'captions' => [
                '{product} is designed to meet the exacting standards of {industry} professionals.',
                'Built on reliability and results, {product} supports {industry} businesses at every stage of growth.',
                '{product} combines proven expertise with modern tools tailored for {industry}.',
                'Trusted by {industry} professionals who expect consistency, quality, and support.',
                '{product} — a dependable partner for {industry} businesses focused on long-term success.',
            ],
            'ctas' => ['Learn More', 'Request a Consultation', 'Explore {product}', 'Get in Touch', 'Schedule a Call'],
        ];
    }

    /** @return array{hooks: list<string>, captions: list<string>, ctas: list<string>} */
    private function genericBank(): array
    {
        return [
            'hooks' => [
                'Meet {product}: {tone} Results for {industry}.',
                '{product} Delivers {tone} Value for {industry} Teams.',
                'Why {industry} Businesses Choose {product} for {tone} Outcomes.',
                '{product} — {tone} Solutions Built for {industry}.',
                'Experience {tone} Growth in {industry} with {product}.',
            ],
            'captions' => [
                '{product} is built to deliver {tone} results for {industry} businesses.',
                'See how {product} supports {industry} teams looking for {tone} outcomes.',
                '{product}: made for {industry} businesses that want {tone} performance.',
                'Discover how {industry} businesses are using {product} for {tone} growth.',
                '{product} brings {tone} value to {industry} businesses of every size.',
            ],
            'ctas' => ['Learn More About {product}', 'Get Started Today', 'Discover {product}', 'Contact Us', 'Request Info'],
        ];
    }
}
