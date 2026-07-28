<?php
declare(strict_types=1);

/**
 * Parsed stage definition from a workflow profile.
 */
class StageDefinition
{
    public string $code;
    public string $label;
    public string $role;
    public bool $terminal;
    public array $next;
    public array $outcomes;
    public array $requirements;

    public function __construct(array $def)
    {
        $this->code = $def['code'] ?? '';
        $this->label = $def['label'] ?? $this->code;
        $this->role = $def['role'] ?? '';
        $this->terminal = (bool)($def['terminal'] ?? false);
        $this->next = $def['next'] ?? [];
        $this->outcomes = $def['outcomes'] ?? [];
        $this->requirements = $def['requirements'] ?? [];
    }

    public function canTransitionTo(string $targetStage): bool
    {
        if (in_array($targetStage, $this->next, true)) {
            return true;
        }
        return in_array($targetStage, array_values($this->outcomes), true);
    }

    public function outcomeTarget(string $outcome): ?string
    {
        return $this->outcomes[$outcome] ?? null;
    }
}
