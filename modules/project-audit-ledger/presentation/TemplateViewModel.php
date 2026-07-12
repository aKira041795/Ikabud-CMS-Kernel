<?php

declare(strict_types=1);

namespace Ikabud\Modules\ProjectAuditLedger\Presentation;

/**
 * TemplateViewModel — contract for view models passed to DiSyL templates.
 */
interface TemplateViewModel
{
    /** @return array<string,mixed> */
    public function toTemplateContext(): array;
}
