<?php

namespace App\Services\Social\Publishing\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Phase 9 Task 3 — a publishing action refused before any provider call:
 * the target account may not use social features (403, SocialTargetGate
 * codes), or an idempotency key was reused for a different post / a post
 * is not in a state that allows the action (409).
 */
class PublishingDenied extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    /** @param array{code: string, message: string} $denial */
    public static function target(array $denial): self
    {
        return new self($denial['message'], $denial['code'], 403);
    }

    public static function conflict(string $message, string $code): self
    {
        return new self($message, $code, 409);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'error_code' => $this->errorCode], $this->httpStatus);
    }
}
