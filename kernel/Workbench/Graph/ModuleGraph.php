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
        if (isset($this->nodes[$id])) {
            $existing = $this->nodes[$id];
            $existing->merge($type, $meta);
            return $existing;
        }
        $node = new GraphNode($id, $type, $meta);
        $this->nodes[$id] = $node;
        return $node;
    }

    public function addEdge(string $from, string $to, string $type, array $meta = []): GraphEdge
    {
        if (!isset($this->nodes[$from]) || !isset($this->nodes[$to])) {
            throw new \InvalidArgumentException("Graph edge '{$type}' requires existing endpoints: {$from} -> {$to}");
        }
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

    /** @return string[] */
    public function validate(): array
    {
        $errors = [];
        foreach ($this->edges as $edge) {
            if (!isset($this->nodes[$edge->from])) $errors[] = "Missing edge source: {$edge->from}";
            if (!isset($this->nodes[$edge->to])) $errors[] = "Missing edge target: {$edge->to}";
        }
        return $errors;
    }

    public function toArray(string $graphId = 'workbench'): array
    {
        return [
            'schema_version' => '1.0',
            'graph_id' => $graphId,
            'generated_at' => date('c'),
            'nodes' => array_values(array_map(fn(GraphNode $n) => $n->toArray(), $this->nodes)),
            'edges' => array_values(array_map(fn(GraphEdge $e) => $e->toArray(), $this->edges)),
        ];
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
        public string $type,  // entity, route, handler, state, action, capability
        public array $meta = [],
    ) {}

    public function isType(string $type): bool { return $this->type === $type; }

    public function merge(string $type, array $meta): void
    {
        if ($this->type !== $type && $this->type !== 'unknown') {
            $this->meta['type_conflicts'] = array_values(array_unique(array_merge($this->meta['type_conflicts'] ?? [], [$type])));
        } else {
            $this->type = $type;
        }
        $this->meta = array_replace($this->meta, $meta);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type,
            'label' => (string)($this->meta['label'] ?? $this->id),
            'provenance' => (string)($this->meta['provenance'] ?? 'declared'),
            'confidence' => (float)($this->meta['confidence'] ?? 1.0),
            'verified' => (bool)($this->meta['verified'] ?? false),
            'source' => is_array($this->meta['source'] ?? null) ? $this->meta['source'] : [],
            'meta' => $this->meta,
        ];
    }
}

final class GraphEdge
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $type,  // calls, triggers, transitions, reads, writes, creates, updates, deletes
        public readonly array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => hash('sha256', $this->from . '|' . $this->to . '|' . $this->type),
            'from' => $this->from, 'to' => $this->to, 'type' => $this->type,
            'provenance' => (string)($this->meta['provenance'] ?? 'declared'),
            'confidence' => (float)($this->meta['confidence'] ?? 1.0),
            'verified' => (bool)($this->meta['verified'] ?? false),
            'evidence_ids' => array_values((array)($this->meta['evidence_ids'] ?? [])),
            'meta' => $this->meta,
        ];
    }
}
