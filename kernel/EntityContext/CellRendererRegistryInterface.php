<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Registry for named cell renderers — the single extension point for
 * modules to add custom cell rendering without modifying the kernel.
 *
 * Renderer keys MUST be namespaced by provider to prevent collisions:
 *   'text', 'badge', 'money', 'datetime', 'boolean'  (kernel built-ins)
 *   'guidance.rating', 'wms.progress'                 (module renderers)
 *
 * @package Ikabud\Kernel\EntityContext
 */
interface CellRendererRegistryInterface
{
    /**
     * Register a cell renderer.
     *
     * @param string               $name     Renderer key (e.g. 'badge', 'guidance.rating')
     * @param CellRendererInterface $renderer
     * @param string               $provider Provider ID (e.g. 'kernel', 'guidance', 'wms')
     */
    public function register(string $name, CellRendererInterface $renderer, string $provider): void;

    /**
     * Check if a renderer is registered.
     */
    public function has(string $name): bool;

    /**
     * Get a renderer by name.
     *
     * @throws \RuntimeException When the renderer is not found
     */
    public function get(string $name): CellRendererInterface;

    /**
     * Get all registered renderer names, optionally filtered by provider.
     *
     * @return array<string, string> Name => provider map
     */
    public function all(?string $provider = null): array;

    /**
     * Remove all renderers (for test reset).
     */
    public function reset(): void;
}
