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

    /** Node types whose configuration this class governs. */
    public const ACTION_TYPES = ['message', 'question', 'save_lead', ...self::PALETTE_SEND_TYPES];

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
