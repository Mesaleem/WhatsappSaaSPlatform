<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiException;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;

/**
 * Phase 8 AI foundation — Anthropic Messages API (POST /messages).
 * [Hypothesis] request/response shapes follow Anthropic's published API; no
 * live key exists in this environment, so this is verified against faked
 * HTTP responses only. No native JSON mode: structured generation adds an
 * explicit instruction and the answer is validated by decodeObject().
 */
class AnthropicProvider extends HttpAiProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    protected function complete(AiRequest $request, bool $json): AiResponse
    {
        $model = $this->model($request);

        $system = trim(implode("\n\n", array_filter([
            $request->system,
            $json ? $this->jsonInstruction() : null,
        ])));

        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }

        $response = $this->call(
            $this->http()->withHeaders([
                'x-api-key' => (string) $this->config['api_key'],
                'anthropic-version' => (string) ($this->config['version'] ?? '2023-06-01'),
            ]),
            fn ($http) => $http->post('/v1/messages', $body),
        );

        $blocks = $response->json('content');

        if (! is_array($blocks)) {
            throw AiException::malformedResponse($this->name());
        }

        $text = collect($blocks)
            ->filter(fn ($b) => is_array($b) && ($b['type'] ?? null) === 'text' && is_string($b['text'] ?? null))
            ->pluck('text')
            ->implode('');

        if ($text === '') {
            throw AiException::malformedResponse($this->name());
        }

        return new AiResponse(
            provider: $this->name(),
            model: self::stringOrNull($response->json('model')) ?? $model,
            text: $text,
            inputTokens: self::intOrNull($response->json('usage.input_tokens')),
            outputTokens: self::intOrNull($response->json('usage.output_tokens')),
            finishReason: self::stringOrNull($response->json('stop_reason')),
        );
    }
}
