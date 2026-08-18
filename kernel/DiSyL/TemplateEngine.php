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

require_once __DIR__ . '/ExpressionEvaluator.php';
require_once __DIR__ . '/v4/FunctionRegistry.php';
require_once __DIR__ . '/Component/ComponentRenderer.php';
require_once __DIR__ . '/Component/MacroProcessor.php';
require_once __DIR__ . '/Component/IncludeResolver.php';
require_once __DIR__ . '/Component/ExtendsProcessor.php';
require_once __DIR__ . '/Cache/SourceCache.php';
require_once __DIR__ . '/Renderer/TemplateRenderer.php';

use Ikabud\Kernel\DiSyL\Bridge\BridgeManager;
use Ikabud\Kernel\DiSyL\Cache\SourceCache;
use Ikabud\Kernel\DiSyL\Component\ComponentRenderer;
use Ikabud\Kernel\DiSyL\Component\MacroProcessor;
use Ikabud\Kernel\DiSyL\Component\IncludeResolver;
use Ikabud\Kernel\DiSyL\Component\ExtendsProcessor;
use Ikabud\Kernel\DiSyL\Renderer\TemplateRenderer;
use Ikabud\Kernel\DiSyL\v4\RenderContext;

class TemplateEngine
{
    private const LOOP_BREAK_MARKER = "\x1E__DISYL_BREAK__\x1E";
    private const LOOP_CONTINUE_MARKER = "\x1E__DISYL_CONTINUE__\x1E";

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
    private ?MacroProcessor $macroProcessor = null;
    /** @var SourceCache|null Lazy source-reading/caching layer (D8 refactor) */
    private ?SourceCache $sourceCache = null;
    /** @var IncludeResolver|null {include} tag processor (D8 refactor) */
    private ?IncludeResolver $includeResolver = null;
    /** @var ExtendsProcessor|null {extends}/{block}/{debug} processor (D8 refactor) */
    private ?ExtendsProcessor $extendsProcessor = null;
    /** @var TemplateRenderer|null Output-cache/metrics/fingerprint machinery (D8 refactor) */
    private ?TemplateRenderer $templateRenderer = null;
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

    /** @var array<string, string> Component namespace => directory path */
    private array $componentDirs = [];
    
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
     * Register a component directory for a namespace (e.g., 'workbench' => '.../components').
     * Enables {include "workbench:app_shell"} resolution.
     */
    public function addComponentDirectory(string $namespace, string $dir): void
    {
        $this->componentDirs[$namespace] = rtrim($dir, '/');
        // Keep compiled cache in sync for include-path mtime tracking
        if ($this->compiledCache !== null) {
            $this->compiledCache->addComponentDirectory($namespace, rtrim($dir, '/'));
        }
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
                    // Register component directories for include-path mtime tracking
                    foreach ($this->componentDirs as $ns => $dir) {
                        $this->compiledCache->addComponentDirectory($ns, $dir);
                    }
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
    
    /** @var array{file:string, errors:array}[]|null Last loadViewConfigs result with per-file errors */
    private static ?array $lastLoadErrors = null;

    /** @var array<string, bool> Per-request cache of compiled-mode eligibility */
    private array $compiledEligibilityCache = [];

    /**
     * Bump this version whenever the compiled-eligibility rules change
     * (e.g. new interpreted-only tags are added to the exclusion list).
     * Stale eligibility cache files from older versions are automatically
     * ignored — no manual cache clearing required.
     */
    private const COMPILED_ELIGIBILITY_CACHE_VERSION = 4;

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

        // Guard against empty resolved paths from upstream code
        if ($templatePath === '' || $templatePath === $this->templateDir . '/.disyl') {
            $this->logError("Invalid template path resolved: {$template}");
            throw new \RuntimeException("Template not found: {$template}");
        }

        $context = array_merge($this->globals, $context);
        $sharedCacheKey = null;
        if ($this->templateRenderer()->sharedOutputCacheTtl() > 0 && $this->cacheEnabled && $this->hasApcuCache()) {
            $sharedCacheKey = $this->templateRenderer()->buildSharedOutputCacheKey($templatePath, $context);
            $shared = apcu_fetch($sharedCacheKey, $sharedHit);
            if ($sharedHit && is_string($shared)) {
                TemplateRenderer::incrementMetric('output_hits');
                $this->templateRenderer()->logCacheMetricsPeriodic();
                if (function_exists('log_timing')) {
                    log_timing('disyl.render.breakdown', microtime(true) - 0.0001, [
                        'template' => $template,
                        'cache_path' => 'apcu_output_hit',
                        'output_bytes' => strlen($shared),
                    ]);
                }
                return $shared;
            }
            TemplateRenderer::incrementMetric('output_misses');
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
                        $c->setErrorHandler(\Closure::fromCallable([$this, 'logError']));
                        return $c;
                    }
                    $c = $this->compiledCache->get($path);
                    $c->setTemplateLoader($loader);
                    $c->setErrorHandler(\Closure::fromCallable([$this, 'logError']));
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
                $compiled->setErrorHandler(\Closure::fromCallable([$this, 'logError']));
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
                    $parentCompiled->setErrorHandler(\Closure::fromCallable([$this, 'logError']));
                    $parentCompiled->setFilters($registry);
                    $result = $parentCompiled->executeRaw($ctx_obj);
                }
                if (strlen($result) > self::MAX_OUTPUT_BYTES) {
                    $this->logError("Template output exceeds maximum size (" . self::MAX_OUTPUT_BYTES . " bytes): {$template}");
                    throw new \RuntimeException("Template output exceeds maximum allowed size");
                }
                if ($sharedCacheKey !== null) {
                    apcu_store($sharedCacheKey, $result, $this->templateRenderer()->sharedOutputCacheTtl());
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
        
        // Interpreted pipeline deprecation notice (production observability).
        // The interpreted path is legacy — kept as a fallback for
        // interpreted-only component tags. New templates should use
        // compiled-mode-eligible syntax. Log each template once so usage is
        // visible without flooding the log.
        if (function_exists('write_log')
            && in_array(strtolower((string)($_ENV['APP_ENV'] ?? $_ENV['IKABUD_ENV'] ?? '')), ['production', 'prod'], true)) {
            static $interpretedDeprecationLogged = [];
            if (!isset($interpretedDeprecationLogged[$templatePath])) {
                $interpretedDeprecationLogged[$templatePath] = true;
                write_log('disyl.interpreted.deprecated', 'warning', [
                    'template' => $template,
                    'reason' => 'template rendered via the legacy interpreted pipeline; migrate to compiled-eligible syntax',
                ]);
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
            $memKey = $this->templateRenderer()->buildOutputCacheKey($templatePath, $context);
            if ($this->templateRenderer()->hasOutputCacheKey($memKey)) {
                $this->currentTemplatePath = $prevTemplatePath;
                return $this->templateRenderer()->outputCacheGet($memKey);
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
            $this->templateRenderer()->outputCacheSet($memKey, $result);
            if ($sharedCacheKey !== null) {
                apcu_store($sharedCacheKey, $result, $this->templateRenderer()->sharedOutputCacheTtl());
            }
            $this->templateRenderer()->logCacheMetricsPeriodic();
            return $result;
        }
        
        $result = $this->compile($content, $context);

        $this->currentTemplatePath = $prevTemplatePath;

        if (strlen($result) > self::MAX_OUTPUT_BYTES) {
            $this->logError("Template output exceeds maximum size (" . self::MAX_OUTPUT_BYTES . " bytes): {$template}");
            throw new \RuntimeException("Template output exceeds maximum allowed size");
        }

        if ($sharedCacheKey !== null) {
            apcu_store($sharedCacheKey, $result, $this->templateRenderer()->sharedOutputCacheTtl());
        }

        $this->templateRenderer()->logCacheMetricsPeriodic();
        return $result;
    }

    private function buildOutputCacheKey(string $templatePath, array $context): string
    {
        return $this->templateRenderer()->buildOutputCacheKey($templatePath, $context);
    }

    private function buildSharedOutputCacheKey(string $templatePath, array $context): string
    {
        return $this->templateRenderer()->buildSharedOutputCacheKey($templatePath, $context);
    }

    private function hasApcuCache(): bool
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled();
    }

    /** Set the shared (APCu) output-cache TTL (delegates to TemplateRenderer). */
    public function setSharedOutputCacheTtl(int $seconds): void
    {
        $this->templateRenderer()->setSharedOutputCacheTtl($seconds);
    }

    /** Return aggregate cache hit/miss counters for the current FPM worker (delegates to TemplateRenderer). */
    public static function getCacheMetrics(): array
    {
        return TemplateRenderer::getCacheMetrics();
    }

    /** Reset aggregate cache counters (delegates to TemplateRenderer). */
    public static function resetCacheMetrics(): void
    {
        TemplateRenderer::resetCacheMetrics();
    }

    /**
     * Lazily build the shared TemplateRenderer (D8 refactor). The class is
     * self-contained (output cache + metrics + fingerprint) — no engine deps.
     */
    private function templateRenderer(): TemplateRenderer
    {
        return $this->templateRenderer ??= new TemplateRenderer();
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
        TemplateRenderer::incrementMetric('compiles');
        $compileStartedAt = microtime(true);
        $phases = [];

        // v4.8: track recursion depth — macros only extracted at top level.
        // Always clean-slate macros at the start of a top-level compile
        // to prevent cross-request state leakage in PHP-FPM.
        $isTopLevel = ($this->compileDepth === 0);
        if ($isTopLevel) {
            $this->macroProcessor()->reset();
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
            $this->macroProcessor()->reset();
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
            $this->macroProcessor()->reset();
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
        
        // 4c. Extract <style> blocks — process DiSyL tags inside style bodies
        //     (same approach as <script> blocks at 4b).
        $styles = [];
        if (stripos($content, '<style') !== false) {
            $t = microtime(true);
            $content = preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/si', function($match) use (&$styles, $context) {
                $attrs = $this->processVariables($match[1], $context);
                $body = $match[2];
                
                // Process DiSyL tags inside style body — protects CSS curly braces
                // from being mistaken for DiSyL tags, then resolves {variable} references,
                // {if} conditionals, {set} assignments, and {include} directives.
                $body = $this->compileStyleBody($body, $context);
                
                $key = '___STYLE_' . count($styles) . '___';
                $styles[$key] = '<style' . $attrs . '>' . $body . '</style>';
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
        if ($this->macroProcessor()->hasMacros() && str_contains($content, '{call ')) {
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

        if ($isTopLevel) {
            $content = str_replace([self::LOOP_BREAK_MARKER, self::LOOP_CONTINUE_MARKER], '', $content);
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
    
    /**
     * Compile DiSyL tags inside a <style> block body while protecting CSS curly braces.
     *
     * Follows the same approach as compileScriptBody(): CSS rule blocks { ... }
     * are protected with markers, DiSyL tags ({variable}, {if}, {foreach}, {set},
     * {include}) are fully processed, and CSS braces are restored afterward.
     *
     * @param string $body  Raw CSS content inside <style>...</style>
     * @param array  $context  Current render context
     * @return string  CSS with DiSyL tags resolved
     */
    private function compileStyleBody(string $body, array $context): string
    {
        // Pattern matching DiSyL tags — same as script body
        $disylPattern = '/\{(?:'
            . '\/(?:if|for|foreach|each|literal|verbatim)\}'
            . '|(?:if|elseif|for|foreach|each|set|include|literal|verbatim|else)\s'
            . '|else\}'
            . '|[a-zA-Z_][\w.]*'
            . ')/s';
        
        // Step 1: Protect CSS curly braces that aren't DiSyL tags
        $cssMarkers = [];
        $markerCount = 0;
        $chunks = [];
        $insideDisylTag = false;
        
        $len = strlen($body);
        $i = 0;
        while ($i < $len) {
            $char = $body[$i];

            if ($char === '{') {
                if (preg_match($disylPattern, $body, $m, PREG_OFFSET_CAPTURE, $i) === 1 && ($m[0][1] ?? -1) === $i) {
                    $insideDisylTag = true;
                    $chunks[] = $char;
                    $i++;
                    continue;
                }

                $marker = "___CSSCURLY_OPEN_{$markerCount}___";
                $cssMarkers[$marker] = '{';
                $chunks[] = $marker;
                $markerCount++;
            } elseif ($char === '}') {
                if ($insideDisylTag) {
                    $insideDisylTag = false;
                    $chunks[] = $char;
                } else {
                    $marker = "___CSSCURLY_CLOSE_{$markerCount}___";
                    $cssMarkers[$marker] = '}';
                    $chunks[] = $marker;
                    $markerCount++;
                }
            } else {
                $chunks[] = $char;
            }

            $i++;
        }

        $body = implode('', $chunks);
        
        // Step 2: Run full DiSyL compilation (same pipeline as script bodies)
        $this->scriptContext = true; // raw output, no HTML escaping
        
        // Process {literal} blocks within the style
        $styleLiterals = [];
        $body = preg_replace_callback('/\{literal\}(.*?)\{\/literal\}/s', function($match) use (&$styleLiterals) {
            $key = '___STYLELIT_' . count($styleLiterals) . '___';
            $styleLiterals[$key] = $match[1];
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
        
        // Process variables (raw output — no HTML escaping in CSS context)
        $body = $this->processScriptVariables($body, $context);
        
        // Restore style literals
        if (!empty($styleLiterals)) {
            $body = str_replace(array_keys($styleLiterals), array_values($styleLiterals), $body);
        }
        
        $this->scriptContext = false;
        
        // Step 3: Restore CSS curly braces
        if (!empty($cssMarkers)) {
            $body = str_replace(array_keys($cssMarkers), array_values($cssMarkers), $body);
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
        // First pass: null-coalescing {var ?? fallback} (e.g. {sales_count ?? 0},
        // {session.starting_cash ?? 0}). The HTML/v4 paths resolve `??`, but the
        // script-block path did not, so these stayed raw and broke the JS.
        if (str_contains($content, '??')) {
            $content = preg_replace_callback(
                '/\{((?:[a-zA-Z_][\w.]*)\s*\?\?\s*[^}]+)\}/',
                function ($match) use ($context) {
                    $expr = trim($match[1]);
                    $parts = explode('??', $expr, 2);
                    $varPath = trim($parts[0]);
                    $fallback = trim($parts[1]);
                    $value = $this->resolveValue($varPath, $context);
                    // Only fall back on null (missing), not on ''/0 which are
                    // legitimate values in `??` semantics.
                    if ($value === null) {
                        return $fallback;
                    }
                    if (!is_scalar($value)) {
                        return $match[0];
                    }
                    return (string) $value;
                },
                $content
            );
        }

        // Second pass: ternary expressions
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
     *
     * Uses a depth-aware scanner instead of a greedy regex so that array
     * literals containing '[' / ']' (which would never match '}') don't
     * cause the value capture to skip past the closing '}' and merge
     * adjacent {set} blocks.
     */
    private function processSetStatements(string $content, array &$context): string
    {
        // Normalize shorthand {var++} and {var--}
        $content = preg_replace('/\{(?!set\s)(\w+)(\+\+|--)\}/', '{set $1$2}', $content);

        $result = '';
        $len = strlen($content);
        $i = 0;

        while ($i < $len) {
            // Look for {set at current position
            $rest = substr($content, $i);
            if (preg_match('/^\{set\s+(\w+)(?::\s*(\??(?:"[^"]*"(?:\s*\|\s*"[^"]*")*|\w+)))?\s*(?:(?:([+\-*\/]))?\s*=\s*|(\+\+|--)\})/', $rest, $m, PREG_OFFSET_CAPTURE)) {
                $matchStart = $i + $m[0][1];
                $matchLen = strlen($m[0][0]);

                // Output everything before this match
                $result .= substr($content, $i, $m[0][1]);

                $varName = $m[1][0];
                $varType = isset($m[2]) && $m[2][0] !== '' ? trim($m[2][0]) : null;
                $compoundOp = isset($m[3]) && $m[3][0] !== '' ? trim($m[3][0]) : null;

                // Postfix: {set x++} / {set x--}
                if (isset($m[4]) && $m[4][0] !== '') {
                    $current = (int)($context[$varName] ?? 0);
                    $context[$varName] = $m[4][0] === '++' ? $current + 1 : $current - 1;
                    $i = $matchStart + $matchLen;
                    continue;
                }

                // Find the value: scan from after '=' tracking bracket/quote depth
                $eqPos = strrpos($m[0][0], '=');
                $valStart = $matchStart + $eqPos + 1;
                $valEnd = $this->scanToClosingBrace($content, $valStart);

                if ($valEnd === false) {
                    // Couldn't find closing brace — output the tag as-is
                    $result .= $m[0][0];
                    $i = $matchStart + $matchLen;
                    continue;
                }

                $expr = trim(substr($content, $valStart, $valEnd - $valStart));
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
                }

                $context[$varName] = $value;
                $i = $valEnd + 1; // skip past closing '}'
                continue;
            }

            // Also normalize shorthand {var = expr} (no 'set' keyword)
            if (preg_match('/^\{(\w+)\s*=\s*/', $rest, $sm, PREG_OFFSET_CAPTURE)) {
                $matchStart = $i + $sm[0][1];
                $varName = $sm[1][0];
                $eqPos = strlen($sm[0][0]);
                $valStart = $matchStart + $eqPos;
                $valEnd = $this->scanToClosingBrace($content, $valStart);

                if ($valEnd !== false) {
                    $expr = trim(substr($content, $valStart, $valEnd - $valStart));
                    $value = $this->resolveSetValue($expr, $context, null);
                    $context[$varName] = $value;
                    $result .= substr($content, $i, $sm[0][1]);
                    $i = $valEnd + 1;
                    continue;
                }
            }

            $result .= $content[$i];
            $i++;
        }

        return $result;
    }

    /**
     * Scan from $start to find the matching '}' that closes a {set} or {...} block,
     * tracking bracket, paren, and quote depth.
     */
    private function scanToClosingBrace(string $content, int $start): int|false
    {
        $len = strlen($content);
        $depth = 0;
        $inSingle = false;
        $inDouble = false;

        for ($i = $start; $i < $len; $i++) {
            $ch = $content[$i];

            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $i++; continue;
            }
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; continue; }
            if ($inSingle || $inDouble) continue;

            if ($ch === '{' || $ch === '[' || $ch === '(') { $depth++; continue; }
            if ($ch === '}') {
                if ($depth === 0) return $i;
                $depth--;
                continue;
            }
            if ($ch === ']' || $ch === ')') {
                if ($depth > 0) $depth--;
                continue;
            }
        }

        return false;
    }

    /**
     * Resolve a {set} expression value through multiple strategies.
     */
    private function resolveSetValue(string $expr, array $context, ?string $varType): mixed
    {
        // Array literal: delegate directly to expression evaluator
        if (trim($expr) !== '' && trim($expr)[0] === '[') {
            $value = $this->evaluator()->resolveValue(trim($expr), $context);
            return $this->coerceType($value, $varType, '');
        }

        // A bare identifier is an assignment source, not a boolean condition.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', trim($expr))) {
            $value = $this->resolveValue(trim($expr), $context);
            return $this->coerceType($value, $varType, '');
        }

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

        // Try logical expression (or, and) which evaluateCondition handles
        // but evaluateComparison does not (it only handles ==, !=, <, > etc.).
        // Must check before quoted string since 'a or b' is not a valid string.
        if (preg_match('/\s+(or|\|\||and|&&)\s+/i', $expr) && !preg_match('/^["\']/', $expr)) {
            $result = $this->evaluateCondition($expr, $context);
            return $this->coerceType($result, $varType, '');
        }

        // Try quoted string literal (single token only). A multi-part concat
        // like '<a href="/x/" ~ id ~ '/edit">' also starts/ends with a quote
        // but must NOT be collapsed into one mangled literal.
        if ($this->isSingleQuotedToken($expr)) {
            $inner = trim($expr);
            return $this->coerceType(substr($inner, 1, -1), $varType, '');
        }

        // String concatenation with ~ operator (quote-aware split).
        if (str_contains($expr, '~')) {
            $concat = $this->evaluator()->evaluateConcat($expr, $context);
            if ($concat !== null) {
                return $this->coerceType($concat, $varType, '');
            }
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
     * Whether $expr is a SINGLE quoted string token (opening quote at the start
     * and its matching closing quote as the last non-space char). A multi-part
     * concatenation like '<a href="/x/" ~ id ~ '/edit">' starts and ends with a
     * quote but is NOT a single token.
     */
    private function isSingleQuotedToken(string $expr): bool
    {
        $expr = trim($expr);
        $len = strlen($expr);
        if ($len < 2 || ($expr[0] !== "'" && $expr[0] !== '"')) {
            return false;
        }
        $quote = $expr[0];
        for ($i = 1; $i < $len; $i++) {
            if ($expr[$i] === '\\') { $i++; continue; }
            if ($expr[$i] === $quote) {
                return trim(substr($expr, $i + 1)) === '';
            }
        }
        return false;
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
    /**
     * Process {extends} inheritance — delegated to ExtendsProcessor (D8 refactor).
     */
    private function processExtends(string $content, array $context): string
    {
        return $this->extendsProcessor()->processExtends($content, $context);
    }

    // ── Cross-request extends resolution cache ──────────────────────

    /**
     * Process standalone blocks — delegated to ExtendsProcessor (D8 refactor).
     */
    private function processBlocks(string $content, array $context): string
    {
        return $this->extendsProcessor()->processBlocks($content, $context);
    }

    /**
     * Process {debug expr} — delegated to ExtendsProcessor (D8 refactor).
     */
    private function processDebugTags(string $content, array $context): string
    {
        return $this->extendsProcessor()->processDebugTags($content, $context);
    }

    // ── v4.8: User-defined macros ──────────────────────────────────

        /**
     * Extract {macro} definitions (delegated to MacroProcessor — D8).
     */
    private function extractMacros(string $content, bool $merge = false): string
    {
        return $this->macroProcessor()->extractMacros($content, $merge);
    }

    /**
     * Expand {call name(args)} (delegated to MacroProcessor — D8).
     */
    private function expandMacroCalls(string $content, array $context): string
    {
        return $this->macroProcessor()->expandMacroCalls($content, $context);
    }

    /**
     * Lazily build the MacroProcessor (D8 refactor). The engine's private
     * compile / resolve helpers are injected as closures so the processor
     * stays decoupled from TemplateEngine internals.
     */
    private function macroProcessor(): MacroProcessor
    {
        return $this->macroProcessor ??= new MacroProcessor(
            function (string $content, array $context): string {
                return $this->compile($content, $context);
            },
            function ($value, array $context) {
                return $this->resolveValue($value, $context);
            },
            function (string $expr, array $context) {
                return $this->resolveValueWithFilters($expr, $context);
            },
            function (string $message): void {
                $this->logError($message);
            }
        );
    }/**
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
            && !str_contains($content, '{while')
            && !str_contains($content, '{break}')
            && !str_contains($content, '{continue}')
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
        $allTypes = ['for', 'foreach', 'each', 'if', 'while', 'break', 'continue', 'match', 'trans', 'cache', 'experiment', 'sandbox', 'trusted', 'untrusted', 'parallel', 'await', 'suspense', 'federated_query', 'ai_generate', 'ai_query', 'ai_complete'];

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
            if ($tag['type'] === 'break' || $tag['type'] === 'continue') {
                $result .= $this->evaluateStructureBody($tag, '', $context);
                $offset = $afterOpen;
                continue;
            }
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
            'break'   => self::LOOP_BREAK_MARKER,
            'continue'=> self::LOOP_CONTINUE_MARKER,
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
        // Always recurse selected branch so loop-control tags ({break}/{continue})
        // and any nested structures are resolved before returning to parent scope.
        return $this->processControlStructuresSinglePass($chosenContent, $context);
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

        // v4.8: resolve src from expression. If it's a Promise, await it.
        // Literal strings like src='hello' are parsed by parseAttrPairs.
        $attrs = $this->parseAttrPairs($expr, $context);
        $hasSrc = array_key_exists('src', $attrs);
        $src = $attrs['src'] ?? null;

        // Resolve the source value
        $resolved = null;
        $isPromise = false;

        if ($hasSrc) {
            // parseAttrPairs already resolved the src value from context.
            // If it's a Promise, await it; otherwise use value directly.
            $resolved = $src;
            if ($resolved instanceof \Ikabud\Kernel\DiSyL\Async\Promise) {
                $isPromise = true;
            }
        } else {
            // No explicit src — resolve the entire expression as a variable
            $resolved = $this->resolveValue(trim($expr), $context);
            if ($resolved instanceof \Ikabud\Kernel\DiSyL\Async\Promise) {
                $isPromise = true;
            }
        }

        // If resolved is a Promise, await it via the scheduler
        if ($isPromise) {
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

        // Synchronous path: render body with value bound
        $let = $info['let'] ?? $this->extractLetIdentifier($expr) ?: 'value';
        $childCtx = $context;
        if ($resolved !== null) {
            $childCtx[$let] = $resolved;
        }
        return $this->compile($info['thenBody'] ?? $info['body'], $childCtx);
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
            return $this->compile($info['thenBody'] ?? $info['body'], $childCtx);
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
                $chunk = $this->compile($innerContent, $ctx);
                [$chunk, $signal] = $this->consumeLoopSignal($chunk);
                $result .= $chunk;
                // Evaluate increment — set $var = $var + 1 style
                $incResult = $this->processSetStatements('{' . $incExpr . '}', $ctx);
                $count++;
                if ($signal === 'break') {
                    break;
                }
                if ($signal === 'continue') {
                    if ($count >= $maxIterations) {
                        break;
                    }
                    continue;
                }
                if ($count >= $maxIterations) {
                    break;
                }
            }
            return $result;
        }

        // {for key, value in list} — key-value iteration
        if (preg_match('/^(\w+)\s*,\s*(\w+)\s+in\s+(.+)$/s', trim($expr), $parts)) {
            $keyName = $parts[1];
            $itemName = $parts[2];
            $listExpr = trim($parts[3]);
        } elseif (preg_match('/^(\w+)\s+in\s+(.+)$/s', trim($expr), $parts)) {
            $itemName = $parts[1];
            $listExpr = trim($parts[2]);
        } else {
            return '';
        }

        $body = $innerContent;
        $emptyContent = '';
        foreach (['{forelse}', '{empty}'] as $emptyTag) {
            if (($emptyTagPos = strpos($body, $emptyTag)) !== false) {
                $emptyContent = substr($body, $emptyTagPos + strlen($emptyTag));
                $body = substr($body, 0, $emptyTagPos);
                break;
            }
        }
        // {for}...{else}...{/for} — treat a top-level {else} as the
        // empty-collection fallback (mirrors {forelse}/{empty}). Depth-aware so
        // a nested {if}...{else}...{/if} is not mistaken for the loop else.
        if ($emptyContent === '' && ($elsePos = $this->findTopLevelForElse($body)) !== false) {
            $emptyContent = substr($body, $elsePos + strlen('{else}'));
            $body = substr($body, 0, $elsePos);
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
            // When {for key, value in list} syntax is used, expose the key as a named variable
            if (isset($keyName)) {
                $loopContext[$keyName] = $key;
            }
            $chunk = $this->compile($body, $loopContext);
            [$chunk, $signal] = $this->consumeLoopSignal($chunk);
            $result .= $chunk;
            if ($signal === 'break') {
                break;
            }
            $index++;
            if ($signal === 'continue') {
                continue;
            }
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
        foreach (['{forelse}', '{empty}'] as $emptyTag) {
            if (($emptyTagPos = strpos($body, $emptyTag)) !== false) {
                $emptyContent = substr($body, $emptyTagPos + strlen($emptyTag));
                $body = substr($body, 0, $emptyTagPos);
                break;
            }
        }
        // {foreach}...{else}...{/foreach} — see evaluateForBody note.
        if ($emptyContent === '' && ($elsePos = $this->findTopLevelForElse($body)) !== false) {
            $emptyContent = substr($body, $elsePos + strlen('{else}'));
            $body = substr($body, 0, $elsePos);
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
            $chunk = $this->compile($body, $loopContext);
            [$chunk, $signal] = $this->consumeLoopSignal($chunk);
            $result .= $chunk;
            if ($signal === 'break') {
                break;
            }
            $index++;
            if ($signal === 'continue') {
                continue;
            }
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
            $chunk = $this->compile($innerContent, $context);
            [$chunk, $signal] = $this->consumeLoopSignal($chunk);
            $result .= $chunk;
            $count++;
            if ($signal === 'break') {
                break;
            }
            if ($signal === 'continue') {
                if ($count >= $maxIterations) {
                    if (function_exists('write_log')) {
                        write_log('DiSyL {while} loop exceeded max iterations (' . $maxIterations . ')', 'warning');
                    }
                    break;
                }
                continue;
            }
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
        foreach (['{forelse}', '{empty}'] as $emptyTag) {
            if (($emptyTagPos = strpos($body, $emptyTag)) !== false) {
                $emptyContent = substr($body, $emptyTagPos + strlen($emptyTag));
                $body = substr($body, 0, $emptyTagPos);
                break;
            }
        }
        // {each}...{else}...{/each} — see evaluateForBody note.
        if ($emptyContent === '' && ($elsePos = $this->findTopLevelForElse($body)) !== false) {
            $emptyContent = substr($body, $elsePos + strlen('{else}'));
            $body = substr($body, 0, $elsePos);
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
            $chunk = $this->compile($body, $loopContext);
            [$chunk, $signal] = $this->consumeLoopSignal($chunk);
            $result .= $chunk;
            if ($signal === 'break') {
                break;
            }
            $index++;
            if ($signal === 'continue') {
                continue;
            }
        }
        return $result;
    }

    /**
     * Consume one loop-control signal marker from rendered loop content.
     *
     * @return array{0:string,1:?string} [sanitizedContent, signal]
     */
    private function consumeLoopSignal(string $content): array
    {
        $breakPos = strpos($content, self::LOOP_BREAK_MARKER);
        $continuePos = strpos($content, self::LOOP_CONTINUE_MARKER);

        if ($breakPos === false && $continuePos === false) {
            return [$content, null];
        }

        if ($breakPos !== false && ($continuePos === false || $breakPos <= $continuePos)) {
            return [substr($content, 0, $breakPos), 'break'];
        }

        return [substr($content, 0, (int)$continuePos), 'continue'];
    }

    /**
     * Locate a top-level {else} inside a loop body — the empty-collection
     * fallback for {for}/{foreach}/{each}. Skips any {else} that lives inside a
     * nested {if}/{for}/{foreach}/{each}/{while}/{with}/{apply}/{block} block,
     * so a nested {if}...{else}...{/if} is never mistaken for the loop else.
     *
     * Returns the byte offset of the {else} token, or false if none found.
     */
    private function findTopLevelForElse(string $body): int|false
    {
        $openNames = ['if', 'for', 'foreach', 'each', 'while', 'with', 'apply', 'block', 'capture', 'verbatim', 'literal'];
        $closeNames = ['if', 'for', 'foreach', 'each', 'while', 'with', 'apply', 'block', 'capture', 'verbatim', 'literal', 'cfor'];
        $depth = 0;
        $offset = 0;
        $len = strlen($body);

        while ($offset < $len && preg_match('/\{\s*(\/?)([a-z_][a-z_0-9]*)/i', $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $pos = $m[0][1];
            $tokLen = strlen($m[0][0]);
            $slash = strtolower($m[1][0]);
            $name = strtolower($m[2][0]);

            if ($slash === '/') {
                if (in_array($name, $closeNames, true) && $depth > 0) {
                    $depth--;
                }
                $offset = $pos + $tokLen;
                continue;
            }

            // Top-level {else} (no arguments) → the loop empty-fallback.
            if ($depth === 0 && $name === 'else' && substr($body, $pos + $tokLen, 1) === '}') {
                return $pos;
            }

            if (in_array($name, $openNames, true)) {
                $depth++;
            }

            $offset = $pos + $tokLen;
        }

        return false;
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
            // Allow argless control tags with immediate '}'.
            if ($nextChar === '}' && ($type === 'trusted' || $type === 'untrusted' || $type === 'parallel' || $type === 'break' || $type === 'continue')) {
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

            if ($expr === '' && $type !== 'trusted' && $type !== 'untrusted' && $type !== 'parallel' && $type !== 'break' && $type !== 'continue') {
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
    
    /** @var int Current component nesting depth (tracks compile()-within-compile() via component children) */
    private int $componentDepth = 0;

    /**
     * Process include tags — delegated to IncludeResolver (D8 refactor).
     */
    private function processIncludes(string $content, array $context): string
    {
        return $this->includeResolver()->processIncludes($content, $context);
    }

    private function readIncludeSource(string $includePath): string|false
    {
        return $this->sourceCache()->readInclude($includePath);
    }

    private function readTemplateSource(string $templatePath): string|false
    {
        return $this->sourceCache()->readTemplate($templatePath);
    }

    /**
     * Lazily build the shared SourceCache layer (D8 refactor). Cache-metric
     * counters keep incrementing the TemplateEngine aggregate static; APCu
     * availability is delegated to hasApcuCache().
     */
    private function sourceCache(): SourceCache
    {
        return $this->sourceCache ??= new SourceCache(
            $this->cacheEnabled,
            function (string $metric): void {
                TemplateRenderer::incrementMetric($metric);
            },
            function (): bool {
                return $this->hasApcuCache();
            }
        );
    }

    /**
     * Lazily build the IncludeResolver (D8 refactor). Engine private helpers
     * are injected as closures so the resolver stays decoupled; include
     * source reads flow through the shared SourceCache layer.
     */
    private function includeResolver(): IncludeResolver
    {
        return $this->includeResolver ??= new IncludeResolver(
            function (string $content, array $context): string {
                return $this->compile($content, $context);
            },
            function (string $template): string {
                return $this->resolveTemplatePath($template);
            },
            function (string $str, array $context): array {
                return $this->parseInlineObject($str, $context);
            },
            function (string $expr, array $context) {
                return $this->resolveValueWithFilters($expr, $context);
            },
            function (string $message): void {
                $this->logError($message);
            },
            function (string $includePath) {
                return $this->readIncludeSource($includePath);
            }
        );
    }

    /**
     * Lazily build the ExtendsProcessor (D8 refactor). Engine private helpers
     * are injected as closures so the processor stays decoupled; layout source
     * reads flow through the shared SourceCache layer, and the extends cache
     * directory / current template path remain owned by the engine.
     */
    private function extendsProcessor(): ExtendsProcessor
    {
        return $this->extendsProcessor ??= new ExtendsProcessor(
            function (string $template): string {
                return $this->resolveTemplatePath($template);
            },
            function (string $templatePath) {
                return $this->readTemplateSource($templatePath);
            },
            function ($value, array $context) {
                return $this->resolveValue($value, $context);
            },
            function (string $message): void {
                $this->logError($message);
            },
            function (): ?string {
                return $this->currentTemplatePath;
            },
            function (): string {
                return $this->extendsCacheDir;
            },
            function (): bool {
                return $this->cacheEnabled;
            }
        );
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

        // Inheritance safety (cycle detection, depth limits, and nearest-safe-
        // ancestor block preservation) currently lives in processExtends().
        // Keep inherited templates on that enforcing path until the compiler
        // provides the same rejection and diagnostics contract.
        if (str_contains($source, '{extends ')) {
            return true;
        }

        foreach ([
            '{cache ', '{invalidate ', '{depends_on ', '{experiment ', '{variant ', '{convert ',
            '{sandbox', '{trusted', '{untrusted', '{parallel', '{await ', '{suspense',
            '{federated_query ', '{ai_generate ', '{ai_query ', '{ai_complete ',
        ] as $interpretedOnlyTag) {
            if (str_contains($source, $interpretedOnlyTag)) {
                return true;
            }
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

                // Parenthesized pipe expression: {('literal'|filter:arg)} or
                // {('literal'|filter1|filter2)}. The outer parens wrap the whole
                // expression, which trips up the arithmetic path and splitByPipe
                // (paren depth keeps the pipe inside a single segment, and the
                // quoted-literal resolver then mangles it). Strip a balanced
                // outer pair and re-process the inner expression.
                if (str_starts_with($expr, '(') && str_ends_with($expr, ')') && str_contains($expr, '|')) {
                    $inner = trim(substr($expr, 1, -1));
                    $pDepth = 0;
                    $pBalanced = true;
                    for ($pi = 0, $pl = strlen($inner); $pi < $pl; $pi++) {
                        if ($inner[$pi] === '(') { $pDepth++; }
                        elseif ($inner[$pi] === ')') { $pDepth--; if ($pDepth < 0) { $pBalanced = false; break; } }
                    }
                    if ($pBalanced && $pDepth === 0) {
                        return $this->processVariables('{' . $inner . '}', $context);
                    }
                }

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
                if (str_contains($expr, '?') && str_contains($expr, ':')) {
                    return $this->evaluateTernary($expr, $context);
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

                    // Strict mode: warn when a variable is undefined in context
                    // (key genuinely absent), but skip when the value is merely
                    // null (a defined nullable field) or the root is {@var}-declared.
                    $varRoot = strtok($expr, '.');
                    if ($this->strictMode && $value === null && !$this->isDefined($expr, $context) && ($varRoot === false || !array_key_exists($varRoot, $this->declaredVars))) {
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

                $hasDefaultLikeFilter = false;
                foreach ($filters as $candidateFilter) {
                    $candidateFilter = trim($candidateFilter);
                    if ($candidateFilter === '') {
                        continue;
                    }
                    $candidateFilterName = trim(explode(':', $candidateFilter, 2)[0]);
                    if ($candidateFilterName === 'default' || $candidateFilterName === 'fallback') {
                        $hasDefaultLikeFilter = true;
                        break;
                    }
                }

                // Strict mode: warn when a filtered variable is undefined,
                // but skip if the variable root is declared via {@var}, a
                // default-like filter handles the missing value, or the key is
                // present (a defined nullable field).
                $filteredVarRoot = strtok($varPath, '.');
                if ($this->strictMode && !$hasDefaultLikeFilter && $value === null && !$this->isDefined($varPath, $context) && ($filteredVarRoot === false || !array_key_exists($filteredVarRoot, $this->declaredVars))) {
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

        // Quoted string literal piped through filters: {'text'|upper},
        // {'now'|date:'Y-m-d'}. The base is a literal, not a variable path.
        if (preg_match('/^["\'](?:[^"\'\\\\]|\\\\.)*["\']$/', $varPath)) {
            return true;
        }

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
     * Whether every key along the dotted path is present in context. Distinct
     * from resolveValue(): a key that exists but holds null counts as defined,
     * so strict mode does not flag legitimate nullable fields.
     */
    private function isDefined(string $path, array $context): bool
    {
        return $this->evaluator()->isDefined($path, $context);
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
        $str = trim($str);
        
        // Strip outer braces
        if (str_starts_with($str, '{') && str_ends_with($str, '}')) {
            $str = trim(substr($str, 1, -1));
        }
        
        // Split by comma at the top level only (not inside nested braces)
        $pairs = $this->splitTopLevelPairs($str);
        
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $colonPos = strpos($pair, ':');
            if ($colonPos === false) {
                continue;
            }
            $key = trim(substr($pair, 0, $colonPos));
            $rawValue = trim(substr($pair, $colonPos + 1));
            
            // Handle nested object recursively
            if (str_starts_with($rawValue, '{') && str_ends_with($rawValue, '}')) {
                $result[$key] = $this->parseInlineObject($rawValue, $context);
            } elseif (preg_match('/^["\']/', $rawValue)) {
                // Quoted literal: strip quotes and use as-is
                $cleanValue = trim($rawValue, ' "\'');
                $resolved = $this->resolveValue($cleanValue, $context);
                $result[$key] = $resolved ?? $cleanValue;
            } else {
                // DiSyL expression: resolve with full filter/operator support
                $result[$key] = $this->resolveValueWithFilters($rawValue, $context);
            }
        }
        
        return $result;
    }
    
    /**
     * Split a string by commas at the top level, respecting nested braces and quotes.
     */
    private function splitTopLevelPairs(string $str): array
    {
        $parts = [];
        $depth = 0;
        $inQuote = null;
        $current = '';
        $len = strlen($str);
        
        for ($i = 0; $i < $len; $i++) {
            $c = $str[$i];
            
            if ($inQuote !== null) {
                if ($c === '\\') {
                    $current .= $c . ($i + 1 < $len ? $str[++$i] : '');
                    continue;
                }
                if ($c === $inQuote) {
                    $inQuote = null;
                }
                $current .= $c;
                continue;
            }
            
            if ($c === '"' || $c === "'") {
                $inQuote = $c;
                $current .= $c;
                continue;
            }
            
            if ($c === '{') {
                $depth++;
                $current .= $c;
                continue;
            }
            
            if ($c === '}') {
                $depth--;
                $current .= $c;
                continue;
            }
            
            if ($c === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            
            $current .= $c;
        }
        
        if ($current !== '') {
            $parts[] = $current;
        }
        
        return $parts;
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
    public function logError(string $message): void
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
            'truncate' => fn($v, $a, $n) => mb_strlen((string)$v) > (int)($n['length'] ?? ($a[0] ?? 100))
                ? mb_substr((string)$v, 0, (int)($n['length'] ?? ($a[0] ?? 100))) . '...'
                : (string)$v,
            'nl2br' => fn($v) => nl2br(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8')),
            'json' => fn($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            // json_attr: JSON-encode then HTML-escape for safe embedding in double-quoted HTML attributes.
            // Use {myArray | json_attr} in x-data="{raw: {myArray | json_attr}}" and similar Alpine/x-* attrs.
            // Browsers decode &quot; → " before passing the attribute value to JS, so Alpine.js sees correct JSON.
            'json_attr' => fn($v) => htmlspecialchars(
                json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ENT_QUOTES,
                'UTF-8'
            ),
            'date' => fn($v, $a, $n) => $v ? date($n['format'] ?? ($a[0] ?? 'Y-m-d'), is_numeric($v) ? (int)$v : strtotime((string)$v)) : '',
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

        // Component namespace resolution: "workbench:app_shell" → registered component dir
        $componentPath = $this->resolveComponentNamespacePath($template);
        if ($componentPath !== null) {
            return $componentPath;
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
     * Resolve "namespace:component" include paths to registered component directories.
     * Example: "workbench:app_shell" → "{componentDirs['workbench']}/app_shell.disyl"
     */
    private function resolveComponentNamespacePath(string $template): ?string
    {
        $colonPos = strpos($template, ':');
        if ($colonPos === false) {
            return null;
        }

        $namespace = substr($template, 0, $colonPos);
        $componentName = substr($template, $colonPos + 1);

        if (!isset($this->componentDirs[$namespace])) {
            return null;
        }

        $baseDir = $this->componentDirs[$namespace];

        // If componentName already includes a category: "shell/app_shell" → direct match
        if (str_contains($componentName, '/')) {
            $candidates = [
                $baseDir . '/' . $componentName,
                $baseDir . '/' . $componentName . '/index.disyl',
            ];
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
            return null;
        }

        // Scan subdirectories for the component file
        $dirs = glob($baseDir . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return null;
        }

        foreach ($dirs as $dir) {
            $candidate = $dir . '/' . $componentName;
            if (is_file($candidate)) {
                return $candidate;
            }
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
    private ?ComponentRenderer $componentRenderer = null;

    /**
     * Lazily build the ComponentRenderer (D8 refactor).
     */
    private function componentRenderer(): ComponentRenderer
    {
        return $this->componentRenderer ??= new ComponentRenderer($this);
    }

    /**
     * Render a DiSyL component. Delegates to ComponentRenderer (D8 refactor).
     */
    private function renderComponent(string $component, array $attrs, string $children, array $context): string
    {
        return $this->componentRenderer()->renderComponent($component, $attrs, $children, $context);
    }

    /**
     * Internal accessor for ComponentRenderer: the custom component registry.
     */
    public function internalComponents(): array
    {
        return $this->components;
    }

    /**
     * Debug flag accessor for ComponentRenderer.
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

}
