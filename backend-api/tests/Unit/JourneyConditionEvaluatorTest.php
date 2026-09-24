<?php

namespace Tests\Unit;

use App\Services\WhatsApp\InvalidJourneyCondition;
use App\Services\WhatsApp\JourneyConditionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 Task 4 — the condition contract, evaluated in isolation (no
 * framework, no database): every operator, the normalisation rules,
 * missing values, AND/OR, malformed definitions and determinism.
 */
class JourneyConditionEvaluatorTest extends TestCase
{
    private JourneyConditionEvaluator $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e = new JourneyConditionEvaluator();
    }

    /** [operator, actual, expected, result] */
    public static function operatorCases(): array
    {
        return [
            // equals / not_equals — trimmed, case-insensitive text
            'equals same' => ['equals', 'Yes', 'yes', true],
            'equals trims' => ['equals', '  YES ', 'yes', true],
            'equals different' => ['equals', 'no', 'yes', false],
            'equals is text not numeric' => ['equals', '10', '10.0', false],
            'equals number value' => ['equals', '50', 50, true],
            'not_equals different' => ['not_equals', 'no', 'yes', true],
            'not_equals same' => ['not_equals', 'YES', 'yes', false],
            // contains / not_contains
            'contains' => ['contains', 'I want PRICING info', 'pricing', true],
            'contains no' => ['contains', 'hello', 'price', false],
            'not_contains' => ['not_contains', 'hello', 'price', true],
            'not_contains no' => ['not_contains', 'my price', 'PRICE', false],
            // starts / ends
            'starts_with' => ['starts_with', 'Hello there', 'hello', true],
            'starts_with no' => ['starts_with', 'Say hello', 'hello', false],
            'ends_with' => ['ends_with', 'order #123', '123', true],
            'ends_with no' => ['ends_with', '123 order', '123', false],
            // numeric
            'gt' => ['greater_than', '51', '50', true],
            'gt equal' => ['greater_than', '50', '50', false],
            'lt' => ['less_than', '4.5', '5', true],
            'lt no' => ['less_than', '5', '4.5', false],
            'ge equal' => ['greater_or_equal', '50', '50', true],
            'ge float vs int' => ['greater_or_equal', '50.0', 50, true],
            'le' => ['less_or_equal', '-3', '0', true],
            'le no' => ['less_or_equal', '1e3', '999', false],
            'numeric trims' => ['greater_than', ' 12 ', '10', true],
            'numeric is not text order' => ['greater_than', '9', '10', false],
            // exists / not_exists
            'exists' => ['exists', 'anything', null, true],
            'exists empty string' => ['exists', '', null, false],
            'exists whitespace (untrimmed, legacy)' => ['exists', ' ', null, true],
            'not_exists empty' => ['not_exists', '', null, true],
            'not_exists set' => ['not_exists', 'x', null, false],
        ];
    }

    #[DataProvider('operatorCases')]
    public function test_every_operator(string $operator, mixed $actual, mixed $expected, bool $result): void
    {
        $this->assertSame($result, $this->e->evaluate($operator, $actual, $expected));
    }

    public function test_the_operator_list_is_exactly_the_documented_contract(): void
    {
        $this->assertSame([
            'equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with',
            'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal', 'exists', 'not_exists',
        ], JourneyConditionEvaluator::OPERATORS);
    }

    /** A missing variable: positive tests are false, their negations true. */
    public function test_a_missing_value_never_satisfies_a_positive_test(): void
    {
        $positive = ['contains' => 'x', 'starts_with' => 'x', 'ends_with' => 'x', 'greater_than' => '0', 'less_than' => '0', 'greater_or_equal' => '0', 'less_or_equal' => '0', 'exists' => null, 'equals' => 'x'];
        foreach ($positive as $operator => $value) {
            $this->assertFalse($this->e->evaluate($operator, null, $value), $operator);
        }

        foreach (['not_contains' => 'x', 'not_exists' => null, 'not_equals' => 'x'] as $operator => $value) {
            $this->assertTrue($this->e->evaluate($operator, null, $value), $operator);
        }
    }

    public function test_equals_without_a_value_tests_for_a_missing_variable_like_the_legacy_engine(): void
    {
        $this->assertTrue($this->e->evaluate('equals', null, null));
        $this->assertFalse($this->e->evaluate('equals', '', null));
        $this->assertFalse($this->e->evaluate('equals', 'x', null));
        $this->assertFalse($this->e->evaluate('not_equals', null, null));
        $this->assertTrue($this->e->evaluate('not_equals', 'x', null));
    }

    public function test_non_numeric_text_never_satisfies_a_numeric_test(): void
    {
        foreach (['greater_than', 'less_than', 'greater_or_equal', 'less_or_equal'] as $operator) {
            $this->assertFalse($this->e->evaluate($operator, 'lots', '10'), $operator);
            $this->assertFalse($this->e->evaluate($operator, '', '10'), $operator);
            $this->assertFalse($this->e->evaluate($operator, true, '0'), "{$operator}: a boolean is not a number");
        }
    }

    public function test_booleans_compare_as_the_words_true_and_false(): void
    {
        $this->assertTrue($this->e->evaluate('equals', true, 'true'));
        $this->assertTrue($this->e->evaluate('equals', false, 'FALSE'));
        $this->assertFalse($this->e->evaluate('equals', true, '1'));
        $this->assertTrue($this->e->evaluate('equals', 'True', true));
        $this->assertTrue($this->e->evaluate('exists', false), 'false is a value, not a missing one');
    }

    public function test_arrays_have_no_text_or_number_form(): void
    {
        $this->assertFalse($this->e->evaluate('equals', ['a'], 'a'));
        $this->assertFalse($this->e->evaluate('contains', ['a'], 'a'));
        $this->assertFalse($this->e->evaluate('greater_than', [5], '1'));
        $this->assertTrue($this->e->evaluate('not_equals', ['a'], 'a'));
    }

    public function test_unicode_is_compared_case_insensitively(): void
    {
        $this->assertTrue($this->e->evaluate('equals', 'ÉCOLE', 'école'));
        $this->assertTrue($this->e->evaluate('contains', 'नमस्ते दुनिया', 'दुनिया'));
    }

    // ------------------------------------------------------------------ AND / OR

    public function test_all_is_and(): void
    {
        $rules = [['variable' => 'size', 'operator' => 'greater_or_equal', 'value' => '10'], ['variable' => 'plan', 'operator' => 'equals', 'value' => 'pro']];

        $this->assertTrue($this->e->evaluateAll($rules, 'all', ['size' => '12', 'plan' => 'Pro']));
        $this->assertFalse($this->e->evaluateAll($rules, 'all', ['size' => '12', 'plan' => 'free']));
        $this->assertFalse($this->e->evaluateAll($rules, 'all', ['plan' => 'pro']), 'a missing variable fails its rule');
        $this->assertTrue($this->e->evaluateAll($rules, null, ['size' => '12', 'plan' => 'pro']), 'match defaults to all');
    }

    public function test_any_is_or(): void
    {
        $rules = [['variable' => 'size', 'operator' => 'greater_or_equal', 'value' => '10'], ['variable' => 'plan', 'operator' => 'equals', 'value' => 'pro']];

        $this->assertTrue($this->e->evaluateAll($rules, 'any', ['size' => '2', 'plan' => 'pro']));
        $this->assertTrue($this->e->evaluateAll($rules, 'any', ['size' => '20']));
        $this->assertFalse($this->e->evaluateAll($rules, 'any', ['size' => '2', 'plan' => 'free']));
        $this->assertFalse($this->e->evaluateAll($rules, 'any', []));
    }

    public function test_a_malformed_rule_fails_even_when_any_could_short_circuit(): void
    {
        $this->expectException(InvalidJourneyCondition::class);

        $this->e->evaluateAll([
            ['variable' => 'a', 'operator' => 'exists'],
            ['variable' => 'b', 'operator' => 'greater_than', 'value' => 'ten'],
        ], 'any', ['a' => 'yes']);
    }

    // ------------------------------------------------------------------ malformed

    public static function malformed(): array
    {
        return [
            'unknown operator' => [fn (JourneyConditionEvaluator $e) => $e->evaluate('matches_regex', 'a', 'a')],
            'php-ish operator' => [fn (JourneyConditionEvaluator $e) => $e->evaluate('==', 'a', 'a')],
            'numeric op with text value' => [fn (JourneyConditionEvaluator $e) => $e->evaluate('greater_than', '5', 'five')],
            'numeric op without value' => [fn (JourneyConditionEvaluator $e) => $e->evaluate('less_than', '5', null)],
            'contains without value' => [fn (JourneyConditionEvaluator $e) => $e->evaluate('contains', '5', null)],
            'array value' => [fn (JourneyConditionEvaluator $e) => $e->evaluate('equals', 'a', ['a'])],
            'empty rule list' => [fn (JourneyConditionEvaluator $e) => $e->evaluateAll([], 'all', [])],
            'rules not a list' => [fn (JourneyConditionEvaluator $e) => $e->evaluateAll(['x' => ['variable' => 'a', 'operator' => 'exists']], 'all', [])],
            'rule not an object' => [fn (JourneyConditionEvaluator $e) => $e->evaluateAll(['a'], 'all', [])],
            'rule without variable' => [fn (JourneyConditionEvaluator $e) => $e->evaluateAll([['operator' => 'exists']], 'all', [])],
            'rule without operator' => [fn (JourneyConditionEvaluator $e) => $e->evaluateAll([['variable' => 'a']], 'all', [])],
            'bad match mode' => [fn (JourneyConditionEvaluator $e) => $e->evaluateAll([['variable' => 'a', 'operator' => 'exists']], 'xor', [])],
        ];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_definition_is_refused_not_guessed(\Closure $call): void
    {
        $this->expectException(InvalidJourneyCondition::class);

        $call($this->e);
    }

    public function test_save_time_list_validation_allows_drafts_but_not_unsafe_rules(): void
    {
        $this->assertSame([], JourneyConditionEvaluator::conditionListErrors([], null, requireRules: false));
        $this->assertSame([], JourneyConditionEvaluator::conditionListErrors([['variable' => '', 'operator' => '']], 'all', requireRules: false));
        $this->assertNotSame([], JourneyConditionEvaluator::conditionListErrors([['variable' => 'a', 'operator' => 'eval']], 'all', requireRules: false));
        $this->assertNotSame([], JourneyConditionEvaluator::conditionListErrors([['variable' => 'a', 'operator' => 'less_than', 'value' => 'x']], 'all', requireRules: false));
        $this->assertNotSame([], JourneyConditionEvaluator::conditionListErrors('nope', 'all', requireRules: false));
    }

    // ------------------------------------------------------------------ determinism

    public function test_the_same_inputs_always_give_the_same_answer(): void
    {
        $rules = [['variable' => 'q', 'operator' => 'contains', 'value' => 'Price'], ['variable' => 'n', 'operator' => 'less_than', 'value' => '3']];
        $context = ['q' => 'what is the PRICE?', 'n' => '2'];

        $first = $this->e->evaluateAll($rules, 'all', $context);
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame($first, (new JourneyConditionEvaluator())->evaluateAll($rules, 'all', $context));
        }
        $this->assertTrue($first);
    }

    public function test_the_evaluator_has_no_escape_hatch_to_code_or_data(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/WhatsApp/JourneyConditionEvaluator.php');

        foreach (['eval(', 'create_function', 'call_user_func', 'preg_replace_callback', 'DB::', '::query(', 'Model', 'app(', 'now(', 'rand(', 'random_int'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }
}
