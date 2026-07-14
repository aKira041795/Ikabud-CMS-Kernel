<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Contracts;

/**
 * An expected effect of an action — the "then" part of the causal chain.
 */
class EffectContract
{
    /**
     * @param array<string, mixed> $effects Key-value pairs of expected state changes
     */
    public function __construct(
        public readonly string $actionId,
        public readonly array $effects = [],
    ) {}
}
