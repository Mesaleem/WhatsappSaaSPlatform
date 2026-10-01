<?php

namespace App\Services\Ai\Billing;

use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\EmbeddingRequest;

/**
 * Phase 8 Task 5 — THE conversion of an AI operation into credits. Nothing
 * else in the application computes an AI credit amount; every value comes
 * from config('ai.credits').
 *
 *   charge   = max(minimum, ceil((input + output tokens) / tokens_per_credit))
 *   estimate = the same formula over an UPPER BOUND of the request's tokens
 *              (UTF-8 bytes of prompt + system + a fixed instruction
 *              allowance — a BPE token is at least one byte — plus
 *              max_tokens for the answer). It is the credit hold taken before
 *              the provider is called.
 *
 * tokens_per_credit may be overridden per "provider:model" or per provider
 * (config('ai.credits.tokens_per_credit_overrides')) — the dimension a
 * model-specific price needs; operation/input-vs-output weighting are not
 * implemented because no requirement defines them yet.
 */
final class AiCreditPricing
{
    /** Allowance for provider-side instructions the service adds (JSON mode). */
    private const INSTRUCTION_BYTES = 64;

    public function estimate(AiRequest $request, string $provider, ?string $model): int
    {
        $inputUpperBound = strlen($request->prompt) + strlen((string) $request->system) + self::INSTRUCTION_BYTES;

        return $this->credits($inputUpperBound + $request->maxTokens(), $provider, $model);
    }

    /**
     * What the finished operation costs, capped at the hold.
     *
     * @return array{charged: int, uncharged: int, usage_reported: bool, computed: int}
     */
    public function charge(AiResponse $response, int $reserved): array
    {
        return $this->chargeTokens($response->inputTokens, $response->outputTokens, $response->provider, $response->model, $reserved);
    }

    /**
     * Phase 8 Task 9 — an embedding request's hold: its input bytes (an upper
     * bound of its tokens) — an embedding generates no output tokens.
     */
    public function estimateEmbedding(EmbeddingRequest $request, string $provider, ?string $model): int
    {
        return $this->credits(array_sum(array_map('strlen', $request->inputs)), $provider, $model);
    }

    /**
     * The charge for reported token counts (both null-checked; an embedding
     * reports output 0), capped at the hold — the one formula for every
     * operation type.
     *
     * @return array{charged: int, uncharged: int, usage_reported: bool, computed: int}
     */
    public function chargeTokens(?int $inputTokens, ?int $outputTokens, string $provider, ?string $model, int $reserved): array
    {
        $reported = $inputTokens !== null && $outputTokens !== null;

        if ($reported) {
            $computed = $this->credits($inputTokens + $outputTokens, $provider, $model);
        } else {
            $computed = config('ai.credits.missing_usage') === 'reservation' ? $reserved : $this->minimum();
        }

        $charged = min($computed, $reserved);

        return ['charged' => $charged, 'uncharged' => $computed - $charged, 'usage_reported' => $reported, 'computed' => $computed];
    }

    public function credits(int $tokens, string $provider, ?string $model): int
    {
        return max($this->minimum(), (int) ceil(max(0, $tokens) / $this->tokensPerCredit($provider, $model)));
    }

    public function tokensPerCredit(string $provider, ?string $model): int
    {
        $overrides = (array) config('ai.credits.tokens_per_credit_overrides', []);
        $value = ($model !== null ? ($overrides["{$provider}:{$model}"] ?? null) : null)
            ?? $overrides[$provider]
            ?? config('ai.credits.tokens_per_credit', 1000);

        return max(1, (int) $value);
    }

    public function minimum(): int
    {
        return max(0, (int) config('ai.credits.minimum_per_operation', 1));
    }
}
