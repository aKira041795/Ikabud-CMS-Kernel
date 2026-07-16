<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Planning;

final class MatrixPlanner
{
    /** @param array<string,list<string>> $dimensions @param list<array<string,string>> $mandatory @return array<string,mixed> */
    public function plan(array $dimensions, array $mandatory = [], int $budget = 24): array
    {
        ksort($dimensions);
        foreach ($dimensions as &$values) { $values = array_values(array_unique(array_map('strval', $values))); sort($values); }
        unset($values);
        $all = $this->cartesian($dimensions);
        $selected = [];
        foreach ($all as $combination) {
            if ($this->matchesAny($combination, $mandatory)) $selected[$this->key($combination)] = $combination;
        }
        $pairUniverse = [];
        foreach ($all as $combination) foreach ($this->pairs($combination) as $pair) $pairUniverse[$pair] = true;
        $covered = [];
        foreach ($selected as $combination) foreach ($this->pairs($combination) as $pair) $covered[$pair] = true;
        while (count($selected) < min($budget, count($all)) && count($covered) < count($pairUniverse)) {
            $best = null; $bestScore = -1; $bestKey = '';
            foreach ($all as $combination) {
                $key = $this->key($combination);
                if (isset($selected[$key])) continue;
                $score = count(array_filter($this->pairs($combination), static fn(string $pair): bool => !isset($covered[$pair])));
                if ($score > $bestScore || ($score === $bestScore && ($best === null || $key < $bestKey))) { $best = $combination; $bestScore = $score; $bestKey = $key; }
            }
            if ($best === null || $bestScore <= 0) break;
            $selected[$bestKey] = $best;
            foreach ($this->pairs($best) as $pair) $covered[$pair] = true;
        }
        ksort($selected);
        $omitted = [];
        foreach ($all as $combination) {
            $key = $this->key($combination);
            if (!isset($selected[$key])) $omitted[] = ['combination' => $combination, 'reason' => count($selected) >= $budget ? 'risk budget reached after mandatory and pairwise coverage' : 'adds no uncovered dimension pair'];
        }
        $checks = ['navigation', 'direct-route', 'action', 'api', 'entity-access', 'export', 'log', 'error-page'];
        return [
            'schema' => 'ark.workbench-matrix-plan.v1',
            'dimensions' => $dimensions,
            'selected' => array_values($selected),
            'omitted' => $omitted,
            'mandatory' => $mandatory,
            'checks' => $checks,
            'coverage' => ['pairs_covered' => count($covered), 'pairs_total' => count($pairUniverse), 'pairwise_pct' => $pairUniverse === [] ? 100.0 : round(count($covered) / count($pairUniverse) * 100, 2), 'critical_combinations' => count(array_filter($all, fn(array $c): bool => $this->matchesAny($c, $mandatory))), 'critical_selected' => count(array_filter($selected, fn(array $c): bool => $this->matchesAny($c, $mandatory)))],
            'digest' => hash('sha256', json_encode([$dimensions, array_values($selected), $mandatory], JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** @param list<array<string,mixed>> $observations @return list<array<string,mixed>> */
    public function detectIsolationLeaks(array $observations): array
    {
        $leaks = [];
        foreach ($observations as $observation) {
            $active = (string) ($observation['tenant'] ?? '');
            $observed = (string) ($observation['observed_tenant'] ?? $active);
            if ($active !== '' && $observed !== '' && $active !== $observed) $leaks[] = ['severity' => 'critical', 'category' => 'tenant-isolation', 'observation' => $observation];
        }
        return $leaks;
    }

    /** @return list<array<string,string>> */
    private function cartesian(array $dimensions): array
    {
        $result = [[]];
        foreach ($dimensions as $name => $values) {
            $next = [];
            foreach ($result as $row) foreach ($values as $value) $next[] = $row + [$name => $value];
            $result = $next;
        }
        return $result;
    }

    private function matchesAny(array $combination, array $rules): bool
    {
        foreach ($rules as $rule) if (array_diff_assoc($rule, $combination) === []) return true;
        return false;
    }

    /** @return list<string> */
    private function pairs(array $combination): array
    {
        $pairs = []; $keys = array_keys($combination);
        for ($i = 0; $i < count($keys); $i++) for ($j = $i + 1; $j < count($keys); $j++) $pairs[] = $keys[$i] . '=' . $combination[$keys[$i]] . '|' . $keys[$j] . '=' . $combination[$keys[$j]];
        return $pairs;
    }

    private function key(array $combination): string { return implode('|', array_map(static fn($k, $v): string => "{$k}={$v}", array_keys($combination), $combination)); }
}
