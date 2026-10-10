<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiException;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;

/**
 * Phase 8 Task 12 — Groq (OpenAI-compatible Chat Completions,
 * POST {base_url}/chat/completions, base_url defaults to Groq's
 * "/openai/v1" compatibility path). Request/response shapes mirror
 * OpenAiProvider exactly — Groq documents itself as a drop-in replacement
 * for the OpenAI chat-completions contract — so this class intentionally
 * duplicates OpenAiProvider::complete() rather than inheriting it, to keep
 * each vendor independently editable (OpenAiProvider's shape changing
 * must never silently change Groq's, and vice versa — same reasoning
 * AnthropicProvider/GeminiProvider are siblings, not subclasses, of
 * OpenAiProvider).
 *
 * [Hypothesis] verified against Groq's published API docs and faked HTTP
 * only — no live key in this environment. Flag before first production use
 * exactly like GeminiProvider's docblock does for its own model default.
 *
 * NO embeddings: Groq is an inference-only API (fast hosted inference for
 * open models), it does not publish an embeddings endpoint — this class
 * deliberately does NOT implement EmbeddingProvider, same as
 * AnthropicProvider. AiManager::embeddingProvider('groq') therefore throws
 * AI_EMBEDDINGS_UNSUPPORTED, which is correct, not a gap.
 *
 * Model deprecation risk (the reason this task exists): Groq rotates its
 * hosted model catalog faster than OpenAI/Anthropic/Gemini do — a model id
 * that works today can 404 as model_not_found within weeks. Phase 8 Task 12
 * adds the DB-backed override (ai_provider_settings) so the Super Admin
 * can repoint `model` without an env change/redeploy; config('ai.providers.groq.model')
 * below remains the fallback when no DB override is set, same 3-tier
 * shape already used elsewhere in this codebase (tenant -> platform ->
 * env/default).
 */
class GroqProvider extends HttpAiProvider
{
    public function name(): string
    {
        return 'groq';
    }

    protected function complete(AiRequest $request, bool $json): AiResponse
    {
        $model = $this->model($request);

        $messages = [];
        if ($request->system !== null && $request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }
        if ($json) {
            // Groq's JSON mode follows the same OpenAI convention: the word
            // "JSON" must appear somewhere in the conversation.
            $messages[] = ['role' => 'system', 'content' => $this->jsonInstruction()];
        }
        $messages[] = ['role' => 'user', 'content' => $request->prompt];

        $body = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
        ];
        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->call(
            $this->http()->withToken((string) $this->config['api_key']),
            fn ($http) => $http->post('/chat/completions', $body),
        );

        $text = $response->json('choices.0.message.content');

        if (! is_string($text)) {
            throw AiException::malformedResponse($this->name());
        }

        return new AiResponse(
            provider: $this->name(),
            model: self::stringOrNull($response->json('model')) ?? $model,
            text: $text,
            inputTokens: self::intOrNull($response->json('usage.prompt_tokens')),
            outputTokens: self::intOrNull($response->json('usage.completion_tokens')),
            finishReason: self::stringOrNull($response->json('choices.0.finish_reason')),
        );
    }
}
