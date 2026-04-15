<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * Contract for the capability registry.
 *
 * Read-only surface consumed by CapabilityBus and diagnostic tools.
 */
interface CapabilityRegistryContract
{
    public function register(
        string $capabilityId,
        string $providerId,
        callable $handler,
        int $priority = 10,
        array $modes = ['first'],
        array $meta = []
    ): void;

    public function has(string $capabilityId): bool;

    public function providers(string $capabilityId): array;

    public function capabilityIds(): array;

    public function resolve(string $capabilityId): string;

    public function inspect(string $capabilityId): array;

    public function inspectAll(): array;
}
