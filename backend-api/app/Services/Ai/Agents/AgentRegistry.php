<?php

namespace App\Services\Ai\Agents;

use App\Models\Account;
use App\Models\AiAgent;
use App\Models\AiAgentVersion;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Ai\Agents\Tools\AgentTool;
use App\Services\Ai\Agents\Tools\ToolRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 Task 11 — the account-scoped agent registry (no AI call, no
 * charge here). Every method takes the TARGET account the caller already
 * authorized (AiAuthorizer::forRequest in AiAgentController); an agent id is
 * only ever resolved inside that account.
 *
 * VERSIONING. The executable part of an agent — instructions, model hint,
 * tool allow-list, limit overrides — lives in immutable AiAgentVersion rows.
 * An update that changes any of it appends version n+1 and moves
 * current_version_id; an update that changes nothing executable (name,
 * description, enabled) creates no version. Executions pin the version they
 * start with (per Journey session), so editing an agent never changes a
 * conversation already using it.
 *
 * TOOL GRANTS. A tool is granted by the user who creates the version (or
 * enables the agent): that user must hold the tool's permission (Spatie +
 * the existing Gate::before Super Admin rule) and the target account must
 * have the tool's module and capability. A version is created only from a
 * complete, re-checked tool list, so a user who lacks e.g. `manage-crm`
 * cannot carry CRM tools forward into a new version either. Every tool is
 * checked again on every invocation (ToolExecutor) — a grant is necessary,
 * never sufficient.
 */
class AgentRegistry
{
    public const SETTINGS = ['max_model_calls', 'max_tool_calls'];

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AccessControlService $access,
    ) {
    }

    public function find(Account $account, int|string $id): AiAgent
    {
        $agent = (is_int($id) || (is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) === 1))
            ? AiAgent::query()->forAccount((int) $account->id)->find((int) $id)
            : null;

        return $agent ?? throw AgentException::notFound();
    }

    /**
     * @param  array{name: string, description?: ?string, instructions: string, model?: ?string, tools?: list<string>, settings?: array<string, int>|null, is_enabled?: bool}  $data
     */
    public function create(Account $account, User $actor, array $data): AiAgent
    {
        $name = trim($data['name']);
        $config = $this->config($account, $actor, $data['instructions'], $data['model'] ?? null, $data['tools'] ?? [], $data['settings'] ?? null);

        if (AiAgent::query()->forAccount((int) $account->id)->where('name', $name)->exists()) {
            throw AgentException::nameTaken();
        }

        try {
            return DB::transaction(function () use ($account, $actor, $data, $name, $config): AiAgent {
                $agent = AiAgent::create([
                    'account_id' => $account->id,
                    'name' => $name,
                    'description' => $this->clean($data['description'] ?? null),
                    'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                    'created_by_user_id' => $actor->id,
                    'updated_by_user_id' => $actor->id,
                ]);

                $this->appendVersion($agent, $config, $actor);

                return $agent->fresh();
            });
        } catch (QueryException $e) {
            if (AiAgent::query()->forAccount((int) $account->id)->where('name', $name)->exists()) {
                throw AgentException::nameTaken();
            }

            throw $e;
        }
    }

    /**
     * Partial update. Executable fields (instructions, model, tools, settings)
     * produce a new version only when the resulting configuration differs.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, AiAgent $agent, User $actor, array $data): AiAgent
    {
        $this->assertOwned($account, $agent);

        return DB::transaction(function () use ($account, $agent, $actor, $data): AiAgent {
            $agent = AiAgent::query()->forAccount((int) $account->id)->lockForUpdate()->findOrFail($agent->id);
            $current = $agent->currentVersion();

            $executable = array_intersect_key($data, array_flip(['instructions', 'model', 'tools', 'settings']));

            if ($executable !== [] || $current === null) {
                $config = $this->config(
                    $account, $actor,
                    array_key_exists('instructions', $data) ? (string) $data['instructions'] : (string) $current?->instructions,
                    array_key_exists('model', $data) ? $data['model'] : $current?->model,
                    array_key_exists('tools', $data) ? (array) $data['tools'] : ($current?->toolNames() ?? []),
                    array_key_exists('settings', $data) ? $data['settings'] : $current?->settings,
                );

                if ($current === null || $config['config_hash'] !== $current->config_hash) {
                    $this->appendVersion($agent, $config, $actor);
                    $agent->refresh();
                }
            }

            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);

                if ($name !== $agent->name && AiAgent::query()->forAccount((int) $account->id)->where('name', $name)->whereKeyNot($agent->id)->exists()) {
                    throw AgentException::nameTaken();
                }

                $agent->name = $name;
            }

            if (array_key_exists('description', $data)) {
                $agent->description = $this->clean($data['description']);
            }

            $agent->updated_by_user_id = $actor->id;
            $agent->save();

            return $agent->fresh();
        });
    }

    /** Enabling re-checks the grant of every tool of the current version for the acting user; disabling never needs to. */
    public function setEnabled(Account $account, AiAgent $agent, User $actor, bool $enabled): AiAgent
    {
        $this->assertOwned($account, $agent);

        if ($enabled) {
            $current = $agent->currentVersion() ?? throw AgentException::invalidConfiguration('The agent has no configuration.');
            $this->assertGrantable($account, $actor, $current->toolNames());
        }

        $agent->forceFill(['is_enabled' => $enabled, 'updated_by_user_id' => $actor->id])->save();

        return $agent->fresh();
    }

    public function delete(Account $account, AiAgent $agent): void
    {
        $this->assertOwned($account, $agent);

        // Versions cascade (composite FK). Tool-invocation audit rows are kept.
        $agent->delete();
    }

    /**
     * The version an execution should use: the pinned one when it still
     * belongs to this agent, else the current one. Resolved inside $account.
     */
    public function executableVersion(Account $account, AiAgent $agent, ?int $pinnedVersionId): ?AiAgentVersion
    {
        $this->assertOwned($account, $agent);

        $query = fn () => AiAgentVersion::query()->forAccount((int) $account->id)->where('ai_agent_id', $agent->id);

        return ($pinnedVersionId !== null ? $query()->find($pinnedVersionId) : null) ?? $agent->currentVersion();
    }

    /** @return array<string, mixed> the API view of an agent and its current version */
    public function present(AiAgent $agent): array
    {
        $version = $agent->currentVersion();

        return [
            'id' => (int) $agent->id,
            'account_id' => (int) $agent->account_id,
            'name' => $agent->name,
            'description' => $agent->description,
            'is_enabled' => (bool) $agent->is_enabled,
            'version' => $version?->version,
            'version_id' => $version?->id,
            'version_count' => (int) $agent->version_count,
            'instructions' => $version?->instructions,
            'model' => $version?->model,
            'tools' => $version?->toolNames() ?? [],
            'settings' => $version?->settings ?? (object) [],
            'created_at' => $agent->created_at?->toIso8601String(),
            'updated_at' => $agent->updated_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> the allow-listed tools, with whether this user may grant them for this account */
    public function availableTools(Account $account, User $actor): array
    {
        return array_values(array_map(fn (AgentTool $tool) => ToolRegistry::describe($tool) + [
            'grantable' => $this->grantError($account, $actor, $tool) === null,
        ], $this->tools->all()));
    }

    // ------------------------------------------------------------------ internals

    private function assertOwned(Account $account, AiAgent $agent): void
    {
        if ((int) $agent->account_id !== (int) $account->id) {
            throw AgentException::notFound();
        }
    }

    /**
     * Normalize + validate one executable configuration.
     *
     * @return array{instructions: string, model: ?string, tools: list<string>, settings: array<string, int>, config_hash: string}
     */
    private function config(Account $account, User $actor, string $instructions, mixed $model, array $tools, mixed $settings): array
    {
        $instructions = trim($instructions);
        $max = (int) config('ai.agents.max_instructions_chars', 8000);

        if ($instructions === '' || mb_strlen($instructions) > $max) {
            throw AgentException::invalidConfiguration("The instructions must be between 1 and {$max} characters.");
        }

        $model = is_string($model) && trim($model) !== '' ? trim($model) : null;

        if ($model !== null && preg_match('/\A[A-Za-z0-9._:\-\/]{1,128}\z/', $model) !== 1) {
            throw AgentException::invalidConfiguration('The model may contain only letters, digits and . _ : - / (at most 128).');
        }

        $names = [];
        foreach ($tools as $tool) {
            if (! is_string($tool)) {
                throw AgentException::invalidConfiguration('Tools must be a list of tool ids.');
            }
            $names[trim($tool)] = true;
        }
        $names = array_keys($names);
        sort($names);
        $this->assertGrantable($account, $actor, $names);

        $clean = [];
        foreach (is_array($settings) ? $settings : [] as $key => $value) {
            if (! in_array($key, self::SETTINGS, true)) {
                throw AgentException::invalidConfiguration("Unknown setting '".mb_substr((string) $key, 0, 64)."'.");
            }
            $ceiling = (int) config("ai.agents.{$key}");
            $floor = $key === 'max_model_calls' ? 1 : 0;
            if (! is_int($value) || $value < $floor || $value > $ceiling) {
                throw AgentException::invalidConfiguration("The setting {$key} must be a whole number between {$floor} and {$ceiling} (the platform limit).");
            }
            $clean[$key] = $value;
        }
        ksort($clean);

        return [
            'instructions' => $instructions,
            'model' => $model,
            'tools' => $names,
            'settings' => $clean,
            'config_hash' => hash('sha256', json_encode([$instructions, $model, $names, $clean], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** @param list<string> $names */
    private function assertGrantable(Account $account, User $actor, array $names): void
    {
        foreach ($names as $name) {
            $tool = $this->tools->get($name) ?? throw AgentException::unknownTool($name);

            if (($error = $this->grantError($account, $actor, $tool)) !== null) {
                throw $error;
            }
        }
    }

    private function grantError(Account $account, User $actor, AgentTool $tool): ?AgentException
    {
        if ($tool->permission() !== null && ! $actor->can($tool->permission())) {
            return AgentException::toolNotPermitted($tool->name());
        }

        if (($tool->module() !== null && ! $account->hasModuleEnabled($tool->module()))
            || ($tool->capability() !== null && ! $this->access->canTenant($account, $tool->capability()))) {
            return AgentException::toolUnavailable($tool->name());
        }

        return null;
    }

    /** @param array{instructions: string, model: ?string, tools: list<string>, settings: array<string, int>, config_hash: string} $config */
    private function appendVersion(AiAgent $agent, array $config, User $actor): AiAgentVersion
    {
        $number = (int) $agent->version_count + 1;

        $version = AiAgentVersion::create([
            'account_id' => $agent->account_id,
            'ai_agent_id' => $agent->id,
            'version' => $number,
            'instructions' => $config['instructions'],
            'model' => $config['model'],
            'tools' => $config['tools'],
            'settings' => $config['settings'] === [] ? null : $config['settings'],
            'config_hash' => $config['config_hash'],
            'created_by_user_id' => $actor->id,
        ]);

        $agent->forceFill(['current_version_id' => $version->id, 'version_count' => $number])->save();

        return $version;
    }

    private function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
