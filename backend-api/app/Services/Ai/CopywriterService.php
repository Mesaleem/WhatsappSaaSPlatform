<?php

namespace App\Services\Ai;

use App\Models\Account;
use App\Models\SocialProviderConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Social/Ads Launcher Overhaul — Step 1 (Gemini Pro Engine & Multi-Token
 * Prompt Refactor). AI Ad Copywriter (AICopywriterController). Produces
 * 5 ad-copy variants, each a {hook, caption, cta} triple — a tenant
 * picking "variant 3" needs its hook/caption/cta to belong together,
 * which is what the wizard needs to fill headline/primary_text with a
 * coherent option.
 *
 * PROVIDER SELECTION (Gemini Pro is now primary): Gemini is tried first
 * whenever a key resolves (see resolveGeminiApiKey() below); OpenAI and
 * Anthropic are KEPT as documented secondary fallbacks — no existing,
 * working behavior is removed — and the deterministic template engine
 * remains the final, zero-external-call fallback for a fresh install
 * with no key configured anywhere. A real provider call THROWING
 * (timeout, invalid key, malformed response) degrades to the next
 * provider in the chain rather than ever turning into a 500 for the
 * tenant.
 *
 * PER-TENANT KEY RESOLUTION (database-backed, not only .env): see
 * resolveGeminiApiKey()'s docblock and the accounts.gemini_api_key
 * migration for the full 3-tier order (tenant override -> platform
 * social_provider_configs row -> env). OpenAI/Anthropic keys remain
 * env-only/platform-wide, unchanged from before this refactor — only
 * Gemini was asked to become per-tenant-resolvable.
 *
 * MULTI-TOKEN PROMPT: generate() now takes 4 dynamic business-context
 * tokens (businessName, targetIndustry, offerDetails, targetGoal) plus
 * tone, up from the prior 2 (product_name misused as a campaign name,
 * target_industry). None of these are matched against a hardcoded
 * per-industry branch anywhere in this class — target_industry and
 * offer_details are free text interpolated directly into the prompt/
 * templates, so any industry (Real Estate, Gym, Coaching, Healthcare,
 * E-Commerce, ...) takes the exact same code path.
 *
 * DISCLOSED — NOT INTEGRATION-TESTED: no Gemini/OpenAI/Anthropic API key
 * is configured anywhere in this dev environment, and this environment
 * has no network path to test one even if it did. The request/response
 * shapes below follow each provider's own published API docs as closely
 * as possible from training knowledge but are [Hypothesis] until
 * exercised against a real key — same disclosed status this class
 * already carried for OpenAI/Anthropic before this refactor.
 */
class CopywriterService
{
    /** Meta Ads objectives this app supports also gate campaign creation (see AdCampaign::OBJECTIVES); this is a SEPARATE, coarser concept the AI prompt uses to shape tone/CTA. */
    public const TARGET_GOALS = ['ORGANIC_POST', 'PAID_LEAD_AD'];

    /**
     * @return array{provider: string, variants: list<array{hook: string, caption: string, cta: string}>}
     */
    public function generate(
        Account $account,
        string $businessName,
        string $targetIndustry,
        string $offerDetails,
        string $targetGoal,
        string $tone
    ): array {
        $tokens = compact('businessName', 'targetIndustry', 'offerDetails', 'targetGoal', 'tone');

        if ($geminiKey = $this->resolveGeminiApiKey($account)) {
            try {
                return ['provider' => 'gemini', 'variants' => $this->generateWithGemini($geminiKey, $tokens)];
            } catch (Throwable $e) {
                Log::warning('CopywriterService: Gemini call failed, falling back to the next provider.', ['exception' => $e->getMessage()]);
            }
        }

        if (config('services.openai.key')) {
            try {
                return ['provider' => 'openai', 'variants' => $this->generateWithOpenAi($tokens)];
            } catch (Throwable $e) {
                Log::warning('CopywriterService: OpenAI call failed, falling back to the next provider.', ['exception' => $e->getMessage()]);
            }
        } elseif (config('services.anthropic.key')) {
            try {
                return ['provider' => 'anthropic', 'variants' => $this->generateWithAnthropic($tokens)];
            } catch (Throwable $e) {
                Log::warning('CopywriterService: Anthropic call failed, falling back to templates.', ['exception' => $e->getMessage()]);
            }
        }

        return ['provider' => 'template', 'variants' => $this->generateFromTemplates($tokens)];
    }

    /**
     * Resolves the Gemini API key to use for THIS tenant's request, in
     * priority order:
     *
     *  1. $account->gemini_api_key — a tenant's own key, if they've set
     *     one (encrypted at rest, see Account::casts()).
     *  2. SocialProviderConfig::findByProviderCached('gemini') — the
     *     platform default a Super Admin configures once via the
     *     existing Admin Social Gateway Settings endpoint/vault, reusing
     *     its `client_secret` column as "the API key" for this
     *     non-OAuth provider (see that model's PROVIDERS docblock).
     *  3. null — generate() falls through to OpenAI/Anthropic/templates.
     *
     * Never throws; a decryption failure or missing row is treated as
     * "no key configured", the same degrade-gracefully posture the rest
     * of this class already takes toward a flaky/misconfigured provider.
     */
    private function resolveGeminiApiKey(Account $account): ?string
    {
        try {
            if (! empty($account->gemini_api_key)) {
                return $account->gemini_api_key;
            }
        } catch (Throwable $e) {
            Log::warning('CopywriterService: failed to read accounts.gemini_api_key, falling back to the platform key.', [
                'account_id' => $account->id,
                'exception' => $e->getMessage(),
            ]);
        }

        try {
            $platformKey = SocialProviderConfig::findByProviderCached('gemini')?->client_secret;

            if (! empty($platformKey)) {
                return $platformKey;
            }
        } catch (Throwable $e) {
            Log::warning('CopywriterService: failed to read the platform Gemini key.', ['exception' => $e->getMessage()]);
        }

        $envKey = config('services.gemini.key');

        return $envKey !== null && $envKey !== '' ? (string) $envKey : null;
    }

    /**
     * @param array{businessName: string, targetIndustry: string, offerDetails: string, targetGoal: string, tone: string} $tokens
     * @return list<array{hook: string, caption: string, cta: string}>
     */
    private function generateWithGemini(string $apiKey, array $tokens): array
    {
        $model = config('services.gemini.model', 'gemini-1.5-pro');

        // [Hypothesis]: Gemini's generateContent endpoint takes the API
        // key as a `key` query parameter (not a bearer/header credential
        // like OpenAI/Anthropic) and a `generationConfig.responseMimeType`
        // of 'application/json' to request structured JSON output,
        // per Google's published Generative Language API v1beta docs.
        // Http::post() only accepts (url, body) — a 3rd array argument for
        // query params is silently dropped, not merged — so the key is
        // appended to the URL directly rather than passed as a 3rd arg.
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.
            $model.':generateContent?key='.urlencode($apiKey);

        $response = Http::timeout(20)->post($url, [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $this->userPrompt($tokens)]]],
            ],
            'systemInstruction' => [
                'parts' => [['text' => $this->systemPrompt()]],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.9,
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException($response->json('error.message') ?? 'Gemini rejected the request.');
        }

        $raw = $response->json('candidates.0.content.parts.0.text');

        return $this->parseVariants($raw);
    }

    /**
     * @param array{businessName: string, targetIndustry: string, offerDetails: string, targetGoal: string, tone: string} $tokens
     * @return list<array{hook: string, caption: string, cta: string}>
     */
    private function generateWithOpenAi(array $tokens): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(20)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($tokens)],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException($response->json('error.message') ?? 'OpenAI rejected the request.');
        }

        $raw = $response->json('choices.0.message.content');

        return $this->parseVariants($raw);
    }

    /**
     * @param array{businessName: string, targetIndustry: string, offerDetails: string, targetGoal: string, tone: string} $tokens
     * @return list<array{hook: string, caption: string, cta: string}>
     */
    private function generateWithAnthropic(array $tokens): array
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
                    ['role' => 'user', 'content' => $this->userPrompt($tokens)],
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
