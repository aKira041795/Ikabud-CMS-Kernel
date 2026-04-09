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
 * Deferred events:
 *   app()->events()->fireDeferred('order.placed', ['order_id' => 42]);
 *   // flushed automatically at shutdown or manually via flushDeferred()
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

    /** @var array<string, bool> */
    private array $listenerSortDirty = [];

    /** @var array<int, array{pattern: string, regex: string}> */
    private array $compiledWildcards = [];

    private bool $wildcardsDirty = true;

    /** @var bool Whether to record event history (useful for debugging/testing) */
    private bool $recordHistory = false;

    /** @var int Max history entries to keep */
    private int $maxHistory = 100;

    /** @var array<int, array{event: string, payload: array, module: string, queued_at: float}> */
    private array $deferred = [];

    private bool $deferredFlushRegistered = false;
    private bool $flushingDeferred = false;

    private const MAX_DEFERRED_FLUSH_BATCHES = 100;

    private function __construct()
    {
        $this->registerDeferredFlush();
    }

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
        $this->listenerSortDirty[$event] = true;
        if ($this->isWildcardPattern($event)) {
            $this->wildcardsDirty = true;
        }
    }

    private function isWildcardPattern(string $event): bool
    {
        return strpbrk($event, '*?') !== false;
    }

    private function patternToRegex(string $pattern): string
    {
        return '/^' . str_replace(['\\*', '\\?'], ['[^.]+', '.'], preg_quote($pattern, '/')) . '$/';
    }

    /**
     * @return array<int, array{callback: callable, priority: int, module: string}>
     */
    private function listenersFor(string $event): array
    {
        if (empty($this->listeners[$event])) {
            return [];
        }

        if (!empty($this->listenerSortDirty[$event])) {
            usort($this->listeners[$event], fn($a, $b) => $a['priority'] <=> $b['priority']);
            $this->listenerSortDirty[$event] = false;
        }

        return $this->listeners[$event];
    }

    /**
     * @return array<int, array{pattern: string, regex: string}>
     */
    private function wildcardPatterns(): array
    {
        if (!$this->wildcardsDirty) {
            return $this->compiledWildcards;
        }

        $compiled = [];
        foreach (array_keys($this->listeners) as $pattern) {
            if (!$this->isWildcardPattern($pattern)) {
                continue;
            }

            $compiled[] = [
                'pattern' => $pattern,
                'regex' => $this->patternToRegex($pattern),
            ];
        }

        $this->compiledWildcards = $compiled;
        $this->wildcardsDirty = false;
        return $this->compiledWildcards;
    }

    /**
     * @param array<int, array{callback: callable, priority: int, module: string}> $left
     * @param array<int, array{callback: callable, priority: int, module: string}> $right
     * @return array<int, array{callback: callable, priority: int, module: string}>
     */
    private function mergeListeners(array $left, array $right): array
    {
        if ($left === []) {
            return $right;
        }
        if ($right === []) {
            return $left;
        }

        $merged = [];
        $leftIndex = 0;
        $rightIndex = 0;
        $leftCount = count($left);
        $rightCount = count($right);

        while ($leftIndex < $leftCount && $rightIndex < $rightCount) {
            if (($left[$leftIndex]['priority'] ?? 0) <= ($right[$rightIndex]['priority'] ?? 0)) {
                $merged[] = $left[$leftIndex++];
                continue;
            }

            $merged[] = $right[$rightIndex++];
        }

        while ($leftIndex < $leftCount) {
            $merged[] = $left[$leftIndex++];
        }
        while ($rightIndex < $rightCount) {
            $merged[] = $right[$rightIndex++];
        }

        return $merged;
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
        $matchedWildcards = [];
        foreach ($this->wildcardPatterns() as $compiled) {
            if (($compiled['pattern'] ?? '') === $event) {
                continue;
            }

            if (preg_match((string)$compiled['regex'], $event) === 1) {
                foreach ($this->listenersFor((string)$compiled['pattern']) as $entry) {
                    $matchedWildcards[] = $entry;
                }
            }
        }

        if (count($matchedWildcards) > 1) {
            usort($matchedWildcards, fn($a, $b) => $a['priority'] <=> $b['priority']);
        }

        $matched = $this->mergeListeners($this->listenersFor($event), $matchedWildcards);

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

        try {
            if (
                class_exists(IntegrationBridge::class)
                && $event !== ''
                && !str_starts_with($event, 't.')
                && !str_starts_with($event, 'kernel.database.')
                && !str_starts_with($event, 'integration.result.')
            ) {
                IntegrationBridge::handle($payload, $event);
            }
        } catch (\Throwable $e) {
            if (function_exists('write_log')) {
                write_log("EventBus: integration bridge error on '{$event}': " . $e->getMessage(), 'warning', [
                    'event' => $event,
                    'module' => $module,
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return $called;
    }

    /**
     * Check if any listeners are registered for an event.
     */
    public function hasListeners(string $event): bool
    {
        if ($this->listenersFor($event) !== []) {
            return true;
        }
        // Check wildcards
        foreach ($this->wildcardPatterns() as $compiled) {
            if (preg_match((string)$compiled['regex'], $event) === 1) {
                return true;
            }
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

    private function registerDeferredFlush(): void
    {
        if ($this->deferredFlushRegistered) {
            return;
        }

        register_shutdown_function([$this, 'flushDeferred']);
        $this->deferredFlushRegistered = true;
    }

    public function fireDeferred(string $event, array $payload = [], string $module = ''): int
    {
        $this->deferred[] = [
            'event' => $event,
            'payload' => $payload,
            'module' => $module,
            'queued_at' => microtime(true),
        ];

        return count($this->deferred);
    }

    public function defer(string $event, array $payload = [], string $module = ''): int
    {
        return $this->fireDeferred($event, $payload, $module);
    }

    public function deferredCount(): int
    {
        return count($this->deferred);
    }

    public function flushDeferred(): int
    {
        if ($this->flushingDeferred || $this->deferred === []) {
            return 0;
        }

        $this->flushingDeferred = true;
        $called = 0;
        $batches = 0;

        try {
            while ($this->deferred !== []) {
                $queue = $this->deferred;
                $this->deferred = [];

                foreach ($queue as $queued) {
                    $called += $this->fire(
                        (string)($queued['event'] ?? ''),
                        is_array($queued['payload'] ?? null) ? $queued['payload'] : [],
                        (string)($queued['module'] ?? '')
                    );
                }

                $batches++;
                if ($batches >= self::MAX_DEFERRED_FLUSH_BATCHES) {
                    if (function_exists('write_log')) {
                        write_log('EventBus: deferred flush exceeded maximum batches and was aborted', 'warning', [
                            'remaining' => count($this->deferred),
                        ]);
                    }
                    $this->deferred = [];
                    break;
                }
            }
        } finally {
            $this->flushingDeferred = false;
        }

        return $called;
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
        unset($this->listenerSortDirty[$event]);
        if ($this->isWildcardPattern($event)) {
            $this->wildcardsDirty = true;
        }
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
        $this->listenerSortDirty = [];
        $this->compiledWildcards = [];
        $this->wildcardsDirty = true;
        $this->deferred = [];
        $this->flushingDeferred = false;
    }
}
