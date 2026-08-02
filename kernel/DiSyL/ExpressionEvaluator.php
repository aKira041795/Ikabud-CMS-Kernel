<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL;

use Ikabud\Kernel\DiSyL\v4\FunctionRegistry;

/**
 * DiSyL Expression Evaluator — resolves variable paths, evaluates conditions,
 * arithmetic, comparisons, ternary, concatenation, and applies filters.
 *
 * Extracted from TemplateEngine (7,698L → this class ~700L) for testability
 * and single responsibility. TemplateEngine delegates here.
 *
 * @package Ikabud\Kernel\DiSyL
 */
class ExpressionEvaluator
{
    /** @var array<string, callable> Registered filters */
    private array $filters = [];

    private bool $strictMode = false;
    private array $declaredVars = [];

    /** @var string|null Current template path (for error context) */
    private ?string $currentTemplatePath = null;

    /** @var string|null Current expression being resolved (for error context) */
    private ?string $currentExpression = null;

    /** @var callable|null Sandbox require callback */
    private $sandboxRequire = null;

    /** @var callable|null Error logger callback */
    private $logErrorCallback = null;

    /** @var bool Whether we're inside a <script> context */
    private bool $scriptContext = false;

    /** Max filter chain depth */
    private const FILTER_CHAIN_MAX = 10;

    // ── Configuration ────────────────────────────────────────────────

    public function setStrictMode(bool $strict): void { $this->strictMode = $strict; }
    public function isStrictMode(): bool { return $this->strictMode; }
    public function setDeclaredVars(array $vars): void { $this->declaredVars = $vars; }
    public function setCurrentTemplatePath(?string $path): void { $this->currentTemplatePath = $path; }
    public function setScriptContext(bool $v): void { $this->scriptContext = $v; }
    public function isScriptContext(): bool { return $this->scriptContext; }

    public function setSandboxRequire(callable $cb): void { $this->sandboxRequire = $cb; }
    public function setLogErrorCallback(callable $cb): void { $this->logErrorCallback = $cb; }

    public function setFilters(array $filters): void { $this->filters = $filters; }
    public function getFilters(): array { return $this->filters; }

    // ── Core value resolution ────────────────────────────────────────

    /**
     * Resolve a variable path or expression to a value from context.
     */
    public function resolveValue(string $path, array $context): mixed
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        // Quoted string literal
        if (preg_match('/^["\'](.*)["\']$/', $path, $qm)) {
            return $qm[1];
        }

        $prevExpr = $this->currentExpression;
        $this->currentExpression = $path;

        // Strip leading $ for PHP-style variable references e.g. isset($var)
        if (str_starts_with($path, '$')) {
            $path = substr($path, 1);
        }

        // Strip outer balanced parentheses (e.g. (foo.bar|default:false) → foo.bar|default:false)
        if (str_starts_with($path, '(') && str_ends_with($path, ')')) {
            $inner = trim(substr($path, 1, -1));
            $pdepth = 0;
            $balanced = true;
            for ($pi = 0, $pl = strlen($inner); $pi < $pl; $pi++) {
                if ($inner[$pi] === '(') { $pdepth++; }
                elseif ($inner[$pi] === ')') { $pdepth--; if ($pdepth < 0) { $balanced = false; break; } }
            }
            if ($balanced && $pdepth === 0) {
                $path = $inner;
            }
        }

        // String concatenation with ~ operator (skip if array literal)
        if (str_contains($path, '~') && $path[0] !== '[' && !preg_match('/^["\'].*["\']$/', $path)) {
            $result = $this->evaluateConcat($path, $context);
            if ($result !== null) {
                $this->currentExpression = $prevExpr;
                return $result;
            }
        }

        // Boolean and null literals
        $lower = strtolower($path);
        if ($lower === 'true') { $this->currentExpression = $prevExpr; return true; }
        if ($lower === 'false') { $this->currentExpression = $prevExpr; return false; }
        if ($lower === 'null') { $this->currentExpression = $prevExpr; return null; }

        // keyof expression
        if (str_starts_with($lower, 'keyof ')) {
            $this->currentExpression = $prevExpr;
            return $this->resolveKeyof(substr($path, 6));
        }

        // Numeric literals
        if (is_numeric($path)) {
            $this->currentExpression = $prevExpr;
            return str_contains($path, '.') ? (float)$path : (int)$path;
        }

        // Function call: funcname(args...)
        if (preg_match('/^([a-zA-Z_]\w*)\s*\(/', $path, $fcm)) {
            $parenStart = strpos($path, '(', strlen($fcm[1]));
            if ($parenStart !== false) {
                $depth = 0;
                $close = -1;
                for ($i = $parenStart, $plen = strlen($path); $i < $plen; $i++) {
                    if ($path[$i] === '(') {
                        $depth++;
                    } elseif ($path[$i] === ')') {
                        $depth--;
                        if ($depth === 0) {
                            $close = $i;
                            break;
                        }
                    }
                }
                if ($close === strlen($path) - 1) {
                    $funcName = $fcm[1];
                    $argsStr = trim(substr($path, $parenStart + 1, $close - $parenStart - 1));
                    $argParts = $argsStr !== '' ? $this->splitCallArgs($argsStr) : [];
                    $resolved = [];
                    foreach ($argParts as $arg) {
                        $arg = trim($arg);
                        if (is_numeric($arg)) {
                            $resolved[] = str_contains($arg, '.') ? (float)$arg : (int)$arg;
                        } else {
                            $arith = $this->evaluateArithmetic($arg, $context);
                            $resolved[] = $arith !== null ? $arith : $this->resolveValue($arg, $context);
                        }
                    }
                    $this->currentExpression = $prevExpr;
                    return FunctionRegistry::call($funcName, $resolved);
                }
            }
        }

        // Array literal: [val1, val2, ...] or ['key1' => val1, 'key2' => val2, ...]
        if ($path !== '' && $path[0] === '[') {
            $close = $this->findMatchingBracket($path, '[', ']', 0);
            if ($close !== false && $close === strlen($path) - 1) {
                $inner = trim(substr($path, 1, -1));
                if ($inner === '') {
                    $this->currentExpression = $prevExpr;
                    return [];
                }
                $parts = $this->splitByComma($inner);
                $result = [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '') { continue; }

                    // Associative entry: key => value
                    $arrowPos = $this->findUnquotedArrow($part);
                    if ($arrowPos !== false) {
                        $keyPart = trim(substr($part, 0, $arrowPos));
                        $valPart = trim(substr($part, $arrowPos + 2));

                        // Resolve key
                        if (preg_match('/^["\'](.*)["\']$/', $keyPart, $km)) {
                            $key = $km[1];
                        } elseif (preg_match('/^[a-zA-Z_]\w*$/', $keyPart)) {
                            $key = $keyPart;
                        } else {
                            $key = $this->resolveValue($keyPart, $context);
                        }

                        // Resolve value
                        if (preg_match('/^["\'](.*)["\']$/', $valPart, $vm)) {
                            $resolvedVal = $vm[1];
                        } elseif (is_numeric($valPart)) {
                            $resolvedVal = str_contains($valPart, '.') ? (float)$valPart : (int)$valPart;
                        } elseif (strtolower($valPart) === 'true') {
                            $resolvedVal = true;
                        } elseif (strtolower($valPart) === 'false') {
                            $resolvedVal = false;
                        } elseif (strtolower($valPart) === 'null') {
                            $resolvedVal = null;
                        } else {
                            $resolvedVal = $this->resolveValueWithFilters($valPart, $context);
                        }

                        $result[(string)$key] = $resolvedVal;
                    } else {
                        // Indexed entry (no =>)
                        if (preg_match('/^["\'](.*)["\']$/', $part, $m)) {
                            $result[] = $m[1];
                        } elseif (is_numeric($part)) {
                            $result[] = str_contains($part, '.') ? (float)$part : (int)$part;
                        } elseif (strtolower($part) === 'true') {
                            $result[] = true;
                        } elseif (strtolower($part) === 'false') {
                            $result[] = false;
                        } elseif (strtolower($part) === 'null') {
                            $result[] = null;
                        } else {
                            $result[] = $this->resolveValueWithFilters($part, $context);
                        }
                    }
                }
                $this->currentExpression = $prevExpr;
                return $result;
            }
        }

        // Arithmetic expressions: a + b, a * b, etc.
        // Must check before dot-path resolution to prevent "a + b" being treated as a single path
        if (preg_match('/[+\-*\/%]/', $path) && !preg_match('/^["\'].*["\']$/', $path)) {
            $arith = $this->evaluateArithmetic($path, $context);
            if ($arith !== null) {
                $this->currentExpression = $prevExpr;
                return $arith;
            }
        }

        $parts = explode('.', $path);
        $value = $context;

        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                $this->currentExpression = $prevExpr;
                return null;
            }
        }

        $this->currentExpression = $prevExpr;
        return $value;
    }

    /**
     * Resolve an expression with optional filter chain.
     */
    public function resolveValueWithFilters(string $expr, array $context): mixed
    {
        if (!str_contains($expr, '|')) {
            return $this->resolveValue($expr, $context);
        }

        $parts = $this->splitByPipe($expr);
        $varPath = trim(array_shift($parts));

        $value = $this->resolveValue($varPath, $context);

        $filterCount = 0;
        foreach ($parts as $filter) {
            if (++$filterCount > self::FILTER_CHAIN_MAX) {
                $this->logError("Filter chain exceeds maximum depth (" . self::FILTER_CHAIN_MAX . ") on: {$expr}");
                break;
            }
            $value = $this->applyFilter(trim($filter), $value, $context);
        }

        return $value;
    }

    // ── Arithmetic ───────────────────────────────────────────────────

    public function evaluateArithmetic(string $expr, array $context): int|float|null
    {
        $tokens = $this->tokenizeArithExpr($expr);
        if ($tokens === null || count($tokens) === 0) {
            return null;
        }
        $hasOp = false;
        foreach ($tokens as $tok) {
            if (is_string($tok) && in_array($tok, ['+', '-', '*', '/', '%'], true)) {
                $hasOp = true;
                break;
            }
        }
        if (!$hasOp) {
            return null;
        }
        $pos = 0;
        $result = $this->exprAdd($tokens, $pos, $context);
        if ($result === null || $pos !== count($tokens)) {
            return null;
        }
        if (is_float($result) && $result == (int)$result) {
            return (int)$result;
        }
        return $result;
    }

    public function tokenizeArithExpr(string $expr): ?array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($expr);
        while ($i < $len) {
            $c = $expr[$i];
            if ($c === ' ') { $i++; continue; }
            if ($c === '(' || $c === ')' || in_array($c, ['+', '-', '*', '/', '%'], true)) {
                $tokens[] = $c;
                $i++;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $j = $i;
                while ($j < $len && (ctype_digit($expr[$j]) || $expr[$j] === '.')) { $j++; }
                $num = substr($expr, $i, $j - $i);
                $tokens[] = str_contains($num, '.') ? (float)$num : (int)$num;
                $i = $j;
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($expr[$j]) || $expr[$j] === '_' || $expr[$j] === '.')) { $j++; }
                $tokens[] = ['var', substr($expr, $i, $j - $i)];
                $i = $j;
                continue;
            }
            return null;
        }
        return $tokens;
    }

    private function exprAdd(array $tokens, int &$pos, array $context): int|float|null
    {
        $left = $this->exprMul($tokens, $pos, $context);
        if ($left === null) { return null; }
        $n = count($tokens);
        while ($pos < $n && ($tokens[$pos] === '+' || $tokens[$pos] === '-')) {
            $op = $tokens[$pos++];
            $right = $this->exprMul($tokens, $pos, $context);
            if ($right === null) { return null; }
            $left = $op === '+' ? $left + $right : $left - $right;
        }
        return $left;
    }

    private function exprMul(array $tokens, int &$pos, array $context): int|float|null
    {
        $left = $this->exprUnary($tokens, $pos, $context);
        if ($left === null) { return null; }
        $n = count($tokens);
        while ($pos < $n && in_array($tokens[$pos], ['*', '/', '%'], true)) {
            $op = $tokens[$pos++];
            $right = $this->exprUnary($tokens, $pos, $context);
            if ($right === null) { return null; }
            if ($op === '*') {
                $left = $left * $right;
            } elseif ($op === '/') {
                $left = $right != 0 ? $left / $right : 0;
            } else {
                $left = $right != 0 ? (int)$left % (int)$right : 0;
            }
        }
        return $left;
    }

    private function exprUnary(array $tokens, int &$pos, array $context): int|float|null
    {
        if ($pos < count($tokens) && $tokens[$pos] === '-') {
            $pos++;
            $val = $this->exprPrimary($tokens, $pos, $context);
            return $val !== null ? -$val : null;
        }
        return $this->exprPrimary($tokens, $pos, $context);
    }

    private function exprPrimary(array $tokens, int &$pos, array $context): int|float|null
    {
        if ($pos >= count($tokens)) { return null; }
        $tok = $tokens[$pos];
        if (is_int($tok) || is_float($tok)) {
            $pos++;
            return $tok;
        }
        if (is_array($tok)) {
            $pos++;
            $val = $this->resolveValue($tok[1], $context);
            return ($val !== null && is_numeric($val)) ? (float)$val : null;
        }
        if ($tok === '(') {
            $pos++;
            $val = $this->exprAdd($tokens, $pos, $context);
            if ($pos < count($tokens) && $tokens[$pos] === ')') {
                $pos++;
            }
            return $val;
        }
        return null;
    }

    // ── String concatenation ─────────────────────────────────────────

    public function evaluateConcat(string $expr, array $context): ?string
    {
        $parts = $this->splitByTilde($expr);
        if (count($parts) < 2) {
            return null;
        }
        $result = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            if (preg_match('/^["\'](.*)["\']$/', $part, $m)) {
                $result .= $m[1];
            } else {
                $resolved = $this->resolveValueWithFilters($part, $context);
                $result .= $resolved !== null ? (string)$resolved : '';
            }
        }
        return $result;
    }

    public function splitByTilde(string $expr): array
    {
        $parts = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;
        for ($i = 0, $len = strlen($expr); $i < $len; $i++) {
            $ch = $expr[$i];
            if ($ch === '\\' && ($inSingle || $inDouble) && $i + 1 < $len) {
                $current .= $ch . $expr[++$i];
                continue;
            }
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; $current .= $ch; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; $current .= $ch; continue; }
            if ($inSingle || $inDouble) { $current .= $ch; continue; }
            if ($ch === '~') {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    // ── Comparison ───────────────────────────────────────────────────

    public function evaluateComparison(string $expr, array $context): ?bool
    {
        if (preg_match('/^(.*?)\s*\|\|\s*(.*)$/', $expr, $m)) {
            $left = $this->evaluateComparison(trim($m[1]), $context);
            $right = $this->evaluateComparison(trim($m[2]), $context);
            if ($left !== null && $right !== null) { return $left || $right; }
        }
        if (preg_match('/^(.*?)\s*&&\s*(.*)$/', $expr, $m)) {
            $left = $this->evaluateComparison(trim($m[1]), $context);
            $right = $this->evaluateComparison(trim($m[2]), $context);
            if ($left !== null && $right !== null) { return $left && $right; }
        }

        if (str_starts_with($expr, '!')) {
            $inner = trim(substr($expr, 1));
            if ($inner !== '' && $inner[0] === '(' && $inner[-1] === ')') {
                $inner = trim(substr($inner, 1, -1));
            }
            $val = $this->evaluateComparison($inner, $context);
            return $val !== null ? !$val : null;
        }

        if (preg_match('/^([a-zA-Z_][\w.]*)$/', $expr, $m)) {
            $val = $this->resolveValue($m[1], $context);
            return $val !== null ? (bool)$val : null;
        }

        $ops = ['!==', '===', '!=', '==', '>=', '<=', '>', '<'];
        foreach ($ops as $op) {
            $parts = explode($op, $expr, 2);
            if (count($parts) !== 2) { continue; }
            $left = trim($parts[0]);
            $right = trim($parts[1]);

            if (!preg_match('/^(\$?\w[\w.]*)$/', $left, $lm)) { continue; }
            $leftVal = $this->resolveValue($lm[1], $context);

            if (preg_match('/^["\'](.*)["\']$/', $right, $rm)) {
                $rightVal = $rm[1];
            } elseif (is_numeric($right)) {
                $rightVal = $right + 0;
            } elseif (preg_match('/^(\$?\w[\w.]*)$/', $right, $rm)) {
                $rightVal = $this->resolveValue($rm[1], $context);
            } else {
                continue;
            }

            return match ($op) {
                '!==' => $leftVal !== $rightVal,
                '===' => $leftVal === $rightVal,
                '!=' => $leftVal != $rightVal,
                '==' => $leftVal == $rightVal,
                '>=' => $leftVal >= $rightVal,
                '<=' => $leftVal <= $rightVal,
                '>' => $leftVal > $rightVal,
                '<' => $leftVal < $rightVal,
                default => null,
            };
        }
        return null;
    }

    // ── Condition evaluation ─────────────────────────────────────────

    public function evaluateCondition(string $condition, array $context): bool
    {
        $condition = trim($condition);
        if ($condition === '') { return false; }

        if (preg_match('/^\((.+)\)$/', $condition, $pm)) {
            $inner = $pm[1];
            $depth = 0;
            $balanced = true;
            for ($ci = 0, $cl = strlen($inner); $ci < $cl; $ci++) {
                if ($inner[$ci] === '(') { $depth++; }
                elseif ($inner[$ci] === ')') { $depth--; if ($depth < 0) { $balanced = false; break; } }
            }
            if ($balanced && $depth === 0) {
                $condition = $inner;
            }
        }

        if (preg_match('/^!\s*(.+)$/', $condition, $nm)) {
            return !$this->evaluateCondition($nm[1], $context);
        }
        if (preg_match('/^not\s+(.+)$/i', $condition, $nm)) {
            return !$this->evaluateCondition($nm[1], $context);
        }

        if (preg_match('/^(.+?)\s+(and|&&)\s+(.+)$/i', $condition, $m)) {
            return $this->evaluateCondition($m[1], $context) && $this->evaluateCondition($m[3], $context);
        }
        if (preg_match('/^(.+?)\s+(or|\|\|)\s+(.+)$/i', $condition, $m)) {
            return $this->evaluateCondition($m[1], $context) || $this->evaluateCondition($m[3], $context);
        }

        if (preg_match('/^(.+?)\s*(===|!==|==|!=|>=|<=|>|<)\s*(.+)$/', $condition, $match)) {
            $left = $this->resolveConditionOperand(trim($match[1]), $context);
            $op = $match[2];
            $right = $this->resolveConditionOperand(trim($match[3]), $context);

            if ($op !== '===' && $op !== '!==' && is_numeric($left)) { $left = $left + 0; }
            if ($op !== '===' && $op !== '!==' && is_numeric($right)) { $right = $right + 0; }

            return match ($op) {
                '===' => $left === $right,
                '!==' => $left !== $right,
                '==' => $left == $right,
                '!=' => $left != $right,
                '>=' => $left >= $right,
                '<=' => $left <= $right,
                '>' => $left > $right,
                '<' => $left < $right,
                default => false,
            };
        }

        $value = $this->evaluateArithmetic($condition, $context);
        if ($value === null) {
            $value = $this->resolveValueWithFilters($condition, $context);
        }
        return !empty($value);
    }

    public function resolveConditionOperand(string $raw, array $context): mixed
    {
        if (preg_match('/^\((.+)\)$/', $raw, $pm)) {
            $inner = $pm[1];
            $depth = 0;
            $balanced = true;
            for ($ci = 0, $cl = strlen($inner); $ci < $cl; $ci++) {
                if ($inner[$ci] === '(') { $depth++; }
                elseif ($inner[$ci] === ')') { $depth--; if ($depth < 0) { $balanced = false; break; } }
            }
            if ($balanced && $depth === 0) {
                $raw = $inner;
            }
        }

        if (preg_match('/^["\'](.*)["\']\s*$/', $raw, $qm)) {
            return $qm[1];
        }

        $arith = $this->evaluateArithmetic($raw, $context);
        if ($arith !== null) { return $arith; }

        $resolved = $this->resolveValueWithFilters($raw, $context);
        if ($resolved !== null) { return $resolved; }

        if (is_numeric($raw)) { return $raw + 0; }

        return $raw;
    }

    // ── Ternary ──────────────────────────────────────────────────────

    public function evaluateTernary(string $expr, array $context): string
    {
        $qPos = strpos($expr, '?');
        if ($qPos === false) { return ''; }

        $condition = trim(substr($expr, 0, $qPos));
        $rest = substr($expr, $qPos + 1);

        $colonPos = $this->findTernaryColon($rest);
        if ($colonPos === false) { return ''; }

        $trueExpr = trim(substr($rest, 0, $colonPos));
        $falseExpr = trim(substr($rest, $colonPos + 1));

        $result = $this->evaluateCondition($condition, $context) ? $trueExpr : $falseExpr;

        if (preg_match('/^["\'](.*)["\']\s*$/', $result, $m)) {
            return htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        }

        $resolved = $this->resolveValueWithFilters($result, $context);
        if (is_scalar($resolved)) {
            return htmlspecialchars((string) $resolved, ENT_QUOTES, 'UTF-8');
        }

        if (is_numeric($result)) {
            return $result;
        }

        return htmlspecialchars($result, ENT_QUOTES, 'UTF-8');
    }

    // ── keyof ─────────────────────────────────────────────────────────

    public function resolveKeyof(string $expr): array
    {
        $expr = trim($expr);
        if ($expr === '') {
            $this->logError('keyof: empty expression');
            return [];
        }

        $dotPos = strrpos($expr, '.');
        if ($dotPos === false) {
            $entityType = $expr;
            $view = 'compact';
        } else {
            $entityType = substr($expr, 0, $dotPos);
            $view = substr($expr, $dotPos + 1);
        }

        if ($entityType === '') {
            $this->logError("keyof: invalid expression '{$expr}'");
            return [];
        }

        $resolverClass = 'Ikabud\\Kernel\\EntityContext\\EntityViewResolver';
        if (!class_exists($resolverClass, true)) {
            $this->logError("keyof: EntityViewResolver not available");
            return [];
        }

        $resolver = $resolverClass::getInstance();
        $contract = $resolver->viewContract($entityType, $view);

        if ($contract === null) {
            $this->logError("keyof: no contract for {$entityType}.{$view}");
            return [];
        }

        return $contract['fields'] ?? [];
    }

    // ── Filters ──────────────────────────────────────────────────────

    public function registerFilter(string $name, callable $callback): void
    {
        $this->filters[$name] = $callback;
    }

    public function applyFilter(string $filter, mixed $value, array $context): mixed
    {
        $parts = explode(':', $filter, 2);
        $filterName = trim($parts[0]);
        $rawArgs = isset($parts[1]) ? $this->splitByComma($parts[1]) : [];

        $positional = [];
        $named = [];
        foreach ($rawArgs as $arg) {
            $arg = trim($arg);
            if (preg_match('/^(\w+)\s*=\s*(.+)$/s', $arg, $m)) {
                $named[$m[1]] = $this->normalizeFilterArg($filterName, $m[2], $context);
            } else {
                $positional[] = $this->normalizeFilterArg($filterName, $arg, $context);
            }
        }

        if (isset($this->filters[$filterName])) {
            return ($this->filters[$filterName])($value, $positional, $named, $context);
        }

        return $value;
    }

    public function normalizeFilterArg(string $filterName, string $arg, array $context): mixed
    {
        $arg = trim($arg);
        if ($arg === '') { return ''; }

        if (preg_match('/^["\'](.*)["\']\s*$/', $arg, $matches)) {
            return $matches[1];
        }

        if ($filterName === 'default') {
            if (is_numeric($arg)) {
                return $arg + 0;
            }
            // Strip outer balanced parentheses before resolving sub-expression
            // e.g. default:(foo.bar|default:false) → default:foo.bar|default:false
            if (str_starts_with($arg, '(') && str_ends_with($arg, ')')) {
                $inner = trim(substr($arg, 1, -1));
                $pdepth = 0;
                $balanced = true;
                for ($pi = 0, $pl = strlen($inner); $pi < $pl; $pi++) {
                    if ($inner[$pi] === '(') { $pdepth++; }
                    elseif ($inner[$pi] === ')') { $pdepth--; if ($pdepth < 0) { $balanced = false; break; } }
                }
                if ($balanced && $pdepth === 0) {
                    $arg = $inner;
                }
            }
            return $this->resolveValueWithFilters($arg, $context);
        }

        return $arg;
    }

    // ── String helpers ───────────────────────────────────────────────

    public function hasEscapeFilter(string $expr, array $parsedFilterNames = []): bool
    {
        $escapeFilters = ['esc_html', 'esc_attr', 'esc_url', 'esc_js', 'json', 'url_encode', 'base64', 'nl2br'];
        foreach ($escapeFilters as $ef) {
            if (in_array($ef, $parsedFilterNames, true)) {
                return true;
            }
        }
        if (!empty($parsedFilterNames)) {
            return false;
        }
        if (!str_contains($expr, '|')) {
            return false;
        }
        $parts = $this->splitByPipe($expr);
        array_shift($parts);
        foreach ($parts as $filter) {
            $name = trim(explode(':', $filter, 2)[0]);
            if (in_array($name, $escapeFilters, true)) {
                return true;
            }
        }
        return false;
    }

    public function splitCallArgs(string $str): array
    {
        $parts = [];
        $cur = '';
        $inSingle = false;
        $inDouble = false;
        $depth = 0;
        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '\\' && ($inSingle || $inDouble) && $i + 1 < $len) {
                $cur .= $ch . $str[++$i];
                continue;
            }
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; $cur .= $ch; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; $cur .= $ch; continue; }
            if ($inSingle || $inDouble) { $cur .= $ch; continue; }
            if ($ch === '(' || $ch === '[') { $depth++; $cur .= $ch; continue; }
            if ($ch === ')' || $ch === ']') { $depth--; $cur .= $ch; continue; }
            if ($ch === ',' && $depth === 0) { $parts[] = trim($cur); $cur = ''; continue; }
            $cur .= $ch;
        }
        $t = trim($cur);
        if ($t !== '') { $parts[] = $t; }
        return $parts;
    }

    public function splitByPipe(string $expr): array
    {
        return $this->splitByChar($expr, '|');
    }

    public function splitByComma(string $expr): array
    {
        return $this->splitByChar($expr, ',');
    }

    public function splitByChar(string $expr, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;
        $depth = 0;

        for ($i = 0, $len = strlen($expr); $i < $len; $i++) {
            $ch = $expr[$i];
            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $current .= $ch;
                if ($i + 1 < $len) { $current .= $expr[++$i]; }
                continue;
            }
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; $current .= $ch; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; $current .= $ch; continue; }
            if ($inSingle || $inDouble) { $current .= $ch; continue; }
            // Track bracket/paren depth to avoid splitting inside nested groups
            if ($ch === '(' || $ch === '[') { $depth++; $current .= $ch; continue; }
            if ($ch === ')' || $ch === ']') { if ($depth > 0) $depth--; $current .= $ch; continue; }
            if ($ch === $delimiter && $depth === 0) { $parts[] = $current; $current = ''; continue; }
            $current .= $ch;
        }
        if ($current !== '') { $parts[] = $current; }
        return $parts;
    }

    public function findUnquotedChar(string $str, string $char, int $start = 0): int|false
    {
        $inSingle = false;
        $inDouble = false;

        for ($i = $start, $len = strlen($str); $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }
            if (!$inSingle && !$inDouble && $ch === $char) {
                return $i;
            }
        }
        return false;
    }

    /**
     * Find the unquoted => arrow operator in a string.
     * Returns the position of '=' (first char of =>), or false if not found.
     * Skips => inside single or double quotes.
     */
    public function findUnquotedArrow(string $str, int $start = 0): int|false
    {
        $inSingle = false;
        $inDouble = false;

        for ($i = $start, $len = strlen($str); $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }
            if (!$inSingle && !$inDouble && $ch === '=' && ($i + 1 < $len) && $str[$i + 1] === '>') {
                return $i;
            }
        }
        return false;
    }

    /**
     * Find the unquoted colon that separates true/false branches of a ternary.
     * Skips colons that are filter argument separators (preceded by a word character),
     * since those belong to filter specs like |default:'val', not to the ternary.
     */
    public function findTernaryColon(string $str, int $start = 0): int|false
    {
        $inSingle = false;
        $inDouble = false;

        for ($i = $start, $len = strlen($str); $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }
            if (!$inSingle && !$inDouble && $ch === ':') {
                // Skip : that is a filter arg separator (preceded by word char like filtername:)
                if ($i > 0 && preg_match('/[a-zA-Z0-9_]/', $str[$i - 1])) {
                    continue;
                }
                return $i;
            }
        }
        return false;
    }

    // ── Coercion ─────────────────────────────────────────────────────

    /**
     * Find the matching closing bracket, tracking nested brackets and quotes.
     */
    public function findMatchingBracket(string $str, string $open, string $close, int $start = 0): int|false
    {
        $inSingle = false;
        $inDouble = false;
        $depth = 1;

        for ($i = $start + 1, $len = strlen($str); $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '\\' && ($inSingle || $inDouble)) { $i++; continue; }
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; continue; }
            if ($inSingle || $inDouble) continue;
            if ($ch === $open) $depth++;
            if ($ch === $close) { $depth--; if ($depth === 0) return $i; }
        }
        return false;
    }

    public function coerceType(mixed $value, ?string $type, string $varName): mixed
    {
        if ($type === null || $type === '' || $type === 'mixed') {
            return $value;
        }

        if (str_starts_with($type, '?')) {
            if ($value === null || $value === '') {
                return null;
            }
            $type = substr($type, 1);
        }

        if (preg_match_all('/"([^"]*)"/', $type, $literalMatches) && str_contains($type, '|')) {
            $allowed = $literalMatches[1] ?? [];
            if (in_array((string)$value, $allowed, true)) {
                return (string)$value;
            }
            $fallback = (string)($allowed[0] ?? '');
            if ($this->strictMode) {
                $this->logError("[strict] Invalid literal for \${$varName}: expected {$type}");
            }
            return $fallback;
        }

        if ($this->strictMode) {
            $valid = match ($type) {
                'string' => is_string($value) || is_null($value) || is_numeric($value),
                'int', 'integer' => is_int($value) || (is_numeric($value) && $value == (int)$value),
                'float', 'number' => is_numeric($value),
                'bool', 'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
                'array' => is_array($value),
                'object' => is_object($value) || is_array($value),
                'null' => $value === null,
                'callable' => is_callable($value),
                default => true,
            };
            if (!$valid) {
                $actualType = gettype($value);
                $this->logError("[strict] Type mismatch for \${$varName}: expected {$type}, got {$actualType}");
            }
            return $value;
        }

        return match ($type) {
            'string' => $value !== null ? (string)$value : '',
            'int', 'integer' => (int)$value,
            'float', 'number' => (float)$value,
            'bool', 'boolean' => (bool)$value,
            'array' => is_array($value) ? $value : [$value],
            'object' => (object)$value,
            'null' => null,
            'callable' => is_callable($value) ? $value : null,
            default => $value,
        };
    }

    // ── Logging ──────────────────────────────────────────────────────

    private function logError(string $message): void
    {
        if ($this->logErrorCallback !== null) {
            ($this->logErrorCallback)($message);
        }
    }
}
