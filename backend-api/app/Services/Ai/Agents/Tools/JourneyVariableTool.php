<?php

namespace App\Services\Ai\Agents\Tools;

use App\Services\WhatsApp\JourneyActionConfig;

/**
 * Phase 8 Task 11 — read ONE variable of the current Journey conversation
 * (a value the journey itself collected, e.g. an answer to a question).
 * Read-only; only the running session's own variables ('@' internal state
 * is never visible); no other session, no conversation history.
 */
final class JourneyVariableTool implements AgentTool
{
    public const NAME = 'journey.variable.get';

    private const MAX_VALUE_CHARS = 500;

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Read a journey variable';
    }

    public function description(): string
    {
        return 'Read the value of one variable collected earlier in this conversation (for example an answer the customer gave). Returns found=false when the variable is not set.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9_.\-]{1,64}$'],
            ],
            'required' => ['name'],
        ];
    }

    public function permission(): ?string
    {
        return null;
    }

    public function module(): ?string
    {
        return null;
    }

    public function capability(): ?string
    {
        return null;
    }

    public function sideEffect(): bool
    {
        return false;
    }

    public function authorize(ToolContext $context): ?string
    {
        return $context->session === null ? 'no_conversation' : null;
    }

    public function execute(ToolContext $context, array $arguments): array
    {
        $name = (string) $arguments['name'];

        if (preg_match(JourneyActionConfig::VARIABLE_NAME_PATTERN, $name) !== 1) {
            throw new ToolError('invalid_arguments', 'Invalid variable name.');
        }

        $variables = $context->variables();

        if (! array_key_exists($name, $variables) || ! is_scalar($variables[$name])) {
            return ['name' => $name, 'found' => false];
        }

        $value = $variables[$name];

        return ['name' => $name, 'found' => true, 'value' => mb_substr(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 0, self::MAX_VALUE_CHARS)];
    }
}
