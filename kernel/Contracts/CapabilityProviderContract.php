<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * CapabilityProviderContract
 *
 * Defines the Phase 3B interface for modules exporting managed capabilities
 * back to the Ikabud Application Kernel OS.
 *
 * Usage: Implement this interface and register via:
 *   $registry->registerProvider(new MyProvider(), 'my-module');
 *
 * The CapabilityRegistry::registerProvider() method wraps the contract into
 * a callable handler with auto-extracted input/output schema metadata.
 *
 * @see \Ikabud\Kernel\Capabilities\CapabilityRegistry::registerProvider()
 */
interface CapabilityProviderContract
{
    /**
     * Define the capability identifier this provider responds to.
     */
    public function getCapabilityId(): string;

    /**
     * Get the JSON schema describing input requirements for this capability.
     */
    public function getInputSchema(): array;

    /**
     * Get the JSON schema describing output structure and types for this capability.
     */
    public function getOutputSchema(): array;

    /**
     * Execute the underlying capability action using the provided context payload.
     * Returns the output array payload.
     */
    public function handle(array $context): array;
}
