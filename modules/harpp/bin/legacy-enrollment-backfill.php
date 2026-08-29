<?php

declare(strict_types=1);

// Phase 1 remediation (Allow HARPP Workspaces): enroll every active, non-deleted
// HARPP user into the 'legacy' workspace using migration 007's role mapping.
//
// Migration 007 only enrolled users present when it ran (it is guarded by the
// one-time harpp_migration_007_progress setting), so users created afterwards
// have no legacy membership. This backfill re-runs the exact membership INSERT
// without that one-time guard. It is idempotent and safe to re-run.
//
// Usage: php modules/harpp/bin/legacy-enrollment-backfill.php <tenant_id>

$root = dirname(__DIR__, 3);
require_once $root . '/bootstrap.php';

$tenantId = (int)($_SERVER['argv'][1] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php modules/harpp/bin/legacy-enrollment-backfill.php <tenant_id>\n");
    exit(1);
}

app()->tenant()->setTenantId($tenantId);
require_once dirname(__DIR__) . '/helpers.php';
$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$pdo = app()->dbForTenant($tenantId);
$db = new \Ikabud\Kernel\Contracts\ModuleDB($pdo, 'harpp', (array)($manifest['owns_tables'] ?? []), (array)($manifest['reads_tables'] ?? []));

$legacy = $db->prepare("SELECT id,created_by FROM harpp_workspaces WHERE workspace_key='legacy' LIMIT 1");
$legacy->execute();
$legacyRow = $legacy->fetch(PDO::FETCH_ASSOC);
$legacyId = (int)($legacyRow['id'] ?? 0);
$creatorId = (int)($legacyRow['created_by'] ?? 0);
if ($legacyId <= 0) {
    fwrite(STDERR, "legacy workspace not found for tenant {$tenantId}; ensure migration 007 ran.\n");
    exit(2);
}

$statement = $db->prepare(
    "INSERT INTO harpp_workspace_memberships (workspace_id,user_id,roles,status,created_by,version,created_at,updated_at)
     SELECT :workspace,u.id,
            CASE WHEN u.role IN ('owner','admin') THEN JSON_ARRAY('manager','operator','reviewer','viewer')
                 ELSE JSON_ARRAY('operator','reviewer','viewer') END,
            'active',:creator,1,CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6)
     FROM harpp_users u
     WHERE u.is_active=1 AND u.deleted_at IS NULL
     ON DUPLICATE KEY UPDATE roles=VALUES(roles),status='active'"
);
$statement->execute([':workspace' => $legacyId, ':creator' => $creatorId]);
$rows = $statement->rowCount();

$missing = (int)$db->query(
    "SELECT COUNT(*) FROM harpp_users u
     WHERE u.is_active=1 AND u.deleted_at IS NULL
       AND NOT EXISTS (
           SELECT 1 FROM harpp_workspace_memberships wm
           JOIN harpp_workspaces w ON w.id=wm.workspace_id
           WHERE w.workspace_key='legacy' AND wm.user_id=u.id
       )"
)->fetchColumn();

echo "tenant {$tenantId}: enrolled/updated {$rows} membership row(s); active users still missing legacy enrollment: {$missing}\n";
exit($missing === 0 ? 0 : 3);
