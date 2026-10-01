<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiException;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Contracts\EmbeddingProvider;
use App\Services\Ai\Data\EmbeddingRequest;
use App\Services\Ai\Data\EmbeddingResponse;

/**
 * Phase 8 AI foundation — OpenAI Chat Completions (POST /chat/completions).
 * [Hypothesis] request/response shapes follow OpenAI's published API; no
 * live key exists in this environment, so this is verified against faked
 * HTTP responses only.
 */
class OpenAiProvider extends HttpAiProvider implements EmbeddingProvider
{
    public function name(): string
    {
        return 'openai';
    }

    protected function complete(AiRequest $request, bool $json): AiResponse
    {
        $model = $this->model($request);

        $messages = [];
        if ($request->system !== null && $request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }
        if ($json) {
            // OpenAI's JSON mode requires the word "JSON" in the conversation.
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

    /**
     * Phase 8 Task 9 — POST /embeddings. [Hypothesis] shape per OpenAI's
     * published API (data[].index/embedding, usage.prompt_tokens); verified
     * against faked HTTP only.
     */
    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $model = $this->embeddingModel($request);
        $body = ['model' => $model, 'input' => $request->inputs, 'encoding_format' => 'float'];

        if (($dimensions = $this->embeddingDimensions()) !== null) {
            $body['dimensions'] = $dimensions;
        }

        $response = $this->call(
            $this->http()->withToken((string) $this->config['api_key']),
            fn ($http) => $http->post('/embeddings', $body),
        );

        $data = $response->json('data');

        if (! is_array($data) || count($data) !== count($request->inputs)) {
            throw AiException::malformedResponse($this->name());
        }

        $vectors = [];
        foreach ($data as $position => $item) {
            $index = is_array($item) && is_int($item['index'] ?? null) ? $item['index'] : $position;

            if (! is_array($item) || ! is_array($item['embedding'] ?? null) || isset($vectors[$index])) {
                throw AiException::malformedResponse($this->name());
            }

            $vectors[$index] = $item['embedding'];
        }
        ksort($vectors);

        if (array_keys($vectors) !== range(0, count($request->inputs) - 1)) {
            throw AiException::malformedResponse($this->name());
        }

        return new EmbeddingResponse(
            provider: $this->name(),
            model: self::stringOrNull($response->json('model')) ?? $model,
            vectors: array_values($vectors),
            inputTokens: self::intOrNull($response->json('usage.prompt_tokens')),
        );
    }
}
