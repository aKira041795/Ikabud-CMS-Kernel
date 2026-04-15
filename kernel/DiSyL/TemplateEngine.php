<?php
/**
 * DiSyL Template Engine v3.0 - Robust Token-Based Implementation
 * 
 * A declarative template engine with proper handling of nested structures,
 * comprehensive error handling, output caching, and auto-escaping.
 * 
 * v4.0.0 changes:
 * - {verbatim}...{/verbatim}: truly inert block, extracted before all processing
 * - {literal} fixed: now extracted per-compile() call so it works inside loops
 * - <script> blocks: full control structure support ({if}, {foreach}, {for}),
 *   not just variable resolution. JS curly braces protected via temporary markers.
 * - |json filter: outputs raw by default (no HTML-escaping)
 * - |default filter: correctly handles null from unresolved nested dot paths while preserving explicit false
 * 
 * v3.0.0 changes:
 * - Arithmetic expressions: {page + 1}, {total - count}, {price * qty}, {x / y}, {x % y}
 * - Ternary expressions: {condition ? 'yes' : 'no'} in variable output
 * - Local variable assignment: {set name = expression}
 * - Fixed parseIfBranches to correctly skip nested {if} blocks
 * - Fixed quoted string regex in evaluateCondition
 * - Arithmetic in conditions: {if page + 1 > total}, {if count - 1 == 0}
 * 
 * v2.2.0 changes:
 * - Script-aware compilation: <script> blocks auto-extracted before control
 *   structure processing. Template {variables} inside scripts still resolve.
 * 
 * v2.1.0 changes:
 * - Output caching, auto-escape, per-request in-memory cache
 * 
 * @package Ikabud\Kernel\DiSyL
 * @version 4.0.0
 */

namespace Ikabud\Kernel\DiSyL;

class TemplateEngine
{
    private string $templateDir;
    private string $cacheDir;
    private bool $cacheEnabled;
    private bool $debug = false;
    private bool $compiledMode = false;
    private ?Compiler\TemplateCache $compiledCache = null;
    private array $components = [];
    private array $filters = [];
    private array $globals = [];
    private array $errors = [];
    
    public function __construct(string $templateDir, string $cacheDir, bool $cacheEnabled = true)
    {
        $this->templateDir = rtrim($templateDir, '/');
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->cacheEnabled = $cacheEnabled;
        
        $this->registerDefaultFilters();
        $this->registerDefaultComponents();
    }
    
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * Enable the opt-in compiled template mode.
     *
     * When enabled and the v4 Compiler pipeline is available, render() will
     * attempt to use pre-compiled PHP classes via TemplateCache before falling
     * back to the interpreted pipeline.  This is a no-op when the v4 Parser
     * class does not exist.
     */
    public function enableCompiledMode(bool $enable = true): void
    {
        $this->compiledMode = $enable;
        if ($enable && $this->compiledCache === null) {
            // Only instantiate if the v4 pipeline is loadable
            if (class_exists(Compiler\TemplateCache::class, true)) {
                try {
                    $this->compiledCache = new Compiler\TemplateCache(
                        $this->cacheDir . '/compiled',
                        $this->debug
                    );
                } catch (\Throwable $e) {
                    // Pipeline not ready (missing v4 Parser, etc.) — stay interpreted
                    $this->compiledMode = false;
                    $this->logError('Compiled mode unavailable: ' . $e->getMessage());
                }
            } else {
                $this->compiledMode = false;
            }
        }
    }

    public function isCompiledMode(): bool
    {
        return $this->compiledMode && $this->compiledCache !== null;
    }
    
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    public function setGlobals(array $globals): void
    {
        $this->globals = array_merge($this->globals, $globals);
    }
    
    /** @var array In-memory cache of compiled output per request */
    private array $outputCache = [];

    /** @var array<string, string> Per-request template source cache */
    private array $templateSourceCache = [];

    /** Maximum number of entries in the in-memory output cache */
    private const OUTPUT_CACHE_MAX = 200;

    /** Maximum number of template source entries cached per request */
    private const TEMPLATE_SOURCE_CACHE_MAX = 100;

    /** Maximum nesting depth for the fast output-cache key path before falling back to serialize() */
    private const OUTPUT_CACHE_KEY_FAST_DEPTH = 8;

    /** Maximum number of ancestor templates allowed in an {extends} chain */
    private const EXTENDS_CHAIN_MAX = 20;

    /** Maximum output size in bytes (5 MB default — prevents runaway templates) */
    private const MAX_OUTPUT_BYTES = 5 * 1024 * 1024;
    
    public function render(string $template, array $context = []): string
    {
        $this->errors = [];
        $templatePath = $this->resolveTemplatePath($template);
        
        if (!file_exists($templatePath)) {
            $this->logError("Template not found: {$template}");
            throw new \RuntimeException("Template not found: {$template}");
        }

        // Compiled-mode fast path: use pre-compiled PHP class when available
        if ($this->compiledMode && $this->compiledCache !== null) {
            try {
                $compiled = $this->compiledCache->get($templatePath);
                $mergedContext = array_merge($this->globals, $context);
                $result = $compiled->render($mergedContext);
                if (strlen($result) > self::MAX_OUTPUT_BYTES) {
                    $this->logError("Template output exceeds maximum size (" . self::MAX_OUTPUT_BYTES . " bytes): {$template}");
                    throw new \RuntimeException("Template output exceeds maximum allowed size");
                }
                return $result;
            } catch (\RuntimeException $e) {
                throw $e; // re-throw size limit errors
            } catch (\Throwable $e) {
                // Compiled path failed — fall through to interpreted path
                $this->logError("Compiled render failed, falling back: " . $e->getMessage());
            }
        }
        
        $content = $this->readTemplateSource($templatePath);
        if ($content === false) {
            $this->logError("Failed to read template: {$template}");
            throw new \RuntimeException("Failed to read template: {$template}");
        }
        $context = array_merge($this->globals, $context);
        
        // In-memory cache for repeated renders within same request (e.g., HTMX partials)
        if ($this->cacheEnabled) {
            $memKey = $this->buildOutputCacheKey($templatePath, $context);
            if (isset($this->outputCache[$memKey])) {
                return $this->outputCache[$memKey];
            }
            
            $result = $this->compile($content, $context);

            if (strlen($result) > self::MAX_OUTPUT_BYTES) {
                $this->logError("Template output exceeds maximum size (" . self::MAX_OUTPUT_BYTES . " bytes): {$template}");
                throw new \RuntimeException("Template output exceeds maximum allowed size");
            }

            // Evict oldest entry when cache is full to bound memory growth
            if (count($this->outputCache) >= self::OUTPUT_CACHE_MAX) {
                reset($this->outputCache);
                unset($this->outputCache[key($this->outputCache)]);
            }
            $this->outputCache[$memKey] = $result;
            return $result;
        }
        
        $result = $this->compile($content, $context);

        if (strlen($result) > self::MAX_OUTPUT_BYTES) {
            $this->logError("Template output exceeds maximum size (" . self::MAX_OUTPUT_BYTES . " bytes): {$template}");
            throw new \RuntimeException("Template output exceeds maximum allowed size");
        }

        return $result;
    }

    private function buildOutputCacheKey(string $templatePath, array $context): string
    {
        $fastFingerprint = $this->tryBuildFastContextFingerprint($context);
        if ($fastFingerprint !== null) {
            return $templatePath . '|' . $fastFingerprint;
        }

        return $templatePath . '|' . md5(serialize($context));
    }

    private function tryBuildFastContextFingerprint(array $context): ?string
    {
        $hash = hash_init('md5');
        if (!$this->hashContextValue($hash, $context, 0)) {
            return null;
        }

        return hash_final($hash);
    }

    private function hashContextValue($hash, mixed $value, int $depth): bool
    {
        if ($depth > self::OUTPUT_CACHE_KEY_FAST_DEPTH) {
            return false;
        }

        if ($value === null || is_scalar($value)) {
            hash_update($hash, serialize($value));
            return true;
        }

        if (is_array($value)) {
            hash_update($hash, 'a' . count($value) . '{');
            foreach ($value as $key => $item) {
                if (!$this->hashContextValue($hash, $key, $depth + 1)) {
                    return false;
                }
                if (!$this->hashContextValue($hash, $item, $depth + 1)) {
                    return false;
                }
            }
            hash_update($hash, '}');
            return true;
        }

        if ($value instanceof \DateTimeInterface) {
            hash_update($hash, 'dt:' . get_class($value) . ':' . $value->format(\DateTimeInterface::ATOM));
            return true;
        }

        if ($value instanceof \JsonSerializable) {
            hash_update($hash, 'js:' . get_class($value) . '{');
            $ok = $this->hashContextValue($hash, $value->jsonSerialize(), $depth + 1);
            hash_update($hash, '}');
            return $ok;
        }

        if ($value instanceof \Stringable) {
            hash_update($hash, 'st:' . get_class($value) . ':' . (string)$value);
            return true;
        }

        if ($value instanceof \UnitEnum) {
            hash_update($hash, 'en:' . get_class($value) . ':' . $value->name);
            return true;
        }

        if ($value instanceof \Closure || is_resource($value)) {
            return false;
        }

        if (is_object($value)) {
            try {
                $serialized = serialize($value);
            } catch (\Throwable $e) {
                return false;
            }

            hash_update($hash, 'ob:' . $serialized);
            return true;
        }

        return false;
    }
    
    public function renderString(string $content, array $context = []): string
    {
        $this->errors = [];
        $context = array_merge($this->globals, $context);
        return $this->compile($content, $context);
    }
    
    /**
     * Main compilation pipeline
     */
    private function compile(string $content, array $context): string
    {
        if (!str_contains($content, '{') && stripos($content, '<script') === false) {
            return $content;
        }

        // 0. Extract {verbatim}...{/verbatim} blocks — truly inert, restored last
        $verbatims = [];
        $content = preg_replace_callback('/\{verbatim\}(.*?)\{\/verbatim\}/s', function($match) use (&$verbatims) {
            $key = '___VERBATIM_' . count($verbatims) . '___';
            $verbatims[$key] = $match[1];
            return $key;
        }, $content);
        
        // 1. Remove comments first
        $content = $this->removeComments($content);
        
        // 2. Process extends/layouts (merges child blocks into layout)
        $content = $this->processExtends($content, $context);
        
        // 3. Remove comments again (layout may have comments)
        $content = $this->removeComments($content);
        
        // 4. Process blocks (standalone)
        $content = $this->processBlocks($content, $context);
        
        // 4b. Extract <script> blocks and process them with full DiSyL support.
        //     JS curly braces that are NOT DiSyL tags are protected by temporarily
        //     converting them to markers before control structure processing.
        $scripts = [];
        $content = preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/si', function($match) use (&$scripts, $context) {
            $attrs = $match[1];
            $body = $match[2];
            
            // Resolve DiSyL variables in tag attributes (e.g. src="{base_url}/...")
            $attrs = $this->processVariables($attrs, $context);
            
            // Compile the script body with full DiSyL support
            $body = $this->compileScriptBody($body, $context);
            
            $key = '___SCRIPT_' . count($scripts) . '___';
            $scripts[$key] = '<script' . $attrs . '>' . $body . '</script>';
            return $key;
        }, $content);
        
        // 5. Extract {literal}...{/literal} blocks — after extends/blocks but before
        //    control structures, so they work correctly inside loop bodies
        $literals = [];
        $content = preg_replace_callback('/\{literal\}(.*?)\{\/literal\}/s', function($match) use (&$literals) {
            $key = '___LITERAL_' . count($literals) . '___';
            $literals[$key] = $match[1];
            return $key;
        }, $content);
        
        // 6. Process {set var = expr} assignments (mutates context)
        $content = $this->processSetStatements($content, $context);
        
        // 7. Process control structures (if/for/foreach) - token-based for proper nesting
        $content = $this->processControlStructures($content, $context);
        
        // 8. Process includes
        if (str_contains($content, '{include ')) {
            $content = $this->processIncludes($content, $context);
        }
        
        // 9. Process components
        if (str_contains($content, '{ikb_') || str_contains($content, '{island')) {
            $content = $this->processComponents($content, $context);
        }
        
        // 10. Process remaining variables (including arithmetic and ternary expressions)
        if (str_contains($content, '{')) {
            $content = $this->processVariables($content, $context);
        }
        
        // 11. Restore {literal} blocks (raw, no processing)
        if (!empty($literals)) {
            $content = str_replace(array_keys($literals), array_values($literals), $content);
        }
        
        // 12. Restore <script> blocks (already fully compiled)
        if (!empty($scripts)) {
            $content = str_replace(array_keys($scripts), array_values($scripts), $content);
        }
        
        // 13. Restore {verbatim} blocks last (completely raw)
        if (!empty($verbatims)) {
            $content = str_replace(array_keys($verbatims), array_values($verbatims), $content);
        }
        
        return $content;
    }
    
    /**
     * Compile a <script> body with full DiSyL support.
     *
     * Strategy: protect JS curly braces that are NOT DiSyL tags, then run
     * the full compilation pipeline, then restore JS curlies.
     *
     * DiSyL tags are identified by patterns like:
     *   {if ...}, {/if}, {foreach ...}, {/foreach}, {for ...}, {/for},
     *   {each ...}, {/each}, {else}, {elseif ...}, {set ...},
     *   {include ...}, {literal}, {/literal}, {verbatim}, {/verbatim},
     *   {variable}, {variable | filter}, {variable.path}, {expr ? a : b}
     *
     * Everything else (JS object literals, arrow functions, etc.) is protected.
     */
    private function compileScriptBody(string $body, array $context): string
    {
        // Pattern matching DiSyL tags — opening/closing control structures,
        // variables (letter/underscore start), filters, set, include, etc.
        $disylPattern = '/\{(?:'             // Opening brace followed by:
            . '\/(?:if|for|foreach|each|literal|verbatim)\}'  // Closing tags
            . '|(?:if|elseif|for|foreach|each|set|include|literal|verbatim|else)\s' // Opening tags with space
            . '|else\}'                       // {else}
            . '|[a-zA-Z_][\w.]*'              // Variables: {name}, {user.email}
            . ')/s';
        
        // Step 1: Protect JS curly braces in a single pass without repeatedly
        // mutating the string, which avoids O(n^2) behavior on script-heavy templates.
        $jsMarkers = [];
        $markerCount = 0;
        $chunks = [];
        $insideDisylTag = false;
        
        $len = strlen($body);
        $i = 0;
        while ($i < $len) {
            $char = $body[$i];

            if ($char === '{') {
                // Check if this looks like a DiSyL tag
                if (preg_match($disylPattern, $body, $m, PREG_OFFSET_CAPTURE, $i) === 1 && ($m[0][1] ?? -1) === $i) {
                    $insideDisylTag = true;
                    $chunks[] = $char;
                    $i++;
                    continue;
                }

                $marker = "___JSCURLY_OPEN_{$markerCount}___";
                $jsMarkers[$marker] = '{';
                $chunks[] = $marker;
                $markerCount++;
            } elseif ($char === '}') {
                if ($insideDisylTag) {
                    $insideDisylTag = false;
                    $chunks[] = $char;
                } else {
                    $marker = "___JSCURLY_CLOSE_{$markerCount}___";
                    $jsMarkers[$marker] = '}';
                    $chunks[] = $marker;
                    $markerCount++;
                }
            } else {
                $chunks[] = $char;
            }

            $i++;
        }

        $body = implode('', $chunks);
        
        // Step 2: Run full compilation (control structures + variables)
        //         Variables are output raw by default in script context.
        $this->scriptContext = true;
        
        // Process {literal} blocks within the script
        $scriptLiterals = [];
        $body = preg_replace_callback('/\{literal\}(.*?)\{\/literal\}/s', function($match) use (&$scriptLiterals) {
            $key = '___SCRIPTLIT_' . count($scriptLiterals) . '___';
            $scriptLiterals[$key] = $match[1];
            return $key;
        }, $body);
        
        // Process set statements
        $body = $this->processSetStatements($body, $context);
        
        // Process control structures
        $body = $this->processControlStructures($body, $context);
        
        // Process includes
        $body = $this->processIncludes($body, $context);
        
        // Process variables (raw output in script context)
        $body = $this->processScriptVariables($body, $context);
        
        // Restore script literals
        if (!empty($scriptLiterals)) {
            $body = str_replace(array_keys($scriptLiterals), array_values($scriptLiterals), $body);
        }
        
        $this->scriptContext = false;
        
        // Step 3: Restore JS curly braces
        if (!empty($jsMarkers)) {
            $body = str_replace(array_keys($jsMarkers), array_values($jsMarkers), $body);
        }
        
        return $body;
    }
    
    /** @var bool Whether we're compiling inside a <script> context (raw output) */
    private bool $scriptContext = false;
    
    /**
     * Process DiSyL variables inside <script> blocks.
     * 
     * Resolves {variable} and {variable | filter} expressions.
     * Variables inside <script> are output raw by default (no HTML-escaping)
     * unless an explicit escape filter is used, because script content is
     * not HTML context.
     */
    private function processScriptVariables(string $content, array $context): string
    {
        // First pass: ternary expressions
        if (str_contains($content, '?') && str_contains($content, ':')) {
            $content = preg_replace_callback(
                '/\{([^}]+\?[^}]+:[^}]+)\}/',
                function($match) use ($context) {
                    return $this->evaluateTernary(trim($match[1]), $context);
                },
                $content
            );
        }
        
        // Second pass: arithmetic
        if (strpbrk($content, '+-*/%') !== false) {
            $content = preg_replace_callback(
                '/\{([a-zA-Z_][\w.]*\s*[+\-*\/%]\s*[\w.]+)\}/',
                function($match) use ($context) {
                    $result = $this->evaluateArithmetic(trim($match[1]), $context);
                    if ($result !== null) {
                        return (string) $result;
                    }
                    // Arithmetic operands with dots are template expressions — output empty
                    if (str_contains($match[1], '.')) {
                        return '';
                    }
                    return $match[0];
                },
                $content
            );
        }
        
        // Third pass: variables with filters
        return preg_replace_callback(
            '/(?<!\$)\{([a-zA-Z_][\w.]*(?:\s*\|\s*[^}]+)?)\}/',
            function($match) use ($context) {
                $expr = trim($match[1]);
                if (!str_contains($expr, '|')) {
                    $value = $this->resolveValue($expr, $context);

                    if (!is_scalar($value)) {
                        $rootKey = explode('.', $expr, 2)[0];
                        if (str_contains($expr, '.') || array_key_exists($rootKey, $context)) {
                            return '';
                        }
                        return $match[0];
                    }

                    return (string) $value;
                }

                // Split filters
                $filters = $this->splitByPipe($expr);
                $varPath = trim(array_shift($filters));
                
                // Resolve the value
                $value = $this->resolveValue($varPath, $context);
                
                // Apply any explicit filters
                foreach ($filters as $filter) {
                    $filter = trim($filter);
                    if ($filter === 'raw') continue; // raw is default in script context
                    $value = $this->applyFilter($filter, $value, $context);
                }
                
                if (!is_scalar($value)) {
                    // Dot-path variables (e.g. user.name, cms_settings.site_tagline)
                    // are always template expressions — never valid JS identifiers.
                    // Also, if the top-level key exists in context, it's a template var.
                    $rootKey = explode('.', $varPath, 2)[0];
                    if (str_contains($varPath, '.') || array_key_exists($rootKey, $context)) {
                        return '';
                    }
                    // Single-word variable not in context — might be a JS identifier;
                    // preserve the original token to avoid breaking JS destructuring.
                    return $match[0];
                }
                
                return (string) $value;
            },
            $content
        );
    }
    
    /**
     * Process {set var = expression} statements.
     * Removes the tag from output and adds the computed value to context.
     * Supports: {set x = 5}, {set total = items | count}, {set next = page + 1}
     */
    private function processSetStatements(string $content, array &$context): string
    {
        return preg_replace_callback(
            '/\{set\s+(\w+)\s*=\s*([^}]+)\}/',
            function($match) use (&$context) {
                $varName = trim($match[1]);
                $expr = trim($match[2]);
                
                // Try arithmetic first
                $value = $this->evaluateArithmetic($expr, $context);
                if ($value === null) {
                    // Try quoted string literal
                    if (preg_match('/^["\'](.*)["\']\s*$/', $expr, $qm)) {
                        $value = $qm[1];
                    }
                    // Try numeric literal
                    elseif (is_numeric($expr)) {
                        $value = $expr + 0;
                    }
                    else {
                        // Fall back to variable with filters
                        $value = $this->resolveValueWithFilters($expr, $context);
                    }
                }
                
                $context[$varName] = $value;
                return ''; // Remove the {set} tag from output
            },
            $content
        );
    }
    
    /**
     * Evaluate arithmetic expressions: var + num, var - num, var * num, var / num, var % num
     * Returns null if the expression is not arithmetic.
     */
    private function evaluateArithmetic(string $expr, array $context)
    {
        // Match: operand operator operand (e.g. "page + 1", "total - count", "price * 1.1")
        if (preg_match('/^(.+?)\s*([+\-*\/%])\s*(.+)$/', $expr, $m)) {
            $leftRaw = trim($m[1]);
            $op = $m[2];
            $rightRaw = trim($m[3]);
            
            // Resolve left operand
            $left = $this->resolveValue($leftRaw, $context);
            if ($left === null && is_numeric($leftRaw)) $left = $leftRaw + 0;
            if ($left === null) return null;
            
            // Resolve right operand
            $right = $this->resolveValue($rightRaw, $context);
            if ($right === null && is_numeric($rightRaw)) $right = $rightRaw + 0;
            if ($right === null) return null;
            
            $left = (float) $left;
            $right = (float) $right;
            
            return match($op) {
                '+' => ($left + $right == (int)($left + $right)) ? (int)($left + $right) : $left + $right,
                '-' => ($left - $right == (int)($left - $right)) ? (int)($left - $right) : $left - $right,
                '*' => ($left * $right == (int)($left * $right)) ? (int)($left * $right) : $left * $right,
                '/' => $right != 0 ? (($left / $right == (int)($left / $right)) ? (int)($left / $right) : $left / $right) : 0,
                '%' => $right != 0 ? (int)$left % (int)$right : 0,
                default => null,
            };
        }
        
        return null;
    }
    
    /**
     * Remove template comments
     */
    private function removeComments(string $content): string
    {
        $content = preg_replace('/\{!--.*?--\}/s', '', $content);
        return preg_replace('/\{\*.*?\*\}/s', '', $content);
    }
    
    /**
     * Process template extends with HTMX partial support.
     * Supports multi-level inheritance (grandchild → parent → grandparent).
     * Detects and breaks circular {extends} chains.
     *
     * Algorithm: walk the full inheritance chain, collecting block overrides
     * from child to root (child wins). Apply all overrides to the root ancestor
     * in a single pass — no recursive merging, avoids nested-block ambiguity.
     */
    private function processExtends(string $content, array $context): string
    {
        $isHtmx = !empty($context['is_htmx']);

        if (!preg_match('/\{extends\s+"([^"]+)"\s*\}/', $content, $match)) {
            return $content;
        }

        if ($isHtmx) {
            // For HTMX: extract block content without any layout wrapping
            preg_match_all('/\{block\s+(\w+)\}(.*?)\{\/block\}/s', $content, $blocks, PREG_SET_ORDER);
            $blockContent = '';
            foreach ($blocks as $block) {
                $blockContent .= $block[2];
            }
            return preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $blockContent ?: $content);
        }

        // Walk the full inheritance chain from child → root, collecting each template.
        // $chain[0] is the child; last element is the first ancestor with no {extends}.
        $chain    = [];
        $seenPaths = [];
        $current  = $content;
        $chainDepth = 0;

        while (preg_match('/\{extends\s+"([^"]+)"\s*\}/', $current, $extMatch)) {
            if ($chainDepth >= self::EXTENDS_CHAIN_MAX) {
                $this->logError('Extends chain depth exceeded maximum (' . self::EXTENDS_CHAIN_MAX . ')');
                $current = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $current);
                break;
            }

            $layoutName = $extMatch[1];
            $layoutPath = $this->resolveTemplatePath($layoutName);

            if (!file_exists($layoutPath)) {
                // Missing layout: strip directive and treat this as the root
                $current = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $current);
                break;
            }

            $realPath = realpath($layoutPath) ?: $layoutPath;
            if (isset($seenPaths[$realPath])) {
                $this->logError("Circular {extends} detected: \"{$layoutName}\"");
                $current = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $current);
                break;
            }

            $seenPaths[$realPath] = true;
            $chain[] = $current;
            $layoutContent = $this->readTemplateSource($layoutPath);
            if ($layoutContent === false) {
                $this->logError("Failed to read layout: {$layoutName}");
                $current = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $current);
                break;
            }
            $current = $layoutContent;
            $chainDepth++;
        }

        // $current is now the root ancestor. Collect block overrides from the chain
        // with child definitions winning over parent definitions (first one wins).
        $allBlocks = [];
        foreach ($chain as $template) {
            preg_match_all('/\{block\s+(\w+)\}(.*?)\{\/block\}/s', $template, $blocks, PREG_SET_ORDER);
            foreach ($blocks as $block) {
                if (!isset($allBlocks[$block[1]])) {
                    $allBlocks[$block[1]] = $block[2];
                }
            }
        }

        // Apply all collected overrides to the root ancestor in one pass.
        // Iterate until stable to handle multiple block levels in the ancestor itself.
        $result   = $current;
        $maxPasses = 10;
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $new = preg_replace_callback(
                '/\{block\s+(\w+)\}(.*?)\{\/block\}/s',
                fn($m) => $allBlocks[$m[1]] ?? $m[2],
                $result
            );
            if ($new === $result) {
                break;
            }
            $result = $new;
        }

        return preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $result ?? $current);
    }
    
    /**
     * Process standalone blocks
     */
    private function processBlocks(string $content, array $context): string
    {
        return preg_replace('/\{block\s+\w+\}(.*?)\{\/block\}/s', '$1', $content);
    }
    
    /**
     * Token-based control structure processing
     * Handles nested if/elseif/else/for/foreach correctly
     */
    private function processControlStructures(string $content, array $context): string
    {
        if (!str_contains($content, '{')) {
            return $content;
        }

        if (
            !str_contains($content, '{if')
            && !str_contains($content, '{for')
            && !str_contains($content, '{foreach')
            && !str_contains($content, '{each')
        ) {
            return $content;
        }

        $maxIterations = 100;
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            $processed = $this->processOneControlStructure($content, $context);
            if ($processed === $content) {
                break; // No more changes
            }
            $content = $processed;
            $iteration++;
        }
        
        return $content;
    }
    
    /**
     * Process one control structure per iteration.
     * 
     * Loops (for/foreach/each) are processed OUTERMOST-FIRST so that
     * compile() on the loop body can resolve inner loops with the
     * correct iteration context.
     * 
     * Conditionals (if) are processed INNERMOST-FIRST so that nested
     * conditions evaluate correctly bottom-up.
     */
    private function processOneControlStructure(string $content, array $context): string
    {
        $result = $this->processFirstLoopStructure($content, $context);
        if ($result !== null) {
            return $result;
        }

        $result = $this->processLastIfStructure($content, $context);
        if ($result !== null) {
            return $result;
        }

        return $content;
    }

    private function processFirstLoopStructure(string $content, array $context): ?string
    {
        $offset = 0;
        $len = strlen($content);

        while ($offset < $len) {
            $tag = $this->findNextOpeningControlTag($content, $offset, ['for', 'foreach', 'each']);
            if ($tag === null) {
                break;
            }

            $result = $this->extractAndProcessStructure($content, $tag, $context);
            if ($result !== null) {
                return $result;
            }

            $offset = $tag['pos'] + 1;
        }

        return null;
    }

    private function processLastIfStructure(string $content, array $context): ?string
    {
        $ifTags = [];
        $offset = 0;
        $len = strlen($content);

        while ($offset < $len) {
            $tag = $this->findNextOpeningControlTag($content, $offset, ['if']);
            if ($tag === null) {
                break;
            }

            $ifTags[] = $tag;
            $offset = $tag['pos'] + 1;
        }

        for ($index = count($ifTags) - 1; $index >= 0; $index--) {
            $result = $this->extractAndProcessStructure($content, $ifTags[$index], $context);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Find the next opening control tag from the given offset.
     *
     * @param array<int, string> $allowedTypes
     * @return array{type: string, expr: string, pos: int, len: int, full: string}|null
     */
    private function findNextOpeningControlTag(string $content, int $offset, array $allowedTypes): ?array
    {
        $len = strlen($content);

        while ($offset < $len) {
            $pos = strpos($content, '{', $offset);
            if ($pos === false) {
                return null;
            }

            $tag = $this->readOpeningControlTagAt($content, $pos, $allowedTypes);
            if ($tag !== null) {
                return $tag;
            }

            $offset = $pos + 1;
        }

        return null;
    }

    /**
     * Parse an opening control tag at a known "{" position.
     *
     * @param array<int, string> $allowedTypes
     * @return array{type: string, expr: string, pos: int, len: int, full: string}|null
     */
    private function readOpeningControlTagAt(string $content, int $pos, array $allowedTypes): ?array
    {
        foreach ($allowedTypes as $type) {
            $keyword = '{' . $type;
            $keywordLen = strlen($keyword);
            if (substr_compare($content, $keyword, $pos, $keywordLen) !== 0) {
                continue;
            }

            $whitespacePos = $pos + $keywordLen;
            $nextChar = $content[$whitespacePos] ?? '';
            if ($nextChar === '' || !ctype_space($nextChar)) {
                continue;
            }

            $tagEnd = strpos($content, '}', $whitespacePos + 1);
            if ($tagEnd === false) {
                return null;
            }

            $full = substr($content, $pos, $tagEnd - $pos + 1);
            $expr = trim(substr($content, $whitespacePos + 1, $tagEnd - $whitespacePos - 1));

            if ($expr === '') {
                continue;
            }

            return [
                'type' => $type,
                'expr' => $expr,
                'pos' => $pos,
                'len' => strlen($full),
                'full' => $full,
            ];
        }

        return null;
    }
    
    /**
     * Extract and process a single control structure
     */
    private function extractAndProcessStructure(string $content, array $tag, array $context): ?string
    {
        $type = $tag['type'];
        $startPos = $tag['pos'];
        $afterOpen = $startPos + $tag['len'];
        
        if ($type === 'if') {
            return $this->processIfStructure($content, $tag, $context);
        } elseif ($type === 'for') {
            return $this->processForStructure($content, $tag, $context);
        } elseif ($type === 'foreach') {
            return $this->processForeachStructure($content, $tag, $context);
        } elseif ($type === 'each') {
            return $this->processEachStructure($content, $tag, $context);
        }
        
        return null;
    }
    
    /**
     * Process {if}...{elseif}...{else}...{/if} structure
     */
    private function processIfStructure(string $content, array $tag, array $context): ?string
    {
        $startPos = $tag['pos'];
        $afterOpen = $startPos + $tag['len'];
        
        // Find the matching {/if} - accounting for nesting
        $closePos = $this->findMatchingClose($content, $afterOpen, 'if');
        if ($closePos === false) {
            return null;
        }
        
        $innerContent = substr($content, $afterOpen, $closePos - $afterOpen);
        
        // Parse the if/elseif/else structure
        $branches = $this->parseIfBranches($innerContent, $tag['expr']);
        
        // Evaluate and get result
        $result = '';
        foreach ($branches as $branch) {
            if ($branch['type'] === 'else' || $this->evaluateCondition($branch['condition'], $context)) {
                $result = $branch['content'];
                break;
            }
        }
        
        // Replace the entire structure with result
        $closeLen = strlen('{/if}');
        return substr($content, 0, $startPos) . $result . substr($content, $closePos + $closeLen);
    }
    
    /**
     * Parse if/elseif/else branches.
     * 
     * Correctly skips nested {if}...{/if} blocks so that an {elseif} or {else}
     * inside a nested block is not mistaken for one belonging to the outer {if}.
     */
    private function parseIfBranches(string $content, string $initialCondition): array
    {
        $branches = [];
        $currentContent = '';
        $currentCondition = $initialCondition;
        $currentType = 'if';
        
        $pos = 0;
        $len = strlen($content);
        $depth = 0; // Track nested {if} depth
        
        while ($pos < $len) {
            // Find the next relevant tag: {if, {/if}, {elseif, {else}
            $nextIf = strpos($content, '{if ', $pos);
            $nextEndIf = strpos($content, '{/if}', $pos);
            $nextElseIf = ($depth === 0) ? $this->findElseIfAt($content, $pos) : false;
            $nextElse = ($depth === 0) ? $this->findElseAt($content, $pos) : false;
            
            // Find the earliest tag
            $candidates = [];
            if ($nextIf !== false) $candidates['if'] = $nextIf;
            if ($nextEndIf !== false) $candidates['endif'] = $nextEndIf;
            if ($nextElseIf !== false) $candidates['elseif'] = $nextElseIf;
            if ($nextElse !== false) $candidates['else'] = $nextElse;
            
            if (empty($candidates)) {
                $currentContent .= substr($content, $pos);
                break;
            }
            
            $nextType = '';
            $nextPos = PHP_INT_MAX;
            foreach ($candidates as $type => $p) {
                if ($p < $nextPos) {
                    $nextPos = $p;
                    $nextType = $type;
                }
            }
            
            if ($nextType === 'if') {
                // Entering a nested {if} — add content up to here and increase depth
                $tagEnd = strpos($content, '}', $nextIf);
                $currentContent .= substr($content, $pos, $tagEnd + 1 - $pos);
                $depth++;
                $pos = $tagEnd + 1;
            } elseif ($nextType === 'endif') {
                if ($depth > 0) {
                    // Closing a nested {if}
                    $currentContent .= substr($content, $pos, $nextEndIf + 5 - $pos);
                    $depth--;
                    $pos = $nextEndIf + 5;
                } else {
                    // This shouldn't happen (outer {/if} is already stripped)
                    $currentContent .= substr($content, $pos, $nextEndIf + 5 - $pos);
                    $pos = $nextEndIf + 5;
                }
            } elseif ($nextType === 'elseif' && $depth === 0) {
                // Top-level {elseif} — split branch
                $currentContent .= substr($content, $pos, $nextPos - $pos);
                $branches[] = ['type' => $currentType, 'condition' => $currentCondition, 'content' => $currentContent];
                
                // Extract the condition from {elseif condition}
                preg_match('/\{elseif\s+([^}]+)\}/', $content, $m, 0, $nextPos);
                $currentType = 'elseif';
                $currentCondition = $m[1];
                $currentContent = '';
                $pos = $nextPos + strlen($m[0]);
            } elseif ($nextType === 'else' && $depth === 0) {
                // Top-level {else} — split branch
                $currentContent .= substr($content, $pos, $nextPos - $pos);
                $branches[] = ['type' => $currentType, 'condition' => $currentCondition, 'content' => $currentContent];
                
                $currentType = 'else';
                $currentCondition = '';
                $currentContent = '';
                $pos = $nextPos + 6; // strlen('{else}')
            } else {
                // Nested elseif/else — just include as content
                $currentContent .= substr($content, $pos, $nextPos + 1 - $pos);
                $pos = $nextPos + 1;
            }
        }
        
        // Add final branch
        $branches[] = ['type' => $currentType, 'condition' => $currentCondition, 'content' => $currentContent];
        
        return $branches;
    }
    
    /**
     * Find {elseif ...} at or after position, returning its start position or false.
     * Must not match inside a word (e.g. {elseifx}).
     */
    private function findElseIfAt(string $content, int $pos): int|false
    {
        $search = '{elseif ';
        $found = strpos($content, $search, $pos);
        return $found;
    }
    
    /**
     * Find standalone {else} at or after position.
     * Must match exactly {else} not {elseif}.
     */
    private function findElseAt(string $content, int $pos): int|false
    {
        $offset = $pos;
        while (($found = strpos($content, '{else}', $offset)) !== false) {
            return $found;
        }
        return false;
    }
    
    /**
     * Process {for item in list}...{/for} structure
     */
    private function processForStructure(string $content, array $tag, array $context): ?string
    {
        $startPos = $tag['pos'];
        $afterOpen = $startPos + $tag['len'];
        
        // Parse: item in list
        if (!preg_match('/^(\w+)\s+in\s+(.+)$/s', trim($tag['expr']), $parts)) {
            return null;
        }
        
        $itemName = $parts[1];
        $listExpr = trim($parts[2]);
        
        // Find matching {/for}
        $closePos = $this->findMatchingClose($content, $afterOpen, 'for');
        if ($closePos === false) {
            return null;
        }
        
        $body = substr($content, $afterOpen, $closePos - $afterOpen);

        // Extract optional {empty} clause (content shown when list is empty)
        $emptyContent = '';
        if (($emptyTagPos = strpos($body, '{empty}')) !== false) {
            $emptyContent = substr($body, $emptyTagPos + 7);
            $body = substr($body, 0, $emptyTagPos);
        }

        // Get the list
        $list = $this->resolveValue($listExpr, $context);
        if (!is_array($list)) {
            $list = [];
        }

        $closeLen = strlen('{/for}');

        // Empty list — render {empty} clause if present
        if (empty($list)) {
            $emptyResult = $emptyContent !== '' ? $this->compile($emptyContent, $context) : '';
            return substr($content, 0, $startPos) . $emptyResult . substr($content, $closePos + $closeLen);
        }

        // Iterate
        $result = '';
        $index = 0;
        $count = count($list);
        
        foreach ($list as $key => $item) {
            $loopContext = array_merge($context, [
                $itemName => $item,
                'loop' => [
                    'index' => $index,
                    'index1' => $index + 1,
                    'first' => $index === 0,
                    'last' => $index === $count - 1,
                    'key' => $key,
                    'length' => $count,
                ],
            ]);
            $result .= $this->compile($body, $loopContext);
            $index++;
        }
        
        return substr($content, 0, $startPos) . $result . substr($content, $closePos + $closeLen);
    }
    
    /**
     * Process {foreach list as item} or {foreach list as key => value}...{/foreach} structure
     */
    private function processForeachStructure(string $content, array $tag, array $context): ?string
    {
        $startPos = $tag['pos'];
        $afterOpen = $startPos + $tag['len'];
        
        $expr = trim($tag['expr']);
        $keyName = null;
        $itemName = null;
        $listExpr = null;
        
        // Parse: list as key => value
        if (preg_match('/^(.+)\s+as\s+(\w+)\s*=>\s*(\w+)$/s', $expr, $parts)) {
            $listExpr = trim($parts[1]);
            $keyName = $parts[2];
            $itemName = $parts[3];
        }
        // Parse: list as item
        elseif (preg_match('/^(.+)\s+as\s+(\w+)$/s', $expr, $parts)) {
            $listExpr = trim($parts[1]);
            $itemName = $parts[2];
        } else {
            return null;
        }
        
        // Find matching {/foreach}
        $closePos = $this->findMatchingClose($content, $afterOpen, 'foreach');
        if ($closePos === false) {
            return null;
        }
        
        $body = substr($content, $afterOpen, $closePos - $afterOpen);

        // Extract optional {empty} clause (content shown when list is empty)
        $emptyContent = '';
        if (($emptyTagPos = strpos($body, '{empty}')) !== false) {
            $emptyContent = substr($body, $emptyTagPos + 7);
            $body = substr($body, 0, $emptyTagPos);
        }

        // Get the list
        $list = $this->resolveValue($listExpr, $context);
        if (!is_array($list)) {
            $list = [];
        }

        $closeLen = strlen('{/foreach}');

        // Empty list — render {empty} clause if present
        if (empty($list)) {
            $emptyResult = $emptyContent !== '' ? $this->compile($emptyContent, $context) : '';
            return substr($content, 0, $startPos) . $emptyResult . substr($content, $closePos + $closeLen);
        }

        // Iterate
        $result = '';
        $index = 0;
        $count = count($list);
        
        foreach ($list as $key => $item) {
            $loopContext = array_merge($context, [
                $itemName => $item,
                'loop' => [
                    'index' => $index,
                    'index1' => $index + 1,
                    'first' => $index === 0,
                    'last' => $index === $count - 1,
                    'key' => $key,
                    'length' => $count,
                ],
            ]);
            if ($keyName) {
                $loopContext[$keyName] = $key;
            }
            $result .= $this->compile($body, $loopContext);
            $index++;
        }
        
        return substr($content, 0, $startPos) . $result . substr($content, $closePos + $closeLen);
    }
    
    /**
     * Process {each list as item} or {each list as key => value}...{/each} structure
     */
    private function processEachStructure(string $content, array $tag, array $context): ?string
    {
        $startPos = $tag['pos'];
        $afterOpen = $startPos + $tag['len'];
        
        $expr = trim($tag['expr']);
        $keyName = null;
        $itemName = null;
        $listExpr = null;
        
        // Parse: list as key => value
        if (preg_match('/^(.+)\s+as\s+(\w+)\s*=>\s*(\w+)$/s', $expr, $parts)) {
            $listExpr = trim($parts[1]);
            $keyName = $parts[2];
            $itemName = $parts[3];
        }
        // Parse: list as item
        elseif (preg_match('/^(.+)\s+as\s+(\w+)$/s', $expr, $parts)) {
            $listExpr = trim($parts[1]);
            $itemName = $parts[2];
        } else {
            return null;
        }
        
        // Find matching {/each}
        $closePos = $this->findMatchingClose($content, $afterOpen, 'each');
        if ($closePos === false) {
            return null;
        }
        
        $body = substr($content, $afterOpen, $closePos - $afterOpen);

        // Extract optional {empty} clause (content shown when list is empty)
        $emptyContent = '';
        if (($emptyTagPos = strpos($body, '{empty}')) !== false) {
            $emptyContent = substr($body, $emptyTagPos + 7);
            $body = substr($body, 0, $emptyTagPos);
        }

        // Get the list
        $list = $this->resolveValue($listExpr, $context);
        if (!is_array($list)) {
            $list = [];
        }

        $closeLen = strlen('{/each}');

        // Empty list — render {empty} clause if present
        if (empty($list)) {
            $emptyResult = $emptyContent !== '' ? $this->compile($emptyContent, $context) : '';
            return substr($content, 0, $startPos) . $emptyResult . substr($content, $closePos + $closeLen);
        }

        // Iterate
        $result = '';
        $index = 0;
        $count = count($list);
        
        foreach ($list as $key => $item) {
            $loopContext = array_merge($context, [
                $itemName => $item,
                'loop' => [
                    'index' => $index,
                    'index1' => $index + 1,
                    'first' => $index === 0,
                    'last' => $index === $count - 1,
                    'key' => $key,
                    'length' => $count,
                ],
            ]);
            if ($keyName !== null) {
                $loopContext[$keyName] = $key;
            }
            $result .= $this->compile($body, $loopContext);
            $index++;
        }
        
        return substr($content, 0, $startPos) . $result . substr($content, $closePos + $closeLen);
    }
    
    /**
     * Find matching closing tag, accounting for nesting
     */
    private function findMatchingClose(string $content, int $start, string $tagName): int|false
    {
        $openTag = '{' . $tagName;
        $closeTag = '{/' . $tagName . '}';
        $depth = 1;
        $pos = $start;
        $len = strlen($content);
        
        while ($pos < $len && $depth > 0) {
            $nextOpen = strpos($content, $openTag, $pos);
            $nextClose = strpos($content, $closeTag, $pos);
            
            if ($nextClose === false) {
                return false; // No closing tag found
            }
            
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                // Check if it's actually an opening tag (has space or } after tag name)
                $afterTag = $nextOpen + strlen($openTag);
                if ($afterTag < $len && (ctype_space($content[$afterTag]) || $content[$afterTag] === '}')) {
                    $depth++;
                }
                $pos = $nextOpen + 1;
            } else {
                $depth--;
                if ($depth === 0) {
                    return $nextClose;
                }
                $pos = $nextClose + 1;
            }
        }
        
        return false;
    }
    
    /** @var array Include stack for circular-include detection (keyed by real path) */
    private array $includeStack = [];

    /** @var array<string, string> Cached template file contents keyed by resolved path */
    private array $includeSourceCache = [];

    /** @var int Current component nesting depth (tracks compile()-within-compile() via component children) */
    private int $componentDepth = 0;

    /**
     * Process includes
     */
    private function processIncludes(string $content, array $context): string
    {
        if (!str_contains($content, '{include ')) {
            return $content;
        }

        $maxIterations = 20;
        $iteration = 0;
        
        while ($iteration < $maxIterations && preg_match('/\{include\s+"([^"]+)"/', $content)) {
            $content = preg_replace_callback(
                '/\{include\s+"([^"]+)"(?:\s+with\s+(\{[^}]+\}))?\s*\}/',
                function($match) use ($context) {
                    $includePath = $this->resolveTemplatePath($match[1]);
                    if (!file_exists($includePath)) {
                        $this->logError("Include not found: {$match[1]}");
                        return '';
                    }

                    // Circular include detection
                    $realPath = realpath($includePath) ?: $includePath;
                    if (isset($this->includeStack[$realPath])) {
                        $this->logError("Circular include detected: {$match[1]}");
                        return '';
                    }
                    $this->includeStack[$realPath] = true;
                    
                    $includeContext = $context;
                    if (!empty($match[2])) {
                        $extra = $this->parseInlineObject($match[2], $context);
                        $includeContext = array_merge($context, $extra);
                    }

                    $includeContent = $this->readIncludeSource($includePath);
                    if ($includeContent === false) {
                        unset($this->includeStack[$realPath]);
                        $this->logError("Failed to read include: {$match[1]}");
                        return '';
                    }
                    $result = $this->compile($includeContent, $includeContext);

                    unset($this->includeStack[$realPath]);
                    return $result;
                },
                $content
            );
            $iteration++;
        }
        
        return $content;
    }

    private function readIncludeSource(string $includePath): string|false
    {
        if ($this->cacheEnabled && isset($this->includeSourceCache[$includePath])) {
            return $this->includeSourceCache[$includePath];
        }

        $includeContent = file_get_contents($includePath);
        if ($includeContent === false) {
            return false;
        }

        if ($this->cacheEnabled) {
            if (count($this->includeSourceCache) >= self::TEMPLATE_SOURCE_CACHE_MAX) {
                reset($this->includeSourceCache);
                unset($this->includeSourceCache[key($this->includeSourceCache)]);
            }
            $this->includeSourceCache[$includePath] = $includeContent;
        }

        return $includeContent;
    }

    private function readTemplateSource(string $templatePath): string|false
    {
        if ($this->cacheEnabled && isset($this->templateSourceCache[$templatePath])) {
            return $this->templateSourceCache[$templatePath];
        }

        $content = file_get_contents($templatePath);
        if ($content === false) {
            return false;
        }

        if ($this->cacheEnabled) {
            if (count($this->templateSourceCache) >= self::TEMPLATE_SOURCE_CACHE_MAX) {
                reset($this->templateSourceCache);
                unset($this->templateSourceCache[key($this->templateSourceCache)]);
            }
            $this->templateSourceCache[$templatePath] = $content;
        }

        return $content;
    }

    /**
     * Process components with proper quote handling
     */
    /** Maximum number of component nesting levels (prevents runaway depth) */
    private const COMPONENT_MAX_DEPTH = 30;

    private function processComponents(string $content, array $context): string
    {
        if (!str_contains($content, '{ikb_') && !str_contains($content, '{island')) {
            return $content;
        }

        $maxIterations = 200;
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            // Find component tag
            if (!preg_match('/\{(ikb_\w+|island)[\s}]/', $content, $match, PREG_OFFSET_CAPTURE)) {
                break;
            }
            
            $tagStart = $match[0][1];
            $componentName = $match[1][0];
            
            // Find closing brace of opening tag (respecting quotes)
            $tagEnd = $this->findTagEnd($content, $tagStart);
            if ($tagEnd === false) {
                $this->logError("Unclosed component tag: {$componentName}");
                break;
            }
            
            // Extract attribute string
            $tagContent = substr($content, $tagStart + 1, $tagEnd - $tagStart - 1);
            $attrString = substr($tagContent, strlen($componentName));
            
            // Check if self-closing
            $isSelfClosing = preg_match('/\/\s*$/', $attrString);
            if ($isSelfClosing) {
                $attrString = preg_replace('/\/\s*$/', '', $attrString);
                $attrs = $this->parseAttributes($attrString, $context);
                $replacement = $this->renderComponent($componentName, $attrs, '', $context);
                $content = substr($content, 0, $tagStart) . $replacement . substr($content, $tagEnd + 1);
            } else {
                // Find closing tag
                $closeTag = '{/' . $componentName . '}';
                $closePos = $this->findComponentClose($content, $tagEnd + 1, $componentName);
                
                if ($closePos === false) {
                    $this->logError("Missing closing tag for: {$componentName}");
                    break;
                }
                
                $children = substr($content, $tagEnd + 1, $closePos - $tagEnd - 1);
                $attrs = $this->parseAttributes($attrString, $context);
                
                // Compile children with nesting depth guard
                if ($this->componentDepth >= self::COMPONENT_MAX_DEPTH) {
                    $this->logError("Component nesting depth limit (" . self::COMPONENT_MAX_DEPTH . ") exceeded for: {$componentName}");
                    $compiledChildren = '';
                } else {
                    $this->componentDepth++;
                    $compiledChildren = $this->compile($children, $context);
                    $this->componentDepth--;
                }
                $replacement = $this->renderComponent($componentName, $attrs, $compiledChildren, $context);
                
                $content = substr($content, 0, $tagStart) . $replacement . substr($content, $closePos + strlen($closeTag));
            }
            
            $iteration++;
        }
        
        return $content;
    }
    
    /**
     * Find the end of a tag, respecting quotes
     */
    private function findTagEnd(string $content, int $start): int|false
    {
        $len = strlen($content);
        $inQuote = false;
        $quoteChar = '';
        
        for ($i = $start; $i < $len; $i++) {
            $char = $content[$i];
            $prevChar = $i > 0 ? $content[$i - 1] : '';
            
            if (!$inQuote && ($char === '"' || $char === "'")) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar && $prevChar !== '\\') {
                $inQuote = false;
                $quoteChar = '';
            } elseif (!$inQuote && $char === '}') {
                return $i;
            }
        }
        
        return false;
    }
    
    /**
     * Find component closing tag, handling nested same-name components
     */
    private function findComponentClose(string $content, int $start, string $componentName): int|false
    {
        $openPattern = '{' . $componentName;
        $closeTag = '{/' . $componentName . '}';
        $depth = 1;
        $pos = $start;
        $len = strlen($content);
        
        while ($pos < $len && $depth > 0) {
            $nextOpen = strpos($content, $openPattern, $pos);
            $nextClose = strpos($content, $closeTag, $pos);
            
            if ($nextClose === false) {
                return false;
            }
            
            // Check if nextOpen is actually an opening tag (followed by space or })
            $isRealOpen = false;
            if ($nextOpen !== false) {
                $afterOpen = $nextOpen + strlen($openPattern);
                if ($afterOpen < $len) {
                    $nextChar = $content[$afterOpen];
                    $isRealOpen = ctype_space($nextChar) || $nextChar === '}';
                }
            }
            
            if ($isRealOpen && $nextOpen < $nextClose) {
                $depth++;
                $pos = $nextOpen + 1;
            } else {
                $depth--;
                if ($depth === 0) {
                    return $nextClose;
                }
                $pos = $nextClose + 1;
            }
        }
        
        return false;
    }
    
    /**
     * Process variables with filters, arithmetic, and ternary expressions.
     * Skips JavaScript template literals (${...}).
     * 
     * Supports:
     * - Simple variables: {name}, {user.email}
     * - Filters: {name | upper}, {date | date:'M d, Y'}
     * - Arithmetic: {page + 1}, {total - count}, {price * qty}
     * - Ternary: {active ? 'Yes' : 'No'}
     * - Raw output: {html_content | raw}
     * 
     * Auto-escape: All variables are HTML-escaped by default.
     * Use the | raw filter to output unescaped content.
     */
    private function processVariables(string $content, array $context): string
    {
        if (!str_contains($content, '{')) {
            return $content;
        }

        // First pass: ternary expressions {condition ? 'trueVal' : 'falseVal'}
        if (str_contains($content, '?') && str_contains($content, ':')) {
            $content = preg_replace_callback(
                '/\{([^}]+\?[^}]+:[^}]+)\}/',
                function($match) use ($context) {
                    return $this->evaluateTernary(trim($match[1]), $context);
                },
                $content
            );
        }
        
        // Second pass: arithmetic expressions {var + num}, {var - num}, etc.
        if (strpbrk($content, '+-*/%') !== false) {
            $content = preg_replace_callback(
                '/\{([a-zA-Z_][\w.]*\s*[+\-*\/%]\s*[\w.]+)\}/',
                function($match) use ($context) {
                    $result = $this->evaluateArithmetic(trim($match[1]), $context);
                    if ($result !== null) {
                        return htmlspecialchars((string) $result, ENT_QUOTES, 'UTF-8');
                    }
                    // Unresolvable arithmetic — output empty to avoid leaking tag syntax
                    return '';
                },
                $content
            );
        }
        
        // Third pass: standard variables with filters
        $content = preg_replace_callback(
            '/(?<!\$)\{([a-zA-Z_][\w.]*(?:\s*\|\s*[^}]+)?)\}/',
            function($match) use ($context) {
                $expr = trim($match[1]);
                if (!str_contains($expr, '|')) {
                    $value = $this->resolveValue($expr, $context);

                    if (!is_scalar($value)) {
                        return '';
                    }

                    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                }

                // Check if | raw filter is present (bypass auto-escape)
                $hasRaw = false;
                $filterNames = [];
                $filters = $this->splitByPipe($expr);
                $varPath = trim((string) array_shift($filters));
                $value = $this->resolveValue($varPath, $context);

                foreach ($filters as $filter) {
                    $filter = trim($filter);
                    if ($filter === '') {
                        continue;
                    }

                    $filterName = trim(explode(':', $filter, 2)[0]);
                    if ($filterName === 'raw') {
                        $hasRaw = true;
                        continue;
                    }

                    $filterNames[] = $filterName;
                    $value = $this->applyFilter($filter, $value, $context);
                }
                
                if (!is_scalar($value)) {
                    return '';
                }
                
                // Auto-escape unless | raw was specified or another escape filter was used
                if (!$hasRaw && !$this->hasEscapeFilter($expr, $filterNames)) {
                    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                }
                
                return (string) $value;
            },
            $content
        );
        
        return $content;
    }
    
    /**
     * Evaluate a ternary expression: condition ? trueValue : falseValue
     * 
     * Examples:
     *   {active ? 'Yes' : 'No'}
     *   {count > 0 ? count : 'none'}
     *   {user.role == 'admin' ? 'Administrator' : user.role}
     */
    private function evaluateTernary(string $expr, array $context): string
    {
        // Split on ? and : — find the top-level ? and :
        $qPos = strpos($expr, '?');
        if ($qPos === false) return '';
        
        $condition = trim(substr($expr, 0, $qPos));
        $rest = substr($expr, $qPos + 1);
        
        // Find the : separator (not inside quotes)
        $colonPos = $this->findUnquotedChar($rest, ':');
        if ($colonPos === false) return '';
        
        $trueExpr = trim(substr($rest, 0, $colonPos));
        $falseExpr = trim(substr($rest, $colonPos + 1));
        
        $result = $this->evaluateCondition($condition, $context) ? $trueExpr : $falseExpr;
        
        // Resolve the chosen expression
        if (preg_match('/^["\'](.*)["\']\s*$/', $result, $m)) {
            // Quoted string literal
            return htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        }
        
        // Try as variable
        $resolved = $this->resolveValueWithFilters($result, $context);
        if (is_scalar($resolved)) {
            return htmlspecialchars((string) $resolved, ENT_QUOTES, 'UTF-8');
        }
        
        // Try as number
        if (is_numeric($result)) {
            return $result;
        }
        
        return htmlspecialchars($result, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Find a character in a string that is not inside quotes.
     */
    private function findUnquotedChar(string $str, string $char): int|false
    {
        $inQuote = false;
        $quoteChar = '';
        $len = strlen($str);
        
        for ($i = 0; $i < $len; $i++) {
            $c = $str[$i];
            if (!$inQuote && ($c === '"' || $c === "'")) {
                $inQuote = true;
                $quoteChar = $c;
            } elseif ($inQuote && $c === $quoteChar) {
                $inQuote = false;
            } elseif (!$inQuote && $c === $char) {
                return $i;
            }
        }
        
        return false;
    }
    
    /**
     * Check if expression already has an escape filter applied.
     * Accepts the already-parsed filter name list to avoid false positives
     * from substring matches (e.g. a variable named "my_esc_html_thing").
     */
    private function hasEscapeFilter(string $expr, array $parsedFilterNames = []): bool
    {
        $escapeFilters = ['esc_html', 'esc_attr', 'esc_url', 'esc_js', 'json', 'url_encode', 'base64', 'nl2br'];
        if ($parsedFilterNames !== []) {
            foreach ($parsedFilterNames as $name) {
                if (in_array($name, $escapeFilters, true)) {
                    return true;
                }
            }
            return false;
        }
        // Fallback: scan pipe-split names from the raw expression to avoid substring matches
        $parts = $this->splitByPipe($expr);
        array_shift($parts); // drop variable path
        foreach ($parts as $part) {
            $filterName = trim(explode(':', trim($part), 2)[0]);
            if (in_array($filterName, $escapeFilters, true)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Resolve a dotted path to a value
     */
    private function resolveValue(string $path, array $context)
    {
        $path = trim($path);
        if ($path === '') return null;
        
        $parts = explode('.', $path);
        $value = $context;
        
        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return null;
            }
        }
        
        return $value;
    }
    
    /** Maximum number of filters allowed in a single filter chain */
    private const FILTER_CHAIN_MAX = 20;

    /**
     * Resolve value with filters applied
     */
    private function resolveValueWithFilters(string $expr, array $context)
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
    
    /**
     * Evaluate a condition expression to a boolean.
     * 
     * Supports: negation (!), AND/OR, comparison operators (==, !=, >, <, >=, <=, ===, !==),
     * arithmetic operands (page + 1 > total), quoted strings, variable paths, and truthy checks.
     */
    private function evaluateCondition(string $condition, array $context): bool
    {
        $condition = trim($condition);
        if ($condition === '') return false;
        
        // Strip outer parentheses: (expr) → expr
        // Allows {if (items | count) > 0} and {if (items | count)}
        if (preg_match('/^\((.+)\)$/', $condition, $pm)) {
            // Only unwrap if the parens are balanced (not part of a larger expression)
            $inner = $pm[1];
            $depth = 0;
            $balanced = true;
            for ($ci = 0, $cl = strlen($inner); $ci < $cl; $ci++) {
                if ($inner[$ci] === '(') $depth++;
                elseif ($inner[$ci] === ')') { $depth--; if ($depth < 0) { $balanced = false; break; } }
            }
            if ($balanced && $depth === 0) {
                $condition = $inner;
            }
        }
        
        // Handle negation (with optional space after !)
        if (preg_match('/^!\s*(.+)$/', $condition, $nm)) {
            return !$this->evaluateCondition($nm[1], $context);
        }
        
        // Handle AND/OR (simple support)
        if (preg_match('/^(.+?)\s+(and|&&)\s+(.+)$/i', $condition, $m)) {
            return $this->evaluateCondition($m[1], $context) && $this->evaluateCondition($m[3], $context);
        }
        if (preg_match('/^(.+?)\s+(or|\|\|)\s+(.+)$/i', $condition, $m)) {
            return $this->evaluateCondition($m[1], $context) || $this->evaluateCondition($m[3], $context);
        }
        
        // Handle comparison operators.
        // Use a regex that won't split on operators inside a piped filter expression.
        // Strategy: find the LAST comparison operator not inside parens, since filter
        // expressions (left side) may contain | but never contain >, <, ==, != outside quotes.
        if (preg_match('/^(.+?)\s*(===|!==|==|!=|>=|<=|>|<)\s*(.+)$/', $condition, $match)) {
            $left = $this->resolveConditionOperand(trim($match[1]), $context);
            $op = $match[2];
            $right = $this->resolveConditionOperand(trim($match[3]), $context);
            
            // Coerce numeric strings for non-strict comparisons
            if ($op !== '===' && $op !== '!==' && is_numeric($left)) $left = $left + 0;
            if ($op !== '===' && $op !== '!==' && is_numeric($right)) $right = $right + 0;
            
            return match($op) {
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
        
        // Simple truthy check (may include arithmetic: {if count - 1})
        $value = $this->evaluateArithmetic($condition, $context);
        if ($value === null) {
            $value = $this->resolveValueWithFilters($condition, $context);
        }
        return !empty($value);
    }
    
    /**
     * Resolve one side of a condition comparison.
     * Handles: quoted strings, parenthesized filter expressions, arithmetic, variables with filters, numeric literals.
     */
    private function resolveConditionOperand(string $raw, array $context)
    {
        // Strip balanced outer parentheses: (items | count) → items | count
        if (preg_match('/^\((.+)\)$/', $raw, $pm)) {
            $inner = $pm[1];
            $depth = 0;
            $balanced = true;
            for ($ci = 0, $cl = strlen($inner); $ci < $cl; $ci++) {
                if ($inner[$ci] === '(') $depth++;
                elseif ($inner[$ci] === ')') { $depth--; if ($depth < 0) { $balanced = false; break; } }
            }
            if ($balanced && $depth === 0) {
                $raw = $inner;
            }
        }
        
        // Quoted string
        if (preg_match('/^["\'](.*)["\']\s*$/', $raw, $qm)) {
            return $qm[1];
        }
        
        // Try arithmetic (e.g. "page + 1")
        $arith = $this->evaluateArithmetic($raw, $context);
        if ($arith !== null) return $arith;
        
        // Try variable resolution (supports piped filters: items | count)
        $resolved = $this->resolveValueWithFilters($raw, $context);
        if ($resolved !== null) return $resolved;
        
        // Numeric literal
        if (is_numeric($raw)) return $raw + 0;
        
        // Fallback: return as string literal
        return $raw;
    }
    
    /**
     * Parse attributes from a string
     */
    private function parseAttributes(string $attrString, array $context): array
    {
        $attrs = [];
        $attrString = preg_replace('/\s+/', ' ', trim($attrString));
        
        // Match key="value" or key='value' or key={var}
        $pattern = '/([\w-]+)=(?:"([^"]*)"|\'([^\']*)\'|\{([^}]+)\})/';
        
        if (preg_match_all($pattern, $attrString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                
                if (!empty($match[4])) {
                    // Bare variable: key={variable}
                    $attrs[$key] = $this->resolveValueWithFilters($match[4], $context);
                } else {
                    // Quoted value - get from double-quote or single-quote capture group
                    // Note: $match[2] is double-quoted, $match[3] is single-quoted
                    $value = (isset($match[2]) && $match[2] !== '') ? $match[2] : ($match[3] ?? '');
                    
                    // Only resolve template variables like {var.name}, not JSON like {"key": "value"}
                    // Template vars start with letter/underscore, JSON starts with quote
                    if (preg_match('/\{[a-zA-Z_]/', $value)) {
                        $value = preg_replace_callback(
                            '/\{([a-zA-Z_][\w.]*(?:\s*\|\s*[^}]+)?)\}/',
                            fn($m) => $this->resolveValueWithFilters($m[1], $context) ?? '',
                            $value
                        );
                    }
                    $attrs[$key] = $value;
                }
            }
        }
        
        // Boolean attributes
        if (preg_match_all('/(?:^|\s)(\w+)(?=\s|$)/', $attrString, $booleans)) {
            foreach ($booleans[1] as $attr) {
                if (!isset($attrs[$attr]) && !in_array($attr, ['ikb_button', 'ikb_card', 'island'])) {
                    $attrs[$attr] = true;
                }
            }
        }
        
        return $attrs;
    }
    
    /**
     * Parse inline object {key: value, key2: value2}
     */
    private function parseInlineObject(string $str, array $context): array
    {
        $result = [];
        $str = trim($str, '{}');
        
        // Split by comma, respecting quotes
        $pairs = $this->splitByComma($str);
        
        foreach ($pairs as $pair) {
            if (strpos($pair, ':') !== false) {
                list($key, $value) = explode(':', $pair, 2);
                $key = trim($key);
                $value = trim($value, ' "\'');
                $result[$key] = $this->resolveValue($value, $context) ?? $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Split by pipe, respecting quotes
     */
    private function splitByPipe(string $expr): array
    {
        return $this->splitByChar($expr, '|');
    }
    
    /**
     * Split by comma, respecting quotes
     */
    private function splitByComma(string $expr): array
    {
        return $this->splitByChar($expr, ',');
    }
    
    /**
     * Generic split by character, respecting quotes
     */
    private function splitByChar(string $expr, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $len = strlen($expr);
        
        for ($i = 0; $i < $len; $i++) {
            $char = $expr[$i];
            
            if (!$inQuote && ($char === '"' || $char === "'")) {
                $inQuote = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($inQuote && $char === $quoteChar) {
                $inQuote = false;
                $quoteChar = '';
                $current .= $char;
            } elseif (!$inQuote && $char === $delimiter) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        
        if ($current !== '') {
            $parts[] = $current;
        }
        
        return $parts;
    }
    
    /**
     * Apply a filter
     */
    private function normalizeFilterArg(string $filterName, string $arg, array $context)
    {
        $arg = trim($arg);
        if ($arg === '') {
            return '';
        }

        if (preg_match('/^["\'](.*)["\']\s*$/', $arg, $matches)) {
            return $matches[1];
        }

        if ($filterName === 'default') {
            if (is_numeric($arg)) {
                return $arg + 0;
            }

            return $this->resolveValueWithFilters($arg, $context);
        }

        return $arg;
    }

    private function applyFilter(string $filter, $value, array $context)
    {
        $parts = explode(':', $filter, 2);
        $filterName = trim($parts[0]);
        $args = isset($parts[1])
            ? array_map(fn($arg) => $this->normalizeFilterArg($filterName, $arg, $context), $this->splitByComma($parts[1]))
            : [];
        
        if (isset($this->filters[$filterName])) {
            return call_user_func($this->filters[$filterName], $value, $args, $context);
        }
        
        return $value;
    }
    
    /**
     * Log an error
     */
    private function logError(string $message): void
    {
        $this->errors[] = $message;
        if ($this->debug) {
            error_log("[DiSyL] {$message}");
        }
    }
    
    public function registerFilter(string $name, callable $callback): void
    {
        $this->filters[$name] = $callback;
    }
    
    public function registerComponent(string $name, callable $callback): void
    {
        $this->components[$name] = $callback;
    }
    
    private function registerDefaultFilters(): void
    {
        $this->filters = [
            'esc_html' => fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
            'esc_attr' => fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
            'esc_url' => function($v) {
                $url = filter_var((string) $v, FILTER_SANITIZE_URL);
                // Reject protocol-relative URLs that resolve to external hosts (e.g. //evil.com)
                if (str_starts_with($url, '//')) {
                    return '#';
                }
                // Reject dangerous schemes that survive FILTER_SANITIZE_URL
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if ($scheme !== '' && !in_array($scheme, ['http', 'https', 'mailto', 'tel', 'ftp'], true)) {
                    return '#';
                }
                return $url;
            },
            'esc_js' => fn($v) => str_replace(
                ['\\', "'", '"', "\n", "\r", '</', "\xe2\x80\xa8", "\xe2\x80\xa9"],
                ['\\\\', "\\'", '\\"', '\\n', '\\r', '<\\/', '\\u2028', '\\u2029'],
                (string) $v
            ),
            'raw' => fn($v) => $v,
            'upper' => fn($v) => strtoupper((string) $v),
            'lower' => fn($v) => strtolower((string) $v),
            'capitalize' => fn($v) => ucfirst((string) $v),
            'title' => fn($v) => ucwords(str_replace('_', ' ', (string) $v)),
            'trim' => fn($v) => trim((string) $v),
            'truncate' => fn($v, $a) => mb_strlen((string)$v) > (int)($a[0] ?? 100) 
                ? mb_substr((string)$v, 0, (int)($a[0] ?? 100)) . '...' 
                : (string)$v,
            'nl2br' => fn($v) => nl2br((string) $v),
            'json' => fn($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'date' => fn($v, $a) => $v ? date($a[0] ?? 'Y-m-d', is_numeric($v) ? (int)$v : strtotime((string)$v)) : '',
            'default' => fn($v, $a) => ($v !== null && $v !== '') ? $v : ($a[0] ?? ''),
            'count' => fn($v) => is_countable($v) ? count($v) : 0,
            'join' => fn($v, $a) => is_array($v) ? implode($a[0] ?? ', ', $v) : $v,
            'first' => fn($v) => is_array($v) ? reset($v) : (is_string($v) ? mb_substr($v, 0, 1) : $v),
            'last' => fn($v) => is_array($v) ? end($v) : $v,
            'keys' => fn($v) => is_array($v) ? array_keys($v) : [],
            'values' => fn($v) => is_array($v) ? array_values($v) : [],
            'number_format' => fn($v, $a) => number_format((float)$v, (int)($a[0] ?? 0)),
            'abs' => fn($v) => abs((float)$v),
            'round' => fn($v, $a) => round((float)$v, (int)($a[0] ?? 0)),
            'floor' => fn($v) => floor((float)$v),
            'ceil' => fn($v) => ceil((float)$v),
            'length' => fn($v) => is_array($v) ? count($v) : mb_strlen((string)$v),
            'reverse' => fn($v) => is_array($v) ? array_reverse($v) : strrev((string)$v),
            'sort' => function($v) { if (is_array($v)) { sort($v); return $v; } return $v; },
            'unique' => fn($v) => is_array($v) ? array_unique($v) : $v,
            'slice' => fn($v, $a) => is_array($v) 
                ? array_slice($v, (int)($a[0] ?? 0), isset($a[1]) ? (int)$a[1] : null)
                : mb_substr((string)$v, (int)($a[0] ?? 0), isset($a[1]) ? (int)$a[1] : null),
            'split' => fn($v, $a) => explode($a[0] ?? ',', (string)$v),
            'replace' => fn($v, $a) => str_replace($a[0] ?? '', $a[1] ?? '', (string)$v),
            'strip_tags' => fn($v) => strip_tags((string)$v),
            'url_encode' => fn($v) => urlencode((string)$v),
            'base64' => fn($v) => base64_encode((string)$v),
            'md5' => fn($v) => md5((string)$v),
            'pluralize' => fn($v, $a) => (int)$v === 1 ? ($a[0] ?? '') : ($a[1] ?? (($a[0] ?? '') . 's')),
        ];
    }
    
    private function registerDefaultComponents(): void
    {
        // Custom components registered here
    }
    
    private function resolveTemplatePath(string $template): string
    {
        if (pathinfo($template, PATHINFO_EXTENSION) !== 'disyl') {
            $template .= '.disyl';
        }

        if (str_starts_with($template, '_cms_active_theme/') && function_exists('cmsResolveThemeTemplateAliasPath')) {
            $resolvedPath = cmsResolveThemeTemplateAliasPath($template);
            if ($resolvedPath !== '') {
                return $resolvedPath;
            }
        }

        if (str_starts_with($template, '/')) {
            // Absolute paths are used by trusted kernel/module callers only.
            // Block path traversal even in absolute paths: normalize and verify
            // no '..' segments remain that could escape expected directories.
            $normalized = $this->normalizePath($template);
            if (str_contains($normalized, '/../') || str_ends_with($normalized, '/..')) {
                $this->logError("Path traversal attempt blocked in absolute path: {$template}");
                return '';
            }
            return $normalized;
        }

        // Normalize to detect and block path traversal (e.g. ../../etc/passwd.disyl)
        $candidate = $this->templateDir . '/' . $template;
        $normalizedCandidate = $this->normalizePath($candidate);
        $normalizedTemplateDir = $this->normalizePath($this->templateDir);

        if (!str_starts_with($normalizedCandidate, $normalizedTemplateDir . '/')) {
            $this->logError("Path traversal attempt blocked: {$template}");
            return ''; // Will trigger "Template not found" gracefully
        }

        return $candidate;
    }

    /**
     * Normalize a filesystem path by resolving '..' and '.' segments.
     * Works on paths that may not exist on disk (unlike realpath()).
     */
    private function normalizePath(string $path): string
    {
        $parts = explode('/', $path);
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }
        return '/' . implode('/', $normalized);
    }

    /**
     * Render a component
     */
    private function renderComponent(string $component, array $attrs, string $children, array $context): string
    {
        if (isset($this->components[$component])) {
            return call_user_func($this->components[$component], $attrs, $children, $context);
        }
        
        return match($component) {
            'ikb_section' => $this->renderSection($attrs, $children),
            'ikb_container' => $this->renderContainer($attrs, $children),
            'ikb_grid' => $this->renderGrid($attrs, $children),
            'ikb_card' => $this->renderCard($attrs, $children),
            'ikb_text' => $this->renderText($attrs, $children),
            'ikb_button' => $this->renderButton($attrs, $children),
            'ikb_badge' => $this->renderBadge($attrs, $children),
            'ikb_input' => $this->renderInput($attrs),
            'ikb_textarea' => $this->renderTextarea($attrs, $children),
            'ikb_select' => $this->renderSelect($attrs, $children),
            'ikb_icon' => $this->renderIcon($attrs),
            'ikb_image' => $this->renderImage($attrs),
            'ikb_link' => $this->renderLink($attrs, $children),
            'ikb_table' => $this->renderTable($attrs, $children),
            'ikb_modal' => $this->renderModal($attrs, $children),
            'ikb_alert' => $this->renderAlert($attrs, $children),
            'ikb_spinner' => $this->renderSpinner($attrs),
            'island' => $this->renderIsland($attrs, $children),
            default => $children,
        };
    }
    
    private function buildHtmxAttrs(array $attrs): string
    {
        $htmxAttrs = [];
        $htmxKeys = ['hx-get', 'hx-post', 'hx-put', 'hx-delete', 'hx-patch',
                     'hx-trigger', 'hx-target', 'hx-swap', 'hx-push-url',
                     'hx-select', 'hx-indicator', 'hx-confirm', 'hx-vals',
                     'hx-boost', 'hx-ext', 'hx-headers', 'hx-include',
                     'hx-params', 'hx-preserve', 'hx-prompt', 'hx-replace-url'];
        
        foreach ($htmxKeys as $key) {
            $camelKey = str_replace('-', '_', $key);
            $value = $attrs[$key] ?? $attrs[$camelKey] ?? null;
            
            if ($value !== null) {
                // For all HTMX attributes, use double quotes and htmlspecialchars to prevent
                // attribute injection regardless of the attribute value's content.
                $htmxAttrs[] = "{$key}=\"" . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . "\"";
            }
        }
        
        return !empty($htmxAttrs) ? ' ' . implode(' ', $htmxAttrs) : '';
    }
    
    /**
     * Sanitize a URL for use in an href attribute.
     * Rejects javascript:, vbscript:, and data: schemes to prevent XSS.
     */
    private function sanitizeHref(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '#';
        }
        // Check scheme — strip everything before first colon and compare
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https', 'mailto', 'tel', 'ftp'], true)) {
            return '#';
        }
        return htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    }

    private function renderSection(array $attrs, string $children): string
    {
        $padding = $attrs['padding'] ?? 'medium';
        $bg = $attrs['background'] ?? '';
        $class = $attrs['class'] ?? '';
        $id = isset($attrs['id']) ? ' id="' . htmlspecialchars((string) $attrs['id'], ENT_QUOTES, 'UTF-8') . '"' : '';
        
        $paddingClass = match($padding) {
            'none' => '', 'small' => 'py-4', 'medium' => 'py-8',
            'large' => 'py-12', 'xlarge' => 'py-16', default => 'py-8',
        };
        
        $bgClass = match($bg) {
            'white' => 'bg-white', 'gray' => 'bg-gray-50',
            'dark' => 'bg-gray-900 text-white', 'primary' => 'bg-indigo-600 text-white',
            'gradient' => 'bg-gradient-to-br from-indigo-500 to-purple-600 text-white',
            default => '',
        };
        
        $htmx = $this->buildHtmxAttrs($attrs);
        return "<section{$id} class=\"{$paddingClass} {$bgClass} {$class}\"{$htmx}>{$children}</section>";
    }
    
    private function renderContainer(array $attrs, string $children): string
    {
        $size = $attrs['size'] ?? 'large';
        $class = $attrs['class'] ?? '';
        
        $sizeClass = match($size) {
            'small' => 'max-w-2xl', 'medium' => 'max-w-4xl', 'large' => 'max-w-6xl',
            'xlarge' => 'max-w-7xl', 'full' => 'max-w-full', default => 'max-w-6xl',
        };
        
        $htmx = $this->buildHtmxAttrs($attrs);
        return "<div class=\"container mx-auto px-4 {$sizeClass} {$class}\"{$htmx}>{$children}</div>";
    }
    
    private function renderGrid(array $attrs, string $children): string
    {
        $columns = $attrs['columns'] ?? '3';
        $gap = $attrs['gap'] ?? 'medium';
        $class = $attrs['class'] ?? '';
        
        $colClass = "grid-cols-1 md:grid-cols-{$columns}";
        $gapClass = match($gap) {
            'none' => 'gap-0', 'small' => 'gap-2', 'medium' => 'gap-4',
            'large' => 'gap-6', 'xlarge' => 'gap-8', default => 'gap-4',
        };
        
        $htmx = $this->buildHtmxAttrs($attrs);
        return "<div class=\"grid {$colClass} {$gapClass} {$class}\"{$htmx}>{$children}</div>";
    }
    
    private function renderCard(array $attrs, string $children): string
    {
        $variant = $attrs['variant'] ?? 'default';
        $padding = $attrs['padding'] ?? 'medium';
        $class = $attrs['class'] ?? '';
        $id = isset($attrs['id']) ? ' id="' . htmlspecialchars((string) $attrs['id'], ENT_QUOTES, 'UTF-8') . '"' : '';
        
        $variantClass = match($variant) {
            'elevated' => 'bg-white shadow-lg hover:shadow-xl transition-shadow',
            'outlined' => 'bg-white border border-gray-200',
            'flat' => 'bg-gray-50', 'stat' => 'bg-white shadow rounded-lg text-center',
            default => 'bg-white shadow rounded-lg',
        };
        
        $paddingClass = match($padding) {
            'none' => '', 'small' => 'p-3', 'medium' => 'p-4', 'large' => 'p-6', default => 'p-4',
        };
        
        $htmx = $this->buildHtmxAttrs($attrs);
        return "<div{$id} class=\"rounded-lg {$variantClass} {$paddingClass} {$class}\"{$htmx}>{$children}</div>";
    }
    
    private function renderText(array $attrs, string $children): string
    {
        $allowedTags = ['p', 'span', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'small', 'label', 'li', 'dt', 'dd', 'figcaption'];
        $requestedTag = (string) ($attrs['tag'] ?? 'p');
        $tag = in_array($requestedTag, $allowedTags, true) ? $requestedTag : 'p';
        $size = $attrs['size'] ?? 'base';
        $weight = $attrs['weight'] ?? 'normal';
        $color = $attrs['color'] ?? '';
        $align = $attrs['align'] ?? '';
        $class = $attrs['class'] ?? '';
        
        $sizeClass = match($size) {
            'xs' => 'text-xs', 'sm' => 'text-sm', 'base' => 'text-base',
            'lg' => 'text-lg', 'xl' => 'text-xl', '2xl' => 'text-2xl',
            '3xl' => 'text-3xl', '4xl' => 'text-4xl', default => 'text-base',
        };
        
        $weightClass = match($weight) {
            'light' => 'font-light', 'normal' => 'font-normal', 'medium' => 'font-medium',
            'semibold' => 'font-semibold', 'bold' => 'font-bold', default => '',
        };
        
        $colorClass = match($color) {
            'muted' => 'text-gray-500', 'primary' => 'text-indigo-600',
            'success' => 'text-green-600', 'warning' => 'text-yellow-600',
            'danger' => 'text-red-600', 'white' => 'text-white', default => '',
        };
        
        $alignClass = match($align) {
            'left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right', default => '',
        };
        
        return "<{$tag} class=\"{$sizeClass} {$weightClass} {$colorClass} {$alignClass} {$class}\">{$children}</{$tag}>";
    }
    
    private function renderButton(array $attrs, string $children): string
    {
        $variant = $attrs['variant'] ?? 'primary';
        $size = $attrs['size'] ?? 'medium';
        $href = $attrs['href'] ?? '';
        $type = $attrs['type'] ?? 'button';
        $class = $attrs['class'] ?? '';
        $disabled = isset($attrs['disabled']) && $attrs['disabled'];
        
        $variantClass = match($variant) {
            'primary' => 'bg-indigo-600 text-white hover:bg-indigo-700',
            'secondary' => 'bg-gray-200 text-gray-800 hover:bg-gray-300',
            'success' => 'bg-green-600 text-white hover:bg-green-700',
            'danger' => 'bg-red-600 text-white hover:bg-red-700',
            'warning' => 'bg-yellow-500 text-white hover:bg-yellow-600',
            'outline' => 'border border-indigo-600 text-indigo-600 hover:bg-indigo-50',
            'ghost' => 'text-gray-600 hover:bg-gray-100',
            'link' => 'text-indigo-600 hover:underline',
            default => 'bg-indigo-600 text-white hover:bg-indigo-700',
        };
        
        $sizeClass = match($size) {
            'small' => 'px-3 py-1.5 text-sm', 'medium' => 'px-4 py-2',
            'large' => 'px-6 py-3 text-lg', default => 'px-4 py-2',
        };
        
        $disabledClass = $disabled ? 'opacity-50 cursor-not-allowed' : '';
        $disabledAttr = $disabled ? ' disabled' : '';
        $htmx = $this->buildHtmxAttrs($attrs);
        
        if ($href && !$disabled) {
            $safeHref = $this->sanitizeHref($href);
            return "<a href=\"{$safeHref}\" class=\"inline-flex items-center justify-center rounded-lg font-medium transition {$variantClass} {$sizeClass} {$class}\"{$htmx}>{$children}</a>";
        }
        
        return "<button type=\"{$type}\" class=\"inline-flex items-center justify-center rounded-lg font-medium transition {$variantClass} {$sizeClass} {$disabledClass} {$class}\"{$disabledAttr}{$htmx}>{$children}</button>";
    }
    
    private function renderBadge(array $attrs, string $children): string
    {
        $variant = $attrs['variant'] ?? 'default';
        $class = $attrs['class'] ?? '';
        
        $variantClass = match($variant) {
            'primary' => 'bg-indigo-100 text-indigo-800',
            'success' => 'bg-green-100 text-green-800',
            'warning' => 'bg-yellow-100 text-yellow-800',
            'danger', 'critical', 'high' => 'bg-red-100 text-red-800',
            'info' => 'bg-blue-100 text-blue-800',
            'medium' => 'bg-yellow-100 text-yellow-800',
            'low' => 'bg-gray-100 text-gray-800',
            'open' => 'bg-blue-100 text-blue-800',
            'in_progress' => 'bg-indigo-100 text-indigo-800',
            'closed' => 'bg-green-100 text-green-800',
            'on_hold' => 'bg-yellow-100 text-yellow-800',
            'scheduled' => 'bg-blue-100 text-blue-800',
            'completed' => 'bg-green-100 text-green-800',
            'cancelled' => 'bg-red-100 text-red-800',
            'no_show' => 'bg-yellow-100 text-yellow-800',
            default => 'bg-gray-100 text-gray-800',
        };
        
        return "<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {$variantClass} {$class}\">{$children}</span>";
    }
    
    private function renderInput(array $attrs): string
    {
        $type = $attrs['type'] ?? 'text';
        $name = $attrs['name'] ?? '';
        $id = $attrs['id'] ?? $name;
        $value = htmlspecialchars($attrs['value'] ?? '', ENT_QUOTES);
        $placeholder = htmlspecialchars($attrs['placeholder'] ?? '', ENT_QUOTES);
        $required = isset($attrs['required']) ? ' required' : '';
        $disabled = isset($attrs['disabled']) ? ' disabled' : '';
        $class = $attrs['class'] ?? '';
        $htmx = $this->buildHtmxAttrs($attrs);
        
        return "<input type=\"{$type}\" id=\"{$id}\" name=\"{$name}\" value=\"{$value}\" placeholder=\"{$placeholder}\" class=\"w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {$class}\"{$required}{$disabled}{$htmx}>";
    }
    
    private function renderTextarea(array $attrs, string $children): string
    {
        $name = $attrs['name'] ?? '';
        $id = $attrs['id'] ?? $name;
        $rows = $attrs['rows'] ?? '4';
        $placeholder = htmlspecialchars($attrs['placeholder'] ?? '', ENT_QUOTES);
        $required = isset($attrs['required']) ? ' required' : '';
        $class = $attrs['class'] ?? '';
        
        return "<textarea id=\"{$id}\" name=\"{$name}\" rows=\"{$rows}\" placeholder=\"{$placeholder}\" class=\"w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {$class}\"{$required}>{$children}</textarea>";
    }
    
    private function renderSelect(array $attrs, string $children): string
    {
        $name = $attrs['name'] ?? '';
        $id = $attrs['id'] ?? $name;
        $required = isset($attrs['required']) ? ' required' : '';
        $class = $attrs['class'] ?? '';
        $htmx = $this->buildHtmxAttrs($attrs);
        
        return "<select id=\"{$id}\" name=\"{$name}\" class=\"w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {$class}\"{$required}{$htmx}>{$children}</select>";
    }
    
    private function renderIcon(array $attrs): string
    {
        $name = $attrs['name'] ?? 'circle';
        $size = $attrs['size'] ?? 'md';
        $class = $attrs['class'] ?? '';
        
        $sizeClass = match($size) {
            'sm' => 'w-4 h-4', 'md' => 'w-5 h-5', 'lg' => 'w-6 h-6', 'xl' => 'w-8 h-8', default => 'w-5 h-5',
        };
        
        return "<i class=\"fas fa-{$name} {$sizeClass} {$class}\"></i>";
    }
    
    private function renderImage(array $attrs): string
    {
        $src = htmlspecialchars($attrs['src'] ?? '', ENT_QUOTES);
        $alt = htmlspecialchars($attrs['alt'] ?? '', ENT_QUOTES);
        $class = $attrs['class'] ?? '';
        
        return "<img src=\"{$src}\" alt=\"{$alt}\" class=\"{$class}\" loading=\"lazy\">";
    }
    
    private function renderLink(array $attrs, string $children): string
    {
        $href = $this->sanitizeHref($attrs['href'] ?? '#');
        $class = htmlspecialchars($attrs['class'] ?? 'text-indigo-600 hover:underline', ENT_QUOTES, 'UTF-8');
        $htmx = $this->buildHtmxAttrs($attrs);
        
        return "<a href=\"{$href}\" class=\"{$class}\"{$htmx}>{$children}</a>";
    }
    
    private function renderTable(array $attrs, string $children): string
    {
        $class = $attrs['class'] ?? '';
        return "<div class=\"overflow-x-auto\"><table class=\"min-w-full divide-y divide-gray-200 {$class}\">{$children}</table></div>";
    }
    
    private function renderModal(array $attrs, string $children): string
    {
        $id = $attrs['id'] ?? 'modal';
        $title = htmlspecialchars($attrs['title'] ?? '', ENT_QUOTES);
        $size = $attrs['size'] ?? 'medium';
        
        $sizeClass = match($size) {
            'small' => 'max-w-md', 'medium' => 'max-w-lg', 'large' => 'max-w-2xl',
            'xlarge' => 'max-w-4xl', default => 'max-w-lg',
        };
        
        return "<div id=\"{$id}\" class=\"hidden fixed inset-0 z-50 overflow-y-auto\" aria-modal=\"true\">
            <div class=\"flex items-center justify-center min-h-screen px-4\">
                <div class=\"fixed inset-0 bg-black bg-opacity-50\" onclick=\"document.getElementById('{$id}').classList.add('hidden')\"></div>
                <div class=\"relative bg-white rounded-lg shadow-xl {$sizeClass} w-full\">
                    <div class=\"flex items-center justify-between p-4 border-b\">
                        <h3 class=\"text-lg font-semibold\">{$title}</h3>
                        <button onclick=\"document.getElementById('{$id}').classList.add('hidden')\" class=\"text-gray-400 hover:text-gray-600\">
                            <i class=\"fas fa-times\"></i>
                        </button>
                    </div>
                    <div class=\"p-4\">{$children}</div>
                </div>
            </div>
        </div>";
    }
    
    private function renderAlert(array $attrs, string $children): string
    {
        $variant = $attrs['variant'] ?? 'info';
        $class = $attrs['class'] ?? '';
        
        $config = match($variant) {
            'success' => ['bg-green-50 border-green-500 text-green-800', 'check-circle'],
            'warning' => ['bg-yellow-50 border-yellow-500 text-yellow-800', 'exclamation-triangle'],
            'danger', 'error' => ['bg-red-50 border-red-500 text-red-800', 'exclamation-circle'],
            default => ['bg-blue-50 border-blue-500 text-blue-800', 'info-circle'],
        };
        
        return "<div class=\"flex items-start p-4 border-l-4 rounded-r-lg {$config[0]} {$class}\">
            <i class=\"fas fa-{$config[1]} mr-3 mt-0.5\"></i>
            <div>{$children}</div>
        </div>";
    }
    
    private function renderSpinner(array $attrs): string
    {
        $size = $attrs['size'] ?? 'md';
        $class = $attrs['class'] ?? '';
        
        $sizeClass = match($size) {
            'sm' => 'w-4 h-4', 'md' => 'w-6 h-6', 'lg' => 'w-8 h-8', default => 'w-6 h-6',
        };
        
        return "<div class=\"animate-spin rounded-full border-2 border-gray-300 border-t-indigo-600 {$sizeClass} {$class}\"></div>";
    }
    
    private function renderIsland(array $attrs, string $children): string
    {
        $name = $attrs['name'] ?? 'island';
        $strategy = $attrs['strategy'] ?? 'load';
        $class = $attrs['class'] ?? '';
        
        $strategyAttr = match($strategy) {
            'visible' => 'data-hydrate="visible"', 'idle' => 'data-hydrate="idle"',
            'interaction' => 'data-hydrate="interaction"', default => 'data-hydrate="load"',
        };
        
        return "<div data-island=\"{$name}\" {$strategyAttr} class=\"{$class}\">{$children}</div>";
    }
}
