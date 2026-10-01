<?php

namespace App\Services\SocialAuth\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Phase 9 Task 2 — a provider API call made with a stored social
 * connection's credentials returned an error. Carries the HTTP status and
 * ONLY the provider's `error` object (never the request, headers or token)
 * so SocialConnectionService can ask the provider driver whether the
 * failure means the connection itself is expired/revoked.
 *
 * Extends RuntimeException and keeps the message the feature used before,
 * so every existing `catch (RuntimeException)` behaves exactly as it did.
 */
class ProviderRequestFailed extends RuntimeException
{
    /** @param array<string, mixed> $errorBody */
    public function __construct(string $message, public readonly int $httpStatus, public readonly array $errorBody = [])
    {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response, string $fallbackMessage, ?string $messagePath = 'error.message'): self
    {
        $error = $response->json('error');
        $message = $messagePath ? $response->json($messagePath) : null;

        return new self(
            is_string($message) && $message !== '' ? $message : $fallbackMessage,
            $response->status(),
            is_array($error) ? ['error' => $error] : [],
        );
    }
}
