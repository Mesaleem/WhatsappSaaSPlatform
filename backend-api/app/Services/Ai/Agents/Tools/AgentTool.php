<?php

namespace App\Services\Ai\Agents\Tools;

/**
 * Phase 8 Task 11 — one server-side, allow-listed capability an agent may
 * be granted. A tool is a small PHP class operating on existing application
 * services under the caller's account; it never runs model-supplied code,
 * SQL, shell commands, HTTP requests or filesystem operations.
 *
 * Authorization is declared, not assumed: ToolExecutor checks, on EVERY
 * invocation and independently of the agent's own authorization, the
 * account (active, subscription, chatbot module + `ai` capability for agent
 * access), module(), capability(), permission() (against the acting user
 * when there is one), then authorize() for tool-specific rules, then
 * validates the arguments against inputSchema(). execute() only ever runs
 * after all of that; it must scope every resource to $context->account and
 * throw ToolError for a controlled failure.
 */
interface AgentTool
{
    /** Stable identifier, e.g. "crm.lead.find_current" (≤ 64 chars). */
    public function name(): string;

    public function label(): string;

    /** Shown to the model: what the tool does and when to use it. */
    public function description(): string;

    /**
     * JSON-Schema subset validated by ToolSchemaValidator: a root object
     * with `properties` (string / integer / boolean), `required`; extra
     * properties are always refused.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /** The permission a user must hold to grant (and, when acting, to run) the tool; null = none beyond the agent's. */
    public function permission(): ?string;

    /** The account module the tool needs (besides the Journey `chatbot` module); null = none. */
    public function module(): ?string;

    /** The capability the target account must hold (besides `ai`); null = none. */
    public function capability(): ?string;

    /** True when execute() writes data; its effect is then recorded exactly once. */
    public function sideEffect(): bool;

    /** A tool-specific refusal code, or null when this context may run the tool. */
    public function authorize(ToolContext $context): ?string;

    /**
     * @param  array<string, mixed>  $arguments  already validated against inputSchema()
     * @return array<string, mixed>  a small, normalized result (no secrets, minimal customer data)
     *
     * @throws ToolError a controlled failure reported back to the model
     */
    public function execute(ToolContext $context, array $arguments): array;
}
