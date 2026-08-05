<?php
/**
 * DiSyL Macro Processor
 *
 * Extracted from TemplateEngine (D8 refactor). Handles {macro} definition
 * extraction and {call ...} expansion with parameter substitution. The
 * engine's compile / value-resolution / logging helpers are injected as
 * closures so this class stays decoupled from the TemplateEngine internals.
 *
 * @package Ikabud\Kernel\DiSyL\Component
 */

namespace Ikabud\Kernel\DiSyL\Component;

final class MacroProcessor
{
    /** @var array<string, array{params: array, body: string}> */
    private array $macros = [];

    /** @var callable(string, array): string */
    private $compile;
    /** @var callable(string, array): mixed */
    private $resolveValue;
    /** @var callable(string, array): mixed */
    private $resolveValueWithFilters;
    /** @var callable(string): void */
    private $logError;

    public function __construct(
        callable $compile,
        callable $resolveValue,
        callable $resolveValueWithFilters,
        callable $logError
    ) {
        $this->compile = $compile;
        $this->resolveValue = $resolveValue;
        $this->resolveValueWithFilters = $resolveValueWithFilters;
        $this->logError = $logError;
    }

    /** Reset all extracted macros (top-level compile start). */
    public function reset(): void
    {
        $this->macros = [];
    }

    /** Whether any macros have been extracted. */
    public function hasMacros(): bool
    {
        return $this->macros !== [];
    }
/**
     * Extract {macro name(params)}...{/macro} definitions from template content.
     *
     * Each macro is stored in $this->macros keyed by name. The macro body
     * is kept as raw template text; {paramName} patterns in the body are
     * substituted at call time via expandMacroCalls().
     *
     * Macro definitions are removed from the template — they produce no
     * output on their own.
     */
    public function extractMacros(string $content, bool $merge = false): string
    {
        // Reset or preserve existing macros
        if (!$merge) {
            $this->macros = [];
        }

        return preg_replace_callback(
            '/\{macro\s+(\w+)\s*\(([^)]*)\)\}(.*?)\{\/macro\}/s',
            function (array $m): string {
                $name = $m[1];
                $paramsRaw = trim($m[2]);
                $body = $m[3];

                // Parse parameter list: "param1, param2 = default"
                $params = $this->parseMacroParamList($paramsRaw);
                $this->macros[$name] = ['params' => $params, 'body' => $body];

                // Remove macro definition from output
                return '';
            },
            $content
        );
    }

    /**
     * Expand {call name(arg1, arg2)} into macro body with parameter substitution.
     *
     * Resolves call arguments through the variable context first, then
     * substitutes {paramName} patterns in the macro body with the resolved
     * argument values.  Recursively re-processes the expanded body for
     * nested macros, variables, and control structures.
     */
    public function expandMacroCalls(string $content, array $context): string
    {
        return preg_replace_callback(
            '/\{call\s+(\w+)\s*(?:\(([^)]*)\))?\}/',
            function (array $m) use ($context): string {
                $name = $m[1];
                $argsRaw = isset($m[2]) ? trim($m[2]) : '';

                if (!isset($this->macros[$name])) {
                    ($this->logError)("DISYL_MACRO_NOT_FOUND: {$name}");
                    return '';
                }

                $macro = $this->macros[$name];
                $body = $macro['body'];
                $params = $macro['params'];

                // Parse call arguments
                $callArgs = $this->parseCallArgList($argsRaw, $context);

                // Build substitution map: paramName → resolved value
                $subs = [];
                $paramNames = array_keys($params);
                foreach ($paramNames as $i => $pName) {
                    $default = $params[$pName]; // null = required
                    $value = $callArgs[$i] ?? $callArgs[$pName] ?? null;
                    if ($value === null || $value === '') {
                        if ($default !== null) {
                            $value = $default;
                        } elseif ($value === null) {
                            $value = ''; // missing required param → empty
                        }
                    }
                    // Resolve any variables in the value
                    if ($value !== '' && $value !== null) {
                        $resolved = ($this->resolveValue)($value, $context);
                        $value = is_string($resolved) ? $resolved : (string)$value;
                    }
                    $subs['{' . $pName . '}'] = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                }

                // Substitute {paramName} patterns in macro body
                $expanded = str_replace(array_keys($subs), array_values($subs), $body);

                // Recurse: the expanded body may contain variables, calls, control flow
                return ($this->compile)($expanded, $context);
            },
            $content
        );
    }

    /**
     * Parse a macro parameter list string into a map of name → default.
     * Parameters without defaults have null as their value (required).
     */
    private function parseMacroParamList(string $raw): array
    {
        $params = [];
        if ($raw === '') {
            return $params;
        }
        $parts = explode(',', $raw);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            if (str_contains($part, '=')) {
                [$name, $default] = explode('=', $part, 2);
                $params[trim($name)] = trim($default, " \t\n\r\0\x0B\"'");
            } else {
                $params[$part] = null;
            }
        }
        return $params;
    }

    /**
     * Parse a call argument list string into an array of resolved values.
     * Supports: positional args "val1, val2" and named refs.
     */
    private function parseCallArgList(string $raw, array $context): array
    {
        $args = [];
        if ($raw === '') {
            return $args;
        }
        // Split on commas, respecting quoted strings
        $parts = $this->splitMacroCallArgs($raw);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            // Quoted string literal
            if ((str_starts_with($part, '"') && str_ends_with($part, '"')) ||
                (str_starts_with($part, "'") && str_ends_with($part, "'"))) {
                $args[] = substr($part, 1, -1);
                continue;
            }
            // Filter expression (contains |)
            if (str_contains($part, '|')) {
                $resolved = ($this->resolveValueWithFilters)($part, $context);
                $args[] = is_scalar($resolved) ? (string)$resolved : $part;
                continue;
            }
            // Numeric literal
            if (is_numeric($part)) {
                $args[] = $part;
                continue;
            }
            // Variable name or dotted path
            if (preg_match('/^[a-zA-Z_][\w.]*$/', $part)) {
                $resolved = ($this->resolveValue)($part, $context);
                $args[] = is_scalar($resolved) ? (string)$resolved : $part;
            } else {
                $args[] = $part;
            }
        }
        return $args;
    }

    /**
     * Split call arguments on commas, respecting quoted strings.
     */
    private function splitMacroCallArgs(string $raw): array
    {
        $parts = [];
        $buf = '';
        $inSingle = false;
        $inDouble = false;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($ch === '\\' && $i + 1 < $len) { $buf .= $ch . $raw[++$i]; continue; }
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; $buf .= $ch; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; $buf .= $ch; continue; }
            if ($ch === ',' && !$inSingle && !$inDouble) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '') { $parts[] = $buf; }
        return $parts;
    }
    
    
}
