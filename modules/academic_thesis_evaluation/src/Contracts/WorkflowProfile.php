<?php
declare(strict_types=1);

/**
 * Workflow profile contract — parsed from config JSON or database.
 */
class WorkflowProfile
{
    public string $code;
    public string $name;
    public string $degreeLevel;
    public string $version;
    /** @var array<string, StageDefinition> */
    public array $stages;
    public ?string $rubricTemplateCode;
    public array $raw;

    public function __construct(array $raw)
    {
        $this->raw = $raw;
        $this->code = $raw['code'] ?? '';
        $this->name = $raw['name'] ?? $this->code;
        $this->degreeLevel = $raw['degree_level'] ?? '';
        $this->version = $raw['version'] ?? '1.0';
        $this->rubricTemplateCode = $raw['rubric_template_code'] ?? null;

        $this->stages = [];
        foreach ($raw['stages'] ?? [] as $stageDef) {
            $stage = new StageDefinition($stageDef);
            $this->stages[$stage->code] = $stage;
        }
    }

    public function getStage(string $code): ?StageDefinition
    {
        return $this->stages[$code] ?? null;
    }

    public function getInitialStage(): ?StageDefinition
    {
        foreach ($this->stages as $stage) {
            return $stage;
        }
        return null;
    }

    public function getAllowedTransitions(string $currentStage): array
    {
        $stage = $this->getStage($currentStage);
        if (!$stage) {
            return [];
        }
        return array_merge($stage->next, array_values($stage->outcomes));
    }
}
