<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\{
    ModuleComprehensionProvider,
    ActionContract,
    ChainLink,
    WorkflowContract,
    EntityContract,
    EffectContract,
};

/**
 * Deterministic module comprehension engine.
 *
 * Does NOT guess. Takes declared contracts + runtime evidence, compares them,
 * identifies breakpoints.
 *
 * Flow:
 *   1. Accept a ModuleComprehensionProvider (declares what the module should do)
 *   2. Accept runtime evidence (what actually happened)
 *   3. For each action, check if the expected causal chain holds
 *   4. Identify the first link where expected ≠ actual → breakpoint
 *   5. Package evidence for the AI Steward
 */
class ModuleComprehensionEngine
{
    private ModuleComprehensionProvider $provider;
    private array $runtimeEvidence = [];

    public function __construct(ModuleComprehensionProvider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Feed runtime evidence collected during test execution.
     *
     * @param array<string, mixed> $evidence
     */
    public function feedEvidence(array $evidence): void
    {
        $this->runtimeEvidence = $evidence;
    }

    /**
     * Analyze an action: compare expected causal chain against runtime evidence.
     *
     * @return array{
     *   action: string,
     *   chain: array<int, array{step: string, description: string, category: string, expected: mixed, actual: mixed, ok: bool}>,
     *   breakpoint: ?string,
     *   likely_area: string,
     *   confidence: float
     * }
     */
    public function analyzeAction(string $actionId): array
    {
        $actions = $this->provider->actions();
        $action = null;
        foreach ($actions as $a) {
            if ($a->id === $actionId) {
                $action = $a;
                break;
            }
        }

        if (!$action) {
            return [
                'action' => $actionId,
                'error' => "Action '{$actionId}' not declared by module",
            ];
        }

        $chainResults = [];
        $breakpoint = null;
        $breakCategory = 'unknown';

        foreach ($action->chain as $link) {
            $expected = true; // The link should succeed
            $raw = $this->probeLink($link);
            // Treat any truthy value (boolean true, non-empty string, number > 0) as success
            $actual = $raw;
            $ok = $raw === true || (is_string($raw) && $raw !== '' && $raw !== 'false' && $raw !== '0')
                  || (is_numeric($raw) && (float)$raw > 0);

            $chainResults[] = [
                'step' => $link->step,
                'description' => $link->description,
                'category' => $link->category,
                'expected' => $expected,
                'actual' => $actual,
                'ok' => $ok,
            ];

            if (!$ok && $breakpoint === null) {
                $breakpoint = $link->step;
                $breakCategory = $link->category;
            }
        }

        // Determine likely area based on breakpoint category
        $areaMap = [
            'ui' => 'template/javascript',
            'http' => 'handler/routing',
            'service' => 'service layer',
            'db' => 'database/query',
            'event' => 'event/trigger system',
            'audit' => 'audit/logging',
            'verify' => 'post-action verification',
        ];
        $likelyArea = $areaMap[$breakCategory] ?? 'unknown';

        // Confidence: higher when earlier links passed but later ones failed
        $passedCount = 0;
        foreach ($chainResults as $cr) {
            if ($cr['ok']) $passedCount++;
        }
        $totalCount = count($chainResults);
        $confidence = $totalCount > 0 ? ($passedCount / $totalCount) : 0.5;
        if ($breakpoint !== null && $passedCount > 0) {
            // More passed links before the break = higher confidence
            $confidence = min(0.95, 0.5 + ($passedCount / $totalCount) * 0.45);
        }

        return [
            'action' => $actionId,
            'chain' => $chainResults,
            'breakpoint' => $breakpoint,
            'likely_area' => $likelyArea,
            'confidence' => round($confidence, 2),
        ];
    }

    /**
     * Analyze all module actions and return a summary.
     */
    public function analyzeAll(): array
    {
        $results = [];
        foreach ($this->provider->actions() as $action) {
            $results[$action->id] = $this->analyzeAction($action->id);
        }
        return $results;
    }

    /**
     * Build the full module knowledge graph.
     */
    public function buildGraph(): array
    {
        return [
            'entities' => array_map(fn(EntityContract $e) => [
                'id' => $e->id,
                'label' => $e->label,
                'table' => $e->table,
                'fields' => $e->fields,
                'relationships' => $e->relationships,
                'statuses' => $e->statuses,
            ], $this->provider->entities()),
            'workflows' => array_map(fn(WorkflowContract $w) => [
                'id' => $w->id,
                'entity_type' => $w->entityType,
                'states' => $w->states,
                'transitions' => $w->transitions,
            ], $this->provider->workflows()),
            'actions' => array_map(fn(ActionContract $a) => [
                'id' => $a->id,
                'label' => $a->label,
                'entity_type' => $a->entityType,
                'route' => $a->route,
                'method' => $a->method,
                'requires' => $a->requires,
                'chain' => array_map(fn(ChainLink $l) => [
                    'step' => $l->step, 'description' => $l->description, 'category' => $l->category,
                ], $a->chain),
            ], $this->provider->actions()),
            'capabilities' => $this->provider->capabilities(),
            'invariants' => $this->provider->invariants(),
        ];
    }

    /**
     * Package evidence for the AI Steward.
     */
    public function buildEvidencePacket(array $analysisResult): array
    {
        return [
            'module' => [
                'entities' => array_map(fn(EntityContract $e) => $e->id, $this->provider->entities()),
                'workflows' => array_map(fn(WorkflowContract $w) => $w->id, $this->provider->workflows()),
                'actions' => array_map(fn(ActionContract $a) => $a->id, $this->provider->actions()),
            ],
            'analysis' => $analysisResult,
            'runtime' => $this->runtimeEvidence,
            'generated_at' => date('c'),
        ];
    }

    /**
     * Probe a single chain link against runtime evidence.
     * Falls back to executing the link's declared SQL probe if evidence
     * is not found and the link has a probe defined.
     */
    private function probeLink(ChainLink $link): mixed
    {
        // 1. Check if we have direct evidence for this step
        if (isset($this->runtimeEvidence[$link->step])) {
            return $this->runtimeEvidence[$link->step];
        }

        // 2. Check category-level evidence
        if (isset($this->runtimeEvidence[$link->category])) {
            $catEvidence = $this->runtimeEvidence[$link->category];
            if (is_array($catEvidence) && isset($catEvidence[$link->step])) {
                return $catEvidence[$link->step];
            }
        }

        // 3. Execute declared SQL probe if present and DB is available
        if ($link->probe !== null && function_exists('app')) {
            try {
                $result = $this->executeProbe($link);
                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                // Probe execution failed — evidence missing
                return false;
            }
        }

        // Default: no evidence = step not observed = failed
        return false;
    }

    /**
     * Execute a declared SQL probe.
     * Resolves :id and :tenant_id tokens from evidence context.
     */
    private function executeProbe(ChainLink $link): mixed
    {
        $sql = $link->probe;
        if ($sql === null || $sql === '') {
            return null;
        }

        // Resolve parameters from evidence
        $entityId = $this->runtimeEvidence['_entity_id'] ?? $this->runtimeEvidence['entity_id'] ?? null;
        $tenantId = $this->runtimeEvidence['_tenant_id'] ?? $this->runtimeEvidence['tenant_id']
                  ?? (function_exists('app') && app()->tenant() ? app()->tenant()->current() : null);

        if ($entityId === null || $tenantId === null) {
            return null; // Can't run probe without context
        }

        $app = app();
        $db = method_exists($app, 'dbForTenant') ? $app->dbForTenant((int)$tenantId) : null;
        if (!$db) {
            return null;
        }

        // Replace :id and :tenant_id placeholders
        $sql = str_replace([':id', ':tenant_id'], [(int)$entityId, (int)$tenantId], $sql);

        $stmt = $db->query($sql);
        $result = $stmt->fetch(\PDO::FETCH_COLUMN);

        return $result;
    }
}
