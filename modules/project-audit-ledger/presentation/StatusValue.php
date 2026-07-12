<?php

declare(strict_types=1);

namespace Ikabud\Modules\ProjectAuditLedger\Presentation;

/**
 * StatusValue — domain status with resolved visual tone.
 *
 * The module maps domain status → semantic tone for Workbench.
 * isTerminal belongs to the workflow service, not here.
 */
final readonly class StatusValue implements TemplateContextValue
{
    public function __construct(
        public string $key,           // "approved"
        public string $label,         // "Approved"
        public string $tone,          // "success" (neutral|informational|warning|success|danger)
        public string $description,   // Human-readable description
    ) {}

    /** @return array<string,mixed> */
    public function toTemplateValue(): array
    {
        return [
            'key'         => $this->key,
            'label'       => $this->label,
            'tone'        => $this->tone,
            'description' => $this->description,
        ];
    }
}
