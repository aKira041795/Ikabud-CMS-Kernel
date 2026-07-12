<?php

declare(strict_types=1);

namespace Ikabud\Modules\ProjectAuditLedger\Presentation;

/**
 * ActionValue — resolved action with authorization already applied.
 *
 * The actions collection contains only actions the current user may perform.
 * Authorization is resolved before the view model is built.
 */
final readonly class ActionValue implements TemplateContextValue
{
    public function __construct(
        public string $key,           // "invoice.pay"
        public string $label,
        public string $url,           // Fully resolved URL
        public string $method,        // "GET" | "POST"
        public string $variant,       // "primary" | "secondary" | "danger" | "ghost"
        public ?string $confirm,      // Confirmation message
    ) {}

    /** @return array<string,mixed> */
    public function toTemplateValue(): array
    {
        return [
            'key'     => $this->key,
            'label'   => $this->label,
            'url'     => $this->url,
            'method'  => $this->method,
            'variant' => $this->variant,
            'confirm' => $this->confirm,
        ];
    }
}
