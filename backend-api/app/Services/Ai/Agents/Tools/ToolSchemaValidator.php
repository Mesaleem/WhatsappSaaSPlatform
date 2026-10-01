<?php

namespace App\Services\Ai\Agents\Tools;

/**
 * Phase 8 Task 11 — validates model-supplied tool arguments against a
 * tool's inputSchema() BEFORE anything runs. A deliberately small,
 * closed JSON-Schema subset (no $ref, no nesting, no arrays):
 *
 *   root      {type: object, properties: {...}, required: [...]}
 *             additional properties are ALWAYS refused
 *   string    maxLength (required — no unbounded strings), minLength,
 *             enum, pattern (PCRE), format: email
 *   integer   minimum, maximum (a JSON number with no fraction; numeric
 *             strings are refused — no coercion)
 *   boolean
 *
 * Returns human-readable errors (safe for the model: they name the field
 * and the rule, never echo the value).
 */
final class ToolSchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function errors(array $schema, mixed $arguments): array
    {
        if ($arguments === null) {
            $arguments = [];
        }

        if (! is_array($arguments) || (array_is_list($arguments) && $arguments !== [])) {
            return ['The arguments must be a JSON object.'];
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $errors = [];

        foreach (array_keys($arguments) as $key) {
            if (! is_string($key) || ! array_key_exists($key, $properties)) {
                $errors[] = "Unknown argument '".mb_substr((string) $key, 0, 64)."'.";
            }
        }

        foreach ((array) ($schema['required'] ?? []) as $key) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null) {
                $errors[] = "Missing required argument '{$key}'.";
            }
        }

        foreach ($properties as $key => $rule) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null || ! is_array($rule)) {
                continue;
            }

            if (($error = self::valueError((string) $key, $rule, $arguments[$key])) !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $rule */
    private static function valueError(string $key, array $rule, mixed $value): ?string
    {
        switch ($rule['type'] ?? null) {
            case 'string':
                if (! is_string($value)) {
                    return "Argument '{$key}' must be a string.";
                }
                $length = mb_strlen($value);
                if ($length > (int) ($rule['maxLength'] ?? 0)) {
                    return "Argument '{$key}' is longer than ".(int) ($rule['maxLength'] ?? 0).' characters.';
                }
                if (isset($rule['minLength']) && $length < (int) $rule['minLength']) {
                    return "Argument '{$key}' is shorter than ".(int) $rule['minLength'].' characters.';
                }
                if (isset($rule['enum']) && ! in_array($value, (array) $rule['enum'], true)) {
                    return "Argument '{$key}' must be one of: ".implode(', ', (array) $rule['enum']).'.';
                }
                if (isset($rule['pattern']) && preg_match('/'.str_replace('/', '\/', (string) $rule['pattern']).'/u', $value) !== 1) {
                    return "Argument '{$key}' has an invalid format.";
                }
                if (($rule['format'] ?? null) === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return "Argument '{$key}' must be an email address.";
                }

                return null;
            case 'integer':
                if (! is_int($value)) {
                    return "Argument '{$key}' must be an integer.";
                }
                if (isset($rule['minimum']) && $value < (int) $rule['minimum']) {
                    return "Argument '{$key}' must be at least ".(int) $rule['minimum'].'.';
                }
                if (isset($rule['maximum']) && $value > (int) $rule['maximum']) {
                    return "Argument '{$key}' must be at most ".(int) $rule['maximum'].'.';
                }

                return null;
            case 'boolean':
                return is_bool($value) ? null : "Argument '{$key}' must be true or false.";
            default:
                return "Argument '{$key}' has an unsupported schema.";
        }
    }
}
