<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\SourceContext;

/**
 * Source Retrieval Layer.
 *
 * When a chain link step fails, retrieves only the relevant source files
 * instead of dumping the whole repo. Maps step patterns to specific
 * handler/service/template files based on the module structure.
 *
 * Step-to-source mapping:
 *   - http.request / http.response_ok  → route map + handler
 *   - workflow.transition              → workflow engine + service class
 *   - ui.status_updated                → template + presenter
 *   - db.*                             → migration SQL + entity model
 *   - audit.*                          → audit service + event config
 *   - event.* / email.*               → event listener + mailer
 *   - approval.*                       → approval service + handler
 *   - capability.*                     → capability registry + provider
 */
class SourceRetriever
{
    private string $moduleId;
    private string $modulePath;
    private string $basePath;

    /** @var array<string, array{handlers: string[], templates: string[], migrations: string[]}> */
    private array $stepSourceMap = [];

    public function __construct(string $moduleId)
    {
        $this->moduleId = $moduleId;
        $this->basePath = realpath(__DIR__ . '/../../../../');
        $this->modulePath = $this->basePath . '/modules/' . $moduleId;
        $this->buildSourceMap();
    }

    /**
     * Retrieve source context for a specific failed step.
     */
    public function retrieve(string $step, string $category = 'unknown'): SourceContext
    {
        // 1. Direct step match in source map
        foreach ($this->stepSourceMap as $pattern => $sources) {
            if (fnmatch($pattern, $step) || fnmatch($pattern, $category . '.' . $step)) {
                return new SourceContext(
                    step: $step,
                    category: $category,
                    handlerFiles: $this->resolvePaths($sources['handlers']),
                    templateFiles: $this->resolvePaths($sources['templates']),
                    routeInfo: $this->getRouteInfo(),
                    migrationFiles: $this->resolvePaths($sources['migrations']),
                    logSnippets: $this->getLogSnippets($category),
                );
            }
        }

        // 2. Category-based fallback
        $defaults = $this->getDefaultsForCategory($category);
        if (!empty($defaults)) {
            return new SourceContext(
                step: $step,
                category: $category,
                handlerFiles: $this->resolvePaths($defaults['handlers']),
                templateFiles: $this->resolvePaths($defaults['templates']),
                routeInfo: $this->getRouteInfo(),
                migrationFiles: $this->resolvePaths($defaults['migrations']),
                logSnippets: $this->getLogSnippets($category),
            );
        }

        // 3. Empty — no mapping known
        return new SourceContext(step: $step, category: $category);
    }

    /**
     * Build the step-to-source mapping from module structure conventions.
     */
    private function buildSourceMap(): void
    {
        // HTTP layer
        $this->stepSourceMap['http.request'] = [
            'handlers' => ['handlers/*.php'],
            'templates' => [],
            'migrations' => [],
        ];
        $this->stepSourceMap['http.response_ok'] = [
            'handlers' => ['handlers/*.php'],
            'templates' => [],
            'migrations' => [],
        ];

        // Workflow/service layer
        $this->stepSourceMap['workflow.transition'] = [
            'handlers' => ['services/*.php', 'handlers/*.php'],
            'templates' => [],
            'migrations' => [],
        ];
        $this->stepSourceMap['workflow.*'] = [
            'handlers' => ['services/*.php', 'handlers/*.php'],
            'templates' => [],
            'migrations' => [],
        ];
        $this->stepSourceMap['service.*'] = [
            'handlers' => ['services/*.php'],
            'templates' => [],
            'migrations' => [],
        ];

        // DB layer
        $this->stepSourceMap['db.*'] = [
            'handlers' => [],
            'templates' => [],
            'migrations' => ['database/migrations/*.sql'],
        ];
        $this->stepSourceMap['db.status_change'] = [
            'handlers' => ['services/*.php', 'handlers/*.php'],
            'templates' => [],
            'migrations' => ['database/migrations/*.sql'],
        ];

        // UI layer
        $this->stepSourceMap['ui.*'] = [
            'handlers' => [],
            'templates' => ['templates/modules/' . $this->moduleId . '/pages/*.disyl'],
            'migrations' => [],
        ];
        $this->stepSourceMap['button.*'] = [
            'handlers' => [],
            'templates' => ['templates/modules/' . $this->moduleId . '/pages/*.disyl'],
            'migrations' => [],
        ];

        // Audit layer
        $this->stepSourceMap['audit.*'] = [
            'handlers' => ['handlers/*.php'],
            'templates' => [],
            'migrations' => [],
        ];

        // Event layer
        $this->stepSourceMap['event.*'] = [
            'handlers' => ['handlers/*.php'],
            'templates' => [],
            'migrations' => [],
        ];

        // Email layer
        $this->stepSourceMap['email.*'] = [
            'handlers' => ['services/*.php', 'handlers/*.php'],
            'templates' => ['templates/modules/' . $this->moduleId . '/emails/*.disyl'],
            'migrations' => [],
        ];

        // Approval layer
        $this->stepSourceMap['approval.*'] = [
            'handlers' => ['handlers/*approval*.php'],
            'templates' => ['templates/modules/' . $this->moduleId . '/pages/*approval*.disyl'],
            'migrations' => ['database/migrations/*approval*.sql'],
        ];

        // Verify layer (post-action checks)
        $this->stepSourceMap['verify.*'] = [
            'handlers' => [],
            'templates' => ['templates/modules/' . $this->moduleId . '/pages/*.disyl'],
            'migrations' => [],
        ];
    }

    /**
     * Get default source files for a category when no specific step pattern matches.
     */
    private function getDefaultsForCategory(string $category): array
    {
        return match ($category) {
            'ui', 'verify' => [
                'handlers' => [],
                'templates' => ['templates/modules/' . $this->moduleId . '/pages/*.disyl'],
                'migrations' => [],
            ],
            'http' => [
                'handlers' => ['handlers/*.php'],
                'templates' => [],
                'migrations' => [],
            ],
            'service' => [
                'handlers' => ['services/*.php', 'handlers/*.php'],
                'templates' => [],
                'migrations' => [],
            ],
            'db' => [
                'handlers' => ['handlers/*.php', 'services/*.php'],
                'templates' => [],
                'migrations' => ['database/migrations/*.sql'],
            ],
            'event' => [
                'handlers' => ['handlers/*.php'],
                'templates' => [],
                'migrations' => [],
            ],
            'audit' => [
                'handlers' => [],
                'templates' => [],
                'migrations' => [],
            ],
            default => ['handlers' => [], 'templates' => [], 'migrations' => []],
        };
    }

    /**
     * Resolve glob patterns to actual file paths.
     */
    private function resolvePaths(array $patterns): array
    {
        $paths = [];
        foreach ($patterns as $pattern) {
            $fullPattern = $this->modulePath . '/' . $pattern;
            $globResults = glob($fullPattern) ?: [];
            foreach ($globResults as $result) {
                $rel = str_replace($this->basePath . '/', '', $result);
                $paths[] = $rel;
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * Get route registration info for the module.
     */
    private function getRouteInfo(): array
    {
        $routeFile = $this->modulePath . '/routes.php';
        if (file_exists($routeFile)) {
            return ['modules/' . $this->moduleId . '/routes.php'];
        }
        return [];
    }

    /**
     * Get recent log snippets relevant to a failure category.
     */
    private function getLogSnippets(string $category): array
    {
        $snippets = [];

        // App log
        $appLog = $this->basePath . '/storage/logs/app.log';
        if (file_exists($appLog)) {
            $lines = $this->tailFile($appLog, 30);
            $filtered = $this->filterLinesByCategory($lines, $category);
            if (!empty($filtered)) {
                $snippets[] = 'app.log (last 30 lines, filtered by category):';
                $snippets = array_merge($snippets, $filtered);
            }
        }

        // Error log
        $errorLog = $this->basePath . '/storage/logs/error.log';
        if (file_exists($errorLog)) {
            $lines = $this->tailFile($errorLog, 20);
            if (!empty($lines)) {
                $snippets[] = 'error.log (last 20 lines):';
                $snippets = array_merge($snippets, $lines);
            }
        }

        return $snippets;
    }

    /**
     * Read the last N lines of a file.
     */
    private function tailFile(string $path, int $lines): array
    {
        $content = file($path, FILE_IGNORE_NEW_LINES);
        if ($content === false) {
            return [];
        }
        return array_slice($content, -$lines);
    }

    /**
     * Filter log lines by relevance to a category.
     */
    private function filterLinesByCategory(array $lines, string $category): array
    {
        $keywords = match ($category) {
            'ui' => ['template', 'render', 'view', 'disyl', 'compile'],
            'http' => ['route', 'handler', 'request', 'response', 'csrf', 'token', '419', '403', '404', '500'],
            'service' => ['service', 'workflow', 'transition', 'process'],
            'db' => ['sql', 'query', 'database', 'constraint', 'migration', 'table', 'column'],
            'event' => ['event', 'trigger', 'fired', 'dispatch'],
            'audit' => ['audit', 'log'],
            default => ['error', 'fail', 'exception', 'warn'],
        };

        return array_values(array_filter($lines, function (string $line) use ($keywords) {
            foreach ($keywords as $kw) {
                if (stripos($line, $kw) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }
}
