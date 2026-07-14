<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Contracts;

/**
 * An action a user can perform on an entity.
 * Describes the entire expected causal chain: preconditions → effects.
 */
class ActionContract
{
    /**
     * @param array<string, mixed> $requires Preconditions (status, capability, etc.)
     * @param array<int, ChainLink> $chain Expected causal chain
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $entityType,
        public readonly string $route,
        public readonly string $method = 'POST',
        public readonly array $requires = [],
        public readonly array $chain = [],
    ) {}
}

/**
 * One step in the expected causal chain.
 */
class ChainLink
{
    public function __construct(
        public readonly string $step,          // e.g. 'button.visible', 'http.request', 'db.status_change'
        public readonly string $description,   // Human-readable
        public readonly string $category,      // 'ui', 'http', 'service', 'db', 'event', 'audit'
        public readonly ?string $probe = null, // How to check: e.g. "SELECT status FROM pal_projects WHERE id=:id"
    ) {}
}
