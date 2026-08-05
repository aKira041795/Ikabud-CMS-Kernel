<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Component;

/**
 * IncludeResolver — processes {include} tags (self-closing and block forms).
 *
 * Extracted from TemplateEngine as part of the D8 decomposition. Fully
 * decoupled via injected closures: the engine supplies compile(),
 * resolveTemplatePath(), parseInlineObject(), resolveValueWithFilters(),
 * logError(), and source reading (which now flows through SourceCache).
 * Owns its own include-stack for circular-include detection.
 *
 * Supported forms:
 *   {include "name"}                        — no params
 *   {include "name" with: {k: v}}           — with: inline object
 *   {include "name" key=value key2=value2}  — key=value params
 *   {include "name" key=val} ... {/include} — block: body becomes page_body
 */
final class IncludeResolver
{
    /** @var int Upper bound on include passes per content blob (guards runaway nesting) */
    private const MAX_INCLUDE_ITERATIONS = 20;

    /** @var array<string, true> Include stack for circular-include detection (keyed by real path) */
    private array $includeStack = [];

    /**
     * @param callable(string, array): string $compile                Compile DiSyL source to output
     * @param callable(string): string        $resolveTemplatePath    Resolve a template name to a filesystem path
     * @param callable(string, array): array  $parseInlineObject      Parse a {k: v} inline object literal
     * @param callable(string, array): mixed  $resolveValueWithFilters Resolve an expression through the filter chain
     * @param callable(string): void          $logError               Route an engine-level error log entry
     * @param callable(string): string|false  $readIncludeSource      Read include source via SourceCache
     */
    public function __construct(
        private $compile,
        private $resolveTemplatePath,
        private $parseInlineObject,
        private $resolveValueWithFilters,
        private $logError,
        private $readIncludeSource,
    ) {
    }

    /** Clear per-request state (called by TemplateEngine::reset()). */
    public function reset(): void
    {
        $this->includeStack = [];
    }

    /**
     * Process all {include ...} tags in content. Returns content unchanged
     * when no include tags are present.
     */
    public function processIncludes(string $content, array $context): string
    {
        if (!str_contains($content, '{include ')) {
            return $content;
        }

        $iteration = 0;

        while ($iteration < self::MAX_INCLUDE_ITERATIONS) {
            $result = $this->processNextInclude($content, $context);
            if ($result === null) {
                break;
            }
            $content = $result;
            $iteration++;
        }

        return $content;
    }

    /**
     * Find and process the next {include ...} tag in content, using depth-aware
     * brace matching so nested {k: v} map literals in params are handled correctly.
     * Returns modified content, or null if no include tag is found.
     */
    private function processNextInclude(string $content, array $context): ?string
    {
        $len = strlen($content);
        $pos = 0;

        while ($pos < $len) {
            // Look for {include "..."
            if ($pos + 9 > $len) break;

            $brace = strpos($content, '{include ', $pos);
            if ($brace === false) {
                return null;
            }

            // Find the opening quote after {include "
            $q1 = strpos($content, '"', $brace + 9);
            if ($q1 === false || $q1 >= $len) {
                $pos = $brace + 1;
                continue;
            }

            // Find the closing quote and extract template name
            $q2 = strpos($content, '"', $q1 + 1);
            if ($q2 === false) {
                $pos = $brace + 1;
                continue;
            }

            $templateName = substr($content, $q1 + 1, $q2 - $q1 - 1);

            // Now find the matching closing } with depth-aware scanning
            // (params may contain nested {k: v} map literals)
            $tagClose = $brace + 1; // position after the opening {
            $tagDepth = 1;

            while ($tagClose < $len && $tagDepth > 0) {
                $ch = $content[$tagClose];
                if ($ch === '{') {
                    $tagDepth++;
                } elseif ($ch === '}') {
                    $tagDepth--;
                }
                $tagClose++;
            }

            if ($tagDepth !== 0) {
                $pos = $brace + 1;
                continue;
            }

            $paramsEnd = $tagClose - 1; // position of matching }
            $paramsStr = substr($content, $q2 + 1, $paramsEnd - $q2 - 1);

            // Check if {/include} follows the closing } (with optional whitespace)
            $restStart = $tagClose;
            while ($restStart < $len && ($content[$restStart] === ' ' || $content[$restStart] === "\t" || $content[$restStart] === "\n" || $content[$restStart] === "\r")) {
                $restStart++;
            }
            $isBlock = false;
            $bodyContent = null;
            $blockEnd = -1;

            if (substr($content, $restStart, 10) === '{/include}') {
                $isBlock = false; // empty block body
                $blockEnd = $restStart + 10;
            } elseif (preg_match('/^((?:(?!\{include\s|\{\/include\}).)*?)\s*\{\/include\}/s', substr($content, $restStart), $bm)) {
                $isBlock = true;
                $bodyContent = $bm[1];
                $blockEnd = $restStart + strlen($bm[0]);
            }

            $includeResult = $this->processIncludeTag($templateName, $paramsStr, $context, $bodyContent);

            // Replace from the opening { to the end of the include tag (or block)
            $replaceEnd = $isBlock ? $blockEnd : $tagClose;
            $content = substr_replace($content, $includeResult, $brace, $replaceEnd - $brace);

            return $content;
        }

        return null;
    }

    /**
     * Process a single include tag — resolve template, merge params, compile.
     */
    private function processIncludeTag(string $templateName, string $paramsStr, array $context, ?string $bodyContent): string
    {
        $includePath = ($this->resolveTemplatePath)($templateName);
        if (!file_exists($includePath)) {
            ($this->logError)("Include not found: {$templateName}");
            return $bodyContent ?? '';
        }

        // Circular include detection
        $realPath = realpath($includePath) ?: $includePath;
        if (isset($this->includeStack[$realPath])) {
            ($this->logError)("Circular include detected: {$templateName}");
            return $bodyContent ?? '';
        }
        $this->includeStack[$realPath] = true;

        $includeContext = $context;

        // Parse params: both with: {...} and key=value
        $params = $this->parseIncludeParams($paramsStr, $context);
        if ($params !== []) {
            $includeContext = array_merge($context, $params);
        }

        // Block include: body content becomes page_body (compiled as DiSyL first)
        if ($bodyContent !== null) {
            $includeContext['page_body'] = ($this->compile)(trim($bodyContent), $includeContext);
        }

        $includeSource = ($this->readIncludeSource)($includePath);
        if ($includeSource === false) {
            unset($this->includeStack[$realPath]);
            ($this->logError)("Failed to read include: {$templateName}");
            return $bodyContent ?? '';
        }

        $result = ($this->compile)($includeSource, $includeContext);
        unset($this->includeStack[$realPath]);
        return $result;
    }

    /**
     * Parse include params string into key-value array.
     * Supports: with: {k: v, ...} and key=value key2=value2
     */
    private function parseIncludeParams(string $paramsStr, array $context): array
    {
        $paramsStr = trim($paramsStr);
        if ($paramsStr === '') {
            return [];
        }

        $result = [];

        // with: {...} syntax — use depth-aware brace matching for nested maps
        if (preg_match('/^with:?\s*(\{)/', $paramsStr, $m)) {
            $objStart = strpos($paramsStr, $m[1]);
            if ($objStart !== false) {
                $len = strlen($paramsStr);
                $depth = 0;
                $objEnd = -1;
                for ($i = $objStart; $i < $len; $i++) {
                    if ($paramsStr[$i] === '{') {
                        $depth++;
                    } elseif ($paramsStr[$i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $objEnd = $i + 1;
                            break;
                        }
                    }
                }
                if ($objEnd > 0) {
                    $objectStr = substr($paramsStr, $objStart, $objEnd - $objStart);
                    $result = ($this->parseInlineObject)($objectStr, $context);
                    // Also check for key=value params after the with: block
                    $rest = trim(substr($paramsStr, $objEnd));
                    if ($rest !== '') {
                        $kv = $this->parseKeyValueParams($rest, $context);
                        $result = array_merge($result, $kv);
                    }
                    return $result;
                }
            }
        }

        // key=value syntax only
        return $this->parseKeyValueParams($paramsStr, $context);
    }

    /**
     * Parse space-separated key=value pairs using a character-by-character
     * scanner that correctly handles quoted strings, nested braces, and
     * filter expressions like val|default:'Hello World'.
     */
    private function parseKeyValueParams(string $str, array $context): array
    {
        $result = [];
        $len = strlen($str);
        $i = 0;

        while ($i < $len) {
            // Skip whitespace
            while ($i < $len && ($str[$i] === ' ' || $str[$i] === "\t" || $str[$i] === "\n")) {
                $i++;
            }
            if ($i >= $len) break;

            // Read key (up to '=')
            $keyStart = $i;
            while ($i < $len && $str[$i] !== '=' && $str[$i] !== ' ') {
                $i++;
            }
            $key = substr($str, $keyStart, $i - $keyStart);
            if ($key === '') break;

            // Skip whitespace before '='
            while ($i < $len && ($str[$i] === ' ' || $str[$i] === "\t")) {
                $i++;
            }
            if ($i >= $len || $str[$i] !== '=') break;
            $i++; // skip '='

            // Skip whitespace after '='
            while ($i < $len && ($str[$i] === ' ' || $str[$i] === "\t")) {
                $i++;
            }
            if ($i >= $len) break;

            // Read value — scan until unquoted/unbraced space or end
            $valStart = $i;
            $depth = 0;
            $quote = null;

            while ($i < $len) {
                $c = $str[$i];

                if ($quote !== null) {
                    if ($c === '\\' && $i + 1 < $len) {
                        $i += 2; continue;
                    }
                    if ($c === $quote) {
                        $quote = null;
                    }
                    $i++;
                    continue;
                }

                if ($c === '"' || $c === "'") {
                    $quote = $c;
                    $i++;
                    continue;
                }

                if ($c === '{') {
                    $depth++;
                    $i++;
                    continue;
                }

                if ($c === '}') {
                    if ($depth > 0) {
                        $depth--;
                        $i++;
                        continue;
                    }
                    // Unnested '}' at top level — end of value
                    // But don't consume it; it belongs to the include tag closing
                    break;
                }

                if ($c === ' ' || $c === "\t" || $c === "\n") {
                    if ($depth === 0) break;
                }

                $i++;
            }

            $rawValue = substr($str, $valStart, $i - $valStart);

            // Resolve the value
            if (str_starts_with($rawValue, '{') && str_ends_with($rawValue, '}')) {
                $result[$key] = ($this->parseInlineObject)($rawValue, $context);
            } elseif (preg_match('/^["\']/', $rawValue)) {
                $result[$key] = trim($rawValue, ' "\'');
            } else {
                $result[$key] = ($this->resolveValueWithFilters)($rawValue, $context);
            }
        }

        return $result;
    }
}
