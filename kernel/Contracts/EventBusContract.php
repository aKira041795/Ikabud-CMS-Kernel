<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * Contract for the kernel event bus.
 *
 * Separates consumers from the singleton EventBus implementation,
 * enabling test doubles and alternative dispatchers.
 */
interface EventBusContract
{
    public function listen(string $event, callable $callback, int $priority = 10, string $module = ''): void;

    public function fire(string $event, array $payload = [], string $module = ''): int;

    public function fireDurable(string $event, array $payload = [], ?\PDO $pdo = null, array $opts = []): ?int;

    public function hasListeners(string $event): bool;

    public function registeredEvents(): array;

    public function defer(string $event, array $payload = [], string $module = ''): int;

    public function deferredCount(): int;

    public function flushDeferred(): int;

    public function listenerCount(string $event): int;

    public function off(string $event): void;

    public function enableHistory(bool $enable = true): void;

    public function history(): array;

    public function reset(): void;
}
