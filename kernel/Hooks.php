<?php
/**
 * Ikabud Kernel Hook System
 * 
 * Provides a clean decoupling layer between the kernel OS and userland (modules).
 * The kernel never calls module functions directly — it fires hooks.
 * Modules register listeners during their bootstrap phase.
 * 
 * Hook types:
 *   filter  — transforms a value through a chain of callbacks (null means no change)
 *   action  — fires side-effect callbacks (returns nothing)
 * 
 * The kernel defines well-known hooks it fires:
 *   'kernel.nav_items'       (filter)  — build navigation items for the current user
 *   'kernel.gui_context'     (filter)  — merge GUI settings into the theme context
 *   'kernel.gui_css'         (filter)  — generate CSS override block
 *   'kernel.home_url'        (filter)  — resolve the home URL for a role
 *   'kernel.request.before_dispatch' (filter) — mutate or short-circuit request dispatch context before route matching
 *   'kernel.csrf_token'      (filter)  — provide CSRF token string
 *   'kernel.csrf_field'      (filter)  — provide CSRF hidden input HTML
 *   'kernel.render_context'  (filter)  — modify the global render context before template compilation
 *   'kernel.database.query.before' (filter)  — inspect/modify SQL + params before execution (KernelPDO)
 *   'kernel.boot'            (action)  — fired after kernel boot completes
 *   'kernel.shutdown'        (action)  — fired at shutdown
 *
 * Related events fired via EventBus (not Hooks):
 *   'kernel.database.query.after'   — emitted after each query with timing + row count
 *
 * @package Ikabud\Kernel
 * @version 1.1.0
 */

namespace Ikabud\Kernel;

final class Hooks
{
    private static ?Hooks $instance = null;

    /** @var array<string, array<int, array{callback: callable, priority: int, module: string}>> */
    private array $listeners = [];

    /** @var array<string, bool> */
    private array $listenerSortDirty = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a listener for a hook.
     * Lower priority numbers run first (default 10).
     */
    public function on(string $hook, callable $callback, int $priority = 10): void
    {
        $module = '';
        if (\function_exists('moduleCurrentId')) {
            $resolved = \moduleCurrentId();
            $module = \is_string($resolved) ? $resolved : '';
        }

        $this->listeners[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'module' => $module,
        ];
        $this->listenerSortDirty[$hook] = true;
    }

    /**
     * @return array<int, array{callback: callable, priority: int, module: string}>
     */
    private function listenersFor(string $hook): array
    {
        if (empty($this->listeners[$hook])) {
            return [];
        }

        if (!empty($this->listenerSortDirty[$hook])) {
            usort($this->listeners[$hook], fn($a, $b) => $a['priority'] <=> $b['priority']);
            $this->listenerSortDirty[$hook] = false;
        }

        return $this->listeners[$hook];
    }

    /**
     * Fire a filter hook — passes $value through each listener and returns the result.
     * Each listener receives ($value, ...$args). Returning null preserves the current value.
     */
    public function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $this->applyFilter($hook, $value, false, ...$args);
    }

    /**
     * Fire a filter hook that allows null to become the next value in the chain.
     * Use this when null is a meaningful result instead of a no-op sentinel.
     */
    public function filterNullable(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $this->applyFilter($hook, $value, true, ...$args);
    }

    private function applyFilter(string $hook, mixed $value, bool $allowNullResult, mixed ...$args): mixed
    {
        $listeners = $this->listenersFor($hook);
        if ($listeners === []) {
            return $value;
        }

        foreach ($listeners as $entry) {
            try {
                $result = null;
                $module = (string)($entry['module'] ?? '');
                if ($module !== '' && \function_exists('moduleWithContext')) {
                    $result = \moduleWithContext($module, static function () use ($entry, $value, $args) {
                        return ($entry['callback'])($value, ...$args);
                    });
                } else {
                    $result = ($entry['callback'])($value, ...$args);
                }
                if ($allowNullResult || $result !== null) {
                    $value = $result;
                }
            } catch (\Throwable $e) {
                if (function_exists('write_log')) {
                    write_log("Hooks: filter '{$hook}' listener threw: " . $e->getMessage(), 'error', [
                        'hook'  => $hook,
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                    ]);
                }
                // Continue chain with previous $value — bad listener is skipped
            }
        }
        return $value;
    }

    /**
     * Fire an action hook — calls each listener for side effects, returns nothing.
     */
    public function action(string $hook, mixed ...$args): void
    {
        $listeners = $this->listenersFor($hook);
        if ($listeners === []) {
            return;
        }
        foreach ($listeners as $entry) {
            try {
                $module = (string)($entry['module'] ?? '');
                if ($module !== '' && \function_exists('moduleWithContext')) {
                    \moduleWithContext($module, static function () use ($entry, $args): void {
                        ($entry['callback'])(...$args);
                    });
                } else {
                    ($entry['callback'])(...$args);
                }
            } catch (\Throwable $e) {
                if (function_exists('write_log')) {
                    write_log("Hooks: action '{$hook}' listener threw: " . $e->getMessage(), 'error', [
                        'hook'  => $hook,
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                    ]);
                }
                // Continue — bad listener doesn't kill other listeners
            }
        }
    }

    /**
     * Check if any listener is registered for a hook.
     */
    public function has(string $hook): bool
    {
        return !empty($this->listeners[$hook]);
    }

    /**
     * Remove all listeners for a hook (useful in tests).
     */
    public function off(string $hook): void
    {
        unset($this->listeners[$hook]);
        unset($this->listenerSortDirty[$hook]);
    }

    /**
     * Reset all hooks (useful in tests).
     */
    public function reset(): void
    {
        $this->listeners = [];
        $this->listenerSortDirty = [];
    }
}
