<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Graph;

/**
 * ModuleGraph — in-memory representation of a module's structure as a directed graph.
 *
 * Nodes: entities, routes, handlers, states, actions
 * Edges: workflow transitions, route→handler, handler→entity, action chains
 *
 * This graph is the input to the Gap Analyzer and Test Spec Generator.
 */
final class ModuleGraph
{
    /** @var array<string, GraphNode> */
    private array $nodes = [];

    /** @var array<string, GraphEdge> */
    private array $edges = [];

    public function addNode(string $id, string $type, array $meta = []): GraphNode
    {
        $node = new GraphNode($id, $type, $meta);
        $this->nodes[$id] = $node;
        return $node;
    }

    public function addEdge(string $from, string $to, string $type, array $meta = []): GraphEdge
    {
        $key = "{$from}→{$to}::{$type}";
        $edge = new GraphEdge($from, $to, $type, $meta);
        $this->edges[$key] = $edge;
        if (isset($this->nodes[$from])) {
            $this->nodes[$from]->edgesOut[] = $key;
        }
        if (isset($this->nodes[$to])) {
            $this->nodes[$to]->edgesIn[] = $key;
        }
        return $edge;
    }

    /** @return GraphNode[] */
    public function nodes(): array { return $this->nodes; }

    /** @return GraphEdge[] */
    public function edges(): array { return $this->edges; }

    public function node(string $id): ?GraphNode { return $this->nodes[$id] ?? null; }

    /** @return GraphNode[] */
    public function nodesOfType(string $type): array
    {
        return array_filter($this->nodes, fn(GraphNode $n) => $n->type === $type);
    }

    /** @return GraphNode[] */
    public function orphans(): array
    {
        return array_filter($this->nodes, fn(GraphNode $n) => empty($n->edgesIn) && empty($n->edgesOut));
    }

    /** @return GraphNode[] */
    public function deadEnds(): array
    {
        return array_filter($this->nodes, fn(GraphNode $n) => !empty($n->edgesIn) && empty($n->edgesOut));
    }

    /** @return GraphNode[] */
    public function entryPoints(): array
    {
        return array_filter($this->nodes, fn(GraphNode $n) => empty($n->edgesIn) && !empty($n->edgesOut));
    }
}

final class GraphNode
{
    /** @var string[] */
    public array $edgesIn = [];
    /** @var string[] */
    public array $edgesOut = [];

    public function __construct(
        public readonly string $id,
        public readonly string $type,  // entity, route, handler, state, action, capability
        public readonly array $meta = [],
    ) {}

    public function isType(string $type): bool { return $this->type === $type; }
}

final class GraphEdge
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $type,  // calls, triggers, transitions, reads, writes, creates, updates, deletes
        public readonly array $meta = [],
    ) {}
}
