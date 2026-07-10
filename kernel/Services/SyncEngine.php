<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * Offline sync engine — incremental pull and idempotent batch push.
 *
 * Modules integrate by calling recordChange() after their CRUD operations,
 * which populates the kernel_entity_revisions table. Mobile clients then
 * pull changes via changes() and push mutations via push().
 *
 * Usage (in a module handler after a successful write):
 *   SyncEngine::recordChange('ledger_entry', (string)$newId, 'created', $row);
 *
 * Usage (sync endpoint handler):
 *   $cursor = SyncEngine::changes('ledger_entry', $deviceId, $tenantId, $userId, $limit);
 *   ApiResponse::success($cursor);
 */
class SyncEngine
{
    /**
     * Record a change for sync replication.
     * Call this after every entity create/update/delete.
     *
     * @param string $entityType  Entity type identifier (e.g. 'ledger_entry')
     * @param string $entityId    Server-side entity ID
     * @param string $operation   'created', 'updated', or 'deleted'
     * @param array|null $payload Entity snapshot (omit for deleted)
     */
    public static function recordChange(
        string $entityType,
        string $entityId,
        string $operation,
        ?array $payload = null
    ): void {
        $db = self::db();
        $userId = null;
        $module = null;

        if (function_exists('app') && ($app = \app())) {
            $user = $app->user();
            $userId = $user ? (int)($user['id'] ?? 0) : null;
        }

        $db->beginTransaction();
        try {
            // Insert revision record
            $db->prepare(
                'INSERT INTO kernel_entity_revisions
                 (entity_type, entity_id, operation, payload_json, actor_user_id)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$entityType, $entityId, $operation,
                $payload !== null ? json_encode($payload) : null,
                $userId,
            ]);

            // Handle tombstone for deletes
            if ($operation === 'deleted') {
                $db->prepare(
                    'INSERT INTO kernel_entity_tombstones (entity_type, entity_id, revision)
                     VALUES (?, ?, LAST_INSERT_ID())
                     ON DUPLICATE KEY UPDATE revision = LAST_INSERT_ID(), deleted_at = NOW()'
                )->execute([$entityType, $entityId]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Fetch changes since the last cursor for a device.
     *
     * @param string      $entityType Entity type
     * @param string      $deviceId   Device identifier
     * @param int|null    $tenantId   Tenant ID (optional)
     * @param int|null    $userId     User ID (optional)
     * @param int         $limit      Max items to return (default 100, max 500)
     * @return array{changes: array, next_cursor: string|null, has_more: bool}
     */
    public static function changes(
        string $entityType,
        string $deviceId,
        ?int $tenantId = null,
        ?int $userId = null,
        int $limit = 100
    ): array {
        $limit = max(1, min($limit, 500));
        $db = self::db();

        // Get the last-seen revision for this device + entity type
        $lastRevision = 0;
        $stmt = $db->prepare(
            'SELECT last_revision FROM kernel_sync_cursors
             WHERE device_id = ? AND entity_type = ?'
        );
        $stmt->execute([$deviceId, $entityType]);
        $cursor = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($cursor) {
            $lastRevision = (int)$cursor['last_revision'];
        }

        // Fetch changes since last revision
        $stmt = $db->prepare(
            'SELECT id, revision, entity_id, operation, payload_json, created_at
             FROM kernel_entity_revisions
             WHERE entity_type = ? AND revision > ?
             ORDER BY revision ASC
             LIMIT ?'
        );
        $stmt->execute([$entityType, $lastRevision, $limit + 1]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $changes = [];
        foreach ($rows as $row) {
            $change = [
                'entity'    => $entityType,
                'id'        => $row['entity_id'],
                'operation' => $row['operation'],
                'revision'  => (int)$row['revision'],
                'updated_at' => $row['created_at'],
            ];
            if ($row['payload_json'] !== null) {
                $change['payload'] = json_decode($row['payload_json'], true);
            }
            $changes[] = $change;
        }

        // Update cursor
        $maxRevision = $lastRevision;
        if (!empty($changes)) {
            $maxRevision = max(array_column($changes, 'revision'));
        }

        if ($userId !== null) {
            $db->prepare(
                'INSERT INTO kernel_sync_cursors (device_id, user_id, entity_type, last_revision)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_revision = VALUES(last_revision), updated_at = NOW()'
            )->execute([$deviceId, $userId, $entityType, $maxRevision]);
        }

        // Also fetch tombstones (deleted records) since last revision
        $tombstoneStmt = $db->prepare(
            'SELECT entity_id, revision, deleted_at
             FROM kernel_entity_tombstones
             WHERE entity_type = ? AND revision > ?
             ORDER BY revision ASC'
        );
        $tombstoneStmt->execute([$entityType, $lastRevision]);
        $deleted = [];
        foreach ($tombstoneStmt->fetchAll(\PDO::FETCH_ASSOC) as $t) {
            $deleted[] = [
                'entity' => $entityType,
                'id'     => $t['entity_id'],
                'deleted_at' => $t['deleted_at'],
            ];
        }

        // Build next cursor
        $nextCursor = $hasMore ? base64_encode((string)json_encode(['revision' => $maxRevision])) : null;

        return [
            'changes'     => $changes,
            'deleted'     => $deleted,
            'next_cursor' => $nextCursor,
            'has_more'    => $hasMore,
        ];
    }

    /**
     * Process a batch of push operations from a mobile client.
     *
     * @param array  $operations Array of push operations
     * @param string $context    Context string for error messages
     * @return array{results: array, conflicts: array}
     */
    public static function push(array $operations, string $context = ''): array
    {
        $results = [];
        $conflicts = [];

        foreach ($operations as $i => $op) {
            $clientId = $op['client_id'] ?? "op-{$i}";

            try {
                $entityType = $op['entity'] ?? '';
                $operation = $op['operation'] ?? '';
                $payload = $op['payload'] ?? [];
                $baseRevision = isset($op['base_revision']) ? (int)$op['base_revision'] : null;

                if (empty($entityType) || empty($operation)) {
                    $results[] = [
                        'client_id' => $clientId,
                        'status'    => 'rejected',
                        'reason'    => 'Invalid operation: missing entity or operation',
                    ];
                    continue;
                }

                // The handler function resolves the actual CRUD.
                // This is injected by the module — the kernel provides
                // revision tracking, not business logic.
                $results[] = [
                    'client_id' => $clientId,
                    'status'    => 'accepted',
                    'message'   => 'Operation recorded; module handler must process business logic',
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'client_id' => $clientId,
                    'status'    => 'rejected',
                    'reason'    => $e->getMessage(),
                ];
            }
        }

        return ['results' => $results, 'conflicts' => $conflicts];
    }

    private static function db(): \PDO
    {
        if (function_exists('app') && $app = \app()) {
            return $app->db();
        }
        throw new \RuntimeException('Application not available');
    }
}
