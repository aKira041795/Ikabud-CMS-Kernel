<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Contracts;

class EntityContract
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $table,
        public readonly array $fields = [],
        public readonly array $relationships = [],
        public readonly array $statuses = [],
    ) {}
}
