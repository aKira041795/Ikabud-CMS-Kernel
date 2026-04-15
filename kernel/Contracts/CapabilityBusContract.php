<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * Contract for the capability dispatch bus.
 *
 * Provides the primary call() entry point consumed by modules.
 */
interface CapabilityBusContract
{
    /**
     * Dispatch a capability call.
     *
     * @param string $capabilityId  Versioned capability ID (e.g. "email.send@1")
     * @param mixed  $payload       Payload forwarded to the provider
     * @param array  $options       Dispatch options (caller, correlation_id, etc.)
     * @return mixed Provider result
     */
    public function call(string $capabilityId, mixed $payload = null, array $options = []): mixed;

    /**
     * Flush runtime state (metrics, breaker) to persistent storage.
     */
    public function flushRuntimeState(): void;
}
