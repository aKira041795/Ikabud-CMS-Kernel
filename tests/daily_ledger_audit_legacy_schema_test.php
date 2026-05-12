<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'baronledger.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/daily-ledger/admin/activity';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function dlAuditLegacyDisplay(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  [PASS] {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

$db = app()->db();
$backupTable = 'audit_logs_backup_' . bin2hex(random_bytes(4));
$restored = false;

$restoreAuditLogs = static function () use ($db, $backupTable, &$restored): void {
    if ($restored) {
        return;
    }

    try {
        $backupStmt = $db->query("SHOW TABLES LIKE '" . $backupTable . "'");
        $backupExists = $backupStmt && $backupStmt->fetchColumn() !== false;
        if (!$backupExists) {
            return;
        }

        $db->exec('DROP TABLE IF EXISTS audit_logs');
        $db->exec('RENAME TABLE `' . $backupTable . '` TO audit_logs');
        $restored = true;
    } catch (Throwable $ignored) {
    }
};

register_shutdown_function($restoreAuditLogs);

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== DAILY LEDGER LEGACY AUDIT SCHEMA TEST ===\n\n";

try {
    $auditTableStmt = $db->query("SHOW TABLES LIKE 'audit_logs'");
    $auditTableExists = $auditTableStmt && $auditTableStmt->fetchColumn() !== false;
    dlAuditLegacyDisplay('audit_logs table exists before legacy swap', $auditTableExists);

    if (!$auditTableExists) {
        throw new RuntimeException('audit_logs table is required for this regression test');
    }

    $db->exec('DROP TABLE IF EXISTS `' . $backupTable . '`');
    $db->exec('RENAME TABLE audit_logs TO `' . $backupTable . '`');
    $db->exec(
        'CREATE TABLE audit_logs ('
        . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'module VARCHAR(50) DEFAULT \'daily-ledger\','
        . 'actor_user_id INT UNSIGNED DEFAULT NULL,'
        . 'branch_id INT UNSIGNED DEFAULT NULL,'
        . 'action VARCHAR(80) NOT NULL,'
        . 'entity_type VARCHAR(50) DEFAULT NULL,'
        . 'entity_id VARCHAR(50) DEFAULT NULL,'
        . 'old_data JSON DEFAULT NULL,'
        . 'new_data JSON DEFAULT NULL,'
        . 'metadata_json JSON DEFAULT NULL,'
        . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'INDEX idx_al_module (module),'
        . 'INDEX idx_al_actor (actor_user_id),'
        . 'INDEX idx_al_created (created_at),'
        . 'INDEX idx_al_branch (branch_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $moduleUserColumnStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'actor_module_user_id'");
    $sourceColumnStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'actor_source'");
    dlAuditLegacyDisplay('legacy audit_logs schema omits actor_module_user_id', $moduleUserColumnStmt && $moduleUserColumnStmt->fetchColumn() === false);
    dlAuditLegacyDisplay('legacy audit_logs schema omits actor_source', $sourceColumnStmt && $sourceColumnStmt->fetchColumn() === false);

    $userStmt = $db->query("SELECT id, username, full_name, role FROM dl_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1");
    $user = $userStmt ? $userStmt->fetch(PDO::FETCH_ASSOC) : false;
    dlAuditLegacyDisplay('daily-ledger admin user available for audit writes', is_array($user), is_array($user) ? '' : 'missing dl_users admin');

    if (!is_array($user)) {
        throw new RuntimeException('missing daily-ledger admin user');
    }

    app()->setUser([
        'id' => (int)$user['id'],
        'sub' => (string)$user['role'] . ':' . (string)$user['id'],
        'username' => (string)$user['username'],
        'name' => (string)$user['full_name'],
        'role' => (string)$user['role'],
        'source' => 'daily-ledger',
    ]);

    $ctx = modulePushContext('daily-ledger');
    dlAuditLegacyDisplay('daily-ledger module context available', $ctx !== null);

    if ($ctx === null) {
        throw new RuntimeException('daily-ledger module context unavailable');
    }

    try {
        $ctx->audit(
            'daily-ledger.test.module_context',
            null,
            'daily_ledger_settings',
            'module-context',
            ['before' => 'legacy'],
            ['after' => 'module-context']
        );
    } finally {
        modulePopContext();
    }

    $capResult = app()->cap()->call('kernel.audit.record@1', [
        'module' => 'daily-ledger',
        'action' => 'daily-ledger.test.capability',
        'entity_type' => 'daily_ledger_settings',
        'entity_id' => 'kernel-capability',
        'old_data' => ['before' => 'legacy'],
        'new_data' => ['after' => 'kernel-capability'],
    ], ['caller_module' => 'daily-ledger']);
    dlAuditLegacyDisplay('kernel audit capability returns ok on legacy audit schema', !empty($capResult['ok']), json_encode($capResult, JSON_UNESCAPED_SLASHES));

    $rowsStmt = $db->query('SELECT module, action, actor_user_id, branch_id, entity_type, entity_id, old_data, new_data FROM audit_logs ORDER BY id ASC');
    $rows = $rowsStmt ? ($rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $actions = array_values(array_map(static fn(array $row): string => (string)($row['action'] ?? ''), $rows));

    dlAuditLegacyDisplay('legacy audit table accepted two fallback inserts', count($rows) === 2, json_encode($rows, JSON_UNESCAPED_SLASHES));
    dlAuditLegacyDisplay('module context audit row persisted', in_array('daily-ledger.test.module_context', $actions, true), json_encode($actions, JSON_UNESCAPED_SLASHES));
    dlAuditLegacyDisplay('kernel capability audit row persisted', in_array('daily-ledger.test.capability', $actions, true), json_encode($actions, JSON_UNESCAPED_SLASHES));

    $appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
    $errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));

    dlAuditLegacyDisplay('no audit write failure logged', !str_contains(strtolower($appLog), 'audit log write failed'), $appLog);
    dlAuditLegacyDisplay('no runtime self-heal logged', !str_contains(strtolower($appLog), 'kernel runtime migration self-heal'), $appLog);
    dlAuditLegacyDisplay('no php errors logged', $errorLog === '', $errorLog);
} finally {
    $restoreAuditLogs();
    app()->setUser([]);
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);