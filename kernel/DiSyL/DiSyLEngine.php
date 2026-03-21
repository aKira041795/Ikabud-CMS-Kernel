<?php
/**
 * DiSyL Unified Template Engine v11.1
 * 
 * Main entry point combining all DiSyL features from v4-v11.
 * 
 * v11.0 Features:
 * - Advanced Type System (generics, union/intersection, conditional types)
 * - Signal-based Reactive System (fine-grained reactivity)
 * - Pattern Matching syntax with guards
 * - Async Template Support (suspense, streaming)
 * - Plugin Architecture (extensibility)
 * - Debug/Profiling System
 * - i18n System (translations, pluralization)
 * - Error Recovery (graceful degradation)
 * 
 * v11.1 Features (Industry-Leading):
 * - AI-Native Template Generation
 * - Visual Debugger with Performance Heatmaps
 * - Built-in A/B Testing & Experimentation
 * - Smart Cache with Dependency Tracking
 * - Security Sandbox System
 * - Cross-Instance Content Federation
 * 
 * @package Ikabud\Kernel\DiSyL
 * @version 11.1.0
 */

namespace Ikabud\Kernel\DiSyL;

use Ikabud\Kernel\DiSyL\v4\Parser;
use Ikabud\Kernel\DiSyL\v4\FilterRegistry;
use Ikabud\Kernel\DiSyL\v4\CMSRenderer;
use Ikabud\Kernel\DiSyL\v4\StreamingRenderer;
use Ikabud\Kernel\DiSyL\v4\AST\DocumentNode;
use Ikabud\Kernel\DiSyL\CMS\CMSAdapterInterface;
use Ikabud\Kernel\DiSyL\CMS\NullAdapter;
use Ikabud\Kernel\DiSyL\Compiler\TemplateCache;
use Ikabud\Kernel\DiSyL\Compiler\IncrementalCompiler;
use Ikabud\Kernel\DiSyL\Compiler\TreeShaker;
use Ikabud\Kernel\DiSyL\Component\ComponentLoader;
use Ikabud\Kernel\DiSyL\Security\AutoEscaper;
use Ikabud\Kernel\DiSyL\Types\TypeChecker;
use Ikabud\Kernel\DiSyL\SourceMap\SourceMap;
use Ikabud\Kernel\DiSyL\Reactive\ReactiveState;
use Ikabud\Kernel\DiSyL\Reactive\ClientBlockRegistry;
use Ikabud\Kernel\DiSyL\Reactive\Store;
use Ikabud\Kernel\DiSyL\Islands\IslandRegistry;
use Ikabud\Kernel\DiSyL\Targets\JavaScriptCompiler;
use Ikabud\Kernel\DiSyL\Targets\PythonCompiler;
use Ikabud\Kernel\DiSyL\Targets\EdgeRuntime;
use Ikabud\Kernel\DiSyL\AI\AITemplateAssistant;
use Ikabud\Kernel\DiSyL\Framework\Router;
use Ikabud\Kernel\DiSyL\Framework\Auth;
use Ikabud\Kernel\DiSyL\Plugin\PluginManager;
use Ikabud\Kernel\DiSyL\Plugin\PluginLoader;
use Ikabud\Kernel\DiSyL\Debug\Profiler;
use Ikabud\Kernel\DiSyL\Debug\DebugToolbar;
use Ikabud\Kernel\DiSyL\I18n\Translator;
use Ikabud\Kernel\DiSyL\ErrorRecovery\ErrorRecoveryManager;
use Ikabud\Kernel\DiSyL\ErrorRecovery\FallbackRenderer;
use Ikabud\Kernel\DiSyL\AI\AIEngine;
use Ikabud\Kernel\DiSyL\Debug\VisualDebugger;
use Ikabud\Kernel\DiSyL\Experiment\ExperimentManager;
use Ikabud\Kernel\DiSyL\Cache\SmartCache;
use Ikabud\Kernel\DiSyL\Cache\DependsOn;
use Ikabud\Kernel\DiSyL\Security\Sandbox;
use Ikabud\Kernel\DiSyL\Security\SecurityPolicy;
use Ikabud\Kernel\DiSyL\Federation\ContentFederation;
use Ikabud\Kernel\DiSyL\Federation\RemoteInstance;
use Ikabud\Kernel\DiSyL\Runtime\TemplateRuntime;
use Ikabud\Kernel\DiSyL\Runtime\CompilationRuntime;
use Ikabud\Kernel\DiSyL\Runtime\ExecutionRuntime;
use Ikabud\Kernel\DiSyL\Services\AIService;
use Ikabud\Kernel\DiSyL\Services\SecurityService;
use Ikabud\Kernel\DiSyL\Services\FederationService;
use Ikabud\Kernel\DiSyL\Services\ExperimentService;

class DiSyLEngine
{
    // Core components
    private Parser $parser;
    private FilterRegistry $filters;
    private CMSAdapterInterface $cms;
    private AutoEscaper $escaper;
    
    // Caching & Compilation
    private ?TemplateCache $cache = null;
    private ?IncrementalCompiler $incrementalCompiler = null;
    private ?TreeShaker $treeShaker = null;
    
    // Components
    private ?ComponentLoader $components = null;
    
    // Type System (v5)
    private ?TypeChecker $typeChecker = null;
    private bool $strictTypes = false;
    
    // Reactive (v6)
    private ?IslandRegistry $islands = null;
    private ?ClientBlockRegistry $clientBlocks = null;
    
    // Multi-target (v8)
    private ?JavaScriptCompiler $jsCompiler = null;
    private ?PythonCompiler $pyCompiler = null;
    private ?EdgeRuntime $edgeRuntime = null;
    
    // AI (v9)
    private ?AITemplateAssistant $ai = null;
    
    // Framework (v10)
    private ?Router $router = null;
    private ?Auth $auth = null;
    
    // v11: Plugin System
    private ?PluginManager $plugins = null;
    
    // v11: Debug/Profiling
    private ?Profiler $profiler = null;
    private ?DebugToolbar $debugToolbar = null;
    
    // v11: i18n
    private ?Translator $translator = null;
    
    // v11: Error Recovery
    private ?ErrorRecoveryManager $errorRecovery = null;
    private ?FallbackRenderer $fallbackRenderer = null;
    
    // v11.1: AI Engine
    private ?AIEngine $aiEngine = null;
    
    // v11.1: Visual Debugger
    private ?VisualDebugger $visualDebugger = null;
    
    // v11.1: Experimentation
    private ?ExperimentManager $experiments = null;
    
    // v11.1: Smart Cache
    private ?SmartCache $smartCache = null;
    
    // v11.1: Security Sandbox
    private ?Sandbox $sandbox = null;
    
    // v11.1: Content Federation
    private ?ContentFederation $federation = null;
    
    // v11.1: Service Layer (Kernel Facade)
    private ?TemplateRuntime $templateRuntime = null;
    private ?CompilationRuntime $compilationRuntime = null;
    private ?ExecutionRuntime $executionRuntime = null;
    private ?AIService $aiService = null;
    private ?SecurityService $securityService = null;
    private ?FederationService $federationService = null;
    private ?ExperimentService $experimentService = null;
    
    // Configuration
    private array $templateDirs = [];
    private bool $debug = false;
    private bool $autoEscape = true;
    private bool $errorRecoveryEnabled = false;
    
    public function __construct(?CMSAdapterInterface $cms = null)
    {
        $this->parser = new Parser();
        $this->filters = new FilterRegistry();
        $this->cms = $cms ?? new NullAdapter();
        $this->escaper = new AutoEscaper();
        $this->escaper->registerFilters($this->filters);
    }
    
    // ========== Configuration ==========
    
    public function setCMS(CMSAdapterInterface $cms): self
    {
        $this->cms = $cms;
        return $this;
    }
    
    public function addTemplateDir(string $path, string $namespace = ''): self
    {
        $this->templateDirs[$namespace] = rtrim($path, '/');
        return $this;
    }
    
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }
    
    public function setAutoEscape(bool $enabled): self
    {
        $this->autoEscape = $enabled;
        return $this;
    }
    
    public function addFilter(string $name, callable $filter): self
    {
        $this->filters->register($name, $filter);
        return $this;
    }
    
    // ========== v5: Type System ==========
    
    public function enableTypeChecking(bool $strict = false): self
    {
        $this->typeChecker = new TypeChecker($strict);
        $this->strictTypes = $strict;
        return $this;
    }
    
    public function checkTypes(string $source): array
    {
        if (!$this->typeChecker) {
            $this->enableTypeChecking();
        }
        $ast = $this->parser->parse($source);
        $result = $this->typeChecker->check($ast->toArray());
        return $result->getErrors();
    }
    
    // ========== v6: Reactive & Islands ==========
    
    public function enableIslands(): self
    {
        $this->islands = new IslandRegistry();
        $this->clientBlocks = new ClientBlockRegistry();
        return $this;
    }
    
    public function getIslands(): ?IslandRegistry
    {
        return $this->islands;
    }
    
    public function getClientBlocks(): ?ClientBlockRegistry
    {
        return $this->clientBlocks;
    }
    
    // ========== v7: Compilation ==========
    
    public function enableCache(string $cacheDir): self
    {
        $this->cache = new TemplateCache($cacheDir, $this->debug);
        return $this;
    }
    
    public function enableIncrementalCompilation(string $cacheDir): self
    {
        $this->incrementalCompiler = new IncrementalCompiler($cacheDir);
        return $this;
    }
    
    public function enableTreeShaking(): self
    {
        $this->treeShaker = new TreeShaker();
        return $this;
    }
    
    // ========== v8: Multi-Target ==========
    
    public function compileToJS(string $source, string $name = 'Template'): string
    {
        if (!$this->jsCompiler) {
            $this->jsCompiler = new JavaScriptCompiler();
        }
        $ast = $this->parser->parse($source);
        return $this->jsCompiler->compile($ast, $name);
    }
    
    public function compileToPython(string $source, string $name = 'template'): string
    {
        if (!$this->pyCompiler) {
            $this->pyCompiler = new PythonCompiler();
        }
        $ast = $this->parser->parse($source);
        return $this->pyCompiler->compile($ast, $name);
    }
    
    public function compileToEdge(string $source, string $target = 'cloudflare'): string
    {
        if (!$this->edgeRuntime) {
            $this->edgeRuntime = new EdgeRuntime($target);
        }
        $ast = $this->parser->parse($source);
        return $this->edgeRuntime->compile($ast);
    }
    
    // ========== v9: AI ==========
    
    public function enableAI(?string $apiKey = null): self
    {
        $this->ai = new AITemplateAssistant($apiKey);
        return $this;
    }
    
    public function parseNaturalQuery(string $query): string
    {
        if (!$this->ai) {
            $this->enableAI();
        }
        return $this->ai->parseNaturalQuery($query);
    }
    
    public function suggestCompletion(string $code, int $cursor): array
    {
        if (!$this->ai) {
            $this->enableAI();
        }
        return $this->ai->suggestCompletion($code, $cursor);
    }
    
    // ========== v10: Framework ==========
    
    public function enableFramework(string $pagesDir): self
    {
        $this->router = new Router($pagesDir);
        $this->auth = new Auth();
        return $this;
    }
    
    public function getRouter(): ?Router
    {
        return $this->router;
    }
    
    public function getAuth(): ?Auth
    {
        return $this->auth;
    }
    
    public function handleRequest(string $path): ?string
    {
        if (!$this->router) {
            return null;
        }
        
        $match = $this->router->match($path);
        if (!$match) {
            return null;
        }
        
        $route = $match['route'];
        $params = $match['params'];
        
        return $this->render($route['file'], [
            'params' => $params,
            'auth' => $this->auth,
        ]);
    }
    
    // ========== Core Rendering ==========
    
    public function render(string $template, array $variables = []): string
    {
        // Use compiled cache if available
        if ($this->cache !== null) {
            $path = $this->findTemplate($template);
            if ($path !== null) {
                $compiled = $this->cache->get($path);
                $compiled->setCMS($this->cms);
                $compiled->setTemplateLoader(fn($name) => $this->loadTemplate($name));
                return $compiled->execute($variables);
            }
        }
        
        // Otherwise use interpreted rendering
        $ast = $this->loadTemplate($template);
        if ($ast === null) {
            throw new \RuntimeException("Template not found: {$template}");
        }
        
        // Tree shake if enabled
        if ($this->treeShaker) {
            $ast = $this->treeShaker->shake($ast);
        }
        
        return $this->renderAST($ast, $variables);
    }
    
    public function renderString(string $source, array $variables = []): string
    {
        $ast = $this->parser->parse($source);
        
        if ($this->treeShaker) {
            $ast = $this->treeShaker->shake($ast);
        }
        
        return $this->renderAST($ast, $variables);
    }
    
    public function renderAST(DocumentNode $ast, array $variables = []): string
    {
        $renderer = new CMSRenderer($this->cms, $this->filters);
        $renderer->setAutoEscape($this->autoEscape);
        $renderer->setTemplateLoader(fn($name) => $this->loadTemplate($name));
        
        return $renderer->render($ast, $variables);
    }
    
    public function stream(string $template, array $variables = []): \Generator
    {
        $ast = $this->loadTemplate($template);
        if ($ast === null) {
            throw new \RuntimeException("Template not found: {$template}");
        }
        
        $renderer = new StreamingRenderer($this->cms, $this->filters);
        $renderer->setAutoEscape($this->autoEscape);
        $renderer->setTemplateLoader(fn($name) => $this->loadTemplate($name));
        
        yield from $renderer->stream($ast, $variables);
    }
    
    public function parse(string $source, string $name = ''): DocumentNode
    {
        return $this->parser->parse($source, $name);
    }
    
    public function loadTemplate(string $name): ?DocumentNode
    {
        $path = $this->findTemplate($name);
        if ($path === null) {
            return null;
        }
        
        $source = file_get_contents($path);
        return $this->parser->parse($source, $path);
    }
    
    private function findTemplate(string $name): ?string
    {
        // Namespaced template (@namespace/template)
        if (str_starts_with($name, '@')) {
            $parts = explode('/', substr($name, 1), 2);
            $namespace = $parts[0];
            $template = $parts[1] ?? 'index';
            
            if (isset($this->templateDirs[$namespace])) {
                $path = $this->templateDirs[$namespace] . '/' . $template;
                if (!str_ends_with($path, '.disyl')) $path .= '.disyl';
                if (file_exists($path)) return $path;
            }
            return null;
        }
        
        // Search all directories
        foreach ($this->templateDirs as $dir) {
            $path = $dir . '/' . $name;
            if (!str_ends_with($path, '.disyl')) $path .= '.disyl';
            if (file_exists($path)) return $path;
        }
        
        // Absolute path
        if (file_exists($name)) return $name;
        
        return null;
    }
    
    public function clearCache(): void
    {
        $this->cache?->clear();
    }
    
    public function getVersion(): string
    {
        return '11.1.0';
    }
    
    // ========== v11: Plugin System ==========
    
    public function enablePlugins(?string $pluginDir = null): self
    {
        $this->plugins = new PluginManager();
        
        if ($pluginDir !== null) {
            $loader = new PluginLoader($pluginDir, $this->plugins);
            $loader->discover();
        }
        
        $this->plugins->load();
        $this->plugins->boot();
        
        // Register plugin filters
        foreach ($this->plugins->getFilters() as $name => $filter) {
            $this->filters->register($name, $filter);
        }
        
        return $this;
    }
    
    public function getPlugins(): ?PluginManager
    {
        return $this->plugins;
    }
    
    // ========== v11: Debug/Profiling ==========
    
    public function enableProfiling(): self
    {
        $this->profiler = Profiler::getInstance();
        $this->profiler->enable();
        return $this;
    }
    
    public function enableDebugToolbar(): self
    {
        $this->debugToolbar = new DebugToolbar();
        $this->debugToolbar->enable();
        $this->enableProfiling();
        return $this;
    }
    
    public function getProfiler(): ?Profiler
    {
        return $this->profiler;
    }
    
    public function getDebugToolbar(): ?DebugToolbar
    {
        return $this->debugToolbar;
    }
    
    // ========== v11: i18n ==========
    
    public function enableI18n(string $locale = 'en', string $fallback = 'en'): self
    {
        $this->translator = new Translator($locale, $fallback);
        
        // Register translation filters
        $this->filters->register('trans', fn($key, $params = []) => 
            $this->translator->trans($key, $params)
        );
        $this->filters->register('transChoice', fn($key, $count, $params = []) => 
            $this->translator->transChoice($key, $count, $params)
        );
        
        return $this;
    }
    
    public function setLocale(string $locale): self
    {
        $this->translator?->setLocale($locale);
        return $this;
    }
    
    public function getTranslator(): ?Translator
    {
        return $this->translator;
    }
    
    public function addTranslations(array $translations, string $locale, string $domain = 'messages'): self
    {
        $this->translator?->addTranslations($translations, $locale, $domain);
        return $this;
    }
    
    // ========== v11: Error Recovery ==========
    
    public function enableErrorRecovery(bool $showErrors = true): self
    {
        $this->errorRecoveryEnabled = true;
        $this->errorRecovery = new ErrorRecoveryManager();
        $this->fallbackRenderer = new FallbackRenderer();
        $this->fallbackRenderer->setShowErrors($showErrors);
        return $this;
    }
    
    public function setFallbackContent(string $content): self
    {
        $this->fallbackRenderer?->setFallbackContent($content);
        return $this;
    }
    
    /**
     * Render with error recovery
     */
    public function renderSafe(string $template, array $variables = []): string
    {
        $this->profiler?->startTimer('render:' . $template);
        
        try {
            $result = $this->render($template, $variables);
            
            // Append debug toolbar if enabled
            if ($this->debugToolbar !== null) {
                $result .= $this->debugToolbar->render();
            }
            
            return $result;
        } catch (\Throwable $e) {
            if ($this->fallbackRenderer !== null) {
                return $this->fallbackRenderer->render($e);
            }
            throw $e;
        } finally {
            $this->profiler?->stopTimer('render:' . $template);
        }
    }
    
    /**
     * Render string with error recovery
     */
    public function renderStringSafe(string $source, array $variables = []): string
    {
        $this->profiler?->startTimer('renderString');
        
        try {
            return $this->renderString($source, $variables);
        } catch (\Throwable $e) {
            if ($this->fallbackRenderer !== null) {
                return $this->fallbackRenderer->render($e);
            }
            throw $e;
        } finally {
            $this->profiler?->stopTimer('renderString');
        }
    }
    
    // ========== v11: Advanced Features ==========
    
    /**
     * Create a reactive store for template state
     */
    public function createStore(string $name, array $initialState = []): object
    {
        return new \Ikabud\Kernel\DiSyL\Reactive\Store($name, $initialState);
    }
    
    /**
     * Get engine statistics
     */
    public function getStats(): array
    {
        $stats = [
            'version' => $this->getVersion(),
            'debug' => $this->debug,
            'template_dirs' => count($this->templateDirs),
            'filters' => $this->filters->count(),
        ];
        
        if ($this->profiler !== null) {
            $report = $this->profiler->getReport();
            $stats['profiler'] = [
                'total_time_ms' => $report->totalTime * 1000,
                'memory_used' => $report->totalMemory,
            ];
        }
        
        if ($this->plugins !== null) {
            $stats['plugins'] = count($this->plugins->getPlugins());
        }
        
        return $stats;
    }
    
    // ========== v11.1: AI Engine (Advanced) ==========
    
    /**
     * Enable advanced AI-powered template features (v11.1)
     */
    public function enableAdvancedAI(): AIEngine
    {
        $this->aiEngine = new AIEngine();
        return $this->aiEngine;
    }
    
    /**
     * Get advanced AI engine instance
     */
    public function getAdvancedAI(): ?AIEngine
    {
        return $this->aiEngine;
    }
    
    /**
     * Generate template from natural language
     */
    public function generateTemplate(string $description, array $context = []): string
    {
        if ($this->aiEngine === null) {
            throw new \RuntimeException('Advanced AI engine not enabled. Call enableAdvancedAI() first.');
        }
        $result = $this->aiEngine->generate($description, $context);
        return $result->isSuccess() ? $result->template : '';
    }
    
    // ========== v11.1: Visual Debugger ==========
    
    /**
     * Enable visual debugging with overlays
     */
    public function enableVisualDebugger(): self
    {
        $this->visualDebugger = new VisualDebugger();
        $this->visualDebugger->enable();
        return $this;
    }
    
    /**
     * Get visual debugger instance
     */
    public function getVisualDebugger(): ?VisualDebugger
    {
        return $this->visualDebugger;
    }
    
    // ========== v11.1: A/B Testing ==========
    
    /**
     * Enable experimentation/A/B testing
     */
    public function enableExperiments(): ExperimentManager
    {
        $this->experiments = new ExperimentManager();
        return $this->experiments;
    }
    
    /**
     * Get experiment manager
     */
    public function getExperiments(): ?ExperimentManager
    {
        return $this->experiments;
    }
    
    /**
     * Get variant for an experiment (for current user)
     */
    public function getVariant(string $experimentId): ?object
    {
        return $this->experiments?->getVariant($experimentId);
    }
    
    /**
     * Record a conversion for an experiment
     */
    public function trackConversion(string $experimentId, string $goal, float $value = 1.0): void
    {
        $this->experiments?->convert($experimentId, $goal, $value);
    }
    
    // ========== v11.1: Smart Cache ==========
    
    /**
     * Enable smart caching with dependency tracking
     */
    public function enableSmartCache(?string $cacheDir = null): SmartCache
    {
        $backend = $cacheDir !== null 
            ? new \Ikabud\Kernel\DiSyL\Cache\FileCacheBackend($cacheDir)
            : null;
        $this->smartCache = new SmartCache($backend);
        return $this->smartCache;
    }
    
    /**
     * Get smart cache instance
     */
    public function getSmartCache(): ?SmartCache
    {
        return $this->smartCache;
    }
    
    /**
     * Cache template output with dependencies
     */
    public function cacheRemember(string $key, callable $callback, ?int $ttl = null, array $dependsOn = []): mixed
    {
        if ($this->smartCache === null) {
            return $callback();
        }
        
        $dependency = null;
        if (!empty($dependsOn)) {
            $dependency = DependsOn::keys($dependsOn);
        }
        
        return $this->smartCache->remember($key, $callback, $ttl, $dependency);
    }
    
    // ========== v11.1: Security Sandbox ==========
    
    /**
     * Enable security sandbox
     */
    public function enableSandbox(SecurityPolicy $policy): self
    {
        $this->sandbox = new Sandbox();
        $this->sandbox->enable($policy);
        return $this;
    }
    
    /**
     * Enable sandbox with preset policy
     */
    public function enableStrictSandbox(): self
    {
        return $this->enableSandbox(SecurityPolicy::strict());
    }
    
    /**
     * Enable sandbox with moderate policy
     */
    public function enableModerateSandbox(): self
    {
        return $this->enableSandbox(SecurityPolicy::moderate());
    }
    
    /**
     * Get sandbox instance
     */
    public function getSandbox(): ?Sandbox
    {
        return $this->sandbox;
    }
    
    // ========== v11.1: Content Federation ==========
    
    /**
     * Enable cross-instance content federation
     */
    public function enableFederation(?string $cacheDir = null): ContentFederation
    {
        $cache = $cacheDir !== null 
            ? new \Ikabud\Kernel\DiSyL\Federation\FederationCache(300, $cacheDir)
            : null;
        $this->federation = new ContentFederation($cache);
        return $this->federation;
    }
    
    /**
     * Get federation instance
     */
    public function getFederation(): ?ContentFederation
    {
        return $this->federation;
    }
    
    /**
     * Add a remote CMS instance for federation
     */
    public function addRemoteInstance(string $id, string $name, string $baseUrl, string $apiKey, string $platform = 'ikabud'): self
    {
        if ($this->federation === null) {
            $this->enableFederation();
        }
        
        $this->federation->addInstance(new RemoteInstance($id, $name, $baseUrl, $apiKey, $platform));
        return $this;
    }
    
    /**
     * Query content from a remote instance
     */
    public function federatedQuery(string $instanceId, string $contentType, array $params = []): array
    {
        if ($this->federation === null) {
            return [];
        }
        
        $result = $this->federation->query($instanceId, $contentType, $params);
        return $result->isSuccess() ? $result->data : [];
    }
    
    // ========== v11.1: Service Layer (Kernel Facade) ==========
    
    /**
     * Get or create TemplateRuntime service
     */
    public function getTemplateRuntime(): TemplateRuntime
    {
        if ($this->templateRuntime === null) {
            $this->templateRuntime = new TemplateRuntime($this->cms);
            foreach ($this->templateDirs as $ns => $dir) {
                $this->templateRuntime->addTemplateDir($dir, $ns);
            }
            $this->templateRuntime->setDebug($this->debug);
        }
        return $this->templateRuntime;
    }
    
    /**
     * Get or create CompilationRuntime service
     */
    public function getCompilationRuntime(): CompilationRuntime
    {
        if ($this->compilationRuntime === null) {
            $this->compilationRuntime = new CompilationRuntime();
        }
        return $this->compilationRuntime;
    }
    
    /**
     * Get or create ExecutionRuntime service
     */
    public function getExecutionRuntime(): ExecutionRuntime
    {
        if ($this->executionRuntime === null) {
            $this->executionRuntime = new ExecutionRuntime();
        }
        return $this->executionRuntime;
    }
    
    /**
     * Get or create AIService
     */
    public function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = new AIService();
        }
        return $this->aiService;
    }
    
    /**
     * Get or create SecurityService
     */
    public function getSecurityService(): SecurityService
    {
        if ($this->securityService === null) {
            $this->securityService = new SecurityService();
        }
        return $this->securityService;
    }
    
    /**
     * Get or create FederationService (with guardrails)
     */
    public function getFederationService(): FederationService
    {
        if ($this->federationService === null) {
            $this->federationService = new FederationService();
        }
        return $this->federationService;
    }
    
    /**
     * Get or create ExperimentService (resolve before render pattern)
     */
    public function getExperimentService(): ExperimentService
    {
        if ($this->experimentService === null) {
            $this->experimentService = new ExperimentService();
        }
        return $this->experimentService;
    }
    
    /**
     * Shutdown engine and cleanup
     */
    public function shutdown(): void
    {
        $this->plugins?->shutdown();
        $this->profiler?->disable();
        $this->smartCache?->processRevalidationQueue();
        $this->executionRuntime?->shutdown();
        $this->experimentService?->processConversions();
    }
}
