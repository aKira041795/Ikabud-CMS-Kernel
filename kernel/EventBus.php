<?php
/**
 * Ikabud Kernel — Event Bus
 * 
 * Inter-module communication system for decoupled event-driven architecture.
 * Unlike Hooks (which are kernel→module), the EventBus is module→module.
 * 
 * Events carry typed payloads and are fire-and-forget (async-safe design).
 * Listeners are registered during module bootstrap, events are fired from
 * any module handler. The kernel never fires events — it uses Hooks.
 * 
 * Usage (listener — in module helpers.php):
 *   app()->events()->listen('employee.deactivated', function (array $payload) {
 *       // Revoke inventory access, send SMS, etc.
 *   });
 * 
 * Usage (emitter — in module handler):
 *   app()->events()->fire('employee.deactivated', [
 *       'user_id'  => 42,
 *       'reason'   => 'Resigned',
 *       'actor_id' => $currentUser['id'],
 *   ]);
 * 
 * Event naming convention:  <entity>.<past_tense_verb>
 *   employee.created, employee.deactivated, order.placed, order.cancelled,
 *   ledger.closed, sms.sent, appointment.confirmed
 * 
 * Wildcard listeners:
 *   app()->events()->listen('order.*', fn($payload, $event) => logEvent($event, $payload));
 * 
 * @package Ikabud\Kernel
 * @version 1.0.0
 */

namespace Ikabud\Kernel;

class EventBus
{
    private static ?EventBus $instance = null;

    /** @var array<string, array<int, array{callback: callable, priority: int, module: string}>> */
    private array $listeners = [];

    /** @var array<int, array{event: string, payload: array, fired_at: float, module: string}> */
    private array $history = [];

    /** @var bool Whether to record event history (useful for debugging/testing) */
    private bool $recordHistory = false;

    /** @var int Max history entries to keep */
    private int $maxHistory = 100;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register an event listener.
     * 
     * @param string   $event    Event name or wildcard pattern (e.g. 'order.*')
     * @param callable $callback Receives (array $payload, string $eventName)
     * @param int      $priority Lower runs first (default 10)
     * @param string   $module   Module ID for debugging (auto-detected if empty)
     */
    public function listen(string $event, callable $callback, int $priority = 10, string $module = ''): void
    {
        if ($module === '' && \function_exists('moduleCurrentId')) {
            $resolved = \moduleCurrentId();
            $module = \is_string($resolved) ? $resolved : '';
        }

        $this->listeners[$event][] = [
            'callback' => $callback,
            'priority' => $priority,
            'module'   => $module,
        ];
        // Stable sort by priority
        usort($this->listeners[$event], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    /**
     * Fire an event. All matching listeners (exact + wildcard) are called.
     * 
     * @param string $event   Event name (e.g. 'order.placed')
     * @param array  $payload Event data
     * @param string $module  Source module ID for audit trail
     * @return int Number of listeners called
     */
    public function fire(string $event, array $payload = [], string $module = ''): int
    {
        $called = 0;

        if ($this->recordHistory) {
            $this->history[] = [
                'event'    => $event,
                'payload'  => $payload,
                'fired_at' => microtime(true),
                'module'   => $module,
            ];
            if (count($this->history) > $this->maxHistory) {
                array_shift($this->history);
            }
        }

        // Collect matching listeners: exact match + wildcard patterns
        $matched = [];

        // Exact match
        if (!empty($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $entry) {
                $matched[] = $entry;
            }
        }

        // Wildcard match: 'order.*' matches 'order.placed', 'order.cancelled', etc.
        foreach ($this->listeners as $pattern => $entries) {
            if ($pattern === $event) continue; // already handled
            if (!str_contains($pattern, '*')) continue;

            $regex = '/^' . str_replace(['\\*', '\\?'], ['[^.]+', '.'], preg_quote($pattern, '/')) . '$/';
            if (preg_match($regex, $event)) {
                foreach ($entries as $entry) {
                    $matched[] = $entry;
                }
            }
        }

        // Sort all matched by priority
        usort($matched, fn($a, $b) => $a['priority'] <=> $b['priority']);

        // Fire
        foreach ($matched as $entry) {
            try {
                $listenerModule = (string)($entry['module'] ?? '');
                if ($listenerModule !== '' && \function_exists('moduleWithContext')) {
                    \moduleWithContext($listenerModule, static function () use ($entry, $payload, $event): void {
                        ($entry['callback'])($payload, $event);
                    });
                } else {
                    ($entry['callback'])($payload, $event);
                }
                $called++;
            } catch (\Throwable $e) {
                // Log but don't break the chain — events are fire-and-forget
                if (function_exists('write_log')) {
                    if (str_starts_with($event, 't.')) {
                        continue;
                    }
                    $level = 'error';
                    if (PHP_SAPI === 'cli' || str_starts_with($event, 't.') || (string)($entry['module'] ?? '') === '') {
                        $level = 'warning';
                    }
                    write_log("EventBus: listener error on '{$event}' from module '{$entry['module']}': " . $e->getMessage(), $level, [
                        'event'  => $event,
                        'module' => $entry['module'],
                        'trace'  => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        return $called;
    }

    /**
     * Check if any listeners are registered for an event.
     */
    public function hasListeners(string $event): bool
    {
        if (!empty($this->listeners[$event])) {
            return true;
        }
        // Check wildcards
        foreach ($this->listeners as $pattern => $_) {
            if (!str_contains($pattern, '*')) continue;
            $regex = '/^' . str_replace(['\\*', '\\?'], ['[^.]+', '.'], preg_quote($pattern, '/')) . '$/';
            if (preg_match($regex, $event)) return true;
        }
        return false;
    }

    /**
     * Get all registered event names (including wildcards).
     * @return string[]
     */
    public function registeredEvents(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * Get listener count for an event (exact only, not wildcard).
     */
    public function listenerCount(string $event): int
    {
        return count($this->listeners[$event] ?? []);
    }

    /**
     * Remove all listeners for an event.
     */
    public function off(string $event): void
    {
        unset($this->listeners[$event]);
    }

    /**
     * Enable/disable history recording (for debugging/testing).
     */
    public function enableHistory(bool $enable = true): void
    {
        $this->recordHistory = $enable;
    }

    /**
     * Get recorded event history.
     * @return array<int, array{event: string, payload: array, fired_at: float, module: string}>
     */
    public function history(): array
    {
        return $this->history;
    }

    /**
     * Clear all listeners and history (for tests).
     */
    public function reset(): void
    {
        $this->listeners = [];
        $this->history = [];
    }
}
