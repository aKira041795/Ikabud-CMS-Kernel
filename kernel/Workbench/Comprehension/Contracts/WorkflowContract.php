<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Contracts;

class WorkflowContract
{
    /** @param array<int, string> $states */
    public function __construct(
        public readonly string $id,
        public readonly string $entityType,
        public readonly array $states,
        /** @param array<int, array{from: string, to: string, action: string, capability?: string}> $transitions */
        public readonly array $transitions = [],
    ) {}
}
