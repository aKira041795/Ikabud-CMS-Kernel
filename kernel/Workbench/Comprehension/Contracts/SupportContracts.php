<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Contracts;

class InvariantContract
{
    public function __construct(
        public readonly string $description,
        public readonly string $type, // 'db', 'ui', 'capability'
        public readonly ?string $sql = null,
        public readonly ?string $capabilityId = null,
    ) {}
}

class ScenarioContract
{
    /** @param array<int, string> $actionIds */
    public function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly array $actionIds = [],
    ) {}
}
