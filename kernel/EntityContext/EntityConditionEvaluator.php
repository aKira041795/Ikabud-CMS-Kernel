<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Restricted expression evaluator for entity view conditions (action_show_if).
 *
 * Compiles conditions into an AST once, then evaluates against row data
 * in O(1) per row — avoids parsing the same expression N times.
 *
 * Supported grammar (restricted — no function calls, mutations, or service access):
 *   field == "value"
 *   field != "value"
 *   field >  numeric
 *   field >= numeric
 *   field <  numeric
 *   field <= numeric
 *   field in ["a", "b"]
 *   !condition
 *   condition && condition
 *   condition || condition
 *   (condition)
 *   field == null
 *   field != null
 *   field              (truthy check)
 *   !field             (falsy check)
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class EntityConditionEvaluator
{
    /** @var array<string, array{ast: array, source: string}> Compiled condition cache */
    private static array $compiledCache = [];

    /**
     * Compile a condition string into a reusable AST node.
     *
     * Returns a serializable array structure that can be cached with
     * the view contract. Use evaluate() to run it against row data.
     *
     * @param string $condition The raw condition string (e.g. 'status == "pending" && priority == "high"')
     * @return array{type: string, ...} Compiled AST
     *
     * @throws \InvalidArgumentException When the condition cannot be parsed
     */
    public function compile(string $condition): array
    {
        $trimmed = trim($condition);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Condition cannot be empty.');
        }

        $cacheKey = $trimmed;
        if (isset(self::$compiledCache[$cacheKey])) {
            return self::$compiledCache[$cacheKey]['ast'];
        }

        $ast = $this->parseExpression($trimmed);

        self::$compiledCache[$cacheKey] = ['ast' => $ast, 'source' => $trimmed];

        return $ast;
    }

    /**
     * Evaluate a compiled AST against a row of data.
     *
     * @param array $ast  Compiled AST from compile()
     * @param array $row  Row data (field => value)
     * @return bool
     */
    public function evaluate(array $ast, array $row): bool
    {
        return $this->evaluateNode($ast, $row);
    }

    /**
     * Compile and evaluate in one call (convenience for single-use).
     *
     * For repeated evaluation (e.g. N rows × M conditions), pre-compile
     * with compile() and call evaluate() per row.
     */
    public function evaluateString(string $condition, array $row): bool
    {
        $ast = $this->compile($condition);
        return $this->evaluate($ast, $row);
    }

    /**
     * Clear the compiled condition cache (for test reset).
     */
    public static function resetCache(): void
    {
        self::$compiledCache = [];
    }

    // ── Parser ─────────────────────────────────────────────────────

    /**
     * Recursive-descent parser for the restricted condition grammar.
     *
     * Top level: orExpr
     */
    private function parseExpression(string $input): array
    {
        $parser = new class($input) {
            private string $input;
            private int $pos = 0;
            private int $len;

            public function __construct(string $input) {
                $this->input = $input;
                $this->len = strlen($input);
            }

            public function parse(): array {
                $result = $this->orExpr();
                $this->skipWhitespace();
                if ($this->pos < $this->len) {
                    throw new \InvalidArgumentException(
                        "Unexpected token at position {$this->pos}: '" . substr($this->input, $this->pos, 10) . "'"
                    );
                }
                return $result;
            }

            private function orExpr(): array {
                $left = $this->andExpr();
                $this->skipWhitespace();
                while ($this->pos < $this->len && substr($this->input, $this->pos, 2) === '||') {
                    $this->pos += 2;
                    $right = $this->andExpr();
                    $left = ['type' => 'or', 'left' => $left, 'right' => $right];
                    $this->skipWhitespace();
                }
                return $left;
            }

            private function andExpr(): array {
                $left = $this->unaryExpr();
                $this->skipWhitespace();
                while ($this->pos < $this->len && substr($this->input, $this->pos, 2) === '&&') {
                    $this->pos += 2;
                    $right = $this->unaryExpr();
                    $left = ['type' => 'and', 'left' => $left, 'right' => $right];
                    $this->skipWhitespace();
                }
                return $left;
            }

            private function unaryExpr(): array {
                $this->skipWhitespace();
                if ($this->pos < $this->len && $this->input[$this->pos] === '!') {
                    $this->pos++;
                    $inner = $this->primaryExpr();
                    return ['type' => 'not', 'child' => $inner];
                }
                return $this->primaryExpr();
            }

            private function primaryExpr(): array {
                $this->skipWhitespace();

                // Parenthesized expression
                if ($this->pos < $this->len && $this->input[$this->pos] === '(') {
                    $this->pos++; // consume '('
                    $inner = $this->orExpr();
                    $this->skipWhitespace();
                    if ($this->pos < $this->len && $this->input[$this->pos] === ')') {
                        $this->pos++; // consume ')'
                    }
                    return $inner;
                }

                // Peek ahead: identifier or quoted string
                $node = $this->valueExpr();

                $this->skipWhitespace();

                // Comparison operators
                if ($this->pos < $this->len) {
                    $op = null;
                    if (substr($this->input, $this->pos, 2) === '==') {
                        $op = '=='; $this->pos += 2;
                    } elseif (substr($this->input, $this->pos, 2) === '!=') {
                        $op = '!='; $this->pos += 2;
                    } elseif (substr($this->input, $this->pos, 2) === '>=') {
                        $op = '>='; $this->pos += 2;
                    } elseif (substr($this->input, $this->pos, 2) === '<=') {
                        $op = '<='; $this->pos += 2;
                    } elseif ($this->input[$this->pos] === '>') {
                        $op = '>'; $this->pos++;
                    } elseif ($this->input[$this->pos] === '<') {
                        $op = '<'; $this->pos++;
                    } elseif (substr($this->input, $this->pos, 2) === 'in' && ($this->pos + 2 >= $this->len || ctype_space($this->input[$this->pos + 2] ?? ' ') || $this->input[$this->pos + 2] === '(')) {
                        $op = 'in';
                        $this->pos += 2;
                    }

                    if ($op !== null) {
                        $this->skipWhitespace();
                        if ($op === 'in') {
                            $right = $this->parseList();
                        } else {
                            $right = $this->valueExpr();
                        }
                        return ['type' => 'compare', 'op' => $op, 'left' => $node, 'right' => $right];
                    }
                }

                // Bare value: truthy check (field) or falsy (!field handled in unaryExpr)
                return $node;
            }

            private function valueExpr(): array {
                $this->skipWhitespace();
                if ($this->pos >= $this->len) {
                    throw new \InvalidArgumentException('Expected value but reached end of condition.');
                }

                $ch = $this->input[$this->pos];

                // Quoted string
                if ($ch === '"' || $ch === "'") {
                    return $this->parseString($ch);
                }

                // Number
                if ($ch === '-' || ctype_digit($ch)) {
                    $num = $this->parseNumber();
                    if ($num !== null) return $num;
                }

                // Keyword null/true/false
                if (substr($this->input, $this->pos, 4) === 'null') {
                    $this->pos += 4;
                    return ['type' => 'null'];
                }
                if (substr($this->input, $this->pos, 4) === 'true') {
                    $this->pos += 4;
                    return ['type' => 'bool', 'value' => true];
                }
                if (substr($this->input, $this->pos, 5) === 'false') {
                    $this->pos += 5;
                    return ['type' => 'bool', 'value' => false];
                }

                // Identifier (field name)
                return $this->parseIdentifier();
            }

            private function parseString(string $quote): array {
                $start = $this->pos + 1;
                $end = strpos($this->input, $quote, $start);
                if ($end === false) {
                    throw new \InvalidArgumentException('Unterminated string in condition.');
                }
                $value = substr($this->input, $start, $end - $start);
                $this->pos = $end + 1;
                return ['type' => 'string', 'value' => $value];
            }

            private function parseNumber(): ?array {
                $start = $this->pos;
                if ($this->input[$this->pos] === '-') $this->pos++;
                while ($this->pos < $this->len && ctype_digit($this->input[$this->pos])) {
                    $this->pos++;
                }
                if ($this->pos > $start) {
                    $numStr = substr($this->input, $start, $this->pos - $start);
                    return ['type' => 'number', 'value' => (int)$numStr];
                }
                return null;
            }

            private function parseIdentifier(): array {
                $start = $this->pos;
                while ($this->pos < $this->len && (ctype_alnum($this->input[$this->pos]) || $this->input[$this->pos] === '_' || $this->input[$this->pos] === '.')) {
                    $this->pos++;
                }
                $name = substr($this->input, $start, $this->pos - $start);
                if ($name === '') {
                    throw new \InvalidArgumentException('Expected identifier at position ' . $start);
                }
                return ['type' => 'field', 'name' => $name];
            }

            private function parseList(): array {
                $this->skipWhitespace();
                $items = [];
                if ($this->pos < $this->len && $this->input[$this->pos] === '[') {
                    $this->pos++; // consume '['
                    $this->skipWhitespace();
                    while ($this->pos < $this->len && $this->input[$this->pos] !== ']') {
                        $items[] = $this->valueExpr();
                        $this->skipWhitespace();
                        if ($this->pos < $this->len && $this->input[$this->pos] === ',') {
                            $this->pos++;
                            $this->skipWhitespace();
                        }
                    }
                    if ($this->pos < $this->len && $this->input[$this->pos] === ']') {
                        $this->pos++;
                    }
                }
                return ['type' => 'list', 'items' => $items];
            }

            private function skipWhitespace(): void {
                while ($this->pos < $this->len && ctype_space($this->input[$this->pos])) {
                    $this->pos++;
                }
            }
        };

        return $parser->parse();
    }

    // ── Evaluator ──────────────────────────────────────────────────

    private function evaluateNode(array $node, array $row): bool
    {
        return match ($node['type']) {
            'and' => $this->evaluateNode($node['left'], $row) && $this->evaluateNode($node['right'], $row),
            'or'  => $this->evaluateNode($node['left'], $row) || $this->evaluateNode($node['right'], $row),
            'not' => !$this->evaluateNode($node['child'], $row),
            'compare' => $this->evaluateCompare($node, $row),
            'field' => self::isTruthy($this->resolveField($node['name'], $row)),
            'string' => true,  // bare string literal as truthy check
            'number' => (bool)$node['value'],
            'bool' => $node['value'],
            'null' => false,
            'list' => true,
            default => true,
        };
    }

    private function evaluateCompare(array $node, array $row): bool
    {
        $leftVal = $this->resolveValue($node['left'], $row);
        $rightVal = $this->resolveValue($node['right'], $row);

        return match ($node['op']) {
            '==' => $this->compareEq($leftVal, $rightVal),
            '!=' => !$this->compareEq($leftVal, $rightVal),
            '>'  => is_numeric($leftVal) && is_numeric($rightVal) && (float)$leftVal > (float)$rightVal,
            '>=' => is_numeric($leftVal) && is_numeric($rightVal) && (float)$leftVal >= (float)$rightVal,
            '<'  => is_numeric($leftVal) && is_numeric($rightVal) && (float)$leftVal < (float)$rightVal,
            '<=' => is_numeric($leftVal) && is_numeric($rightVal) && (float)$leftVal <= (float)$rightVal,
            'in' => $this->evaluateIn($leftVal, $node['right'], $row),
            default => true,
        };
    }

    private function compareEq(mixed $left, mixed $right): bool
    {
        // Null comparison
        if ($left === null && $right === null) return true;
        if ($left === null || $right === null) return false;

        // String comparison
        if (is_string($left) && is_string($right)) return $left === $right;

        // Numeric comparison
        if (is_numeric($left) && is_numeric($right)) return (float)$left === (float)$right;

        // Boolean comparison
        if (is_bool($left) && is_bool($right)) return $left === $right;

        return (string)$left === (string)$right;
    }

    private function evaluateIn(mixed $leftVal, array $listNode, array $row): bool
    {
        foreach ($listNode['items'] as $item) {
            $itemVal = $this->resolveValue($item, $row);
            if ($this->compareEq($leftVal, $itemVal)) {
                return true;
            }
        }
        return false;
    }

    private function resolveValue(array $node, array $row): mixed
    {
        return match ($node['type']) {
            'field' => $this->resolveField($node['name'], $row),
            'string' => $node['value'],
            'number' => $node['value'],
            'bool' => $node['value'],
            'null' => null,
            'list' => array_map(fn(array $item): mixed => $this->resolveValue($item, $row), $node['items']),
            default => null,
        };
    }

    private function resolveField(string $name, array $row): mixed
    {
        // Support simple dot notation: user.name
        if (str_contains($name, '.')) {
            $parts = explode('.', $name);
            $current = $row;
            foreach ($parts as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) {
                    return null;
                }
                $current = $current[$part];
            }
            return $current;
        }

        return $row[$name] ?? null;
    }

    private static function isTruthy(mixed $value): bool
    {
        if ($value === null) return false;
        if (is_bool($value)) return $value;
        if (is_string($value)) return $value !== '' && $value !== '0';
        if (is_numeric($value)) return (float)$value !== 0.0;
        if (is_array($value)) return !empty($value);
        return (bool)$value;
    }
}
