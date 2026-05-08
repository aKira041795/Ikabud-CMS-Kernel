<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\AI;

/**
 * Runtime policy for AI calls.
 *
 * 4.6.0 enforces:
 *  - kill switch (env KERNEL_AI_DISABLED=1)
 *  - per-instance model allowlist (default = no allowlist = allow all)
 *  - per-instance cost ceiling in USD (in-memory accumulator; reset() per render)
 *  - per-call max_tokens cap
 *
 * 4.6.1 will add: per-tenant DB-backed daily ceiling, PII regex redaction
 * with restoration, model routing rules, structured audit table.
 */
final class Policy
{
    /** @var list<string>|null null = no allowlist; otherwise list of allowed model ids */
    private ?array $modelAllowlist = null;
    private float $maxCostUsd = INF;
    private float $accumulatedCostUsd = 0.0;
    private int $maxTokensCap = 4096;

    /** @var array<string, float> Per-model approximate USD per 1k output tokens */
    private array $costPer1k = [
        'echo'         => 0.0,
        'gpt-4o-mini'  => 0.0006,
        'claude-haiku' => 0.0008,
    ];

    public function setAllowlist(?array $models): void
    {
        $this->modelAllowlist = $models;
    }

    public function setCostCeiling(float $usd): void
    {
        $this->maxCostUsd = $usd;
    }

    public function setMaxTokensCap(int $cap): void
    {
        $this->maxTokensCap = max(1, $cap);
    }

    public function setCostPer1k(string $model, float $usd): void
    {
        $this->costPer1k[$model] = $usd;
    }

    public function reset(): void
    {
        $this->accumulatedCostUsd = 0.0;
    }

    public function isKilled(): bool
    {
        return ((string) (getenv('KERNEL_AI_DISABLED') ?: '')) === '1';
    }

    public function allowsModel(string $model): bool
    {
        if ($this->modelAllowlist === null) return true;
        return in_array($model, $this->modelAllowlist, true);
    }

    public function capMaxTokens(int $requested): int
    {
        return min($requested, $this->maxTokensCap);
    }

    /**
     * Check whether a projected call fits the remaining budget.
     */
    public function canAfford(string $model, int $maxTokens): bool
    {
        $rate = $this->costPer1k[$model] ?? 0.001;
        $projected = ($maxTokens / 1000.0) * $rate;
        return ($this->accumulatedCostUsd + $projected) <= $this->maxCostUsd;
    }

    public function recordUsage(string $model, int $outputTokens): float
    {
        $rate = $this->costPer1k[$model] ?? 0.001;
        $cost = ($outputTokens / 1000.0) * $rate;
        $this->accumulatedCostUsd += $cost;
        return $cost;
    }

    public function accumulatedCost(): float
    {
        return $this->accumulatedCostUsd;
    }
}
