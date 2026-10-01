<?php

namespace App\Services\Ai\Agents\Tools;

use InvalidArgumentException;

/**
 * Phase 8 Task 11 — the closed set of tools agents can use. A tool exists
 * for agents only when BOTH
 *   - its class is registered here (AppServiceProvider — code-owned), and
 *   - its id is in config('ai.agents.tools') (the platform allow-list).
 * There is no way to register a tool at run time, from a request, from an
 * agent's configuration or from model output.
 */
final class ToolRegistry
{
    /** @var array<string, AgentTool> */
    private array $tools = [];

    /** @param iterable<AgentTool> $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(AgentTool $tool): void
    {
        $name = $tool->name();

        if (preg_match('/\A[a-z][a-z0-9_.]{2,63}\z/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid agent tool name '{$name}'.");
        }

        $this->tools[$name] = $tool;
    }

    /** The allow-listed tool, or null (unregistered or not allow-listed). */
    public function get(string $name): ?AgentTool
    {
        return in_array($name, (array) config('ai.agents.tools', []), true) ? ($this->tools[$name] ?? null) : null;
    }

    /** @return array<string, AgentTool> every allow-listed, registered tool */
    public function all(): array
    {
        return array_filter($this->tools, fn (AgentTool $tool) => $this->get($tool->name()) !== null);
    }

    /** @return array{name: string, label: string, description: string, input_schema: array<string, mixed>, side_effect: bool, permission: ?string, module: ?string, capability: ?string} */
    public static function describe(AgentTool $tool): array
    {
        return [
            'name' => $tool->name(),
            'label' => $tool->label(),
            'description' => $tool->description(),
            'input_schema' => $tool->inputSchema(),
            'side_effect' => $tool->sideEffect(),
            'permission' => $tool->permission(),
            'module' => $tool->module(),
            'capability' => $tool->capability(),
        ];
    }
}
