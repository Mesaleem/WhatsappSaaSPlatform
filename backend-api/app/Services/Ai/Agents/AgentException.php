<?php

namespace App\Services\Ai\Agents;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Phase 8 Task 11 — the agent registry's controlled error (same envelope as
 * AiException / KnowledgeException: {success:false, message, error_code}).
 * Messages are safe: never instructions, tool arguments, a credential or
 * another account's data. "Not found" is also what another account's id
 * gets — existence is never revealed across accounts.
 */
class AgentException extends RuntimeException
{
    public const NOT_FOUND = 'AI_AGENT_NOT_FOUND';

    public const NAME_TAKEN = 'AI_AGENT_NAME_TAKEN';

    public const DISABLED = 'AI_AGENT_DISABLED';

    public const UNKNOWN_TOOL = 'AI_AGENT_UNKNOWN_TOOL';

    public const TOOL_NOT_PERMITTED = 'AI_AGENT_TOOL_NOT_PERMITTED';

    public const TOOL_UNAVAILABLE = 'AI_AGENT_TOOL_UNAVAILABLE';

    public const INVALID_CONFIGURATION = 'AI_AGENT_INVALID_CONFIGURATION';

    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'error_code' => $this->errorCode], $this->httpStatus);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'AI agent not found.', 404);
    }

    public static function nameTaken(): self
    {
        return new self(self::NAME_TAKEN, 'An AI agent with this name already exists.', 422);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'This AI agent is disabled.', 422);
    }

    public static function unknownTool(string $tool): self
    {
        return new self(self::UNKNOWN_TOOL, "Tool '".mb_substr($tool, 0, 64)."' is not available on this platform.", 422);
    }

    public static function toolNotPermitted(string $tool): self
    {
        return new self(self::TOOL_NOT_PERMITTED, "You are not permitted to grant the tool '".mb_substr($tool, 0, 64)."'.", 403);
    }

    public static function toolUnavailable(string $tool): self
    {
        return new self(self::TOOL_UNAVAILABLE, "The tool '".mb_substr($tool, 0, 64)."' is not available for this account (its module or capability is not enabled).", 422);
    }

    public static function invalidConfiguration(string $detail): self
    {
        return new self(self::INVALID_CONFIGURATION, $detail, 422);
    }
}
