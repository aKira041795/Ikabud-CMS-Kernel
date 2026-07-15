<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Graph;

/**
 * GapAnalyzer — walks a ModuleGraph and identifies untested paths.
 *
 * Compares the graph's computed paths against existing test specs in
 * tests/browser/modules/<moduleId>/ to find gaps.
 */
final class GapAnalyzer
{
    /** @var array<int, array{path: array, reason: string}> */
    private array $gaps = [];

    /** @var array<string, bool> */
    private array $coveredPaths = [];

    public function __construct(
        private readonly ModuleGraph $graph,
        private readonly string $moduleId,
        private readonly string $projectRoot,
    ) {}

    /**
     * Analyze the graph and return all identified gaps.
     *
     * @return array{gaps: array, total_paths: int, covered: int, uncovered: int, score: float}
     */
    public function analyze(): array
    {
        $builder = new GraphBuilder(new class implements \Ikabud\Kernel\Workbench\Comprehension\Contracts\ModuleComprehensionProvider {
            public function entities(): array { return []; }
            public function routes(): array { return []; }
            public function workflows(): array { return []; }
            public function actions(): array { return []; }
            public function capabilities(): array { return []; }
            public function invariants(): array { return []; }
            public function expectedEffects(): array { return []; }
            public function testScenarios(): array { return []; }
        }, $this->moduleId);

        // The builder's computePaths() works on $this->graph directly
        // We need to expose it — let's use reflection or a public method
        $paths = $this->computeGraphPaths();

        $this->gaps = [];
        $this->coveredPaths = [];
        $covered = 0;

        // Scan what spec files exist
        // Map module IDs to test directory short-names (module-id → dir-name)
        $moduleDirMap = [
            'project-audit-ledger' => 'pal',
            'bakeshop' => 'bakeshop',
            'guidance' => 'guidance',
            'wms' => 'wms',
            'attendance-wage' => 'attendance-wage',
            'cms' => 'cms',
        ];
        $testDirName = $moduleDirMap[$this->moduleId] ?? str_replace('-', '/', $this->moduleId);
        $specDir = $this->projectRoot . '/tests/browser/modules/' . $testDirName . '/workflows';
        $existingTests = $this->listExistingTests($specDir);

        foreach ($paths as $i => $path) {
            $pathKey = $path['type'] . ':' . ($path['label'] ?? "path-{$i}");
            $isCovered = false;

            foreach ($existingTests as $testFile => $content) {
                if ($this->pathCoveredByTest($path, $content)) {
                    $isCovered = true;
                    $this->coveredPaths[$pathKey] = $testFile;
                    break;
                }
            }

            if ($isCovered) {
                $covered++;
            } else {
                $this->gaps[] = [
                    'path' => $path,
                    'path_key' => $pathKey,
                    'reason' => "No test exercises this path",
                    'type' => $path['type'],
                    'label' => $path['label'] ?? "Path #{$i}",
                ];
            }
        }

        $total = count($paths);
        return [
            'gaps' => $this->gaps,
            'total_paths' => $total,
            'covered' => $covered,
            'uncovered' => $total - $covered,
            'score' => $total > 0 ? round($covered / $total, 2) : 0,
            'existing_tests' => array_keys($existingTests),
        ];
    }

    /** @return array<int, array{nodes: string[], type: string, label: string}> */
    private function computeGraphPaths(): array
    {
        $paths = [];

        // Entity CRUD paths
        foreach ($this->graph->nodesOfType('entity') as $entity) {
            $paths[] = ['nodes' => ["entity:{$entity->id}"], 'type' => 'entity_list', 'label' => "List {$entity->meta['label']}"];
            $paths[] = ['nodes' => ["entity:{$entity->id}"], 'type' => 'entity_create', 'label' => "Create {$entity->meta['label']}"];
            $paths[] = ['nodes' => ["entity:{$entity->id}"], 'type' => 'entity_detail', 'label' => "View {$entity->meta['label']}"];
            $paths[] = ['nodes' => ["entity:{$entity->id}"], 'type' => 'entity_edit', 'label' => "Edit {$entity->meta['label']}"];
        }

        // Action paths
        foreach ($this->graph->nodesOfType('action') as $action) {
            $paths[] = ['nodes' => ["action:{$action->id}"], 'type' => 'action_execute', 'label' => $action->meta['label'] ?? $action->id];

            // Chain steps
            $steps = array_filter(
                $this->graph->nodesOfType('chain_step'),
                fn(GraphNode $n) => ($n->meta['action'] ?? '') === $action->id
            );
            if (!empty($steps)) {
                $paths[] = ['nodes' => array_map(fn(GraphNode $s) => $s->id, array_values($steps)), 'type' => 'action_chain', 'label' => "Chain: {$action->meta['label']}"];
            }
        }

        // Workflow transition paths
        $stateNodes = $this->graph->nodesOfType('state');
        $transitionEdges = array_filter(
            $this->graph->edges(),
            fn(GraphEdge $e) => $e->type === 'transitions'
        );
        foreach ($transitionEdges as $edge) {
            $paths[] = [
                'nodes' => [$edge->from, $edge->to],
                'type' => 'workflow_transition',
                'label' => "Transition: {$edge->from}→{$edge->to}",
            ];
        }

        // Route paths
        $routes = array_merge(
            $this->graph->nodesOfType('route'),
            $this->graph->nodesOfType('route_pattern')
        );
        foreach ($routes as $route) {
            $paths[] = ['nodes' => ["route:{$route->id}"], 'type' => 'route_exists', 'label' => "{$route->meta['method']} {$route->meta['path']}"];
        }

        return $paths;
    }

    /** @return array<string, string> */
    private function listExistingTests(string $specDir): array
    {
        $tests = [];
        if (!is_dir($specDir)) return $tests;

        $files = glob($specDir . '/*.spec.js') ?: [];
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $tests[basename($file)] = $content;
            }
        }

        return $tests;
    }

    private function pathCoveredByTest(array $path, string $content): bool
    {
        $label = $path['label'] ?? '';
        $type = $path['type'] ?? '';
        $nodes = $path['nodes'] ?? [];

        // Precise matching: check for specific entity/action IDs
        foreach ($nodes as $node) {
            // Convert "entity:pal.project" to check for "pal.project" or "pal_project"
            $parts = explode(':', $node);
            $entityId = $parts[1] ?? '';
            if ($entityId) {
                // Check for the exact entity ID or dashed variant
                $dashedId = str_replace('.', '-', $entityId);
                if (stripos($content, $entityId) !== false) return true;
                if ($dashedId !== $entityId && stripos($content, $dashedId) !== false) return true;
            }
        }

        // Action matching: check for action IDs like "save-as-draft", "submit-for-approval"
        foreach ($nodes as $node) {
            $parts = explode(':', $node);
            if (($parts[0] ?? '') === 'action') {
                $actionId = implode(':', array_slice($parts, 1));
                // Extract the action name part (e.g., "pal.job-order.submit" → "submit")
                $actionParts = explode('.', $actionId);
                $actionName = end($actionParts);
                if ($actionName && stripos($content, $actionName) !== false) return true;
                if (stripos($content, $actionId) !== false) return true;
            }
        }

        // Type-specific matching (stricter)
        $typeChecks = [
            'entity_list' => ['data-ikb-list', 'entity.list', 'data-wb-list', 'entity-list', 'project-list'],
            'entity_create' => ['projects/create', 'create-project', 'create-form', 'project form'],
            'entity_detail' => ['project-detail', 'detail-header', 'detail page', 'project detail'],
            'entity_edit' => ['edit-form', 'edit project', 'edit form'],
            'action_execute' => ['save-as-draft', 'submit-for-approval', 'mark-ongoing', 'start-work'],
            'action_chain' => ['WorkbenchObserver', 'observer'],
            'workflow_transition' => ['lifecycle', 'draft', 'pending', 'approved'],
            'route_exists' => ['goto', 'navigate', 'goTo(page'],
        ];

        $checks = $typeChecks[$type] ?? [];
        foreach ($checks as $pattern) {
            if (stripos($content, $pattern) !== false) return true;
        }

        return false;
    }
}
