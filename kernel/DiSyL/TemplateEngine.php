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

use Ikabud\Kernel\DiSyL\Bridge\BridgeManager;
use Ikabud\Kernel\DiSyL\v4\RenderContext;

class TemplateEngine
{
    private string $templateDir;
    private string $cacheDir;
    private bool $cacheEnabled;
    private bool $debug = false;
    /** Compiled mode is ON by default (v4.7+). Falls back to interpreted on failure. */
    private bool $compiledMode = true;
    /** Track whether eager compiled-cache init has been attempted this request. */
    private bool $compiledModeBooted = false;
    /** Strict mode ON by default (v4.8+). Logs undefined vars, type mismatches, |raw usage. */
    private bool $strictMode = true;
    /** Auto-convert HTML-style <ikb_> tags to DiSyL {ikb_...} syntax (default off). */
    private bool $autoConvertHtmlTags = false;
    /** @var array<string, array{params: array, body: string}> Registered {macro} definitions */
    private array $macros = [];
    /** @var int Recursion depth for compile() — macros only extracted at depth 0 */
    private int $compileDepth = 0;
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

    /** @var string|null Expression currently being evaluated (for error context) */
    private ?string $currentExpression = null;

    /** @var string Directory for cross-request extends resolution cache */
    private string $extendsCacheDir;

    /** @var array<string, string> {@var} declarations: variable name => type string */
    private array $declaredVars = [];
    
    /** @var ExpressionEvaluator Lazy-instantiated expression evaluator */
    private ?ExpressionEvaluator $evaluator = null;

    public function __construct(string $templateDir, string $cacheDir, bool $cacheEnabled = true)
    {
        $this->templateDir = rtrim($templateDir, '/');
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->cacheEnabled = $cacheEnabled;
        $this->extendsCacheDir = $this->cacheDir . '/disyl-extends';

        $this->registerDefaultFilters();
        $this->registerDefaultComponents();
    }

    /**
     * Get or create the shared expression evaluator.
     */
    private function evaluator(): ExpressionEvaluator
    {
        if ($this->evaluator === null) {
            $this->evaluator = new ExpressionEvaluator();
            $this->evaluator->setStrictMode($this->strictMode);
            $this->evaluator->setDeclaredVars($this->declaredVars);
            $this->evaluator->setFilters($this->filters);
            $this->evaluator->setScriptContext($this->scriptContext);
            $this->evaluator->setCurrentTemplatePath($this->currentTemplatePath);
            $this->evaluator->setLogErrorCallback(\Closure::fromCallable([$this, 'logError']));
        }
        return $this->evaluator;
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
        if ($this->evaluator !== null) {
            $this->evaluator->setStrictMode($enable);
        }
    }

    /**
     * Enable auto-conversion of HTML-style <ikb_> tags to DiSyL {ikb_...} syntax.
     *
     * When enabled, the engine converts:
     *   <ikb_section padding_y="lg">        → {ikb_section padding_y="lg"}
     *   <ikb_entity_list source="..." />    → {ikb_entity_list source="..." /}
     *   </ikb_section>                      → {/ikb_section}
     *
     * This helps templates migrating from HTML-style to curly-brace syntax.
     * Conversion happens at step 8.5, before component processing.
     */
    public function enableAutoConvertHtmlTags(bool $enable = true): void
    {
        $this->autoConvertHtmlTags = $enable;
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

    /**
     * Load DiSyL entity view config files from a module's helpers/views/ directory.
     *
     * Scans for *.disyl files, renders each through a temporary TemplateEngine
     * to process {ikb_entity_view} declarations, which register view contracts
     * with the EntityViewResolver at runtime.
     *
     * Call from a module's helpers bootstrap, e.g.:
     *   \Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/views');
     *
     * @param string $viewsDir Absolute path to the views directory
     * @return int Number of config files loaded
     */
    /**
     * Get details from the last loadViewConfigs call, including per-file errors.
     *
     * @return array{file:string, success:bool, errors:array}[]|null Null if loadViewConfigs was never called.
     */
    public static function getLastLoadErrors(): ?array
    {
        return self::$lastLoadErrors;
    }

    public static function loadViewConfigs(string $viewsDir): int
    {
        self::$lastLoadErrors = null;

        if (!is_dir($viewsDir)) {
            return 0;
        }

        $count = 0;
        $files = glob($viewsDir . '/*.disyl');
        if ($files === false || $files === []) {
            return 0;
        }

        // Use a temporary engine — the {ikb_entity_view} component handles
        // EntityViewResolver registration internally.
        $engine = new self('/tmp', '/tmp/cache');
        $engine->enableStrictMode(false);

        $results = [];
        $hasCriticalErrors = false;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false || $content === '') {
                \write_log('disyl.view_config', 'warning', ['file' => $file, 'error' => 'Empty or unreadable file']);
                $results[] = ['file' => $file, 'success' => false, 'errors' => ['Empty or unreadable file']];
                continue;
            }
            // Render the config file — the component produces no output
            // but registers views via EntityViewResolver as a side effect.
            $engine->renderString($content, []);

            // Collect errors from the config rendering
            $fileErrors = [];
            foreach ($engine->getErrors() as $err) {
                $fileErrors[] = $err;
                \write_log('disyl.view_config', 'error', ['file' => $file, 'error' => $err]);
            }

            if (!empty($fileErrors)) {
                $hasCriticalErrors = true;
                $results[] = ['file' => $file, 'success' => false, 'errors' => $fileErrors];
            } else {
                $results[] = ['file' => $file, 'success' => true, 'errors' => []];
            }

            $count++;
        }

        self::$lastLoadErrors = $results;

        // Throw if any file had errors — prevents silent contract registration failures
        if ($hasCriticalErrors) {
            $failures = [];
            foreach ($results as $r) {
                if (!$r['success']) {
                    $failures[] = basename($r['file']) . ': ' . implode('; ', $r['errors']);
                }
            }
            throw new \RuntimeException(
                'Entity view config loading failed for ' . count($failures) . ' file(s): ' . implode(' | ', $failures)
            );
        }

        return $count;
    }
    
    /** @var array In-memory cache of compiled output per request */
    private array $outputCache = [];

    /** @var array{file:string, errors:array}[]|null Last loadViewConfigs result with per-file errors */
    private static ?array $lastLoadErrors = null;

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

    /**
     * Bump this version whenever the compiled-eligibility rules change
     * (e.g. new interpreted-only tags are added to the exclusion list).
     * Stale eligibility cache files from older versions are automatically
     * ignored — no manual cache clearing required.
     */
    private const COMPILED_ELIGIBILITY_CACHE_VERSION = 2;

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

        // Guard against empty resolved paths from upstream code
        if ($templatePath === '' || $templatePath === $this->templateDir . '/.disyl') {
            $this->logError("Invalid template path resolved: {$template}");
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
        //
        // Since compiled mode is the default (v4.7+), eagerly boot the
        // compiled cache on first render if it hasn't been tried yet.
        if ($this->compiledMode && $this->compiledCache === null && !$this->compiledModeBooted) {
            $this->compiledModeBooted = true;
            $this->enableCompiledMode(true);
        }
        if ($this->compiledMode && $this->compiledCache !== null && $this->isCompiledEligibleTemplate($templatePath)) {
            try {
                $compiled = $this->compiledCache->get($templatePath);
                
                $loader = function(string $tmpl) use (&$loader) {
                    $path = $this->resolveTemplatePath($tmpl);
                    // Guard against empty/invalid resolved paths from blank includes
                    if ($path === '' || !file_exists($path)) {
                        $this->logError("Template include not found: {$tmpl}");
                        // Return a silent no-op so the page still renders
                        $c = $this->compiledCache->compileSource('', $tmpl);
                        $c->setTemplateLoader($loader);
                        return $c;
                    }
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

        // v4.8: track recursion depth — macros only extracted at top level.
        // Always clean-slate macros at the start of a top-level compile
        // to prevent cross-request state leakage in PHP-FPM.
        $isTopLevel = ($this->compileDepth === 0);
        if ($isTopLevel) {
            $this->macros = [];
        }
        $this->compileDepth++;

        // Fast-path: skip full compile when content has no DiSyL markers.
        // When auto-convert is enabled, also keep content with <ikb_ HTML tags
        // so step 8.5 can convert them before the component processor runs.
        $hasHtmlIkb = $this->autoConvertHtmlTags && str_contains($content, '<ikb_');
        if (!str_contains($content, '{') && !$hasHtmlIkb
            && stripos($content, '<script') === false && stripos($content, '<style') === false
        ) {
            $this->compileDepth--;
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
        
        // 0a. Extract {@var type $name} declarations — register variable types,
        //     then remove from output (produce no HTML).
        if (str_contains($content, '{@var ')) {
            $content = preg_replace_callback(
                '/\{@var\s+(\??\w+(?:<[^>]+>)?)\s+\$([a-zA-Z_]\w*)\s*\}/',
                function($match) {
                    $type = $match[1];
                    $name = $match[2];
                    $this->declaredVars[$name] = $type;
                    if ($this->evaluator !== null) {
                        $this->evaluator->setDeclaredVars($this->declaredVars);
                    }
                    return ''; // {@var} produces no output
                },
                $content
            );
        }
        
        // 1. Remove comments first
        if (str_contains($content, '{!--') || str_contains($content, '{*') || str_contains($content, '{#')) {
            $content = $this->removeComments($content);
        }
        
        // 1.5. Pre-extends macro extraction — catch {macro} definitions in
        //      the child template that live OUTSIDE {block} tags.  These
        //      would be discarded by processExtends() since only {block}
        //      content is preserved during layout merging.
        $hasExtends = str_contains($content, '{extends ');
        if ($isTopLevel && $hasExtends && str_contains($content, '{macro ')) {
            $this->macros = [];
            $content = $this->extractMacros($content, merge: false);
        }

        // 2. Process extends/layouts (merges child blocks into layout)
        if ($hasExtends) {
            $t = microtime(true);
            $content = $this->processExtends($content, $context);
            $phases['extends_ms'] = round((microtime(true) - $t) * 1000, 2);

            // Post-extends {@var} extraction — the layout may contain {@var}
            // declarations that were merged in. Extract and strip them now,
            // since step 0a ran on the child template before extends resolution.
            if (str_contains($content, '{@var ')) {
                $content = preg_replace_callback(
                    '/\{@var\s+(\??\w+(?:<[^>]+>)?)\s+\$([a-zA-Z_]\w*)\s*\}/',
                    function($match) {
                        $type = $match[1];
                        $name = $match[2];
                        $this->declaredVars[$name] = $type;
                        return '';
                    },
                    $content
                );
            }
        }

        // 2.5. Post-extends macro extraction — catch {macro} definitions
        //      from the parent layout now in the merged content.  Merge
        //      mode preserves macros already extracted from the child.
        if ($isTopLevel && $hasExtends && str_contains($content, '{macro ')) {
            $content = $this->extractMacros($content, merge: true);
        }

        // 2.6. Non-extends macro extraction — standalone templates get a
        //      single clean extraction pass.
        if ($isTopLevel && !$hasExtends && str_contains($content, '{macro ')) {
            $this->macros = [];
            $content = $this->extractMacros($content);
        }
        
        // 3. Remove comments again (layout may have comments)
        if (str_contains($content, '{!--') || str_contains($content, '{*') || str_contains($content, '{#')) {
            $content = $this->removeComments($content);
        }
        
        // 4. Process blocks (standalone)
        if (str_contains($content, '{block ')) {
            $content = $this->processBlocks($content, $context);
        }
        
        // 4b. Extract <script> blocks — process DiSyL variables inside script bodies
        $scripts = [];
        if (stripos($content, '<script') !== false) {
            $t = microtime(true);
            $content = preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/si', function($match) use (&$scripts, $context) {
                $attrs = $match[1];
                $body = $match[2];
                
                // Resolve DiSyL variables in tag attributes only (e.g. src="{base_url}/...")
                $attrs = $this->processVariables($attrs, $context);
                
                // Resolve DiSyL variables inside script body — protects JS curly braces
                // from being mistaken for DiSyL tags, then resolves {variable} references.
                $body = $this->compileScriptBody($body, $context);
                
                $key = '___SCRIPT_' . count($scripts) . '___';
                $scripts[$key] = '<script' . $attrs . '>' . $body . '</script>';
                return $key;
            }, $content);
            $phases['scripts_ms'] = round((microtime(true) - $t) * 1000, 2);
        }
        
        // 4c. Extract <style> blocks as raw passthrough — no DiSyL evaluation.
        //     Variables in tag attributes still resolve (e.g. media="{breakpoint}").
        $styles = [];
        if (stripos($content, '<style') !== false) {
            $t = microtime(true);
            $content = preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/si', function($match) use (&$styles, $context) {
                $attrs = $this->processVariables($match[1], $context);
                
                $key = '___STYLE_' . count($styles) . '___';
                $styles[$key] = '<style' . $attrs . '>' . $match[2] . '</style>';
                return $key;
            }, $content);
            $phases['styles_ms'] = round((microtime(true) - $t) * 1000, 2);
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
        
        // 8.5. Auto-convert HTML-style <ikb_ tags to DiSyL {ikb_...} syntax
        //     When autoConvertHtmlTags is enabled, converts in place so templates
        //     using HTML-style tags render without manual edits. When disabled,
        //     logs a warning pointing to the correct syntax.
        if (str_contains($content, '<ikb_') || str_contains($content, '</ikb_')) {
            if ($this->autoConvertHtmlTags) {
                // 1. Self-closing: <ikb_tag attr="val" /> → {ikb_tag attr="val" /}
                //    Uses [^>]* for attributes — safe for DiSyL templates where
                //    > never appears inside attribute values.
                $content = preg_replace(
                    '/<(ikb_\w+)([^>]*?)\s*\/>/',
                    '{$1$2 /}',
                    $content
                );
                // 2. Opening: <ikb_tag attr="val"> → {ikb_tag attr="val"}
                $content = preg_replace(
                    '/<(ikb_\w+)([^>]*?)>/',
                    '{$1$2}',
                    $content
                );
                // 3. Closing: </ikb_tag> → {/ikb_tag}
                $content = preg_replace('/<\/(ikb_\w+)\s*>/', '{/$1}', $content);
            } else {
                preg_match_all('/<(\w+(?:-\w+)*)([\s>])/', $content, $htmlTags, PREG_SET_ORDER);
                $seen = [];
                foreach ($htmlTags as $tag) {
                    $name = $tag[1];
                    if (str_starts_with($name, 'ikb_') && !isset($seen[$name])) {
                        $seen[$name] = true;
                        $this->logError("Component tag '<{$name}>' uses HTML angle brackets — must use DiSyL curly-brace syntax: '{" . $name . ' ... /}". All component tags must use { } delimiters, not < >.');
                    }
                }
            }
        }

        // 9. Process components
        if (str_contains($content, '{ikb_') || str_contains($content, '{island') || str_contains($content, '{state')) {
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

        // 9c. Process {debug expr} — pretty-print any variable for development
        if (str_contains($content, '{debug ')) {
            $content = $this->processDebugTags($content, $context);
        }

        // 10. Process remaining variables (including arithmetic and ternary expressions)
        if (str_contains($content, '{')) {
            $t = microtime(true);
            $content = $this->processVariables($content, $context);
            $phases['variables_ms'] = round((microtime(true) - $t) * 1000, 2);
        }

        // 10.5. Expand {call name(args)} — substitute macro bodies with resolved args
        if (!empty($this->macros) && str_contains($content, '{call ')) {
            $content = $this->expandMacroCalls($content, $context);
        }
        
        // 11. Restore {literal} blocks (raw, no processing)
        if (!empty($literals)) {
            $content = str_replace(array_keys($literals), array_values($literals), $content);
        }
        
        // 12. Restore <script> blocks (raw passthrough)
        if (!empty($scripts)) {
            $content = str_replace(array_keys($scripts), array_values($scripts), $content);
        }
        
        // 12b. Restore <style> blocks (raw passthrough)
        if (!empty($styles)) {
            $content = str_replace(array_keys($styles), array_values($styles), $content);
        }
        
        // 13. Restore {verbatim} blocks last (completely raw)
        if (!empty($verbatims)) {
            $content = str_replace(array_keys($verbatims), array_values($verbatims), $content);
        }

        // 14. Emit template manifest for tooling (top-level compile only)
        if ($isTopLevel && $this->currentTemplatePath !== null) {
            try {
                if (class_exists(\Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::class, true)) {
                    \Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::build(
                        $this->currentTemplatePath,
                        $content,
                        $context
                    );
                }
            } catch (\Throwable $e) {
                // Manifest emission is non-critical
            }
        }

        // Emit phase breakdown (guarded by APP_TIMING_LOGS)
        $phases['total_ms'] = round((microtime(true) - $compileStartedAt) * 1000, 2);
        $phases['content_bytes'] = strlen($content);
        if (function_exists('log_timing')) {
            log_timing('disyl.compile.phases', $compileStartedAt, $phases);
        }

        $this->compileDepth--;
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
     * Also supports shorthand {var = expr} (without the set keyword).
     * Removes the tag from output and adds the computed value to context.
     * Supports: {set x = 5}, {set total = items | count}, {set next = page + 1},
     *           {set locked = status != 'pending'}, {set ok = count > 0}
     */
    private function processSetStatements(string $content, array &$context): string
    {
        // Normalize shorthand {var = expr} to {set var = expr} before processing
        $content = preg_replace('/\{(?!set\s)(\w+)\s*=\s*([^}]+)\}/', '{set $1 = $2}', $content);

        // Match {set ...}, {set ... += ...}, {set ... ++}, etc.
        // Uses `#` as delimiter to avoid escaping / inside character classes.
        return preg_replace_callback(
            '#\{set\s+(\w+)(?::\s*(\??(?:"[^"]*"(?:\s*\|\s*"[^"]*")*|\w+)))?\s*(?:(?:([+\-*\/]))?\s*=\s*([^}]+)|(\+\+|--))\}#',
            function($match) use (&$context) {
                $varName = trim($match[1]);
                $varType = isset($match[2]) && $match[2] !== '' ? trim($match[2]) : null;

                // Case 1: {set x++} or {set x--} (postfix)
                if (isset($match[5]) && ($match[5] === '++' || $match[5] === '--')) {
                    $current = (int)($context[$varName] ?? 0);
                    $context[$varName] = $match[5] === '++' ? $current + 1 : $current - 1;
                    return '';
                }

                // Case 2: compound assignment {set x += val} or simple {set x = val}
                $compoundOp = isset($match[3]) && $match[3] !== '' ? trim($match[3]) : null;
                $expr = isset($match[4]) ? trim($match[4]) : '';

                $value = $this->resolveSetValue($expr, $context, $varType);

                if ($compoundOp !== null) {
                    $current = (int)($context[$varName] ?? 0);
                    $value = match ($compoundOp) {
                        '+' => $current + $value,
                        '-' => $current - $value,
                        '*' => $current * $value,
                        '/' => $current / $value,
                        default => $value,
                    };
                    $value = $this->coerceType($value, $varType, $varName);
                    $context[$varName] = $value;
                    return '';
                }

                $context[$varName] = $value;
                return '';
            },
            $content
        );
    }

    /**
     * Resolve a {set} expression value through multiple strategies.
     */
    private function resolveSetValue(string $expr, array $context, ?string $varType): mixed
    {
        // Try arithmetic first
        $value = $this->evaluateArithmetic($expr, $context);
        if ($value !== null) {
            return $this->coerceType($value, $varType, '');
        }

        // Try boolean/comparison expression
        $value = $this->evaluateComparison($expr, $context);
        if ($value !== null) {
            return $this->coerceType($value, $varType, '');
        }

        // Try quoted string literal
        if (preg_match('/^["\'](.*)["\']\s*$/', $expr, $qm)) {
            return $this->coerceType($qm[1], $varType, '');
        }

        // Try numeric literal
        if (is_numeric($expr)) {
            return $this->coerceType($expr + 0, $varType, '');
        }

        // Fall back to variable with filters
        $value = $this->resolveValueWithFilters($expr, $context);
        return $this->coerceType($value, $varType, '');
    }

    /**
     * Coerce a value to a declared type annotation.
     *
     * Supports: string, int, float, bool, array, mixed (no-op).
     * Nullable prefix `?` allows null to pass through uncoerced.
     *
     * In strict mode, logs a warning on type mismatch but does not coerce.
     * In non-strict mode (default), coerces the value to the declared type.
     */
    private function coerceType(mixed $value, ?string $type, string $varName): mixed
    {
        return $this->evaluator()->coerceType($value, $type, $varName);
    }

    /**
     * Evaluate comparison/boolean expressions for {set} statements.
     * Supports: var op "string", var op num, !var, cond && cond, cond || cond
     * Returns null if not a recognized comparison.
     */
    private function evaluateComparison(string $expr, array $context): ?bool
    {
        return $this->evaluator()->evaluateComparison($expr, $context);
    }
    
    /**
     * Evaluate arithmetic expressions: var + num, var - num, var * num, var / num, var % num
     * Returns null if the expression is not arithmetic.
     */
    private function evaluateArithmetic(string $expr, array $context): int|float|null
    {
        return $this->evaluator()->evaluateArithmetic($expr, $context);
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
        return $this->evaluator()->tokenizeArithExpr($expr);
    }









    /**
     * Evaluate string concatenation using the ~ operator.
     *
     * Splits $expr on ~ at top level (outside quotes), resolves each part,
     * and concatenates. Returns null if no ~ operator found.
     *
     * Examples:
     *   {'INV#'~s.id}         → 'INV#' . (string)s.id
     *   {prefix~user.name}    → (string)prefix . (string)user.name
     */
    private function evaluateConcat(string $expr, array $context): ?string
    {
        return $this->evaluator()->evaluateConcat($expr, $context);
    }

    /**
     * Split an expression on ~ operators at the top level (outside quotes).
     *
     * @return list<string>
     */
    private function splitByTilde(string $expr): array
    {
        return $this->evaluator()->splitByTilde($expr);
    }

    /**
     * Remove template comments
     */
    private function removeComments(string $content): string
    {
        $content = preg_replace('/\{!--.*?--\}/s', '', $content);
        $content = preg_replace('/\{\*.*?\*\}/s', '', $content);
        $content = preg_replace('/\{#.*?#\}/s', '', $content);
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
     * The cache key includes the template's mtime so that any source change
     * naturally produces a new cache entry — no stale cache can be served.
     * Deps validation is a secondary safeguard for parent template changes.
     *
     * Returns null on miss or stale.
     */
    private function getExtendsCache(string $templatePath): ?string
    {
        // Versioned key: template path + current mtime ensures source changes
        // produce new cache entries. Old entries are cleaned up by TTL-based GC.
        $mtime = (int)@filemtime($templatePath);
        $versionedKey = $templatePath . '|' . $mtime;
        $cacheFile = $this->extendsCacheDir . '/' . md5($versionedKey) . '.cache';
        if (!file_exists($cacheFile)) {
            // Fallback: try the unversioned key (legacy cache files from before versioning)
            $legacyFile = $this->extendsCacheDir . '/' . md5($templatePath) . '.cache';
            if (file_exists($legacyFile)) {
                @unlink($legacyFile); // clean up legacy entry
            }
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

        // Versioned key: template path + mtime ensures source changes produce
        // new cache entries. Old entries cleaned by TTL-based GC.
        $mtime = (int)@filemtime($templatePath);
        $versionedKey = $templatePath . '|' . ($mtime > 0 ? $mtime : time());
        $cacheFile = $this->extendsCacheDir . '/' . md5($versionedKey) . '.cache';
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
     * Process {debug expr} — pretty-print any variable value for development.
     * Renders as a styled <pre> block with type info and formatted output.
     */
    private function processDebugTags(string $content, array $context): string
    {
        return preg_replace_callback(
            '/\{debug\s+([^}]+)\}/',
            function (array $m) use ($context): string {
                $expr = trim($m[1]);
                $value = $this->resolveValue($expr, $context);

                $type = gettype($value);
                if ($value === null) {
                    $dump = 'null';
                } elseif (is_bool($value)) {
                    $dump = $value ? 'true' : 'false';
                } elseif (is_array($value)) {
                    $dump = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif (is_object($value)) {
                    $dump = get_class($value) . "\n" . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } elseif (is_string($value) && strlen($value) > 500) {
                    $dump = substr($value, 0, 500) . '... (' . strlen($value) . ' chars)';
                } else {
                    $dump = var_export($value, true);
                }

                $safeExpr = htmlspecialchars($expr, ENT_QUOTES, 'UTF-8');
                $safeDump = htmlspecialchars($dump, ENT_QUOTES, 'UTF-8');
                $safeType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');

                return '<pre class="ikb-debug my-2 p-3 bg-gray-900 text-green-400 text-xs rounded-lg overflow-x-auto font-mono">' . "\n"
                    . '<span class="text-gray-500">debug</span> <span class="text-yellow-300">' . $safeExpr . '</span> <span class="text-gray-500">:: ' . $safeType . '</span>' . "\n"
                    . $safeDump . "\n"
                    . '</pre>';
            },
            $content
        );
    }

    // ── v4.8: User-defined macros ──────────────────────────────────

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
    private function extractMacros(string $content, bool $merge = false): string
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
    private function expandMacroCalls(string $content, array $context): string
    {
        return preg_replace_callback(
            '/\{call\s+(\w+)\s*(?:\(([^)]*)\))?\}/',
            function (array $m) use ($context): string {
                $name = $m[1];
                $argsRaw = isset($m[2]) ? trim($m[2]) : '';

                if (!isset($this->macros[$name])) {
                    $this->logError("DISYL_MACRO_NOT_FOUND: {$name}");
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
                        $resolved = $this->resolveValue($value, $context);
                        $value = is_string($resolved) ? $resolved : (string)$value;
                    }
                    $subs['{' . $pName . '}'] = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                }

                // Substitute {paramName} patterns in macro body
                $expanded = str_replace(array_keys($subs), array_values($subs), $body);

                // Recurse: the expanded body may contain variables, calls, control flow
                return $this->compile($expanded, $context);
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
                $resolved = $this->resolveValueWithFilters($part, $context);
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
                $resolved = $this->resolveValue($part, $context);
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
        $allTypes = ['for', 'foreach', 'each', 'if', 'while', 'match', 'trans', 'cache', 'experiment', 'sandbox', 'trusted', 'untrusted', 'parallel', 'await', 'suspense', 'federated_query', 'ai_generate', 'ai_query', 'ai_complete'];

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
            'while'   => $this->evaluateWhileBody($tag['expr'], $innerContent, $context),
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

            // Skip {/when}, {/default}, {/else} close tags — they delimit arms but don't render
            if ($rawTagTrimmed === '/when' || $rawTagTrimmed === '/default' || $rawTagTrimmed === '/else') {
                if ($current !== null && $tagPos > $offset) {
                    $current['content'] .= substr($content, $offset, $tagPos - $offset);
                }
                $offset = $tagEnd + 1;
                continue;
            }

            $isWhen = str_starts_with($rawTagTrimmed, 'when ') || $rawTagTrimmed === 'when';
            $isDefault = $rawTagTrimmed === 'default' || $rawTagTrimmed === 'else';

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

        // v4.8: resolve the expression from context. If it's a Promise, await it.
        // Otherwise render the body immediately (synchronous path).
        $resolved = $this->resolveValue(trim($expr), $context);

        // If resolved is a Promise, try to get its value
        if ($resolved instanceof \Ikabud\Kernel\DiSyL\Async\Promise) {
            try {
                require_once __DIR__ . '/Async/Scheduler.php';
                $sched = new \Ikabud\Kernel\DiSyL\Async\Scheduler();
                $sched->add(fn() => $resolved);
                $results = $sched->run();
                $result = $results[0] ?? ['error' => new \RuntimeException('no result')];
                return $this->renderAwaitResult($info, $result, $context);
            } catch (\Throwable $e) {
                return $this->renderAwaitResult($info, ['error' => $e], $context);
            }
        }

        // Synchronous path: render body (or {then} block) directly
        $let = $info['let'] ?? $this->extractLetIdentifier($expr) ?: 'value';
        if ($info['thenBody'] !== null) {
            $childCtx = $context;
            $childCtx[$let] = $resolved;
            return $this->compile($info['thenBody'], $childCtx);
        }

        // No then block: render body with value bound
        return $this->compile($info['body'], $context);
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
     * Parse an {await} body into success / then / loading / catch arms.
     *
     * @return array{expr:string, body:string, thenBody:?string, let:?string, loading:?string, catch:?string, catchLet:?string}
     */
    private function parseAwaitArms(string $expr, string $innerContent): array
    {
        $thenBody = null; $loading = null; $catch = null; $catchLet = null; $let = null;
        $body = $innerContent;

        // Extract {then}...{/then} block (v4.8)
        if (preg_match('/\{then\}(.*?)\{\/then\}/s', $body, $tm)) {
            $thenBody = $tm[1];
            $body = str_replace($tm[0], '', $body);
        }

        // Extract {loading}...{/loading} block (v4.8 paired syntax)
        if (preg_match('/\{loading\}(.*?)\{\/loading\}/s', $body, $lm)) {
            $loading = $lm[1];
            $body = str_replace($lm[0], '', $body);
        } elseif (preg_match('/\{loading\}/', $body)) {
            // Legacy open-token syntax: {loading}...{catch ...}
            $parts = preg_split('/\{loading\}/', $body, 2);
            $body = $parts[0];
            $rest = $parts[1] ?? '';
            if (preg_match('/\{catch(?:\s+let=(\w+))?\}/', $rest, $cm, PREG_OFFSET_CAPTURE)) {
                $loading = substr($rest, 0, (int)$cm[0][1]);
                $catchLet = is_array($cm[1] ?? null) ? ($cm[1][0] ?: null) : null;
                $catch = substr($rest, (int)$cm[0][1] + strlen($cm[0][0]));
            } else {
                $loading = $rest;
            }
        }

        // Extract {catch let=...}...{/catch} block (v4.8 paired syntax)
        if ($catch === null && preg_match('/\{catch(?:\s+let=(\w+))?\}(.*?)\{\/catch\}/s', $body, $cm)) {
            $catchLet = $cm[1] !== '' ? $cm[1] : null;
            $catch = $cm[2];
            $body = str_replace($cm[0], '', $body);
        } elseif ($catch === null && preg_match('/\{catch(?:\s+let=(\w+))?\}/', $body, $cm, PREG_OFFSET_CAPTURE)) {
            // Legacy open-token
            $catchLet = is_array($cm[1] ?? null) ? ($cm[1][0] ?: null) : null;
            $catch = substr($body, (int)$cm[0][1] + strlen($cm[0][0]));
            $body = substr($body, 0, (int)$cm[0][1]);
        }

        // Extract let= from expression
        $let = $this->extractLetIdentifier($expr) ?: 'value';

        return [
            'expr' => $expr, 'body' => trim($body), 'thenBody' => $thenBody,
            'let' => $let, 'loading' => $loading, 'catch' => $catch, 'catchLet' => $catchLet,
        ];
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
        // C-style for: {for init; condition; increment}
        if (substr_count($expr, ';') === 2) {
            $parts = explode(';', $expr);
            $initExpr = trim($parts[0]);
            $condExpr = trim($parts[1]);
            $incExpr  = trim($parts[2]);

            // Evaluate init (typically a {set} operation)
            $ctx = $context;
            $initResult = $this->processSetStatements('{' . $initExpr . '}', $ctx);
            $maxIterations = 10000;
            $count = 0;
            $result = '';
            while ($this->evaluateCondition($condExpr, $ctx)) {
                $result .= $this->compile($innerContent, $ctx);
                // Evaluate increment — set $var = $var + 1 style
                $incResult = $this->processSetStatements('{' . $incExpr . '}', $ctx);
                $count++;
                if ($count >= $maxIterations) {
                    break;
                }
            }
            return $result;
        }

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
     * Evaluate a {while condition}...{/while} body.
     * Safety-limited to 10000 iterations to prevent accidental infinite loops.
     */
    private function evaluateWhileBody(string $expr, string $innerContent, array $context): string
    {
        $maxIterations = 10000;
        $count = 0;
        $result = '';
        while ($this->evaluateCondition($expr, $context)) {
            $result .= $this->compile($innerContent, $context);
            $count++;
            if ($count >= $maxIterations) {
                if (function_exists('write_log')) {
                    write_log('DiSyL {while} loop exceeded max iterations (' . $maxIterations . ')', 'warning');
                }
                break;
            }
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
                
                // Extract the condition from {elseif cond} or {else if cond}
                preg_match('/\{else(?:\s+if|if)\s+([^}]+)\}/', $content, $m, 0, $nextPos);
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
        // Support both {elseif cond} and {else if cond}
        $a = strpos($content, '{elseif ', $pos);
        $b = strpos($content, '{else if ', $pos);
        if ($a === false) return $b;
        if ($b === false) return $a;
        return min($a, $b);
    }
    
    /**
     * Find standalone {else} at or after position.
     * Must match exactly {else} not {elseif} or {else if}.
     */
    private function findElseAt(string $content, int $pos): int|false
    {
        $offset = $pos;
        while (($found = strpos($content, '{else}', $offset)) !== false) {
            // Ensure it's not {elseif or {else if
            $after = substr($content, $found + 5, 1);
            $after2 = substr($content, $found + 5, 4);
            if ($after === ' ' && str_starts_with($after2, ' if')) {
                $offset = $found + 6;
                continue;
            }
            if ($after === 'i' && str_starts_with(substr($content, $found + 6, 1), 'f')) {
                $offset = $found + 8;
                continue;
            }
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

        // Persistent file-based eligibility cache: avoid re-scanning template
        // sources on every request. Keyed by template path + mtime of root template.
        $eligibilityCacheFile = $this->getCompiledEligibilityCachePath($templatePath);
        if ($eligibilityCacheFile !== null && is_file($eligibilityCacheFile)) {
            $cached = @json_decode((string)@file_get_contents($eligibilityCacheFile), true);
            if (is_array($cached) && isset($cached['eligible'])) {
                $this->compiledEligibilityCache[$templatePath] = (bool)$cached['eligible'];
                return (bool)$cached['eligible'];
            }
        }

        $visited = [];
        $eligible = !$this->templateGraphUsesComponentTags($templatePath, $visited);
        $this->compiledEligibilityCache[$templatePath] = $eligible;

        // Persist the result for future requests
        if ($eligibilityCacheFile !== null) {
            @file_put_contents($eligibilityCacheFile, json_encode([
                'eligible'   => $eligible,
                'checked_at' => time(),
            ]), LOCK_EX);
        }

        return $eligible;
    }

    /**
     * Get the file path for the compiled-mode eligibility cache.
     * Returns null if the extends cache directory is not writable.
     */
    private function getCompiledEligibilityCachePath(string $templatePath): ?string
    {
        if (!is_dir($this->extendsCacheDir) && !@mkdir($this->extendsCacheDir, 0755, true)) {
            return null;
        }
        $mtime = @filemtime($templatePath);
        $hash = md5($templatePath . '|' . ($mtime ?: 0) . '|v' . self::COMPILED_ELIGIBILITY_CACHE_VERSION);
        return $this->extendsCacheDir . '/elig_' . $hash . '.json';
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

        // Component tags always require interpreted path
        if (str_contains($source, '{ikb_') || str_contains($source, '{island')) {
            return true;
        }

        // User-defined macros ({macro}...{/macro} + {call ...}) are not
        // yet supported by the compiled path (TemplateCompiler). Templates
        // or layouts that use them must fall back to the interpreted engine.
        if (str_contains($source, '{macro ') || str_contains($source, '{call ')) {
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
        if (!str_contains($content, '{ikb_') && !str_contains($content, '{island') && !str_contains($content, '{state')) {
            return $content;
        }

        $maxIterations = 200;
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            // Find component tag
            if (!preg_match('/\{(ikb_\w+|island|state)[\s}]/', $content, $match, PREG_OFFSET_CAPTURE)) {
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
                
                // Compile children with nesting depth guard.
                // Config-only components (ikb_entity_view) keep children raw
                // to preserve {field} and {action} sub-tags as-is.
                $skipCompile = in_array($componentName, ['ikb_entity_view', 'state'], true);
                if ($skipCompile) {
                    $compiledChildren = $children;
                } elseif ($this->componentDepth >= self::COMPONENT_MAX_DEPTH) {
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
                    $result = app()->cap()->call($capId, $payload);
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
            '/(?<!\$)\{((?:[a-zA-Z_([\d])[^{}]*)\}/',
            function($match) use ($context, &$resolveCache) {
                $expr = trim($match[1]);

                if (!$this->isProcessableTemplateExpression($expr)) {
                    return $match[0];
                }

                // 0a. Null-coalescing: {var ?? fallback} — transforms to {var|default:fallback}
                //     Must be checked before ternary/arithmetic since ?? uses ? and :
                if (str_contains($expr, '??')) {
                    // Find the first ?? that's not inside a quoted string
                    $inQuote = null;
                    $len = strlen($expr);
                    for ($i = 0; $i < $len - 1; $i++) {
                        $c = $expr[$i];
                        if ($inQuote !== null) {
                            if ($c === '\\') { $i++; continue; }
                            if ($c === $inQuote) $inQuote = null;
                            continue;
                        }
                        if ($c === '"' || $c === "'") { $inQuote = $c; continue; }
                        if ($c === '?' && $expr[$i + 1] === '?') {
                            $left = trim(substr($expr, 0, $i));
                            $right = trim(substr($expr, $i + 2));
                            // Recurse into the left side in case of chained ??, then apply default filter
                            $transformed = '{' . $left . '|default:' . $right . '}';
                            return $this->processVariables($transformed, $context);
                        }
                    }
                }

                // 0. keyof expression: {keyof entity_type} or {keyof entity_type.view}
                //    Resolves to the field list of a registered entity view contract.
                //    Supports filters: {keyof employee_profile | json}, {keyof employee_profile | join(', ')}
                if (str_starts_with($expr, 'keyof ')) {
                    $keyofRest = substr($expr, 6); // strip 'keyof '
                    $pipePos = strpos($keyofRest, '|');
                    $keyofArgs = $pipePos !== false ? trim(substr($keyofRest, 0, $pipePos)) : trim($keyofRest);
                    $fields = $this->resolveKeyof($keyofArgs);

                    if ($pipePos !== false) {
                        // Pass through filter chain
                        $filterPart = substr($keyofRest, $pipePos + 1);
                        $value = $fields;
                        $hasRaw = false;
                        $filterNames = [];
                        $filterParts = $this->splitByPipe($filterPart);
                        foreach ($filterParts as $filter) {
                            $filter = trim($filter);
                            if ($filter === '') continue;
                            $filterName = trim(explode(':', $filter, 2)[0]);
                            if ($filterName === 'raw') { $hasRaw = true; continue; }
                            $filterNames[] = $filterName;
                            $value = $this->applyFilter($filter, $value, $context);
                        }
                        if (!is_scalar($value)) return '';
                        if (!$hasRaw && !$this->hasEscapeFilter($expr, $filterNames)) {
                            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                        }
                        return (string) $value;
                    }

                    // No filters: return JSON array (not htmlspecialchars — JSON
                    // from keyof is a controlled list of identifiers, never user
                    // content, and json_encode already escapes internal quotes)
                    return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

                // 1.5. String concatenation with ~ operator: {a ~ b}, {'INV#'~s.id}
                //     Precedence: ~ binds at the same level as +/-, so must be checked
                //     before filter-less arithmetic to catch filters in operands.
                //     Works both bare and with filters: {prefix~name | upper}
                if (str_contains($expr, '~') && !preg_match('/^["\'].*["\']$/', $expr)) {
                    $pipePos = strpos($expr, '|');
                    if ($pipePos === false) {
                        // No filters: evaluate concat directly
                        $result = $this->evaluateConcat($expr, $context);
                        if ($result !== null) {
                            return htmlspecialchars($result, ENT_QUOTES, 'UTF-8');
                        }
                    } else {
                        // Concat with filters: resolve concat first, then pipe through filter chain
                        $concatPart = trim(substr($expr, 0, $pipePos));
                        if (str_contains($concatPart, '~')) {
                            $concatResult = $this->evaluateConcat($concatPart, $context);
                            if ($concatResult !== null) {
                                $filterPart = substr($expr, $pipePos + 1);
                                $value = $concatResult;
                                $hasRaw = false;
                                $filterNames = [];
                                $filterParts = $this->splitByPipe($filterPart);
                                foreach ($filterParts as $filter) {
                                    $filter = trim($filter);
                                    if ($filter === '') continue;
                                    $filterName = trim(explode(':', $filter, 2)[0]);
                                    if ($filterName === 'raw') { $hasRaw = true; continue; }
                                    $filterNames[] = $filterName;
                                    $value = $this->applyFilter($filter, $value, $context);
                                }
                                if (!is_scalar($value)) return '';
                                if (!$hasRaw && !$this->hasEscapeFilter($expr, $filterNames)) {
                                    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                                }
                                return (string) $value;
                            }
                        }
                    }
                }

                // 2. Arithmetic/expression: contains operators or parentheses.
                //    Supports bare {a + b}, parenthesized {(a + b) * c}, and
                //    arithmetic with filters: {a + b | number_format:2}.
                if (strpbrk($expr, '+-*/%()') !== false) {
                    $pipePos = strpos($expr, '|');
                    if ($pipePos === false) {
                        // No filters: evaluate and return directly
                        $result = $this->evaluateArithmetic($expr, $context);
                        if ($result !== null) {
                            return htmlspecialchars((string) $result, ENT_QUOTES, 'UTF-8');
                        }
                    } else {
                        // Arithmetic with filters: evaluate left side, pipe through filter chain
                        $arithPart = trim(substr($expr, 0, $pipePos));
                        if (strpbrk($arithPart, '+-*/%()') !== false) {
                            $arithResult = $this->evaluateArithmetic($arithPart, $context);
                            if ($arithResult !== null) {
                                $filterPart = substr($expr, $pipePos + 1);
                                $value = $arithResult;
                                $hasRaw = false;
                                $filterNames = [];
                                $filterParts = $this->splitByPipe($filterPart);
                                foreach ($filterParts as $filter) {
                                    $filter = trim($filter);
                                    if ($filter === '') continue;
                                    $filterName = trim(explode(':', $filter, 2)[0]);
                                    if ($filterName === 'raw') { $hasRaw = true; continue; }
                                    $filterNames[] = $filterName;
                                    $value = $this->applyFilter($filter, $value, $context);
                                }
                                if (!is_scalar($value)) return '';
                                if (!$hasRaw && !$this->hasEscapeFilter($expr, $filterNames)) {
                                    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                                }
                                return (string) $value;
                            }
                        }
                    }
                    // Not a valid arithmetic expression — fall through to variable resolution
                }

                // 3. Simple variable (no filters)
                if (!str_contains($expr, '|')) {
                    if (!array_key_exists($expr, $resolveCache)) {
                        $resolveCache[$expr] = $this->resolveValue($expr, $context);
                    }
                    $value = $resolveCache[$expr];

                    // Strict mode: warn when a variable resolves to null (undefined in context),
                    // but skip the warning if the variable root is declared via {@var}.
                    $varRoot = strtok($expr, '.');
                    if ($this->strictMode && $value === null && ($varRoot === false || !array_key_exists($varRoot, $this->declaredVars))) {
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

                // Strict mode: warn when filtered variable is undefined,
                // but skip if the variable root is declared via {@var}.
                $filteredVarRoot = strtok($varPath, '.');
                if ($this->strictMode && $value === null && ($filteredVarRoot === false || !array_key_exists($filteredVarRoot, $this->declaredVars))) {
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

        // v4.8: {call ...} and {macro ...} are control structures, not variables
        if (str_starts_with($expr, 'call ') || str_starts_with($expr, 'macro ')) {
            return false;
        }

        // keyof expression
        if (str_starts_with($expr, 'keyof ')) {
            return true;
        }

        // Null-coalescing: {var ?? fallback}
        if (str_contains($expr, '??')) {
            return true;
        }

        if (str_contains($expr, '?') && str_contains($expr, ':')) {
            return true;
        }

        // Arithmetic expression: accept with or without filters
        if (strpbrk($expr, '+-*/%()') !== false) {
            return true;
        }

        // String concatenation with ~ operator
        if (str_contains($expr, '~') && !preg_match('/^["\'].*["\']$/', $expr)) {
            return true;
        }

        // Array literal: [val1, val2, ...]
        if ($expr[0] === '[') {
            return true;
        }

        // Postfix ++/--
        if (str_ends_with($expr, '++') || str_ends_with($expr, '--')) {
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
        return $this->evaluator()->evaluateTernary($expr, $context);
    }
    
    /**
     * Find a character in a string that is not inside quotes.
     */
    private function findUnquotedChar(string $str, string $char): int|false
    {
        return $this->evaluator()->findUnquotedChar($str, $char);
    }
    
    /**
     * Check if expression already has an escape filter applied.
     * Accepts the already-parsed filter name list to avoid false positives
     * from substring matches (e.g. a variable named "my_esc_html_thing").
     */
    private function hasEscapeFilter(string $expr, array $parsedFilterNames = []): bool
    {
        return $this->evaluator()->hasEscapeFilter($expr, $parsedFilterNames);
    }
    
    /**
     * Resolve a dotted path to a value
     */
    private function resolveValue(string $path, array $context)
    {
        return $this->evaluator()->resolveValue($path, $context);
    }

    /**
     * Split comma-separated function call arguments, respecting nested
     * parentheses, brackets, and quoted strings.
     */
    private function splitCallArgs(string $str): array
    {
        return $this->evaluator()->splitCallArgs($str);
    }

    /**
     * Resolve a keyof expression to an array of field names.
     *
     * Parses "entity_type" or "entity_type.view" and looks up the registered
     * view contract from EntityViewResolver. Returns the field list, or an
     * empty array if the entity/view is not found.
     *
     * @param string $expr "entity_type" or "entity_type.view"
     * @return list<string>
     */
    private function resolveKeyof(string $expr): array
    {
        return $this->evaluator()->resolveKeyof($expr);
    }

    /** Maximum number of filters allowed in a single filter chain */
    private const FILTER_CHAIN_MAX = 20;

    /**
     * Resolve value with filters applied
     */
    private function resolveValueWithFilters(string $expr, array $context)
    {
        return $this->evaluator()->resolveValueWithFilters($expr, $context);
    }

    /**
     * Evaluate a condition expression to a boolean.
     * 
     * Supports: negation (!), AND/OR, comparison operators (==, !=, >, <, >=, <=, ===, !==),
     * arithmetic operands (page + 1 > total), quoted strings, variable paths, and truthy checks.
     */
    private function evaluateCondition(string $condition, array $context): bool
    {
        return $this->evaluator()->evaluateCondition($condition, $context);
    }
    
    /**
     * Resolve one side of a condition comparison.
     * Handles: quoted strings, parenthesized filter expressions, arithmetic, variables with filters, numeric literals.
     */
    private function resolveConditionOperand(string $raw, array $context)
    {
        return $this->evaluator()->resolveConditionOperand($raw, $context);
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
        return $this->evaluator()->splitByPipe($expr);
    }
    
    /**
     * Split by comma, respecting quotes
     */
    private function splitByComma(string $expr): array
    {
        return $this->evaluator()->splitByComma($expr);
    }
    
    /**
     * Generic split by character, respecting quotes
     */
    private function splitByChar(string $expr, string $delimiter): array
    {
        return $this->evaluator()->splitByChar($expr, $delimiter);
    }
    
    /**
     * Apply a filter
     */
    private function normalizeFilterArg(string $filterName, string $arg, array $context)
    {
        return $this->evaluator()->normalizeFilterArg($filterName, $arg, $context);
    }

    private function applyFilter(string $filter, $value, array $context)
    {
        return $this->evaluator()->applyFilter($filter, $value, $context);
    }
    
    /**
     * Log an error
     */
    private function logError(string $message): void
    {
        // v4.8: always tag errors with template path + expression context
        $ctx = '';
        if ($this->currentTemplatePath !== null) {
            $ctx .= ' in ' . $this->currentTemplatePath;
        }
        if ($this->currentExpression !== null) {
            $ctx .= ' near {' . $this->currentExpression . '}';
        }
        $fullMessage = $message . $ctx;

        $this->errors[] = $fullMessage;
        if ($this->debug) {
            error_log("[DiSyL] {$fullMessage}");
        }
        // Also emit to app log when strict mode is on (v4.8+)
        if ($this->strictMode && function_exists('write_log')) {
            \write_log('disyl.strict.' . strtok($message, ':'), 'warning', [
                'template' => $this->currentTemplatePath,
                'expression' => $this->currentExpression,
                'message' => $message,
            ]);
        }
    }
    
    public function registerFilter(string $name, callable $callback): void
    {
        $this->filters[$name] = $callback;
        if ($this->evaluator !== null) {
            $this->evaluator->setFilters($this->filters);
        }
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
            'truncate' => fn($v, $a, $n) => mb_strlen((string)$v) > (int)(($n['length'] ?? $a[0]) ?? 100) 
                ? mb_substr((string)$v, 0, (int)(($n['length'] ?? $a[0]) ?? 100)) . '...' 
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
            'date' => fn($v, $a, $n) => $v ? date(($n['format'] ?? $a[0]) ?? 'Y-m-d', is_numeric($v) ? (int)$v : strtotime((string)$v)) : '',
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
        // Guard against empty template names (resolve to .disyl otherwise)
        $template = trim($template);
        if ($template === '' || $template === '.disyl') {
            return '';
        }

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
            'ikb_component' => $this->renderIkbComponent($attrs, $children, $context),
            'ikb_entity_view' => $this->renderEntityViewConfig($attrs, $children, $context),
            'state' => $this->renderStateDeclaration($attrs, $children, $context),
            'ikb_entity_list' => $this->renderEntityListViaService($attrs, $children, $context),
            'ikb_entity_detail' => $this->renderEntityDetailViaService($attrs, $children, $context),
            'ikb_export_button' => $this->renderExportButton($attrs, $children),
            'ikb_form' => $this->renderForm($attrs, $children, $context),
            'ikb_stat_card' => $this->renderStatCard($attrs, $children),
            'ikb_timeline' => $this->renderTimeline($attrs, $children),
            'ikb_confirm_action' => $this->renderConfirmAction($attrs, $children),
            'ikb_panel' => $this->renderPanel($attrs, $children),
            'ikb_drawer' => $this->renderDrawer($attrs, $children),
            'ikb_audit_log' => $this->renderAuditLog($attrs, $children, $context),
            'ikb_ai_summary' => $this->renderAiSummary($attrs, $children, $context),
            'ikb_ai_assist' => $this->renderAiAssist($attrs, $children, $context),
            'ikb_report' => $this->renderReport($attrs, $children, $context),
            'ikb_signature_block' => $this->renderSignatureBlock($attrs, $children),
            'island' => $this->renderIsland($attrs, $children),
            default => $this->renderUnknownComponent($component, $children),
        };
    }

    /** @var array<string>|null Lazily-built list of known governed component names for typo suggestions */
    private static ?array $knownGovernedComponents = null;

    /**
     * Get the list of known governed component names (for typo suggestions).
     */
    private function getKnownGovernedComponents(): array
    {
        if (self::$knownGovernedComponents !== null) {
            return self::$knownGovernedComponents;
        }

        // Built-in governed components from the renderComponent match block
        $governed = [
            'ikb_section',
            'ikb_container',
            'ikb_grid',
            'ikb_card',
            'ikb_text',
            'ikb_button',
            'ikb_badge',
            'ikb_input',
            'ikb_textarea',
            'ikb_select',
            'ikb_icon',
            'ikb_image',
            'ikb_link',
            'ikb_table',
            'ikb_modal',
            'ikb_alert',
            'ikb_spinner',
            'ikb_component',
            'ikb_entity_view',
            'ikb_entity_list',
            'ikb_entity_detail',
            'ikb_export_button',
            'ikb_form',
            'ikb_stat_card',
            'ikb_timeline',
            'ikb_confirm_action',
            'ikb_panel',
            'ikb_drawer',
            'ikb_audit_log',
            'ikb_ai_summary',
            'ikb_ai_assist',
            'ikb_report',
            'ikb_signature_block',
        ];

        // Add any custom registered components
        foreach (array_keys($this->components) as $custom) {
            if (!in_array($custom, $governed, true)) {
                $governed[] = $custom;
            }
        }

        self::$knownGovernedComponents = $governed;
        return $governed;
    }

    /**
     * Find the closest matching known component name by Levenshtein distance.
     *
     * @param string $input The unknown component name
     * @param array<string> $candidates List of known component names
     * @return string|null The closest match, or null if distance is too large
     */
    private function findClosestComponent(string $input, array $candidates): ?string
    {
        $best = null;
        $bestDist = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $dist = levenshtein($input, $candidate);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $candidate;
            }
        }

        // Only suggest if the distance is within reasonable threshold
        $threshold = max(3, (int)(strlen($input) * 0.4));
        if ($bestDist > 0 && $bestDist <= $threshold) {
            return $best;
        }

        return null;
    }

    /**
     * Handle unknown/unregistered component names.
     * Logs a warning, suggests the closest known component, and returns a visible
     * HTML comment so developers catch typos.
     */
    private function renderUnknownComponent(string $component, string $children): string
    {
        if (str_starts_with($component, 'ikb_') || $component === 'state' || $component === 'island') {
            $suggestion = $this->findClosestComponent($component, $this->getKnownGovernedComponents());
            $msg = "Unknown component '{$component}' — not registered.";
            if ($suggestion !== null) {
                $msg .= " Did you mean '{$suggestion}'?";
            } else {
                $msg .= " Check for typos. If using a custom component, register it via ComponentRegistry::register().";
            }
            $this->logError($msg);
            return "<!-- Unknown DiSyL component: {$component} -->";
        }
        return $children;
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
        $bind = $attrs['bind'] ?? '';
        
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
        
        $classAttr = "{$sizeClass} {$weightClass} {$colorClass} {$alignClass} {$class}";
        
        // If bind attribute is set, emit framework-neutral binding via current bridge
        if ($bind !== '') {
            $bridge = BridgeManager::resolve($attrs['bridge'] ?? 'alpine');
            $bindAttr = $bridge->renderBind($bind);
            $classAttr = trim($classAttr) !== '' ? " class=\"" . trim($classAttr) . "\"" : '';
            return "<{$tag}{$bindAttr}{$classAttr}>{$children}</{$tag}>";
        }
        
        return "<{$tag} class=\"{$classAttr}\">{$children}</{$tag}>";
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
        $model = $attrs['model'] ?? '';
        $htmx = $this->buildHtmxAttrs($attrs);
        
        // If model attribute is set, emit framework-neutral binding via current bridge
        if ($model !== '') {
            $bridge = BridgeManager::resolve($attrs['bridge'] ?? 'alpine');
            $modelAttr = $bridge->renderModel($model);
            return "<input type=\"{$type}\" id=\"{$id}\" name=\"{$name}\" value=\"{$value}\" placeholder=\"{$placeholder}\" class=\"w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {$class}\"{$required}{$disabled}{$modelAttr}{$htmx}>";
        }
        
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


    /**
     * Render an export button — generates downloadable exports from entity data.
     *
     * Attributes:
     *   source   — entity type (e.g. "orders", "cases", "ledger")
     *   format   — export format: pdf, docx, csv, xlsx (default: csv)
     *   label    — button label (default: "Export {format}")
     *   variant  — button variant: primary, outline, secondary (default: outline)
     *   size     — button size: small, medium, large (default: medium)
     *   class    — additional CSS classes
     */
    private function renderExportButton(array $attrs, string $children): string
    {
        $source = (string)($attrs['source'] ?? '');
        $format = strtolower((string)($attrs['format'] ?? 'csv'));
        $label = (string)($attrs['label'] ?? '');
        $variant = (string)($attrs['variant'] ?? 'outline');
        $size = (string)($attrs['size'] ?? 'medium');
        $class = (string)($attrs['class'] ?? '');

        if ($source === '') {
            // No source — render a disabled placeholder
            $safeLabel = htmlspecialchars($label ?: 'Export', ENT_QUOTES, 'UTF-8');
            return "<button type=\"button\" class=\"ikb-export-btn opacity-50 cursor-not-allowed inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 text-gray-500 {$class}\" disabled>"
                . '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>'
                . "{$safeLabel}</button>";
        }

        $safeFormat = htmlspecialchars($format, ENT_QUOTES, 'UTF-8');
        $safeSource = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');

        if ($label === '') {
            $label = 'Export ' . strtoupper($format);
        }
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        $variantClass = match ($variant) {
            'primary' => 'bg-indigo-600 text-white hover:bg-indigo-700 border-indigo-600',
            'secondary' => 'bg-gray-200 text-gray-800 hover:bg-gray-300 border-gray-200',
            default => 'border border-gray-300 text-gray-700 hover:bg-gray-50',
        };

        $sizeClass = match ($size) {
            'small' => 'px-3 py-1.5 text-xs',
            'large' => 'px-6 py-3 text-base',
            default => 'px-4 py-2 text-sm',
        };

        $icon = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>';

        // Export URL: /api/v1/export?source={source}&format={format}
        $exportUrl = htmlspecialchars("/api/v1/export?source={$safeSource}&format={$safeFormat}", ENT_QUOTES, 'UTF-8');

        return "<a href=\"{$exportUrl}\" "
            . "class=\"ikb-export-btn inline-flex items-center gap-2 rounded-lg font-medium transition {$variantClass} {$sizeClass} {$class}\" "
            . "data-export-source=\"{$safeSource}\" data-export-format=\"{$safeFormat}\" "
            . "download>"
            . "{$icon}{$safeLabel}</a>";
    }

    /**
     * Render a governed form component.
     *
     * Attributes:
     *   action   — capability action ID (e.g. "ticket.create", "order.submit")
     *   method   — POST (default) or GET
     *   layout   — form layout: stacked, inline, guided (default: stacked)
     *   csrf     — include CSRF token (default: true)
     *   class    — additional CSS classes
     *   id       — form element id
     *   hx-*     — HTMX attributes pass through
     */
    private function renderForm(array $attrs, string $children, array $context): string
    {
        $action = (string)($attrs['action'] ?? '');
        $method = strtoupper((string)($attrs['method'] ?? 'post'));
        $layout = (string)($attrs['layout'] ?? 'stacked');
        $includeCsrf = !isset($attrs['csrf']) || $attrs['csrf'];
        $class = (string)($attrs['class'] ?? '');
        $id = isset($attrs['id']) ? ' id="' . htmlspecialchars((string)$attrs['id'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $htmx = $this->buildHtmxAttrs($attrs);

        if ($method !== 'GET' && $method !== 'POST') {
            $method = 'POST';
        }

        // Build form action URL from capability action
        $formAction = '';
        if ($action !== '') {
            $safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
            // Route to the capability handler endpoint
            $formAction = htmlspecialchars("/api/v1/capability/{$safeAction}", ENT_QUOTES, 'UTF-8');
        } else {
            $formAction = '#';
        }

        $layoutClass = match ($layout) {
            'inline' => 'ikb-form--inline flex flex-wrap items-end gap-4',
            'guided' => 'ikb-form--guided space-y-6',
            default => 'ikb-form--stacked space-y-4',
        };

        $csrfHtml = '';
        if ($includeCsrf && $method === 'POST') {
            // Try to inject CSRF token from context or app
            $token = '';
            if (isset($context['csrf_token'])) {
                $token = (string)$context['csrf_token'];
            } elseif (\function_exists('app') && ($a = \app()) !== null && method_exists($a, 'csrfToken')) {
                $token = (string)$a->csrfToken();
            }
            if ($token !== '') {
                $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
                $csrfHtml = "<input type=\"hidden\" name=\"_token\" value=\"{$safeToken}\">";
            }
        }

        $methodOverride = '';
        if ($method === 'GET') {
            $methodOverride = '';
        }

        return <<<HTML
        <form{$id} method="{$method}" action="{$formAction}" class="ikb-form {$layoutClass} {$class}"{$htmx}>
            {$csrfHtml}
            {$methodOverride}
            {$children}
        </form>
        HTML;
    }

    /**
     * Render a stat card — single metric with label, value, and optional trend.
     *
     * Attributes:
     *   label    — stat label (e.g. "Total Orders")
     *   value    — stat value (e.g. "1,234")
     *   trend    — direction: up, down, neutral (default: none)
     *   trend_value — percentage or absolute change (e.g. "+12%")
     *   icon     — FontAwesome icon name (e.g. "shopping-cart")
     *   variant  — card variant: elevated, outlined, flat (default: elevated)
     *   class    — additional CSS classes
     */
    private function renderStatCard(array $attrs, string $children): string
    {
        $label = htmlspecialchars((string)($attrs['label'] ?? 'Stat'), ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars((string)($attrs['value'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $trend = (string)($attrs['trend'] ?? '');
        $trendValue = htmlspecialchars((string)($attrs['trend_value'] ?? ''), ENT_QUOTES, 'UTF-8');
        $icon = (string)($attrs['icon'] ?? '');
        $variant = (string)($attrs['variant'] ?? 'elevated');
        $class = (string)($attrs['class'] ?? '');

        $variantClass = match ($variant) {
            'outlined' => 'bg-white border border-gray-200',
            'flat' => 'bg-gray-50',
            default => 'bg-white shadow-sm border border-gray-100',
        };

        $trendHtml = '';
        if ($trend !== '' && $trendValue !== '') {
            $trendColors = match ($trend) {
                'up' => 'text-green-600',
                'down' => 'text-red-600',
                default => 'text-gray-500',
            };
            $trendIcon = match ($trend) {
                'up' => 'fa-arrow-up',
                'down' => 'fa-arrow-down',
                default => 'fa-minus',
            };
            $trendHtml = <<<TREND
            <div class="ikb-stat-trend flex items-center gap-1 mt-1 text-xs font-medium {$trendColors}">
                <i class="fas {$trendIcon}"></i>
                <span>{$trendValue}</span>
            </div>
            TREND;
        }

        $iconHtml = '';
        if ($icon !== '') {
            $iconHtml = "<div class=\"ikb-stat-icon w-10 h-10 rounded-lg bg-indigo-50 flex items-center justify-center flex-shrink-0\"><i class=\"fas fa-{$icon} text-indigo-600\"></i></div>";
        }

        $slotHtml = trim($children) !== '' ? "<div class=\"mt-2\">{$children}</div>" : '';

        return <<<HTML
        <div class="ikb-stat-card rounded-xl {$variantClass} p-5 {$class}">
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <p class="ikb-stat-label text-xs font-semibold text-gray-500 uppercase tracking-wider">{$label}</p>
                    <p class="ikb-stat-value text-2xl font-bold text-gray-900 mt-1">{$value}</p>
                    {$trendHtml}
                </div>
                {$iconHtml}
            </div>
            {$slotHtml}
        </div>
        HTML;
    }

    /**
     * Render a timeline component — chronological list of events.
     *
     * Attributes:
     *   source   — entity source for data (optional; supports child nodes as items)
     *   class    — additional CSS classes
     *
     * Children: {ikb_timeline_item} elements or plain divs
     */
    private function renderTimeline(array $attrs, string $children): string
    {
        $class = (string)($attrs['class'] ?? '');

        // Process ikb_timeline_item children if present
        $processedChildren = $children;

        return <<<HTML
        <div class="ikb-timeline relative pl-6 space-y-6 before:absolute before:left-[11px] before:top-1 before:bottom-1 before:w-0.5 before:bg-gray-200 {$class}">
            {$processedChildren}
        </div>
        HTML;
    }

    /**
     * Render a confirm action wrapper — wraps destructive actions with a confirmation step.
     *
     * Attributes:
     *   message  — confirmation message (default: "Are you sure?")
     *   confirm  — confirm button label (default: "Confirm")
     *   cancel   — cancel button label (default: "Cancel")
     *   variant  — danger (red), warning (yellow), default (indigo)
     *   class    — additional CSS classes
     *
     * Children: the action button(s) that trigger the confirmation
     */
    private function renderConfirmAction(array $attrs, string $children): string
    {
        $message = htmlspecialchars((string)($attrs['message'] ?? 'Are you sure?'), ENT_QUOTES, 'UTF-8');
        $confirmLabel = htmlspecialchars((string)($attrs['confirm'] ?? 'Confirm'), ENT_QUOTES, 'UTF-8');
        $cancelLabel = htmlspecialchars((string)($attrs['cancel'] ?? 'Cancel'), ENT_QUOTES, 'UTF-8');
        $variant = (string)($attrs['variant'] ?? 'danger');
        $class = (string)($attrs['class'] ?? '');

        $confirmClass = match ($variant) {
            'warning' => 'bg-yellow-500 hover:bg-yellow-600 text-white',
            'danger' => 'bg-red-600 hover:bg-red-700 text-white',
            default => 'bg-indigo-600 hover:bg-indigo-700 text-white',
        };

        $uid = 'ikb-confirm-' . bin2hex(random_bytes(4));

        return <<<HTML
        <div class="ikb-confirm-action inline-block {$class}" x-data="{ open: false }" @keydown.escape.window="open = false">
            <div @click="open = true" class="cursor-pointer inline-block">
                {$children}
            </div>
            <template x-teleport="body">
                <div x-show="open" class="fixed inset-0 z-[9999] flex items-center justify-center" x-transition.opacity>
                    <div class="fixed inset-0 bg-black/40" @click="open = false"></div>
                    <div class="relative bg-white rounded-xl shadow-2xl max-w-sm w-full mx-4 p-6" @click.stop>
                        <p class="text-sm text-gray-700 mb-4">{$message}</p>
                        <div class="flex gap-3 justify-end">
                            <button type="button" @click="open = false"
                                class="px-4 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                                {$cancelLabel}
                            </button>
                            <button type="button" @click="\$el.closest('.ikb-confirm-action').querySelector('[data-confirm-target]')?.click(); open = false"
                                class="px-4 py-2 text-sm font-medium rounded-lg {$confirmClass} transition">
                                {$confirmLabel}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
        HTML;
    }

    /**
     * Render a semantic panel — theme-aware container with design tokens.
     *
     * Attributes:
     *   tone     — surface, muted, elevated, primary (default: surface)
     *   spacing  — none, sm, md, lg, xl (default: md)
     *   radius   — none, sm, md, lg, full (default: md)
     *   class    — additional CSS classes
     *
     * The theme controls how these tokens render via CSS custom properties.
     */
    private function renderPanel(array $attrs, string $children): string
    {
        $tone = (string)($attrs['tone'] ?? 'surface');
        $spacing = (string)($attrs['spacing'] ?? 'md');
        $radius = (string)($attrs['radius'] ?? 'md');
        $class = (string)($attrs['class'] ?? '');

        $toneClass = match ($tone) {
            'surface' => 'bg-white border border-gray-100',
            'muted' => 'bg-gray-50 border border-gray-100',
            'elevated' => 'bg-white shadow-md border border-gray-100',
            'primary' => 'bg-indigo-600 text-white',
            default => 'bg-white border border-gray-100',
        };

        $spacingClass = match ($spacing) {
            'none' => 'p-0', 'sm' => 'p-3', 'md' => 'p-5', 'lg' => 'p-8', 'xl' => 'p-12',
            default => 'p-5',
        };

        $radiusClass = match ($radius) {
            'none' => 'rounded-none', 'sm' => 'rounded-md', 'md' => 'rounded-xl', 'lg' => 'rounded-2xl', 'full' => 'rounded-full',
            default => 'rounded-xl',
        };

        return "<div class=\"ikb-panel {$toneClass} {$spacingClass} {$radiusClass} {$class}\">{$children}</div>";
    }

    /**
     * Render a slide-out drawer panel.
     *
     * Attributes:
     *   id       — drawer ID (required)
     *   position — left or right (default: right)
     *   title    — drawer header title
     *   open     — initially open (default: false)
     *   width    — CSS width (default: 320px)
     *   class    — additional CSS classes
     */
    private function renderDrawer(array $attrs, string $children): string
    {
        $id = htmlspecialchars((string)($attrs['id'] ?? 'drawer'), ENT_QUOTES, 'UTF-8');
        $position = (string)($attrs['position'] ?? 'right');
        $title = htmlspecialchars((string)($attrs['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $open = !empty($attrs['open']);
        $width = (string)($attrs['width'] ?? '320px');
        $class = (string)($attrs['class'] ?? '');

        $translateFrom = $position === 'left' ? '-translate-x-full' : 'translate-x-full';
        $translateTo = $position === 'left' ? 'translate-x-0' : 'translate-x-0';
        $positionClass = $position === 'left' ? 'left-0' : 'right-0';
        $initOpen = $open ? 'true' : 'false';

        $titleHtml = '';
        if ($title !== '') {
            $titleHtml = <<<TITLE
            <div class="ikb-drawer-header flex items-center justify-between px-4 py-3 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">{$title}</h3>
                <button type="button" @click="open = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            TITLE;
        }

        return <<<HTML
        <div class="ikb-drawer {$class}" x-data="{ open: {$initOpen} }" @keydown.escape.window="open = false">
            <template x-teleport="body">
                <div>
                    <div x-show="open" class="fixed inset-0 z-[9998] bg-black/40 transition-opacity" @click="open = false" x-transition.opacity></div>
                    <div x-show="open" class="fixed {$positionClass} top-0 h-full z-[9999] bg-white shadow-2xl overflow-y-auto transition-transform"
                         :class="open ? '{$translateTo}' : '{$translateFrom}'"
                         style="width: {$width}; max-width: 100vw;">
                        {$titleHtml}
                        <div class="ikb-drawer-body p-4">
                            {$children}
                        </div>
                    </div>
                </div>
            </template>
        </div>
        HTML;
    }

    /**
     * Render an audit log viewer — governed display of audit trail entries.
     *
     * Attributes:
     *   source   — entity type whose audit entries to display
     *   entity_id — specific entity ID (optional; omit for all audit entries of type)
     *   limit    — max entries (default: 20)
     *   class    — additional CSS classes
     */
    private function renderAuditLog(array $attrs, string $children, array $context): string
    {
        $source = (string)($attrs['source'] ?? '');
        $entityId = (string)($attrs['entity_id'] ?? '');
        $limit = (int)($attrs['limit'] ?? 20);
        $class = (string)($attrs['class'] ?? '');

        // Resolve audit data via the capability bus
        $rows = [];
        $error = null;

        if ($source !== '') {
            try {
                if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'capabilities')) {
                    $result = $app->cap()->call('kernel.audit.list@1', [
                        'entity_type' => $source,
                        'entity_id' => $entityId !== '' ? $entityId : null,
                        'limit' => $limit,
                    ]);
                    if (is_array($result)) {
                        $rows = $result['rows'] ?? $result;
                    }
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        if ($error !== null || ($source !== '' && empty($rows))) {
            $msg = $error ?: 'No audit entries found.';
            return $this->entityErrorState($msg, $class);
        }

        if (empty($rows)) {
            return <<<HTML
            <div class="ikb-audit-log--empty text-center py-6 text-sm text-gray-500 {$class}">
                <p>No audit entries to display.</p>
            </div>
            HTML;
        }

        $entries = '';
        foreach ($rows as $entry) {
            $timestamp = htmlspecialchars((string)($entry['created_at'] ?? $entry['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8');
            $actor = htmlspecialchars((string)($entry['actor'] ?? $entry['user'] ?? 'System'), ENT_QUOTES, 'UTF-8');
            $action = htmlspecialchars((string)($entry['action'] ?? 'modified'), ENT_QUOTES, 'UTF-8');
            $detail = htmlspecialchars((string)($entry['detail'] ?? $entry['summary'] ?? ''), ENT_QUOTES, 'UTF-8');

            $actionBadge = match (strtolower($action)) {
                'created' => 'bg-green-100 text-green-800',
                'updated', 'modified' => 'bg-blue-100 text-blue-800',
                'deleted', 'removed' => 'bg-red-100 text-red-800',
                'login', 'authenticated' => 'bg-purple-100 text-purple-800',
                default => 'bg-gray-100 text-gray-800',
            };

            $entries .= <<<ENTRY
            <div class="ikb-audit-entry flex items-start gap-4 px-4 py-3 hover:bg-gray-50 transition">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-500">
                    {$actor[0]}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-medium text-gray-900">{$actor}</span>
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full {$actionBadge}">{$action}</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">{$detail}</p>
                </div>
                <time class="flex-shrink-0 text-xs text-gray-400 whitespace-nowrap">{$timestamp}</time>
            </div>
            ENTRY;
        }

        return <<<HTML
        <div class="ikb-audit-log divide-y divide-gray-100 border border-gray-200 rounded-xl overflow-hidden {$class}">
            {$entries}
        </div>
        HTML;
    }

    /**
     * Render an AI-summarized block — governed AI content generation.
     *
     * Attributes:
     *   capability — capability ID that defines what data to summarize (e.g. "ledger.daily.summarize")
     *   source     — entity source to fetch data from
     *   review     — "required" (default) or "none" — if required, output is marked as draft
     *   model      — AI model ID (default: gpt-4o-mini)
     *   max_tokens — max output tokens (default: 256)
     *   class      — additional CSS classes
     *
     * The AI Policy governs: kill switch, model allowlist, cost ceiling, token cap.
     */
    private function renderAiSummary(array $attrs, string $children, array $context): string
    {
        $capability = (string)($attrs['capability'] ?? '');
        $source = (string)($attrs['source'] ?? '');
        $review = (string)($attrs['review'] ?? 'required');
        $model = (string)($attrs['model'] ?? 'gpt-4o-mini');
        $maxTokens = (int)($attrs['max_tokens'] ?? 256);
        $class = (string)($attrs['class'] ?? '');

        // Policy gate
        $policy = class_exists('Ikabud\\Kernel\\DiSyL\\AI\\Policy') ? new \Ikabud\Kernel\DiSyL\AI\Policy() : null;
        if ($policy !== null && $policy->isKilled()) {
            return $this->entityErrorState('AI features are disabled.', $class);
        }

        if ($policy !== null && !$policy->allowsModel($model)) {
            return $this->entityErrorState('AI model not permitted by policy.', $class);
        }

        if ($policy !== null && !$policy->canAfford($model, $maxTokens)) {
            return $this->entityErrorState('AI cost ceiling exceeded.', $class);
        }

        // Fetch source data via capability bus if source provided
        $sourceData = '';
        if ($source !== '' && \function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
            try {
                $resolved = $app->entityViews()->resolve($source, 'compact', ['limit' => 10]);
                if (!empty($resolved['rows'])) {
                    $sourceData = json_encode($resolved['rows'], JSON_UNESCAPED_SLASHES);
                }
            } catch (\Throwable $e) {
                // Continue without source data
            }
        }

        // Build the prompt
        $prompt = "Summarize the following data concisely. Be factual and brief.";
        if ($sourceData !== '') {
            $prompt .= "\n\nData:\n" . $sourceData;
        }
        if (trim($children) !== '') {
            // User-defined slot template provides additional instructions
            $prompt .= "\n\nContext: " . strip_tags($children);
        }

        // Call AI provider
        $resultText = '';
        $isDraft = $review === 'required';
        $error = null;

        try {
            $provider = $this->aiProvider ?? null;
            if ($provider === null) {
                // Use echo provider as fallback (returns deterministic placeholder)
                $provider = new \Ikabud\Kernel\DiSyL\AI\EchoAiProvider();
            }

            $response = $provider->complete([
                'model' => $model,
                'prompt' => $prompt,
                'max_tokens' => $policy !== null ? $policy->capMaxTokens($maxTokens) : $maxTokens,
            ]);

            if ($policy !== null) {
                $policy->recordUsage($model, $response['output_tokens'] ?? 0);
            }
            $resultText = $response['text'] ?? '';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if (\function_exists('write_log')) {
                \write_log("ikb_ai_summary: AI call failed", 'warning', [
                    'capability' => $capability,
                    'model' => $model,
                    'error' => $error,
                ]);
            }
        }

        if ($error !== null) {
            return $this->entityErrorState('AI summary unavailable: ' . $error, $class);
        }

        $safeText = htmlspecialchars($resultText, ENT_QUOTES, 'UTF-8');
        $draftBadge = '';
        if ($isDraft) {
            $draftBadge = '<span class="ikb-ai-draft-badge inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800 ml-2">Draft — requires review</span>';
        }

        return <<<HTML
        <div class="ikb-ai-summary rounded-xl border border-indigo-200 bg-indigo-50/30 p-5 {$class}">
            <div class="flex items-center mb-3">
                <svg class="w-4 h-4 text-indigo-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                <span class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">AI Summary</span>
                {$draftBadge}
            </div>
            <div class="ikb-ai-summary-content text-sm text-gray-800 leading-relaxed">
                {$safeText}
            </div>
        </div>
        HTML;
    }

    /**
     * Render an AI-assisted block — governed AI drafting with human approval.
     *
     * Attributes:
     *   capability — capability ID for the draft operation
     *   mode       — "draft_only" (default; read-only, no mutation) or "suggest" (pre-filled, user approves)
     *   model      — AI model ID
     *   max_tokens — max output tokens (default: 512)
     *   class      — additional CSS classes
     *
     * Children: fallback content shown while AI is generating or if unavailable.
     */
    private function renderAiAssist(array $attrs, string $children, array $context): string
    {
        $capability = (string)($attrs['capability'] ?? '');
        $mode = (string)($attrs['mode'] ?? 'draft_only');
        $model = (string)($attrs['model'] ?? 'gpt-4o-mini');
        $maxTokens = (int)($attrs['max_tokens'] ?? 512);
        $class = (string)($attrs['class'] ?? '');

        // Policy gate
        $policy = class_exists('Ikabud\\Kernel\\DiSyL\\AI\\Policy') ? new \Ikabud\Kernel\DiSyL\AI\Policy() : null;
        if ($policy !== null && $policy->isKilled()) {
            return $this->entityErrorState('AI features are disabled.', $class);
        }

        if (!$policy->allowsModel($model)) {
            return $this->entityErrorState('AI model not permitted by policy.', $class);
        }

        if (!$policy->canAfford($model, $maxTokens)) {
            return $this->entityErrorState('AI cost ceiling exceeded.', $class);
        }

        $fallbackHtml = trim($children) !== ''
            ? '<div class="ikb-ai-assist-fallback text-sm text-gray-500 italic mt-2">' . $children . '</div>'
            : '';

        // Deterministic placeholder for non-interactive rendering.
        // In a full implementation, this would be an Alpine.js island that
        // fetches AI content on user interaction.

        $resultText = '';
        $error = null;

        try {
            $provider = $this->aiProvider ?? (class_exists('Ikabud\\Kernel\\DiSyL\\AI\\EchoAiProvider') ? new \Ikabud\Kernel\DiSyL\AI\EchoAiProvider() : null);
            if ($provider !== null) {
                $response = $provider->complete([
                    'model' => $model,
                    'prompt' => "Draft a response for capability: {$capability}. Mode: {$mode}. Be concise.",
                    'max_tokens' => $policy !== null ? $policy->capMaxTokens($maxTokens) : $maxTokens,
                ]);
                if ($policy !== null) {
                    $policy->recordUsage($model, $response['output_tokens'] ?? 0);
                }
                $resultText = $response['text'] ?? '';
            } else {
                $resultText = '[AI provider not available]';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== null) {
            return $this->entityErrorState('AI assist unavailable: ' . $error, $class);
        }

        $safeText = htmlspecialchars($resultText, ENT_QUOTES, 'UTF-8');

        $modeBadge = match ($mode) {
            'suggest' => '<span class="ikb-ai-draft-badge inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-blue-100 text-blue-800 ml-2">Suggestion</span>',
            default => '<span class="ikb-ai-draft-badge inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800 ml-2">Draft Only</span>',
        };

        return <<<HTML
        <div class="ikb-ai-assist rounded-xl border border-indigo-200 bg-white p-5 {$class}">
            <div class="flex items-center mb-3">
                <svg class="w-4 h-4 text-indigo-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>
                <span class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">AI Assist</span>
                {$modeBadge}
            </div>
            <div class="ikb-ai-assist-content text-sm text-gray-800 leading-relaxed">
                {$safeText}
            </div>
            {$fallbackHtml}
        </div>
        HTML;
    }

    /**
     * Render a governed report component — business document with header, body, and signature block.
     *
     * Attributes:
     *   title    — report title
     *   subtitle — report subtitle/description
     *   source   — entity source (optional; for data-driven reports)
     *   format   — report format: summary, detailed, official (default: summary)
     *   class    — additional CSS classes
     *
     * Children: report body content (tables, entity lists, text)
     */
    private function renderReport(array $attrs, string $children, array $context): string
    {
        $title = htmlspecialchars((string)($attrs['title'] ?? 'Report'), ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars((string)($attrs['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
        $format = (string)($attrs['format'] ?? 'summary');
        $class = (string)($attrs['class'] ?? '');

        $formatClass = match ($format) {
            'official' => 'ikb-report--official',
            'detailed' => 'ikb-report--detailed',
            default => 'ikb-report--summary',
        };

        $dateStr = date('F j, Y');
        $subtitleHtml = $subtitle !== ''
            ? "<p class=\"ikb-report-subtitle text-sm text-gray-500 mt-1\">{$subtitle}</p>"
            : '';

        return <<<HTML
        <div class="ikb-report max-w-4xl mx-auto bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden {$formatClass} {$class}">
            <div class="ikb-report-header px-8 py-6 border-b border-gray-100 bg-gray-50/50">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h1 class="ikb-report-title text-xl font-bold text-gray-900">{$title}</h1>
                        {$subtitleHtml}
                    </div>
                    <div class="ikb-report-meta text-right text-xs text-gray-400 flex-shrink-0">
                        <p>{$dateStr}</p>
                    </div>
                </div>
            </div>
            <div class="ikb-report-body px-8 py-6">
                {$children}
            </div>
        </div>
        HTML;
    }

    /**
     * Render a signature block for official documents and reports.
     *
     * Attributes:
     *   roles    — comma-separated role labels (e.g. "Prepared By,Checked By,Approved By")
     *   class    — additional CSS classes
     *
     * Children: optional additional content below signatures
     */
    private function renderSignatureBlock(array $attrs, string $children): string
    {
        $rolesStr = (string)($attrs['roles'] ?? 'Prepared By,Reviewed By,Approved By');
        $class = (string)($attrs['class'] ?? '');
        $roles = array_map('trim', explode(',', $rolesStr));

        $signatures = '';
        foreach ($roles as $index => $role) {
            if ($role === '') { continue; }
            $safeRole = htmlspecialchars($role, ENT_QUOTES, 'UTF-8');
            $signatures .= <<<SIG
            <div class="ikb-signature flex-1 min-w-[120px]">
                <div class="ikb-signature-line border-b border-gray-400 pt-12 mb-2"></div>
                <p class="ikb-signature-label text-xs text-gray-600 font-medium">{$safeRole}</p>
                <p class="ikb-signature-date text-xs text-gray-400 mt-0.5">Date: _______________</p>
            </div>
            SIG;
        }

        $slotHtml = trim($children) !== ''
            ? "<div class=\"ikb-signature-extra mt-4 text-xs text-gray-500\">{$children}</div>"
            : '';

        return <<<HTML
        <div class="ikb-signature-block mt-10 pt-6 border-t border-gray-200 {$class}">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4">Signatures</p>
            <div class="ikb-signature-row flex flex-wrap gap-6">
                {$signatures}
            </div>
            {$slotHtml}
        </div>
        HTML;
    }

    /**
     * Render {ikb_entity_view} — DiSyL entity view configuration.
     *
     * Registers an entity view contract with EntityViewResolver using
     * a declarative DiSyL syntax instead of PHP arrays.
     *
     * Usage in .disyl config files:
     *   {ikb_entity_view name="employee_profile" view="table"}
     *     {field name="first_name" type="string" renderer="text"}
     *     {field name="last_name"  type="string" renderer="text"}
     *     {field name="salary_type" type="enum" renderer="badge:{hourly|Daily}"}
     *     {field name="employment_status" type="enum" renderer="badge:{regular|Regular|green}"}
     *     {action name="view" url="/admin/wage/employees/{id}/view"}
     *     {action name="edit" url="/admin/wage/employees/{id}"}
     *   {/ikb_entity_view}
     *
     * Produces no output — only registers the view contract at runtime.
     *
     * @param array $attrs Component attributes
     * @param string $children Raw child content (not compiled — preserves {field}/{action} tags)
     * @param array $context Template rendering context
     * @return string Empty string (config-only, no output)
     */
    private function renderEntityViewConfig(array $attrs, string $children, array $context): string
    {
        $name = $attrs['name'] ?? '';
        $view = $attrs['view'] ?? 'table';
        $renderer = $attrs['renderer'] ?? '';
        $class = $attrs['class'] ?? '';

        // Collect semantic role→field mapping from {field role="..."} attributes
        $roleFields = [];

        if ($name === '') {
            $this->logError("ikb_entity_view missing required 'name' attribute");
            return '';
        }

        $validViews = ['table', 'compact', 'card_grid', 'detailed', 'summary'];
        if (!in_array($view, $validViews, true)) {
            $this->logError("ikb_entity_view '{$name}': unknown view type '{$view}' — expected one of: " . implode(', ', $validViews));
        }

        $timeoutMs = isset($attrs['timeout_ms']) ? (int)$attrs['timeout_ms'] : null;

        // Parse {field name="..." type="..." renderer="..." visible="true/false"} from raw children
        $fields = [];
        $fieldRenderers = [];
        $visibleFields = [];
        if (preg_match_all('/\{field\s+((?:[^{}]|\{[^{}]*\})*)\}/', $children, $fieldMatches)) {
            foreach ($fieldMatches[1] as $fieldStr) {
                $fieldAttrs = $this->parseSimpleAttrs($fieldStr);
                $fieldName = $fieldAttrs['name'] ?? '';
                if ($fieldName === '') {
                    $this->logError("ikb_entity_view '{$name}': {field} missing required 'name' attribute");
                    continue;
                }
                $fields[] = $fieldName;

                // Track semantic role if present (e.g. role="title", role="subtitle", role="image")
                $fieldRole = $fieldAttrs['role'] ?? '';
                if ($fieldRole !== '' && in_array($fieldRole, ['title', 'subtitle', 'image', 'body', 'description'], true)) {
                    $roleFields[$fieldRole] = $fieldName;
                }

                // Track visible fields — fields with visible="false" are excluded from public wildcard expansion
                $isVisible = ($fieldAttrs['visible'] ?? 'true') !== 'false';
                if ($isVisible) {
                    $visibleFields[] = $fieldName;
                }

                // Validate renderer format if present
                if (!empty($fieldAttrs['renderer'])) {
                    $fieldRenderers[$fieldName] = $fieldAttrs['renderer']; 
                    $this->validateFieldRenderer($name, $fieldName, $fieldAttrs['renderer']);
                }
            }
        }

        // Parse {action name="..." url="..." method="..." ...} from raw children
        $actions = [];
        $actionUrls = [];
        $actionMethods = [];
        $actionLabels = [];
        $actionConfirm = [];
        $actionShowIf = [];
        $actionRoles = [];

        if (preg_match_all('/\{action\s+((?:[^{}]|\{[^{}]*\})*)\}/', $children, $actionMatches)) {
            foreach ($actionMatches[1] as $actionStr) {
                $actionAttrs = $this->parseSimpleAttrs($actionStr);
                $actionName = $actionAttrs['name'] ?? '';
                if ($actionName === '') {
                    continue;
                }
                $actions[] = $actionName;

                if (!empty($actionAttrs['url'])) {
                    $actionUrls[$actionName] = $actionAttrs['url'];
                }
                if (!empty($actionAttrs['method'])) {
                    $actionMethods[$actionName] = $actionAttrs['method'];
                }
                if (!empty($actionAttrs['label'])) {
                    $actionLabels[$actionName] = $actionAttrs['label'];
                }
                if (!empty($actionAttrs['confirm'])) {
                    $actionConfirm[$actionName] = $actionAttrs['confirm'];
                }
                if (!empty($actionAttrs['show_if'])) {
                    $actionShowIf[$actionName] = $actionAttrs['show_if'];
                }
                if (!empty($actionAttrs['roles'])) {
                    $actionRoles[$actionName] = explode(',', $actionAttrs['roles']);
                }
            }
        }

        // Parse {filter name="..." type="..." values="..."} from raw children
        // Declares allowed filters for the entity source with type constraints.
        $filterSchema = [];
        if (preg_match_all('/\{filter\s+((?:[^{}]|\{[^{}]*\})*)\}/', $children, $filterMatches)) {
            foreach ($filterMatches[1] as $filterStr) {
                $filterAttrs = $this->parseSimpleAttrs($filterStr);
                $filterName = $filterAttrs['name'] ?? '';
                if ($filterName === '') continue;

                $entry = ['type' => $filterAttrs['type'] ?? 'string'];
                if (!empty($filterAttrs['values'])) {
                    $entry['values'] = array_map('trim', explode(',', $filterAttrs['values']));
                }
                $filterSchema[$filterName] = $entry;
            }
        }

        // Validate the collected contract before registering
        $this->validateViewContract($name, $view, $fields, $roleFields, $actionUrls);

        // Build contract
        $contract = [
            'fields' => $fields,
            'actions' => $actions,
        ];

        if (!empty($actionUrls)) { $contract['action_urls'] = $actionUrls; }
        if (!empty($actionMethods)) { $contract['action_methods'] = $actionMethods; }
        if (!empty($actionLabels)) { $contract['action_labels'] = $actionLabels; }
        if (!empty($actionConfirm)) { $contract['action_confirm'] = $actionConfirm; }
        if (!empty($actionShowIf)) { $contract['action_show_if'] = $actionShowIf; }
        if (!empty($actionRoles)) { $contract['action_roles'] = $actionRoles; }
        if (!empty($fieldRenderers)) { $contract['renderers'] = $fieldRenderers; }
        if (!empty($visibleFields)) { $contract['visible_fields'] = $visibleFields; }
        if (!empty($filterSchema)) { $contract['filter_schema'] = $filterSchema; }
        if ($renderer !== '') { $contract['renderer'] = $renderer; }
        if ($class !== '') { $contract['class'] = $class; }
        if ($timeoutMs !== null) { $contract['timeout_ms'] = $timeoutMs; }

        // Store role→field mapping in contract so renderers can use semantic roles
        if (!empty($roleFields)) {
            $contract['role_fields'] = $roleFields;
        }

        // Register with EntityViewResolver
        try {
            if (class_exists(\Ikabud\Kernel\EntityContext\EntityViewResolver::class, true)) {
                $resolver = \Ikabud\Kernel\EntityContext\EntityViewResolver::getInstance();
                $resolver->registerView($name, $view, $contract);
            }
        } catch (\Throwable $e) {
            $this->logError("Failed to register entity view {$name}/{$view}: " . $e->getMessage());
        }

        return ''; // No output
    }

    /**
     * Validate an entity view contract before registration.
     *
     * Checks for:
     * - Duplicate field names
     * - Duplicate semantic role assignments
     * - Action URL placeholders ({id}, {slug}) that don't match any declared field
     *
     * Logs errors via logError() but does not abort — the contract is still registered.
     */
    private function validateViewContract(string $entityName, string $view, array $fields, array $roleFields, array $actionUrls): void
    {
        // Check 1: duplicate field names
        $seen = [];
        foreach ($fields as $f) {
            if (isset($seen[$f])) {
                $this->logError("ikb_entity_view '{$entityName}/{$view}': duplicate field '{$f}' declared multiple times");
            }
            $seen[$f] = true;
        }

        // Check 2: duplicate role values (last-writer-wins detection)
        $roleSeen = [];
        foreach ($roleFields as $role => $fieldName) {
            if (isset($roleSeen[$role])) {
                $this->logError("ikb_entity_view '{$entityName}/{$view}': role '{$role}' assigned to both '{$roleSeen[$role]}' and '{$fieldName}' — last definition wins");
            }
            $roleSeen[$role] = $fieldName;
        }

        // Check 3: action URL placeholders not in field list
        $fieldSet = array_flip($fields);
        foreach ($actionUrls as $actionName => $url) {
            if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $url, $placeholderMatches)) {
                foreach ($placeholderMatches[1] as $placeholder) {
                    // Skip standard context variables that don't need field declarations
                    if (in_array($placeholder, ['base_url', 'current_url', 'request_id'], true)) {
                        continue;
                    }
                    if (!isset($fieldSet[$placeholder])) {
                        $this->logError("ikb_entity_view '{$entityName}/{$view}' action '{$actionName}': URL placeholder '{{$placeholder}}' not in declared fields — will render as literal");
                    }
                }
            }
        }
    }

    /**
     * Validate a field renderer string from a view contract {field} tag.
     * Logs a warning for unrecognized renderer patterns without aborting.
     */
    private function validateFieldRenderer(string $entityName, string $fieldName, string $renderer): void
    {
        $validPrefixes = ['badge', 'badge:map', 'money', 'datetime', 'boolean', 'string', 'text', 'number', 'enum', 'date', 'image'];
        $prefix = explode(':', $renderer, 2)[0];
        // Allow dynamic badge:JSON patterns (e.g. badge:{draft|gray|...})
        if ($prefix === 'badge' && str_contains($renderer, '{')) {
            return; // dynamic badge map — accept
        }
        if (!in_array($prefix, $validPrefixes, true)) {
            $this->logError("ikb_entity_view '{$entityName}' field '{$fieldName}': unknown renderer '{$renderer}' — expected prefix one of: " . implode(', ', $validPrefixes));
        }
    }

    /**
     * Render {state} — declarative state manager bridge.
     *
     * Declares a state namespace with typed variables, default values,
     * and an optional server-side source handler. Renders as an Alpine
     * x-data container with computed initial state.
     *
     * Usage:
     *   {state name="kiosk" source="attendance-wage/kiosk-state"}
     *     {variable name="step" type="int" default="0"}
     *     {variable name="searchQuery" type="string" default=""}
     *     {variable name="selectedEmployee" type="?object"}
     *     <div class="kiosk-content">
     *       <span x-text="step"></span>
     *     </div>
     *   {/state}
     *
     * With explicit bridge:
     *   {state name="kiosk" bridge="htmx"}
     *   {state name="kiosk" bridge="custom"}
     *
     * @param array $attrs Component attributes
     * @param string $children Raw child content with {variable} tags
     * @param array $context Template rendering context
     * @return string HTML with framework-specific attributes
     */
    private function renderStateDeclaration(array $attrs, string $children, array $context): string
    {
        $name = $attrs['name'] ?? 'app';
        $source = $attrs['source'] ?? '';

        // Parse {variable name="..." type="..." default="..."} from raw children
        $variables = $this->parseStateVariables($children);

        // Build initial state from defaults
        $initialState = [];
        foreach ($variables as $var) {
            $varName = $var['name'];
            $default = $var['default'];
            $type = $var['type'];

            // Coerce default value to the declared type
            if ($default === null) {
                $initialState[$varName] = null;
            } elseif (str_starts_with($type, '?')) {
                // Nullable: use the raw default
                $initialState[$varName] = $this->coerceValue($default, substr($type, 1));
            } else {
                $initialState[$varName] = $this->coerceValue($default, $type);
            }
        }

        // Allow source handler to override initial state
        if ($source !== '') {
            try {
                $handlerState = $this->resolveStateSource($source, $name, $context);
                if (is_array($handlerState)) {
                    $initialState = array_merge($initialState, $handlerState);
                }
            } catch (\Throwable $e) {
                $this->logError("State source {$source} failed: " . $e->getMessage());
            }
        }

        // Serialize as JSON
        $json = htmlspecialchars(
            json_encode($initialState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        // Strip {variable} tags from children before rendering
        $body = preg_replace('/\{variable\s+((?:[^{}]|\{[^{}]*\})*)\}/', '', $children);
        $body = trim($body);

        // Resolve bridge and delegate rendering
        $bridge = BridgeManager::resolve($attrs['bridge'] ?? 'alpine');
        return $bridge->renderState($name, $json, $body, $attrs);
    }

    /**
     * Parse {variable} declarations from raw child content.
     *
     * @param string $children Raw children containing {variable ...} tags
     * @return array<int, array{name: string, type: string, default: mixed}>
     */
    private function parseStateVariables(string $children): array
    {
        $variables = [];
        if (preg_match_all('/\{variable\s+((?:[^{}]|\{[^{}]*\})*)\}/', $children, $matches)) {
            foreach ($matches[1] as $varStr) {
                $attrs = $this->parseSimpleAttrs($varStr);
                $varName = $attrs['name'] ?? '';
                if ($varName === '') {
                    continue;
                }
                $type = $attrs['type'] ?? 'string';
                $defaultStr = $attrs['default'] ?? '';
                $default = $this->parseDefaultValue($defaultStr, $type);
                $variables[] = [
                    'name' => $varName,
                    'type' => $type,
                    'default' => $default,
                ];
            }
        }
        return $variables;
    }

    /**
     * Parse a default value string to the appropriate PHP type.
     */
    private function parseDefaultValue(string $value, string $type): mixed
    {
        $baseType = ltrim($type, '?');

        // Empty string means null for non-string types
        if ($value === '') {
            return match ($baseType) {
                'string' => '',
                'int', 'integer' => 0,
                'float', 'number' => 0.0,
                'bool', 'boolean' => false,
                'array' => [],
                default => null,
            };
        }

        return match ($baseType) {
            'int', 'integer' => (int)$value,
            'float', 'number' => (float)$value,
            'bool', 'boolean' => in_array(strtolower($value), ['true', '1', 'yes'], true),
            'array' => explode(',', $value),
            default => $value,
        };
    }

    /**
     * Coerce a value to the specified type.
     */
    private function coerceValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int', 'integer' => (int)$value,
            'float', 'number' => (float)$value,
            'bool', 'boolean' => (bool)$value,
            'string' => (string)$value,
            'array' => is_array($value) ? $value : [],
            default => $value,
        };
    }

    /**
     * Resolve state from a source handler.
     *
     * The source format is "module-id/handler-name", e.g. "attendance-wage/kiosk-state".
     * The handler is called via the capability bus or a direct function lookup.
     *
     * @param string $source Source identifier
     * @param string $stateName State namespace name
     * @param array $context Template rendering context
     * @return array|null Computed state or null if unavailable
     */
    private function resolveStateSource(string $source, string $stateName, array $context): ?array
    {
        // Support module-level state handler functions: moduleId_state_handler()
        $parts = explode('/', $source);
        if (count($parts) === 2) {
            $moduleId = str_replace('-', '_', $parts[0]);
            $handler = str_replace('-', '_', $parts[1]);
            $fnName = $moduleId . '_' . $handler;
            if (function_exists($fnName)) {
                $result = $fnName($stateName, $context);
                return is_array($result) ? $result : null;
            }
        }

        // Fallback: try capability-based resolution via the app
        if (function_exists('app') && ($app = @app()) !== null) {
            try {
                if (method_exists($app, 'capabilities')) {
                    $caps = $app->capabilities();
                    if (method_exists($caps, 'call')) {
                        $result = $caps->call("state.{$source}", [
                            'state_name' => $stateName,
                            'context' => $context,
                        ]);
                        return is_array($result) ? $result : null;
                    }
                }
            } catch (\Throwable $e) {
                // Capability not available — return null
            }
        }

        return null;
    }

    /**
     * Parse simple key="value" attributes from a string without resolving
     * template variables — used by renderEntityViewConfig for {field}/{action}.
     *
     * @param string $str Attribute string like name="view" url="/test/{id}"
     * @return array<string, string> Parsed attribute key => value map
     */
    private function parseSimpleAttrs(string $str): array
    {
        $attrs = [];
        preg_match_all('/([\w-]+)="([^"]*)"/', $str, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $attrs[$m[1]] = $m[2];
        }
        return $attrs;
    }

    /**
     * Render {ikb_component} — server-rendered component bridge.
     *
     * Delegates to the configured frontend framework bridge.
     * Default bridge is Alpine.js (x-data="ikbComponent(...)"), but can be
     * overridden per-invocation via the "bridge" attribute.
     *
     * Usage:
     *   {ikb_component name="employee-profile" data="selectedEmployee"}
     *     <div class="...">{name}</div>
     *     <div class="...">{position}</div>
     *   {/ikb_component}
     *
     * With explicit bridge:
     *   {ikb_component name="employee-profile" data="selectedEmployee" bridge="htmx"}
     *   {ikb_component name="employee-profile" data="selectedEmployee" bridge="custom"}
     *
     * @param array $attrs Component attributes: name, data, class, bridge
     * @param string $children Compiled child content
     * @param array $context Template rendering context
     * @return string HTML with framework-specific attributes
     */
    private function renderIkbComponent(array $attrs, string $children, array $context): string
    {
        $name = $attrs['name'] ?? 'component';
        $dataVar = $attrs['data'] ?? '';

        // Resolve the data variable from template context
        $data = [];
        if ($dataVar !== '' && isset($context[$dataVar])) {
            $data = $context[$dataVar];
        } elseif ($dataVar !== '') {
            // Support dot-path for nested data
            $segments = explode('.', $dataVar);
            $current = $context;
            foreach ($segments as $seg) {
                if (is_array($current) && isset($current[$seg])) {
                    $current = $current[$seg];
                } else {
                    $current = [];
                    break;
                }
            }
            if (is_array($current)) {
                $data = $current;
            }
        }

        // Serialize data as JSON
        $json = htmlspecialchars(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        // Resolve bridge and delegate rendering
        $bridge = BridgeManager::resolve($attrs['bridge'] ?? 'alpine');
        return $bridge->renderComponent($name, $json, $children, $attrs);
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

    /**
     * Render {ikb_entity_list} — delegates to the DefaultEntityRenderer service.
     *
     * Replaces the former EntityRenderingTrait::renderEntityList().
     */
    private function renderEntityListViaService(array $attrs, string $children, array $context): string
    {
        if (!\function_exists('app') || ($app = \app()) === null || !method_exists($app, 'entityRenderers')) {
            return '<div class="ikb-entity-error px-4 py-2 text-sm text-red-600">Entity renderer service not available.</div>';
        }

        $source = (string)($attrs['source'] ?? '');
        $view = (string)($attrs['view'] ?? 'compact');
        $overrides = [];
        if (isset($attrs['limit'])) { $overrides['limit'] = (int)$attrs['limit']; }
        if (isset($attrs['actions'])) { $overrides['actions'] = array_map('trim', explode(',', (string)$attrs['actions'])); }

        // Parse filter attribute: filter="project_id={project.id},status=approved"
        // Resolves {var.path} references from the template context.
        if (isset($attrs['filter']) && $attrs['filter'] !== '') {
            $overrides['filters'] = [];
            foreach (explode(',', (string)$attrs['filter']) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || !str_contains($pair, '=')) continue;
                [$key, $rawVal] = explode('=', $pair, 2);
                $key = trim($key);
                $rawVal = trim($rawVal);
                // Resolve {var.path} from context if present
                if (str_starts_with($rawVal, '{') && str_ends_with($rawVal, '}')) {
                    $varPath = substr($rawVal, 1, -1);
                    $segments = explode('.', $varPath);
                    $current = $context;
                    foreach ($segments as $seg) {
                        $current = is_array($current) && isset($current[$seg]) ? $current[$seg] : null;
                        if ($current === null) break;
                    }
                    $overrides['filters'][$key] = $current ?? $rawVal;
                } else {
                    $overrides['filters'][$key] = $rawVal;
                }
            }
        }

        $resolved = null;
        try {
            if (method_exists($app, 'entityViews')) {
                $resolved = $app->entityViews()->resolve($source, $view, $overrides);
            }
        } catch (\Throwable $e) {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg">'
                . '<p class="text-sm text-red-600">Failed to resolve entity list: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        if ($resolved === null || !empty($resolved['error'])) {
            $errorMsg = $resolved['error'] ?? '';
            $emptyMessage = (string)($attrs['empty'] ?? '');
            $class = (string)($attrs['class'] ?? '');
            if ($errorMsg !== '' && $emptyMessage !== '' && (
                str_contains($errorMsg, 'Capability not found') ||
                str_contains($errorMsg, 'Data source unavailable') ||
                str_contains($errorMsg, 'No view contract')
            )) {
                return '<div class="ikb-entity-list--empty text-center py-8 text-gray-500 ' . $class . '">' . htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">' . htmlspecialchars($errorMsg ?: 'Unable to load data.', ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        $rows = $resolved['rows'] ?? [];
        $attrs['_children'] = $children;

        // Validate requested fields against the view contract
        if (isset($attrs['fields']) && is_string($attrs['fields']) && $attrs['fields'] !== '') {
            $requestedFields = array_map('trim', explode(',', $attrs['fields']));
            $contractFields = $resolved['view']['fields'] ?? null;
            if (is_array($contractFields) && $contractFields !== []) {
                $unknownFields = array_diff($requestedFields, $contractFields);
                if (!empty($unknownFields)) {
                    $this->logError("ikb_entity_list '{$source}': unknown field(s) '" . implode(', ', $unknownFields)
                        . "' — valid fields: " . implode(', ', $contractFields));
                }
            }
        }

        if (empty($rows)) {
            $msg = (string)($attrs['empty'] ?: $resolved['view']['empty_state'] ?? 'No records found.');
            return '<div class="ikb-entity-list--empty text-center py-8 text-gray-500 ' . (string)($attrs['class'] ?? '') . '">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        return $app->entityRenderers()->renderList($rows, $resolved['view'], $attrs, $context);
    }

    /**
     * Render {ikb_entity_detail} — delegates to the DefaultEntityRenderer service.
     *
     * Replaces the former EntityRenderingTrait::renderEntityDetail().
     */
    private function renderEntityDetailViaService(array $attrs, string $children, array $context): string
    {
        if (!\function_exists('app') || ($app = \app()) === null || !method_exists($app, 'entityRenderers')) {
            return '<div class="ikb-entity-error px-4 py-2 text-sm text-red-600">Entity renderer service not available.</div>';
        }

        $source = (string)($attrs['source'] ?? '');
        $entityId = (string)($attrs['id'] ?? $attrs['entity_id'] ?? '');
        $view = (string)($attrs['view'] ?? 'detailed');
        $class = (string)($attrs['class'] ?? '');
        $requestedFields = isset($attrs['fields']) ? array_map('trim', explode(',', (string)$attrs['fields'])) : null;

        if ($source === '') {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">Missing source attribute on ikb_entity_detail.</p></div>';
        }
        if ($entityId === '') {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">Missing id attribute on ikb_entity_detail.</p></div>';
        }

        $resolved = null;
        try {
            if (method_exists($app, 'entityViews')) {
                $resolved = $app->entityViews()->resolveDetail($source, $entityId, $view);
            }
        } catch (\Throwable $e) {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">Failed to resolve entity detail: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        if ($resolved === null || !empty($resolved['error'])) {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">' . htmlspecialchars($resolved['error'] ?? 'Entity not found.', ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        $entity = $resolved['entity'] ?? null;
        if ($entity === null || empty($entity)) {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">Entity not found.</p></div>';
        }

        $attrs['_children'] = $children;
        $attrs['fields'] = $requestedFields ?? ($resolved['view']['fields'] ?? null);

        return $app->entityRenderers()->renderDetail($entity, $resolved['view'], $attrs, $context);
    }
}
