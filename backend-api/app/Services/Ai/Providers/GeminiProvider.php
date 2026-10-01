<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiException;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Contracts\EmbeddingProvider;
use App\Services\Ai\Data\EmbeddingRequest;
use App\Services\Ai\Data\EmbeddingResponse;

/**
 * Phase 8 Task 8 — Google Gemini API, generateContent
 * (POST {base}/{version}/models/{model}:generateContent).
 *
 * [Hypothesis] request/response shapes follow Google's published
 * Generative Language API reference (v1beta generateContent); no live key
 * exists in this environment, so — like OpenAiProvider/AnthropicProvider —
 * this is verified against faked HTTP responses only.
 *
 * Same plumbing as every other vendor (HttpAiProvider): timeout, the
 * connection-only retry, the failure → AiException mapping (a vendor body
 * is never copied into a message) and the shared JSON-object validation
 * for structured answers. Everything Gemini-specific stays in this class:
 *
 *   auth       the API key goes in the `x-goog-api-key` header, never in
 *              the URL query (so it cannot land in a URL log)
 *   request    contents[user] + systemInstruction + generationConfig
 *              {maxOutputTokens, temperature, responseMimeType}
 *   structured responseMimeType = application/json (native JSON mode) plus
 *              the shared JSON instruction; decoded by decodeObject()
 *   answer     the text parts of the first candidate, thought parts
 *              excluded; no text (safety/recitation block, prompt blocked,
 *              no candidate) → AI_MALFORMED_RESPONSE — never charged
 *   usage      normalized here, never in MeteredAiService:
 *                input  = usageMetadata.promptTokenCount
 *                output = candidatesTokenCount + thoughtsTokenCount
 *                         (thinking tokens are output the vendor bills),
 *                         or totalTokenCount − promptTokenCount when
 *                         candidatesTokenCount is absent;
 *              a count the vendor did not report stays null, so the
 *              existing ai.credits.missing_usage rule applies — nothing is
 *              invented.
 */
class GeminiProvider extends HttpAiProvider implements EmbeddingProvider
{
    /** A model id (optionally "models/…") that is safe to put in the URL path. */
    private const MODEL_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._\-]{0,127}\z/';

    public function name(): string
    {
        return 'gemini';
    }

    protected function complete(AiRequest $request, bool $json): AiResponse
    {
        $model = $this->model($request);
        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;

        if (preg_match(self::MODEL_PATTERN, $model) !== 1) {
            throw AiException::invalidRequest('The AI model name is not valid.');
        }

        $system = trim(implode("\n\n", array_filter([
            $request->system,
            $json ? $this->jsonInstruction() : null,
        ])));

        $generation = [
            'maxOutputTokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
        ];
        if ($json) {
            $generation['responseMimeType'] = 'application/json';
        }

        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->prompt]]]],
            'generationConfig' => $generation,
        ];
        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $version = trim((string) ($this->config['api_version'] ?? 'v1beta'), '/') ?: 'v1beta';

        $response = $this->call(
            $this->http()->withHeaders(['x-goog-api-key' => (string) $this->config['api_key']]),
            fn ($http) => $http->post("/{$version}/models/{$model}:generateContent", $body),
        );

        $parts = $response->json('candidates.0.content.parts');

        if (! is_array($parts)) {
            throw AiException::malformedResponse($this->name());
        }

        $text = collect($parts)
            ->filter(fn ($p) => is_array($p) && is_string($p['text'] ?? null) && ($p['thought'] ?? false) !== true)
            ->pluck('text')
            ->implode('');

        if (trim($text) === '') {
            throw AiException::malformedResponse($this->name());
        }

        [$input, $output] = self::usage($response->json('usageMetadata'));

        return new AiResponse(
            provider: $this->name(),
            model: self::stringOrNull($response->json('modelVersion')) ?? $model,
            text: $text,
            inputTokens: $input,
            outputTokens: $output,
            finishReason: self::stringOrNull($response->json('candidates.0.finishReason')),
        );
    }

    /**
     * Phase 8 Task 9 — POST {base}/{version}/models/{model}:batchEmbedContents
     * (one Content per input, so one vector per input). [Hypothesis] shape
     * per Google's embeddings guide (requests[].content.parts[].text →
     * embeddings[].values); verified against faked HTTP only. The legacy
     * text model gemini-embedding-001 takes a taskType (RETRIEVAL_DOCUMENT /
     * RETRIEVAL_QUERY); newer models take none, so it is sent only there.
     * Token usage is taken from usageMetadata.promptTokenCount when the
     * vendor reports it, otherwise null (→ the missing-usage rule).
     */
    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $model = $this->embeddingModel($request);
        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;

        if (preg_match(self::MODEL_PATTERN, $model) !== 1) {
            throw AiException::invalidRequest('The AI model name is not valid.');
        }

        $dimensions = $this->embeddingDimensions();
        $taskType = $model === 'gemini-embedding-001'
            ? ($request->purpose === EmbeddingRequest::PURPOSE_QUERY ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT')
            : null;

        $requests = array_map(fn (string $text) => array_filter([
            'model' => "models/{$model}",
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => $taskType,
            'outputDimensionality' => $dimensions,
        ], fn ($v) => $v !== null), $request->inputs);

        $version = trim((string) ($this->config['api_version'] ?? 'v1beta'), '/') ?: 'v1beta';

        $response = $this->call(
            $this->http()->withHeaders(['x-goog-api-key' => (string) $this->config['api_key']]),
            fn ($http) => $http->post("/{$version}/models/{$model}:batchEmbedContents", ['requests' => $requests]),
        );

        $embeddings = $response->json('embeddings');

        if (! is_array($embeddings) || count($embeddings) !== count($request->inputs)) {
            throw AiException::malformedResponse($this->name());
        }

        $vectors = [];
        foreach ($embeddings as $embedding) {
            if (! is_array($embedding) || ! is_array($embedding['values'] ?? null)) {
                throw AiException::malformedResponse($this->name());
            }
            $vectors[] = $embedding['values'];
        }

        return new EmbeddingResponse(
            provider: $this->name(),
            model: $model,
            vectors: $vectors,
            inputTokens: self::intOrNull($response->json('usageMetadata.promptTokenCount')),
        );
    }

    /**
     * @return array{0: int|null, 1: int|null} [input tokens, output tokens]
     */
    private static function usage(mixed $usage): array
    {
        if (! is_array($usage)) {
            return [null, null];
        }

        $prompt = self::intOrNull($usage['promptTokenCount'] ?? null);
        $candidates = self::intOrNull($usage['candidatesTokenCount'] ?? null);
        $thoughts = self::intOrNull($usage['thoughtsTokenCount'] ?? null) ?? 0;
        $total = self::intOrNull($usage['totalTokenCount'] ?? null);

        $output = match (true) {
            $candidates !== null => $candidates + $thoughts,
            $total !== null && $prompt !== null && $total >= $prompt => $total - $prompt,
            default => null,
        };

        return [$prompt, $output];
    }
}
