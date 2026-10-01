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

    /** Node types whose configuration this class governs. */
    public const ACTION_TYPES = ['message', 'question', 'save_lead', ...self::PALETTE_SEND_TYPES, ...self::AI_TYPES];

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
            default => self::mediaError($type, $data, $draft),
        };
    }

    /**
     * Phase 7 Task 6 — fill `{{ variable }}` placeholders from the session's
     * collected answers. Plain substitution, never evaluation: a name is
     * letters, digits, `_`, `.` or `-`; anything else is left as written.
     * A missing, null or non-scalar variable becomes ''; booleans are the
     * words true/false (as JourneyConditionEvaluator compares them).
     *
     * @param  array<string, mixed>  $context
     */
    public static function renderText(string $text, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/', static function (array $m) use ($context): string {
            $value = $context[$m[1]] ?? null;

            return match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => '',
            };
        }, $text);
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
