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
    /**
     * Whether any handler wrote an audit row during this request.
     *
     * Static on purpose: the question is about the request, not about one
     * module context. The kernel's mutation fallback reads it to record a change
     * that no handler described, so nothing is silently unlogged.
     */
    private static bool $auditWrittenThisRequest = false;

    public static function auditWritten(): bool
    {
        return self::$auditWrittenThisRequest;
    }

    /**
     * Clear the flag. Only needed when one process serves more than one request
     * (tests, long-running workers).
     */
    public static function resetAuditTracking(): void
    {
        self::$auditWrittenThisRequest = false;
    }

    private App $app;
    private string $moduleId;
    private DatabaseContract $db;
    private array $manifest;

    public function __construct(App $app, string $moduleId, DatabaseContract $db, array $manifest = [])
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
    public function db(): DatabaseContract
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

    /**
     * Record an audit row.
     *
     * $connection is the database the change was made in. Normally resolved
     * from the current request, but a caller recording after the request has
     * ended (a shutdown hook, a queue worker) must pass the connection it used:
     * late re-resolution can land on the base database instead of the tenant
     * one, filing the record in a database where the change did not happen.
     */
    public function audit(
        string $action,
        ?int $branchId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        mixed $oldData = null,
        mixed $newData = null,
        ?string $reason = null,
        ?\PDO $connection = null
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
        $metadataJson = $this->buildAuditMetadataJson($user);

        try {
            KernelPDO::kernelEscalationEnter();
            $db = $connection ?? $this->app->db();
            $supportsActorColumns = $this->auditLogSupportsActorColumns($db);
            $includeMetadata = $metadataJson !== null && $this->auditLogSupportsMetadataColumn($db);

            $rowData = [
                'module' => $this->moduleId,
                'actor' => $actorId,
                'actor_mod' => $actorModuleUserId,
                'actor_src' => $actorSource,
                'branch' => $branchId,
                'action' => $action,
                'etype' => $entityType,
                'eid' => $entityId,
                'old' => $oldData !== null ? json_encode($oldData) : null,
                'new' => $newData !== null ? json_encode($newData) : null,
            ];

            try {
                $this->insertAuditRow($db, $rowData, $supportsActorColumns, $includeMetadata ? $metadataJson : null);
            } catch (\Throwable $insertError) {
                if ($includeMetadata) {
                    // Fail-safe: if the metadata is what failed, the audit row is
                    // still written exactly as it was written before this change.
                    // Never lose an audit record for the sake of the name.
                    $this->log('Audit metadata write failed; row written without it: ' . $insertError->getMessage(), 'warning');
                    $this->insertAuditRow($db, $rowData, $supportsActorColumns, null);
                } else {
                    throw $insertError;
                }
            }

            // A specific row exists, so the kernel's mutation fallback stands down.
            self::$auditWrittenThisRequest = true;
        } catch (\Throwable $e) {
            // Non-fatal — log but don't crash
            $this->log('Audit log write failed: ' . $e->getMessage(), 'error');
        } finally {
            KernelPDO::kernelEscalationLeave();
        }
    }

    /**
     * @param array<string, mixed> $row Pre-encoded audit column values.
     */
    private function insertAuditRow(\PDO $db, array $row, bool $supportsActorColumns, ?string $metadataJson): void
    {
        if ($supportsActorColumns) {
            $columns = 'module, actor_user_id, actor_module_user_id, actor_source, branch_id, action, entity_type, entity_id, old_data, new_data';
            $values = ':module, :actor, :actor_mod, :actor_src, :branch, :action, :etype, :eid, :old, :new';
            $params = [
                ':module' => $row['module'],
                ':actor' => $row['actor'],
                ':actor_mod' => $row['actor_mod'],
                ':actor_src' => $row['actor_src'],
                ':branch' => $row['branch'],
                ':action' => $row['action'],
                ':etype' => $row['etype'],
                ':eid' => $row['eid'],
                ':old' => $row['old'],
                ':new' => $row['new'],
            ];
        } else {
            $columns = 'module, actor_user_id, branch_id, action, entity_type, entity_id, old_data, new_data';
            $values = ':module, :actor, :branch, :action, :etype, :eid, :old, :new';
            $params = [
                ':module' => $row['module'],
                ':actor' => $row['actor'],
                ':branch' => $row['branch'],
                ':action' => $row['action'],
                ':etype' => $row['etype'],
                ':eid' => $row['eid'],
                ':old' => $row['old'],
                ':new' => $row['new'],
            ];
        }
        if ($metadataJson !== null) {
            $columns .= ', metadata_json';
            $values .= ', :metadata';
            $params[':metadata'] = $metadataJson;
        }

        $stmt = $db->prepare("INSERT INTO audit_logs ({$columns}) VALUES ({$values})");
        $stmt->execute($params);
    }

    private function auditLogSupportsActorColumns(\PDO $db): bool
    {
        try {
            $moduleUserStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'actor_module_user_id'");
            $hasModuleUserId = $moduleUserStmt && $moduleUserStmt->fetchColumn() !== false;
            $sourceStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'actor_source'");
            $hasActorSource = $sourceStmt && $sourceStmt->fetchColumn() !== false;
            return $hasModuleUserId && $hasActorSource;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the per-event identity metadata for daily-ledger audit rows.
     *
     * Scoped to daily-ledger on purpose: other modules must keep the exact audit
     * shape they have today. The name is the value present in the session at write
     * time, so a later profile rename cannot retroactively relabel history.
     */
    private function buildAuditMetadataJson(?array $user): ?string
    {
        if ($this->moduleId !== 'daily-ledger' || $user === null) {
            return null;
        }

        $metadata = [];
        $actorName = '';
        if (isset($user['full_name']) && trim((string)$user['full_name']) !== '') {
            $actorName = trim((string)$user['full_name']);
        } elseif (isset($user['name']) && trim((string)$user['name']) !== '') {
            $actorName = trim((string)$user['name']);
        }
        $actorUsername = trim((string)($user['username'] ?? ''));
        if ($actorName !== '') {
            $metadata['actor_name'] = $actorName;
        }
        if ($actorUsername !== '') {
            $metadata['actor_username'] = $actorUsername;
        }
        if ($metadata === []) {
            return null;
        }

        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) && $encoded !== '' ? $encoded : null;
    }

    private function auditLogSupportsMetadataColumn(\PDO $db): bool
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'metadata_json'");
            return $stmt && $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
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
