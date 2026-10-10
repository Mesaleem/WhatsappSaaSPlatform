<?php

namespace App\Services\WhatsApp;

/**
 * Task 26 — the 'code' node's execution engine: NOT real JavaScript.
 *
 * [Scope decision, confirmed with the account owner before writing this
 * file] Connexxa's Code node runs real, unrestricted JavaScript on its
 * own servers. Doing the same here would mean executing arbitrary
 * journey-author-supplied script on THIS production server — a true JS
 * engine (V8Js) is not reliably available on a stock XAMPP install, and
 * shelling out to a Node.js subprocess is a real sandbox-escape/RCE
 * surface this platform should not carry. Instead this class is a tiny,
 * CLOSED expression language of its own: no loops, no function calls, no
 * object/array literals, no access to any PHP value, class or function —
 * by construction there is no eval()/create_function()/reflection
 * anywhere in this file, so there is no code-execution escape to find.
 * It can read this journey's own variables (var_local/var_system, the
 * same two namespaces Task 22's renderText() exposes) and compute a
 * single `output` value from them with arithmetic, string concatenation,
 * comparisons, boolean logic and simple if/else branching. Nothing else.
 *
 * Grammar (newline- or ';'-separated statements; C-like, deliberately
 * small):
 *
 *   let NAME = EXPR;              declare a script-local variable
 *   NAME = EXPR;                  reassign a declared local, or `output`
 *                                 (always implicitly declared, starts null)
 *   if (EXPR) { STMTS } else { STMTS }    else branch optional
 *
 *   EXPR  := ternary (?:) > || > && > == != > < <= > >= > + - > * / % >
 *            unary (! -) > NUMBER | STRING | true | false | null |
 *            IDENT('.'IDENT)* | '(' EXPR ')'
 *
 *   A dotted path is valid ONLY as var_local.<path> / var_system.<path>
 *   (resolved exactly like renderText()'s own two namespaces — a missing
 *   step at any depth is null, never an error). A bare local/`output`
 *   name never takes a '.' — this language has no objects to dot into.
 *
 * Every run (and every save-time parse — see validate()) is bounded:
 * script length, token count, statements per block and expression
 * nesting depth are all capped, and evaluation itself counts its own
 * steps and aborts past a cap — belt-and-suspenders against a
 * pathologically large script wasting CPU, even though the absence of
 * loops already makes runaway execution essentially impossible.
 *
 * The final `output` value is stringified (JourneyActionConfig::
 * stringifyVariable()'s exact rule, duplicated here as a tiny pure
 * function — this class has no dependency on that one) and written into
 * the node's own outputVariable, precisely like every other node that
 * produces a value (prompt/agent/rag — see JourneyAiNodeRunner::
 * contextAfter()).
 */
final class JourneyCodeSandbox
{
    private const MAX_SCRIPT_LENGTH = 4000;

    private const MAX_TOKENS = 1500;

    private const MAX_STATEMENTS_PER_BLOCK = 100;

    private const MAX_EXPR_DEPTH = 40;

    private const MAX_EVAL_STEPS = 20000;

    private const KEYWORDS = ['let', 'if', 'else', 'true', 'false', 'null'];

    /** @var array<int, array{type: string, value: mixed}> */
    private array $tokens = [];

    private int $pos = 0;

    /** @var array<string, mixed> script-local variables, including the always-present 'output' */
    private array $locals = ['output' => null];

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, mixed> */
    private array $varSystem = [];

    private int $steps = 0;

    /**
     * Save-time check: can this script even be parsed? Never evaluates it
     * (there is nothing to evaluate against at save time — var_local is
     * the SESSION's collected answers, which do not exist yet). Returns
     * the problem, or null if the script is at least well-formed.
     */
    public static function validate(string $script): ?string
    {
        if (mb_strlen($script) > self::MAX_SCRIPT_LENGTH) {
            return 'Code is limited to '.self::MAX_SCRIPT_LENGTH.' characters.';
        }

        try {
            (new self)->parseProgram($script);

            return null;
        } catch (JourneyCodeSandboxException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Run time: parse AND evaluate. Returns the script's final `output`
     * value (null if the script never assigned one), already
     * stringified. Throws JourneyCodeSandboxException on any problem —
     * the engine fails the session on that, same contract as
     * InvalidJourneyCondition for the branching nodes.
     *
     * @param  array<string, mixed>  $context  this journey's own variables (var_local)
     * @param  array<string, mixed>  $varSystem  platform built-ins (var_system)
     */
    public static function run(string $script, array $context, array $varSystem): string
    {
        if (mb_strlen($script) > self::MAX_SCRIPT_LENGTH) {
            throw new JourneyCodeSandboxException('Code is limited to '.self::MAX_SCRIPT_LENGTH.' characters.');
        }

        $sandbox = new self;
        $sandbox->context = $context;
        $sandbox->varSystem = $varSystem;
        $program = $sandbox->parseProgram($script);
        $sandbox->execBlock($program);

        return self::stringify($sandbox->locals['output']);
    }

    // ================================================================== parsing

    /** @return array<int, array<string, mixed>> the top-level statement list */
    private function parseProgram(string $script): array
    {
        $this->tokens = $this->tokenize($script);
        $this->pos = 0;
        $statements = $this->parseStatements();

        if (! $this->atEnd()) {
            throw new JourneyCodeSandboxException("Unexpected '{$this->peek()['value']}' — code after the last statement could not be parsed.");
        }

        return $statements;
    }

    /** @return array<int, array{type: string, value: mixed}> */
    private function tokenize(string $script): array
    {
        $tokens = [];
        $len = mb_strlen($script);
        $i = 0;

        while ($i < $len) {
            $ch = mb_substr($script, $i, 1);

            if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n") {
                $i++;

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $value = '';
                $i++;

                while ($i < $len && mb_substr($script, $i, 1) !== $quote) {
                    $c = mb_substr($script, $i, 1);

                    if ($c === '\\' && $i + 1 < $len) {
                        $next = mb_substr($script, $i + 1, 1);
                        $value .= match ($next) {
                            'n' => "\n", 't' => "\t", '\\' => '\\', "'" => "'", '"' => '"',
                            default => $next,
                        };
                        $i += 2;

                        continue;
                    }

                    $value .= $c;
                    $i++;
                }

                if ($i >= $len) {
                    throw new JourneyCodeSandboxException('A string is missing its closing quote.');
                }

                $i++; // closing quote
                $tokens[] = ['type' => 'STRING', 'value' => $value];

                continue;
            }

            if (ctype_digit($ch) || ($ch === '.' && ctype_digit(mb_substr($script, $i + 1, 1)))) {
                $start = $i;

                while ($i < $len && (ctype_digit(mb_substr($script, $i, 1)) || mb_substr($script, $i, 1) === '.')) {
                    $i++;
                }

                $tokens[] = ['type' => 'NUMBER', 'value' => (float) mb_substr($script, $start, $i - $start)];

                continue;
            }

            if (ctype_alpha($ch) || $ch === '_') {
                $start = $i;

                while ($i < $len && (ctype_alnum(mb_substr($script, $i, 1)) || mb_substr($script, $i, 1) === '_')) {
                    $i++;
                }

                $word = mb_substr($script, $start, $i - $start);
                $tokens[] = ['type' => in_array($word, self::KEYWORDS, true) ? 'KEYWORD' : 'IDENT', 'value' => $word];

                continue;
            }

            $two = mb_substr($script, $i, 2);

            if (in_array($two, ['==', '!=', '<=', '>=', '&&', '||'], true)) {
                $tokens[] = ['type' => 'OP', 'value' => $two];
                $i += 2;

                continue;
            }

            if (in_array($ch, ['+', '-', '*', '/', '%', '=', '<', '>', '!', '(', ')', '{', '}', ';', ',', '.', '?', ':'], true)) {
                $tokens[] = ['type' => 'OP', 'value' => $ch];
                $i++;

                continue;
            }

            throw new JourneyCodeSandboxException("Unrecognised character '{$ch}' in code.");
        }

        if (count($tokens) > self::MAX_TOKENS) {
            throw new JourneyCodeSandboxException('Code is too large to run (over '.self::MAX_TOKENS.' tokens).');
        }

        return $tokens;
    }

    private function atEnd(): bool
    {
        return $this->pos >= count($this->tokens);
    }

    /** @return array{type: string, value: mixed} */
    private function peek(): array
    {
        return $this->tokens[$this->pos] ?? ['type' => 'EOF', 'value' => 'end of code'];
    }

    private function isOp(string $value): bool
    {
        $t = $this->peek();

        return $t['type'] === 'OP' && $t['value'] === $value;
    }

    private function isKeyword(string $value): bool
    {
        $t = $this->peek();

        return $t['type'] === 'KEYWORD' && $t['value'] === $value;
    }

    /** Consumes and returns the current token (whatever it is). */
    private function advance(): array
    {
        $t = $this->peek();
        $this->pos++;

        return $t;
    }

    private function expectOp(string $value): void
    {
        if (! $this->isOp($value)) {
            throw new JourneyCodeSandboxException("Expected '{$value}' but found '{$this->peek()['value']}'.");
        }

        $this->advance();
    }

    /** @return array<int, array<string, mixed>> */
    private function parseStatements(): array
    {
        $statements = [];

        while (! $this->atEnd() && ! $this->isOp('}')) {
            if ($this->isOp(';')) {
                $this->advance();

                continue;
            }

            $statements[] = $this->parseStatement();

            if (count($statements) > self::MAX_STATEMENTS_PER_BLOCK) {
                throw new JourneyCodeSandboxException('A block has too many statements (over '.self::MAX_STATEMENTS_PER_BLOCK.').');
            }

            if ($this->isOp(';')) {
                $this->advance();
            }
        }

        return $statements;
    }

    /** @return array<string, mixed> */
    private function parseStatement(): array
    {
        if ($this->isKeyword('let')) {
            $this->advance();
            $name = $this->expectIdent();
            $this->expectOp('=');
            $expr = $this->parseExpr(0);

            return ['stmt' => 'let', 'name' => $name, 'expr' => $expr];
        }

        if ($this->isKeyword('if')) {
            $this->advance();
            $this->expectOp('(');
            $cond = $this->parseExpr(0);
            $this->expectOp(')');
            $then = $this->parseBlock();
            $else = null;

            if ($this->isKeyword('else')) {
                $this->advance();
                $else = $this->isKeyword('if') ? [$this->parseStatement()] : $this->parseBlock();
            }

            return ['stmt' => 'if', 'cond' => $cond, 'then' => $then, 'else' => $else];
        }

        // The only remaining statement shape: NAME = EXPR;
        $name = $this->expectIdent();
        $this->expectOp('=');
        $expr = $this->parseExpr(0);

        return ['stmt' => 'assign', 'name' => $name, 'expr' => $expr];
    }

    /** @return array<int, array<string, mixed>> */
    private function parseBlock(): array
    {
        $this->expectOp('{');
        $statements = $this->parseStatements();
        $this->expectOp('}');

        return $statements;
    }

    private function expectIdent(): string
    {
        $t = $this->peek();

        if ($t['type'] !== 'IDENT') {
            throw new JourneyCodeSandboxException("Expected a name but found '{$t['value']}'.");
        }

        $this->advance();

        return (string) $t['value'];
    }

    /** @return array<string, mixed> */
    private function parseExpr(int $depth): array
    {
        if ($depth > self::MAX_EXPR_DEPTH) {
            throw new JourneyCodeSandboxException('An expression is nested too deeply (over '.self::MAX_EXPR_DEPTH.' levels).');
        }

        $cond = $this->parseLogicalOr($depth + 1);

        if (! $this->isOp('?')) {
            return $cond;
        }

        $this->advance();
        $then = $this->parseExpr($depth + 1);
        $this->expectOp(':');
        $else = $this->parseExpr($depth + 1);

        return ['expr' => 'ternary', 'cond' => $cond, 'then' => $then, 'else' => $else];
    }

    /** @return array<string, mixed> */
    private function parseLogicalOr(int $depth): array
    {
        $left = $this->parseLogicalAnd($depth);

        while ($this->isOp('||')) {
            $this->advance();
            $left = ['expr' => 'binary', 'op' => '||', 'left' => $left, 'right' => $this->parseLogicalAnd($depth)];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function parseLogicalAnd(int $depth): array
    {
        $left = $this->parseEquality($depth);

        while ($this->isOp('&&')) {
            $this->advance();
            $left = ['expr' => 'binary', 'op' => '&&', 'left' => $left, 'right' => $this->parseEquality($depth)];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function parseEquality(int $depth): array
    {
        $left = $this->parseRelational($depth);

        while ($this->isOp('==') || $this->isOp('!=')) {
            $op = $this->advance()['value'];
            $left = ['expr' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->parseRelational($depth)];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function parseRelational(int $depth): array
    {
        $left = $this->parseAdditive($depth);

        while ($this->isOp('<') || $this->isOp('<=') || $this->isOp('>') || $this->isOp('>=')) {
            $op = $this->advance()['value'];
            $left = ['expr' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->parseAdditive($depth)];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function parseAdditive(int $depth): array
    {
        $left = $this->parseMultiplicative($depth);

        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->advance()['value'];
            $left = ['expr' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->parseMultiplicative($depth)];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function parseMultiplicative(int $depth): array
    {
        $left = $this->parseUnary($depth);

        while ($this->isOp('*') || $this->isOp('/') || $this->isOp('%')) {
            $op = $this->advance()['value'];
            $left = ['expr' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->parseUnary($depth)];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function parseUnary(int $depth): array
    {
        if ($this->isOp('!') || $this->isOp('-')) {
            $op = $this->advance()['value'];

            return ['expr' => 'unary', 'op' => $op, 'operand' => $this->parseUnary($depth)];
        }

        return $this->parsePrimary($depth);
    }

    /** @return array<string, mixed> */
    private function parsePrimary(int $depth): array
    {
        $t = $this->peek();

        if ($t['type'] === 'NUMBER') {
            $this->advance();

            return ['expr' => 'lit', 'value' => $t['value']];
        }

        if ($t['type'] === 'STRING') {
            $this->advance();

            return ['expr' => 'lit', 'value' => $t['value']];
        }

        if ($this->isKeyword('true')) {
            $this->advance();

            return ['expr' => 'lit', 'value' => true];
        }

        if ($this->isKeyword('false')) {
            $this->advance();

            return ['expr' => 'lit', 'value' => false];
        }

        if ($this->isKeyword('null')) {
            $this->advance();

            return ['expr' => 'lit', 'value' => null];
        }

        if ($this->isOp('(')) {
            $this->advance();
            $inner = $this->parseExpr($depth + 1);
            $this->expectOp(')');

            return $inner;
        }

        if ($t['type'] === 'IDENT') {
            $path = [$this->expectIdent()];

            while ($this->isOp('.')) {
                $this->advance();
                $path[] = $this->expectIdent();
            }

            return ['expr' => 'var', 'path' => $path];
        }

        throw new JourneyCodeSandboxException("Expected a value but found '{$t['value']}'.");
    }

    // ================================================================== evaluation

    /** @param array<int, array<string, mixed>> $statements */
    private function execBlock(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->execStatement($statement);
        }
    }

    /** @param array<string, mixed> $statement */
    private function execStatement(array $statement): void
    {
        $this->step();

        match ($statement['stmt']) {
            'let' => $this->locals[$statement['name']] = $this->evalExpr($statement['expr']),
            'assign' => $this->assign($statement['name'], $this->evalExpr($statement['expr'])),
            'if' => $this->truthy($this->evalExpr($statement['cond']))
                ? $this->execBlock($statement['then'])
                : ($statement['else'] !== null ? $this->execBlock($statement['else']) : null),
            default => throw new JourneyCodeSandboxException('Unknown statement.'),
        };
    }

    private function assign(string $name, mixed $value): void
    {
        if (! array_key_exists($name, $this->locals)) {
            throw new JourneyCodeSandboxException("Unknown variable '{$name}'. Declare it first with 'let {$name} = ...;', or assign to 'output'.");
        }

        $this->locals[$name] = $value;
    }

    /** @param array<string, mixed> $expr */
    private function evalExpr(array $expr): mixed
    {
        $this->step();

        return match ($expr['expr']) {
            'lit' => $expr['value'],
            'var' => $this->resolveVar($expr['path']),
            'unary' => $this->evalUnary($expr['op'], $this->evalExpr($expr['operand'])),
            'binary' => $this->evalBinary($expr['op'], $expr['left'], $expr['right']),
            'ternary' => $this->truthy($this->evalExpr($expr['cond']))
                ? $this->evalExpr($expr['then'])
                : $this->evalExpr($expr['else']),
            default => throw new JourneyCodeSandboxException('Unknown expression.'),
        };
    }

    private function step(): void
    {
        if (++$this->steps > self::MAX_EVAL_STEPS) {
            throw new JourneyCodeSandboxException('Code took too many steps to run (over '.self::MAX_EVAL_STEPS.').');
        }
    }

    /** @param array<int, string> $path */
    private function resolveVar(array $path): mixed
    {
        if ($path[0] === 'var_local' && count($path) > 1) {
            return $this->digPath($this->context, array_slice($path, 1));
        }

        if ($path[0] === 'var_system' && count($path) > 1) {
            return $this->digPath($this->varSystem, array_slice($path, 1));
        }

        if (count($path) > 1) {
            throw new JourneyCodeSandboxException("'{$path[0]}' is a plain value; only var_local./var_system. may use '.' paths.");
        }

        $name = $path[0];

        if (! array_key_exists($name, $this->locals)) {
            throw new JourneyCodeSandboxException("Unknown variable '{$name}'. Declare it first with 'let {$name} = ...;', or use var_local./var_system.");
        }

        return $this->locals[$name];
    }

    /** @param array<string, mixed> $root @param array<int, string> $segments */
    private function digPath(array $root, array $segments): mixed
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

    private function evalUnary(string $op, mixed $value): mixed
    {
        return match ($op) {
            '!' => ! $this->truthy($value),
            '-' => -$this->numeric($value, '-'),
            default => throw new JourneyCodeSandboxException("Unknown operator '{$op}'."),
        };
    }

    /** @param array<string, mixed> $leftExpr @param array<string, mixed> $rightExpr */
    private function evalBinary(string $op, array $leftExpr, array $rightExpr): mixed
    {
        // Short-circuit: the right side of && / || is evaluated only when it matters.
        if ($op === '&&') {
            return $this->truthy($this->evalExpr($leftExpr)) ? $this->truthy($this->evalExpr($rightExpr)) : false;
        }

        if ($op === '||') {
            return $this->truthy($this->evalExpr($leftExpr)) ? true : $this->truthy($this->evalExpr($rightExpr));
        }

        $left = $this->evalExpr($leftExpr);
        $right = $this->evalExpr($rightExpr);

        if ($op === '+' && (is_string($left) || is_string($right))) {
            return self::stringify($left).self::stringify($right);
        }

        return match ($op) {
            '+' => $this->numeric($left, '+') + $this->numeric($right, '+'),
            '-' => $this->numeric($left, '-') - $this->numeric($right, '-'),
            '*' => $this->numeric($left, '*') * $this->numeric($right, '*'),
            '/' => $this->divide($this->numeric($left, '/'), $this->numeric($right, '/')),
            '%' => $this->divide($this->numeric($left, '%'), $this->numeric($right, '%'), true),
            '==' => $left == $right,
            '!=' => $left != $right,
            '<' => $this->numeric($left, '<') < $this->numeric($right, '<'),
            '<=' => $this->numeric($left, '<=') <= $this->numeric($right, '<='),
            '>' => $this->numeric($left, '>') > $this->numeric($right, '>'),
            '>=' => $this->numeric($left, '>=') >= $this->numeric($right, '>='),
            default => throw new JourneyCodeSandboxException("Unknown operator '{$op}'."),
        };
    }

    private function divide(float $left, float $right, bool $modulo = false): float
    {
        if ($right === 0.0) {
            throw new JourneyCodeSandboxException('Division by zero.');
        }

        return $modulo ? fmod($left, $right) : $left / $right;
    }

    private function numeric(mixed $value, string $op): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new JourneyCodeSandboxException("'{$op}' needs a number, not '".self::stringify($value)."'.");
    }

    private function truthy(mixed $value): bool
    {
        return (bool) $value;
    }

    /** Mirrors JourneyActionConfig::stringifyVariable() exactly (duplicated — this class has no dependency on that one). */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
