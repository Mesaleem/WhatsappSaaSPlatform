<?php

namespace App\Services\WhatsApp;

/**
 * Phase 7 Task 5 — the configuration contract of the Journey ACTION nodes
 * the engine executes (message, question, save_lead). Pure: no I/O.
 *
 * A node whose configuration breaks this contract is never executed on a
 * guess: the engine fails the session at that node (status 'failed',
 * current_node_id = the node, last_error = this message) and nothing
 * downstream runs. `delay` keeps its own check (WhatsAppJourneyEngine::
 * delaySeconds()) and the two branching nodes theirs
 * (JourneyConditionEvaluator); `trigger` carries no configuration.
 *
 *   message    text: non-empty string (after trim)
 *   question   prompt_text: non-empty string
 *              variable_name: non-empty string
 *              input_type: absent (= "text") | "text" | "buttons" | "list"
 *              options (buttons/list only): non-empty list of objects,
 *                each with a non-empty string id or title
 *              button_text (list only): absent or string
 *              validation: absent | {type: absent|"none"|"number"|"email"|"phone",
 *                error_message?: string}
 *   save_lead  name_variable / email_variable / phone_variable: absent,
 *                null or a string ('' = not mapped, as the builder's
 *                "none" choice)
 *              completion_message: absent, null or string
 *
 * Phase 7 Task 6 — the palette's plain send nodes (PALETTE_SEND_TYPES):
 *
 *   text       text: non-empty string; `{{ variable }}` placeholders are
 *                filled from the session's collected answers at run time
 *                (renderText(): a missing/non-scalar variable becomes '',
 *                and a message that renders empty fails the node)
 *   image, video, document, audio
 *              mediaUrl: an absolute http(s) URL (the frontend's isHttpUrl)
 *              caption (image/video/document): absent, null or string
 *              filename (document): absent, null or string
 *
 * Phase 8 Task 7 — the AI nodes (AI_TYPES; executed through
 * JourneyAiNodeRunner → MeteredAiService, never a vendor directly):
 *
 *   prompt     prompt: non-empty string; `{{ variable }}` placeholders are
 *                filled from the session's variables (renderText) — the
 *                ONLY session context the AI ever receives
 *              outputVariable: a variable name (VARIABLE_NAME_PATTERN) the
 *                reply is stored in
 *              model: absent, null or string (a hint; honoured only when
 *                config('ai.journey.allowed_models') lists it)
 *   agent      instructions: non-empty string (the agent's system
 *                instructions; `{{ variable }}` placeholders filled)
 *              inputVariable: absent, null, '' or a variable name — the one
 *                session variable passed as the agent's input
 *              outputVariable: a variable name, as for prompt
 *              agentId: absent, null or string (a display label; selects nothing)
 *              registeredAgentId (Phase 8 Task 11): absent, null, '' or a
 *                positive integer id of a REGISTERED agent of the journey's
 *                OWN account (checked on save by WhatsAppFlowController and
 *                again at run time). When set, the agent's own versioned
 *                instructions/tools drive the execution and the node's
 *                `instructions` become an optional task text; when absent
 *                the node is the Task 7 single bounded call and
 *                `instructions` stay required.
 *   rag        knowledgeBaseId: a positive integer id (string or number) of a
 *                knowledge base of the journey's OWN account (checked on
 *                save by WhatsAppFlowController and again at run time)
 *              queryVariable: a variable name — the session variable whose
 *                value is the search query
 *              topK: absent/null/'' (= RAG_DEFAULT_TOP_K) or an integer
 *                1..config('ai.knowledge.max_results')
 *              outputVariable: a variable name, as for prompt
 *
 * Save time ($draft) keeps the builder's draft rule: an empty text/mediaUrl
 * is savable, but a non-empty mediaUrl that is not http(s) (javascript:,
 * file:, a bare path) is refused — it could never be sent.
 *
 * Mapping to a variable the journey never collected is NOT malformed — the
 * field is simply empty, as before.
 */
final class JourneyActionConfig
{
    public const QUESTION_INPUT_TYPES = ['text', 'buttons', 'list'];

    public const QUESTION_VALIDATION_TYPES = ['none', 'number', 'email', 'phone'];

    public const SAVE_LEAD_VARIABLES = ['name_variable', 'email_variable', 'phone_variable'];

    /** Phase 7 Task 6 — palette media nodes (the four types WhatsAppMediaPayloadBuilder sends). */
    public const MEDIA_TYPES = ['image', 'video', 'document', 'audio'];

    /** Phase 7 Task 6 — palette nodes that are one plain outbound message. */
    public const PALETTE_SEND_TYPES = ['text', ...self::MEDIA_TYPES];

    /** Phase 8 Task 7 (prompt, agent) / Task 10 (rag) — the AI nodes the engine executes. */
    public const AI_TYPES = ['prompt', 'agent', 'rag'];

    /** Phase 8 Task 10 — default passages retrieved by a rag node when topK is not set. */
    public const RAG_DEFAULT_TOP_K = 3;

    /**
     * Phase 8 Task 7 — a variable an AI node writes/reads: exactly the names
     * renderText() can substitute, so an AI reply is always usable as
     * `{{ name }}` downstream. Capped at 64 characters.
     */
    public const VARIABLE_NAME_PATTERN = '/\A[A-Za-z0-9_.\-]{1,64}\z/';

    /**
     * Phase 8 Task 16 — the classifier node's fixed set of outgoing
     * branch slots. Fixed (not one-per-instance) because the canvas's
     * connection handles are declared statically per node TYPE in the
     * frontend registry (JourneyNodeConfigForm reads
     * getJourneyNode(type).sourceHandles, not anything per-instance) —
     * the same reason 'conditional' has exactly two handles (true/false)
     * rather than a variable number. A node need not use every slot; an
     * unused one is simply never wired to anything (or auto-wired to
     * End by the builder's own ensureEndConnections(), same as an unused
     * conditional branch already is).
     */
    public const CLASSIFIER_BRANCH_HANDLES = ['branch_1', 'branch_2', 'branch_3', 'branch_4', 'branch_5'];

    /**
     * Task 23 — Connexxa IF/ELSE-IF/ELSE parity. Same fixed-slot reasoning
     * as CLASSIFIER_BRANCH_HANDLES above (the canvas declares a node
     * TYPE's handles statically): a 'conditional' node whose data holds a
     * non-empty `groups` list is in multi-branch mode — group i's own
     * outgoing handle is this array's element i, tested top-down, first
     * match wins, no match falls to CONDITIONAL_ELSE_HANDLE. A node with
     * no `groups` (every journey saved before this feature) is untouched:
     * it keeps the original 2-handle 'true'/'false' shape entirely, both
     * in storage and on the canvas — see JourneyConditionEvaluator::
     * evaluateGroups() and WhatsAppFlowController::CONDITIONAL_HANDLES.
     */
    public const CONDITIONAL_GROUP_HANDLES = ['group_1', 'group_2', 'group_3', 'group_4', 'group_5'];

    public const CONDITIONAL_ELSE_HANDLE = 'else';

    /**
     * Task 24 — Connexxa parity. Each 'list' node row gets its OWN
     * outgoing handle (as distinct from the legacy 'question' node's
     * input_type:'list', which still routes every answer through one
     * NEXT edge) — same fixed-slot reasoning as CLASSIFIER_BRANCH_HANDLES/
     * CONDITIONAL_GROUP_HANDLES above. Capped at 10: WhatsApp's own Cloud
     * API hard limit on total rows across all of a list message's
     * sections (see the frontend registry's 'list' node validate()).
     * Position i (0-based, flattened across sections in document order)
     * maps to element i here.
     */
    public const LIST_ROW_HANDLES = [
        'row_1', 'row_2', 'row_3', 'row_4', 'row_5',
        'row_6', 'row_7', 'row_8', 'row_9', 'row_10',
    ];

    /**
     * Task 24 — same per-option handle as LIST_ROW_HANDLES, for a
     * 'reply_button' node. Capped at 3: WhatsApp's own quick-reply
     * button limit (mirrors WhatsAppFlowController::MAX_REPLY_BUTTONS
     * and this file's own MAX_INTERACTIVE_BUTTONS-equivalent cap on the
     * legacy question node).
     */
    public const REPLY_BUTTON_HANDLES = ['button_1', 'button_2', 'button_3'];

    /** Task 25 — a sub-journey node's jump mode: see journeyError()'s docblock. */
    public const SUB_JOURNEY_MODES = ['static', 'dynamic'];

    /** Node types whose configuration this class governs. */
    public const ACTION_TYPES = ['message', 'question', 'save_lead', 'template', 'catalog', 'product', 'flow', 'classifier', 'list', 'reply_button', 'journey', 'code', ...self::PALETTE_SEND_TYPES, ...self::AI_TYPES];

    /**
     * The problem with an action node's configuration, or null when it may run.
     *
     * $draft (save time): a half-built node — empty text/prompt/variable, no
     * options yet — is still savable; only values that could never be valid
     * (wrong types, unknown input/validation types) are refused. The engine
     * always calls this with $draft = false.
     */
    public static function error(string $type, mixed $data, bool $draft = false): ?string
    {
        if (! in_array($type, self::ACTION_TYPES, true)) {
            return null;
        }

        if (! is_array($data)) {
            return 'Its configuration must be an object.';
        }

        return match ($type) {
            'message' => self::present($data['text'] ?? null, $draft) ? null : 'A Message node needs non-empty text.',
            'question' => self::questionError($data, $draft),
            'save_lead' => self::saveLeadError($data),
            'text' => self::present($data['text'] ?? null, $draft) ? null : 'A Text node needs non-empty text.',
            'prompt', 'agent', 'rag' => self::aiError($type, $data, $draft),
            'classifier' => self::classifierError($data, $draft),
            'list' => self::listError($data, $draft),
            'reply_button' => self::replyButtonError($data, $draft),
            'template' => self::templateError($data, $draft),
            'catalog' => self::catalogError($data, $draft),
            'product' => self::productError($data, $draft),
            'flow' => self::flowError($data, $draft),
            'journey' => self::journeyError($data, $draft),
            'code' => self::codeError($data, $draft),
            default => self::mediaError($type, $data, $draft),
        };
    }

    /**
     * Task 22 — Connexxa variable-system parity. Fill `{{ variable }}`
     * placeholders from the session's collected answers (as before) PLUS
     * two explicit namespaces, archaeologically confirmed from 3 real
     * production Connexxa journeys (HDFC, Labdhi Insurance, HRMS):
     *
     *   {{var_local.<path>}}   this journey's own declared variables —
     *                          $context, the same flat map every caller
     *                          already passed. <path> may be a dotted
     *                          path into a nested value, e.g.
     *                          `var_local.buyPolicyJson.pan` — Connexxa's
     *                          own confirmed nested-interpolation syntax.
     *                          wa-saas has no node that writes a nested
     *                          object into a variable yet (no `api`/`flow`/
     *                          `code` execution — Tasks #24-26), so this
     *                          is currently reachable only via a flat
     *                          var that happens to hold an array value;
     *                          it is built now so those later tasks need
     *                          no second change here.
     *   {{var_system.<path>}}  always-available platform built-ins, never
     *                          declared by the journey author — $varSystem,
     *                          built by systemVariables() below.
     *                          `userChatId` is the one key directly
     *                          evidenced in real Connexxa Code-node source
     *                          (`String(var_system.userChatId)`);
     *                          accountId/flowId/sessionId/now are this
     *                          platform's own equivalents of the same
     *                          "platform data, not a declared variable"
     *                          idea — [Inference], not a Connexxa-observed
     *                          key, but the natural wa-saas analogues of
     *                          what var_system IS (session/account facts
     *                          already in the engine's hand at render time).
     *
     * Bare, un-prefixed placeholders — `{{name}}`, including one whose
     * name itself contains a literal dot — are UNCHANGED: resolved as a
     * single flat key against $context exactly as before Task 22. No
     * journey saved before this feature used `var_local.`/`var_system.`
     * literally (it would have rendered to '', since there was no such
     * key), so this is purely additive.
     *
     * Still plain substitution, never evaluation. A missing/null/non-
     * array-traversable value becomes ''; booleans are the words
     * true/false (as JourneyConditionEvaluator compares them).
     *
     * @param  array<string, mixed>  $context  this journey's own variables (var_local)
     * @param  array<string, mixed>  $varSystem  platform built-ins (var_system); omitted by a caller that has none to offer — those placeholders then render ''exactly as they always have.
     */
    public static function renderText(string $text, array $context, array $varSystem = []): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/', static function (array $m) use ($context, $varSystem): string {
            return self::stringifyVariable(self::resolveVariable($m[1], $context, $varSystem));
        }, $text);
    }

    /**
     * Task 22 — one `{{ }}` placeholder's path to its value. See
     * renderText()'s docblock for the exact namespace/backward-
     * compatibility contract this implements.
     */
    private static function resolveVariable(string $path, array $context, array $varSystem): mixed
    {
        $segments = explode('.', $path);

        if ($segments[0] === 'var_local' && count($segments) > 1) {
            return self::digPath($context, array_slice($segments, 1));
        }

        if ($segments[0] === 'var_system' && count($segments) > 1) {
            return self::digPath($varSystem, array_slice($segments, 1));
        }

        // Backward-compatible path: the WHOLE dotted string is one flat
        // key, exactly as every journey saved before Task 22 relies on.
        return $context[$path] ?? null;
    }

    /** Task 22 — walks a dotted path into a nested array; a missing step at any depth is '' (never a warning/error). */
    private static function digPath(array $root, array $segments): mixed
    {
        $value = $root;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private static function stringifyVariable(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * Task 22 — the var_system.* map renderText() resolves against. Pure:
     * every argument is a primitive the caller already has in hand (the
     * executing session's own identity), no I/O of its own except `now()`
     * (Laravel's testable clock, not real I/O).
     *
     * @return array<string, mixed>
     */
    public static function systemVariables(?string $userChatId, ?int $accountId, ?int $flowId, ?int $sessionId): array
    {
        return [
            'userChatId' => $userChatId,
            'accountId' => $accountId,
            'flowId' => $flowId,
            'sessionId' => $sessionId,
            'now' => now()->toIso8601String(),
        ];
    }

    /**
     * Phase 8 Task 16 — the classifier node: an LLM picks ONE of the
     * node's own declared branches for the input text, as distinct from
     * 'conditional', which evaluates a rule against a variable's exact
     * value. `branches` is capped at CLASSIFIER_BRANCH_HANDLES's length
     * (5) — position i corresponds to branch handle `branch_{i+1}`, the
     * SAME fixed handle WhatsAppFlowController's classifierBranchErrors()
     * requires an outgoing edge to declare.
     *
     * @param  array<string, mixed>  $data
     */
    private static function classifierError(array $data, bool $draft): ?string
    {
        foreach (['inputVariable' => 'input variable', 'outputVariable' => 'output variable'] as $key => $label) {
            $value = $data[$key] ?? null;

            if (! self::present($value, $draft)) {
                return "A Classifier node needs an {$label}.";
            }

            if (is_string($value) && trim($value) !== '' && ! preg_match(self::VARIABLE_NAME_PATTERN, trim($value))) {
                return "A Classifier node's {$label} may contain only letters, digits, '_', '.' or '-' (at most 64).";
            }
        }

        $branches = $data['branches'] ?? [];

        if ($draft && $branches === null) {
            $branches = [];
        }

        if (! is_array($branches) || ! array_is_list($branches)) {
            return 'A Classifier node\'s branches must be a list.';
        }

        if (count($branches) > count(self::CLASSIFIER_BRANCH_HANDLES)) {
            return 'A Classifier node may have at most '.count(self::CLASSIFIER_BRANCH_HANDLES).' branches.';
        }

        if (! $draft && count($branches) < 1) {
            return 'A Classifier node needs at least one branch.';
        }

        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                return 'Every classifier branch must be an object.';
            }

            // Deliberately NOT self::present($value, $draft): a branch the
            // user actually added to the list is a concrete object, not an
            // unconfigured top-level field — an empty-label row is always
            // malformed, draft or not. Mirrors JourneyApiConnectionController
            // requiring 'headers.*.key' unconditionally even though the
            // connection's own top-level fields are nullable.
            if (! self::nonEmptyString($branch['label'] ?? null)) {
                return 'Every classifier branch needs a label.';
            }

            if (array_key_exists('intent', $branch) && $branch['intent'] !== null && ! is_string($branch['intent'])) {
                return "A classifier branch's intent description must be text.";
            }
        }

        return null;
    }

    /**
     * Task 24 — Connexxa parity. The 'list' palette node: a Cloud API
     * interactive list message with one outgoing handle PER ROW
     * (LIST_ROW_HANDLES), as distinct from the legacy 'question' node's
     * input_type:'list' (one NEXT edge for every answer). `sections` is
     * a list of {title, rows: [{id|title, description?}, ...]}; the
     * total row count across every section is capped at
     * LIST_ROW_HANDLES's length — the same WhatsApp limit the frontend
     * registry's validate() already enforces, re-checked here so a
     * hand-crafted API payload cannot exceed it either.
     *
     * @param array<string, mixed> $data
     */
    private static function listError(array $data, bool $draft): ?string
    {
        if (! self::present($data['body'] ?? null, $draft)) {
            return 'A List node needs non-empty body text.';
        }

        if (array_key_exists('buttonText', $data) && $data['buttonText'] !== null && ! is_string($data['buttonText'])) {
            return 'The list button text must be text.';
        }

        $sections = $data['sections'] ?? null;

        if ($draft && $sections === null) {
            $sections = [];
        }

        if (! is_array($sections) || ! array_is_list($sections)) {
            return 'A List node\'s sections must be a list.';
        }

        $totalRows = 0;

        foreach ($sections as $section) {
            if (! is_array($section)) {
                return 'Every list section must be an object.';
            }

            $rows = $section['rows'] ?? [];

            if (! is_array($rows) || ! array_is_list($rows)) {
                return 'Every list section\'s rows must be a list.';
            }

            foreach ($rows as $row) {
                if (! is_array($row) || (! self::nonEmptyString($row['id'] ?? null) && ! self::nonEmptyString($row['title'] ?? null))) {
                    return 'Every list row needs an id or a title.';
                }
            }

            $totalRows += count($rows);
        }

        if (! $draft && $totalRows < 1) {
            return 'A List node needs at least one row.';
        }

        if ($totalRows > count(self::LIST_ROW_HANDLES)) {
            return 'A List node may have at most '.count(self::LIST_ROW_HANDLES).' rows total across all sections.';
        }

        return null;
    }

    /**
     * Task 24 — Connexxa parity. The 'reply_button' palette node: a
     * Cloud API quick-reply message with one outgoing handle PER
     * BUTTON (REPLY_BUTTON_HANDLES). `buttons` is a list of
     * {id|title}, capped at REPLY_BUTTON_HANDLES's length (WhatsApp's
     * own 3-button limit — WhatsAppFlowController's nodeConfigErrors()
     * already enforces this count at save time; re-checked here so the
     * engine never runs on a guess, same contract as every other
     * action node).
     *
     * @param array<string, mixed> $data
     */
    private static function replyButtonError(array $data, bool $draft): ?string
    {
        if (! self::present($data['body'] ?? null, $draft)) {
            return 'A Reply Buttons node needs non-empty body text.';
        }

        $buttons = $data['buttons'] ?? null;

        if ($draft && $buttons === null) {
            $buttons = [];
        }

        if (! is_array($buttons) || ! array_is_list($buttons)) {
            return 'A Reply Buttons node\'s buttons must be a list.';
        }

        if (! $draft && count($buttons) < 1) {
            return 'A Reply Buttons node needs at least one button.';
        }

        if (count($buttons) > count(self::REPLY_BUTTON_HANDLES)) {
            return 'A Reply Buttons node may have at most '.count(self::REPLY_BUTTON_HANDLES).' buttons.';
        }

        foreach ($buttons as $button) {
            if (! is_array($button) || (! self::nonEmptyString($button['id'] ?? null) && ! self::nonEmptyString($button['title'] ?? null))) {
                return 'Every reply button needs an id or a title.';
            }
        }

        return null;
    }

    /** Phase 8 Task 10 — @param array<string, mixed> $data */
    private static function ragError(array $data, bool $draft): ?string
    {
        $kb = $data['knowledgeBaseId'] ?? null;

        if (($kb === null || $kb === '') ? ! $draft : ! self::positiveId($kb)) {
            return 'A Knowledge Base node needs a knowledge base.';
        }

        foreach (['queryVariable' => 'query variable', 'outputVariable' => 'output variable'] as $key => $label) {
            $value = $data[$key] ?? null;

            if (! self::present($value, $draft)) {
                return "A Knowledge Base node needs a {$label}.";
            }

            if (is_string($value) && trim($value) !== '' && ! preg_match(self::VARIABLE_NAME_PATTERN, trim($value))) {
                return "A Knowledge Base node's {$label} may contain only letters, digits, '_', '.' or '-' (at most 64).";
            }
        }

        $topK = $data['topK'] ?? null;
        $max = self::ragMaxTopK();

        if ($topK !== null && $topK !== '' && (! is_numeric($topK) || (int) $topK != $topK || (int) $topK < 1 || (int) $topK > $max)) {
            return "A Knowledge Base node's top K must be a whole number between 1 and {$max}.";
        }

        return null;
    }

    /** Phase 8 Task 10 — the largest topK a rag node may ask for (the retriever's own limit). */
    public static function ragMaxTopK(): int
    {
        return max(1, (int) config('ai.knowledge.max_results', 20));
    }

    /**
     * Task 26 — the 'code' node. NOT real JavaScript — see
     * JourneyCodeSandbox's own docblock for the explicit scope decision
     * and the small closed language it runs instead. `code` (the field
     * name the frontend already used, pre-Task-26, for this node's
     * script text — kept rather than renamed, so an already-saved draft
     * keeps meaning exactly what it saved): non-empty string, and if
     * non-empty it must at least PARSE (JourneyCodeSandbox::validate())
     * even at draft time — the same "non-empty but malformed is refused
     * even as a draft" rule as the media nodes' mediaUrl. A pre-Task-26
     * draft's `code` almost certainly will NOT parse (it was never
     * constrained to this language — see RUNTIME_EXECUTABLE_TYPES'
     * docblock: 'code' was draft-only until this task), so such a draft
     * is re-savable only once its script is rewritten in this language;
     * it was never publishable before and still is not until then.
     * `outputVariable`: a variable name, same contract as every other
     * value-producing node (prompt/agent/rag).
     *
     * @param array<string, mixed> $data
     */
    private static function codeError(array $data, bool $draft): ?string
    {
        $script = $data['code'] ?? null;

        if (! self::present($script, $draft)) {
            return 'A Code node needs code.';
        }

        if (is_string($script) && trim($script) !== '') {
            $problem = JourneyCodeSandbox::validate($script);

            if ($problem !== null) {
                return "A Code node's script could not be parsed: {$problem}";
            }
        }

        $outputVariable = $data['outputVariable'] ?? null;

        if (! self::present($outputVariable, $draft)) {
            return 'A Code node needs an output variable.';
        }

        if (is_string($outputVariable) && trim($outputVariable) !== '' && ! preg_match(self::VARIABLE_NAME_PATTERN, trim($outputVariable))) {
            return "A Code node's output variable may contain only letters, digits, '_', '.' or '-' (at most 64).";
        }

        return null;
    }

    /** Phase 8 Task 10 — a positive integer id given as an int or a digit string. */
    public static function positiveId(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', trim($value)) === 1);
    }

    /** Phase 8 Task 7 — @param array<string, mixed> $data */
    private static function aiError(string $type, array $data, bool $draft): ?string
    {
        if ($type === 'rag') {
            return self::ragError($data, $draft);
        }

        $label = $type === 'prompt' ? 'A Prompt node' : 'An AI Agent node';
        $textKey = $type === 'prompt' ? 'prompt' : 'instructions';

        // Phase 8 Task 11 — an agent node may reference a registered agent.
        $registered = $data['registeredAgentId'] ?? null;
        $isRegistered = $type === 'agent' && $registered !== null && $registered !== '';

        if ($isRegistered && ! self::positiveId($registered)) {
            return "{$label}'s registered agent must be one of your AI agents.";
        }

        if ($isRegistered) {
            if (array_key_exists($textKey, $data) && $data[$textKey] !== null && ! is_string($data[$textKey])) {
                return "{$label}'s {$textKey} must be text.";
            }
        } elseif (! self::present($data[$textKey] ?? null, $draft)) {
            return "{$label} needs non-empty {$textKey}.";
        }

        $output = $data['outputVariable'] ?? null;

        if (! self::present($output, $draft)) {
            return "{$label} needs an output variable to store the AI reply in.";
        }

        if (is_string($output) && trim($output) !== '' && ! preg_match(self::VARIABLE_NAME_PATTERN, trim($output))) {
            return "{$label}'s output variable may contain only letters, digits, '_', '.' or '-' (at most 64).";
        }

        $input = $data['inputVariable'] ?? null;

        if ($type === 'agent' && $input !== null && $input !== '' && (! is_string($input) || ! preg_match(self::VARIABLE_NAME_PATTERN, trim($input)))) {
            return "{$label}'s input variable may contain only letters, digits, '_', '.' or '-' (at most 64).";
        }

        foreach (['model', 'agentId'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && ! is_string($data[$key])) {
                return "{$label}'s {$key} must be text.";
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    /**
     * Phase 3 — Meta Template node. templateId is resolved against
     * message_templates at run time by TemplateMessageDispatcher (which
     * also enforces approval/provider/entitlement) — this check only
     * catches configuration that could never be valid.
     *
     * @param array<string, mixed> $data
     */
    private static function templateError(array $data, bool $draft): ?string
    {
        $templateId = $data['templateId'] ?? null;

        if (! self::present($templateId, $draft)) {
            return 'A Template node needs a template selected.';
        }

        if (! $draft && ! (is_int($templateId) || (is_string($templateId) && ctype_digit($templateId)))) {
            return 'A Template node\'s template must be a valid template id.';
        }

        if (array_key_exists('variables', $data) && $data['variables'] !== null && ! is_array($data['variables'])) {
            return 'A Template node\'s variables must be an object.';
        }

        return null;
    }

    /**
     * Phase 4 — Meta Catalog node. catalogId is required; productIds, when
     * present, must be a list (each entry is cast to a string product
     * retailer id by the engine — no further format is enforced here,
     * since Meta's own catalog defines what a valid id looks like).
     *
     * @param array<string, mixed> $data
     */
    private static function catalogError(array $data, bool $draft): ?string
    {
        if (! self::present($data['catalogId'] ?? null, $draft)) {
            return 'A Catalog node needs a Catalog ID.';
        }

        if (array_key_exists('productIds', $data) && $data['productIds'] !== null && ! is_array($data['productIds'])) {
            return 'A Catalog node\'s Product IDs must be a list.';
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private static function productError(array $data, bool $draft): ?string
    {
        if (! self::present($data['productId'] ?? null, $draft)) {
            return 'A Product node needs a Product ID.';
        }

        if (! self::present($data['catalogId'] ?? null, $draft)) {
            return 'A Product node needs a Catalog ID.';
        }

        return null;
    }

    /**
     * Phase 5 — Meta Flow node. flowId, flowCta and screenName are all
     * required: Meta's Cloud API rejects an interactive 'flow' message
     * without a flow_cta (button label), and flow_action=navigate (the
     * only mode this app can use — it stores no Flow data-exchange
     * endpoint) requires flow_action_payload.screen.
     *
     * @param array<string, mixed> $data
     */
    private static function flowError(array $data, bool $draft): ?string
    {
        if (! self::present($data['flowId'] ?? null, $draft)) {
            return 'A WhatsApp Flow node needs a Meta Flow ID.';
        }

        if (! self::present($data['flowCta'] ?? null, $draft)) {
            return 'A WhatsApp Flow node needs a button label (CTA).';
        }

        if (! self::present($data['screenName'] ?? null, $draft)) {
            return 'A WhatsApp Flow node needs a starting screen name.';
        }

        return null;
    }

    /**
     * Task 25 — Connexxa parity. The 'journey' (sub-journey) node's two
     * jump modes, mirroring ConnexxaIQ's Static/Dynamic journey-jump
     * config:
     *
     *   static (absent `mode`, or mode:'static' — the pre-Task-25 shape
     *     is exactly this mode, so every journey saved before this
     *     feature keeps validating identically):
     *     journeyId     a positive integer id (string or number) of a
     *                   journey of the SAME account (checked again by
     *                   WhatsAppFlowController::assertSubJourneysOwned(),
     *                   same no-existence-leak posture as
     *                   knowledgeBaseId/registeredAgentId/
     *                   apiConnectionId)
     *     startNodeId   absent, null or a string — an explicit node id
     *                   to jump straight into; empty means the target
     *                   journey's own default entry. Not validated
     *                   against the target journey's actual nodes here
     *                   (only the controller sees the target's graph,
     *                   and the target can change after this node is
     *                   saved) — a dangling value is simply unusable
     *                   once this node type gains a runtime.
     *
     *   dynamic (mode:'dynamic' — a runtime-variable-bound jump; no
     *     target can be checked at save time, exactly like a `{{ }}`
     *     recipient on the Email node's `to` field is never pattern-
     *     checked either):
     *     journeyIdTemplate  non-empty string (may contain `{{ }}`
     *                        placeholders — Task 22's renderText())
     *     nodeIdTemplate     non-empty string, same placeholder rule
     *
     * This node is NOT runtime-executable yet (JourneyNodeCatalog::
     * RUNTIME_EXECUTABLE_TYPES) — this is a save-time config-shape
     * guard only, the same posture as 'api' (JourneyActionConfig has no
     * apiError(); that node's structural checks live entirely in
     * WhatsAppFlowController::nodeConfigErrors()'s own 'api' branch).
     * Actually jumping between journeys — session hand-off, version
     * pinning, loop protection across journey boundaries — is Task 25's
     * explicitly out-of-scope follow-on, not implemented here.
     *
     * @param array<string, mixed> $data
     */
    private static function journeyError(array $data, bool $draft): ?string
    {
        $mode = $data['mode'] ?? 'static';

        if (! in_array($mode, self::SUB_JOURNEY_MODES, true)) {
            return 'Sub-journey mode must be one of: '.implode(', ', self::SUB_JOURNEY_MODES).'.';
        }

        if ($mode === 'dynamic') {
            if (! self::present($data['journeyIdTemplate'] ?? null, $draft)) {
                return 'A Dynamic sub-journey node needs a journey ID expression.';
            }

            if (! self::present($data['nodeIdTemplate'] ?? null, $draft)) {
                return 'A Dynamic sub-journey node needs a node ID expression.';
            }

            return null;
        }

        $journeyId = $data['journeyId'] ?? null;

        if (($journeyId === null || $journeyId === '') ? ! $draft : ! self::positiveId($journeyId)) {
            return 'A Static sub-journey node needs a journey to run.';
        }

        if (array_key_exists('startNodeId', $data) && $data['startNodeId'] !== null && ! is_string($data['startNodeId'])) {
            return 'The sub-journey start node id must be text.';
        }

        return null;
    }

    private static function mediaError(string $type, array $data, bool $draft): ?string
    {
        $url = $data['mediaUrl'] ?? null;
        $label = ucfirst($type);

        if (! self::present($url, $draft)) {
            return "The {$label} node needs a media URL.";
        }

        if (is_string($url) && trim($url) !== '' && ! self::httpUrl(trim($url))) {
            return "The {$label} media URL must be an absolute http(s) link.";
        }

        foreach (['caption', 'filename'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && ! is_string($data[$key])) {
                return "The {$label} {$key} must be text.";
            }
        }

        return null;
    }

    private static function httpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /** @param array<string, mixed> $data */
    private static function questionError(array $data, bool $draft): ?string
    {
        if (! self::present($data['prompt_text'] ?? null, $draft)) {
            return 'A Question node needs non-empty prompt text.';
        }

        if (! self::present($data['variable_name'] ?? null, $draft)) {
            return 'A Question node needs a variable name to store the answer in.';
        }

        $inputType = $data['input_type'] ?? 'text';

        if (! in_array($inputType, self::QUESTION_INPUT_TYPES, true)) {
            return 'Question input type must be one of: '.implode(', ', self::QUESTION_INPUT_TYPES).'.';
        }

        if ($inputType !== 'text') {
            $options = $data['options'] ?? null;

            if ($draft && ($options === null || $options === [])) {
                $options = [];
            } elseif (! is_array($options) || $options === [] || ! array_is_list($options)) {
                return "A '{$inputType}' question needs at least one option.";
            }

            foreach ($options as $option) {
                if (! is_array($option) || (! self::nonEmptyString($option['id'] ?? null) && ! self::nonEmptyString($option['title'] ?? null))) {
                    return 'Every question option needs an id or a title.';
                }
            }

            if (array_key_exists('button_text', $data) && $data['button_text'] !== null && ! is_string($data['button_text'])) {
                return 'The list button text must be text.';
            }
        }

        $validation = $data['validation'] ?? null;

        if ($validation !== null) {
            if (! is_array($validation)) {
                return 'Question validation must be an object.';
            }

            if (! in_array($validation['type'] ?? 'none', self::QUESTION_VALIDATION_TYPES, true)) {
                return 'Question validation type must be one of: '.implode(', ', self::QUESTION_VALIDATION_TYPES).'.';
            }

            if (array_key_exists('error_message', $validation) && $validation['error_message'] !== null && ! is_string($validation['error_message'])) {
                return 'The validation error message must be text.';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private static function saveLeadError(array $data): ?string
    {
        foreach (self::SAVE_LEAD_VARIABLES as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && ! is_string($data[$key])) {
                return "Save Lead '{$key}' must name a variable.";
            }
        }

        if (array_key_exists('completion_message', $data) && $data['completion_message'] !== null && ! is_string($data['completion_message'])) {
            return 'The completion message must be text.';
        }

        return null;
    }

    /** Run time: a non-empty string. Draft: absent/null or any string. */
    private static function present(mixed $value, bool $draft): bool
    {
        return $draft ? ($value === null || is_string($value)) : self::nonEmptyString($value);
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
