<?php
/**
 * Ikabud Kernel — Module Context
 * 
 * The scoped gateway object passed to every module handler.
 * Implements AuthContract, LogContract, and provides a scoped DatabaseContract.
 * 
 * This is the ONLY object modules should use to interact with the kernel.
 * It enforces:
 *   - Table ownership (via ModuleDB)
 *   - Centralized audit logging (module ID auto-tagged)
 *   - Auth delegation (no direct JWT/session access)
 *   - Template rendering (scoped to module's template directory)
 * 
 * Modules still have access to app() in PHP (we can't prevent it),
 * but ModuleContext is the documented, supported, and auditable interface.
 * Any module calling app()->db() directly will be flagged in code review.
 * 
 * @package Ikabud\Kernel\Contracts
 */

namespace Ikabud\Kernel\Contracts;

use Ikabud\Kernel\App;
use Ikabud\Kernel\Database\KernelPDO;

final class ModuleContext implements AuthContract, LogContract
{
    private App $app;
    private string $moduleId;
    private ModuleDB $db;
    private array $manifest;

    public function __construct(App $app, string $moduleId, ModuleDB $db, array $manifest = [])
    {
        $this->app = $app;
        $this->moduleId = $moduleId;
        $this->db = $db;
        $this->manifest = $manifest;
    }

    // ── Identity ─────────────────────────────────────────────────────

    /**
     * Get the module ID.
     */
    public function moduleId(): string
    {
        return $this->moduleId;
    }

    // ── DatabaseContract (scoped) ────────────────────────────────────

    /**
     * Get the scoped database gateway.
     * This enforces table ownership — modules can only touch declared tables.
     */
    public function db(): ModuleDB
    {
        return $this->db;
    }

    // ── AuthContract ─────────────────────────────────────────────────

    public function user(): ?array
    {
        return $this->app->user();
    }

    public function requireAuth(): array
    {
        return $this->app->requireAuth();
    }

    public function requireRole(string $role): array
    {
        return $this->app->requireRole($role);
    }

    public function requireAnyRole(string ...$roles): array
    {
        return $this->app->requireAnyRole(...$roles);
    }

    public function hasRole(string $role): bool
    {
        return $this->app->hasRole($role);
    }

    public function isAuthenticated(): bool
    {
        return $this->app->isAuthenticated();
    }

    // ── LogContract ──────────────────────────────────────────────────

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        $context['module'] = $this->moduleId;
        $this->app->log("[{$this->moduleId}] {$message}", $level, $context);
    }

    public function audit(
        string $action,
        ?int $branchId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        mixed $oldData = null,
        mixed $newData = null,
        ?string $reason = null
    ): void {
        $user = $this->app->user();
        $source = (string)($user['source'] ?? '');
        // audit_logs.actor_user_id references users.id, so only kernel actors belong there.
        $actorId = ($user && $source === 'kernel') ? (int)($user['id'] ?? $user['sub'] ?? 0) : null;
        if ($actorId !== null && $actorId <= 0) {
            $actorId = null;
        }
        // Record all non-kernel identities in the module-scoped actor slot.
        $actorModuleUserId = ($user && $source !== '' && $source !== 'kernel') ? (int)($user['id'] ?? $user['sub'] ?? 0) : null;
        if ($actorModuleUserId !== null && $actorModuleUserId <= 0) {
            $actorModuleUserId = null;
        }
        $actorSource = $source !== '' ? $source : null;

        try {
            KernelPDO::kernelEscalationEnter();
            $stmt = $this->app->db()->prepare(
                'INSERT INTO audit_logs (module, actor_user_id, actor_module_user_id, actor_source, branch_id, action, entity_type, entity_id, old_data, new_data) '
                . 'VALUES (:module, :actor, :actor_mod, :actor_src, :branch, :action, :etype, :eid, :old, :new)'
            );
            $stmt->execute([
                ':module'     => $this->moduleId, // always use the scoped module ID, not the passed one
                ':actor'      => $actorId,
                ':actor_mod'  => $actorModuleUserId,
                ':actor_src'  => $actorSource,
                ':branch'     => $branchId,
                ':action'     => $action,
                ':etype'      => $entityType,
                ':eid'        => $entityId,
                ':old'        => $oldData !== null ? json_encode($oldData) : null,
                ':new'        => $newData !== null ? json_encode($newData) : null,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — log but don't crash
            $this->log('Audit log write failed: ' . $e->getMessage(), 'error');
        } finally {
            KernelPDO::kernelEscalationLeave();
        }
    }

    // ── Rendering ────────────────────────────────────────────────────

    /**
    * Render a template with the kernel's full context.
    * Module templates are expected under templates/modules/{moduleId}/ or
    * a contextual subfolder that mirrors the module path under modules/.
     */
    public function render(string $template, array $context = []): string
    {
        return $this->app->render($template, $context);
    }

    // ── Request Helpers ──────────────────────────────────────────────

    /**
     * Get sanitized request input.
     */
    public function input(?string $key = null, $default = null)
    {
        return $this->app->input($key, $default);
    }

    /**
     * Send a JSON response and exit.
     */
    public function json(array $data, int $status = 200): void
    {
        $this->app->json($data, $status);
    }

    /**
     * Redirect and exit.
     */
    public function redirect(string $url, int $status = 302): void
    {
        $this->app->redirect($url, $status);
    }

    /**
     * Check if the current request is HTMX.
     */
    public function isHtmx(): bool
    {
        return $this->app->isHtmx();
    }

    /**
     * Check if the current request is an HTMX boosted navigation.
     */
    public function isHtmxBoosted(): bool
    {
        return $this->app->isHtmxBoosted();
    }

    /**
     * Send HTMX response headers.
     */
    public function htmxResponse(array $headers = []): void
    {
        $this->app->htmxResponse($headers);
    }

    // ── Events ───────────────────────────────────────────────────────

    /**
     * Fire an event on the kernel EventBus (module ID auto-tagged).
     */
    public function fireEvent(string $event, array $payload = []): int
    {
        return $this->app->events()->fire($event, $payload, $this->moduleId);
    }

    /**
     * Listen to an event on the kernel EventBus (module ID auto-tagged).
     */
    public function listenEvent(string $event, callable $callback, int $priority = 10): void
    {
        $this->app->events()->listen($event, $callback, $priority, $this->moduleId);
    }

    // ── Settings ─────────────────────────────────────────────────────

    /**
     * Get this module's settings from the registry.
     */
    public function settings(): array
    {
        return $this->manifest['_settings'] ?? [];
    }

    /**
     * Get the full manifest for this module.
     */
    public function manifest(): array
    {
        return $this->manifest;
    }
}
