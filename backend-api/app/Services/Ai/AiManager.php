<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Contracts\EmbeddingProvider;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use Closure;

/**
 * Phase 8 AI foundation — the single place that turns configuration into
 * an AiProvider. Nothing else in the application names a vendor class.
 *
 *   provider(null)     → config('ai.default')
 *   provider('openai') → that provider, if it is known, enabled and has
 *                        credentials (config('ai.providers.openai'))
 *
 * Failures are controlled AiExceptions:
 *   - no default configured / missing credentials → PROVIDER_NOT_CONFIGURED
 *   - unknown or not-enabled provider              → PROVIDER_UNAVAILABLE
 *
 * extend() registers another implementation under a key (a new vendor, or
 * a test double) without touching callers — that is the replaceability
 * seam. A registered provider still has to be listed in ai.enabled.
 *
 * Bound as a singleton (AppServiceProvider) so extensions persist for the
 * container's lifetime.
 */
class AiManager
{
    /** @var array<string, Closure(array): AiProvider> */
    private array $factories = [];

    /** @var array<string, AiProvider|EmbeddingProvider> */
    private array $resolved = [];

    public function __construct()
    {
        $this->factories = [
            'openai' => fn (array $config) => new OpenAiProvider($config),
            'anthropic' => fn (array $config) => new AnthropicProvider($config),
            // Phase 8 Task 8
            'gemini' => fn (array $config) => new GeminiProvider($config),
        ];
    }

    /** @param Closure(array): (AiProvider|EmbeddingProvider) $factory receives config('ai.providers.<name>', []) */
    public function extend(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->resolved[$name]);
    }

    public function defaultProviderName(): ?string
    {
        $name = config('ai.default');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** @throws AiException */
    public function provider(?string $name = null): AiProvider
    {
        $name ??= $this->defaultProviderName();

        if ($name === null) {
            throw AiException::providerNotConfigured();
        }

        $provider = $this->resolve($name);

        if (! $provider instanceof AiProvider) {
            throw AiException::providerUnavailable($name);
        }

        return $provider;
    }

    /**
     * Phase 8 Task 9 — the embedding provider: $name, else
     * config('ai.embeddings.provider'). Same registry, enabled list and
     * credentials as provider(); a registered provider without an embedding
     * API (e.g. anthropic) → AI_EMBEDDINGS_UNSUPPORTED. No default → AI
     * embeddings are off (PROVIDER_NOT_CONFIGURED), like generation.
     *
     * @throws AiException
     */
    public function embeddingProvider(?string $name = null): EmbeddingProvider
    {
        $configured = config('ai.embeddings.provider');
        $name ??= is_string($configured) && trim($configured) !== '' ? trim($configured) : null;

        if ($name === null) {
            throw AiException::providerNotConfigured();
        }

        $provider = $this->resolve($name);

        if (! $provider instanceof EmbeddingProvider) {
            throw AiException::embeddingsUnsupported($name);
        }

        return $provider;
    }

    /** @throws AiException */
    private function resolve(string $name): AiProvider|EmbeddingProvider
    {
        if (! isset($this->factories[$name]) || ! in_array($name, (array) config('ai.enabled', []), true)) {
            throw AiException::providerUnavailable($name);
        }

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $provider = ($this->factories[$name])((array) config("ai.providers.{$name}", []));

        if (! ($provider instanceof AiProvider || $provider instanceof EmbeddingProvider) || $provider->name() !== $name) {
            throw AiException::providerUnavailable($name);
        }

        return $this->resolved[$name] = $provider;
    }

    /** True when provider() would resolve without throwing. */
    public function available(?string $name = null): bool
    {
        try {
            $this->provider($name);

            return true;
        } catch (AiException) {
            return false;
        }
    }

    /** Forget resolved instances (config changed at runtime, e.g. in tests). */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
