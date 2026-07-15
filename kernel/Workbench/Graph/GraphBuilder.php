<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Graph;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\{
    ModuleComprehensionProvider,
    EntityContract,
    WorkflowContract,
    ActionContract,
};

/**
 * GraphBuilder — builds a ModuleGraph from a ModuleComprehensionProvider.
 *
 * Reads the provider's declarations and constructs nodes and edges for:
 *   - Entities (data nodes) and their relationships
 *   - Workflows (state nodes + transition edges)
 *   - Actions (action nodes + chain edges)
 *   - Routes (entry point nodes)
 */
final class GraphBuilder
{
    private ModuleGraph $graph;
    private int $entityCount = 0;

    public function __construct(
        private readonly ModuleComprehensionProvider $provider,
        private readonly string $moduleId,
    ) {}

    public function build(): ModuleGraph
    {
        $this->graph = new ModuleGraph();

        $this->buildEntities();
        $this->buildWorkflows();
        $this->buildActions();
        $this->buildRoutes();
        $this->buildCapabilities();

        return $this->graph;
    }

    private function buildEntities(): void
    {
        foreach ($this->provider->entities() as $entity) {
            $node = $this->graph->addNode(
                $entity->id,
                'entity',
                [
                    'label'   => $entity->label,
                    'table'   => $entity->table,
                    'fields'  => $entity->fields,
                    'statuses' => $entity->statuses ?? [],
                ]
            );

            // Entity relationships
            foreach (($entity->relationships ?? []) as $relName => $targetEntityId) {
                $this->graph->addEdge($entity->id, $targetEntityId, 'references', [
                    'relationship' => $relName,
                ]);
            }
        }
    }

    private function buildWorkflows(): void
    {
        foreach ($this->provider->workflows() as $wf) {
            $entityNodeId = $wf->entityType;

            // State nodes
            foreach ($wf->states as $state) {
                $stateId = "{$entityNodeId}:{$state}";
                $this->graph->addNode($stateId, 'state', [
                    'entity' => $entityNodeId,
                    'status' => $state,
                    'workflow' => $wf->id,
                ]);
                // Connect entity to state
                $this->graph->addEdge($entityNodeId, $stateId, 'has_state');
            }

            // Transition edges
            foreach ($wf->transitions as $t) {
                $fromId = "{$entityNodeId}:{$t['from']}";
                $toId = "{$entityNodeId}:{$t['to']}";
                $edge = $this->graph->addEdge(
                    $fromId,
                    $toId,
                    'transitions',
                    [
                        'action_id' => $t['action'],
                        'capability' => $t['capability'] ?? null,
                        'workflow' => $wf->id,
                    ]
                );

                // Connect action to transition
                $actionId = $t['action'];
                if ($this->graph->node($actionId) === null) {
                    $this->graph->addNode($actionId, 'action', ['label' => $actionId]);
                }
                $this->graph->addEdge($actionId, $fromId, 'triggers_from');
                $this->graph->addEdge($actionId, $toId, 'triggers_to');
            }
        }
    }

    private function buildActions(): void
    {
        foreach ($this->provider->actions() as $action) {
            $node = $this->graph->addNode($action->id, 'action', [
                'label' => $action->label,
                'entity_type' => $action->entityType ?? null,
                'route' => $action->route,
                'method' => $action->method,
                'chain_length' => count($action->chain),
                'chain_steps' => array_map(fn($s) => [
                    'step' => $s->step,
                    'description' => $s->description,
                    'category' => $s->category,
                ], $action->chain),
            ]);

            // Each chain step becomes a sub-node
            foreach ($action->chain as $chainLink) {
                $stepId = "{$action->id}:{$chainLink->step}";
                $this->graph->addNode($stepId, 'chain_step', [
                    'action' => $action->id,
                    'category' => $chainLink->category,
                    'description' => $chainLink->description,
                ]);
                $this->graph->addEdge($action->id, $stepId, 'has_step');
            }

            // Connect action to entity
            if ($action->entityType !== null) {
                if ($this->graph->node($action->entityType)) {
                    $this->graph->addEdge($action->id, $action->entityType, 'acts_on');
                }
            }
        }
    }

    private function buildRoutes(): void
    {
        $routes = $this->provider->routes();
        if (empty($routes)) {
            // Auto-discover from known patterns
            $this->discoverRoutes();
            return;
        }

        foreach ($routes as $r) {
            $routeId = "route:{$r['method']}:{$r['path']}";
            $handlerId = "handler:{$r['handler']}";
            $this->graph->addNode($routeId, 'route', [
                'path' => $r['path'],
                'method' => $r['method'],
                'handler' => $r['handler'],
            ]);
            $this->graph->addNode($handlerId, 'handler');
            $this->graph->addEdge($routeId, $handlerId, 'dispatched_to');
        }
    }

    private function discoverRoutes(): void
    {
        // Discover routes from actions + known patterns
        foreach ($this->provider->actions() as $action) {
            $routeId = "route:{$action->method}:{$action->route}";
            $this->graph->addNode($routeId, 'route', [
                'path' => $action->route,
                'method' => $action->method,
                'action_id' => $action->id,
            ]);
            $this->graph->addEdge($routeId, $action->id, 'maps_to');
        }

        // Add standard CRUD patterns inferred from entities
        foreach ($this->provider->entities() as $entity) {
            $base = str_replace('.', '/', $entity->id);
            $patterns = [
                ['GET', "/admin/{$base}", 'list'],
                ['GET', "/admin/{$base}/create", 'create_form'],
                ['GET', "/admin/{$base}/{id}", 'detail'],
                ['GET', "/admin/{$base}/{id}/edit", 'edit_form'],
                ['POST', "/api/v1/{$base}", 'store'],
                ['POST', "/api/v1/{$base}/{id}", 'update'],
            ];
            foreach ($patterns as [$method, $path, $type]) {
                $routeId = "route:{$method}:{$path}";
                $this->graph->addNode($routeId, 'route_pattern', [
                    'path' => $path,
                    'method' => $method,
                    'type' => $type,
                    'entity' => $entity->id,
                ]);
            }
        }
    }

    private function buildCapabilities(): void
    {
        foreach ($this->provider->capabilities() as $capId) {
            $this->graph->addNode($capId, 'capability', ['capability_id' => $capId]);
        }

        // Connect capabilities to actions that require them
        foreach ($this->provider->actions() as $action) {
            $requiredCap = ($action->requires ?? [])['capability'] ?? null;
            if ($requiredCap !== null && $this->graph->node($requiredCap)) {
                $this->graph->addEdge($action->id, $requiredCap, 'requires');
            }
        }
    }

    /**
     * Compute all valid paths through the graph (entity lifecycle = entity → each state → each transition).
     *
     * @return array<int, array{nodes: string[], edges: string[], type: string}>
     */
    public function computePaths(): array
    {
        $paths = [];
        $entities = $this->graph->nodesOfType('entity');

        foreach ($entities as $entity) {
            $statuses = $entity->meta['statuses'] ?? [];
            $entityId = $entity->id;

            // Path for each entity: list → create_form → create → detail → edit → update
            $paths[] = [
                'nodes' => [
                    "route:GET:/admin/{$entityId}",
                    $entityId,
                ],
                'edges' => [],
                'type' => 'entity_list',
                'label' => "List {$entity->meta['label']}",
            ];

            $paths[] = [
                'nodes' => [
                    "route:GET:/admin/{$entityId}/create",
                    $entityId,
                ],
                'edges' => [],
                'type' => 'entity_create_form',
                'label' => "Create {$entity->meta['label']} form",
            ];

            // Workflow lifecycle path
            $stateNodes = $this->graph->nodesOfType('state');
            $entityStates = array_filter(
                $stateNodes,
                fn(GraphNode $n) => str_starts_with($n->id, "{$entityId}:")
            );

            if (!empty($entityStates)) {
                $states = array_map(fn(GraphNode $n) => $n->meta['status'], array_values($entityStates));
                $paths[] = [
                    'nodes' => array_map(fn(string $s) => "{$entityId}:{$s}", $states),
                    'edges' => [],
                    'type' => 'entity_lifecycle',
                    'label' => "Lifecycle: {$entity->meta['label']} ({$states[0]}→{$states[count($states)-1]})",
                ];
            }
        }

        // Paths for each action
        $actions = $this->graph->nodesOfType('action');
        foreach ($actions as $action) {
            $paths[] = [
                'nodes' => [$action->id],
                'edges' => [],
                'type' => 'action_execute',
                'label' => "Action: {$action->meta['label']}",
            ];

            // Chain steps path
            $chainSteps = array_filter(
                $this->graph->nodesOfType('chain_step'),
                fn(GraphNode $n) => ($n->meta['action'] ?? '') === $action->id
            );
            if (!empty($chainSteps)) {
                $paths[] = [
                    'nodes' => array_map(fn(GraphNode $n) => $n->id, array_values($chainSteps)),
                    'edges' => [],
                    'type' => 'action_chain',
                    'label' => "Verify chain: {$action->meta['label']}",
                ];
            }
        }

        return $paths;
    }
}
