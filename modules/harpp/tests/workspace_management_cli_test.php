<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/bootstrap.php';
require_once $root . '/tests/harness/TestHarness.php';

use Harpp\Services\HarppProjectService;
use Harpp\Services\HarppWorkspaceService;

$h = new TestHarness('harpp-workspace-management');
$tenantId = (int)($_SERVER['argv'][1] ?? 1);
app()->tenant()->setTenantId($tenantId);
require_once $root . '/modules/harpp/helpers.php';
$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$pdo = app()->dbForTenant($tenantId);
$db = new \Ikabud\Kernel\Contracts\ModuleDB($pdo, 'harpp', (array)($manifest['owns_tables'] ?? []), (array)($manifest['reads_tables'] ?? []));
$assert = static function (string $name, bool $ok) use ($h): void { $h->test($name, $ok); };
$suffix = bin2hex(random_bytes(4));
$workspaceIds = [];
$userId = 0;

try {
    $requiredTables = ['harpp_workspaces', 'harpp_workspace_memberships', 'harpp_projects', 'harpp_project_memberships'];
    $tableStatement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
    foreach ($requiredTables as $table) {
        $tableStatement->execute([':table' => $table]);
        $assert("migration 007 table {$table} exists", (int)$tableStatement->fetchColumn() === 1);
    }
    $activeUsers = (int)$db->query('SELECT COUNT(*) FROM harpp_users WHERE is_active=1 AND deleted_at IS NULL')->fetchColumn();
    $legacyCount = (int)$db->query("SELECT COUNT(*) FROM harpp_workspaces WHERE workspace_key='legacy'")->fetchColumn();
    $legacyMissing = (int)$db->query("SELECT COUNT(*) FROM harpp_users u WHERE u.is_active=1 AND u.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM harpp_workspace_memberships wm JOIN harpp_workspaces w ON w.id=wm.workspace_id WHERE w.workspace_key='legacy' AND wm.user_id=u.id)")->fetchColumn();
    $assert('legacy workspace exists when active users exist', $activeUsers === 0 || $legacyCount === 1);
    $h->detail("Phase 1 diagnostic: {$legacyMissing} active user(s) lack legacy workspace enrollment; migration 007 only enrolled users present when it ran.");
    foreach (['harpp_conversations', 'harpp_decisions'] as $table) {
        $nullScope = (int)$db->query("SELECT COUNT(*) FROM {$table} WHERE workspace_id IS NULL OR visibility IS NULL OR version IS NULL")->fetchColumn();
        $assert("{$table} scope columns are fully backfilled", $nullScope === 0);
    }
    $requiredIndexes = ['idx_harpp_conversation_scope', 'idx_harpp_decision_scope'];
    $indexStatement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME=:name');
    foreach ($requiredIndexes as $index) {
        $indexStatement->execute([':name' => $index]);
        $assert("migration 007 index {$index} exists", (int)$indexStatement->fetchColumn() > 0);
    }
    $foreignKeys = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY' AND CONSTRAINT_NAME IN ('fk_harpp_conversation_workspace','fk_harpp_conversation_project','fk_harpp_decision_workspace','fk_harpp_decision_project')")->fetchColumn();
    $assert('migration 007 scope foreign keys exist', $foreignKeys === 4);

    $owner = $db->query("SELECT id,role FROM harpp_users WHERE role IN ('owner','admin') AND is_active=1 AND deleted_at IS NULL ORDER BY FIELD(role,'owner','admin'),id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$owner) throw new RuntimeException('An active HARPP owner/admin is required.');
    $owner['source'] = 'harpp';
    $member = ['id' => 0, 'role' => 'member', 'source' => 'harpp'];
    $db->prepare("INSERT INTO harpp_users (email,password_hash,full_name,role,is_active) VALUES (:email,:password,:name,'member',1)")->execute([
        ':email' => "workspace-test-{$suffix}@example.test",
        ':password' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
        ':name' => 'Workspace Test Member',
    ]);
    $userId = (int)$db->lastInsertId();
    $member['id'] = $userId;
    $workspaces = new HarppWorkspaceService($db);
    $projects = new HarppProjectService($db);

    $created = $workspaces->create($owner, ['workspace_key' => "ws_{$suffix}", 'name' => 'Workspace Alpha']);
    $workspaceId = (int)($created['data']['workspace']['id'] ?? 0);
    $workspaceIds[] = $workspaceId;
    $assert('create workspace is active version one', $created['ok'] && $created['data']['workspace']['status'] === 'active' && (int)$created['data']['workspace']['version'] === 1);
    $duplicate = $workspaces->create($owner, ['workspace_key' => "ws_{$suffix}", 'name' => 'Duplicate']);
    $assert('duplicate workspace key returns conflict', !$duplicate['ok'] && $duplicate['status'] === 409 && $duplicate['code'] === 'workspace_key_taken');
    $updated = $workspaces->update($owner, $workspaceId, ['name' => 'Workspace Renamed', 'version' => 1]);
    $assert('workspace update bumps version', $updated['ok'] && (int)$updated['data']['workspace']['version'] === 2);
    $stale = $workspaces->update($owner, $workspaceId, ['name' => 'Stale', 'version' => 1]);
    $assert('stale workspace update conflicts', !$stale['ok'] && $stale['status'] === 409);

    $enrolled = $workspaces->enroll($owner, $workspaceId, ['user_id' => $userId, 'action' => 'enroll']);
    $assert('member enrollment uses migration role mapping', $enrolled['ok'] && $enrolled['data']['membership']['roles'] === ['operator', 'reviewer', 'viewer'] && $enrolled['data']['membership']['status'] === 'active');
    $reenrolled = $workspaces->enroll($owner, $workspaceId, ['user_id' => $userId, 'action' => 'enroll']);
    $assert('duplicate enrollment is idempotent', $reenrolled['ok'] && $reenrolled['data']['membership']['status'] === 'active');

    $project = $projects->create($owner, $workspaceId, ['project_key' => "project_{$suffix}", 'name' => 'Project Alpha']);
    $projectId = (int)($project['data']['project']['id'] ?? 0);
    $assert('project is created in workspace', $project['ok'] && (int)$project['data']['project']['workspace_id'] === $workspaceId);
    $projectUpdated = $projects->update($owner, $projectId, ['name' => 'Project Renamed', 'version' => 1]);
    $assert('project update bumps version', $projectUpdated['ok'] && (int)$projectUpdated['data']['project']['version'] === 2);
    $assert('stale project update conflicts', $projects->update($owner, $projectId, ['name' => 'Stale', 'version' => 1])['status'] === 409);
    $projectDuplicate = $projects->create($owner, $workspaceId, ['project_key' => "project_{$suffix}", 'name' => 'Duplicate']);
    $assert('duplicate project key conflicts within workspace', !$projectDuplicate['ok'] && $projectDuplicate['status'] === 409);

    $second = $workspaces->create($owner, ['workspace_key' => "ws2_{$suffix}", 'name' => 'Workspace Beta']);
    $secondId = (int)($second['data']['workspace']['id'] ?? 0);
    $workspaceIds[] = $secondId;
    $sameKey = $projects->create($owner, $secondId, ['project_key' => "project_{$suffix}", 'name' => 'Project Beta']);
    $assert('project key can be reused in another workspace', $sameKey['ok']);

    $projectMember = $projects->enroll($owner, $projectId, ['user_id' => $userId, 'action' => 'enroll']);
    $assert('project member enrollment uses migration role mapping', $projectMember['ok'] && $projectMember['data']['membership']['roles'] === ['operator', 'reviewer', 'viewer']);
    $projectRevoked = $projects->enroll($owner, $projectId, ['user_id' => $userId, 'action' => 'revoke']);
    $assert('project membership can be revoked', $projectRevoked['ok'] && $projectRevoked['data']['membership']['status'] === 'revoked');
    $projectArchived = $projects->archive($owner, $projectId, ['version' => 2]);
    $assert('project can be archived', $projectArchived['ok'] && $projectArchived['data']['project']['status'] === 'archived');

    $archived = $workspaces->archive($owner, $workspaceId, ['version' => 2]);
    $activeList = $workspaces->list($owner);
    $allList = $workspaces->list($owner, ['archived' => 1]);
    $activeIds = array_map('intval', array_column($activeList['data']['workspaces'], 'id'));
    $allIds = array_map('intval', array_column($allList['data']['workspaces'], 'id'));
    $assert('archive excludes workspace from active list', $archived['ok'] && $archived['data']['workspace']['status'] === 'archived' && !in_array($workspaceId, $activeIds, true) && in_array($workspaceId, $allIds, true));

    $revoked = $workspaces->enroll($owner, $workspaceId, ['user_id' => $userId, 'action' => 'revoke']);
    $assert('workspace membership can be revoked', $revoked['ok'] && $revoked['data']['membership']['status'] === 'revoked');
    $assert('member cannot list owner workspaces', $workspaces->list($member)['status'] === 403);
    $assert('member cannot create workspace', $workspaces->create($member, ['workspace_key' => "denied_{$suffix}", 'name' => 'Denied'])['status'] === 403);
    $assert('member cannot archive workspace', $workspaces->archive($member, $secondId, ['version' => 1])['status'] === 403);
    $assert('member cannot enroll workspace users', $workspaces->enroll($member, $secondId, ['user_id' => $userId, 'action' => 'enroll'])['status'] === 403);
    $assert('member cannot create project', $projects->create($member, $secondId, ['project_key' => "denied_{$suffix}", 'name' => 'Denied'])['status'] === 403);

    // Gate regression (review B1): project HTTP handlers must be gated by the payload-identity
    // capabilities (harpp.read@1 / harpp.manage@1) exactly like workspace handlers. The
    // pre-existing harpp.project.* collaboration caps deny payload-borne identity via
    // harppCapabilityData, so gating on them made every project endpoint 403 for everyone.
    $handlerSource = (string)file_get_contents(dirname(__DIR__) . '/handlers.php');
    $projectGates = [
        'harppProjectList' => 'harpp.read@1', 'harppProjectGet' => 'harpp.read@1', 'harppProjectMembers' => 'harpp.read@1',
        'harppProjectCreate' => 'harpp.manage@1', 'harppProjectUpdate' => 'harpp.manage@1',
        'harppProjectArchive' => 'harpp.manage@1', 'harppProjectEnroll' => 'harpp.manage@1',
    ];
    foreach ($projectGates as $handler => $cap) {
        $fn = preg_quote($handler, '/');
        if (preg_match("/function {$fn}\([^)]*\):void\s*\{[^}]*\}/s", $handlerSource, $m)) {
            $assert("{$handler} is gated by {$cap}", str_contains($m[0], "harppAuthenticated('{$cap}')"));
        } else {
            $assert("{$handler} gate source found", false);
        }
    }
    foreach (['harppProjectCreate', 'harppProjectUpdate', 'harppProjectArchive', 'harppProjectEnroll'] as $handler) {
        $fn = preg_quote($handler, '/');
        if (preg_match("/function {$fn}\([^)]*\):void\s*\{[^}]*\}/s", $handlerSource, $m)) {
            $line = $m[0];
            $csrf = strpos($line, 'harppRequireCsrf()');
            $auth = strpos($line, 'harppAuthenticated(');
            $assert("{$handler} checks CSRF before auth", $csrf !== false && $auth !== false && $csrf < $auth);
        } else {
            $assert("{$handler} mutation gate source found", false);
        }
    }
} finally {
    foreach (array_reverse($workspaceIds) as $workspaceId) {
        $db->prepare('DELETE FROM harpp_project_memberships WHERE project_id IN (SELECT id FROM harpp_projects WHERE workspace_id=:id)')->execute([':id' => $workspaceId]);
        $db->prepare('DELETE FROM harpp_projects WHERE workspace_id=:id')->execute([':id' => $workspaceId]);
        $db->prepare('DELETE FROM harpp_workspace_memberships WHERE workspace_id=:id')->execute([':id' => $workspaceId]);
        $db->prepare('DELETE FROM harpp_workspaces WHERE id=:id')->execute([':id' => $workspaceId]);
    }
    if ($userId > 0) $db->prepare('DELETE FROM harpp_users WHERE id=:id')->execute([':id' => $userId]);
}

$h->done();