<?php

namespace App\Services\Ai\Agents\Tools;

use App\Models\Account;
use App\Models\AiAgent;
use App\Models\AiAgentToolInvocation;
use App\Models\AiAgentVersion;
use App\Services\Access\AccessControlService;
use App\Services\Access\EntitlementAuditLogger;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\AiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8 Task 11 — the ONLY way an agent's tool call is executed.
 *
 * invoke() returns the normalized value handed back to the model:
 *   {ok: true,  data: {...}}                     the tool ran
 *   {ok: false, error: "<code>", message: "..."} refused or a controlled failure
 * and never throws for a refusal. It throws only for an unexpected failure
 * (database down …), which rolls back any partial effect and fails the
 * surrounding step so it is retried with the SAME invocation key.
 *
 * EVERY invocation independently checks, in order — the agent having been
 * authorized proves nothing about a tool or a resource:
 *   1. the tool is registered AND platform allow-listed   (unknown_tool)
 *   2. the tool is in THIS agent version's allow-list      (tool_not_allowed)
 *   3. agent + version belong to the context account, the agent is still
 *      enabled (fresh read)                                (agent_unavailable)
 *   4. agent access: AiAuthorizer (fresh) — account active, subscription
 *      current, `chatbot` module, `ai` capability, and with an acting user
 *      also that user's target-account rule + `manage-chatbot`
 *   5. the tool's module / capability on the account and, with an acting
 *      user, the tool's permission                         (module_disabled, …)
 *   6. the tool's own rule (authorize(): e.g. a conversation is required,
 *      the CRM account/subscription rule)
 *   7. the arguments against the tool's schema             (invalid_arguments)
 * Source/origin (journey, manual …) never grants anything. Refusals at 2–5
 * are also written to the P5-8 entitlement audit trail (action agent.tool).
 *
 * EXACTLY ONCE. Each call has a deterministic invocation key (the Journey
 * step's operation key + ":t{n}"). The stored row for that key is returned
 * as-is on any retry. For a side-effecting tool the effect and the row are
 * written in ONE transaction: a crash before commit leaves neither (the
 * retry runs it once), after commit the retry finds the row (no second
 * effect). A concurrent duplicate loses on unique(account_id,
 * invocation_key), its transaction — effect included — rolls back, and it
 * returns the winner's result.
 *
 * DATA MINIMIZATION. Arguments are stored only as a hash; results are
 * normalized (scalars, strings cut, bounded size) before storage and before
 * the model sees them; nothing here is logged.
 */
class ToolExecutor
{
    public const MODULE = 'chatbot';

    public const PERMISSION = 'manage-chatbot';

    private const MAX_STRING = 500;

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly AiAuthorizer $authorizer,
        private readonly AccessControlService $access,
        private readonly EntitlementAuditLogger $audit,
    ) {
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, message?: string}
     */
    public function invoke(ToolContext $context, string $toolName, mixed $arguments, string $invocationKey): array
    {
        $accountId = (int) $context->account->id;
        $toolName = mb_substr($toolName, 0, 64);
        $hash = hash('sha256', $toolName."\n".json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

        if (($stored = $this->stored($accountId, $invocationKey, $toolName, $hash)) !== null) {
            return $stored;
        }

        $tool = $this->registry->get($toolName);

        if ($tool === null) {
            return $this->deny($context, $invocationKey, $toolName, $hash, 'unknown_tool', 'This tool does not exist.', false);
        }

        if (! in_array($toolName, $context->version->toolNames(), true)) {
            return $this->deny($context, $invocationKey, $toolName, $hash, 'tool_not_allowed', 'This agent is not allowed to use this tool.', true, 'tool_not_allowed');
        }

        if (! $this->agentStillOwned($context)) {
            return $this->deny($context, $invocationKey, $toolName, $hash, 'agent_unavailable', 'The agent is disabled or no longer available.', true, 'cross_tenant');
        }

        try {
            $context->actor !== null
                ? $this->authorizer->forUser($context->actor, $accountId, self::MODULE, self::PERMISSION, $context->source)
                : $this->authorizer->forAccount($context->account, self::MODULE, $context->source);
        } catch (AiException $e) {
            // AiAuthorizer already wrote its own denied audit row.
            return $this->deny($context, $invocationKey, $toolName, $hash, 'not_authorized', 'The account may not use AI agents right now ('.$e->errorCode.').', false);
        }

        $account = Account::query()->findOrFail($accountId);

        if ($tool->module() !== null && ! $account->hasModuleEnabled($tool->module())) {
            return $this->deny($context, $invocationKey, $toolName, $hash, 'module_disabled', 'The feature this tool needs is not enabled for this account.', true, 'module_disabled', $tool);
        }

        if ($tool->capability() !== null && ! $this->access->canTenant($account, $tool->capability())) {
            return $this->deny($context, $invocationKey, $toolName, $hash, 'capability_not_entitled', "This account's plan does not include the feature this tool needs.", true, 'capability_not_entitled', $tool);
        }

        if ($tool->permission() !== null && $context->actor !== null && ! $context->actor->can($tool->permission())) {
            return $this->deny($context, $invocationKey, $toolName, $hash, 'missing_permission', 'The acting user may not use this tool.', true, 'missing_permission', $tool);
        }

        if (($refusal = $tool->authorize($context)) !== null) {
            return $this->deny($context, $invocationKey, $toolName, $hash, $refusal, match ($refusal) {
                'no_conversation' => 'This tool needs a customer conversation.',
                'crm_unavailable' => 'The CRM cannot be changed for this account right now.',
                default => 'This tool cannot be used here.',
            }, false);
        }

        $arguments ??= [];
        $errors = ToolSchemaValidator::errors($tool->inputSchema(), $arguments);

        if ($errors !== []) {
            return $this->deny($context, $invocationKey, $toolName, $hash, 'invalid_arguments', mb_substr(implode(' ', $errors), 0, 300), false);
        }

        return $this->execute($context, $tool, $arguments, $invocationKey, $hash);
    }

    /** @return array{ok: bool, data?: array<string, mixed>, error?: string, message?: string} */
    private function execute(ToolContext $context, AgentTool $tool, array $arguments, string $invocationKey, string $hash): array
    {
        $accountId = (int) $context->account->id;

        try {
            return DB::transaction(function () use ($context, $tool, $arguments, $invocationKey, $hash): array {
                $result = ['ok' => true, 'data' => self::normalize($tool->execute($context, $arguments))];
                // Same transaction as the effect: the row exists iff the effect happened.
                AiAgentToolInvocation::create($this->row($context, $invocationKey, $tool->name(), $hash, AiAgentToolInvocation::STATUS_SUCCEEDED, $result, null));

                return $result;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent run of the same invocation committed first; ours rolled back.
            return $this->stored($accountId, $invocationKey, $tool->name(), $hash) ?? throw $e;
        } catch (ToolError $e) {
            return $this->record($context, $invocationKey, $tool->name(), $hash, AiAgentToolInvocation::STATUS_ERROR, $e->errorCode, $e->getMessage());
        } catch (ValidationException $e) {
            // A domain guard (e.g. CrmLead's saving invariants) refused the write; it was rolled back.
            return $this->record($context, $invocationKey, $tool->name(), $hash, AiAgentToolInvocation::STATUS_ERROR, 'rejected', mb_substr((string) collect($e->errors())->flatten()->first(), 0, 200));
        }
    }

    /** The stored outcome of this invocation key, or null when it never ran. */
    private function stored(int $accountId, string $invocationKey, string $toolName, string $hash): ?array
    {
        $row = AiAgentToolInvocation::query()->forAccount($accountId)->where('invocation_key', $invocationKey)->first();

        if ($row === null) {
            return null;
        }

        if ($row->tool !== $toolName || $row->arguments_hash !== $hash) {
            // The same step asked for a different call than the one recorded: never execute it.
            return ['ok' => false, 'error' => 'invocation_conflict', 'message' => 'This tool call conflicts with one already recorded for this step.'];
        }

        return is_array($row->result) ? $row->result : ['ok' => false, 'error' => (string) $row->error_code, 'message' => 'The tool call was refused.'];
    }

    private function agentStillOwned(ToolContext $context): bool
    {
        $accountId = (int) $context->account->id;

        $agent = AiAgent::query()->forAccount($accountId)->find((int) $context->agent->id);

        return $agent !== null && $agent->is_enabled
            && AiAgentVersion::query()->forAccount($accountId)->where('ai_agent_id', $agent->id)->whereKey((int) $context->version->id)->exists();
    }

    /** @return array{ok: false, error: string, message: string} */
    private function deny(ToolContext $context, string $invocationKey, string $toolName, string $hash, string $code, string $message, bool $audit, ?string $category = null, ?AgentTool $tool = null): array
    {
        if ($audit) {
            $this->audit->record($context->account, false, [
                'action' => 'agent.tool',
                'resource_type' => 'ai_agent',
                'resource_id' => (int) $context->agent->id,
                'category' => $category ?? $code,
                'source' => $context->source,
                'module' => $tool?->module(),
                'capability' => $tool?->capability(),
                'permission' => $tool?->permission(),
                'tool' => $toolName,
                'session_id' => $context->session?->id,
                'actor_account_id' => $context->actor?->account_id === null ? null : (int) $context->actor->account_id,
                'target_account_id' => (int) $context->account->id,
                'error_code' => $code,
            ]);
        }

        return $this->record($context, $invocationKey, $toolName, $hash, AiAgentToolInvocation::STATUS_DENIED, $code, $message);
    }

    /** Record a refusal / controlled error once (the first record wins) and return what is stored. */
    private function record(ToolContext $context, string $invocationKey, string $toolName, string $hash, string $status, string $code, string $message): array
    {
        $result = ['ok' => false, 'error' => $code, 'message' => mb_substr($message, 0, 300)];

        DB::table('ai_agent_tool_invocations')->insertOrIgnore($this->row($context, $invocationKey, $toolName, $hash, $status, $result, $code, true));

        return $this->stored((int) $context->account->id, $invocationKey, $toolName, $hash) ?? $result;
    }

    private function row(ToolContext $context, string $invocationKey, string $toolName, string $hash, string $status, array $result, ?string $errorCode, bool $raw = false): array
    {
        return [
            'account_id' => (int) $context->account->id,
            'ai_agent_id' => (int) $context->agent->id,
            'ai_agent_version_id' => (int) $context->version->id,
            'invocation_key' => mb_substr($invocationKey, 0, 191),
            'tool' => $toolName,
            'status' => $status,
            'arguments_hash' => $hash,
            'result' => $raw ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $result,
            'error_code' => $errorCode === null ? null : mb_substr($errorCode, 0, 64),
            'source' => mb_substr($context->source, 0, 32),
            'flow_id' => $context->session?->flow_id,
            'session_id' => $context->session?->id,
            'actor_user_id' => $context->actor?->id,
        ] + ($raw ? ['created_at' => now(), 'updated_at' => now()] : []);
    }

    /**
     * Keep only scalars (strings cut) in at most two levels of small arrays.
     *
     * @param  array<mixed>  $data
     * @return array<string|int, mixed>
     */
    public static function normalize(array $data, int $depth = 0): array
    {
        $out = [];

        foreach (array_slice($data, 0, 20, true) as $key => $value) {
            if (is_string($value)) {
                $out[$key] = mb_substr($value, 0, self::MAX_STRING);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $out[$key] = $value;
            } elseif (is_array($value) && $depth < 1) {
                $out[$key] = self::normalize($value, $depth + 1);
            }
        }

        return $out;
    }
}
