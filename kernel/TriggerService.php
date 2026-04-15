<?php

declare(strict_types=1);

namespace Ikabud\Kernel;

/**
 * Encapsulates per-request trigger state that was previously in $GLOBALS.
 *
 * Procedural functions in EventTriggers.php delegate to this class to avoid
 * fragile global state.  Modules can also access it via app()->triggers().
 */
final class TriggerService
{
    /** @var array<string, array> Pending module-event registrations, keyed by moduleId */
    private array $pendingRegistrations = [];

    /** @var array<string, array>|null Per-request trigger config cache, keyed by eventKey */
    private ?array $triggerCache = null;

    // ── Pending event registrations ──

    public function addPendingRegistration(string $moduleId, array $events): void
    {
        $this->pendingRegistrations[$moduleId] = $events;
    }

    public function consumePendingRegistrations(): array
    {
        $pending = $this->pendingRegistrations;
        $this->pendingRegistrations = [];
        return $pending;
    }

    public function getPendingRegistrations(): array
    {
        return $this->pendingRegistrations;
    }

    // ── Trigger config cache ──

    public function getCachedTriggers(string $eventKey): ?array
    {
        if ($this->triggerCache !== null && array_key_exists($eventKey, $this->triggerCache)) {
            return $this->triggerCache[$eventKey];
        }
        return null;
    }

    public function hasCachedTriggers(string $eventKey): bool
    {
        return $this->triggerCache !== null && array_key_exists($eventKey, $this->triggerCache);
    }

    public function cacheTriggers(string $eventKey, array $triggers): void
    {
        if ($this->triggerCache === null) {
            $this->triggerCache = [];
        }
        $this->triggerCache[$eventKey] = $triggers;
    }

    /**
     * Reset all per-request state (call on teardown or in tests).
     */
    public function reset(): void
    {
        $this->pendingRegistrations = [];
        $this->triggerCache = null;
    }
}
