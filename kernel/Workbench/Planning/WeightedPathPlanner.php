<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Planning;

use Ikabud\Kernel\Workbench\Graph\ModuleGraph;

/** Non-negative Dijkstra/Yen traversal plus budgeted diverse suite selection. */
final class WeightedPathPlanner
{
    public function __construct(private readonly array $weights = []) {}

    /** @return array<int, array{nodes:array,edges:array,cost:float,value:float}> */
    public function kShortestTestPaths(ModuleGraph $graph, string $start, string $target, array $stats = [], int $k = 3): array
    {
        $first = $this->shortest($graph, $start, $target, fn(string $key): float => $this->planningWeight($stats[$key] ?? []));
        if ($first === null) return [];
        $accepted = [$first]; $candidates = [];
        for ($round = 1; $round < max(1, $k); $round++) {
            $previous = $accepted[$round - 1];
            for ($i = 0; $i < count($previous['nodes']) - 1; $i++) {
                $rootNodes = array_slice($previous['nodes'], 0, $i + 1);
                $rootEdges = array_slice($previous['edges'], 0, $i);
                $blocked = [];
                foreach ($accepted as $path) {
                    if (array_slice($path['nodes'], 0, $i + 1) === $rootNodes && isset($path['edges'][$i])) $blocked[$path['edges'][$i]] = true;
                }
                $spur = $this->shortest($graph, $rootNodes[$i], $target, fn(string $key): float => $this->planningWeight($stats[$key] ?? []), $blocked, array_fill_keys(array_slice($rootNodes, 0, -1), true));
                if ($spur === null) continue;
                $nodes = array_merge(array_slice($rootNodes, 0, -1), $spur['nodes']);
                $edges = array_merge($rootEdges, $spur['edges']);
                $cost = $this->pathCost($edges, fn(string $key): float => $this->planningWeight($stats[$key] ?? []));
                $signature = implode('|', $edges);
                $candidates[$signature] = ['nodes' => $nodes, 'edges' => $edges, 'cost' => $cost, 'value' => $this->pathValue($edges, $stats)];
            }
            if ($candidates === []) break;
            uasort($candidates, fn(array $a, array $b): int => $a['cost'] <=> $b['cost']);
            $nextKey = array_key_first($candidates); $accepted[] = $candidates[$nextKey]; unset($candidates[$nextKey]);
        }
        return $accepted;
    }

    public function diagnosticPaths(ModuleGraph $graph, string $symptom, string $candidateCause, array $probabilities, int $k = 3): array
    {
        $weight = fn(string $key): float => -log(max(0.000001, min(1.0, (float)($probabilities[$key] ?? 0.01))));
        $path = $this->shortest($graph, $symptom, $candidateCause, $weight);
        return $path === null ? [] : [$path + ['likelihood' => exp(-$path['cost'])]];
    }

    /** @return array<int, array> */
    public function selectSuite(array $paths, float $budget, array $mandatoryNodes = []): array
    {
        $selected = []; $spent = 0.0; $covered = [];
        foreach ($paths as $path) {
            if (array_intersect($mandatoryNodes, $path['nodes'] ?? []) === []) continue;
            if ($spent + $path['cost'] <= $budget) { $selected[] = $path; $spent += $path['cost']; foreach ($path['edges'] as $e) $covered[$e] = true; }
        }
        $remaining = array_values(array_filter($paths, fn(array $p): bool => !in_array($p, $selected, true)));
        while ($remaining !== []) {
            usort($remaining, function (array $a, array $b) use ($covered): int {
                $score = fn(array $p): float => (($p['value'] ?? 1) + count(array_diff($p['edges'] ?? [], array_keys($covered)))) / max(0.001, (float)($p['cost'] ?? 1));
                return $score($b) <=> $score($a);
            });
            $next = array_shift($remaining);
            if ($spent + $next['cost'] > $budget) continue;
            $selected[] = $next; $spent += $next['cost']; foreach ($next['edges'] as $e) $covered[$e] = true;
        }
        return $selected;
    }

    private function shortest(ModuleGraph $graph, string $start, string $target, callable $weight, array $blockedEdges = [], array $blockedNodes = []): ?array
    {
        if ($graph->node($start) === null || $graph->node($target) === null) return null;
        $dist = [$start => 0.0]; $previousNode = []; $previousEdge = []; $queue = [$start => 0.0];
        while ($queue !== []) {
            asort($queue, SORT_NUMERIC); $node = array_key_first($queue); $nodeDistance = $queue[$node]; unset($queue[$node]);
            if ($node === $target) break;
            foreach ($graph->node($node)->edgesOut as $edgeKey) {
                if (isset($blockedEdges[$edgeKey])) continue;
                $edge = $graph->edges()[$edgeKey] ?? null; if ($edge === null || isset($blockedNodes[$edge->to])) continue;
                $edgeWeight = max(0.0, (float)$weight($edgeKey));
                $candidate = $nodeDistance + $edgeWeight;
                if ($candidate < ($dist[$edge->to] ?? INF)) { $dist[$edge->to] = $candidate; $previousNode[$edge->to] = $node; $previousEdge[$edge->to] = $edgeKey; $queue[$edge->to] = $candidate; }
            }
        }
        if (!isset($dist[$target])) return null;
        $nodes = [$target]; $edges = []; $cursor = $target;
        while ($cursor !== $start) { $edges[] = $previousEdge[$cursor]; $cursor = $previousNode[$cursor]; $nodes[] = $cursor; }
        return ['nodes' => array_reverse($nodes), 'edges' => array_reverse($edges), 'cost' => round($dist[$target], 6), 'value' => 0.0];
    }

    private function planningWeight(array $s): float
    {
        $risk = $this->unit($s['risk'] ?? 0); $novelty = $this->unit($s['novelty'] ?? 0); $gap = $this->unit($s['gap'] ?? 0); $impact = $this->unit($s['impact'] ?? 0); $uncertainty = $this->unit($s['uncertainty'] ?? 0);
        $value = 0.30*$risk + 0.20*$novelty + 0.20*$gap + 0.20*$impact + 0.10*$uncertainty;
        return max(0.000001, (float)($s['execution_cost'] ?? 1.0)) / max(0.01, $value);
    }
    private function pathValue(array $edges, array $stats): float { $v = 0.0; foreach ($edges as $e) { $s=$stats[$e]??[]; $v += $this->unit($s['risk']??0)+$this->unit($s['novelty']??0)+$this->unit($s['gap']??0)+$this->unit($s['impact']??0)+$this->unit($s['uncertainty']??0); } return round($v, 4); }
    private function pathCost(array $edges, callable $weight): float { $sum=0.0; foreach($edges as $e)$sum+=max(0.0,(float)$weight($e)); return round($sum,6); }
    private function unit(mixed $v): float { return max(0.0, min(1.0, (float)$v)); }
}
