<?php

namespace App\Services\WhatsApp;

/**
 * Phase 7 Task 4 — THE Journey condition contract. Pure: no database, no
 * I/O, no clock, no randomness, no eval and no expression language. The
 * same (operator, actual, expected) always gives the same answer.
 *
 * CONTEXT. A condition reads one named variable from the session's own
 * context_data (the answers its questions collected, stored as trimmed
 * strings). Nothing else is reachable: no contact/CRM row, no other
 * session, no other tenant. Missing variable = PHP null.
 *
 * OPERATORS ($operator → true when …). "text" means the normalised string
 * form below; "number" means a finite numeric value.
 *
 *   equals            text(actual) == text(expected)                   (1)
 *   not_equals        NOT equals                                       (1)
 *   contains          text(expected) is a substring of text(actual)
 *   not_contains      NOT contains
 *   starts_with       text(actual) begins with text(expected)
 *   ends_with         text(actual) ends with text(expected)
 *   greater_than      number(actual) >  number(expected)
 *   less_than         number(actual) <  number(expected)
 *   greater_or_equal  number(actual) >= number(expected)
 *   less_or_equal     number(actual) <= number(expected)
 *   exists            actual is present: not null and not ''           (2)
 *   not_exists        NOT exists
 *
 *   (1) An equals/not_equals whose `value` is absent (null) tests for a
 *       missing variable: equals ⇔ actual is null. This is the legacy
 *       engine's behaviour (null === null) and is kept for saved flows.
 *   (2) Untrimmed, exactly as the legacy engine: '' is "not set".
 *
 * NORMALISATION.
 *   text(v):  strings are trimmed and lower-cased (mb_*), so every text
 *             comparison is case-insensitive and ignores surrounding
 *             whitespace — the legacy behaviour. Integers/floats become
 *             their PHP string form, booleans 'true'/'false'. Arrays and
 *             objects have no text form.
 *   number(v): int/float as-is; a string that is_numeric() after trim
 *             ("42", "4.5", "-3", "1e3"); anything else (booleans, '',
 *             'abc', arrays) is NOT a number.
 *   There is no implicit numeric equality: "10" equals "10.0" is FALSE
 *   (text); use greater_or_equal + less_or_equal for numeric equality.
 *
 * MISSING / NON-COMPARABLE ACTUAL VALUE. A positive test (equals,
 * contains, starts_with, ends_with, the four numeric ones, exists) is
 * FALSE; its negation (not_equals, not_contains, not_exists) is TRUE.
 * So a missing answer never satisfies "> 10", and a text "abc" never
 * satisfies "< 10".
 *
 * MALFORMED DEFINITIONS throw InvalidJourneyCondition — they are never
 * guessed at: an unknown operator, a non-numeric `value` for a numeric
 * operator, a missing `value` for a text operator other than
 * equals/not_equals, a non-scalar `value`.
 */
class JourneyConditionEvaluator
{
    public const OPERATORS = [
        'equals', 'not_equals',
        'contains', 'not_contains',
        'starts_with', 'ends_with',
        'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal',
        'exists', 'not_exists',
    ];

    /** Operators that take no `value`. */
    public const UNARY_OPERATORS = ['exists', 'not_exists'];

    public const NUMERIC_OPERATORS = ['greater_than', 'less_than', 'greater_or_equal', 'less_or_equal'];

    /** How several conditions on one `conditional` node combine. */
    public const MATCH_MODES = ['all', 'any'];

    /**
     * @throws InvalidJourneyCondition
     */
    public function evaluate(string $operator, mixed $actual, mixed $expected = null): bool
    {
        $this->assertValid($operator, $expected);

        return match ($operator) {
            'equals' => $this->equals($actual, $expected),
            'not_equals' => ! $this->equals($actual, $expected),
            'contains' => $this->textTest($actual, $expected, fn (string $a, string $e) => str_contains($a, $e)),
            'not_contains' => ! $this->textTest($actual, $expected, fn (string $a, string $e) => str_contains($a, $e)),
            'starts_with' => $this->textTest($actual, $expected, fn (string $a, string $e) => str_starts_with($a, $e)),
            'ends_with' => $this->textTest($actual, $expected, fn (string $a, string $e) => str_ends_with($a, $e)),
            'greater_than' => $this->numericTest($actual, $expected, fn (float $a, float $e) => $a > $e),
            'less_than' => $this->numericTest($actual, $expected, fn (float $a, float $e) => $a < $e),
            'greater_or_equal' => $this->numericTest($actual, $expected, fn (float $a, float $e) => $a >= $e),
            'less_or_equal' => $this->numericTest($actual, $expected, fn (float $a, float $e) => $a <= $e),
            'exists' => $this->exists($actual),
            'not_exists' => ! $this->exists($actual),
        };
    }

    /**
     * Evaluates a `conditional` node's rule list against a context.
     * 'all' = every rule true (AND); 'any' = at least one rule true (OR).
     * Every rule is validated before any is evaluated, so a malformed rule
     * anywhere fails the node even when 'any' could short-circuit.
     *
     * @param mixed $conditions list of {variable, operator, value?}
     * @param array<string, mixed> $context
     * @throws InvalidJourneyCondition
     */
    public function evaluateAll(mixed $conditions, mixed $match, array $context): bool
    {
        $errors = self::conditionListErrors($conditions, $match, requireRules: true);

        if ($errors !== []) {
            throw new InvalidJourneyCondition($errors[0]);
        }

        $results = array_map(
            fn (array $rule) => $this->evaluate((string) $rule['operator'], $context[$rule['variable']] ?? null, $rule['value'] ?? null),
            array_values($conditions),
        );

        return ($match ?? 'all') === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * Definition problems for one operator/value pair, or null when valid.
     * Shared by save-time validation and runtime so the two never disagree.
     */
    public static function definitionError(mixed $operator, mixed $value): ?string
    {
        if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
            return 'Unknown condition operator'.(is_scalar($operator) ? " '{$operator}'" : '').'. Allowed: '.implode(', ', self::OPERATORS).'.';
        }

        if (in_array($operator, self::UNARY_OPERATORS, true)) {
            return null;
        }

        if ($value !== null && ! is_scalar($value)) {
            return "The value for '{$operator}' must be text or a number.";
        }

        if (in_array($operator, self::NUMERIC_OPERATORS, true)) {
            return self::toNumber($value) === null ? "The value for '{$operator}' must be a number." : null;
        }

        if ($value === null && ! in_array($operator, ['equals', 'not_equals'], true)) {
            return "The '{$operator}' operator needs a value.";
        }

        return null;
    }

    /**
     * Problems with a `conditional` node's {conditions, match}. With
     * $requireRules false (save time) an empty list or an empty variable is
     * allowed — a half-built draft — and is refused at run time instead.
     *
     * @return list<string>
     */
    public static function conditionListErrors(mixed $conditions, mixed $match, bool $requireRules): array
    {
        if ($match !== null && ! in_array($match, self::MATCH_MODES, true)) {
            return ["Match must be one of: ".implode(', ', self::MATCH_MODES).'.'];
        }

        if (! is_array($conditions) || ! array_is_list($conditions)) {
            return ['Conditions must be a list.'];
        }

        if ($requireRules && $conditions === []) {
            return ['A Conditional node needs at least one condition.'];
        }

        $errors = [];

        foreach ($conditions as $i => $rule) {
            $n = $i + 1;

            if (! is_array($rule)) {
                $errors[] = "Condition {$n} must be an object.";

                continue;
            }

            $variable = $rule['variable'] ?? null;

            if ($variable !== null && ! is_string($variable)) {
                $errors[] = "Condition {$n}: the variable must be a name.";
            } elseif ($requireRules && trim((string) $variable) === '') {
                $errors[] = "Condition {$n}: a variable is required.";
            }

            if ($requireRules || ! in_array($rule['operator'] ?? null, [null, ''], true)) {
                $error = self::definitionError($rule['operator'] ?? null, $rule['value'] ?? null);

                if ($error !== null) {
                    $errors[] = "Condition {$n}: {$error}";
                }
            }
        }

        return $errors;
    }

    /** @throws InvalidJourneyCondition */
    private function assertValid(string $operator, mixed $expected): void
    {
        $error = self::definitionError($operator, $expected);

        if ($error !== null) {
            throw new InvalidJourneyCondition($error);
        }
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }

        $a = self::toText($actual);

        return $a !== null && $a === self::toText($expected);
    }

    private function exists(mixed $actual): bool
    {
        return $actual !== null && $actual !== '';
    }

    /** @param callable(string, string): bool $test */
    private function textTest(mixed $actual, mixed $expected, callable $test): bool
    {
        $a = self::toText($actual);
        $e = self::toText($expected);

        return $a !== null && $e !== null && $test($a, $e);
    }

    /** @param callable(float, float): bool $test */
    private function numericTest(mixed $actual, mixed $expected, callable $test): bool
    {
        $a = self::toNumber($actual);
        $e = self::toNumber($expected);

        return $a !== null && $e !== null && $test($a, $e);
    }

    public static function toText(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => mb_strtolower(trim($value)),
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => mb_strtolower((string) $value),
            default => null,
        };
    }

    public static function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $number = (float) trim($value);

            return is_finite($number) ? $number : null;
        }

        return null;
    }
}
