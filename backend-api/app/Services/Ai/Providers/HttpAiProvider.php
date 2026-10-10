<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiException;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Phase 8 AI foundation — shared HTTP plumbing for vendor providers:
 * timeout, connection-only retry (no sleep — P5-9), and the mapping of
 * every transport/vendor failure onto AiException. A vendor response body
 * is never copied into an exception message.
 */
abstract class HttpAiProvider implements AiProvider
{
    /**
     * @param array{api_key?: string|null, base_url?: string|null, model?: string|null} $config
     */
    public function __construct(protected readonly array $config)
    {
        if (empty($config['api_key'])) {
            throw AiException::providerNotConfigured($this->name());
        }
    }

    protected function model(AiRequest $request): string
    {
        $model = $request->model ?? ($this->config['model'] ?? null);

        if (! is_string($model) || $model === '') {
            throw AiException::providerNotConfigured($this->name());
        }

        return $model;
    }

    /** Phase 8 Task 9 — the embedding model: the request's, else config embedding_model. */
    protected function embeddingModel(\App\Services\Ai\Data\EmbeddingRequest $request): string
    {
        $model = $request->model ?? ($this->config['embedding_model'] ?? null);

        if (! is_string($model) || $model === '') {
            throw AiException::providerNotConfigured($this->name());
        }

        return $model;
    }

    /** Phase 8 Task 9 — optional fixed output dimension (config embedding_dimensions), else the model default. */
    protected function embeddingDimensions(): ?int
    {
        $value = $this->config['embedding_dimensions'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

        protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    /** @param callable(PendingRequest): Response $send */
    protected function call(PendingRequest $request, callable $send): Response
    {
        $request = $request->acceptJson()->asJson()->timeout(max(1, (int) config('ai.timeout', 30)));
        $retries = max(0, (int) config('ai.retries', 0));

        if ($retries > 0) {
            // Connection failures only: a vendor ANSWER (even an error) is never
            // retried here, since the vendor may already have billed it.
            $request = $request->retry($retries + 1, 0, fn (Throwable $e) => $e instanceof ConnectionException, throw: false);
        }

        try {
            $response = $send($request);
        } catch (ConnectionException $e) {
            // No vendor response was ever received here - vendorHttpStatus
            // stays null (the default).
            throw $this->isTimeout($e) ? AiException::timeout($this->name(), $e) : AiException::providerFailed($this->name(), $e);
        } catch (AiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw AiException::providerFailed($this->name(), $e);
        }

        if ($response->status() === 408 || $response->status() === 504) {
            // The vendor DID answer, with its own timeout-shaped status -
            // carry it, unlike the connection-level case above.
            throw AiException::timeout($this->name(), vendorHttpStatus: $response->status());
        }

        if (! $response->successful()) {
            throw AiException::providerFailed($this->name());
        }

        if (! is_array($response->json())) {
            throw AiException::malformedResponse($this->name());
        }

        return $response;
    }

    private function isTimeout(ConnectionException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28');
    }

    /**
     * The JSON object inside a model's text answer (tolerates a ```json fence).
     *
     * @return array<string, mixed>
     */
    protected function decodeObject(string $text, AiRequest $request): array
    {
        $candidate = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $candidate, $m) === 1) {
            $candidate = $m[1];
        }

        $data = json_decode($candidate, true);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw AiException::malformedResponse($this->name());
        }

        foreach ($request->requiredKeys as $key) {
            if (! array_key_exists($key, $data)) {
                throw AiException::malformedResponse($this->name());
            }
        }

        return $data;
    }

    public function generateStructured(AiRequest $request): AiResponse
    {
        $response = $this->complete($request, true);

        return $response->withData($this->decodeObject($response->text, $request));
    }

    public function generateText(AiRequest $request): AiResponse
    {
        return $this->complete($request, false);
    }

    /** One vendor call; $json asks the vendor for a JSON-object answer. */
    abstract protected function complete(AiRequest $request, bool $json): AiResponse;

    /** Shared instruction for vendors without a native JSON mode. */
    protected function jsonInstruction(): string
    {
        return 'Respond with a single valid JSON object and nothing else.';
    }

    protected static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Short-lived factory hook so tests can inspect the base request. */
    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl());
    }
}
