<?php

namespace App\Services\Ai;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * Phase 8 AI foundation — the one controlled error type of the AI layer.
 *
 * Every failure a caller can see carries a stable error code, an HTTP
 * status and a SAFE message: never a credential, request header, provider
 * response body, prompt, stack trace or another tenant's data. The raw
 * cause (if any) stays in getPrevious() for server-side debugging only and
 * is never rendered.
 *
 * Rendered with the project's usual envelope
 * (`{success: false, message, error_code}`, as EnsureModuleEnabledMiddleware
 * and EnsureCapabilityMiddleware answer), should one ever reach an HTTP
 * response.
 */
class AiException extends RuntimeException
{
    // authorization / entitlement
    public const CAPABILITY_UNAVAILABLE = 'AI_CAPABILITY_UNAVAILABLE';

    public const MODULE_DISABLED = 'MODULE_DISABLED';

    public const PERMISSION_DENIED = 'AI_PERMISSION_DENIED';

    public const SUBSCRIPTION_INACTIVE = 'SUBSCRIPTION_EXPIRED';

    public const ACCOUNT_SUSPENDED = 'CLIENT_ACCOUNT_SUSPENDED';

    public const TARGET_ACCOUNT_REQUIRED = 'AI_TARGET_ACCOUNT_REQUIRED';

    public const TARGET_ACCOUNT_FORBIDDEN = 'AI_TARGET_ACCOUNT_FORBIDDEN';

    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    // provider
    public const PROVIDER_UNAVAILABLE = 'AI_PROVIDER_UNAVAILABLE';

    public const PROVIDER_NOT_CONFIGURED = 'AI_PROVIDER_NOT_CONFIGURED';

    public const PROVIDER_FAILED = 'AI_PROVIDER_FAILED';

    public const PROVIDER_TIMEOUT = 'AI_PROVIDER_TIMEOUT';

    public const MALFORMED_RESPONSE = 'AI_MALFORMED_RESPONSE';

    public const INVALID_REQUEST = 'AI_INVALID_REQUEST';

    // Phase 8 Task 9 — the selected provider has no embedding API
    public const EMBEDDINGS_UNSUPPORTED = 'AI_EMBEDDINGS_UNSUPPORTED';

    // billing (Phase 8 Task 5)
    public const INSUFFICIENT_CREDITS = 'INSUFFICIENT_CREDITS'; // the credit system's own code (AdminCreditController)

    public const OPERATION_DUPLICATE = 'AI_OPERATION_DUPLICATE';

    public const BILLING_UNAVAILABLE = 'AI_BILLING_UNAVAILABLE';

    /** Error category for logs (no free text). */
    public const CATEGORIES = [
        self::CAPABILITY_UNAVAILABLE => 'entitlement',
        self::MODULE_DISABLED => 'entitlement',
        self::SUBSCRIPTION_INACTIVE => 'entitlement',
        self::ACCOUNT_SUSPENDED => 'entitlement',
        self::PERMISSION_DENIED => 'authorization',
        self::TARGET_ACCOUNT_REQUIRED => 'authorization',
        self::TARGET_ACCOUNT_FORBIDDEN => 'authorization',
        self::UNAUTHENTICATED => 'authorization',
        self::PROVIDER_UNAVAILABLE => 'configuration',
        self::PROVIDER_NOT_CONFIGURED => 'configuration',
        self::PROVIDER_FAILED => 'provider',
        self::PROVIDER_TIMEOUT => 'timeout',
        self::MALFORMED_RESPONSE => 'malformed_response',
        self::INVALID_REQUEST => 'invalid_request',
        self::EMBEDDINGS_UNSUPPORTED => 'configuration',
        self::INSUFFICIENT_CREDITS => 'credits',
        self::OPERATION_DUPLICATE => 'duplicate',
        self::BILLING_UNAVAILABLE => 'billing',
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function category(): string
    {
        return self::CATEGORIES[$this->errorCode] ?? 'unknown';
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->httpStatus);
    }

    // ------------------------------------------------------------ authorization

    public static function capabilityUnavailable(): self
    {
        return new self(self::CAPABILITY_UNAVAILABLE, 'Your current plan does not include AI features. Please upgrade your subscription to unlock them.', 403);
    }

    public static function moduleDisabled(): self
    {
        return new self(self::MODULE_DISABLED, 'This feature has been disabled for your account by the Super Admin.', 403);
    }

    public static function permissionDenied(): self
    {
        return new self(self::PERMISSION_DENIED, 'You do not have permission to use this AI feature.', 403);
    }

    public static function subscriptionInactive(): self
    {
        return new self(self::SUBSCRIPTION_INACTIVE, "The account's subscription is not active. AI features are unavailable until it is renewed.", 403);
    }

    public static function accountSuspended(): self
    {
        return new self(self::ACCOUNT_SUSPENDED, 'This organization account has been suspended. Contact Super Admin.', 403);
    }

    public static function targetAccountRequired(): self
    {
        return new self(self::TARGET_ACCOUNT_REQUIRED, 'Select a client/tenant account to use AI features for (pass ?account_id=).', 422);
    }

    public static function targetAccountForbidden(): self
    {
        return new self(self::TARGET_ACCOUNT_FORBIDDEN, 'The selected account was not found or you may not act on it.', 404);
    }

    public static function unauthenticated(): self
    {
        return new self(self::UNAUTHENTICATED, 'Unauthenticated.', 401);
    }

    // ------------------------------------------------------------ provider

    public static function providerUnavailable(string $provider): self
    {
        return new self(self::PROVIDER_UNAVAILABLE, "The AI provider '{$provider}' is not available.", 503);
    }

    public static function providerNotConfigured(?string $provider = null): self
    {
        return new self(self::PROVIDER_NOT_CONFIGURED, $provider === null || $provider === ''
            ? 'No AI provider is configured.'
            : "The AI provider '{$provider}' is not configured.", 503);
    }

    public static function providerFailed(string $provider, ?Throwable $previous = null): self
    {
        return new self(self::PROVIDER_FAILED, "The AI provider '{$provider}' could not complete the request.", 502, $previous);
    }

    public static function timeout(string $provider, ?Throwable $previous = null): self
    {
        return new self(self::PROVIDER_TIMEOUT, "The AI provider '{$provider}' did not respond in time.", 504, $previous);
    }

    public static function embeddingsUnsupported(string $provider): self
    {
        return new self(self::EMBEDDINGS_UNSUPPORTED, "The AI provider '{$provider}' does not provide embeddings.", 503);
    }

    public static function malformedResponse(string $provider): self
    {
        return new self(self::MALFORMED_RESPONSE, "The AI provider '{$provider}' returned a response that could not be understood.", 502);
    }

    // ------------------------------------------------------------ billing (Phase 8 Task 5)

    public static function insufficientCredits(int $required, int $available, ?Throwable $previous = null): self
    {
        return new self(self::INSUFFICIENT_CREDITS, "Insufficient credits: {$required} requested, {$available} available.", 422, $previous);
    }

    public static function duplicateOperation(): self
    {
        return new self(self::OPERATION_DUPLICATE, 'This AI operation has already been run or is still running; it is never charged twice.', 409);
    }

    public static function billingUnavailable(?Throwable $previous = null): self
    {
        return new self(self::BILLING_UNAVAILABLE, 'AI credits could not be reserved for this operation. Try again later.', 503, $previous);
    }

    public static function invalidRequest(string $detail): self
    {
        return new self(self::INVALID_REQUEST, $detail, 422);
    }
}
