<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Types;

require_once __DIR__ . '/TypeAst.php';

/**
 * DiSyL 4.2 — TypeChecker.
 *
 * Static checker run after a template has been parsed. Collects every
 * variable reference in the template body — `{var}`, `{a.b.c}`, control-tag
 * heads (`{if x.y}`, `{for i in items}`, `{foreach list as x}`,
 * `{match expr}`), and validates each path against the declared context type
 * from the `{types}` block.
 *
 * Reports diagnostics with template name + 1-based line number. Caller
 * decides whether to render a banner (dev) or fail the request (strict).
 */
final class TypeChecker
{
    /** @var array<string, string> */
    private const RUNTIME_TYPE_ALIASES = [
        'string' => 'string',
        'int' => 'int',
        'integer' => 'int',
        'float' => 'float',
        'number' => 'number',
        'bool' => 'bool',
        'boolean' => 'bool',
    ];

    /** @var list<array{code:string, message:string, template:string, line:int}> */
    public array $diagnostics = [];

    /** @var array<string, TypeNode> */
    private array $env = [];
    private ?TypeNode $contextType = null;
    private string $templateName = '';

    /**
     * @return list<array{code:string, message:string, template:string, line:int}>
     */
    public function check(string $source, string $templateName = '<inline>'): array
    {
        $this->diagnostics = [];
        $this->templateName = $templateName;
        $this->env = [];
        $this->contextType = null;

        $stripped = $this->extractTypesBlock($source);
        if ($stripped === null) {
            // No types block → nothing to check (opt-in).
            return [];
        }
        [$body, $typesSource, $typesLine] = $stripped;

        $parsed = (new TypeParser())->parse($typesSource);
        foreach ($parsed['errors'] as $err) {
            $this->diagnostics[] = [
                'code'     => 'DISYL_TYPE_PARSE_ERROR',
                'message'  => $err,
                'template' => $this->templateName,
                'line'     => $typesLine,
            ];
        }
        $this->env = $parsed['types'];
        $this->contextType = $parsed['context'];

        if ($this->contextType === null) {
            $this->diagnostics[] = [
                'code'     => 'DISYL_TYPE_NO_CONTEXT',
                'message'  => '{types} block must declare `context: TYPE`',
                'template' => $this->templateName,
                'line'     => $typesLine,
            ];
            return $this->diagnostics;
        }

        $this->checkBody($body);
        return $this->diagnostics;
    }

    /**
     * Locate a single `{types} ... {/types}` block. Returns the source with
     * the block removed plus the block contents and 1-based starting line,
     * or null if no block is present.
     *
     * @return array{0:string,1:string,2:int}|null
     */
    private function extractTypesBlock(string $source): ?array
    {
        if (!preg_match('/\{types\s*\}(.*?)\{\/types\s*\}/s', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $offset = $m[0][1];
        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
        $stripped = substr($source, 0, $offset) . substr($source, $offset + strlen($m[0][0]));
        return [$stripped, $m[1][0], $line];
    }

    private function checkBody(string $body): void
    {
        // 1. Local bindings introduced by {for v in expr}, {foreach expr as v} or `as k => v`.
        //    We approximate scope as global (any binding seen anywhere is treated as known).
        //    This avoids false positives without running a full scope analysis.
        $locals = $this->collectLocalBindings($body);

        // 2. Walk every `{x.y.z}` reference and every control-tag head.
        $len = strlen($body);
        $line = 1;
        $i = 0;
        while ($i < $len) {
            $ch = $body[$i];
            if ($ch === "\n") { $line++; $i++; continue; }
            if ($ch !== '{') { $i++; continue; }

            // Find tag end at the same nesting depth (no nesting in DiSyL tags).
            $end = strpos($body, '}', $i + 1);
            if ($end === false) break;
            $raw = substr($body, $i + 1, $end - $i - 1);
            $i = $end + 1;
            $rawTrimmed = ltrim($raw);

            // Skip comments / verbatim / literal
            if ($rawTrimmed === '' || $rawTrimmed[0] === '*' || $rawTrimmed[0] === '!' || $rawTrimmed[0] === '#') continue;
            if (str_starts_with($rawTrimmed, '/')) continue;
            if (str_starts_with($rawTrimmed, 'verbatim') || str_starts_with($rawTrimmed, 'literal')) continue;
            if (str_starts_with($rawTrimmed, 'include') || str_starts_with($rawTrimmed, 'extends')) continue;
            if (str_starts_with($rawTrimmed, 'block') || str_starts_with($rawTrimmed, 'slot')) continue;
            if (str_starts_with($rawTrimmed, 'set ') || str_starts_with($rawTrimmed, 'set\t')) continue;
            if (str_starts_with($rawTrimmed, 'trans ') || str_starts_with($rawTrimmed, 'when ') || $rawTrimmed === 'when' || $rawTrimmed === 'default') continue;

            // Strip filter chain (`expr | filter`) — only check the head expr.
            $expr = $rawTrimmed;
            $pipePos = $this->findOuterPipe($expr);
            if ($pipePos !== false) {
                $expr = trim(substr($expr, 0, $pipePos));
            }

            // Recognise control heads and pull out the path expression.
            foreach (['if', 'for', 'foreach', 'each', 'match'] as $head) {
                if (str_starts_with($expr, $head . ' ') || $expr === $head) {
                    $expr = trim(substr($expr, strlen($head)));
                    if ($head === 'for') {
                        // {for v in PATH}
                        if (preg_match('/^\w+\s+in\s+(.+)$/', $expr, $m2)) {
                            $expr = trim($m2[1]);
                        } else {
                            continue 2;
                        }
                    }
                    if ($head === 'foreach' || $head === 'each') {
                        // {foreach PATH as v} | {foreach PATH as k => v}
                        if (preg_match('/^(.+?)\s+as\s+/', $expr, $m2)) {
                            $expr = trim($m2[1]);
                        } else {
                            continue 2;
                        }
                    }
                    break;
                }
            }

            // Ignore literals, assignments, calls — only check dotted-path references.
            if ($expr === '' || ctype_digit($expr[0]) || $expr[0] === "'" || $expr[0] === '"') continue;
            if (str_contains($expr, '(') || str_contains($expr, '=')) continue;

            $paths = $this->extractDottedPaths($expr);
            foreach ($paths as $path) {
                $head = $path[0];
                if (in_array($head, $locals, true)) continue;
                if (in_array($head, ['true', 'false', 'null', 'loop'], true)) continue;
                $this->checkPath($path, $line);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function collectLocalBindings(string $body): array
    {
        $locals = [];
        if (preg_match_all('/\{for\s+(\w+)\s+in\s+/', $body, $m)) {
            foreach ($m[1] as $name) $locals[] = $name;
        }
        if (preg_match_all('/\{foreach\s+\S.*?\s+as\s+(?:(\w+)\s*=>\s*)?(\w+)\s*\}/', $body, $m)) {
            foreach ($m[1] as $k) if ($k !== '') $locals[] = $k;
            foreach ($m[2] as $v) $locals[] = $v;
        }
        if (preg_match_all('/\{set\s+(\w+)(?:\s*:\s*[^=]+)?\s*=/', $body, $m)) {
            foreach ($m[1] as $name) $locals[] = $name;
        }
        return array_values(array_unique($locals));
    }

    /**
     * Pull dotted-path identifiers out of an expression while ignoring
     * string literals and operators. Returns each path as a list of segments.
     *
     * @return list<list<string>>
     */
    private function extractDottedPaths(string $expr): array
    {
        $paths = [];
        $len = strlen($expr);
        $i = 0;
        while ($i < $len) {
            $ch = $expr[$i];
            if ($ch === "'" || $ch === '"') {
                $i++;
                while ($i < $len && $expr[$i] !== $ch) {
                    if ($expr[$i] === '\\' && $i + 1 < $len) { $i += 2; continue; }
                    $i++;
                }
                $i++;
                continue;
            }
            if (ctype_alpha($ch) || $ch === '_') {
                $start = $i;
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_' || $expr[$i] === '.')) $i++;
                $raw = substr($expr, $start, $i - $start);
                $segs = explode('.', $raw);
                $segs = array_values(array_filter($segs, static fn(string $s) => $s !== ''));
                if ($segs !== []) $paths[] = $segs;
                continue;
            }
            $i++;
        }
        return $paths;
    }

    public static function normalizeRuntimeType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $type = strtolower(trim($type));
        return self::RUNTIME_TYPE_ALIASES[$type] ?? null;
    }

    public static function matchesRuntimeType(mixed $value, ?string $type): bool
    {
        $type = self::normalizeRuntimeType($type);
        if ($type === null) {
            return true;
        }

        return match ($type) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'bool' => is_bool($value),
            'int' => is_int($value),
            'float' => is_float($value),
            default => true,
        };
    }

    public static function describeRuntimeType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            is_string($value) => 'string',
            is_array($value) => 'array',
            is_object($value) => 'object',
            $value === null => 'null',
            default => gettype($value),
        };
    }

    /**
     * @param list<string> $path
     */
    private function checkPath(array $path, int $line): void
    {
        if ($this->contextType === null) return;
        $current = Subtype::resolve($this->contextType, $this->env);
        $consumed = [];
        foreach ($path as $segment) {
            $consumed[] = $segment;
            if ($current instanceof ObjectType) {
                $spec = $current->properties[$segment] ?? null;
                if ($spec === null) {
                    $this->diagnostics[] = [
                        'code'     => 'DISYL_TYPE_UNKNOWN_PROP',
                        'message'  => 'Property "' . implode('.', $consumed) . '" does not exist on context type',
                        'template' => $this->templateName,
                        'line'     => $line,
                    ];
                    return;
                }
                $current = Subtype::resolve($spec['type'], $this->env);
                continue;
            }
            if ($current instanceof ArrayType) {
                // Dotted access through arrays not supported at compile time
                // (foreach binding handles the element type at runtime).
                return;
            }
            if ($current instanceof PrimitiveType && ($current->name === 'any' || $current->name === 'unknown')) {
                return;
            }
            // Cannot index into this type.
            $this->diagnostics[] = [
                'code'     => 'DISYL_TYPE_BAD_INDEX',
                'message'  => 'Cannot read property "' . $segment . '" on non-object type ' . $current->describe(),
                'template' => $this->templateName,
                'line'     => $line,
            ];
            return;
        }
    }

    private function findOuterPipe(string $expr): int|false
    {
        $len = strlen($expr);
        $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $expr[$i];
            if ($c === '(') $depth++;
            elseif ($c === ')') $depth--;
            elseif ($c === '|' && $depth === 0) {
                if ($i + 1 < $len && $expr[$i + 1] === '|') { $i++; continue; }
                return $i;
            } elseif ($c === "'" || $c === '"') {
                $i++;
                while ($i < $len && $expr[$i] !== $c) {
                    if ($expr[$i] === '\\' && $i + 1 < $len) { $i += 2; continue; }
                    $i++;
                }
            }
        }
        return false;
    }
}
