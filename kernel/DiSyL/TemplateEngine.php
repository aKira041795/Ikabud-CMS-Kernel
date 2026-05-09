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

use Ikabud\Kernel\DiSyL\v4\RenderContext;

class TemplateEngine
{
    private string $templateDir;
    private string $cacheDir;
    private bool $cacheEnabled;
    private bool $debug = false;
    private bool $compiledMode = false;
    private bool $strictMode = false;
    private ?Compiler\TemplateCache $compiledCache = null;
    private array $components = [];
    private array $filters = [];
    private array $globals = [];
    private array $errors = [];

    /** DiSyL 4.3 — fragment cache + experiments (lazy-instantiated). */
    private ?\Ikabud\Kernel\DiSyL\Cache\FragmentStore $fragmentStore = null;
    private ?\Ikabud\Kernel\DiSyL\Experiments\Bucketer $bucketer = null;
    private ?string $tenantId = null;
    private ?string $subjectId = null;
    private ?string $requestId = null;

    /** DiSyL 4.4 — sandbox runtime (lazy-instantiated). */
    private ?\Ikabud\Kernel\DiSyL\Security\Sandbox $sandbox = null;

    /** DiSyL 4.5 — async runtime (lazy-instantiated). */
    private ?\Ikabud\Kernel\DiSyL\Async\HttpClient $httpClient = null;

    /** DiSyL 4.6 — federation registry (lazy). */
    private ?\Ikabud\Kernel\DiSyL\Federation\ServiceRegistry $serviceRegistry = null;

    /** DiSyL 4.6 — AI provider (lazy = EchoAiProvider). */
    private ?\Ikabud\Kernel\DiSyL\AI\AiProvider $aiProvider = null;

    /** DiSyL 4.6 — AI policy (lazy). */
    private ?\Ikabud\Kernel\DiSyL\AI\Policy $aiPolicy = null;

    /** @var string|null Template path being rendered (set during top-level render) */
    private ?string $currentTemplatePath = null;

    /** @var string Directory for cross-request extends resolution cache */
    private string $extendsCacheDir;
    
    public function __construct(string $templateDir, string $cacheDir, bool $cacheEnabled = true)
    {
        $this->templateDir = rtrim($templateDir, '/');
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->cacheEnabled = $cacheEnabled;
        $this->extendsCacheDir = $this->cacheDir . '/disyl-extends';
        
        $this->registerDefaultFilters();
        $this->registerDefaultComponents();
    }
    
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * Enable strict mode: warn on undefined variables and log | raw filter usage.
     * Controlled via DISYL_STRICT_MODE env var (wired in App.php).
     */
    public function enableStrictMode(bool $enable = true): void
    {
        $this->strictMode = $enable;
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
                $this->logError('Compiled mode unavailable: v4 TemplateCache class not found');
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

    /** @var array<string, bool> Per-request cache of compiled-mode eligibility */
    private array $compiledEligibilityCache = [];

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

    /** Shared APCu rendered-output cache TTL (seconds). 0 = disabled. */
    private int $sharedOutputCacheTtl = 0;

    /** Whether the cache authority warning has already been emitted this process. */
    private static bool $cacheAuthorityWarningEmitted = false;

    /** @var array<string,int> Aggregate cache metrics for the current FPM worker */
    private static array $cacheMetrics = [
        'output_hits' => 0,
        'output_misses' => 0,
        'source_hits' => 0,
        'source_misses' => 0,
        'compiles' => 0,
    ];

    /** @var int Render calls since last metrics log */
    private static int $rendersSinceMetricsLog = 0;

    /** Emit cache metrics log every N renders */
    private const CACHE_METRICS_LOG_INTERVAL = 100;
    
    public function render(string $template, array $context = []): string
    {
        $this->errors = [];
        $templatePath = $this->resolveTemplatePath($template);
        
        if (!file_exists($templatePath)) {
            $this->logError("Template not found: {$template}");
            throw new \RuntimeException("Template not found: {$template}");
        }

        $context = array_merge($this->globals, $context);
        $sharedCacheKey = null;
        if ($this->sharedOutputCacheTtl > 0 && $this->cacheEnabled && $this->hasApcuCache()) {
            $sharedCacheKey = $this->buildSharedOutputCacheKey($templatePath, $context);
            $shared = apcu_fetch($sharedCacheKey, $sharedHit);
            if ($sharedHit && is_string($shared)) {
                self::$cacheMetrics['output_hits']++;
                $this->logCacheMetricsPeriodic();
                if (function_exists('log_timing')) {
                    log_timing('disyl.render.breakdown', microtime(true) - 0.0001, [
                        'template' => $template,
                        'cache_path' => 'apcu_output_hit',
                        'output_bytes' => strlen($shared),
                    ]);
                }
                return $shared;
            }
            self::$cacheMetrics['output_misses']++;
        }

        // Compiled-mode fast path: use pre-compiled PHP class when available.
        // Templates that still rely on interpreted-only component tags must stay
        // on the interpreted pipeline to avoid leaking raw DiSyL markup.
        if ($this->compiledMode && $this->compiledCache !== null && $this->isCompiledEligibleTemplate($templatePath)) {
            try {
                $compiled = $this->compiledCache->get($templatePath);
                
                $loader = function(string $tmpl) use (&$loader) {
                    $path = $this->resolveTemplatePath($tmpl);
                    $c = $this->compiledCache->get($path);
                    $c->setTemplateLoader($loader);
                    // Provide consistent filter state to loaded includes
                    $registry = new \Ikabud\Kernel\DiSyL\v4\FilterRegistry();
                    foreach ($this->filters as $name => $f) {
                        $registry->register($name, $f);
                    }
                    $c->setFilters($registry);
                    return $c;
                };
                
                $registry = new \Ikabud\Kernel\DiSyL\v4\FilterRegistry();
                foreach ($this->filters as $name => $f) {
                    $registry->register($name, $f);
                }
                $compiled->setTemplateLoader($loader);
                $compiled->setFilters($registry);
                
                $ctx_obj = new RenderContext($context);
                $result = $compiled->executeRaw($ctx_obj);
                // Handle {extends} chain: child registers blocks, parent reads them
                $maxExtendsDepth = 10;
                while ($ctx_obj->getParentTemplate() !== null && $maxExtendsDepth-- > 0) {
                    $parentName = $ctx_obj->getParentTemplate();
                    $ctx_obj->setParentTemplate(null); // prevent infinite loop
                    $parentPath = $this->resolveTemplatePath($parentName);
                    $parentCompiled = $this->compiledCache->get($parentPath);
                    $parentCompiled->setTemplateLoader($loader);
                    $parentCompiled->setFilters($registry);
                    $result = $parentCompiled->executeRaw($ctx_obj);
                }
                if (strlen($result) > self::MAX_OUTPUT_BYTES) {
                    $this->logError("Template output exceeds maximum size (" . self::MAX_OUTPUT_BYTES . " bytes): {$template}");
                    throw new \RuntimeException("Template output exceeds maximum allowed size");
                }
                if ($sharedCacheKey !== null) {
                    apcu_store($sharedCacheKey, $result, $this->sharedOutputCacheTtl);
                }
                return $result;
            } catch (\RuntimeException $e) {
                throw $e; // re-throw size limit errors
            } catch (\Throwable $e) {
                // Compiled path failed — fall through to interpreted path
                $this->logError("Compiled render failed, falling back: " . $e->getMessage());
                if (function_exists('write_log')) {
                    write_log('disyl.compile.fallback', 'warning', [
                        'template' => $template,
                        'reason' => $e->getMessage(),
                        'fallback' => 'interpreted',
                    ]);
                }
            }
        }
        
        $sourceReadStart = microtime(true);
        $content = $this->readTemplateSource($templatePath);
        if ($content === false) {
            $this->logError("Failed to read template: {$template}");
            throw new \RuntimeException("Failed to read template: {$template}");
        }
        $sourceReadMs = round((microtime(true) - $sourceReadStart) * 1000, 2);
        $context = array_merge($this->globals, $context);

        // Track current template path for cross-request extends cache
        $prevTemplatePath = $this->currentTemplatePath;
        $this->currentTemplatePath = $templatePath;
        
        // In-memory cache for repeated renders within same request (e.g., HTMX partials)
        if ($this->cacheEnabled) {
            $memKey = $this->buildOutputCacheKey($templatePath, $context);
            if (isset($this->outputCache[$memKey])) {
                $this->currentTemplatePath = $prevTemplatePath;
                return $this->outputCache[$memKey];
            }
            
            $result = $this->compile($content, $context);
            if (function_exists('log_timing')) {
                log_timing('disyl.render.breakdown', $sourceReadStart, [
                    'template' => $template,
                    'source_read_ms' => $sourceReadMs,
                    'source_bytes' => strlen($content),
                    'output_bytes' => strlen($result),
                    'cache_path' => 'interpreted_cached',
                ]);
            }

            $this->currentTemplatePath = $prevTemplatePath;

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
            if ($sharedCacheKey !== null) {
                apcu_store($sharedCacheKey, $result, $this->sharedOutputCacheTtl);
            }
            $this->logCacheMetricsPeriodic();
            return $result;
        }
        
        $result = $this->compile($content, $context);

        $this->currentTemplatePath = $prevTemplatePath;

        if (strlen($result) > self::MAX_OUTPUT_BYTES) {
            $this->logError("Template output exceeds maximum size (" . self::MAX_OUTPUT_BYTES . " bytes): {$template}");
            throw new \RuntimeException("Template output exceeds maximum allowed size");
        }

        if ($sharedCacheKey !== null) {
            apcu_store($sharedCacheKey, $result, $this->sharedOutputCacheTtl);
        }

        $this->logCacheMetricsPeriodic();
        return $result;
    }

    private function buildOutputCacheKey(string $templatePath, array $context): string
    {
        $fastFingerprint = $this->tryBuildFastContextFingerprint($context);
        if ($fastFingerprint !== null) {
            return $templatePath . '|' . $fastFingerprint;
        }

        try {
            return $templatePath . '|' . md5(serialize($context));
        } catch (\Throwable $e) {
            // Non-serializable context payloads (e.g. closures) should not explode render path.
            return $templatePath . '|uncacheable|' . md5(spl_object_hash($this) . '|' . (string)microtime(true));
        }
    }

    private function buildSharedOutputCacheKey(string $templatePath, array $context): string
    {
        $mtime = (int)@filemtime($templatePath);
        return 'disyl:render:' . md5($templatePath . '|' . $mtime . '|' . $this->buildOutputCacheKey($templatePath, $context));
    }

    private function hasApcuCache(): bool
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled();
    }

    public function setSharedOutputCacheTtl(int $seconds): void
    {
        $this->sharedOutputCacheTtl = max(0, $seconds);
        if ($this->sharedOutputCacheTtl > 0 && !self::$cacheAuthorityWarningEmitted) {
            self::$cacheAuthorityWarningEmitted = true;
            if (function_exists('write_log')) {
                write_log('disyl.cache.authority_warning', 'warning', [
                    'shared_output_ttl' => $this->sharedOutputCacheTtl,
                    'message' => 'Shared output cache is active. Ensure it does not overlap with handler-level page caches to avoid stale content.',
                ]);
            }
        }
    }

    /** Return aggregate cache hit/miss counters for the current FPM worker. */
    public static function getCacheMetrics(): array
    {
        return self::$cacheMetrics;
    }

    /** Reset aggregate cache counters. */
    public static function resetCacheMetrics(): void
    {
        self::$cacheMetrics = array_map(fn() => 0, self::$cacheMetrics);
        self::$rendersSinceMetricsLog = 0;
        self::$cacheAuthorityWarningEmitted = false;
    }

    /** Emit a periodic cache metrics log entry. */
    private function logCacheMetricsPeriodic(): void
    {
        if (++self::$rendersSinceMetricsLog < self::CACHE_METRICS_LOG_INTERVAL) {
            return;
        }
        self::$rendersSinceMetricsLog = 0;
        if (function_exists('write_log')) {
            $m = self::$cacheMetrics;
            $totalOutput = $m['output_hits'] + $m['output_misses'];
            $totalSource = $m['source_hits'] + $m['source_misses'];
            write_log('disyl.cache.metrics', 'info', [
                'output_hit_pct' => $totalOutput > 0 ? round($m['output_hits'] / $totalOutput * 100, 1) : null,
                'source_hit_pct' => $totalSource > 0 ? round($m['source_hits'] / $totalSource * 100, 1) : null,
                'compiles' => $m['compiles'],
                'output_hits' => $m['output_hits'],
                'output_misses' => $m['output_misses'],
                'source_hits' => $m['source_hits'],
                'source_misses' => $m['source_misses'],
            ]);
        }
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
        self::$cacheMetrics['compiles']++;
        $compileStartedAt = microtime(true);
        $phases = [];

        if (!str_contains($content, '{') && stripos($content, '<script') === false) {
            return $content;
        }

        // 0. Extract {verbatim}...{/verbatim} blocks — truly inert, restored last
        $verbatims = [];
        if (str_contains($content, '{verbatim')) {
            $content = preg_replace_callback('/\{verbatim\}(.*?)\{\/verbatim\}/s', function($match) use (&$verbatims) {
                $key = '___VERBATIM_' . count($verbatims) . '___';
                $verbatims[$key] = $match[1];
                return $key;
            }, $content);
        }
        
        // 1. Remove comments first
        if (str_contains($content, '{!--') || str_contains($content, '{*')) {
            $content = $this->removeComments($content);
        }
        
        // 2. Process extends/layouts (merges child blocks into layout)
        if (str_contains($content, '{extends ')) {
            $t = microtime(true);
            $content = $this->processExtends($content, $context);
            $phases['extends_ms'] = round((microtime(true) - $t) * 1000, 2);
        }
        
        // 3. Remove comments again (layout may have comments)
        if (str_contains($content, '{!--') || str_contains($content, '{*')) {
            $content = $this->removeComments($content);
        }
        
        // 4. Process blocks (standalone)
        if (str_contains($content, '{block ')) {
            $content = $this->processBlocks($content, $context);
        }
        
        // 4b. Extract <script> blocks and process them with full DiSyL support.
        //     JS curly braces that are NOT DiSyL tags are protected by temporarily
        //     converting them to markers before control structure processing.
        $scripts = [];
        if (stripos($content, '<script') !== false) {
            $t = microtime(true);
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
            $phases['scripts_ms'] = round((microtime(true) - $t) * 1000, 2);
        }
        
        // 5. Extract {literal}...{/literal} blocks — after extends/blocks but before
        //    control structures, so they work correctly inside loop bodies
        $literals = [];
        if (str_contains($content, '{literal')) {
            $content = preg_replace_callback('/\{literal\}(.*?)\{\/literal\}/s', function($match) use (&$literals) {
                $key = '___LITERAL_' . count($literals) . '___';
                $literals[$key] = $match[1];
                return $key;
            }, $content);
        }
        
        // 6. Process {set var = expr} assignments (mutates context)
        if (str_contains($content, '{set ')) {
            $content = $this->processSetStatements($content, $context);
        }
        
        // 7. Process control structures (if/for/foreach) - token-based for proper nesting
        $t = microtime(true);
        $content = $this->processControlStructures($content, $context);
        $phases['control_ms'] = round((microtime(true) - $t) * 1000, 2);
        
        // 8. Process includes
        if (str_contains($content, '{include ')) {
            $t = microtime(true);
            $content = $this->processIncludes($content, $context);
            $phases['includes_ms'] = round((microtime(true) - $t) * 1000, 2);
        }
        
        // 9. Process components
        if (str_contains($content, '{ikb_') || str_contains($content, '{island')) {
            $content = $this->processComponents($content, $context);
        }

        // 9a. Process {capability} tags (capability-driven template calls)
        if (str_contains($content, '{capability ')) {
            $content = $this->processCapabilityTags($content, $context);
        }

        // 9b. Process {on} event-conditional rendering
        if (str_contains($content, '{on ')) {
            $content = $this->processOnTags($content, $context);
        }

        // 10. Process remaining variables (including arithmetic and ternary expressions)
        if (str_contains($content, '{')) {
            $t = microtime(true);
            $content = $this->processVariables($content, $context);
            $phases['variables_ms'] = round((microtime(true) - $t) * 1000, 2);
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

        // Emit phase breakdown (guarded by APP_TIMING_LOGS)
        $phases['total_ms'] = round((microtime(true) - $compileStartedAt) * 1000, 2);
        $phases['content_bytes'] = strlen($content);
        if (function_exists('log_timing')) {
            log_timing('disyl.compile.phases', $compileStartedAt, $phases);
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
        if (str_contains($body, '{include ')) {
            $body = $this->processIncludes($body, $context);
        }
        
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
        
        // Second pass: arithmetic (including parenthesized and chained expressions)
        if (strpbrk($content, '+-*/%()') !== false) {
            $content = preg_replace_callback(
                '/\{((?:[a-zA-Z_(]|\d)[^}]*[+\-*\/%][^}]*)\}/',
                function($match) use ($context) {
                    $result = $this->evaluateArithmetic(trim($match[1]), $context);
                    if ($result !== null) {
                        return (string) $result;
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
    private function evaluateArithmetic(string $expr, array $context): int|float|null
    {
        $tokens = $this->tokenizeArithExpr($expr);
        if ($tokens === null || count($tokens) === 0) {
            return null;
        }
        // Require at least one arithmetic operator — bare variable/literal lookups
        // must not be handled here (they are handled by resolveValue elsewhere).
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
            return null; // Not all tokens consumed — not a pure arithmetic expression
        }
        // Return as int when value is a whole number
        if (is_float($result) && $result == (int)$result) {
            return (int)$result;
        }
        return $result;
    }

    /**
     * Tokenize an arithmetic expression into an array of typed tokens:
     *   - int|float   : numeric literal
     *   - ['var', str]: variable path (dot-notation)
     *   - string      : single-char operator (+,-,*,/,%) or parenthesis
     * Returns null if the expression contains characters not valid in arithmetic.
     */
    private function tokenizeArithExpr(string $expr): ?array
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
            // Numeric literal (integer or decimal)
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $j = $i;
                while ($j < $len && (ctype_digit($expr[$j]) || $expr[$j] === '.')) {
                    $j++;
                }
                $num = substr($expr, $i, $j - $i);
                $tokens[] = str_contains($num, '.') ? (float)$num : (int)$num;
                $i = $j;
                continue;
            }
            // Identifier / variable path (dot-notation)
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($expr[$j]) || $expr[$j] === '_' || $expr[$j] === '.')) {
                    $j++;
                }
                $tokens[] = ['var', substr($expr, $i, $j - $i)];
                $i = $j;
                continue;
            }
            return null; // Unknown character — not a valid arithmetic expression
        }
        return $tokens;
    }

    /** Recursive-descent: additive level (+, -) */
    private function exprAdd(array $tokens, int &$pos, array $context): int|float|null
    {
        $left = $this->exprMul($tokens, $pos, $context);
        if ($left === null) return null;
        $n = count($tokens);
        while ($pos < $n && ($tokens[$pos] === '+' || $tokens[$pos] === '-')) {
            $op = $tokens[$pos++];
            $right = $this->exprMul($tokens, $pos, $context);
            if ($right === null) return null;
            $left = $op === '+' ? $left + $right : $left - $right;
        }
        return $left;
    }

    /** Recursive-descent: multiplicative level (*, /, %) */
    private function exprMul(array $tokens, int &$pos, array $context): int|float|null
    {
        $left = $this->exprUnary($tokens, $pos, $context);
        if ($left === null) return null;
        $n = count($tokens);
        while ($pos < $n && in_array($tokens[$pos], ['*', '/', '%'], true)) {
            $op = $tokens[$pos++];
            $right = $this->exprUnary($tokens, $pos, $context);
            if ($right === null) return null;
            if ($op === '*') $left = $left * $right;
            elseif ($op === '/') $left = $right != 0 ? $left / $right : 0;
            else $left = $right != 0 ? (int)$left % (int)$right : 0;
        }
        return $left;
    }

    /** Recursive-descent: unary minus */
    private function exprUnary(array $tokens, int &$pos, array $context): int|float|null
    {
        if ($pos < count($tokens) && $tokens[$pos] === '-') {
            $pos++;
            $val = $this->exprPrimary($tokens, $pos, $context);
            return $val !== null ? -$val : null;
        }
        return $this->exprPrimary($tokens, $pos, $context);
    }

    /** Recursive-descent: primary (literal, variable, parenthesized expression) */
    private function exprPrimary(array $tokens, int &$pos, array $context): int|float|null
    {
        if ($pos >= count($tokens)) return null;
        $tok = $tokens[$pos];
        if (is_int($tok) || is_float($tok)) {
            $pos++;
            return $tok;
        }
        if (is_array($tok)) { // ['var', 'path.name']
            $pos++;
            $val = $this->resolveValue($tok[1], $context);
            return ($val !== null && is_numeric($val)) ? (float)$val : null;
        }
        if ($tok === '(') {
            $pos++; // consume '('
            $val = $this->exprAdd($tokens, $pos, $context);
            if ($pos < count($tokens) && $tokens[$pos] === ')') {
                $pos++; // consume ')'
            }
            return $val;
        }
        return null;
    }

    /**
     * Remove template comments
     */
    private function removeComments(string $content): string
    {
        $content = preg_replace('/\{!--.*?--\}/s', '', $content);
        $content = preg_replace('/\{\*.*?\*\}/s', '', $content);
        // DiSyL 4.2: {types}...{/types} blocks are compile-time only; never render.
        $content = preg_replace('/\{types\s*\}.*?\{\/types\s*\}/s', '', $content);
        return $content;
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

        // ── Cross-request extends resolution cache ──────────────────────
        // The extends chain resolution (file reads + regex block merging)
        // depends only on file contents, not runtime context.  Cache the
        // merged result keyed by template path, validated against the
        // mtime of every file in the chain.
        $extendsCacheKey = null;
        if ($this->cacheEnabled && $this->currentTemplatePath !== null) {
            $extendsCacheKey = $this->currentTemplatePath;
            $cached = $this->getExtendsCache($extendsCacheKey);
            if ($cached !== null) {
                return $cached;
            }
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

        $result = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $result ?? $current);

        // Store in cross-request cache with all file dependencies
        if ($extendsCacheKey !== null && !empty($seenPaths)) {
            $deps = [];
            if (file_exists($extendsCacheKey)) {
                $deps[$extendsCacheKey] = filemtime($extendsCacheKey);
            }
            foreach ($seenPaths as $depPath => $_) {
                if (file_exists($depPath)) {
                    $deps[$depPath] = filemtime($depPath);
                }
            }
            $this->setExtendsCache($extendsCacheKey, $result, $deps);
        }

        return $result;
    }

    // ── Cross-request extends resolution cache ──────────────────────

    /**
     * Retrieve a cached extends-resolved template.
     *
     * The cache entry contains the merged template string and the mtimes
     * of every file in the extends chain.  Returns null on miss or stale.
     */
    private function getExtendsCache(string $templatePath): ?string
    {
        $cacheFile = $this->extendsCacheDir . '/' . md5($templatePath) . '.cache';
        if (!file_exists($cacheFile)) {
            return null;
        }

        $raw = @file_get_contents($cacheFile);
        if ($raw === false) {
            return null;
        }

        $entry = @unserialize($raw);
        if (!is_array($entry) || !isset($entry['content'], $entry['deps']) || !is_array($entry['deps'])) {
            @unlink($cacheFile);
            return null;
        }

        // Validate every dependency mtime
        foreach ($entry['deps'] as $depPath => $depMtime) {
            if (!file_exists($depPath) || filemtime($depPath) !== $depMtime) {
                @unlink($cacheFile);
                return null;
            }
        }

        return $entry['content'];
    }

    /**
     * Store an extends-resolved template in the cross-request cache.
     *
     * Uses atomic write (tmp + rename) to avoid serving partial content.
     *
     * @param array<string,int> $deps  Map of absolute-path → filemtime
     */
    private function setExtendsCache(string $templatePath, string $content, array $deps): void
    {
        if (empty($deps)) {
            return;
        }

        if (!is_dir($this->extendsCacheDir)) {
            @mkdir($this->extendsCacheDir, 0777, true);
        }

        $cacheFile = $this->extendsCacheDir . '/' . md5($templatePath) . '.cache';
        $tmpFile = $cacheFile . '.' . getmypid() . '.tmp';

        $ok = @file_put_contents($tmpFile, serialize([
            'content' => $content,
            'deps'    => $deps,
        ]));

        if ($ok !== false) {
            @rename($tmpFile, $cacheFile);
        } else {
            @unlink($tmpFile);
        }
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

        // DiSyL 4.3 — inline self-closing tags ({invalidate ...}, {convert ...}).
        if (str_contains($content, '{invalidate') || str_contains($content, '{convert')) {
            // 4.4: must run AFTER structure pass so that {untrusted}{invalidate}{/untrusted}
            // pushes the sandbox frame before the inline tag is processed (the recursive
            // compile() inside the structure body re-enters this method with the frame active).
            $hasInline = true;
        } else {
            $hasInline = false;
        }

        if (
            !$hasInline
            && !str_contains($content, '{if')
            && !str_contains($content, '{for')
            && !str_contains($content, '{foreach')
            && !str_contains($content, '{each')
            && !str_contains($content, '{match')
            && !str_contains($content, '{trans')
            && !str_contains($content, '{cache')
            && !str_contains($content, '{experiment')
            && !str_contains($content, '{sandbox')
            && !str_contains($content, '{trusted')
            && !str_contains($content, '{untrusted')
            && !str_contains($content, '{parallel')
            && !str_contains($content, '{await')
            && !str_contains($content, '{suspense')
            && !str_contains($content, '{federated_query')
            && !str_contains($content, '{ai_generate')
            && !str_contains($content, '{ai_query')
            && !str_contains($content, '{ai_complete')
        ) {
            return $content;
        }

        $content = $this->processControlStructuresSinglePass($content, $context);
        if ($hasInline && (str_contains($content, '{invalidate') || str_contains($content, '{convert'))) {
            $content = $this->processInlineSideEffectTags($content, $context);
        }
        return $content;
    }

    /**
     * Single-pass control structure processor.
     *
     * Scans left-to-right for top-level control structures, processes each
     * in-place, and concatenates the results.  Nested structures inside loop
     * bodies are handled by the recursive compile() call; nested structures
     * inside chosen if-branches are handled by recursive invocation of this
     * method.
     *
     * Replaces the former O(N²) while-loop that rescanned the full string
     * after every single structure evaluation.
     */
    private function processControlStructuresSinglePass(string $content, array $context): string
    {
        $result = '';
        $offset = 0;
        $len = strlen($content);
        $allTypes = ['for', 'foreach', 'each', 'if', 'match', 'trans', 'cache', 'experiment', 'sandbox', 'trusted', 'untrusted', 'parallel', 'await', 'suspense', 'federated_query', 'ai_generate', 'ai_query', 'ai_complete'];

        while ($offset < $len) {
            $tag = $this->findNextOpeningControlTag($content, $offset, $allTypes);

            if ($tag === null) {
                $result .= substr($content, $offset);
                break;
            }

            // Append literal text before this structure
            if ($tag['pos'] > $offset) {
                $result .= substr($content, $offset, $tag['pos'] - $offset);
            }

            $afterOpen = $tag['pos'] + $tag['len'];
            $closePos = $this->findMatchingClose($content, $afterOpen, $tag['type']);

            if ($closePos === false) {
                // No matching close — output the opening tag as literal text
                $result .= $tag['full'];
                $offset = $afterOpen;
                continue;
            }

            $closeLen = strlen('{/' . $tag['type'] . '}');
            $innerContent = substr($content, $afterOpen, $closePos - $afterOpen);

            $result .= $this->evaluateStructureBody($tag, $innerContent, $context);
            $offset = $closePos + $closeLen;
        }

        return $result;
    }

    /**
     * Dispatch a matched control structure to the appropriate evaluator.
     */
    private function evaluateStructureBody(array $tag, string $innerContent, array $context): string
    {
        return match ($tag['type']) {
            'if'      => $this->evaluateIfBody($tag['expr'], $innerContent, $context),
            'for'     => $this->evaluateForBody($tag['expr'], $innerContent, $context),
            'foreach' => $this->evaluateForeachBody($tag['expr'], $innerContent, $context),
            'each'    => $this->evaluateEachBody($tag['expr'], $innerContent, $context),
            'match'      => $this->evaluateMatchBody($tag['expr'], $innerContent, $context),
            'trans'      => $this->evaluateTransBody($tag['expr'], $innerContent, $context),
            'cache'      => $this->evaluateCacheBody($tag['expr'], $innerContent, $context),
            'experiment' => $this->evaluateExperimentBody($tag['expr'], $innerContent, $context),
            'sandbox'    => $this->evaluateSandboxBody($tag['expr'], $innerContent, $context, 'sandbox'),
            'trusted'    => $this->evaluateSandboxBody($tag['expr'], $innerContent, $context, 'trusted'),
            'untrusted'  => $this->evaluateSandboxBody($tag['expr'], $innerContent, $context, 'untrusted'),
            'parallel'   => $this->evaluateParallelBody($tag['expr'], $innerContent, $context),
            'await'      => $this->evaluateAwaitBody($tag['expr'], $innerContent, $context),
            'suspense'   => $this->evaluateSuspenseBody($tag['expr'], $innerContent, $context),
            'federated_query' => $this->evaluateFederatedQueryBody($tag['expr'], $innerContent, $context),
            'ai_generate', 'ai_query', 'ai_complete' => $this->evaluateAiBody($tag['type'], $tag['expr'], $innerContent, $context),
            default      => '',
        };
    }

    /**
     * Evaluate an {if}/{elseif}/{else}/{/if} structure.
     *
     * Picks the winning branch, then recursively processes any nested
     * control structures inside the chosen content.
     */
    private function evaluateIfBody(string $condition, string $innerContent, array $context): string
    {
        $branches = $this->parseIfBranches($innerContent, $condition);
        $chosenContent = '';
        foreach ($branches as $branch) {
            if ($branch['type'] === 'else' || $this->evaluateCondition($branch['condition'], $context)) {
                $chosenContent = $branch['content'];
                break;
            }
        }
        if ($chosenContent === '') {
            return '';
        }
        // Recursively process any nested control structures in the chosen branch
        if (
            str_contains($chosenContent, '{if')
            || str_contains($chosenContent, '{for')
            || str_contains($chosenContent, '{foreach')
            || str_contains($chosenContent, '{each')
            || str_contains($chosenContent, '{match')
        ) {
            return $this->processControlStructuresSinglePass($chosenContent, $context);
        }
        return $chosenContent;
    }

    /**
     * Evaluate a {match expr}{when ...}...{default}...{/match} body.
     *
     * Walks arms in source order. The first arm whose pattern list contains
     * the subject value (and whose optional `guard` predicate is truthy) wins.
     * Falls back to the {default} arm if present.
     *
     * Pattern syntax (4.1):
     *   {when 'literal', 42, true, null, _}
     *   {when 'paid' guard refund.partial}
     *
     * Wildcard `_` always matches. Guard reuses evaluateCondition().
     *
     * In strict mode, an unmatched value with no default emits a single
     * `disyl.match.unmatched` log line.
     */
    private function evaluateMatchBody(string $subjectExpr, string $innerContent, array $context): string
    {
        $subjectValue = $this->resolveValue(trim($subjectExpr), $context);
        $arms = $this->parseMatchArms($innerContent);

        $chosenContent = null;
        foreach ($arms as $arm) {
            if ($arm['type'] === 'default') {
                continue;
            }
            if (!$this->matchAnyPattern($subjectValue, $arm['patterns'], $context)) {
                continue;
            }
            if ($arm['guard'] !== '' && !$this->evaluateCondition($arm['guard'], $context)) {
                continue;
            }
            $chosenContent = $arm['content'];
            break;
        }

        if ($chosenContent === null) {
            foreach ($arms as $arm) {
                if ($arm['type'] === 'default') {
                    $chosenContent = $arm['content'];
                    break;
                }
            }
        }

        if ($chosenContent === null) {
            if ($this->strictMode ?? false) {
                $this->logError('disyl.match.unmatched: no arm matched and no {default} provided');
            }
            return '';
        }

        if (
            str_contains($chosenContent, '{if')
            || str_contains($chosenContent, '{for')
            || str_contains($chosenContent, '{foreach')
            || str_contains($chosenContent, '{each')
            || str_contains($chosenContent, '{match')
        ) {
            return $this->processControlStructuresSinglePass($chosenContent, $context);
        }
        return $chosenContent;
    }

    /**
     * Parse {match} body into ordered arms.
     *
     * @return list<array{type:string,patterns:list<string>,guard:string,content:string}>
     */
    private function parseMatchArms(string $content): array
    {
        $arms = [];
        $len = strlen($content);
        $offset = 0;
        $current = null;
        $defaultSeen = false;

        while ($offset < $len) {
            $tagPos = strpos($content, '{', $offset);
            if ($tagPos === false) {
                if ($current !== null) {
                    $current['content'] .= substr($content, $offset);
                }
                break;
            }

            // Skip nested {match}/{if}/{for}/etc — only top-level {when}/{default} are arms here.
            $nested = $this->readOpeningControlTagAt($content, $tagPos, ['if', 'for', 'foreach', 'each', 'match']);
            if ($nested !== null) {
                $afterOpen = $nested['pos'] + $nested['len'];
                $closePos = $this->findMatchingClose($content, $afterOpen, $nested['type']);
                if ($closePos === false) {
                    if ($current !== null) {
                        $current['content'] .= substr($content, $offset);
                    }
                    break;
                }
                $closeLen = strlen('{/' . $nested['type'] . '}');
                $chunkEnd = $closePos + $closeLen;
                if ($current !== null) {
                    $current['content'] .= substr($content, $offset, $chunkEnd - $offset);
                }
                $offset = $chunkEnd;
                continue;
            }

            $tagEnd = strpos($content, '}', $tagPos + 1);
            if ($tagEnd === false) {
                if ($current !== null) {
                    $current['content'] .= substr($content, $offset);
                }
                break;
            }

            $rawTag = substr($content, $tagPos + 1, $tagEnd - $tagPos - 1);
            $rawTagTrimmed = ltrim($rawTag);

            $isWhen = str_starts_with($rawTagTrimmed, 'when ') || $rawTagTrimmed === 'when';
            $isDefault = $rawTagTrimmed === 'default';

            if (!$isWhen && !$isDefault) {
                if ($current !== null) {
                    $current['content'] .= substr($content, $offset, ($tagEnd + 1) - $offset);
                }
                $offset = $tagEnd + 1;
                continue;
            }

            // Flush any text before this arm tag into the current arm body.
            if ($current !== null && $tagPos > $offset) {
                $current['content'] .= substr($content, $offset, $tagPos - $offset);
            }

            // Close out current arm.
            if ($current !== null) {
                $arms[] = $current;
                $current = null;
            }

            if ($isDefault) {
                if ($defaultSeen) {
                    $this->logError('DISYL_MATCH_DUP_DEFAULT: more than one {default} in {match}');
                }
                $defaultSeen = true;
                $current = ['type' => 'default', 'patterns' => [], 'guard' => '', 'content' => ''];
            } else {
                $body = trim(substr($rawTagTrimmed, 4)); // strip "when"
                $guard = '';
                $guardPos = $this->findUnquotedToken($body, ' guard ');
                if ($guardPos !== false) {
                    $guard = trim(substr($body, $guardPos + strlen(' guard ')));
                    $body = trim(substr($body, 0, $guardPos));
                }
                $patterns = $this->splitMatchPatterns($body);
                $current = ['type' => 'when', 'patterns' => $patterns, 'guard' => $guard, 'content' => ''];
            }

            $offset = $tagEnd + 1;
        }

        if ($current !== null) {
            $arms[] = $current;
        }
        return $arms;
    }

    /**
     * Split a {when ...} pattern list on commas not inside quotes.
     *
     * @return list<string>
     */
    private function splitMatchPatterns(string $list): array
    {
        $list = trim($list);
        if ($list === '') {
            return [];
        }
        $parts = [];
        $buf = '';
        $len = strlen($list);
        $inSingle = false;
        $inDouble = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $list[$i];
            if ($ch === "\\" && $i + 1 < $len) {
                $buf .= $ch . $list[$i + 1];
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $buf .= $ch;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $buf .= $ch;
                continue;
            }
            if ($ch === ',' && !$inSingle && !$inDouble) {
                $parts[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $tail = trim($buf);
        if ($tail !== '') {
            $parts[] = $tail;
        }
        return $parts;
    }

    /**
     * Locate `$needle` in `$haystack` ignoring matches that fall inside single
     * or double quotes. Returns false when not found. Used to find the ` guard `
     * separator inside a {when ...} clause without splitting on a literal.
     */
    private function findUnquotedToken(string $haystack, string $needle): int|false
    {
        $needleLen = strlen($needle);
        $len = strlen($haystack);
        $inSingle = false;
        $inDouble = false;
        for ($i = 0; $i + $needleLen <= $len; $i++) {
            $ch = $haystack[$i];
            if ($ch === "\\" && $i + 1 < $len) {
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
            if (!$inSingle && !$inDouble && substr_compare($haystack, $needle, $i, $needleLen) === 0) {
                return $i;
            }
        }
        return false;
    }

    /**
     * Test the subject value against a list of {when} patterns.
     *
     * Each pattern is a literal (`'str'`, `"str"`, integer, float, `true`,
     * `false`, `null`), the wildcard `_`, or any other identifier expression
     * which is resolved against the context and compared via loose equality.
     *
     * @param list<string> $patterns
     */
    private function matchAnyPattern(mixed $subject, array $patterns, array $context): bool
    {
        foreach ($patterns as $pat) {
            $pat = trim($pat);
            if ($pat === '') {
                continue;
            }
            if ($pat === '_') {
                return true;
            }
            // String literal
            $patLen = strlen($pat);
            if ($patLen >= 2 && (
                ($pat[0] === "'" && $pat[$patLen - 1] === "'") ||
                ($pat[0] === '"' && $pat[$patLen - 1] === '"')
            )) {
                $literal = substr($pat, 1, -1);
                if (is_string($subject) && $subject === $literal) {
                    return true;
                }
                continue;
            }
            // Boolean / null
            $lower = strtolower($pat);
            if ($lower === 'true') {
                if ($subject === true) {
                    return true;
                }
                continue;
            }
            if ($lower === 'false') {
                if ($subject === false) {
                    return true;
                }
                continue;
            }
            if ($lower === 'null') {
                if ($subject === null) {
                    return true;
                }
                continue;
            }
            // Numeric literal
            if (is_numeric($pat)) {
                if ((is_int($subject) || is_float($subject) || is_string($subject))
                    && (string)$subject === (string)(0 + $pat + 0)) {
                    return true;
                }
                if (is_numeric($subject) && (float)$subject === (float)$pat) {
                    return true;
                }
                continue;
            }
            // Identifier / dotted path → resolve from context, loose-compare.
            $resolved = $this->resolveValue($pat, $context);
            if ($subject == $resolved) {
                return true;
            }
        }
        return false;
    }

    /**
     * Evaluate a {trans 'key' [plural=EXPR] [context='STR']}...{/trans} body.
     *
     * Behavior:
     *   - Static key required (errors otherwise).
     *   - When `plural` is absent: looks up the catalog `value`, falling back
     *     to the inline body text when the key is missing.
     *   - When `plural` is present: evaluates the expression, picks a CLDR
     *     plural arm via {@see Catalog::pluralCategory()}, looks up that arm
     *     in the catalog, and falls back to the matching {when} arm body
     *     when the catalog entry is missing.
     *   - Both branches interpolate `%(name)s` placeholders from the engine
     *     context (top-level scalar keys + the plural value as `count`).
     */
    private function evaluateTransBody(string $expr, string $innerContent, array $context): string
    {
        $parsed = $this->parseTransAttributes($expr);
        if ($parsed === null) {
            $this->logError('DISYL_TRANS_DYNAMIC_KEY: {trans} requires a static string key as first argument');
            return $this->compile($innerContent, $context);
        }
        [$key, $contextTag, $pluralExpr] = [$parsed['key'], $parsed['context'], $parsed['plural']];

        $tenantId = (string) ($context['_tenant_id'] ?? $context['tenant_id'] ?? '');
        $locale   = (string) ($context['_locale']    ?? $context['locale']    ?? 'en');
        $i18nRoot = (string) ($context['_i18n_root'] ?? (defined('STORAGE_PATH')
            ? rtrim(STORAGE_PATH, '/') . '/i18n'
            : (defined('BASE_PATH') ? rtrim(BASE_PATH, '/') . '/storage/i18n' : 'storage/i18n')));

        $vars = $this->collectTransVars($context);

        if ($pluralExpr === null) {
            $translated = \Ikabud\Kernel\DiSyL\i18n\Catalog::translate(
                $i18nRoot,
                $tenantId,
                $locale,
                $key,
                $contextTag,
                $vars,
                null
            );
            if ($translated !== null) {
                return $translated;
            }
            // Fallback: render inline body so {var} interpolation still works.
            return $this->compile($innerContent, $context);
        }

        // Plural mode: resolve count, pick arm, look up catalog or fall back to {when} body.
        $countRaw = $this->resolveValue($pluralExpr, $context);
        $count = is_numeric($countRaw) ? (0 + $countRaw) : 0;
        $arm = \Ikabud\Kernel\DiSyL\i18n\Catalog::pluralCategory($locale, $count);
        $vars['count'] = (string) $count;

        $translated = \Ikabud\Kernel\DiSyL\i18n\Catalog::translate(
            $i18nRoot,
            $tenantId,
            $locale,
            $key,
            $contextTag,
            $vars,
            $arm
        );
        if ($translated !== null) {
            return $translated;
        }

        // Fallback to inline {when} arm body.
        $arms = $this->parseMatchArms($innerContent);
        $chosen = null;
        foreach ($arms as $a) {
            if ($a['type'] !== 'when') {
                continue;
            }
            foreach ($a['patterns'] as $p) {
                $name = trim($p, " \t'\"");
                if ($name === $arm) {
                    $chosen = $a['content'];
                    break 2;
                }
            }
        }
        if ($chosen === null) {
            // Try 'other' as a final fallback.
            foreach ($arms as $a) {
                if ($a['type'] !== 'when') {
                    continue;
                }
                foreach ($a['patterns'] as $p) {
                    if (trim($p, " \t'\"") === 'other') {
                        $chosen = $a['content'];
                        break 2;
                    }
                }
            }
        }
        if ($chosen === null) {
            $this->logError('DISYL_TRANS_PLURAL_NO_ARM: no matching plural arm for "' . $arm . '"');
            return '';
        }
        return $this->compile($chosen, $context);
    }

    /**
     * Parse a {trans} opening-tag expression of the form:
     *   'key' [plural=EXPR] [context='STR']
     *
     * Returns null if the key is dynamic (anything other than a single
     * literal string in single or double quotes).
     *
     * @return array{key:string, context:?string, plural:?string}|null
     */
    private function parseTransAttributes(string $expr): ?array
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        // Extract leading quoted key (consume up to the matching closing quote
        // of the same kind, respecting backslash escapes).
        $quote = $expr[0];
        if ($quote !== "'" && $quote !== '"') {
            return null;
        }
        $len = strlen($expr);
        $end = -1;
        for ($i = 1; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($ch === "\\" && $i + 1 < $len) {
                $i++;
                continue;
            }
            if ($ch === $quote) {
                $end = $i;
                break;
            }
        }
        if ($end < 0) {
            return null;
        }
        $key = substr($expr, 1, $end - 1);
        $rest = trim(substr($expr, $end + 1));

        $plural = null;
        $contextTag = null;

        // Tokenise remaining attrs as `name=value` pairs.
        while ($rest !== '') {
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*/', $rest, $m)) {
                break;
            }
            $name = $m[1];
            $rest = substr($rest, strlen($m[0]));
            if ($rest === '') {
                break;
            }
            // Value: quoted string (matching close-quote scan) or bareword.
            if ($rest[0] === "'" || $rest[0] === '"') {
                $vq = $rest[0];
                $vlen = strlen($rest);
                $vend = -1;
                for ($j = 1; $j < $vlen; $j++) {
                    $vc = $rest[$j];
                    if ($vc === "\\" && $j + 1 < $vlen) {
                        $j++;
                        continue;
                    }
                    if ($vc === $vq) {
                        $vend = $j;
                        break;
                    }
                }
                if ($vend < 0) {
                    break;
                }
                $value = substr($rest, 1, $vend - 1);
                $rest = ltrim(substr($rest, $vend + 1));
            } else {
                if (preg_match('/^(\S+)/', $rest, $vm)) {
                    $value = $vm[1];
                    $rest = ltrim(substr($rest, strlen($vm[0])));
                } else {
                    break;
                }
            }
            if ($name === 'plural') {
                $plural = $value;
            } elseif ($name === 'context') {
                $contextTag = $value;
            }
        }

        return ['key' => $key, 'context' => $contextTag, 'plural' => $plural];
    }

    // ----------------------------------------------------------------- 4.3 --

    /** Inject a custom fragment cache (test seam). */
    public function setFragmentStore(\Ikabud\Kernel\DiSyL\Cache\FragmentStore $store): void
    {
        $this->fragmentStore = $store;
    }

    /** Inject a custom bucketer (test seam). */
    public function setBucketer(\Ikabud\Kernel\DiSyL\Experiments\Bucketer $bucketer): void
    {
        $this->bucketer = $bucketer;
    }

    /** Tenant id used for cache key namespacing. */
    public function setTenantId(?string $tenantId): void { $this->tenantId = $tenantId; }

    /** Subject id used for sticky bucketing. */
    public function setSubjectId(?string $subjectId): void { $this->subjectId = $subjectId; }

    /** Request id used for exposure dedupe. */
    public function setRequestId(?string $requestId): void { $this->requestId = $requestId; }

    public function fragmentStore(): \Ikabud\Kernel\DiSyL\Cache\FragmentStore
    {
        if ($this->fragmentStore === null) {
            require_once __DIR__ . '/Cache/FragmentStore.php';
            $this->fragmentStore = new \Ikabud\Kernel\DiSyL\Cache\FragmentStore();
        }
        return $this->fragmentStore;
    }

    private function bucketer(): \Ikabud\Kernel\DiSyL\Experiments\Bucketer
    {
        if ($this->bucketer === null) {
            require_once __DIR__ . '/Experiments/Bucketer.php';
            $this->bucketer = new \Ikabud\Kernel\DiSyL\Experiments\Bucketer();
        }
        return $this->bucketer;
    }

    /**
     * Process inline self-closing tags: {invalidate 'tag', 'tag2'} and
     * {convert 'experiment-id' goal='goal-name'}. Both produce no output.
     */
    private function processInlineSideEffectTags(string $content, array $context): string
    {
        $content = preg_replace_callback('/\{invalidate\s+([^}]+)\}/', function (array $m) use ($context): string {
            if (!$this->sandbox()->require('cache.invalidate', '{invalidate}', $m[0])) return '';
            $tags = $this->splitInlineArgs($m[1], $context);
            if ($tags !== []) {
                $this->fragmentStore()->invalidate($tags, $this->tenantId ?? '_global');
            }
            return '';
        }, $content) ?? $content;

        $content = preg_replace_callback('/\{convert\s+([^}]+)\}/', function (array $m) use ($context): string {
            if (!$this->sandbox()->require('experiment', '{convert}', $m[0])) return '';
            $expr = trim($m[1]);
            $expId = $this->parseFirstQuoted($expr, $rest);
            if ($expId === null) return '';
            $goal = null;
            if (preg_match('/goal\s*=\s*([\'"])(.*?)\1/', $rest, $gm)) {
                $goal = $gm[2];
            }
            if ($goal === null) return '';
            $subject = $this->subjectId ?? '_anon';
            $this->bucketer()->convert($expId, $subject, $goal);
            return '';
        }, $content) ?? $content;

        return $content;
    }

    /**
     * Evaluate a {cache key=… ttl=…}…{/cache} block. Renders the body on
     * miss and stores it; serves stored body on hit. Honours {depends_on}
     * tags found inside the body.
     */
    private function evaluateCacheBody(string $expr, string $innerContent, array $context): string
    {
        $attrs = $this->parseAttrPairs($expr, $context);
        $key = $attrs['key'] ?? null;
        $ttl = isset($attrs['ttl']) ? (int) $attrs['ttl'] : 0;
        if (!is_string($key) || $key === '') {
            $this->logError('DiSyL cache: missing key attribute');
            return $this->compile($innerContent, $context);
        }
        if ($ttl < 0) {
            $this->logError('DISYL_CACHE_INVALID_TTL: ttl must be >= 0');
            return $this->compile($innerContent, $context);
        }

        // Extract {depends_on ...} declarations from the body.
        $deps = [];
        $bodyForRender = preg_replace_callback(
            '/\{depends_on\s+([^}]+)\}/',
            function (array $m) use (&$deps, $context): string {
                foreach ($this->splitInlineArgs($m[1], $context) as $tag) $deps[] = $tag;
                return '';
            },
            $innerContent
        ) ?? $innerContent;

        $store = $this->fragmentStore();
        $tenant = $this->tenantId ?? '_global';
        $hit = $store->tryGet($key, $deps, $tenant);
        if ($hit !== null) return $hit;

        $rendered = $this->compile($bodyForRender, $context);
        $store->put($key, $rendered, $deps, $ttl, $tenant);
        return $rendered;
    }

    /**
     * Evaluate an {experiment 'id'}…{/experiment} block. Splits body by
     * {variant 'name' weight=N} markers, picks a sticky variant for the
     * current subject, and returns that variant's body (after recursive
     * compilation).
     */
    private function evaluateExperimentBody(string $expr, string $innerContent, array $context): string
    {
        if (!$this->sandbox()->require('experiment', '{experiment}', $expr)) {
            return '';
        }
        $expId = $this->parseFirstQuoted(trim($expr), $rest);
        if ($expId === null) {
            $this->logError('DiSyL experiment: missing id');
            return '';
        }
        $variants = $this->parseVariantArms($innerContent);
        if ($variants === []) {
            $this->logError('DISYL_EXP_NO_VARIANTS for ' . $expId);
            return '';
        }
        $weights = [];
        $bodies = [];
        foreach ($variants as $name => $v) {
            if (isset($weights[$name])) {
                $this->logError('DISYL_EXP_DUP_VARIANT: ' . $name);
                continue;
            }
            $weights[$name] = $v['weight'];
            $bodies[$name]  = $v['body'];
        }
        $subject = $this->subjectId ?? '_anon';
        try {
            $variant = $this->bucketer()->assign($expId, $subject, $weights);
        } catch (\InvalidArgumentException $e) {
            $this->logError('DiSyL experiment ' . $expId . ': ' . $e->getMessage());
            $variant = array_key_first($bodies);
        }
        $this->bucketer()->expose($expId, $subject, $this->requestId ?? '_req', $variant);
        $body = $bodies[$variant] ?? reset($bodies);
        return $this->compile($body, $context);
    }

    /**
     * Split an experiment body by {variant 'name' weight=N} markers.
     *
     * @return array<string, array{weight: int, body: string}>
     */
    private function parseVariantArms(string $content): array
    {
        $out = [];
        if (!preg_match_all(
            '/\{variant\s+([\'"])(.*?)\1(?:\s+weight\s*=\s*(\d+))?\s*\}/',
            $content, $m, PREG_OFFSET_CAPTURE
        )) {
            return $out;
        }
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $name = $m[2][$i][0];
            $weight = isset($m[3][$i][0]) && $m[3][$i][0] !== '' ? (int) $m[3][$i][0] : 1;
            $start = $m[0][$i][1] + strlen($m[0][$i][0]);
            $end = ($i + 1 < $count) ? $m[0][$i + 1][1] : strlen($content);
            $body = substr($content, $start, $end - $start);
            $out[$name] = ['weight' => $weight, 'body' => $body];
        }
        return $out;
    }

    /**
     * Parse a key=value attribute string. Values may be quoted strings or
     * bare DiSyL expressions (evaluated against $context).
     *
     * @return array<string, mixed>
     */
    private function parseAttrPairs(string $expr, array $context): array
    {
        $out = [];
        $rest = trim($expr);
        while ($rest !== '' && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*/', $rest, $m)) {
            $name = $m[1];
            $rest = substr($rest, strlen($m[0]));
            if ($rest === '') break;
            if ($rest[0] === "'" || $rest[0] === '"') {
                $q = $rest[0];
                $end = -1;
                $len = strlen($rest);
                for ($j = 1; $j < $len; $j++) {
                    if ($rest[$j] === '\\' && $j + 1 < $len) { $j++; continue; }
                    if ($rest[$j] === $q) { $end = $j; break; }
                }
                if ($end < 0) break;
                $out[$name] = substr($rest, 1, $end - 1);
                $rest = ltrim(substr($rest, $end + 1));
            } else {
                if (!preg_match('/^(\S+)/', $rest, $vm)) break;
                $raw = $vm[1];
                // Bracketed list literal: capture the full [...] before splitting
                if ($raw !== '' && $raw[0] === '[') {
                    $end = strpos($rest, ']');
                    if ($end !== false) {
                        $raw = substr($rest, 0, $end + 1);
                    }
                }
                $rest = ltrim(substr($rest, strlen($raw)));
                if ($raw !== '' && $raw[0] === '[') {
                    $out[$name] = $raw; // hand to normalizeListAttr later
                } elseif (is_numeric($raw)) {
                    $out[$name] = $raw + 0;
                } else {
                    $val = $this->resolveValue($raw, $context);
                    $out[$name] = $val;
                }
            }
        }
        return $out;
    }

    /**
     * Split a comma-separated argument list, evaluating each token (quoted
     * literal or DiSyL expression) into a string.
     *
     * @return list<string>
     */
    private function splitInlineArgs(string $expr, array $context): array
    {
        $out = [];
        $expr = trim($expr);
        $len = strlen($expr);
        $i = 0;
        $buf = '';
        while ($i < $len) {
            $ch = $expr[$i];
            if ($ch === ',') { $out[] = trim($buf); $buf = ''; $i++; continue; }
            if ($ch === "'" || $ch === '"') {
                $q = $ch; $buf .= $ch; $i++;
                while ($i < $len && $expr[$i] !== $q) {
                    if ($expr[$i] === '\\' && $i + 1 < $len) { $buf .= $expr[$i] . $expr[$i + 1]; $i += 2; continue; }
                    $buf .= $expr[$i]; $i++;
                }
                if ($i < $len) { $buf .= $expr[$i]; $i++; }
                continue;
            }
            $buf .= $ch; $i++;
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        $resolved = [];
        foreach ($out as $token) {
            if ($token === '') continue;
            if (($token[0] === "'" || $token[0] === '"') && substr($token, -1) === $token[0]) {
                $resolved[] = substr($token, 1, -1);
            } else {
                $val = $this->resolveValue($token, $context);
                if (is_scalar($val)) $resolved[] = (string) $val;
            }
        }
        return $resolved;
    }

    /**
     * Pull the leading quoted token off an expression; remainder via $rest.
     */
    private function parseFirstQuoted(string $expr, ?string &$rest = null): ?string
    {
        $rest = '';
        if ($expr === '') return null;
        $q = $expr[0];
        if ($q !== "'" && $q !== '"') return null;
        $len = strlen($expr);
        for ($i = 1; $i < $len; $i++) {
            if ($expr[$i] === '\\' && $i + 1 < $len) { $i++; continue; }
            if ($expr[$i] === $q) {
                $rest = ltrim(substr($expr, $i + 1));
                return substr($expr, 1, $i - 1);
            }
        }
        return null;
    }

    // ------------------------------------------------------------- /4.3 --

    // ------------------------------------------------------------------ 4.4 --

    /** Inject a custom sandbox (test seam). */
    public function setSandbox(\Ikabud\Kernel\DiSyL\Security\Sandbox $sb): void
    {
        $this->sandbox = $sb;
    }

    public function sandbox(): \Ikabud\Kernel\DiSyL\Security\Sandbox
    {
        if ($this->sandbox === null) {
            require_once __DIR__ . '/Security/Sandbox.php';
            $this->sandbox = new \Ikabud\Kernel\DiSyL\Security\Sandbox();
        }
        return $this->sandbox;
    }

    /**
     * Evaluate a {sandbox}/{trusted}/{untrusted} block. Pushes a new
     * capability frame, renders the body, then pops. Catches
     * SandboxViolation when not in strict mode and replaces the violating
     * region with a comment marker.
     */
    private function evaluateSandboxBody(string $expr, string $innerContent, array $context, string $kind): string
    {
        $sb = $this->sandbox();
        if ($kind === 'sandbox') {
            $attrs = $this->parseAttrPairs($expr, $context);
            $deny  = $this->normalizeListAttr($attrs['deny']  ?? null);
            $allow = $this->normalizeListAttr($attrs['allow'] ?? null);
            $policy = isset($attrs['policy']) ? (string) $attrs['policy'] : '';
            $sb->pushSandbox($deny, $allow, $policy === 'strict');
        } elseif ($kind === 'trusted') {
            $sb->pushTrusted();
        } else { // untrusted
            $sb->pushUntrusted();
        }
        try {
            return $this->compile($innerContent, $context);
        } catch (\Ikabud\Kernel\DiSyL\Security\SandboxViolation $e) {
            return '<!-- sandbox-violation: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' -->';
        } finally {
            $sb->pop();
        }
    }

    /**
     * Coerce an attribute that may be either a list ['a','b'] or a comma
     * string into a list<string>.
     *
     * @return list<string>
     */
    private function normalizeListAttr(mixed $val): array
    {
        if ($val === null) return [];
        if (is_array($val)) {
            $out = [];
            foreach ($val as $v) if (is_scalar($v)) $out[] = (string) $v;
            return $out;
        }
        if (!is_string($val)) return [];
        $val = trim($val);
        if ($val === '') return [];
        // Strip surrounding [] if present.
        if ($val[0] === '[' && substr($val, -1) === ']') {
            $val = substr($val, 1, -1);
        }
        $parts = preg_split("/\s*,\s*/", $val) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p, " \t\n'\"");
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    // ------------------------------------------------------------- /4.4 --

    // ------------------------------------------------------------------ 4.5 --

    /** Inject a custom HTTP client (test seam). */
    public function setHttpClient(\Ikabud\Kernel\DiSyL\Async\HttpClient $c): void
    {
        $this->httpClient = $c;
    }

    public function httpClient(): \Ikabud\Kernel\DiSyL\Async\HttpClient
    {
        if ($this->httpClient === null) {
            require_once __DIR__ . '/Async/HttpClient.php';
            require_once __DIR__ . '/Async/Promise.php';
            $this->httpClient = new \Ikabud\Kernel\DiSyL\Async\HttpClient();
        }
        return $this->httpClient;
    }

    /**
     * Evaluate {parallel}…{/parallel}: collect immediate child {await} blocks,
     * resolve them concurrently (logically; sync backend in 4.5.0), then render
     * each child's body in source order with its resolved value bound.
     *
     * Non-{await} content between awaits is rendered in source position.
     */
    private function evaluateParallelBody(string $expr, string $innerContent, array $context): string
    {
        // Capture deny/allow if expr present (parallel inherits parent caps by default).
        $segments = $this->splitParallelChildren($innerContent);
        $tasks = [];
        $renderers = [];
        foreach ($segments as $seg) {
            if ($seg['type'] === 'static') {
                $renderers[] = ['kind' => 'static', 'content' => $seg['content']];
            } else { // 'await'
                $idx = count($tasks);
                $awaitInfo = $this->parseAwaitArms($seg['expr'], $seg['content']);
                $tasks[] = $this->buildAwaitTask($awaitInfo, $context);
                $renderers[] = ['kind' => 'await', 'taskIndex' => $idx, 'await' => $awaitInfo];
            }
        }
        require_once __DIR__ . '/Async/Scheduler.php';
        $sched = new \Ikabud\Kernel\DiSyL\Async\Scheduler();
        foreach ($tasks as $factory) { $sched->add($factory); }
        $results = $sched->run();

        $out = '';
        foreach ($renderers as $r) {
            if ($r['kind'] === 'static') {
                $out .= $this->compile($r['content'], $context);
            } else {
                $out .= $this->renderAwaitResult($r['await'], $results[$r['taskIndex']] ?? ['error' => new \RuntimeException('no result')], $context);
            }
        }
        return $out;
    }

    /**
     * Evaluate a standalone {await ...}…{/await} block (sequential).
     */
    private function evaluateAwaitBody(string $expr, string $innerContent, array $context): string
    {
        $info = $this->parseAwaitArms($expr, $innerContent);
        $task = $this->buildAwaitTask($info, $context);
        require_once __DIR__ . '/Async/Scheduler.php';
        $sched = new \Ikabud\Kernel\DiSyL\Async\Scheduler();
        $sched->add($task);
        $results = $sched->run();
        return $this->renderAwaitResult($info, $results[0] ?? ['error' => new \RuntimeException('no result')], $context);
    }

    /**
     * Evaluate {suspense fallback=...}…{/suspense}: render the body; on any
     * exception bubbled out of {await}/{parallel} descendants, swap to the
     * fallback expression.
     */
    private function evaluateSuspenseBody(string $expr, string $innerContent, array $context): string
    {
        $attrs = $this->parseAttrPairs($expr, $context);
        $fallback = isset($attrs['fallback']) ? (string) $attrs['fallback'] : '';
        try {
            return $this->compile($innerContent, $context);
        } catch (\Throwable $e) {
            return $fallback !== '' ? $this->compile($fallback, $context) : '';
        }
    }

    /**
     * Split a {parallel} body into static segments and {await} child blocks.
     *
     * @return list<array{type:string, content:string, expr?:string}>
     */
    private function splitParallelChildren(string $body): array
    {
        $segments = [];
        $offset = 0;
        $len = strlen($body);
        while ($offset < $len) {
            $tag = $this->findNextOpeningControlTag($body, $offset, ['await']);
            if ($tag === null) {
                $rest = substr($body, $offset);
                if ($rest !== '') $segments[] = ['type' => 'static', 'content' => $rest];
                break;
            }
            if ($tag['pos'] > $offset) {
                $segments[] = ['type' => 'static', 'content' => substr($body, $offset, $tag['pos'] - $offset)];
            }
            $contentStart = $tag['pos'] + $tag['len'];
            $closePos = $this->findMatchingClose($body, $contentStart, 'await');
            if ($closePos === false) {
                $offset = $contentStart;
                continue;
            }
            $inner = substr($body, $contentStart, $closePos - $contentStart);
            $segments[] = ['type' => 'await', 'expr' => $tag['expr'], 'content' => $inner];
            $offset = $closePos + strlen('{/await}');
        }
        return $segments;
    }

    /**
     * Parse an {await} body into success / loading / catch arms.
     *
     * @return array{expr:string, body:string, loading:?string, catch:?string, catchLet:?string}
     */
    private function parseAwaitArms(string $expr, string $innerContent): array
    {
        $loading = null; $catch = null; $catchLet = null;
        $body = $innerContent;
        // Split on {loading} and {catch ...} markers (single-token separators inside the await body).
        if (preg_match('/\{loading\}/', $body)) {
            $parts = preg_split('/\{loading\}/', $body, 2);
            $body = $parts[0];
            $rest = $parts[1] ?? '';
            if (preg_match('/\{catch(?:\s+let=(\w+))?\}/', $rest, $cm, PREG_OFFSET_CAPTURE)) {
                $loading = substr($rest, 0, (int)$cm[0][1]);
                $catchLet = $cm[1][1] ?? null;
                $catchLet = is_array($cm[1] ?? null) ? ($cm[1][0] ?: null) : null;
                $catch = substr($rest, (int)$cm[0][1] + strlen($cm[0][0]));
            } else {
                $loading = $rest;
            }
        } elseif (preg_match('/\{catch(?:\s+let=(\w+))?\}/', $body, $cm, PREG_OFFSET_CAPTURE)) {
            $catchLet = is_array($cm[1] ?? null) ? ($cm[1][0] ?: null) : null;
            $catch = substr($body, (int)$cm[0][1] + strlen($cm[0][0]));
            $body = substr($body, 0, (int)$cm[0][1]);
        }
        return ['expr' => $expr, 'body' => $body, 'loading' => $loading, 'catch' => $catch, 'catchLet' => $catchLet];
    }

    /**
     * Build a deferred Promise factory from an {await ...} attribute string.
     *
     * @return callable(): \Ikabud\Kernel\DiSyL\Async\Promise
     */
    private function buildAwaitTask(array $info, array $context): callable
    {
        $let = $this->extractLetIdentifier($info['expr']);
        $attrs = $this->parseAttrPairs($info['expr'], $context);
        $src = $attrs['src'] ?? null;
        if ($let === '') {
            return static fn () => \Ikabud\Kernel\DiSyL\Async\Promise::rejected(new \RuntimeException('DISYL_AWAIT_NO_LET'));
        }
        if ($src === null) {
            return static fn () => \Ikabud\Kernel\DiSyL\Async\Promise::rejected(new \RuntimeException('DISYL_AWAIT_NO_SRC'));
        }
        return function () use ($src) {
            require_once __DIR__ . '/Async/Promise.php';
            if ($src instanceof \Ikabud\Kernel\DiSyL\Async\Promise) return $src;
            return \Ikabud\Kernel\DiSyL\Async\Promise::resolved($src);
        };
    }

    /** Extract the bare identifier following `let=` in an attribute expression. */
    private function extractLetIdentifier(string $expr): string
    {
        if (preg_match('/\blet\s*=\s*([A-Za-z_][A-Za-z0-9_]*)/', $expr, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Render the appropriate {await} arm based on settled result.
     */
    private function renderAwaitResult(array $info, array $result, array $context): string
    {
        $let = $this->extractLetIdentifier($info['expr']);
        if ($let === '') $let = '_';
        if (array_key_exists('value', $result)) {
            $childCtx = $context;
            $childCtx[$let] = $result['value'];
            return $this->compile($info['body'], $childCtx);
        }
        // error
        if ($info['catch'] !== null) {
            $childCtx = $context;
            if ($info['catchLet'] !== null) {
                $childCtx[$info['catchLet']] = $result['error'];
            }
            return $this->compile($info['catch'], $childCtx);
        }
        return '';
    }

    // ------------------------------------------------------------- /4.5 --

    // ------------------------------------------------------------------ 4.6 --

    public function setServiceRegistry(\Ikabud\Kernel\DiSyL\Federation\ServiceRegistry $r): void { $this->serviceRegistry = $r; }

    public function serviceRegistry(): \Ikabud\Kernel\DiSyL\Federation\ServiceRegistry
    {
        if ($this->serviceRegistry === null) {
            require_once __DIR__ . '/Federation/ServiceRegistry.php';
            $this->serviceRegistry = new \Ikabud\Kernel\DiSyL\Federation\ServiceRegistry();
        }
        return $this->serviceRegistry;
    }

    public function setAiProvider(\Ikabud\Kernel\DiSyL\AI\AiProvider $p): void { $this->aiProvider = $p; }

    public function aiProvider(): \Ikabud\Kernel\DiSyL\AI\AiProvider
    {
        if ($this->aiProvider === null) {
            require_once __DIR__ . '/AI/AiProvider.php';
            require_once __DIR__ . '/AI/EchoAiProvider.php';
            $this->aiProvider = new \Ikabud\Kernel\DiSyL\AI\EchoAiProvider();
        }
        return $this->aiProvider;
    }

    public function setAiPolicy(\Ikabud\Kernel\DiSyL\AI\Policy $p): void { $this->aiPolicy = $p; }

    public function aiPolicy(): \Ikabud\Kernel\DiSyL\AI\Policy
    {
        if ($this->aiPolicy === null) {
            require_once __DIR__ . '/AI/Policy.php';
            $this->aiPolicy = new \Ikabud\Kernel\DiSyL\AI\Policy();
        }
        return $this->aiPolicy;
    }

    /**
     * Evaluate {federated_query name='…' [policy='all-or-nothing']} block.
     * Children are {remote service=… query=… let=… [fallback=…]} and an
     * optional terminal {aggregate let=…} arm.
     */
    private function evaluateFederatedQueryBody(string $expr, string $innerContent, array $context): string
    {
        $sb = $this->sandbox();
        if (!$sb->require('federation', '{federated_query}', $expr)) {
            return '<!-- federation denied -->';
        }
        $attrs = $this->parseAttrPairs($expr, $context);
        $policy = isset($attrs['policy']) ? (string) $attrs['policy'] : 'partial';

        // Split body into list of remote child specs + optional aggregate.
        $remotes = [];
        $aggregate = null; // ['expr' => string, 'body' => string]
        $offset = 0;
        $len = strlen($innerContent);
        while ($offset < $len) {
            $tag = $this->findNextOpeningControlTag($innerContent, $offset, ['remote', 'aggregate']);
            if ($tag === null) break;
            if ($tag['type'] === 'remote') {
                // {remote ...} is self-closing in our grammar (no body).
                $remotes[] = $tag['expr'];
                $offset = $tag['pos'] + $tag['len'];
            } else { // aggregate has body
                $contentStart = $tag['pos'] + $tag['len'];
                $closePos = $this->findMatchingClose($innerContent, $contentStart, 'aggregate');
                if ($closePos === false) { $offset = $contentStart; continue; }
                $aggregate = ['expr' => $tag['expr'], 'body' => substr($innerContent, $contentStart, $closePos - $contentStart)];
                $offset = $closePos + strlen('{/aggregate}');
            }
        }

        $registry = $this->serviceRegistry();
        $bound = [];
        foreach ($remotes as $rexpr) {
            $rattrs = $this->parseAttrPairs($rexpr, $context);
            $service = (string) ($rattrs['service'] ?? '');
            $query   = (string) ($rattrs['query']   ?? '');
            $let     = $this->extractLetIdentifier($rexpr);
            $fallback = $rattrs['fallback'] ?? null;
            if ($let === '') continue;
            try {
                $bound[$let] = $registry->resolve($service, $query, $context);
            } catch (\Throwable $e) {
                if ($policy === 'all-or-nothing') {
                    return '<!-- federation failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' -->';
                }
                $bound[$let] = $fallback;
            }
        }

        if ($aggregate !== null) {
            $aLet = $this->extractLetIdentifier($aggregate['expr']);
            $childCtx = array_merge($context, $bound);
            $rendered = $this->compile($aggregate['body'], $childCtx);
            if ($aLet !== '') {
                // expose aggregate body output too (uncommon but documented)
                $childCtx[$aLet] = $rendered;
            }
            return $rendered;
        }
        // No aggregate: emit nothing (bound vars are render-local; consumer must use aggregate)
        return '';
    }

    /**
     * Evaluate {ai_generate}/{ai_query}/{ai_complete}.
     * Body of {ai_generate} = the prompt template; {ai_query}/{ai_complete} use prompt= attr.
     */
    private function evaluateAiBody(string $kind, string $expr, string $innerContent, array $context): string
    {
        $sb = $this->sandbox();
        if (!$sb->require('ai', '{' . $kind . '}', $expr)) {
            return '<!-- ai denied: capability -->';
        }
        $policy = $this->aiPolicy();
        if ($policy->isKilled()) {
            return '<!-- ai disabled: KERNEL_AI_DISABLED -->';
        }
        $attrs = $this->parseAttrPairs($expr, $context);
        $model = (string) ($attrs['model'] ?? '');
        if ($model === '' || !$policy->allowsModel($model)) {
            return '<!-- ai denied: model not allowed -->';
        }
        $maxTokens = isset($attrs['max_tokens']) ? (int) $attrs['max_tokens'] : 200;
        $maxTokens = $policy->capMaxTokens($maxTokens);
        if (!$policy->canAfford($model, $maxTokens)) {
            return '<!-- ai denied: cost ceiling -->';
        }

        // Determine prompt source.
        if ($kind === 'ai_generate') {
            $prompt = trim($this->compile($innerContent, $context));
        } else {
            $prompt = (string) ($attrs['prompt'] ?? '');
        }

        $req = [
            'model'      => $model,
            'prompt'     => $prompt,
            'max_tokens' => $maxTokens,
            'temperature' => isset($attrs['temperature']) ? (float) $attrs['temperature'] : 0.0,
        ];
        try {
            $resp = $this->aiProvider()->complete($req);
        } catch (\Throwable $e) {
            return '<!-- ai error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' -->';
        }
        $policy->recordUsage($resp['model'] ?? $model, (int) ($resp['output_tokens'] ?? 0));
        $value = $resp['text'] ?? '';

        // For ai_query with schema, attempt JSON decode.
        if ($kind === 'ai_query') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
        }

        $let = $this->extractLetIdentifier($expr);
        if ($let === '') {
            // No binding: emit value directly (escaped scalar) or nothing for arrays.
            if (is_scalar($value)) return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            return '';
        }
        // ai_generate with let= and a body: body was the prompt; emit nothing,
        // value is bound for downstream context — but our evaluator returns a
        // string so we propagate via context-binding by emitting a sentinel and
        // letting the caller use {let.var}. Instead, we stash in the engine's
        // ad-hoc bind sink so the next compile pass sees it.
        $this->aiLetSink[$let] = $value;
        return '';
    }

    /** @var array<string, mixed> Per-render AI bindings (consumed by render loop). */
    private array $aiLetSink = [];

    /** Public accessor for tests to read AI bindings produced during render. */
    public function aiBindings(): array { return $this->aiLetSink; }

    public function clearAiBindings(): void { $this->aiLetSink = []; }

    // ------------------------------------------------------------- /4.6 --

    /**
     * Flatten top-level scalar context entries into a placeholder var map for
     * {trans} interpolation. Nested structures and non-scalars are skipped to
     * keep the placeholder surface predictable for translators.
     *
     * @return array<string,string>
     */
    private function collectTransVars(array $context): array
    {
        $out = [];
        foreach ($context as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            if (is_string($v) || is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                if ($v === null) {
                    $out[$k] = '';
                } elseif (is_bool($v)) {
                    $out[$k] = $v ? 'true' : 'false';
                } else {
                    $out[$k] = (string) $v;
                }
            }
        }
        return $out;
    }

    /**
     * Evaluate a {for item in list}...{empty}...{/for} body.
     */
    private function evaluateForBody(string $expr, string $innerContent, array $context): string
    {
        if (!preg_match('/^(\w+)\s+in\s+(.+)$/s', trim($expr), $parts)) {
            return '';
        }

        $itemName = $parts[1];
        $listExpr = trim($parts[2]);

        $body = $innerContent;
        $emptyContent = '';
        if (($emptyTagPos = strpos($body, '{empty}')) !== false) {
            $emptyContent = substr($body, $emptyTagPos + 7);
            $body = substr($body, 0, $emptyTagPos);
        }

        $list = $this->resolveValue($listExpr, $context);
        if (!is_array($list)) {
            $list = [];
        }

        if (empty($list)) {
            return $emptyContent !== '' ? $this->compile($emptyContent, $context) : '';
        }

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
        return $result;
    }

    /**
     * Evaluate a {foreach list as [key =>] value}...{empty}...{/foreach} body.
     */
    private function evaluateForeachBody(string $expr, string $innerContent, array $context): string
    {
        $keyName = null;
        $itemName = null;
        $listExpr = null;

        if (preg_match('/^(.+)\s+as\s+(\w+)\s*=>\s*(\w+)$/s', $expr, $parts)) {
            $listExpr = trim($parts[1]);
            $keyName = $parts[2];
            $itemName = $parts[3];
        } elseif (preg_match('/^(.+)\s+as\s+(\w+)$/s', $expr, $parts)) {
            $listExpr = trim($parts[1]);
            $itemName = $parts[2];
        } else {
            return '';
        }

        $body = $innerContent;
        $emptyContent = '';
        if (($emptyTagPos = strpos($body, '{empty}')) !== false) {
            $emptyContent = substr($body, $emptyTagPos + 7);
            $body = substr($body, 0, $emptyTagPos);
        }

        $list = $this->resolveValue($listExpr, $context);
        if (!is_array($list)) {
            $list = [];
        }

        if (empty($list)) {
            return $emptyContent !== '' ? $this->compile($emptyContent, $context) : '';
        }

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
        return $result;
    }

    /**
     * Evaluate a {each list as [key =>] value}...{empty}...{/each} body.
     */
    private function evaluateEachBody(string $expr, string $innerContent, array $context): string
    {
        $keyName = null;
        $itemName = null;
        $listExpr = null;

        if (preg_match('/^(.+)\s+as\s+(\w+)\s*=>\s*(\w+)$/s', $expr, $parts)) {
            $listExpr = trim($parts[1]);
            $keyName = $parts[2];
            $itemName = $parts[3];
        } elseif (preg_match('/^(.+)\s+as\s+(\w+)$/s', $expr, $parts)) {
            $listExpr = trim($parts[1]);
            $itemName = $parts[2];
        } else {
            return '';
        }

        $body = $innerContent;
        $emptyContent = '';
        if (($emptyTagPos = strpos($body, '{empty}')) !== false) {
            $emptyContent = substr($body, $emptyTagPos + 7);
            $body = substr($body, 0, $emptyTagPos);
        }

        $list = $this->resolveValue($listExpr, $context);
        if (!is_array($list)) {
            $list = [];
        }

        if (empty($list)) {
            return $emptyContent !== '' ? $this->compile($emptyContent, $context) : '';
        }

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
        return $result;
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
            // Allow argless form {trusted}/{untrusted}/{parallel} (immediate '}')
            if ($nextChar === '}' && ($type === 'trusted' || $type === 'untrusted' || $type === 'parallel')) {
                $full = substr($content, $pos, $whitespacePos - $pos + 1);
                return [
                    'type' => $type,
                    'expr' => '',
                    'pos'  => $pos,
                    'len'  => strlen($full),
                    'full' => $full,
                ];
            }
            if ($nextChar === '' || !ctype_space($nextChar)) {
                continue;
            }

            $tagEnd = strpos($content, '}', $whitespacePos + 1);
            if ($tagEnd === false) {
                return null;
            }

            $full = substr($content, $pos, $tagEnd - $pos + 1);
            $expr = trim(substr($content, $whitespacePos + 1, $tagEnd - $whitespacePos - 1));

            if ($expr === '' && $type !== 'trusted' && $type !== 'untrusted' && $type !== 'parallel') {
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
            self::$cacheMetrics['source_hits']++;
            return $this->includeSourceCache[$includePath];
        }

        if ($this->cacheEnabled && $this->hasApcuCache()) {
            $mtime = (int)@filemtime($includePath);
            $apcuKey = 'disyl:source:' . md5($includePath . '|' . $mtime);
            $cached = apcu_fetch($apcuKey, $ok);
            if ($ok && is_string($cached)) {
                self::$cacheMetrics['source_hits']++;
                $this->includeSourceCache[$includePath] = $cached;
                return $cached;
            }
        }

        $includeContent = file_get_contents($includePath);
        if ($includeContent === false) {
            return false;
        }
        self::$cacheMetrics['source_misses']++;

        if ($this->cacheEnabled) {
            if (count($this->includeSourceCache) >= self::TEMPLATE_SOURCE_CACHE_MAX) {
                reset($this->includeSourceCache);
                unset($this->includeSourceCache[key($this->includeSourceCache)]);
            }
            $this->includeSourceCache[$includePath] = $includeContent;
            if ($this->hasApcuCache()) {
                $mtime = (int)@filemtime($includePath);
                $apcuKey = 'disyl:source:' . md5($includePath . '|' . $mtime);
                apcu_store($apcuKey, $includeContent, 300);
            }
        }

        return $includeContent;
    }

    private function readTemplateSource(string $templatePath): string|false
    {
        if ($this->cacheEnabled && isset($this->templateSourceCache[$templatePath])) {
            self::$cacheMetrics['source_hits']++;
            return $this->templateSourceCache[$templatePath];
        }

        if ($this->cacheEnabled && $this->hasApcuCache()) {
            $mtime = (int)@filemtime($templatePath);
            $apcuKey = 'disyl:source:' . md5($templatePath . '|' . $mtime);
            $cached = apcu_fetch($apcuKey, $ok);
            if ($ok && is_string($cached)) {
                self::$cacheMetrics['source_hits']++;
                $this->templateSourceCache[$templatePath] = $cached;
                return $cached;
            }
        }

        self::$cacheMetrics['source_misses']++;

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
            if ($this->hasApcuCache()) {
                $mtime = (int)@filemtime($templatePath);
                $apcuKey = 'disyl:source:' . md5($templatePath . '|' . $mtime);
                apcu_store($apcuKey, $content, 300);
            }
        }

        return $content;
    }

    private function isCompiledEligibleTemplate(string $templatePath): bool
    {
        if ($templatePath === '') {
            return false;
        }

        if (array_key_exists($templatePath, $this->compiledEligibilityCache)) {
            return $this->compiledEligibilityCache[$templatePath];
        }

        $visited = [];
        $eligible = !$this->templateGraphUsesComponentTags($templatePath, $visited);
        $this->compiledEligibilityCache[$templatePath] = $eligible;

        return $eligible;
    }

    private function templateGraphUsesComponentTags(string $templatePath, array &$visited): bool
    {
        if ($templatePath === '' || isset($visited[$templatePath])) {
            return false;
        }

        $visited[$templatePath] = true;
        $source = $this->readTemplateSource($templatePath);
        if (!is_string($source)) {
            return false;
        }

        if (str_contains($source, '{ikb_') || str_contains($source, '{island')) {
            return true;
        }

        if (!preg_match_all('/\{(?:extends|include)\s+"([^"]+)"/', $source, $matches)) {
            return false;
        }

        foreach ($matches[1] as $relatedTemplate) {
            $relatedPath = $this->resolveTemplatePath($relatedTemplate);
            if ($relatedPath !== '' && $this->templateGraphUsesComponentTags($relatedPath, $visited)) {
                return true;
            }
        }

        return false;
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
     * Process {capability "id" [with {key: value, ...}]} tags.
     *
     * Calls the Capability Bus and injects the result into the template context
     * under the key `capability_result`. The rendered body is always output;
     * use {capability_result.*} variables inside the block to access the response.
     *
     * Syntax:
     *   {capability "inventory.check@1" with {product_id: product.id}}
     *
     * If the capability call fails (circuit open, timeout, etc.) the tag renders
     * an empty string and logs the failure.
     */
    private function processCapabilityTags(string $content, array $context): string
    {
        return preg_replace_callback(
            '/\{capability\s+"([^"]+)"(?:\s+with\s+\{([^}]*)\})?\s*\}(.*?)\{\/capability\}/s',
            function (array $m) use ($context): string {
                $capId     = trim($m[1]);
                $withRaw   = trim($m[2] ?? '');
                $body      = $m[3];

                // Validate capability ID format: "name@version" or "name"
                if (!preg_match('/^[a-zA-Z0-9_.\-]+(@@?[0-9]+)?$/', $capId)) {
                    $this->logError("Invalid capability id in template: {$capId}");
                    return '';
                }

                // Parse with-block key:value pairs; values may be variable paths
                $payload = [];
                if ($withRaw !== '') {
                    foreach (explode(',', $withRaw) as $pair) {
                        [$k, $v] = array_pad(explode(':', $pair, 2), 2, '');
                        $k = trim($k);
                        $v = trim($v);
                        if ($k !== '') {
                            // Resolve variable path if not a literal
                            $payload[$k] = $this->resolveValue($v, $context) ?? $v;
                        }
                    }
                }

                try {
                    if (!function_exists('app')) {
                        return '';
                    }
                    $result = app()->capabilities()->call($capId, $payload);
                    $context['capability_result'] = is_array($result) ? $result : ['value' => $result];
                } catch (\Throwable $e) {
                    $this->logError("Capability tag call failed ({$capId}): " . $e->getMessage());
                    return '';
                }

                return $this->processVariables(
                    $this->processControlStructures($body, $context),
                    $context
                );
            },
            $content
        ) ?? $content;
    }

    /**
     * Process {on "event.key"}...{/on} tags.
     *
     * Conditionally renders the body when the event key is present in the
     * render context (injected as `events.event_key` or `event_key`).
     * Intended for server-side conditional rendering based on event payload
     * data passed into the template context by the route handler.
     *
     * Syntax:
     *   {on "order.created"}{component "order-card"}{/on}
     */
    private function processOnTags(string $content, array $context): string
    {
        return preg_replace_callback(
            '/\{on\s+"([^"]+)"\s*\}(.*?)\{\/on\}/s',
            function (array $m) use ($context): string {
                $eventKey = trim($m[1]);
                $body     = $m[2];

                // Validate event key format
                if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $eventKey)) {
                    $this->logError("Invalid event key in {on} tag: {$eventKey}");
                    return '';
                }

                // Check events sub-array first, then flat context key (normalized: dots→underscores)
                $normalizedKey = str_replace('.', '_', $eventKey);
                $events = $context['events'] ?? [];

                $present = (is_array($events) && (
                    array_key_exists($eventKey, $events) ||
                    array_key_exists($normalizedKey, $events)
                )) || array_key_exists($eventKey, $context) || array_key_exists($normalizedKey, $context);

                if (!$present) {
                    return '';
                }

                return $this->processVariables(
                    $this->processControlStructures($body, $context),
                    $context
                );
            },
            $content
        ) ?? $content;
    }

    /**
     * Process variables with filters, arithmetic, and ternary expressions.
     * Skips JavaScript template literals (${...}).
     * 
     * Single-pass implementation: one regex scan classifies each {expression}
     * as ternary, arithmetic, or standard variable. A per-call resolution
     * cache avoids re-resolving the same variable path multiple times.
     */
    private function processVariables(string $content, array $context): string
    {
        if (!str_contains($content, '{')) {
            return $content;
        }

        // Resolution cache: avoid re-resolving the same variable path
        $resolveCache = [];

        $content = preg_replace_callback(
            '/(?<!\$)\{((?:[a-zA-Z_(]|\d)[^{}]*)\}/',
            function($match) use ($context, &$resolveCache) {
                $expr = trim($match[1]);

                if (!$this->isProcessableTemplateExpression($expr)) {
                    return $match[0];
                }

                // 1. Ternary: {condition ? trueVal : falseVal}
                //    Only if ? appears before any | (avoid matching filter args containing ?)
                if (str_contains($expr, '?') && str_contains($expr, ':')) {
                    $pipePos = strpos($expr, '|');
                    $qPos = strpos($expr, '?');
                    if ($pipePos === false || $qPos < $pipePos) {
                        return $this->evaluateTernary($expr, $context);
                    }
                }

                // 2. Arithmetic/expression: no pipe + contains operators or parentheses.
                //    Handles simple {a + b}, chained {a / b * c}, parenthesized {(a + b) * c}.
                if (!str_contains($expr, '|') && strpbrk($expr, '+-*/%()') !== false) {
                    $result = $this->evaluateArithmetic($expr, $context);
                    if ($result !== null) {
                        return htmlspecialchars((string) $result, ENT_QUOTES, 'UTF-8');
                    }
                    // Not a valid arithmetic expression — fall through to variable resolution
                }

                // 3. Simple variable (no filters)
                if (!str_contains($expr, '|')) {
                    if (!array_key_exists($expr, $resolveCache)) {
                        $resolveCache[$expr] = $this->resolveValue($expr, $context);
                    }
                    $value = $resolveCache[$expr];

                    // Strict mode: warn when a variable resolves to null (undefined in context).
                    if ($this->strictMode && $value === null) {
                        $this->logError("[strict] Undefined variable: {$expr}");
                    }

                    if (!is_scalar($value)) {
                        return '';
                    }
                    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                }

                // 4. Variable with filters
                $hasRaw = false;
                $filterNames = [];
                $filters = $this->splitByPipe($expr);
                $varPath = trim((string) array_shift($filters));

                if (!array_key_exists($varPath, $resolveCache)) {
                    $resolveCache[$varPath] = $this->resolveValue($varPath, $context);
                }
                $value = $resolveCache[$varPath];

                // Strict mode: warn when filtered variable is undefined.
                if ($this->strictMode && $value === null) {
                    $this->logError("[strict] Undefined variable: {$varPath}");
                }

                foreach ($filters as $filter) {
                    $filter = trim($filter);
                    if ($filter === '') {
                        continue;
                    }

                    $filterName = trim(explode(':', $filter, 2)[0]);
                    if ($filterName === 'raw') {
                        if (!$this->sandbox()->require('raw.html', '| raw on ' . $varPath, (string) $value)) {
                            // Denied: emit auto-escaped output instead.
                            $hasRaw = false;
                            continue;
                        }
                        $hasRaw = true;
                        if ($this->strictMode) {
                            $this->logError("[strict] Raw filter used on variable: {$varPath}");
                        }
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

    private function isProcessableTemplateExpression(string $expr): bool
    {
        if ($expr === '') {
            return false;
        }

        if (str_contains($expr, '?') && str_contains($expr, ':')) {
            return true;
        }

        if (!str_contains($expr, '|') && strpbrk($expr, '+-*/%()') !== false) {
            return true;
        }

        $filters = $this->splitByPipe($expr);
        $varPath = trim((string) array_shift($filters));

        return $this->isValidTemplateVariablePath($varPath);
    }

    private function isValidTemplateVariablePath(string $varPath): bool
    {
        return preg_match('/^[a-zA-Z_][\w.]*$/', $varPath) === 1;
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
        $escapeFilters = ['esc_html', 'esc_attr', 'esc_url', 'esc_js', 'json', 'json_attr', 'url_encode', 'base64', 'nl2br'];
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

        // Boolean and null literals
        $lower = strtolower($path);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null') return null;

        // Numeric literals
        if (is_numeric($path)) {
            return str_contains($path, '.') ? (float)$path : (int)$path;
        }

        // Function call: funcname(args...)
        if (preg_match('/^([a-zA-Z_]\w*)\s*\(/', $path, $fcm)) {
            $parenStart = strpos($path, '(', strlen($fcm[1]));
            if ($parenStart !== false) {
                // Find the matching close paren
                $depth = 0;
                $close = -1;
                for ($i = $parenStart, $plen = strlen($path); $i < $plen; $i++) {
                    if ($path[$i] === '(') $depth++;
                    elseif ($path[$i] === ')') {
                        $depth--;
                        if ($depth === 0) { $close = $i; break; }
                    }
                }
                if ($close === strlen($path) - 1) {
                    $funcName = $fcm[1];
                    $argsStr  = trim(substr($path, $parenStart + 1, $close - $parenStart - 1));
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
                    return \Ikabud\Kernel\DiSyL\v4\FunctionRegistry::call($funcName, $resolved);
                }
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
                return null;
            }
        }
        
        return $value;
    }

    /**
     * Split comma-separated function call arguments, respecting nested
     * parentheses, brackets, and quoted strings.
     */
    private function splitCallArgs(string $str): array
    {
        $parts    = [];
        $cur      = '';
        $inSingle = false;
        $inDouble = false;
        $depth    = 0;
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
        if (($t = trim($cur)) !== '') {
            $parts[] = $t;
        }
        return $parts;
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
        
        // Handle negation: ! prefix or 'not' keyword
        if (preg_match('/^!\s*(.+)$/', $condition, $nm)) {
            return !$this->evaluateCondition($nm[1], $context);
        }
        if (preg_match('/^not\s+(.+)$/i', $condition, $nm)) {
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
            // json_attr: JSON-encode then HTML-escape for safe embedding in double-quoted HTML attributes.
            // Use {myArray | json_attr} in x-data="{raw: {myArray | json_attr}}" and similar Alpine/x-* attrs.
            // Browsers decode &quot; → " before passing the attribute value to JS, so Alpine.js sees correct JSON.
            'json_attr' => fn($v) => htmlspecialchars(
                json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ENT_QUOTES,
                'UTF-8'
            ),
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

        $moduleAliasPath = $this->resolveModuleTemplateAliasPath($template);
        if ($moduleAliasPath !== null) {
            return $moduleAliasPath;
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

    private function resolveModuleTemplateAliasPath(string $template): ?string
    {
        if (!str_starts_with($template, 'modules/') || !function_exists('modulePathForId') || !defined('BASE_PATH')) {
            return null;
        }

        $parts = explode('/', $template, 3);
        if (count($parts) < 3) {
            return null;
        }

        $moduleId = trim((string)($parts[1] ?? ''));
        $templateSuffix = ltrim((string)($parts[2] ?? ''), '/');
        if ($moduleId === '' || $templateSuffix === '') {
            return null;
        }

        $modulePath = modulePathForId($moduleId);
        if (!is_string($modulePath) || $modulePath === '') {
            return null;
        }

        $modulesRoot = rtrim((string)BASE_PATH, '/') . '/modules/';
        $normalizedModulePath = $this->normalizePath($modulePath);
        $normalizedModulesRoot = $this->normalizePath($modulesRoot);
        if (!str_starts_with($normalizedModulePath, $normalizedModulesRoot)) {
            return null;
        }

        $relativeModulePath = ltrim(substr($normalizedModulePath, strlen($normalizedModulesRoot)), '/');
        if ($relativeModulePath === '') {
            return null;
        }

        $candidate = $this->templateDir . '/modules/' . $relativeModulePath . '/' . $templateSuffix;
        $normalizedCandidate = $this->normalizePath($candidate);
        $normalizedTemplateDir = $this->normalizePath($this->templateDir);
        if (!str_starts_with($normalizedCandidate, $normalizedTemplateDir . '/')) {
            $this->logError("Path traversal attempt blocked: {$template}");
            return '';
        }

        if (is_file($normalizedCandidate)) {
            return $normalizedCandidate;
        }

        return null;
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
