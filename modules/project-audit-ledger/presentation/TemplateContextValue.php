<?php

declare(strict_types=1);

namespace Ikabud\Modules\ProjectAuditLedger\Presentation;

/**
 * TemplateContextValue — contract for value objects serializable to DiSyL template context.
 *
 * Every value object passed to a DiSyL template must implement this interface
 * to provide a stable array boundary between PHP objects and the template runtime.
 */
interface TemplateContextValue
{
    /** @return array<string,mixed>|string|int|bool|null */
    public function toTemplateValue(): array|string|int|bool|null;
}
