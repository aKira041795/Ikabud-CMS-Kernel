<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Default cell renderer registry — a simple in-memory store.
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class CellRendererRegistry implements CellRendererRegistryInterface
{
    /** @var array<string, CellRendererInterface> */
    private array $renderers = [];

    /** @var array<string, string> Name => provider */
    private array $providers = [];

    public function register(string $name, CellRendererInterface $renderer, string $provider): void
    {
        $this->renderers[$name] = $renderer;
        $this->providers[$name] = $provider;
    }

    public function has(string $name): bool
    {
        return isset($this->renderers[$name]);
    }

    public function get(string $name): CellRendererInterface
    {
        if (!isset($this->renderers[$name])) {
            throw new \RuntimeException("Cell renderer '{$name}' is not registered.");
        }
        return $this->renderers[$name];
    }

    public function all(?string $provider = null): array
    {
        if ($provider === null) {
            return $this->providers;
        }
        return array_filter($this->providers, fn(string $p): bool => $p === $provider);
    }

    public function reset(): void
    {
        $this->renderers = [];
        $this->providers = [];
    }
}
