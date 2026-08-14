<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/helpers/entity-views.php';
require_once __DIR__ . '/handlers-deliveries.php';
require_once __DIR__ . '/handlers-pos.php';

// Load DiSyL entity view configs
if (is_dir(__DIR__ . '/helpers/views')) {
    \Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views');
}

/**
 * Daily Ledger Module — Handlers
 *
 * Cashier: neutral encoding form (no computed totals, no variance, no enforcement)
 * Admin: dashboard, sales summary, variance flags, product/branch/user management
 */

// ─── Helpers ───────────────────────────────────────────────────────────
function dl_auditLog(string $action, ?int $branchId = null, ?string $entityType = null, ?string $entityId = null, $oldData = null, $newData = null, ?string $reason = null): void
{
    $ctx = module();
    if (!$ctx) {
        return;
    }

    try {
        $ctx->audit($action, $branchId, $entityType, $entityId, $oldData, $newData, $reason);
    } catch (\Throwable $e) {
        // Non-fatal
    }
}

function dl_refreshTokenCacheKey(string $refreshToken): string
{
    return 'refresh_token:' . hash('sha256', $refreshToken);
}

function dl_registerRefreshToken(string $refreshToken, ?int $ttl = null): void
{
    if ($refreshToken === '') {
        return;
    }

    app()->cache()->set('daily-ledger', dl_refreshTokenCacheKey($refreshToken), ['active' => true], $ttl ?? (30 * 86400));
}

function dl_isRefreshTokenActive(string $refreshToken): bool
{
    if ($refreshToken === '') {
        return false;
    }

    $cached = app()->cache()->get('daily-ledger', dl_refreshTokenCacheKey($refreshToken));
    if (!is_array($cached)) {
        return true;
    }

    return !empty($cached['active']);
}

function dl_revokeRefreshToken(string $refreshToken): void
{
    if ($refreshToken === '') {
        return;
    }

    app()->cache()->set('daily-ledger', dl_refreshTokenCacheKey($refreshToken), ['active' => false], 30 * 86400);
}

function dl_idempotencyCacheKey(string $scope, string $idempotencyKey): string
{
    return 'idempotency:' . $scope . ':' . hash('sha256', $idempotencyKey);
}

function dl_loadIdempotentResponse(string $scope, string $idempotencyKey): ?array
{
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '') {
        return null;
    }

    $cached = app()->cache()->get('daily-ledger', dl_idempotencyCacheKey($scope, $idempotencyKey));
    if (!is_array($cached) || !is_array($cached['response'] ?? null)) {
        return null;
    }

    return $cached['response'];
}

function dl_storeIdempotentResponse(string $scope, string $idempotencyKey, array $response, int $ttl = 600): void
{
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '') {
        return;
    }

    app()->cache()->set('daily-ledger', dl_idempotencyCacheKey($scope, $idempotencyKey), ['response' => $response], $ttl);
}

function dl_lockDayStatusRow($db, int $branchId, string $date): string
{
    $ensureStmt = $db->prepare(
        'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status)
         VALUES (:bid, :d, "open")
         ON DUPLICATE KEY UPDATE branch_id = branch_id'
    );
    $ensureStmt->execute([':bid' => $branchId, ':d' => $date]);

    $lockStmt = $db->prepare(
        'SELECT status
           FROM dl_ledger_day_status
          WHERE branch_id = :bid AND ledger_date = :d
          LIMIT 1
          FOR UPDATE'
    );
    $lockStmt->execute([':bid' => $branchId, ':d' => $date]);
    $status = (string)($lockStmt->fetchColumn() ?: 'open');
    return $status === 'closed' ? 'closed' : 'open';
}

function dl_allowedColumn(string $field, array $map): ?string
{
    $column = $map[$field] ?? null;
    return is_string($column) && $column !== '' ? $column : null;
}

function dl_generateAuthTokens(array $payload): array
{
    $accessPayload = $payload;
    unset($accessPayload['token_type']);
    $accessToken = app()->jwt()->generate($accessPayload);

    $refreshPayload = $payload;
    $refreshPayload['token_type'] = 'refresh';
    $refreshJwt = new \Ikabud\Kernel\JWT(
        config('app.jwt.secret'),
        30 * 86400
    );
    $refreshToken = $refreshJwt->generate($refreshPayload);
    dl_registerRefreshToken($refreshToken, 30 * 86400);

    return [
        'token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => (int)config('app.jwt.expiration', 86400),
        'refresh_expires_in' => 30 * 86400,
    ];
}

function dl_verifyRefreshToken(string $refreshToken): ?array
{
    if ($refreshToken === '') {
        return null;
    }

    $refreshJwt = new \Ikabud\Kernel\JWT(
        config('app.jwt.secret'),
        30 * 86400
    );
    $payload = $refreshJwt->verify($refreshToken);
    if (!is_array($payload)) {
        return null;
    }

    if (($payload['source'] ?? '') !== 'daily-ledger' || ($payload['token_type'] ?? '') !== 'refresh') {
        return null;
    }
    if (!dl_isRefreshTokenActive($refreshToken)) {
        return null;
    }

    unset($payload['token_type']);
    return $payload;
}

function dl_getUserBranchId(): ?int
{
    $ctx = module();
    if (!$ctx) return null;

    $user = dlUserFromRequest();
    if (!$user) return null;

    $userId = (int)($user['id'] ?? 0);
    $sub = (string)($user['sub'] ?? '');
    if ($userId <= 0 && preg_match('/^cashier:(\d+)$/', $sub, $m)) {
        $userId = (int)$m[1];
    } elseif (is_numeric($sub)) {
        $userId = (int)$sub;
    }
    
    $role   = (string)($user['role'] ?? '');

    // Admin/supervisor: can work with any branch (selected via param)
    if (in_array($role, ['admin', 'supervisor'], true)) {
        $input = $ctx->input();
        $branchId = $input['branch_id'] ?? null;
        if ($branchId) return (int)$branchId;
        // Default: first branch
        $stmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    // Cashier: locked to assigned branch (single row in dl_user_branches).
    $stmt = $ctx->db()->prepare(
        'SELECT ub.branch_id
         FROM dl_user_branches ub
         INNER JOIN dl_users u ON u.id = ub.user_id
         WHERE ub.user_id = :id AND u.is_active = 1 AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $bid = (int)($stmt->fetchColumn() ?: 0);
    return $bid > 0 ? $bid : null;
}

function dlCurrentUser(array $roles = ['cashier', 'supervisor', 'admin', 'production_in_charge']): array
{
    $u = dlRequireAuth($roles);

    // Kernel OS admin access is opt-in (stored in modules.json settings).
    // Default: kernel admin cannot use this module.
    if (($u['source'] ?? '') === 'kernel' && ($u['role'] ?? '') === 'admin') {
        $settings = getModuleSettings('daily-ledger');
        $allowed = (string)($settings['allow_kernel_admin'] ?? '0');
        if (!in_array($allowed, ['1', 'true', 'yes', 'on'], true)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    return $u;
}

function dl_allPermissionActions(): array
{
    return [
        'ledger.override',
        'production.override',
        'pos.sell',
        'pos.void',
        'pos.refund',
        'pos.fallback',
        'pos.report',
        'delivery.edit',
    ];
}

function dl_defaultRolePermissions(): array
{
    return [
        'admin' => ['ledger.override', 'production.override', 'pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report', 'delivery.edit'],
        'supervisor' => ['pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report', 'delivery.edit'],
        'production_in_charge' => [],
        'cashier' => ['pos.sell'],
        'auditor' => [],
        'viewer' => [],
    ];
}

function dlSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['daily-ledger'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = $field['default'];
    }

    return $defaults;
}

function dlModuleSettings(bool $refresh = false): array
{
    static $cache = null;
    if (!$refresh && $cache !== null) {
        return $cache;
    }
    $cache = array_merge(dlSettingsDefaults(), getModuleSettings('daily-ledger'));
    return $cache;
}

function dlPersistModuleSettings(array $settings): bool
{
    if ($settings === []) {
        return true;
    }

    saveModuleSettings('daily-ledger', $settings);
    $fresh = dlModuleSettings(true);

    foreach ($settings as $key => $expected) {
        if (!array_key_exists($key, $fresh)) {
            return false;
        }

        $actual = $fresh[$key];
            if (!dlSettingValuesMatch($actual, $expected)) {
            return false;
        }
    }

    return true;
}

function dl_rolePermissions(bool $refresh = false): array
{
    static $cache = null;
    if (!$refresh && $cache !== null) {
        return $cache;
    }

    $defaults = dl_defaultRolePermissions();
    $settings = dlModuleSettings();
    $raw = $settings['role_permissions'] ?? null;

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    if (!is_array($raw)) {
        $cache = $defaults;
        return $cache;
    }

    $allowedActions = array_flip(dl_allPermissionActions());
    $result = $defaults;
    foreach ($defaults as $role => $defaultPerms) {
        $vals = $raw[$role] ?? $defaultPerms;
        if (!is_array($vals)) {
            $vals = $defaultPerms;
        }
        $clean = [];
        foreach ($vals as $perm) {
            $perm = (string)$perm;
            if ($perm !== '' && isset($allowedActions[$perm])) {
                $clean[$perm] = true;
            }
        }
        $result[$role] = array_keys($clean);
    }

    $cache = $result;
    return $cache;
}

function dl_roleHasPermission(string $role, string $permission): bool
{
    $permissions = dl_rolePermissions();
    $rolePerms = $permissions[$role] ?? [];
    return in_array($permission, $rolePerms, true);
}

function dl_isKernelAdmin(array $user): bool
{
    return (($user['source'] ?? '') === 'kernel' && in_array($user['role'] ?? '', ['admin', 'superadmin'], true));
}

function dl_canManageFeatureActivation(array $user): bool
{
    return in_array((string)($user['role'] ?? ''), ['admin', 'superadmin'], true);
}

function dl_featureSettings(): array
{
    $settings = dlModuleSettings();

    return [
        'production_output_enabled' => dl_settingToBool($settings['production_output_enabled'] ?? false),
        'formal_delivery_workflow_enabled' => dl_settingToBool($settings['formal_delivery_workflow_enabled'] ?? false),
        'price_groups_enabled' => dl_settingToBool($settings['price_groups_enabled'] ?? true),
        'pos_enabled' => dl_settingToBool($settings['pos_enabled'] ?? false),
        'pos_sort_by_sales' => dl_settingToBool($settings['pos_sort_by_sales'] ?? true),
    ];
}

function dl_isFeatureEnabled(string $feature): bool
{
    $features = dl_featureSettings();
    return !empty($features[$feature]);
}

function dl_settingToBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function dl_normalizeCloseOfDayTime($value): string
{
    $normalized = trim((string)$value);
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $normalized)) {
        return $normalized;
    }

    return '00:00';
}

function dl_normalizeTimezone($value): string
{
    $timezone = trim((string)$value);
    if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
        return $timezone;
    }

    $fallback = (string)config('app.timezone', 'Asia/Manila');
    if ($fallback !== '' && in_array($fallback, timezone_identifiers_list(), true)) {
        return $fallback;
    }

    return 'Asia/Manila';
}

function dl_normalizeRegion($value): string
{
    $region = trim((string)$value);
    if ($region === '') {
        return 'Default Region';
    }

    return mb_substr($region, 0, 100);
}

function dl_normalizeOutputUnitLabel($value): string
{
    $label = strtolower(trim((string)$value));
    if ($label === '') {
        return 'pcs';
    }
    if (!preg_match('/^[a-z][a-z0-9\-_ ]{0,19}$/', $label)) {
        return 'pcs';
    }

    return $label;
}

function dl_normalizePiecesPerBatch($value): ?int
{
    $num = (int)$value;
    if ($num <= 0) {
        return null;
    }

    return min($num, 1000000);
}

function dl_fetchActiveProductsForProduction($db): array
{
    $cacheKey = 'active_products_for_production';
    $cached = app()->cache()->get('daily-ledger', $cacheKey);
    if (is_array($cached) && isset($cached['rows'])) {
        return $cached['rows'];
    }

    try {
        $stmt = $db->query('SELECT id, name, sku, current_price, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label, product_category FROM dl_products WHERE is_active = 1 ORDER BY product_category, name');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $stmt = $db->query('SELECT id, name, sku, current_price FROM dl_products WHERE is_active = 1 ORDER BY name');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    foreach ($rows as &$row) {
        if (!array_key_exists('output_pieces_per_batch', $row)) {
            $row['output_pieces_per_batch'] = null;
        }
        if (!array_key_exists('output_unit_label', $row)) {
            $row['output_unit_label'] = 'pcs';
        }
        if (!array_key_exists('batch_input_qty', $row)) {
            $row['batch_input_qty'] = null;
        }
        if (!array_key_exists('batch_egg_qty', $row)) {
            $row['batch_egg_qty'] = null;
        }
    }
    unset($row);

    foreach ($rows as &$row) {
        if (!array_key_exists('product_category', $row)) {
            $row['product_category'] = 'bread';
        }
    }
    unset($row);

    app()->cache()->setWithTags('daily-ledger', $cacheKey, ['rows' => $rows], ['dl_products'], 300);
    return $rows;
}

function dl_operatingRegionChoices(string $currentRegion): array
{
    $choices = [
        'Default Region',
        'Metro Manila',
        'Manila',
        'Cebu',
        'Davao',
        'Luzon',
        'Visayas',
        'Mindanao',
    ];

    if (!in_array($currentRegion, $choices, true)) {
        array_unshift($choices, $currentRegion);
    }

    return $choices;
}

function dl_operatingTimezoneChoices(string $currentTimezone): array
{
    $choices = [
        'Asia/Manila',
        'Asia/Singapore',
        'Asia/Hong_Kong',
        'Asia/Tokyo',
        'Asia/Seoul',
        'Australia/Sydney',
        'UTC',
    ];

    if (!in_array($currentTimezone, $choices, true)) {
        array_unshift($choices, $currentTimezone);
    }

    return $choices;
}

function dl_isAllowedAutoCloseTime(string $time): bool
{
    if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
        return false;
    }

    $hours = (int)$matches[1];
    return $hours >= 0 && $hours < 24;
}

function dlSettingValuesMatch(mixed $actual, mixed $expected): bool
{
    return json_encode(dlNormalizeSettingValue($actual), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        === json_encode(dlNormalizeSettingValue($expected), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dlNormalizeSettingValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $normalized = [];
    foreach ($value as $key => $item) {
        $normalized[$key] = dlNormalizeSettingValue($item);
    }

    if ($normalized !== [] && array_keys($normalized) !== range(0, count($normalized) - 1)) {
        ksort($normalized);
    }

    return $normalized;
}

function dlAuditLogHasColumn(string $column): bool
{
    return dlTableHasColumn('audit_logs', $column);
}

/**
 * Generic column-existence check that is safe on shared-host (Bluehost)
 * databases where optional migration columns may be missing. Selecting a
 * column that does not exist throws SQLSTATE[42S22] and 500s the request,
 * so optional columns must be gated behind this check.
 */
function dlTableHasColumn(string $table, string $column): bool
{
    static $cache = [];
    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $safeTable = preg_replace('/[^a-z0-9_]+/i', '', $table);
    $safeColumn = preg_replace('/[^a-z0-9_]+/i', '', $column);
    if ($safeTable === '' || $safeColumn === '') {
        $cache[$cacheKey] = false;
        return false;
    }

    try {
        $stmt = dlCtx()->db()->query("SHOW COLUMNS FROM {$safeTable} LIKE '" . $safeColumn . "'");
        $cache[$cacheKey] = $stmt->fetchColumn() !== false;
        return $cache[$cacheKey];
    } catch (Throwable) {
        $cache[$cacheKey] = false;
        return false;
    }
}

function dlActiveAdminCount(): int
{
    try {
        $stmt = dlCtx()->db()->query(
            "SELECT COUNT(*) FROM dl_users WHERE role = 'admin' AND deleted_at IS NULL AND is_active = 1"
        );
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable) {
        return 0;
    }
}

function dl_backupSettings(): array
{
    $settings = dlModuleSettings();

    $enabled = dl_settingToBool($settings['backup_before_reset_enabled'] ?? '1');
    $includeUsers = dl_settingToBool($settings['backup_include_users'] ?? '1');
    $retentionDays = (int)($settings['backup_retention_days'] ?? 14);
    if ($retentionDays < 1) {
        $retentionDays = 1;
    }
    if ($retentionDays > 90) {
        $retentionDays = 90;
    }

    return [
        'backup_before_reset_enabled' => $enabled,
        'backup_include_users' => $includeUsers,
        'backup_retention_days' => $retentionDays,
    ];
}

function dl_resetSecondConfirmPhrase(): string
{
    return 'I UNDERSTAND THIS WILL DELETE ALL DAILY LEDGER DATA';
}

function dl_resetSafeguardSettings(): array
{
    $settings = dlModuleSettings();
    return [
        'reset_second_phrase_enabled' => dl_settingToBool($settings['reset_second_phrase_enabled'] ?? '1'),
        'reset_second_phrase' => dl_resetSecondConfirmPhrase(),
    ];
}

function dl_backupDirectoryPath(): string
{
    return rtrim((string)(defined('STORAGE_PATH') ? STORAGE_PATH : (BASE_PATH . '/storage')), '/\\') . '/backups/daily-ledger';
}

function dl_ensureBackupDirectory(): string
{
    $dir = dl_backupDirectoryPath();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create backup directory.');
    }

    $htaccessPath = $dir . '/.htaccess';
    if (!is_file($htaccessPath)) {
        @file_put_contents($htaccessPath, "Require all denied\nDeny from all\n");
        @chmod($htaccessPath, 0644);
    }

    return $dir;
}

function dl_cleanupOldBackupFiles(string $dir, int $retentionDays): int
{
    $deleted = 0;
    $threshold = time() - ($retentionDays * 86400);
    $items = @scandir($dir);
    if (!is_array($items)) {
        return 0;
    }

    foreach ($items as $item) {
        if (!is_string($item) || $item === '.' || $item === '..') {
            continue;
        }
        if (!preg_match('/^dl-db-backup-[0-9]{8}-[0-9]{6}\.sql$/', $item)) {
            continue;
        }

        $path = $dir . '/' . $item;
        if (!is_file($path)) {
            continue;
        }

        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < $threshold) {
            if (@unlink($path)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

function dl_sqlQuote($value): string
{
    if ($value === null) {
        return 'NULL';
    }

    $string = (string)$value;
    $string = str_replace(
        ["\\", "\0", "\n", "\r", "\x1a", "'"],
        ["\\\\", "\\0", "\\n", "\\r", "\\Z", "\\'"],
        $string
    );

    return "'" . $string . "'";
}

function dl_safeIdentifier(string $name): string
{
    $safe = preg_replace('/[^a-z0-9_]+/i', '', $name);
    if (!is_string($safe) || $safe === '' || $safe !== $name) {
        throw new InvalidArgumentException('Invalid SQL identifier: ' . $name);
    }

    return $safe;
}

function dl_listDailyLedgerTables($db, bool $includeUsers): array
{
    $tables = [];
    $stmt = $db->query("SHOW TABLES LIKE 'dl\\_%'");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $table = (string)($row[0] ?? '');
        if ($table === '') {
            continue;
        }
        if (!$includeUsers && $table === 'dl_users') {
            continue;
        }
        $tables[] = $table;
    }

    sort($tables);
    return $tables;
}

function dl_generateDatabaseBackup(array $user, string $reason, ?bool $includeUsers = null): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable');
    }

    $db = $ctx->db();
    $backupSettings = dl_backupSettings();
    $includeUsersFlag = $includeUsers !== null ? $includeUsers : $backupSettings['backup_include_users'];

    $tables = dl_listDailyLedgerTables($db, $includeUsersFlag);
    if ($tables === []) {
        throw new RuntimeException('No Daily Ledger tables found to back up.');
    }

    $dir = dl_ensureBackupDirectory();
    $filename = 'dl-db-backup-' . date('Ymd-His') . '.sql';
    $target = $dir . '/' . $filename;
    $tmpTarget = $target . '.tmp';

    $fh = @fopen($tmpTarget, 'wb');
    if (!is_resource($fh)) {
        throw new RuntimeException('Failed to open backup file for writing.');
    }

    try {
        fwrite($fh, "-- Daily Ledger SQL Backup\n");
        fwrite($fh, '-- Generated at: ' . date('c') . "\n");
        fwrite($fh, '-- Reason: ' . $reason . "\n");
        fwrite($fh, '-- Include users: ' . ($includeUsersFlag ? 'yes' : 'no') . "\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tableSummaries = [];
        $totalRows = 0;

        foreach ($tables as $table) {
            $safeTable = dl_safeIdentifier($table);
            $countStmt = $db->query('SELECT COUNT(*) FROM ' . $safeTable);
            $rowCount = (int)($countStmt->fetchColumn() ?: 0);
            $totalRows += $rowCount;

            fwrite($fh, '-- ------------------------------------------------------------' . "\n");
            fwrite($fh, '-- Table: ' . $safeTable . ' (rows: ' . $rowCount . ')' . "\n");
            fwrite($fh, '-- Data-only backup (schema must already exist).' . "\n");
            fwrite($fh, 'DELETE FROM `' . $safeTable . "`;\n");

            if ($rowCount > 0) {
                $dataStmt = $db->query('SELECT * FROM ' . $safeTable);
                $batchRows = [];
                $columnSql = null;

                while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }

                    if ($columnSql === null) {
                        $columns = array_map(static function ($col): string {
                            return '`' . str_replace('`', '``', (string)$col) . '`';
                        }, array_keys($row));
                        $columnSql = implode(', ', $columns);
                    }

                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = dl_sqlQuote($v);
                    }
                    $batchRows[] = '(' . implode(', ', $vals) . ')';

                    if (count($batchRows) >= 100) {
                        fwrite($fh, 'INSERT INTO `' . $safeTable . '` (' . $columnSql . ") VALUES\n");
                        fwrite($fh, implode(",\n", $batchRows) . ";\n");
                        $batchRows = [];
                    }
                }

                if ($batchRows !== []) {
                    fwrite($fh, 'INSERT INTO `' . $safeTable . '` (' . $columnSql . ") VALUES\n");
                    fwrite($fh, implode(",\n", $batchRows) . ";\n");
                }
            }

            fwrite($fh, "\n");
            $tableSummaries[] = [
                'table' => $safeTable,
                'rows' => $rowCount,
            ];
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        @chmod($tmpTarget, 0640);
        if (!@rename($tmpTarget, $target)) {
            @unlink($tmpTarget);
            throw new RuntimeException('Failed to finalize backup file.');
        }

        $deletedOld = dl_cleanupOldBackupFiles($dir, (int)$backupSettings['backup_retention_days']);

        $downloadUrl = dlGetBaseUrl() . '/admin/settings/backup-download?file=' . rawurlencode($filename);
        $result = [
            'file_name' => $filename,
            'file_size_bytes' => (int)@filesize($target),
            'download_url' => $downloadUrl,
            'tables' => $tableSummaries,
            'total_rows' => $totalRows,
            'include_users' => $includeUsersFlag,
            'retention_days' => (int)$backupSettings['backup_retention_days'],
            'deleted_old_backups' => $deletedOld,
        ];

        dl_auditLog('database_backup_created', null, 'module_settings', 'daily-ledger', null, [
            'reason' => $reason,
            'file_name' => $filename,
            'file_size_bytes' => $result['file_size_bytes'],
            'total_rows' => $totalRows,
            'include_users' => $includeUsersFlag,
            'deleted_old_backups' => $deletedOld,
            'performed_by_role' => (string)($user['role'] ?? ''),
            'performed_by_source' => (string)($user['source'] ?? ''),
        ]);

        return $result;
    } catch (Throwable $e) {
        fclose($fh);
        @unlink($tmpTarget);
        throw $e;
    }
}

function dl_deploymentResetTables($db): array
{
    // Full deployment reset wipes all module-owned dl_* tables; preserved admin is restored after purge.
    return dl_listDailyLedgerTables($db, true);
}

function dl_preservedAdminRowForReset($db, array $user): array
{
    $actorId = (int)($user['id'] ?? 0);
    $actorUsername = trim((string)($user['username'] ?? ''));
    $actorEmail = trim((string)($user['email'] ?? ''));

    if (!dl_tableExists($db, 'dl_users')) {
        throw new RuntimeException('dl_users table not found; cannot preserve admin account.');
    }

    $row = null;
    if ($actorId > 0) {
        $stmt = $db->prepare('SELECT * FROM dl_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $actorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ((!is_array($row) || $row === []) && $actorUsername !== '') {
        $stmt = $db->prepare(
            "SELECT * FROM dl_users
             WHERE role = 'admin' AND deleted_at IS NULL AND is_active = 1 AND username = :username
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':username' => $actorUsername]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ((!is_array($row) || $row === []) && $actorEmail !== '' && dlTableHasColumn('dl_users', 'email')) {
        $stmt = $db->prepare(
            "SELECT * FROM dl_users
             WHERE role = 'admin' AND deleted_at IS NULL AND is_active = 1 AND email = :email
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':email' => $actorEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!is_array($row) || $row === []) {
        // Fallback for kernel-admin sessions: keep the last known active module admin account.
        $stmt = $db->query(
            "SELECT * FROM dl_users
             WHERE role = 'admin' AND deleted_at IS NULL AND is_active = 1
             ORDER BY id DESC
             LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!is_array($row) || $row === []) {
        throw new RuntimeException('No active admin account found to preserve.');
    }

    $role = strtolower(trim((string)($row['role'] ?? '')));
    if ($role !== 'admin') {
        throw new RuntimeException('Only a Daily Ledger admin account can be preserved by deployment reset.');
    }

    return $row;
}

function dl_restorePreservedAdminRowAfterReset($db, array $row): void
{
    if (!dl_tableExists($db, 'dl_users')) {
        return;
    }

    $colsStmt = $db->query('SHOW COLUMNS FROM dl_users');
    $columns = $colsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($columns === []) {
        throw new RuntimeException('Unable to read dl_users columns for account restore.');
    }

    $insertCols = [];
    $insertVals = [];
    $bind = [];
    $i = 0;

    foreach ($columns as $col) {
        $field = (string)($col['Field'] ?? '');
        if ($field === '') {
            continue;
        }

        $nullable = strtolower((string)($col['Null'] ?? 'NO')) === 'yes';
        $value = array_key_exists($field, $row) ? $row[$field] : ($nullable ? null : ($col['Default'] ?? null));

        if ($field === 'role') {
            $value = 'admin';
        } elseif ($field === 'is_active') {
            $value = 1;
        } elseif ($field === 'deleted_at') {
            $value = null;
        } elseif (in_array($field, ['branch_id', 'default_branch_id'], true)) {
            $value = $nullable ? null : 0;
        }

        $param = ':c' . $i;
        $insertCols[] = '`' . str_replace('`', '``', $field) . '`';
        $insertVals[] = $param;
        $bind[$param] = $value;
        $i++;
    }

    if ($insertCols === []) {
        throw new RuntimeException('No columns available to restore preserved admin account.');
    }

    $sql = 'INSERT INTO dl_users (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')';
    $ins = $db->prepare($sql);
    $ins->execute($bind);
}

function dl_tableExists($db, string $table): bool
{
    $safe = preg_replace('/[^a-z0-9_]+/i', '', $table);
    if ($safe === '' || $safe !== $table) {
        return false;
    }

    try {
        $stmt = $db->query("SHOW TABLES LIKE '" . $safe . "'");
        return $stmt->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

function dl_deleteAllRowsIfTableExists($db, string $table): int
{
    if (!dl_tableExists($db, $table)) {
        return 0;
    }

    $safe = preg_replace('/[^a-z0-9_]+/i', '', $table);
    $stmt = $db->prepare('DELETE FROM ' . $safe);
    $ok = $stmt->execute();
    if ($ok !== true) {
        throw new RuntimeException('Failed deleting table: ' . $safe);
    }

    return (int)$stmt->rowCount();
}

function dl_countRowsIfTableExists($db, string $table): int
{
    if (!dl_tableExists($db, $table)) {
        return 0;
    }

    $safe = preg_replace('/[^a-z0-9_]+/i', '', $table);
    $stmt = $db->query('SELECT COUNT(*) FROM ' . $safe);
    return (int)($stmt->fetchColumn() ?: 0);
}

function dl_runDeploymentDataReset(array $user, bool $dryRun = false): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable');
    }

    $db = $ctx->db();
    $tables = dl_deploymentResetTables($db);
    $preservedAdminRow = dl_preservedAdminRowForReset($db, $user);
    $adminCount = dlActiveAdminCount();
    if ($adminCount < 1) {
        throw new RuntimeException('No active admin account found; reset aborted.');
    }

    $result = [
        'dry_run' => $dryRun,
        'preserved_admin_accounts' => 1,
        'preserved_admin_id' => (int)($preservedAdminRow['id'] ?? 0),
        'preserved_admin_username' => (string)($preservedAdminRow['username'] ?? ''),
        'backup' => null,
        'tables' => [],
        'total_rows' => 0,
    ];

    if ($dryRun) {
        foreach ($tables as $table) {
            $rows = dl_countRowsIfTableExists($db, $table);
            $result['tables'][] = ['table' => $table, 'rows' => $rows];
            $result['total_rows'] += $rows;
        }
        return $result;
    }

    $backupSettings = dl_backupSettings();
    if ($backupSettings['backup_before_reset_enabled']) {
        $result['backup'] = dl_generateDatabaseBackup(
            $user,
            'before_deployment_reset',
            (bool)$backupSettings['backup_include_users']
        );
    }

    $db->beginTransaction();
    $fkChecksDisabled = false;
    try {
        $db->prepare('SET FOREIGN_KEY_CHECKS=0')->execute();
        $fkChecksDisabled = true;

        foreach ($tables as $table) {
            $rows = dl_deleteAllRowsIfTableExists($db, $table);
            $result['tables'][] = ['table' => $table, 'rows' => $rows];
            $result['total_rows'] += $rows;
        }

        dl_restorePreservedAdminRowAfterReset($db, $preservedAdminRow);

        $db->prepare('SET FOREIGN_KEY_CHECKS=1')->execute();
        $fkChecksDisabled = false;
        $db->commit();
    } catch (Throwable $e) {
        if ($fkChecksDisabled) {
            try {
                $db->prepare('SET FOREIGN_KEY_CHECKS=1')->execute();
            } catch (Throwable) {
            }
        }
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    dl_auditLog('deployment_data_reset', null, 'module_settings', 'daily-ledger', null, [
        'performed_by_role' => (string)($user['role'] ?? ''),
        'performed_by_source' => (string)($user['source'] ?? ''),
        'preserved_admin_accounts' => 1,
        'preserved_admin_id' => (int)($preservedAdminRow['id'] ?? 0),
        'preserved_admin_username' => (string)($preservedAdminRow['username'] ?? ''),
        'total_rows' => $result['total_rows'],
        'tables' => $result['tables'],
    ]);

    return $result;
}

/**
 * Tables whose rows are SALES/transaction evidence for the "reset sales data
 * only" feature. Reference/master data is intentionally NOT listed: users,
 * branches, products, prices, price groups, supply rules, production,
 * selling accounts, audit logs, and settings are preserved so a fresh sales
 * period can start without rebuilding the catalog.
 */
function dl_salesResetTables($db): array
{
    $candidates = [
        'dl_daily_ledger',
        'dl_pos_sales',
        'dl_pos_sale_items',
        'dl_pos_payments',
        'dl_pos_sale_events',
        'dl_sales_day_modes',
        'dl_pos_fallback_checkpoints',
        'dl_pos_fallback_checkpoint_items',
        'dl_cashier_withdrawals',
        'dl_deliveries',
        'dl_delivery_items',
        'dl_branch_receivings',
        'dl_branch_receiving_items',
        'dl_variance_flags',
        'dl_delivery_variance_flags',
        'dl_ledger_day_status',
    ];
    $tables = [];
    foreach ($candidates as $table) {
        if (dl_tableExists($db, $table)) {
            $tables[] = $table;
        }
    }
    sort($tables);
    return $tables;
}

/**
 * Reset ONLY sales/transaction data (ledger, POS, deliveries, withdrawals,
 * variance flags, day status). Master data (users, branches, products,
 * prices, settings, audit logs) is untouched, so no admin-account restore is
 * needed. Supports dry-run preview and optional backup-before-reset.
 */
function dl_runSalesDataReset(array $user, bool $dryRun = false): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable');
    }

    $db = $ctx->db();
    $tables = dl_salesResetTables($db);

    $result = [
        'dry_run' => $dryRun,
        'preserved_master_data' => true,
        'backup' => null,
        'tables' => [],
        'total_rows' => 0,
    ];

    if ($dryRun) {
        foreach ($tables as $table) {
            $rows = dl_countRowsIfTableExists($db, $table);
            $result['tables'][] = ['table' => $table, 'rows' => $rows];
            $result['total_rows'] += $rows;
        }
        return $result;
    }

    $backupSettings = dl_backupSettings();
    if ($backupSettings['backup_before_reset_enabled']) {
        $result['backup'] = dl_generateDatabaseBackup(
            $user,
            'before_sales_data_reset',
            (bool)$backupSettings['backup_include_users']
        );
    }

    $db->beginTransaction();
    $fkChecksDisabled = false;
    try {
        $db->prepare('SET FOREIGN_KEY_CHECKS=0')->execute();
        $fkChecksDisabled = true;

        foreach ($tables as $table) {
            $rows = dl_deleteAllRowsIfTableExists($db, $table);
            $result['tables'][] = ['table' => $table, 'rows' => $rows];
            $result['total_rows'] += $rows;
        }

        $db->prepare('SET FOREIGN_KEY_CHECKS=1')->execute();
        $fkChecksDisabled = false;
        $db->commit();
    } catch (Throwable $e) {
        if ($fkChecksDisabled) {
            try {
                $db->prepare('SET FOREIGN_KEY_CHECKS=1')->execute();
            } catch (Throwable) {
            }
        }
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    dl_auditLog('sales_data_reset', null, 'module_settings', 'daily-ledger', null, [
        'performed_by_role' => (string)($user['role'] ?? ''),
        'performed_by_source' => (string)($user['source'] ?? ''),
        'preserved_master_data' => true,
        'total_rows' => $result['total_rows'],
        'tables' => $result['tables'],
    ]);

    return $result;
}

function dl_closeOfDaySettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $settings = dlModuleSettings();
    $cache = [
        'auto_close_enabled' => dl_settingToBool($settings['auto_close_enabled'] ?? false),
        'close_of_day_time' => dl_normalizeCloseOfDayTime($settings['close_of_day_time'] ?? '00:00'),
        'operating_timezone' => dl_normalizeTimezone($settings['operating_timezone'] ?? config('app.timezone', 'Asia/Manila')),
        'operating_region' => dl_normalizeRegion($settings['operating_region'] ?? ''),
    ];
    return $cache;
}

function dl_businessDate(?\DateTimeImmutable $now = null): string
{
    $settings = dl_closeOfDaySettings();
    $timezone = new \DateTimeZone($settings['operating_timezone']);
    $now = $now ? $now->setTimezone($timezone) : new \DateTimeImmutable('now', $timezone);
    
    if (!$settings['auto_close_enabled']) {
        return $now->format('Y-m-d');
    }

    list($hours, $minutes) = explode(':', $settings['close_of_day_time']);
    $hours = (int)$hours;
    
    if ($hours < 12) {
        // Morning cutoff (e.g. 03:00) -> Shift clock backward
        return $now->modify("-{$hours} hours -{$minutes} minutes")->format('Y-m-d');
    } else {
        // Evening cutoff (e.g. 20:00) -> Shift clock forward
        $shiftHours = 24 - $hours;
        // Adjust for minutes as well to be precise (e.g. 20:30 means we add 3 hours 30 mins)
        // Wait, if it's 20:30, and it is 20:29, adding 3h30m gives 23:59. It's the same day.
        // If it's 20:31, adding 3h30m gives 00:01 next day. Correct.
        $shiftMinutes = $minutes > 0 ? (60 - $minutes) : 0;
        $shiftHours = $minutes > 0 ? $shiftHours - 1 : $shiftHours;
        return $now->modify("+{$shiftHours} hours +{$shiftMinutes} minutes")->format('Y-m-d');
    }
}

function dl_maybeAutoCloseBranchDay(int $branchId, ?int $actorId = null, ?\DateTimeImmutable $now = null): bool
{
    if ($branchId <= 0) {
        return false;
    }

    $settings = dl_closeOfDaySettings();
    if (!$settings['auto_close_enabled']) {
        return false;
    }

    $timezone = new \DateTimeZone($settings['operating_timezone']);
    $now = $now ? $now->setTimezone($timezone) : new \DateTimeImmutable('now', $timezone);
    // The shift before the *current* business date has already ended.
    $currentBusinessDate = dl_businessDate($now);
    $closeDate = (new \DateTimeImmutable($currentBusinessDate))->modify('-1 day')->format('Y-m-d');
    if (dl_getDayStatus($branchId, $closeDate) === 'closed') {
        return false;
    }

    $ctx = module();
    if (!$ctx) {
        return false;
    }

    $closeActorId = ($actorId !== null && $actorId > 0) ? $actorId : null;
    $stmt = $ctx->db()->prepare(
        'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
         VALUES (:bid, :d, \'closed\', :uid, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE status = \'closed\', closed_by = VALUES(closed_by), closed_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':bid' => $branchId, ':d' => $closeDate, ':uid' => $closeActorId]);

    dl_auditLog('auto_close_day', $branchId, 'dl_ledger_day_status', "{$branchId}-{$closeDate}", null, [
        'status' => 'closed',
        'source' => 'cutoff',
        'close_of_day_time' => $settings['close_of_day_time'],
    ]);

    return true;
}

function dl_maybeAutoCloseBranches(array $branchIds, ?int $actorId = null, ?\DateTimeImmutable $now = null): void
{
    $settings = dl_closeOfDaySettings();
    $timezone = new \DateTimeZone($settings['operating_timezone']);
    $now = $now ? $now->setTimezone($timezone) : new \DateTimeImmutable('now', $timezone);
    $uniqueBranchIds = [];
    foreach ($branchIds as $branchId) {
        $branchId = (int)$branchId;
        if ($branchId > 0) {
            $uniqueBranchIds[$branchId] = true;
        }
    }

    foreach (array_keys($uniqueBranchIds) as $branchId) {
        dl_maybeAutoCloseBranchDay((int)$branchId, $actorId, $now);
    }
}

function dl_operatingClockLabel(): array
{
    $settings = dl_closeOfDaySettings();

    return [
        'business_date' => dl_businessDate(),
        'close_of_day_time' => $settings['close_of_day_time'],
        'auto_close_enabled' => $settings['auto_close_enabled'],
        'operating_timezone' => $settings['operating_timezone'],
        'operating_region' => $settings['operating_region'],
    ];
}

function dl_getBranchName(int $branchId): string
{
    $ctx = module();
    if (!$ctx) return 'Unknown';
    $stmt = $ctx->db()->prepare('SELECT name FROM dl_branches WHERE id = :id');
    $stmt->execute([':id' => $branchId]);
    return (string)($stmt->fetchColumn() ?: 'Branch #' . $branchId);
}

function dl_getDayStatus(int $branchId, string $date): string
{
    $ctx = module();
    if (!$ctx) return 'open';

    $stmt = $ctx->db()->prepare('SELECT status FROM dl_ledger_day_status WHERE branch_id = :bid AND ledger_date = :d');
    $stmt->execute([':bid' => $branchId, ':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)$row['status'] : 'open';
}

/**
 * Which cashier shift is active right now, resolved in the operating timezone.
 * AM before the configured AM→PM cutoff (dl_amShiftCutoff()), PM at/after.
 * Used as the default shift on the cashier ledger so a PM-hour cashier lands
 * on the PM shift (and sees the AM ending handed off as her beginning)
 * without manually toggling. The explicit AM/PM toggle always overrides.
 */
function dl_currentShift(?\DateTimeImmutable $now = null): string
{
    $settings = dl_closeOfDaySettings();
    $timezone = new \DateTimeZone($settings['operating_timezone']);
    $now = $now ? $now->setTimezone($timezone) : new \DateTimeImmutable('now', $timezone);
    list($hours, $minutes) = array_map('intval', explode(':', dl_amShiftCutoff()));
    $nowMinutes = (int)$now->format('G') * 60 + (int)$now->format('i');
    $cutoffMinutes = $hours * 60 + $minutes;
    return $nowMinutes < $cutoffMinutes ? 'AM' : 'PM';
}

/**
 * AM→PM shift cutoff time (HH:MM) configured in Admin Settings. Invalid or
 * missing values fall back to "14:00". At/after this time the active shift
 * is PM.
 */
function dl_amShiftCutoff(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $raw = trim((string)(dlModuleSettings()['am_shift_cutoff'] ?? '14:00'));
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $raw)) {
        $raw = '14:00';
    }
    $cache = $raw;
    return $raw;
}

/**
 * Explicit per-user shift assignment (AM/PM) read from dl_users.shift, or
 * null when the account has no assignment. Cached per request+user id so
 * the ledger hot path only queries once.
 */
function dl_userAssignedShift(array $user): ?string
{
    $id = dl_getActorUserId($user);
    if ($id <= 0) {
        return null;
    }
    static $cache = [];
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $value = null;
    $ctx = module();
    if ($ctx) {
        try {
            $stmt = $ctx->db()->prepare('SELECT shift FROM dl_users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $v = $stmt->fetchColumn();
            if ($v === 'AM' || $v === 'PM') {
                $value = (string)$v;
            }
        } catch (\Throwable $e) {
            // Column may be missing on tenants that have not run migration 046.
            $value = null;
        }
    }
    $cache[$id] = $value;
    return $value;
}

/**
 * Effective shift for the current actor.
 *
 * - Assigned cashiers (dl_users.shift set) are locked to their shift:
 *   the AM cashier stays on AM and may keep editing its own ledger even
 *   after the PM shift has started; the PM cashier stays on PM.
 * - Unassigned accounts (admin/supervisor/auditor, or cashiers without
 *   an assignment) follow the time-based active shift (dl_currentShift).
 */
function dl_userShift(array $user): string
{
    $assigned = dl_userAssignedShift($user);
    if ($assigned !== null) {
        return $assigned;
    }
    return dl_currentShift();
}

/**
 * Whether the account is bound to a specific shift (AM or PM) via
 * dl_users.shift. Bound cashiers may only ever view/edit that shift.
 */
function dl_userShiftBound(array $user): bool
{
    return dl_userAssignedShift($user) !== null;
}

/**
 * Resolve and enforce the ledger shift for the current actor.
 *
 * - A shift-bound cashier (dl_users.shift set) is FORCED to their assigned
 *   shift: any `shift` value coming from the request is ignored, so they can
 *   never open or edit the other shift's ledger.
 * - Everyone else (admin/supervisor/unassigned) follows the requested shift
 *   and defaults to the time-based active shift (dl_currentShift), which
 *   stays PM for the rest of the day once the AM cutoff has passed.
 *
 * @return array{shift: string, bound: bool}
 */
function dl_resolveLedgerShift(array $user, array $input): array
{
    $boundShift = dl_userAssignedShift($user);
    if ($boundShift !== null) {
        return ['shift' => $boundShift, 'bound' => true];
    }
    $shift = (($input['shift'] ?? dl_currentShift()) === 'PM') ? 'PM' : 'AM';
    return ['shift' => $shift, 'bound' => false];
}


function dl_generateSku(): string
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    $stmt = $ctx->db()->query('SELECT MAX(id) FROM dl_products');
    $nextId = ((int)$stmt->fetchColumn()) + 1;
    return 'BBS-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);
}

function dl_getActorUserId(array $user): int
{
    $userId = 0;
    if (isset($user['id']) && is_numeric($user['id'])) {
        $userId = (int)$user['id'];
        if ($userId <= 0) {
            $userId = 0;
        }
    }
    if ($userId > 0) {
        return $userId;
    }

    $sub = (string)($user['sub'] ?? '');
    if ($sub !== '' && preg_match('/^(?:admin|supervisor|cashier|production_in_charge|auditor|viewer):(\d+)$/', $sub, $m)) {
        return (int)$m[1];
    }
    if (is_numeric($sub)) {
        return (int)$sub;
    }

    return 0;
}

function dl_accessibleBranchIds(array $user): array
{
    $ctx = module();
    if (!$ctx) {
        return [];
    }

    $role = (string)($user['role'] ?? '');
    if (in_array($role, ['admin', 'auditor', 'viewer'], true)) {
        $stmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id');
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
    }

    if ($role === 'supervisor') {
        $sid = dl_getActorUserId($user);
        if ($sid <= 0) {
            return [];
        }
        $stmt = $ctx->db()->prepare(
            'SELECT b.id
             FROM dl_user_branches ub
             INNER JOIN dl_branches b ON b.id = ub.branch_id
             WHERE ub.user_id = :sid AND b.is_active = 1
             ORDER BY b.id'
        );
        $stmt->execute([':sid' => $sid]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
    }

    if ($role === 'production_in_charge') {
        $pid = dl_getActorUserId($user);
        if ($pid <= 0) {
            return [];
        }
        $stmt = $ctx->db()->prepare(
            'SELECT b.id
             FROM dl_user_branches ub
             INNER JOIN dl_branches b ON b.id = ub.branch_id
             WHERE ub.user_id = :pid AND b.is_active = 1
             ORDER BY b.id'
        );
        $stmt->execute([':pid' => $pid]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
    }

    $branchId = dl_getUserBranchId();
    return $branchId ? [$branchId] : [];
}

/**
 * Canonical branch authorization — deny-by-default.
 *
 * Returns ['branch_id' => int, 'accessible' => int[]].
 * If an explicit branch_id is provided in input/GET and it is NOT in the
 * actor's accessible set, the response is a structured denial (caller handles
 * 403). Never silently falls back to a different branch when the caller
 * explicitly requested one.
 *
 * - Admins: accessible = all active tenant branches, default = first active.
 * - Supervisors: accessible = assigned active branches via dl_user_branches.
 * - Production in-charge: accessible = assigned active branches.
 * - Cashiers: locked to single assigned branch (accessible = that branch only).
 */
function dl_authorizeBranch(array $user, array $input = []): array
{
    $role = (string)($user['role'] ?? '');

    // --- Build accessible set ---
    if ($role === 'cashier') {
        $accessible = [];
        $branchId = dl_getUserBranchId();
        if ($branchId) {
            $accessible = [$branchId];
        }
        $requestedBranchId = 0;
        if (!empty($input['branch_id'])) {
            $requestedBranchId = (int)$input['branch_id'];
        } elseif (!empty($_GET['branch_id'])) {
            $requestedBranchId = (int)$_GET['branch_id'];
        }
        if ($requestedBranchId > 0 && $requestedBranchId !== (int)$branchId) {
            return ['branch_id' => -1, 'accessible' => $accessible];
        }
        return ['branch_id' => $branchId ?: 0, 'accessible' => $accessible];
    }

    $accessible = dl_accessibleBranchIds($user);
    $defaultBranchId = count($accessible) > 0 ? $accessible[0] : 0;

    // Check for an explicit requested branch
    $requestedBranchId = 0;
    if (!empty($input['branch_id'])) {
        $requestedBranchId = (int)$input['branch_id'];
    } elseif (!empty($_GET['branch_id'])) {
        $requestedBranchId = (int)$_GET['branch_id'];
    }

    if ($requestedBranchId > 0) {
        if (in_array($requestedBranchId, $accessible, true)) {
            return ['branch_id' => $requestedBranchId, 'accessible' => $accessible];
        }
        // Explicit unauthorized branch — deny, do not fall back
        return ['branch_id' => -1, 'accessible' => $accessible];
    }

    return ['branch_id' => $defaultBranchId, 'accessible' => $accessible];
}

/**
 * @deprecated Use dl_authorizeBranch() instead. Kept for backward compat.
 */
function dl_resolveLedgerBranchId(array $user, array $input = []): int
{
    $result = dl_authorizeBranch($user, $input);
    return $result['branch_id'] > 0 ? $result['branch_id'] : 0;
}

function dl_denyBranch(string $message = 'Branch not authorized'): void
{
    http_response_code(403);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($path, '/daily-ledger/api/')) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message]);
    } else {
        echo $message;
    }
    exit;
}

function dl_generateMovementUuid(): string
{
    try {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    } catch (\Throwable $e) {
        return uniqid('dlm-', true);
    }
}

function dl_computeSalesValue(int $begBal, int $addtl, int $withdraw, int $balEnd): int
{
    return max(0, $begBal + $addtl - $withdraw - $balEnd);
}

function dl_ledgerSalesQuantitySql(string $alias = 'dl'): string
{
    $safeAlias = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) ? $alias : 'dl';
    return "GREATEST(0, COALESCE({$safeAlias}.beg_bal,0) + COALESCE({$safeAlias}.addtl,0) - COALESCE({$safeAlias}.withdraw,0) - COALESCE({$safeAlias}.bal_end,0))";
}

function dl_ledgerSalesAmountSql(string $alias = 'dl', string $priceColumn = 'price_snapshot'): string
{
    $safeAlias = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) ? $alias : 'dl';
    $safePriceColumn = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $priceColumn) ? $priceColumn : 'price_snapshot';
    return dl_ledgerSalesQuantitySql($safeAlias) . " * COALESCE({$safeAlias}.{$safePriceColumn},0)";
}

function dl_applyLedgerDelta(int $branchId, int $productId, string $ledgerDate, int $delta, int $actorId, string $column = 'addtl', string $shift = 'AM'): array
{
    if (!in_array($column, ['addtl', 'withdraw'], true)) {
        throw new \RuntimeException('Invalid ledger column: ' . $column);
    }
    $shift = ($shift === 'PM') ? 'PM' : 'AM';

    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    $select = $ctx->db()->prepare(
        'SELECT id, addtl, withdraw FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift LIMIT 1 FOR UPDATE'
    );
    $select->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
    $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;

    $price = dl_resolveBranchProductPrice($branchId, $productId, $ledgerDate);

    if (!$row) {
        if ($delta < 0) {
            throw new \RuntimeException('Cannot reverse before an output/withdrawal exists for this date.');
        }
        $addtlVal = $column === 'addtl' ? $delta : 0;
        $withdrawVal = $column === 'withdraw' ? $delta : 0;
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :price, 0, :addtl, :withdraw, 0, :uid, :uid2)'
        );
        $ins->execute([
            ':bid' => $branchId,
            ':pid' => $productId,
            ':d' => $ledgerDate,
            ':shift' => $shift,
            ':price' => $price,
            ':addtl' => $addtlVal,
            ':withdraw' => $withdrawVal,
            ':uid' => $actorId > 0 ? $actorId : null,
            ':uid2' => $actorId > 0 ? $actorId : null,
        ]);
        dl_recomputeSales($branchId, $productId, $ledgerDate, max(0, $actorId), $shift);
        return [$column => $delta];
    }

    $currentVal = (int)($row[$column] ?? 0);
    $newVal = $currentVal + $delta;
    if ($newVal < 0) {
        $label = $column === 'addtl' ? 'additional (output)' : 'withdrawal';
        throw new \RuntimeException('Reverse quantity exceeds available ' . $label . ' stock.');
    }

    $upd = $ctx->db()->prepare(
        "UPDATE dl_daily_ledger
         SET {$column} = :val, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    );
    $upd->execute([
        ':val' => $newVal,
        ':uid' => $actorId > 0 ? $actorId : null,
        ':id' => (int)$row['id'],
    ]);

    dl_recomputeSales($branchId, $productId, $ledgerDate, max(0, $actorId), $shift);
    return [$column => $newVal];
}

function dl_applyCommissaryProductLedgerDelta(
    \Ikabud\Kernel\Contracts\DatabaseContract $db,
    int $commissaryBranchId,
    int $productId,
    string $ledgerDate,
    int $producedDelta,
    int $dispatchedDelta,
    int $actorId,
    int $wastageDelta = 0
): array {
    if ($commissaryBranchId <= 0 || $productId <= 0 || $ledgerDate === '') {
        return ['produced_qty' => 0, 'dispatched_qty' => 0, 'remaining_qty' => 0, 'skipped' => true];
    }

    // Verify the branch is actually a commissary
    $checkStmt = $db->prepare('SELECT id FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1');
    $checkStmt->execute([':id' => $commissaryBranchId]);
    if (!$checkStmt->fetchColumn()) {
        return ['produced_qty' => 0, 'dispatched_qty' => 0, 'remaining_qty' => 0, 'skipped' => true];
    }

    $select = $db->prepare(
        'SELECT id, produced_qty, dispatched_qty, wastage_qty
           FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d
          LIMIT 1
          FOR UPDATE'
    );
    $select->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $ledgerDate]);
    $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$row) {
        if ($producedDelta < 0 || $dispatchedDelta < 0 || $wastageDelta < 0) {
            throw new \RuntimeException('Cannot reverse commissary production before any output exists for this date.');
        }
        $newProduced = max(0, $producedDelta);
        $newDispatched = max(0, $dispatchedDelta);
        $newWastage = max(0, $wastageDelta);
        $db->prepare(
            'INSERT INTO dl_commissary_product_ledger
                (commissary_branch_id, product_id, ledger_date, produced_qty, dispatched_qty, wastage_qty, updated_by)
             VALUES (:cb, :pid, :d, :prod, :disp, :waste, :uid)'
        )->execute([
            ':cb' => $commissaryBranchId,
            ':pid' => $productId,
            ':d' => $ledgerDate,
            ':prod' => $newProduced,
            ':disp' => $newDispatched,
            ':waste' => $newWastage,
            ':uid' => $actorId > 0 ? $actorId : null,
        ]);
        return ['produced_qty' => $newProduced, 'dispatched_qty' => $newDispatched, 'wastage_qty' => $newWastage, 'remaining_qty' => $newProduced - $newDispatched - $newWastage, 'skipped' => false];
    }

    $currentProduced = (int)$row['produced_qty'];
    $currentDispatched = (int)$row['dispatched_qty'];
    $currentWastage = (int)$row['wastage_qty'];
    $newProduced = max(0, $currentProduced + $producedDelta);
    $newDispatched = max(0, $currentDispatched + $dispatchedDelta);
    $newWastage = max(0, $currentWastage + $wastageDelta);

    $db->prepare(
        'UPDATE dl_commissary_product_ledger
            SET produced_qty = :prod, dispatched_qty = :disp, wastage_qty = :waste, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
          WHERE id = :id'
    )->execute([
        ':prod' => $newProduced,
        ':disp' => $newDispatched,
        ':waste' => $newWastage,
        ':uid' => $actorId > 0 ? $actorId : null,
        ':id' => (int)$row['id'],
    ]);

    return ['produced_qty' => $newProduced, 'dispatched_qty' => $newDispatched, 'wastage_qty' => $newWastage, 'remaining_qty' => $newProduced - $newDispatched - $newWastage, 'skipped' => false];
}

function dl_processProductionMovement(array $user, string $movementType, array $input): array
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    $allowedTypes = ['withdrawal', 'output', 'reverse'];
    if (!in_array($movementType, $allowedTypes, true)) {
        throw new \RuntimeException('Invalid movement type.');
    }

    $role = (string)($user['role'] ?? '');
    $actorId = dl_getActorUserId($user);
    $flowMode = (string)($input['flow_mode'] ?? 'production');
    if (!in_array($flowMode, ['legacy', 'production'], true)) {
        $flowMode = 'production';
    }

    $clientOpId = trim((string)($input['client_op_id'] ?? ''));
    if ($clientOpId !== '') {
        $dupStmt = $ctx->db()->prepare('SELECT id, movement_uuid FROM dl_production_movements WHERE client_op_id = :coid LIMIT 1');
        $dupStmt->execute([':coid' => $clientOpId]);
        $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            return [
                'movement_id' => (int)$dup['id'],
                'movement_uuid' => (string)$dup['movement_uuid'],
                'duplicate' => true,
            ];
        }
    }

    $destinationBranchId = (int)($input['destination_branch_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $quantity = (int)($input['quantity'] ?? 0);
    $ledgerDate = (string)($input['ledger_date'] ?? dl_businessDate());
    $reason = trim((string)($input['reason'] ?? $input['override_reason'] ?? ''));
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    if ($drNumber !== '') {
        $drNumber = substr($drNumber, 0, 120);
    }

    if ($destinationBranchId <= 0 || $productId <= 0 || $quantity <= 0 || $ledgerDate === '') {
        throw new \RuntimeException('destination_branch_id, product_id, quantity, and ledger_date are required.');
    }

    $allowedBranchIds = dl_accessibleBranchIds($user);
    if (!in_array($destinationBranchId, $allowedBranchIds, true)) {
        throw new \RuntimeException('Destination branch is not allowed for this user.');
    }

    $branchStmt = $ctx->db()->prepare('SELECT id, is_active FROM dl_branches WHERE id = :id LIMIT 1');
    $branchStmt->execute([':id' => $destinationBranchId]);
    $destinationBranch = $branchStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$destinationBranch || (int)($destinationBranch['is_active'] ?? 0) !== 1) {
        throw new \RuntimeException('Destination branch no longer exists or is inactive. Refresh the page and choose a current branch.');
    }

    dl_maybeAutoCloseBranchDay($destinationBranchId, $actorId);

    $dayStatus = dl_getDayStatus($destinationBranchId, $ledgerDate);
    if ($dayStatus === 'closed' && !dl_roleHasPermission($role, 'production.override')) {
        throw new \RuntimeException('Day is closed for this branch.');
    }

    $referenceMovementId = null;
    $delta = $quantity;
    $formalDeliveryEnabled = dl_isFormalDeliveryEnabled();
    if ($formalDeliveryEnabled && $movementType === 'withdrawal' && $flowMode === 'production') {
        if ($drNumber === '') {
            throw new \RuntimeException('Delivery Receipt number is required for production withdrawal when formal delivery workflow is enabled.');
        }

        $deliveryStmt = $ctx->db()->prepare(
            'SELECT d.id
               FROM dl_deliveries d
               INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
              WHERE d.destination_type = :destination_type
                AND d.destination_id = :destination_id
                AND d.dr_number = :dr_number
                AND d.status <> "voided"
                AND di.product_id = :product_id
              ORDER BY d.id DESC
              LIMIT 1'
        );
        $deliveryStmt->execute([
            ':destination_type' => 'branch',
            ':destination_id' => $destinationBranchId,
            ':dr_number' => $drNumber,
            ':product_id' => $productId,
        ]);
        if (!$deliveryStmt->fetchColumn()) {
            throw new \RuntimeException('Production withdrawal requires a matching branch delivery for the same DR before the downstream step can be encoded.');
        }
    }

    // Resolve commissary for output movements — credit commissary finished-goods ledger
    $commissaryBranchId = null;
    $isCommissaryDirectOutput = false;
    if ($movementType === 'output') {
        $commCheckStmt = $ctx->db()->prepare(
            'SELECT id, is_commissary, default_supply_mode, assigned_commissary_id
               FROM dl_branches WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $commCheckStmt->execute([':id' => $destinationBranchId]);
        $destBranch = $commCheckStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($destBranch && (int)($destBranch['is_commissary'] ?? 0) === 1) {
            $commissaryBranchId = $destinationBranchId;
            $isCommissaryDirectOutput = true;
        } else {
            $supply = dl_resolveProductSupplySource($destinationBranchId, $productId);
            if ($supply['source'] === 'commissary' && $supply['source_id'] !== null) {
                $commissaryBranchId = (int)$supply['source_id'];
            }
        }
    }

    $shouldAutoCreateFormalDelivery = $movementType === 'output'
        && $referenceMovementId === null
        && $formalDeliveryEnabled
        && $drNumber !== ''
        && !$isCommissaryDirectOutput;
    if ($movementType === 'reverse') {
        if ($reason === '') {
            throw new \RuntimeException('Reverse requires an override reason.');
        }
        $refId = (int)($input['reference_movement_id'] ?? 0);
        $refUuid = trim((string)($input['reference_movement_uuid'] ?? ''));

        if ($refId <= 0 && $refUuid === '') {
            throw new \RuntimeException('reference_movement_id or reference_movement_uuid is required for reverse.');
        }

        if ($refId > 0) {
            $refStmt = $ctx->db()->prepare(
                "SELECT id, destination_branch_id, product_id, quantity, ledger_date, flow_mode, movement_type, dr_number
                 FROM dl_production_movements
                 WHERE id = :id AND movement_type IN ('withdrawal','output')
                 LIMIT 1"
            );
            $refStmt->execute([':id' => $refId]);
        } else {
            $refStmt = $ctx->db()->prepare(
                "SELECT id, destination_branch_id, product_id, quantity, ledger_date, flow_mode, movement_type, dr_number
                 FROM dl_production_movements
                 WHERE movement_uuid = :uuid AND movement_type IN ('withdrawal','output')
                 LIMIT 1"
            );
            $refStmt->execute([':uuid' => $refUuid]);
        }
        $ref = $refStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ref) {
            throw new \RuntimeException('Reference movement not found.');
        }

        $referenceMovementId = (int)$ref['id'];
        $referenceMovementType = (string)$ref['movement_type'];
        $destinationBranchId = (int)$ref['destination_branch_id'];
        $productId = (int)$ref['product_id'];
        $quantity = (int)$ref['quantity'];
        $ledgerDate = (string)$ref['ledger_date'];
        $flowMode = (string)$ref['flow_mode'];
        if ($drNumber === '') {
            $drNumber = trim((string)($ref['dr_number'] ?? ''));
        }

        if (!in_array($destinationBranchId, $allowedBranchIds, true)) {
            throw new \RuntimeException('You cannot reverse a movement outside your branch scope.');
        }

        $reverseExists = $ctx->db()->prepare("SELECT id FROM dl_production_movements WHERE reference_movement_id = :rid AND movement_type = 'reverse' LIMIT 1");
        $reverseExists->execute([':rid' => $referenceMovementId]);
        if ($reverseExists->fetchColumn()) {
            throw new \RuntimeException('Reference movement is already reversed.');
        }

        $delta = -$quantity;
    }

    // Route each movement type to the correct ledger column:
    // output (delivered to branch) → addtl, withdrawal (pulled from branch) → withdraw
    if ($movementType === 'reverse') {
        $ledgerColumn = $referenceMovementType === 'withdrawal' ? 'withdraw' : 'addtl';
    } else {
        $ledgerColumn = $movementType === 'withdrawal' ? 'withdraw' : 'addtl';
    }

    $ctx->db()->beginTransaction();
    try {
        // Credit commissary finished-goods ledger for output movements
        $commissaryLedgerState = null;
        if ($movementType === 'output' && $commissaryBranchId !== null) {
            $commissaryLedgerState = dl_applyCommissaryProductLedgerDelta(
                $ctx->db(),
                $commissaryBranchId,
                $productId,
                $ledgerDate,
                $delta,  // produced_qty += quantity
                0,       // dispatched_qty tracked separately via delivery
                $actorId
            );

            if (empty($commissaryLedgerState['skipped'])) {
                dl_auditLog(
                    'commissary_production',
                    $commissaryBranchId,
                    'dl_commissary_product_ledger',
                    "{$commissaryBranchId}-{$productId}-{$ledgerDate}",
                    null,
                    [
                        'commissary_branch_id' => $commissaryBranchId,
                        'product_id' => $productId,
                        'ledger_date' => $ledgerDate,
                        'produced_qty' => $commissaryLedgerState['produced_qty'],
                        'dispatched_qty' => $commissaryLedgerState['dispatched_qty'],
                        'remaining_qty' => $commissaryLedgerState['remaining_qty'],
                        'movement_id' => null, // will be set after insert
                    ]
                );
            }
        }

        if ($shouldAutoCreateFormalDelivery || $isCommissaryDirectOutput) {
            // Do NOT update addtl directly — commissary production is tracked
            // in dl_commissary_product_ledger. Branch receives addtl only when
            // it accepts a delivery via Receive Stock.
            $ledgerState = [$ledgerColumn => 0];
        } else {
            $ledgerState = dl_applyLedgerDelta($destinationBranchId, $productId, $ledgerDate, $delta, $actorId, $ledgerColumn);
        }

        $movementUuid = dl_generateMovementUuid();
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_production_movements (
                movement_uuid, client_op_id, movement_type, flow_mode,
                     destination_branch_id, product_id, ledger_date, quantity, dr_number,
                override_reason, reference_movement_id, source_payload,
                created_by_id, created_by_role
             ) VALUES (
                :uuid, :coid, :mtype, :fmode,
                     :bid, :pid, :ldate, :qty, :dr,
                :reason, :refid, :payload,
                :uid, :role
             )'
        );
        $ins->execute([
            ':uuid' => $movementUuid,
            ':coid' => $clientOpId !== '' ? $clientOpId : null,
            ':mtype' => $movementType,
            ':fmode' => $flowMode,
            ':bid' => $destinationBranchId,
            ':pid' => $productId,
            ':ldate' => $ledgerDate,
            ':qty' => $quantity,
            ':dr' => $drNumber !== '' ? $drNumber : null,
            ':reason' => $reason !== '' ? $reason : null,
            ':refid' => $referenceMovementId,
            ':payload' => json_encode($input, JSON_UNESCAPED_SLASHES),
            ':uid' => $actorId > 0 ? $actorId : null,
            ':role' => $role !== '' ? $role : 'unknown',
        ]);
        $movementId = (int)$ctx->db()->lastInsertId();

        $autoDeliveryId = null;
        if ($shouldAutoCreateFormalDelivery) {
            $autoDeliveryId = dl_upsertCommissaryOutputDeliveryItem(
                $ctx->db(),
                $destinationBranchId,
                $productId,
                $ledgerDate,
                $quantity,
                $drNumber,
                $actorId,
                $movementId,
                $commissaryBranchId
            );
        }

        dl_auditLog(
            'production_' . $movementType,
            $destinationBranchId,
            'dl_production_movements',
            (string)$movementId,
            null,
            [
                'movement_uuid' => $movementUuid,
                'flow_mode' => $flowMode,
                'destination_branch_id' => $destinationBranchId,
                'product_id' => $productId,
                'ledger_date' => $ledgerDate,
                'quantity' => $quantity,
                'dr_number' => $drNumber,
                'reference_movement_id' => $referenceMovementId,
                'reason' => $reason,
                'resulting_' . $ledgerColumn => (int)($ledgerState[$ledgerColumn] ?? 0),
            ],
            $reason !== '' ? $reason : null
        );

        $ctx->db()->commit();

        return [
            'movement_id' => $movementId,
            'movement_uuid' => $movementUuid,
            'movement_type' => $movementType,
            'flow_mode' => $flowMode,
            'destination_branch_id' => $destinationBranchId,
            'product_id' => $productId,
            'ledger_date' => $ledgerDate,
            'quantity' => $quantity,
            'dr_number' => $drNumber,
            'delivery_id' => $autoDeliveryId,
            'resulting_' . $ledgerColumn => (int)($ledgerState[$ledgerColumn] ?? 0),
            'ledger_column' => $ledgerColumn,
            'duplicate' => false,
        ];
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        throw $e;
    }
}

function dl_upsertCommissaryOutputDeliveryItem(
    \Ikabud\Kernel\Contracts\DatabaseContract $db,
    int $branchId,
    int $productId,
    string $deliveryDate,
    int $quantity,
    string $drNumber,
    int $actorId,
    int $movementId,
    ?int $commissaryBranchId = null
): int {
    if ($branchId <= 0 || $productId <= 0 || $quantity <= 0 || trim($drNumber) === '') {
        throw new \RuntimeException('Formal production output delivery requires branch, product, quantity, and DR number.');
    }

    $existingPaper = dl_findPaperCapturedCommissaryDelivery($db, $branchId, $deliveryDate, $drNumber);
    if ($existingPaper) {
        $deliveryId = (int)$existingPaper['id'];
        if (dl_deliveryHasActiveReceivings($db, $deliveryId)) {
            throw new \RuntimeException('Matching paper DR delivery already has a receiving. Encode the source in Usage/Commissary instead of creating another delivery from Production Output.');
        }

        $itemStmt = $db->prepare(
            'SELECT id, quantity FROM dl_delivery_items WHERE delivery_id = :delivery_id AND product_id = :product_id LIMIT 1'
        );
        $itemStmt->execute([':delivery_id' => $deliveryId, ':product_id' => $productId]);
        $existingItem = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingItem) {
            $newQty = (int)$existingItem['quantity'] + $quantity;
            $db->prepare('UPDATE dl_delivery_items SET quantity = :quantity WHERE id = :id')
                ->execute([':quantity' => $newQty, ':id' => (int)$existingItem['id']]);
        } else {
            $priceGroupId = dl_defaultPriceGroupId();
            $db->prepare(
                'INSERT INTO dl_delivery_items
                    (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
                 VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
            )->execute([
                ':delivery_id' => $deliveryId,
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':unit' => 'pcs',
                ':unit_cost_snapshot' => 0,
                ':price_snapshot' => dl_resolveProductPrice($productId, $priceGroupId, $deliveryDate),
                ':price_group_id' => $priceGroupId,
                ':remarks' => 'production_output_movement:' . $movementId,
            ]);
        }

        // Debit commissary dispatched_qty for the added quantity
        if ($commissaryBranchId !== null) {
            dl_applyCommissaryProductLedgerDelta($db, $commissaryBranchId, $productId, $deliveryDate, 0, $quantity, $actorId);
        }

        dl_auditLog('update_delivery', $branchId, 'dl_deliveries', (string)$deliveryId, null, [
            'dr_number' => $drNumber,
            'source' => 'production_output',
            'movement_id' => $movementId,
            'product_id' => $productId,
            'quantity_added' => $quantity,
            'commissary_branch_id' => $commissaryBranchId,
        ]);

        return $deliveryId;
    }

    $existingAuto = dl_findAutoCommissaryDelivery($db, $branchId, $deliveryDate, $drNumber);
    $priceGroupId = dl_defaultPriceGroupId();
    if ($existingAuto) {
        $deliveryId = (int)$existingAuto['id'];
        if (dl_deliveryHasActiveReceivings($db, $deliveryId)) {
            throw new \RuntimeException('Delivery already has a receiving. Void the receiving first before changing production output for this DR.');
        }
    } else {
        $stmt = $db->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, status, created_by, posted_by, posted_at, remarks)
             VALUES (:origin_type, :origin_id, :destination_type, :destination_id, :dr_number,
                     :delivery_date, "posted", :created_by, :posted_by, NOW(), :remarks)'
        );
        $stmt->execute([
            ':origin_type' => 'commissary',
            ':origin_id' => $commissaryBranchId,
            ':destination_type' => 'branch',
            ':destination_id' => $branchId,
            ':dr_number' => $drNumber,
            ':delivery_date' => $deliveryDate,
            ':created_by' => $actorId > 0 ? $actorId : null,
            ':posted_by' => $actorId > 0 ? $actorId : null,
            ':remarks' => dl_autoCommissaryDeliveryRemark(),
        ]);
        $deliveryId = (int)$db->lastInsertId();

        dl_auditLog('create_delivery', $branchId, 'dl_deliveries', (string)$deliveryId, null, [
            'dr_number' => $drNumber,
            'status' => 'posted',
            'source' => 'production_output',
            'movement_id' => $movementId,
            'commissary_branch_id' => $commissaryBranchId,
        ]);
    }

    // Debit commissary dispatched_qty for the new delivery
    if ($commissaryBranchId !== null) {
        dl_applyCommissaryProductLedgerDelta($db, $commissaryBranchId, $productId, $deliveryDate, 0, $quantity, $actorId);
    }

    $itemStmt = $db->prepare(
        'SELECT id, quantity FROM dl_delivery_items WHERE delivery_id = :delivery_id AND product_id = :product_id LIMIT 1'
    );
    $itemStmt->execute([':delivery_id' => $deliveryId, ':product_id' => $productId]);
    $existingItem = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($existingItem) {
        $newQty = (int)$existingItem['quantity'] + $quantity;
        $db->prepare('UPDATE dl_delivery_items SET quantity = :quantity WHERE id = :id')
            ->execute([':quantity' => $newQty, ':id' => (int)$existingItem['id']]);
    } else {
        $db->prepare(
            'INSERT INTO dl_delivery_items
                (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
             VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
        )->execute([
            ':delivery_id' => $deliveryId,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':unit' => 'pcs',
            ':unit_cost_snapshot' => 0,
            ':price_snapshot' => dl_resolveProductPrice($productId, $priceGroupId, $deliveryDate),
            ':price_group_id' => $priceGroupId,
            ':remarks' => 'production_output_movement:' . $movementId,
        ]);
    }

    return $deliveryId;
}

function dl_recomputeSales(int $branchId, int $productId, string $date, int $userId, string $shift = 'AM'): void
{
    try {
        $ctx = module();
        if (!$ctx) return;
        $shift = ($shift === 'PM') ? 'PM' : 'AM';

        $stmt = $ctx->db()->prepare(
            'SELECT beg_bal, addtl, withdraw, bal_end FROM dl_daily_ledger
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
        );
        $stmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $sales = dl_computeSalesValue((int)$row['beg_bal'], (int)$row['addtl'], (int)$row['withdraw'], (int)$row['bal_end']);

        $ctx->db()->prepare(
            'UPDATE dl_daily_ledger SET sales = :sales, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
        )->execute([':sales' => $sales, ':uid' => $userId, ':bid' => $branchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
    } catch (\Throwable $e) {
        // Non-fatal
    }
}

function dl_computeVarianceSilently(int $branchId, int $productId, string $date, int $begBal): void
{
    $ctx = module();
    if (!$ctx) return;

    // Find previous day's bal_end for same branch+product. With shift-period
    // ledgers the day's ending physical count is the PM row (fallback AM).
    $stmt = $ctx->db()->prepare(
        'SELECT bal_end FROM dl_daily_ledger
         WHERE branch_id = :bid AND product_id = :pid AND ledger_date < :d
         ORDER BY ledger_date DESC, CASE shift WHEN \'PM\' THEN 1 ELSE 0 END DESC LIMIT 1'
    );
    $stmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prev) return; // No previous day — nothing to compare

    $prevBalEnd = (int)$prev['bal_end'];
    $variance   = $begBal - $prevBalEnd;

    if ($variance === 0) {
        // No variance — remove any existing flag
        $ctx->db()->prepare(
            'DELETE FROM dl_variance_flags WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d'
        )->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
        return;
    }

    // Upsert variance flag
    $ctx->db()->prepare(
        'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, prev_bal_end, current_beg_bal, variance)
         VALUES (:bid, :pid, :d, :prev, :beg, :var)
         ON DUPLICATE KEY UPDATE prev_bal_end = VALUES(prev_bal_end), current_beg_bal = VALUES(current_beg_bal), variance = VALUES(variance)'
    )->execute([
        ':bid' => $branchId,
        ':pid' => $productId, ':d' => $date,
        ':prev' => $prevBalEnd, ':beg' => $begBal, ':var' => $variance,
    ]);
}

// ─── Cashier Handlers ──────────────────────────────────────────────────

function dlCookieName(): string
{
    return 'daily_ledger_token';
}

function dlSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    $expiry = time() + max(60, $expiresInSeconds);
    setcookie(dlCookieName(), $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => 'Strict',
    ]);
}

function dlClearAuthCookie(): void
{
    setcookie(dlCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => 'Strict',
    ]);
}

function dlUserFromRequest(): ?array
{
    $token = null;
    $cookieToken = kernelCookie(dlCookieName());

    // Prefer Authorization: Bearer <jwt> for module API calls
    $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($authHeader === '') {
        $authHeader = (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }
    if ($authHeader === '' && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        if (is_array($hdrs)) {
            foreach ($hdrs as $k => $v) {
                if (is_string($k) && is_string($v) && strtolower($k) === 'authorization') {
                    $authHeader = $v;
                    break;
                }
            }
        }
    }
    if ($authHeader !== '' && preg_match('/Bearer\s+(.+)$/i', $authHeader, $m)) {
        $token = trim((string)($m[1] ?? ''));
    }
    // Fallback to module cookie for browser page requests
    if ($token === null || $token === '') {
        if (is_string($cookieToken) && $cookieToken !== '') {
            $token = $cookieToken;
        }
    }
    if (!is_string($token) || $token === '') {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/daily-ledger/api/')) {
            $authHeaderPresent = false;
            $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if ($authHeader !== '') {
                $authHeaderPresent = true;
            }
            if (!$authHeaderPresent && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $authHeaderPresent = true;
            }
            $cookiePresent = is_string($cookieToken) && $cookieToken !== '';
            write_log('daily-ledger api auth missing token', 'error', [
                'path' => $path,
                'http_authorization' => $authHeaderPresent,
                'redirect_http_authorization' => !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
                'cookie_present' => $cookiePresent,
                'has_getallheaders' => function_exists('getallheaders'),
            ]);
        }
        return null;
    }
    try {
        $payload = app()->jwt()->verify($token);
        if (!is_array($payload)) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if (str_starts_with($path, '/daily-ledger/api/')) {
                write_log('daily-ledger api auth invalid jwt', 'error', [
                    'path' => $path,
                    'token_len' => strlen($token),
                    'auth_header_present' => ($authHeader !== ''),
                    'cookie_present' => (is_string($cookieToken) && $cookieToken !== ''),
                ]);
            }
            return null;
        }
        if (($payload['source'] ?? '') !== 'daily-ledger') {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if (str_starts_with($path, '/daily-ledger/api/')) {
                write_log('daily-ledger api auth wrong source', 'error', [
                    'path' => $path,
                    'source' => $payload['source'] ?? null,
                    'role' => $payload['role'] ?? null,
                    'sub' => $payload['sub'] ?? null,
                ]);
            }
            return null;
        }
        return $payload;
    } catch (Throwable $e) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/daily-ledger/api/')) {
            write_log('daily-ledger api auth exception', 'error', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);
        }
        return null;
    }
}

function dlRequireAuth(array $roles = ['cashier', 'supervisor', 'admin']): array
{
    $u = dlUserFromRequest();
    if (!$u) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/daily-ledger/api/')) {
            dlJson(['ok' => false, 'error' => 'Auth required'], 401);
            exit;
        }
        dlRedirect('/daily-ledger/login');
    }
    $role = (string)($u['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/daily-ledger/api/')) {
            dlJson(['ok' => false, 'error' => 'Auth required'], 401);
            exit;
        }
        dlRedirect('/daily-ledger/login');
    }
    return $u;
}

function dlAuthenticatedHomeRedirect(): ?string
{
    $user = dlUserFromRequest();
    if (!is_array($user)) {
        return null;
    }

    $role = (string)($user['role'] ?? '');
    if ($role === 'cashier') {
        return '/daily-ledger/ledger';
    }

    if ($role === 'production_in_charge') {
        return '/daily-ledger/admin/production-output';
    }

    if ($role === 'viewer') {
        return '/daily-ledger/admin/overview';
    }

    return '/daily-ledger/admin/dashboard';
}

function dlPasswordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function dlForgotPasswordRateLimitSnapshot(string $scope, string $value): array
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $key = 'daily_ledger_forgot_password:' . $scope . ':' . sha1($normalized);
    $cached = app()->cache()->get('security_rate_limits', $key);
    if (!is_array($cached)) {
        return ['key' => $key, 'count' => 0];
    }

    return [
        'key' => $key,
        'count' => max(0, (int)($cached['count'] ?? 0)),
    ];
}

function dlForgotPasswordRateLimitExceeded(string $ip, string $identity): bool
{
    $policy = kernel_password_reset_policy();
    $ipState = dlForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown');
    if ((int)$ipState['count'] >= (int)$policy['forgot_rate_limit_ip_max']) {
        return true;
    }

    $identityState = dlForgotPasswordRateLimitSnapshot('identity', $identity);
    return (int)$identityState['count'] >= (int)$policy['forgot_rate_limit_identity_max'];
}

function dlForgotPasswordRateLimitRecord(string $ip, string $identity): void
{
    $policy = kernel_password_reset_policy();
    $entries = [
        dlForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown'),
        dlForgotPasswordRateLimitSnapshot('identity', $identity),
    ];

    foreach ($entries as $entry) {
        app()->cache()->set(
            'security_rate_limits',
            (string)$entry['key'],
            ['count' => ((int)($entry['count'] ?? 0)) + 1],
            (int)$policy['forgot_rate_limit_window_seconds']
        );
    }
}

function dlResetPasswordRateLimitExceeded(string $ip): bool
{
    $policy = kernel_password_reset_policy();
    $key = 'daily_ledger_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    return is_array($cached) && (int)($cached['count'] ?? 0) >= (int)$policy['reset_rate_limit_ip_max'];
}

function dlResetPasswordRateLimitRecord(string $ip): void
{
    $policy = kernel_password_reset_policy();
    $key = 'daily_ledger_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    $count = is_array($cached) ? max(0, (int)($cached['count'] ?? 0)) : 0;
    app()->cache()->set('security_rate_limits', $key, ['count' => $count + 1], (int)$policy['reset_rate_limit_window_seconds']);
}

function dlResetTokenIsValid(string $token): bool
{
    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return false;
    }

    try {
        $stmt = dlCtx()->db()->prepare(
            'SELECT id
             FROM dl_password_resets
             WHERE token_hash = :token_hash
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => dlPasswordResetTokenHash($token)]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function pageDailyLedgerLogin(): void
{
    $redirect = dlAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        dlRedirect($redirect);
    }

    echo dlRender('modules/daily-ledger/pages/login.disyl', dlLoginPageContext());
}

function pageDailyLedgerForgotPassword(): void
{
    $redirect = dlAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        dlRedirect($redirect);
    }

    echo app()->render('pages/forgot-password.disyl', dlLoginPageContext([
        'page_title' => dlAppName() . ' Forgot Password',
        'forgot_password_endpoint' => dlGetBaseUrl() . '/api/v1/auth/forgot-password',
        'login_page_url' => dlGetBaseUrl() . '/login',
    ]));
}

function pageDailyLedgerResetPassword(): void
{
    $redirect = dlAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        dlRedirect($redirect);
    }

    $token = trim((string)($_GET['token'] ?? ''));

    echo app()->render('pages/reset-password.disyl', dlLoginPageContext([
        'page_title' => dlAppName() . ' Reset Password',
        'reset_password_endpoint' => dlGetBaseUrl() . '/api/v1/auth/reset-password',
        'login_page_url' => dlGetBaseUrl() . '/login',
        'reset_token' => $token,
        'token_valid' => dlResetTokenIsValid($token),
    ]));
}

function dailyLedgerAuthLogin(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $input = dlInput();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($username === '' || $password === '') {
        write_log('daily-ledger auth login validation failed', 'info', [
            'username_present' => ($username !== ''),
            'password_present' => ($password !== ''),
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        ]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username or email and password are required.']);
        return;
    }

    $auth = null;
    try {
        $auth = app()->cap()->call('kernel.auth.authenticate@1', [
            'username' => '@daily-ledger:' . $username,
            'password' => $password,
        ], ['mode' => 'pipeline']);
    } catch (Throwable $e) {
        write_log('daily-ledger auth login exception', 'error', [
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'username' => $username,
            'message' => $e->getMessage(),
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Login failed.']);
        return;
    }

    if (!is_array($auth) || !is_array($auth['user'] ?? null) || (($auth['source'] ?? '') !== 'daily-ledger')) {
        write_log('daily-ledger auth login invalid credentials', 'info', [
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'username' => $username,
            'auth_is_array' => is_array($auth),
            'auth_source' => is_array($auth) ? ($auth['source'] ?? null) : null,
            'auth_user_present' => (is_array($auth) && is_array($auth['user'] ?? null)),
        ]);
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or email/password combination.']);
        return;
    }

    $u = $auth['user'];
    $role = (string)($u['role'] ?? '');
    $sub = (string)($u['sub'] ?? '');
    // The auth provider may not populate a numeric `id` (it sets sub as
    // "role:id"). The kernel audit records actor_module_user_id from payload id,
    // so a 0 here makes every activity entry anonymous. Derive it from sub.
    $payloadId = (int)($u['id'] ?? 0);
    if ($payloadId <= 0 && $sub !== '' && preg_match('/:(\d+)$/', $sub, $idMatch)) {
        $payloadId = (int)$idMatch[1];
    }
    $payload = [
        'sub' => $sub !== '' ? $sub : ($role . ':0'),
        'id' => $payloadId,
        'username' => (string)($u['username'] ?? $username),
        'name' => (string)($u['full_name'] ?? $username),
        'role' => $role,
        'source' => 'daily-ledger',
    ];
    $tokens = dl_generateAuthTokens($payload);
    dlSetAuthCookie($tokens['token'], (int)$tokens['expires_in']);

    if ($role === 'cashier') {
        $redirect = '/daily-ledger/ledger';
    } elseif ($role === 'production_in_charge') {
        $redirect = '/daily-ledger/admin/production-output';
    } elseif ($role === 'viewer') {
        $redirect = '/daily-ledger/admin/overview';
    } else {
        $redirect = '/daily-ledger/admin/dashboard';
    }
    echo json_encode([
        'ok' => true,
        'redirect' => $redirect,
        'token' => $tokens['token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_in' => $tokens['expires_in'],
        'refresh_expires_in' => $tokens['refresh_expires_in'],
    ]);
}

function dailyLedgerForgotPassword(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $policy = kernel_password_reset_policy();
    $ttlMinutes = max(1, (int)$policy['token_ttl_minutes']);
    $input = dlInput();
    $identity = trim((string)($input['identity'] ?? ''));
    if ($identity === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username or email is required.']);
        return;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (dlForgotPasswordRateLimitExceeded($requestIp, $identity)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => (string)$policy['forgot_rate_limit_message']]);
        return;
    }

    dlForgotPasswordRateLimitRecord($requestIp, $identity);

    try {
        $user = dlFindActiveUserByIdentity($identity);
        if (is_array($user)) {
            $email = strtolower(trim((string)($user['email'] ?? '')));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = dlPasswordResetTokenHash($rawToken);

                $clear = dlCtx()->db()->prepare(
                    'UPDATE dl_password_resets
                     SET used_at = NOW()
                     WHERE user_id = :user_id
                       AND used_at IS NULL'
                );
                $clear->execute([':user_id' => (int)$user['id']]);

                $insert = dlCtx()->db()->prepare(
                    'INSERT INTO dl_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
                     VALUES (:user_id, :token_hash, :requester_ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
                );
                $insert->execute([
                    ':user_id' => (int)$user['id'],
                    ':token_hash' => $tokenHash,
                    ':requester_ip' => $requestIp,
                ]);

                if (function_exists('buildEmailTemplate') && function_exists('sendEmail')) {
                    $displayName = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
                    $resetUrl = dlExternalBaseUrl() . '/daily-ledger/reset-password?token=' . urlencode($rawToken);
                    $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ',</p>'
                        . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your Daily Ledger password.</p>'
                        . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in ' . $ttlMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>';
                    $body = buildEmailTemplate('Reset Your Daily Ledger Password', $content, 'Reset Password', $resetUrl);
                    $sent = sendEmail($email, 'Daily Ledger Password Reset', $body);
                    if (!$sent) {
                        write_log('daily-ledger forgot-password email dispatch failed for user_id=' . (string)$user['id'], 'error');
                    }
                }
            }
        }

        echo json_encode(['ok' => true, 'message' => (string)$policy['forgot_success_message']]);
    } catch (Throwable $e) {
        write_log('daily-ledger forgot-password failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to process request right now.']);
    }
}

function dailyLedgerResetPassword(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $policy = kernel_password_reset_policy();
    $input = dlInput();
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => (string)$policy['invalid_token_message']]);
        return;
    }

    if (strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters.']);
        return;
    }

    if ($password !== $confirmPassword) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']);
        return;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (dlResetPasswordRateLimitExceeded($requestIp)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => (string)$policy['reset_rate_limit_message']]);
        return;
    }

    dlResetPasswordRateLimitRecord($requestIp);

    try {
        $stmt = dlCtx()->db()->prepare(
            'SELECT pr.id AS reset_id, pr.user_id
             FROM dl_password_resets pr
             INNER JOIN dl_users du ON du.id = pr.user_id
             WHERE pr.token_hash = :token_hash
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
               AND du.is_active = 1
               AND du.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => dlPasswordResetTokenHash($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => (string)$policy['invalid_token_message']]);
            return;
        }

        $updateUser = dlCtx()->db()->prepare(
            'UPDATE dl_users
             SET password_hash = :password_hash,
                 updated_at = NOW()
             WHERE id = :user_id'
        );
        $updateUser->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':user_id' => (int)$row['user_id'],
        ]);

        $updateReset = dlCtx()->db()->prepare(
            'UPDATE dl_password_resets
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $updateReset->execute([':user_id' => (int)$row['user_id']]);

        echo json_encode([
            'ok' => true,
            'message' => (string)$policy['reset_success_message'],
            'redirect' => '/daily-ledger/login',
        ]);
    } catch (Throwable $e) {
        write_log('daily-ledger reset-password failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to reset password right now.']);
    }
}

function dailyLedgerAuthRefresh(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $input = dlInput();
    $refreshToken = trim((string)($input['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'refresh_token is required.']);
        return;
    }

    $payload = dl_verifyRefreshToken($refreshToken);
    if (!is_array($payload)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid refresh token.']);
        return;
    }

    dl_revokeRefreshToken($refreshToken);
    $tokens = dl_generateAuthTokens($payload);
    dlSetAuthCookie($tokens['token'], (int)$tokens['expires_in']);

    echo json_encode([
        'ok' => true,
        'token' => $tokens['token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_in' => $tokens['expires_in'],
        'refresh_expires_in' => $tokens['refresh_expires_in'],
    ]);
}

function dailyLedgerLogout(): void
{
    dlClearAuthCookie();
    dlRedirect('/daily-ledger/login');
}

/**
 * @return array<int,array<string,mixed>>
 */
function dl_fetchCashierLedgerRows(\Ikabud\Kernel\Contracts\ModuleDB $db, int $branchId, string $ledgerDate, string $shift): array
{
    $stmt = $db->prepare(
        'SELECT p.id AS product_id, p.name, p.current_price, p.sort_order,
                COALESCE(dl.beg_bal, 0) AS beg_bal, COALESCE(dl.addtl, 0) AS addtl,
                COALESCE(dl.withdraw, 0) AS withdraw, COALESCE(dl.bal_end, 0) AS bal_end,
                GREATEST(0, COALESCE(dl.beg_bal,0) + COALESCE(dl.addtl,0) - COALESCE(dl.withdraw,0) - COALESCE(dl.bal_end,0)) AS sales, dl.price_snapshot,
                COALESCE(am.bal_end, 0) AS am_bal_end
           FROM dl_products p
           INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
           LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d AND dl.shift = :shift
           LEFT JOIN dl_daily_ledger am ON am.product_id = p.id AND am.branch_id = :bidam AND am.ledger_date = :dam AND am.shift = \'AM\'
          WHERE p.is_active = 1
          ORDER BY p.sort_order, p.name'
    );
    $stmt->execute([':bid' => $branchId, ':bid2' => $branchId, ':d' => $ledgerDate, ':shift' => $shift, ':bidam' => $branchId, ':dam' => $ledgerDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function handleCashierLedger(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlRequireAuth(['cashier', 'supervisor', 'admin']);
    $role = (string)($user['role'] ?? '');
    if (!in_array($role, ['cashier', 'supervisor', 'admin'], true)) {
        $ctx->redirect('/');
    }

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        dl_denyBranch('Branch not authorized');
        return;
    }
    $branchId   = $authResult['branch_id'];
    $today      = dl_businessDate();
    $ledgerDate = !empty($input['date']) ? (string)$input['date'] : $today;
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift      = $shiftResolved['shift'];
    $shiftBound = $shiftResolved['bound'];
    $branchName = $branchId ? dl_getBranchName($branchId) : 'No Branch';
    $referenceOnly = ($role === 'cashier' && $ledgerDate !== $today);

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $dayStatus  = $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open';

    // Branch selector: only accessible branches for the current actor
    $branches = [];
    if ($branchId > 0) {
        $accessible = $authResult['accessible'];
        if (count($accessible) > 0) {
            $placeholders = implode(',', array_fill(0, count($accessible), '?'));
            $stmt = $ctx->db()->prepare(
                "SELECT id, code, name FROM dl_branches WHERE id IN ({$placeholders}) AND is_active = 1 ORDER BY name"
            );
            $stmt->execute($accessible);
            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $canLedgerOverride = dl_roleHasPermission($role, 'ledger.override');
    // All accessible branches for dispatch dropdown (branch-scoped)
    $allBranches = [];
    $accessible = $authResult['accessible'];
    if (count($accessible) > 0) {
        $placeholders = implode(',', array_fill(0, count($accessible), '?'));
        $stmtAll = $ctx->db()->prepare("SELECT id, code, name, is_commissary FROM dl_branches WHERE id IN ({$placeholders}) AND is_active = 1 ORDER BY name");
        $stmtAll->execute($accessible);
        $allBranches = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    // Pending incoming deliveries (count of distinct DR groups for this branch)
    // Includes both informal transfers (dl_cashier_withdrawals) and formal DRs (dl_deliveries)
    $incomingCount = 0;
    if ($branchId) {
        $incStmt = $ctx->db()->prepare(
            "SELECT COUNT(DISTINCT COALESCE(dr_number, CONCAT('o:', branch_id, ':', ledger_date)))
             FROM dl_cashier_withdrawals
             WHERE target_branch_id = :bid
               AND withdrawal_type = 'delivery'
               AND received_at IS NULL"
        );
        $incStmt->execute([':bid' => $branchId]);
        $incomingCount = (int)$incStmt->fetchColumn();

        if (dl_isFormalDeliveryEnabled()) {
            $formalIncStmt = $ctx->db()->prepare(
                "SELECT COUNT(*)
                 FROM dl_deliveries d
                 WHERE d.destination_type = 'branch'
                   AND d.destination_id = :bid
                   AND d.status = 'posted'
                   AND NOT EXISTS (
                       SELECT 1 FROM dl_branch_receivings br
                       WHERE br.delivery_id = d.id AND br.status <> 'voided'
                   )"
            );
            $formalIncStmt->execute([':bid' => $branchId]);
            $incomingCount += (int)$formalIncStmt->fetchColumn();
        }
    }

    $clockLabel = dl_operatingClockLabel();
    $actorId = dl_getActorUserId($user);
    $tenantScope = (string)(app()->tenant()->current() ?? '');
    $commissaryBranchId = null;
    $commissaryBranchName = null;
    if ($branchId) {
        $commStmt = $ctx->db()->prepare('SELECT assigned_commissary_id FROM dl_branches WHERE id = :id LIMIT 1');
        $commStmt->execute([':id' => $branchId]);
        $commRow = $commStmt->fetch(PDO::FETCH_ASSOC);
        if ($commRow && !empty($commRow['assigned_commissary_id'])) {
            $commissaryBranchId = (int)$commRow['assigned_commissary_id'];
            $commNameStmt = $ctx->db()->prepare('SELECT name FROM dl_branches WHERE id = :id LIMIT 1');
            $commNameStmt->execute([':id' => $commissaryBranchId]);
            $commissaryBranchName = $commNameStmt->fetchColumn() ?: null;
        }
    }

    // Liable persons: production incharge + supervisors for charge-to dropdown
    $liablePersons = $ctx->db()->query(
        "SELECT id, COALESCE(NULLIF(full_name, ''), username, CONCAT('User #', id)) AS name, role
           FROM dl_users
          WHERE is_active = 1
            AND deleted_at IS NULL
            AND role IN ('production_in_charge', 'supervisor', 'admin')
          ORDER BY role = 'production_in_charge' DESC, name ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // POS context: feature flag, cashier sell access, and the branch-day sales mode.
    $posEnabled = dl_isPosEnabled();
    $posMode = ($branchId && $posEnabled) ? dl_pos_dayMode($ctx->db(), $branchId, $ledgerDate) : ['mode' => 'manual', 'row' => null, 'decided' => false];
    $ledgerRows = $branchId ? dl_fetchCashierLedgerRows($ctx->db(), (int)$branchId, $ledgerDate, $shift) : [];

    echo dlRender('modules/daily-ledger/cashier/ledger.disyl', [
        'page_title'  => 'Daily Ledger',
        'user_name'   => $userName,
        'user_role'   => $role,
        'dl_user_id'  => $actorId > 0 ? $actorId : '',
        'tenant_scope' => $tenantScope,
        'current_page'=> 'ledger',
        'base_url' => dlGetBaseUrl(),
        'dl_token'    => (string)kernelCookie(dlCookieName(), ''),
        'branch_id'   => $branchId,
        'branch_name' => $branchName,
        'ledger_date' => $ledgerDate,
        'shift'       => $shift,
        'shift_locked' => $shiftBound,
        'today'       => $today,
        'day_status'  => $dayStatus,
        'branches'    => $branches,
        'is_cashier'  => ($role === 'cashier'),
        'reference_only' => $referenceOnly,
        'can_ledger_override' => $canLedgerOverride,
        'pos_enabled' => $posEnabled,
        'can_pos_sell' => $posEnabled && dl_pos_userCan($user, 'pos.sell'),
        'can_edit_delivery' => (function () use ($user) {
            $r = (string)($user['role'] ?? '');
            return in_array($r, ['supervisor', 'admin'], true) || dl_roleHasPermission($r, 'delivery.edit');
        })(),
        'sales_mode' => (string)$posMode['mode'],
        'sales_mode_decided' => (bool)$posMode['decided'],
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
        'all_branches' => $allBranches,
        'incoming_count' => $incomingCount,
        'formal_delivery_enabled' => dl_isFormalDeliveryEnabled(),
        'commissary_branch_id' => $commissaryBranchId,
        'commissary_branch_name' => $commissaryBranchName,
        'liable_persons' => $liablePersons,
        'liable_persons_json' => json_encode($liablePersons, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        'rows' => $ledgerRows,
    ]);
}

function handleCashierRows(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlRequireAuth(['cashier', 'supervisor', 'admin']);
    $input = $ctx->input();
    $role = (string)($user['role'] ?? '');
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $ledgerDate = !empty($input['date']) ? (string)$input['date'] : dl_businessDate();
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $referenceOnly = ($role === 'cashier' && $ledgerDate !== dl_businessDate());

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $dayStatus = $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open';

    if (!$branchId) {
        echo '<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-light);">No branch assigned</td></tr>';
        return;
    }

    $rows = dl_fetchCashierLedgerRows($ctx->db(), (int)$branchId, $ledgerDate, $shift);

    echo dlRender('modules/daily-ledger/cashier/partials/ledger-rows.disyl', [
        'rows'        => $rows,
        'branch_id'   => $branchId,
        'ledger_date' => $ledgerDate,
        'shift'       => $shift,
        'day_status'  => $dayStatus,
        'reference_only' => $referenceOnly,
    ]);
}

// ─── Cashier API ───────────────────────────────────────────────────────

function apiGetLedgerRows(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $ledgerDate = !empty($_GET['date']) ? (string)$_GET['date'] : (!empty($input['date']) ? (string)$input['date'] : dl_businessDate());
    $shiftSource = $input;
    if (!empty($_GET['shift'])) {
        $shiftSource['shift'] = (string)$_GET['shift'];
    }
    $shiftResolved = dl_resolveLedgerShift($user, $shiftSource);
    $shift = $shiftResolved['shift'];

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $dayStatus = $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open';

    if (!$branchId) {
        $ctx->json(['ok' => true, 'rows' => [], 'day_status' => $dayStatus]);
        return;
    }

    $stmt = $ctx->db()->prepare(
        'SELECT p.id AS product_id, p.name, p.current_price, p.sort_order,
                COALESCE(dl.beg_bal, 0) AS beg_bal, COALESCE(dl.addtl, 0) AS addtl,
                COALESCE(dl.withdraw, 0) AS withdraw, COALESCE(dl.bal_end, 0) AS bal_end,
                GREATEST(0, COALESCE(dl.beg_bal,0) + COALESCE(dl.addtl,0) - COALESCE(dl.withdraw,0) - COALESCE(dl.bal_end,0)) AS sales,
                COALESCE(am.bal_end, 0) AS am_bal_end
         FROM dl_products p
         INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
         LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d AND dl.shift = :shift
         LEFT JOIN dl_daily_ledger am ON am.product_id = p.id AND am.branch_id = :bidam AND am.ledger_date = :dam AND am.shift = \'AM\'
         WHERE p.is_active = 1
         ORDER BY p.sort_order, p.name'
    );
    $stmt->execute([':bid' => $branchId, ':bid2' => $branchId, ':d' => $ledgerDate, ':shift' => $shift, ':bidam' => $branchId, ':dam' => $ledgerDate]);
    $ctx->json([
        'ok' => true,
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'day_status' => $dayStatus,
        'shift' => $shift,
        'shift_locked' => $shiftResolved['bound'],
    ]);
}

function apiGetLedgerDayStatus(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $ledgerDate = !empty($_GET['date']) ? (string)$_GET['date'] : (!empty($input['date']) ? (string)$input['date'] : dl_businessDate());

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $ctx->json([
        'ok' => true,
        'day_status' => $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open',
    ]);
}


function apiGetCashierWithdrawals(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    $user = dlCurrentUser();
    $authResult = dl_authorizeBranch($user, $_GET);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!$productId || !$branchId) {
        $ctx->json(['ok' => false, 'error' => 'Missing product or branch']);
        return;
    }
    
    $stmt = $ctx->db()->prepare('SELECT id, withdrawal_type, dr_number, target_branch_id, quantity FROM dl_cashier_withdrawals WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d');
    $stmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $ctx->json(['ok' => true, 'withdrawals' => $rows]);
}

function apiSaveCashierWithdrawals(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    $user = dlCurrentUser();
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        $cached = dl_loadIdempotentResponse('cashier_withdrawal', $idempotencyKey);
        if ($cached !== null) {
            $ctx->json($cached);
            return;
        }
    }
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    if ($branchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Unable to resolve branch. Verify your branch assignment.'], 422);
        return;
    }
    $date = $input['date'] ?? date('Y-m-d');
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $header = (array)($input['header'] ?? []);
    $lines = (array)($input['lines'] ?? []);

    if (!$branchId) {
        $ctx->json(['ok' => false, 'error' => 'Missing branch']);
        return;
    }

    $type = (string)($header['withdrawal_type'] ?? 'charge');
    if (!in_array($type, ['charge', 'pullout', 'adjustment_add'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid withdrawal type']);
        return;
    }
    $drNumber = isset($header['dr_number']) && $header['dr_number'] !== '' ? (string)$header['dr_number'] : null;
    $targetBranchId = !empty($header['target_branch_id']) ? (int)$header['target_branch_id'] : null;
    $reasonCode = isset($header['reason_code']) && $header['reason_code'] !== '' ? (string)$header['reason_code'] : null;
    $customReason = isset($header['custom_reason']) ? trim((string)$header['custom_reason']) : '';
    $liableUserId = !empty($header['liable_user_id']) ? (int)$header['liable_user_id'] : null;
    $allowedReasons = ['spoilage','staff_meal','sampling','testing','promo','donation','damage','manual_adjustment','other'];
    if ($reasonCode !== null && !in_array($reasonCode, $allowedReasons, true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid reason_code'], 422);
        return;
    }
    if (in_array($type, ['charge','pullout','adjustment_add'], true) && $reasonCode === null) {
        $reasonCode = 'manual_adjustment';
    }
    if ($reasonCode === 'other' && $customReason === '') {
        $ctx->json(['ok' => false, 'error' => 'A custom reason is required when reason is Other.'], 422);
        return;
    }
    if ($customReason !== '' && mb_strlen($customReason) > 255) {
        $ctx->json(['ok' => false, 'error' => 'Custom reason must be 255 characters or fewer.'], 422);
        return;
    }
    if ($reasonCode !== 'other') {
        $customReason = '';
    }
    // adjustment_add requires a liable user
    if ($type === 'adjustment_add' && $liableUserId === null) {
        $ctx->json(['ok' => false, 'error' => 'adjustment_add requires a liable_user_id (charge to person).'], 422);
        return;
    }

    // Filter to valid product+qty pairs
    $validLines = [];
    foreach ($lines as $l) {
        $pid = isset($l['product_id']) ? (int)$l['product_id'] : 0;
        $qty = isset($l['quantity']) ? max(0, (int)$l['quantity']) : 0;
        if ($pid > 0 && $qty > 0) {
            $validLines[] = ['product_id' => $pid, 'quantity' => $qty];
        }
    }
    if (count($validLines) === 0) {
        $ctx->json(['ok' => false, 'error' => 'Add at least one product with a quantity greater than 0.']);
        return;
    }

    $role = (string)($user['role'] ?? '');
    $dayStatus = dl_getDayStatus($branchId, $date);
    if ($role === 'cashier' && $date !== dl_businessDate()) {
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }
    if ($dayStatus === 'closed' && $role === 'cashier') {
        $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
        return;
    }

    $userId = dl_getActorUserId($user);
    $totals = [];

    $ctx->db()->beginTransaction();
    try {
        $stmtIns = $ctx->db()->prepare(
            'INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, withdrawal_type, reason_code, custom_reason, dr_number, target_branch_id, quantity, encoded_by, liable_user_id)
             VALUES (:bid, :pid, :d, :typ, :rc, :crc, :dr, :tbid, :qty, :uid, :luid)'
        );
        $stmtSum = $ctx->db()->prepare(
            'SELECT COALESCE(SUM(quantity), 0) FROM dl_cashier_withdrawals
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND withdrawal_type <> :excludeType'
        );
        $stmtCheck = $ctx->db()->prepare(
            'SELECT id FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift FOR UPDATE'
        );
        // adjustment_add increases addtl (branch gets stock back); charge/pullout increase withdraw
        $isAddtl = ($type === 'adjustment_add');
        if ($isAddtl) {
            // addtl accumulates from multiple sources — use increment, not replace
            $stmtUpd = $ctx->db()->prepare(
                'UPDATE dl_daily_ledger SET addtl = addtl + :qty, updated_by = :uid
                 WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
            );
            $stmtInit = $ctx->db()->prepare(
                'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, addtl, encoded_by, updated_by)
                 VALUES (:bid, :pid, :d, :shift, :prc, :qty, :uid_enc, :uid_upd)'
            );
        } else {
            $stmtUpd = $ctx->db()->prepare(
                'UPDATE dl_daily_ledger SET withdraw = :wdr, updated_by = :uid
                 WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
            );
            $stmtInit = $ctx->db()->prepare(
                'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, withdraw, encoded_by, updated_by)
                 VALUES (:bid, :pid, :d, :shift, :prc, :wdr, :uid_enc, :uid_upd)'
            );
        }

        foreach ($validLines as $line) {
            $pid = $line['product_id'];
            $qty = $line['quantity'];

            $stmtIns->execute([
                ':bid' => $branchId,
                ':pid' => $pid,
                ':d' => $date,
                ':typ' => $type,
                ':rc' => $reasonCode,
                ':crc' => $customReason !== '' ? $customReason : null,
                ':dr' => $drNumber,
                ':tbid' => $targetBranchId,
                ':qty' => $qty,
                ':uid' => $userId,
                ':luid' => $liableUserId,
            ]);

            if ($isAddtl) {
                // adjustment_add: increment addtl by qty (adds stock back to branch)
                $stmtCheck->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
                if ($stmtCheck->fetch()) {
                    $stmtUpd->execute([
                        ':qty' => $qty,
                        ':uid' => $userId,
                        ':bid' => $branchId,
                        ':pid' => $pid,
                        ':d' => $date,
                        ':shift' => $shift,
                    ]);
                } else {
                    $price = dl_resolveBranchProductPrice($branchId, $pid, $date);
                    $stmtInit->execute([
                        ':bid' => $branchId,
                        ':pid' => $pid,
                        ':d' => $date,
                        ':shift' => $shift,
                        ':prc' => $price,
                        ':qty' => $qty,
                        ':uid_enc' => $userId,
                        ':uid_upd' => $userId,
                    ]);
                }
                $totals[] = ['product_id' => $pid, 'addtl' => $qty];
            } else {
                // charge/pullout: recalc withdraw from sum of all withdrawals
                $stmtSum->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':excludeType' => 'adjustment_add']);
                $newTotal = (int)$stmtSum->fetchColumn();

                $stmtCheck->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
                if ($stmtCheck->fetch()) {
                    $stmtUpd->execute([
                        ':wdr' => $newTotal,
                        ':uid' => $userId,
                        ':bid' => $branchId,
                        ':pid' => $pid,
                        ':d' => $date,
                        ':shift' => $shift,
                    ]);
                } else {
                    $price = dl_resolveBranchProductPrice($branchId, $pid, $date);
                    $stmtInit->execute([
                        ':bid' => $branchId,
                        ':pid' => $pid,
                        ':d' => $date,
                        ':shift' => $shift,
                        ':prc' => $price,
                        ':wdr' => $newTotal,
                        ':uid_enc' => $userId,
                        ':uid_upd' => $userId,
                    ]);
                }
                $totals[] = ['product_id' => $pid, 'total' => $newTotal];
            }
        }

        // ── Pullout return to commissary ──────────────────────────────
        // When a branch pullout is recorded with a target commissary,
        // create a return delivery and auto-receive so the commissary
        // ledger reflects the returned goods.
        // If the branch IS the commissary (self-managed production),
        // skip the delivery — no physical movement, just credit the ledger.
        $returnDeliveryId = null;
        $returnReceivingId = null;
        if ($type === 'pullout' && $targetBranchId !== null && dl_isFormalDeliveryEnabled()) {
            $commissaryCheck = $ctx->db()->prepare(
                'SELECT id, name FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1'
            );
            $commissaryCheck->execute([':id' => $targetBranchId]);
            $commissary = $commissaryCheck->fetch(PDO::FETCH_ASSOC);
            if ($commissary) {
                $actorId = dl_getActorUserId($user);
                $effectiveUserId = $actorId > 0 ? $actorId : null;
                $returnDr = '[pullout-return-' . $date . '-' . $branchId . '-' . date('His') . ']';

                // Skip self-delivery when branch IS the commissary (self-managed production)
                if ($branchId === $targetBranchId) {
                    // No physical delivery needed — goods stay at the production site.
                    // Still credit commissary product ledger below.
                } else {
                    $priceGroupId = dl_defaultPriceGroupId();

                    $delIns = $ctx->db()->prepare(
                        'INSERT INTO dl_deliveries
                            (origin_type, origin_id, destination_type, destination_id, dr_number,
                             delivery_date, status, created_by, posted_by, posted_at, remarks)
                         VALUES (:ot, :oid, :dt, :did, :dr, :dd, "posted", :uid1, :uid2, NOW(), :remarks)'
                    );
                    $delIns->execute([
                        ':ot' => 'branch',
                        ':oid' => $branchId,
                        ':dt' => 'branch',
                        ':did' => $targetBranchId,
                        ':dr' => $returnDr,
                        ':dd' => $date,
                        ':uid1' => $effectiveUserId,
                        ':uid2' => $effectiveUserId,
                        ':remarks' => '[cashier-pullout-return]',
                    ]);
                    $returnDeliveryId = (int)$ctx->db()->lastInsertId();

                    // Add delivery items
                    $itemIns = $ctx->db()->prepare(
                        'INSERT INTO dl_delivery_items
                            (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
                         VALUES (:did, :pid, :qty, :unit, :cost, :price, :pg, :remarks)'
                    );
                    foreach ($validLines as $line) {
                        $itemIns->execute([
                            ':did' => $returnDeliveryId,
                            ':pid' => $line['product_id'],
                            ':qty' => $line['quantity'],
                            ':unit' => 'pcs',
                            ':cost' => 0,
                            ':price' => dl_resolveProductPrice((int)$line['product_id'], $priceGroupId, $date),
                            ':pg' => $priceGroupId,
                            ':remarks' => 'pullout_return:' . $branchId,
                        ]);
                    }

                    // Auto-receive for commissary
                    $returnReceivingId = dl_acceptFormalDelivery(
                        $ctx->db(), $targetBranchId, $returnDeliveryId, $actorId, $date, null, $shift
                    );
                }

                // Credit commissary product ledger:
                // - Saleable goods (manual_adjustment, other, null) → produced_qty (can re-dispatch)
                // - Unsaleable/damaged (spoilage, damage, etc.) → wastage_qty (tracked loss)
                $unsaleableReasons = ['spoilage', 'damage', 'staff_meal', 'sampling', 'testing', 'promo', 'donation'];
                $isSaleableReturn = $reasonCode === null || !in_array($reasonCode, $unsaleableReasons, true);

                foreach ($validLines as $line) {
                    $pid = (int)$line['product_id'];
                    $qty = (int)$line['quantity'];
                    if ($isSaleableReturn) {
                        dl_applyCommissaryProductLedgerDelta(
                            $ctx->db(), $targetBranchId, $pid, $date,
                            $qty, 0, $actorId, 0  // produced_qty += qty
                        );
                    } else {
                        dl_applyCommissaryProductLedgerDelta(
                            $ctx->db(), $targetBranchId, $pid, $date,
                            0, 0, $actorId, $qty  // wastage_qty += qty
                        );
                    }
                }

                dl_auditLog('create_delivery', $branchId, 'dl_deliveries', (string)($returnDeliveryId ?? 0), null, [
                    'dr_number' => $returnDr ?? '[self-managed-no-delivery]',
                    'status' => $returnDeliveryId ? 'posted' : 'ledger-only',
                    'source' => 'cashier_pullout_return',
                    'destination_commissary_id' => $targetBranchId,
                    'saleable' => $isSaleableReturn,
                    'items' => count($validLines),
                ]);
            }
        }

        $ctx->db()->commit();
        $response = ['ok' => true, 'totals' => $totals];
        if ($returnDeliveryId !== null) {
            $response['delivery_id'] = $returnDeliveryId;
            $response['receiving_id'] = $returnReceivingId;
        }
        if ($idempotencyKey !== '') {
            dl_storeIdempotentResponse('cashier_withdrawal', $idempotencyKey, $response, 86400);
        }
        $ctx->json($response);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->log('apiSaveCashierWithdrawals error: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error']);
    }
}

function apiCreateCashierDispatch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser();
    if (!dl_isFormalDeliveryEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Formal Delivery Workflow is disabled for branch deliveries.'], 403);
        return;
    }

    $input = (array)json_decode(file_get_contents('php://input'), true);
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $originBranchId = $authResult['branch_id'];
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $deliveryDate = (string)($input['delivery_date'] ?? dl_businessDate());
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    $destType = (string)($input['destination_type'] ?? 'branch');
    $destId = (int)($input['destination_id'] ?? $input['target_branch_id'] ?? 0);
    $items = dl_normalizeDeliveryItems((array)($input['items'] ?? []));
    $role = (string)($user['role'] ?? '');
    $actorId = dl_getActorUserId($user);

    if ($originBranchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Missing source branch.'], 422);
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid delivery date.'], 422);
        return;
    }
    if ($drNumber === '') {
        $ctx->json(['ok' => false, 'error' => 'Paper DR number is required.'], 422);
        return;
    }
    if ($destType !== 'branch') {
        $ctx->json(['ok' => false, 'error' => 'Invalid destination type.'], 422);
        return;
    }
    if ($destId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'A destination is required.'], 422);
        return;
    }
    if ($destType === 'branch' && $destId === $originBranchId) {
        $ctx->json(['ok' => false, 'error' => 'A different destination branch is required.'], 422);
        return;
    }
    if ($items === []) {
        $ctx->json(['ok' => false, 'error' => 'At least one item is required.'], 422);
        return;
    }

    $dayStatus = dl_getDayStatus($originBranchId, $deliveryDate);
    if ($role === 'cashier' && $deliveryDate !== dl_businessDate()) {
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }
    if ($dayStatus === 'closed' && !dl_roleHasPermission($role, 'ledger.override')) {
        $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
        return;
    }

    $dupStmt = $ctx->db()->prepare(
        'SELECT id, status
           FROM dl_deliveries
          WHERE origin_type = :origin_type
            AND origin_id = :origin_id
            AND destination_type = :destination_type
            AND destination_id = :destination_id
            AND dr_number = :dr_number
            AND status <> "voided"
          ORDER BY id DESC
          LIMIT 1'
    );
    $dupStmt->execute([
        ':origin_type' => 'branch',
        ':origin_id' => $originBranchId,
        ':destination_type' => $destType,
        ':destination_id' => $destId,
        ':dr_number' => $drNumber,
    ]);
    $dup = $dupStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($dup) {
        $ctx->json(['ok' => false, 'error' => 'This paper DR already exists in the system. Use Receive Stock on the destination branch.'], 422);
        return;
    }

    $priceGroupId = dl_defaultPriceGroupId();

    $ctx->db()->beginTransaction();
    try {
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, status, created_by, posted_by, posted_at, remarks)
             VALUES (:ot, :oid, :dt, :did, :dr, :dd, "posted", :created_by, :posted_by, NOW(), :remarks)'
        );
        $ins->execute([
            ':ot' => 'branch',
            ':oid' => $originBranchId,
            ':dt' => $destType,
            ':did' => $destId,
            ':dr' => $drNumber,
            ':dd' => $deliveryDate,
            ':created_by' => $actorId ?: null,
            ':posted_by' => $actorId ?: null,
            ':remarks' => dl_cashierDispatchRemark(),
        ]);
        $deliveryId = (int)$ctx->db()->lastInsertId();

        $itemStmt = $ctx->db()->prepare(
            'INSERT INTO dl_delivery_items
                (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
             VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
        );
        foreach ($items as $item) {
            $itemStmt->execute([
                ':delivery_id' => $deliveryId,
                ':product_id' => $item['product_id'],
                ':quantity' => $item['quantity'],
                ':unit' => $item['unit'],
                ':unit_cost_snapshot' => $item['unit_cost_snapshot'],
                ':price_snapshot' => dl_resolveProductPrice((int)$item['product_id'], $priceGroupId, $deliveryDate),
                ':price_group_id' => $priceGroupId,
                ':remarks' => $item['remarks'],
            ]);
            dl_applyLedgerDelta($originBranchId, (int)$item['product_id'], $deliveryDate, (int)$item['quantity'], $actorId, 'withdraw', $shift);
        }

        $ctx->db()->commit();
        dl_auditLog('create_delivery', $originBranchId, 'dl_deliveries', (string)$deliveryId, null, [
            'destination_type' => $destType,
            'destination_id' => $destId,
            'items' => count($items),
            'dr_number' => $drNumber,
            'status' => 'posted',
            'source' => 'cashier_dispatch',
        ]);
        $ctx->json(['ok' => true, 'delivery_id' => $deliveryId]);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function apiGetIncomingDeliveries(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();
    $authResult = dl_authorizeBranch($user, $_GET);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    if (!$branchId) { $ctx->json(['ok' => true, 'deliveries' => []]); return; }

    $drFilter = isset($_GET['dr_number']) ? trim((string)$_GET['dr_number']) : '';

    $sql = 'SELECT cw.id, cw.dr_number, cw.ledger_date, cw.quantity, cw.branch_id AS origin_branch_id,
                   ob.name AS origin_branch_name, cw.product_id, p.name AS product_name
            FROM dl_cashier_withdrawals cw
            INNER JOIN dl_branches ob ON ob.id = cw.branch_id
            INNER JOIN dl_products p ON p.id = cw.product_id
            WHERE cw.target_branch_id = :bid
              AND cw.withdrawal_type = \'delivery\'
              AND cw.received_at IS NULL'
        . ($drFilter !== '' ? ' AND cw.dr_number = :dr_filter' : '')
        . ' ORDER BY cw.ledger_date DESC, cw.dr_number, cw.id';
    $stmt = $ctx->db()->prepare($sql);
    $bind = [':bid' => $branchId];
    if ($drFilter !== '') $bind[':dr_filter'] = $drFilter;
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Group by DR# (or by origin branch + date if DR# is null)
    $groups = [];
    foreach ($rows as $r) {
        $key = ($r['dr_number'] !== null && $r['dr_number'] !== '')
            ? 'dr:' . $r['dr_number'] . ':' . $r['origin_branch_id']
            : 'orig:' . $r['origin_branch_id'] . ':' . $r['ledger_date'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'group_key' => $key,
                'dr_number' => $r['dr_number'],
                'origin_branch_id' => (int)$r['origin_branch_id'],
                'origin_branch_name' => $r['origin_branch_name'],
                'ledger_date' => $r['ledger_date'],
                'items' => [],
                'ids' => [],
                'delivery_ids' => [],
            ];
        }
        $groups[$key]['items'][] = [
            'id' => (int)$r['id'],
            'product_id' => (int)$r['product_id'],
            'product_name' => $r['product_name'],
            'quantity' => (int)$r['quantity'],
        ];
        $groups[$key]['ids'][] = (int)$r['id'];
    }

    if (dl_isFormalDeliveryEnabled()) {
        $formalSql = 'SELECT d.id AS delivery_id, d.dr_number, d.delivery_date,
                             d.origin_id AS origin_branch_id,
                             COALESCE(ob.name, cb.name, d.origin_type) AS origin_branch_name,
                             di.id AS delivery_item_id,
                             di.product_id, p.name AS product_name, di.quantity
                      FROM dl_deliveries d
                      INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                      INNER JOIN dl_products p ON p.id = di.product_id
                      LEFT JOIN dl_branches ob ON ob.id = d.origin_id AND d.origin_type = "branch"
                      LEFT JOIN dl_branches cb ON cb.id = d.origin_id AND d.origin_type = "commissary"
                      WHERE d.destination_type = "branch"
                        AND d.destination_id = :bid
                        AND d.status = "posted"
                        AND NOT EXISTS (
                            SELECT 1 FROM dl_branch_receivings br
                            WHERE br.delivery_id = d.id AND br.status <> "voided"
                        )'
            . ($drFilter !== '' ? ' AND d.dr_number = :dr_filter' : '')
            . ' ORDER BY d.delivery_date DESC, d.dr_number, d.id';
        $formalBind = [':bid' => $branchId];
        if ($drFilter !== '') $formalBind[':dr_filter'] = $drFilter;
        $formalStmt = $ctx->db()->prepare($formalSql);
        $formalStmt->execute($formalBind);
        foreach ($formalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = 'delivery:' . (int)$row['delivery_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'group_key' => $key,
                    'dr_number' => $row['dr_number'],
                    'origin_branch_id' => $row['origin_branch_id'] !== null ? (int)$row['origin_branch_id'] : 0,
                    'origin_branch_name' => $row['origin_branch_name'],
                    'ledger_date' => $row['delivery_date'],
                    'items' => [],
                    'ids' => [],
                    'delivery_ids' => [(int)$row['delivery_id']],
                ];
            }
            $groups[$key]['items'][] = [
                'id' => (int)$row['delivery_item_id'],
                'product_id' => (int)$row['product_id'],
                'product_name' => $row['product_name'],
                'quantity' => (int)$row['quantity'],
            ];
        }
    }

    $deliveries = array_values($groups);
    usort($deliveries, static function (array $left, array $right): int {
        $dateCmp = strcmp((string)($right['ledger_date'] ?? ''), (string)($left['ledger_date'] ?? ''));
        if ($dateCmp !== 0) {
            return $dateCmp;
        }
        return strcmp((string)($left['group_key'] ?? ''), (string)($right['group_key'] ?? ''));
    });

    $ctx->json(['ok' => true, 'deliveries' => $deliveries]);
}

function apiReceiveDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $ids = array_values(array_filter(array_map('intval', (array)($input['withdrawal_ids'] ?? []))));
    $deliveryIds = array_values(array_filter(array_map('intval', (array)($input['delivery_ids'] ?? []))));

    if (!$branchId || (count($ids) === 0 && count($deliveryIds) === 0)) {
        $ctx->json(['ok' => false, 'error' => 'Missing fields']);
        return;
    }

    $userId = dl_getActorUserId($user);
    $receiveDate = dl_businessDate();

    if (count($deliveryIds) > 0) {
        // Optional per-product partial quantities: { delivery_id => { product_id => qty } }
        $partialQtysMap = (array)($input['partial_qtys'] ?? []);
        $ctx->db()->beginTransaction();
        try {
            $receivedCount = 0;
            foreach ($deliveryIds as $deliveryId) {
                $partialQtys = isset($partialQtysMap[$deliveryId])
                    ? array_map('intval', (array)$partialQtysMap[$deliveryId])
                    : null;
                $rcvId = dl_acceptFormalDelivery($ctx->db(), $branchId, $deliveryId, $userId, $receiveDate, $partialQtys, $shift);
                if ($rcvId > 0) {
                    $receivedCount++;
                }
            }
            $ctx->db()->commit();
            $ctx->json(['ok' => true, 'received_count' => $receivedCount, 'receive_date' => $receiveDate]);
            return;
        } catch (\Throwable $e) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
            return;
        }
    }

    // Optional per-item received qty for informal transfers: { withdrawal_id => received_qty }
    $informalPartialQtys = (array)($input['informal_partial_qtys'] ?? []);

    // Make sure all ids are deliveries targeting this branch and not yet received.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $check = $ctx->db()->prepare(
        "SELECT id, product_id, quantity FROM dl_cashier_withdrawals
         WHERE id IN ($placeholders) AND target_branch_id = ? AND withdrawal_type = 'delivery' AND received_at IS NULL"
    );
    $check->execute(array_merge($ids, [$branchId]));
    $rows = $check->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (count($rows) === 0) {
        $ctx->json(['ok' => false, 'error' => 'Nothing to receive']);
        return;
    }

    // Resolve received qty per row (partial or full)
    $rowReceivedQtys = [];
    foreach ($rows as $r) {
        $rid = (int)$r['id'];
        $sentQty = (int)$r['quantity'];
        $rQty = isset($informalPartialQtys[(string)$rid])
            ? max(0, min((int)$informalPartialQtys[(string)$rid], $sentQty))
            : $sentQty;
        $rowReceivedQtys[$rid] = $rQty;
    }

    $ctx->db()->beginTransaction();
    try {
        // Sum received pcs per product (using actual received qty, not sent qty)
        $perProduct = [];
        foreach ($rows as $r) {
            $pid = (int)$r['product_id'];
            $perProduct[$pid] = ($perProduct[$pid] ?? 0) + $rowReceivedQtys[(int)$r['id']];
        }

        // Mark each row received, storing the actual received_qty
        $markIndiv = $ctx->db()->prepare(
            "UPDATE dl_cashier_withdrawals
             SET received_at = NOW(), received_by = ?, received_ledger_date = ?, received_qty = ?
             WHERE id = ?"
        );
        $foundIds = [];
        foreach ($rows as $r) {
            $rid = (int)$r['id'];
            $markIndiv->execute([$userId, $receiveDate, $rowReceivedQtys[$rid], $rid]);
            $foundIds[] = $rid;
        }

        // Apply to dl_daily_ledger.addtl for receive date (shift-scoped: each
        // shift receives into its own row).
        $stmtCheck = $ctx->db()->prepare(
            'SELECT id, addtl FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift FOR UPDATE'
        );
        $stmtUpd = $ctx->db()->prepare(
            'UPDATE dl_daily_ledger SET addtl = :addtl, updated_by = :uid
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
        );
        $stmtInit = $ctx->db()->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, addtl, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :prc, :addtl, :uid_enc, :uid_upd)'
        );

        foreach ($perProduct as $pid => $qty) {
            $stmtCheck->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $receiveDate, ':shift' => $shift]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $newAddtl = (int)$existing['addtl'] + (int)$qty;
                $stmtUpd->execute([':addtl' => $newAddtl, ':uid' => $userId, ':bid' => $branchId, ':pid' => $pid, ':d' => $receiveDate, ':shift' => $shift]);
            } else {
                $price = dl_resolveBranchProductPrice($branchId, (int)$pid, $receiveDate);
                $stmtInit->execute([
                    ':bid' => $branchId, ':pid' => $pid, ':d' => $receiveDate, ':shift' => $shift,
                    ':prc' => $price, ':addtl' => (int)$qty,
                    ':uid_enc' => $userId, ':uid_upd' => $userId,
                ]);
            }
        }

        $ctx->db()->commit();
        $ctx->json(['ok' => true, 'received_count' => count($foundIds), 'receive_date' => $receiveDate]);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->log('apiReceiveDelivery error: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error']);
    }
}

function apiReceivePaperDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();

    $input = (array)json_decode(file_get_contents('php://input'), true);
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        $cached = dl_loadIdempotentResponse('receive_paper_dr', $idempotencyKey);
        if ($cached !== null) {
            $ctx->json($cached);
            return;
        }
    }
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $destinationBranchId = $authResult['branch_id'];
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $originType = (string)($input['origin_type'] ?? 'commissary');
    $originId = isset($input['origin_id']) && $input['origin_id'] !== '' ? (int)$input['origin_id'] : null;
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    $deliveryDate = (string)($input['delivery_date'] ?? dl_businessDate());
    $receiveDate = (string)($input['receive_date'] ?? dl_businessDate());
    $items = dl_normalizeDeliveryItems((array)($input['items'] ?? []));
    $actorId = dl_getActorUserId($user);
    $role = (string)($user['role'] ?? '');
    $isAdminUser = $role === 'admin' || dl_isKernelAdmin($user);

    if ($destinationBranchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Missing destination branch.'], 422);
        return;
    }
    if (!in_array($originType, ['branch', 'commissary'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid origin type.'], 422);
        return;
    }
    if ($originType === 'branch' && (($originId ?? 0) <= 0 || $originId === $destinationBranchId)) {
        $ctx->json(['ok' => false, 'error' => 'A different source branch is required.'], 422);
        return;
    }
    if ($drNumber === '') {
        $ctx->json(['ok' => false, 'error' => 'Paper DR number is required.'], 422);
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiveDate)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid date.'], 422);
        return;
    }
    if ($items === []) {
        $ctx->json(['ok' => false, 'error' => 'At least one item is required.'], 422);
        return;
    }

    $businessDate = dl_businessDate();
    if ($role === 'cashier' && $receiveDate !== $businessDate) {
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }
    if ($originType === 'branch' && !$isAdminUser && $deliveryDate !== $businessDate) {
        $ctx->json(['ok' => false, 'error' => 'Admin required for late branch paper DR capture'], 403);
        return;
    }

    $receiveDayStatus = dl_getDayStatus($destinationBranchId, $receiveDate);
    if ($receiveDayStatus === 'closed' && !dl_roleHasPermission($role, 'ledger.override')) {
        $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
        return;
    }
    if ($originType === 'branch' && $originId !== null) {
        $originDayStatus = dl_getDayStatus((int)$originId, $deliveryDate);
        if ($originDayStatus === 'closed' && !$isAdminUser) {
            $ctx->json(['ok' => false, 'error' => 'Admin required for closed source-branch paper DR capture'], 403);
            return;
        }
    }

    $findStmt = $ctx->db()->prepare(
        'SELECT id, status
           FROM dl_deliveries
          WHERE destination_type = :destination_type
            AND destination_id = :destination_id
            AND dr_number = :dr_number
            AND status <> "voided"
          ORDER BY id DESC
          LIMIT 1'
    );
    $findStmt->execute([
        ':destination_type' => 'branch',
        ':destination_id' => $destinationBranchId,
        ':dr_number' => $drNumber,
    ]);
    $existing = $findStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $ctx->db()->beginTransaction();
    try {
        if ($existing && dl_deliveryHasActiveReceivings($ctx->db(), (int)$existing['id'])) {
            throw new \RuntimeException('This paper DR was already received.');
        }

        $deliveryId = $existing ? (int)$existing['id'] : 0;
        if (!$existing) {
            $priceGroupId = dl_defaultPriceGroupId();
            $ins = $ctx->db()->prepare(
                'INSERT INTO dl_deliveries
                    (origin_type, origin_id, destination_type, destination_id, dr_number,
                     delivery_date, status, created_by, posted_by, posted_at, remarks, provenance_status)
                 VALUES (:ot, :oid, :dt, :did, :dr, :dd, "posted", :created_by, :posted_by, NOW(), :remarks, :provenance_status)'
            );
            $ins->execute([
                ':ot' => $originType,
                ':oid' => $originId,
                ':dt' => 'branch',
                ':did' => $destinationBranchId,
                ':dr' => $drNumber,
                ':dd' => $deliveryDate,
                ':created_by' => $actorId ?: null,
                ':posted_by' => $actorId ?: null,
                ':remarks' => dl_paperDrCaptureRemark(),
                ':provenance_status' => 'paper_dr_pending',
            ]);
            $deliveryId = (int)$ctx->db()->lastInsertId();

            $itemStmt = $ctx->db()->prepare(
                'INSERT INTO dl_delivery_items
                    (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
                 VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
            );
            foreach ($items as $item) {
                $itemStmt->execute([
                    ':delivery_id' => $deliveryId,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':unit' => $item['unit'],
                    ':unit_cost_snapshot' => $item['unit_cost_snapshot'],
                    ':price_snapshot' => dl_resolveProductPrice((int)$item['product_id'], $priceGroupId, $deliveryDate),
                    ':price_group_id' => $priceGroupId,
                    ':remarks' => $item['remarks'],
                ]);
                if ($originType === 'branch' && $originId !== null) {
                    dl_applyLedgerDelta((int)$originId, (int)$item['product_id'], $deliveryDate, (int)$item['quantity'], $actorId, 'withdraw', $shift);
                }
            }

            dl_auditLog('create_delivery', $originType === 'branch' ? (int)$originId : null, 'dl_deliveries', (string)$deliveryId, null, [
                'destination_type' => 'branch',
                'destination_id' => $destinationBranchId,
                'items' => count($items),
                'dr_number' => $drNumber,
                'status' => 'posted',
                'source' => 'captured_from_paper_dr',
            ]);
        } elseif ((string)$existing['status'] === 'draft') {
            $ctx->db()->prepare(
                'UPDATE dl_deliveries SET status = "posted", posted_by = :u, posted_at = NOW() WHERE id = :id'
            )->execute([':u' => $userId ?: null, ':id' => $deliveryId]);
        }

        $receivingId = dl_acceptFormalDelivery($ctx->db(), $destinationBranchId, $deliveryId, $actorId, $receiveDate, null, $shift);
        $ctx->db()->commit();
        $response = ['ok' => true, 'delivery_id' => $deliveryId, 'receiving_id' => $receivingId];
        if ($idempotencyKey !== '') {
            dl_storeIdempotentResponse('receive_paper_dr', $idempotencyKey, $response, 86400);
        }
        $ctx->json($response);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function apiSaveLedgerField(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    header('Content-Type: application/json');

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $input     = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId  = $authResult['branch_id'];
    $productId = (int)($input['product_id'] ?? 0);
    $field     = (string)($input['field'] ?? '');
    $value     = (int)($input['value'] ?? 0);
    $date      = (string)($input['date'] ?? dl_businessDate());
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift     = $shiftResolved['shift'];
    $userId    = dl_getActorUserId($user);
    if ($userId <= 0) {
        write_log('daily-ledger save auth required', 'error', [
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'user' => [
                'id' => $user['id'] ?? null,
                'sub' => $user['sub'] ?? null,
                'role' => $user['role'] ?? null,
                'source' => $user['source'] ?? null,
                'username' => $user['username'] ?? null,
            ],
            'auth_header_present' => (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])),
            'cookie_present' => (is_string(kernelCookie(dlCookieName())) && kernelCookie(dlCookieName()) !== ''),
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required'], 401);
        return;
    }

    $role = (string)($user['role'] ?? '');

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, $userId);
    }

    // Validate field name and value — sales is derived, never client-writable
    $fieldMap = [
        'beg_bal' => 'beg_bal',
        'addtl' => 'addtl',
        'withdraw' => 'withdraw',
        'bal_end' => 'bal_end',
    ];
    $column = dl_allowedColumn($field, $fieldMap);
    if ($column === null || !$branchId || !$productId) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }
    if ($value < 0 || $value > 999999999) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Value out of bounds', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Value out of bounds'], 422);
        return;
    }

    // Production-lock guard: cashier cannot overwrite addtl/withdraw set by a production movement
    if ($role === 'cashier' && in_array($field, ['addtl', 'withdraw'], true)) {
        $movementType = $field === 'addtl' ? 'output' : 'withdrawal';
        $lockStmt = $ctx->db()->prepare(
            'SELECT COUNT(*) FROM dl_production_movements pm
             WHERE pm.destination_branch_id = :bid
               AND pm.product_id = :pid
               AND pm.ledger_date = :d
               AND pm.movement_type = :mtype
               AND NOT EXISTS (
                   SELECT 1 FROM dl_production_movements r
                   WHERE r.reference_movement_id = pm.id AND r.movement_type = :rev
               )'
        );
        $lockStmt->execute([
            ':bid'   => $branchId,
            ':pid'   => $productId,
            ':d'     => $date,
            ':mtype' => $movementType,
            ':rev'   => 'reverse',
        ]);
        if ((int)$lockStmt->fetchColumn() > 0) {
            write_log('daily-ledger cashier override blocked', 'warning', [
                'branch_id'       => $branchId,
                'product_id'      => $productId,
                'date'            => $date,
                'field'           => $field,
                'attempted_value' => $value,
                'user_sub'        => (string)($user['sub'] ?? ''),
            ]);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Set by production — cannot override', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Set by production — cannot override'], 403);
            return;
        }
    }

    if ($role === 'cashier' && $date !== dl_businessDate()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Reference only', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }

    try {
        $ctx->db()->beginTransaction();
        $dayStatus = dl_lockDayStatusRow($ctx->db(), $branchId, $date);
        if ($dayStatus === 'closed' && $role === 'cashier') {
            throw new RuntimeException('Day is closed');
        }

        $currentPrice = dl_resolveBranchProductPrice($branchId, $productId, $date);
        $oldStmt = $ctx->db()->prepare(
            "SELECT {$column} AS current_value FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift LIMIT 1 FOR UPDATE"
        );
        $oldStmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
        $oldVal = $oldStmt->fetchColumn();

        $stmt = $ctx->db()->prepare(
            "INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, {$column}, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :price, :val, :uid, :uid2)
             ON DUPLICATE KEY UPDATE {$column} = :val2, updated_by = :uid3, updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            ':bid'   => $branchId,
            ':pid'   => $productId,
            ':d'     => $date,
            ':shift' => $shift,
            ':price' => $currentPrice,
            ':val'   => $value,
            ':uid'   => $userId,
            ':uid2'  => $userId,
            ':val2'  => $value,
            ':uid3'  => $userId,
        ]);

        // Silent variance computation when beg_bal changes
        if ($field === 'beg_bal') {
            dl_computeVarianceSilently($branchId, $productId, $date, $value);
        }

        // Auto-recompute sales = beg_bal + addtl - withdraw - bal_end (server-side)
        if ($field !== 'sales') {
            dl_recomputeSales($branchId, $productId, $date, $userId, $shift);
        }

        // Audit log (silent)
        dl_auditLog(
            'field_update',
            $branchId,
            'dl_daily_ledger',
            "{$branchId}-{$productId}-{$date}-{$shift}",
            [$field => $oldVal !== false ? (int)$oldVal : null],
            [$field => $value]
        );

        $ctx->db()->commit();

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Saved', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'field' => $field, 'value' => $value]);
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        if ($e instanceof RuntimeException && $e->getMessage() === 'Day is closed') {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day is closed', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
            return;
        }
        $ctx->log('apiSaveLedgerField failed: ' . $e->getMessage(), 'error', [
            'branch_id'  => $branchId,
            'product_id' => $productId,
            'field'      => $field,
            'date'       => $date,
            'user_id'    => $userId,
            'role'       => $role,
            'sub'        => (string)($user['sub'] ?? ''),
            'input'      => $input,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Save failed', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Save failed'], 500);
    }
}

function apiSaveLedgerBatch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $input = $ctx->input();
    $date = (string)($input['date'] ?? dl_businessDate());
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $rows = $input['rows'] ?? null;
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));

    if ($idempotencyKey !== '') {
        $cachedResponse = dl_loadIdempotentResponse('ledger_batch', $idempotencyKey);
        if (is_array($cachedResponse)) {
            $ctx->json($cachedResponse);
            return;
        }
    }

    $userId = dl_getActorUserId($user);
    if ($userId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required'], 401);
        return;
    }

    $role = (string)($user['role'] ?? '');
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, $userId);
    }

    if (!$branchId || !is_array($rows) || count($rows) === 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    $isReadOnly = ($role === 'cashier' && $date !== dl_businessDate());
    if ($isReadOnly) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Reference only', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }

    // Validate payload and normalize
    $normalized = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $productId = (int)($r['product_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $beg = (int)($r['beg_bal'] ?? 0);
        $add = (int)($r['addtl'] ?? 0);
        $with = (int)($r['withdraw'] ?? 0);
        $end = (int)($r['bal_end'] ?? 0);

        if ($beg < 0 || $add < 0 || $with < 0 || $end < 0 || $beg > 999999999 || $add > 999999999 || $with > 999999999 || $end > 999999999) {
            $ctx->json(['ok' => false, 'error' => 'Values are out of bounds'], 422);
            return;
        }

        $normalized[] = [
            'product_id' => $productId,
            'beg_bal' => $beg,
            'addtl' => $add,
            'withdraw' => $with,
            'bal_end' => $end,
        ];
    }

    if (count($normalized) === 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid rows', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid rows'], 422);
        return;
    }

    try {
        $dayStatus = dl_getDayStatus($branchId, $date);
        if (!$isReadOnly) {
            // For cashier: identify production-locked columns per product before entering the transaction
            $productionLocks = [];
            if ($role === 'cashier') {
                $lockStmt = $ctx->db()->prepare(
                    'SELECT pm.product_id, pm.movement_type
                     FROM dl_production_movements pm
                     WHERE pm.destination_branch_id = :bid
                       AND pm.ledger_date = :d
                       AND pm.movement_type IN (\'output\', \'withdrawal\')
                       AND NOT EXISTS (
                           SELECT 1 FROM dl_production_movements r
                           WHERE r.reference_movement_id = pm.id AND r.movement_type = \'reverse\'
                       )
                     GROUP BY pm.product_id, pm.movement_type'
                );
                $lockStmt->execute([':bid' => $branchId, ':d' => $date]);
                foreach ($lockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lockRow) {
                    $lpid = (int)$lockRow['product_id'];
                    $col  = $lockRow['movement_type'] === 'output' ? 'addtl' : 'withdraw';
                    $productionLocks[$lpid][$col] = true;
                }
            }

            $ctx->db()->beginTransaction();
            $dayStatus = dl_lockDayStatusRow($ctx->db(), $branchId, $date);
            if ($role === 'cashier' && $dayStatus === 'closed') {
                throw new RuntimeException('Day is closed');
            }

        $selectOld = $ctx->db()->prepare(
            'SELECT beg_bal, addtl, withdraw, bal_end FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift FOR UPDATE'
        );

        $upsert = $ctx->db()->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :price, :beg, :addtl, :withdraw, :end, :uid, :uid2)
             ON DUPLICATE KEY UPDATE
                beg_bal = VALUES(beg_bal),
                addtl = VALUES(addtl),
                withdraw = VALUES(withdraw),
                bal_end = VALUES(bal_end),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($normalized as $r) {
            $pid = (int)$r['product_id'];

            $selectOld->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
            $old = $selectOld->fetch(PDO::FETCH_ASSOC) ?: null;

            $currentPrice = dl_resolveBranchProductPrice($branchId, $pid, $date);

            // Preserve production-set values: cashier cannot override addtl/withdraw locked by a movement
            $addtlVal    = (int)$r['addtl'];
            $withdrawVal = (int)$r['withdraw'];
            if ($role === 'cashier') {
                $locks = $productionLocks[$pid] ?? [];
                if (!empty($locks['addtl'])) {
                    $preserved = (int)($old['addtl'] ?? 0);
                    if ($addtlVal !== $preserved) {
                        write_log('daily-ledger cashier batch addtl override blocked', 'warning', [
                            'branch_id'       => $branchId,
                            'product_id'      => $pid,
                            'date'            => $date,
                            'attempted_value' => $addtlVal,
                            'preserved_value' => $preserved,
                            'user_sub'        => (string)($user['sub'] ?? ''),
                        ]);
                    }
                    $addtlVal = $preserved;
                }
                if (!empty($locks['withdraw'])) {
                    $preserved = (int)($old['withdraw'] ?? 0);
                    if ($withdrawVal !== $preserved) {
                        write_log('daily-ledger cashier batch withdraw override blocked', 'warning', [
                            'branch_id'       => $branchId,
                            'product_id'      => $pid,
                            'date'            => $date,
                            'attempted_value' => $withdrawVal,
                            'preserved_value' => $preserved,
                            'user_sub'        => (string)($user['sub'] ?? ''),
                        ]);
                    }
                    $withdrawVal = $preserved;
                }
            }

            $upsert->execute([
                ':bid'      => $branchId,
                ':pid'      => $pid,
                ':d'        => $date,
                ':shift'    => $shift,
                ':price'    => $currentPrice,
                ':beg'      => (int)$r['beg_bal'],
                ':addtl'    => $addtlVal,
                ':withdraw' => $withdrawVal,
                ':end'      => (int)$r['bal_end'],
                ':uid'      => $userId,
                ':uid2'     => $userId,
            ]);

            // Beg-bal changes trigger variance check
            if ($old && array_key_exists('beg_bal', $old) && (int)$old['beg_bal'] !== (int)$r['beg_bal']) {
                dl_computeVarianceSilently($branchId, $pid, $date, (int)$r['beg_bal']);
            }

            // Always recompute sales from invariant
            dl_recomputeSales($branchId, $pid, $date, $userId, $shift);

            // Audit as a single event per product row
            dl_auditLog(
                'row_update',
                $branchId,
                'dl_daily_ledger',
                "{$branchId}-{$pid}-{$date}-{$shift}",
                $old,
                [
                    'beg_bal'  => (int)$r['beg_bal'],
                    'addtl'    => $addtlVal,
                    'withdraw' => $withdrawVal,
                    'bal_end'  => (int)$r['bal_end'],
                ]
            );
        }

        $ctx->db()->commit();
        } // end if (!$isReadOnly)

        // Return updated rows as fresh read
        $stmt = $ctx->db()->prepare(
            'SELECT p.id AS product_id, p.name, p.current_price, p.sort_order,
                    COALESCE(dl.beg_bal, 0) AS beg_bal, COALESCE(dl.addtl, 0) AS addtl,
                    COALESCE(dl.withdraw, 0) AS withdraw, COALESCE(dl.bal_end, 0) AS bal_end,
                    GREATEST(0, COALESCE(dl.beg_bal,0) + COALESCE(dl.addtl,0) - COALESCE(dl.withdraw,0) - COALESCE(dl.bal_end,0)) AS sales,
                    COALESCE(am.bal_end, 0) AS am_bal_end
             FROM dl_products p
             INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
             LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d AND dl.shift = :shift
             LEFT JOIN dl_daily_ledger am ON am.product_id = p.id AND am.branch_id = :bidam AND am.ledger_date = :dam AND am.shift = \'AM\'
             WHERE p.is_active = 1
             ORDER BY p.sort_order, p.name'
        );
        $stmt->execute([':bid' => $branchId, ':bid2' => $branchId, ':d' => $date, ':shift' => $shift, ':bidam' => $branchId, ':dam' => $date]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Saved', 'type' => 'success']]));
        $response = [
            'ok' => true,
            'branch_id' => $branchId,
            'date' => $date,
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'day_status' => $dayStatus,
        ];
        dl_storeIdempotentResponse('ledger_batch', $idempotencyKey, $response);
        $ctx->json($response);
    } catch (\Throwable $e) {
        try {
            if ($ctx->db()->inTransaction()) {
                $ctx->db()->rollBack();
            }
        } catch (\Throwable $ignored) {
        }

        if ($e instanceof RuntimeException && $e->getMessage() === 'Day is closed') {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day is closed', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
            return;
        }

        $ctx->log('apiSaveLedgerBatch failed: ' . $e->getMessage(), 'error', [
            'branch_id' => $branchId,
            'date' => $date,
            'user_id' => $userId,
            'role' => $role,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Save failed', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Save failed'], 500);
    }
}

function apiProductionDestinations(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $allowedBranchIds = dl_accessibleBranchIds($user);
    if (count($allowedBranchIds) === 0) {
        $ctx->json(['ok' => true, 'destinations' => []]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));
    $stmt = $ctx->db()->prepare(
        "SELECT id, code, name
         FROM dl_branches
         WHERE is_active = 1 AND id IN ({$placeholders})
         ORDER BY name"
    );
    $stmt->execute($allowedBranchIds);

    $ctx->json(['ok' => true, 'destinations' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function apiProductionProducts(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();
    $category = trim((string)($input['category'] ?? ''));

    $sql = 'SELECT id, sku, name, current_price, product_category, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label
            FROM dl_products
            WHERE is_active = 1';
    $bind = [];
    if ($category !== '' && in_array($category, ['bread', 'cake', 'other'], true)) {
        $sql .= ' AND product_category = :category';
        $bind[':category'] = $category;
    }
    $sql .= ' ORDER BY sort_order, name';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);

    $ctx->json([
        'ok' => true,
        'products' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ]);
}

function apiCommissaryMaterials(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);

    $stmt = $ctx->db()->query(
        'SELECT id, name, unit_of_measure, category, sort_order
         FROM dl_raw_materials
         WHERE is_active = 1
         ORDER BY sort_order, name'
    );

    $ctx->json([
        'ok' => true,
        'materials' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ]);
}

function apiProductionMovements(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();
    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : date('Y-m-d', strtotime($today . ' -7 days'));
    $dateTo = !empty($input['date_to']) ? (string)$input['date_to'] : $today;
    $movementType = trim((string)($input['movement_type'] ?? ''));

    $allowedBranchIds = dl_accessibleBranchIds($user);
    dl_maybeAutoCloseBranches($allowedBranchIds, dl_getActorUserId($user));
    if (count($allowedBranchIds) === 0) {
        $ctx->json(['ok' => true, 'rows' => []]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));
    $sql =
        "SELECT pm.id, pm.movement_uuid, pm.client_op_id, pm.movement_type, pm.flow_mode,
                pm.destination_branch_id, b.code AS destination_code, b.name AS destination_name,
                pm.product_id, p.name AS product_name, p.sku,
            pm.ledger_date, pm.quantity, pm.dr_number, pm.override_reason,
                pm.reference_movement_id, pm.created_by_id, pm.created_by_role, pm.created_at
         FROM dl_production_movements pm
         INNER JOIN dl_branches b ON b.id = pm.destination_branch_id
         INNER JOIN dl_products p ON p.id = pm.product_id
         WHERE pm.destination_branch_id IN ({$placeholders})
           AND pm.ledger_date BETWEEN ? AND ?";
    $bind = $allowedBranchIds;
    $bind[] = $dateFrom;
    $bind[] = $dateTo;

    if ($movementType !== '' && in_array($movementType, ['withdrawal', 'output', 'reverse'], true)) {
        $sql .= ' AND pm.movement_type = ?';
        $bind[] = $movementType;
    }
    $sql .= ' ORDER BY pm.created_at DESC LIMIT 500';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);

    $ctx->json(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function apiProductionWithdrawal(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();

    try {
        $result = dl_processProductionMovement($user, 'withdrawal', $input);
        $ctx->json(['ok' => true, 'result' => $result]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

function apiProductionOutput(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    if (!dl_isFeatureEnabled('production_output_enabled')) {
        $ctx->json(['ok' => false, 'error' => 'Production output feature is disabled. Ask Kernel Admin to enable it.'], 403);
        return;
    }
    $input = $ctx->input();

    try {
        $result = dl_processProductionMovement($user, 'output', $input);
        $ctx->json(['ok' => true, 'result' => $result]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

function apiProductionReverse(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $role = (string)($user['role'] ?? '');
    if (!dl_roleHasPermission($role, 'production.override')) {
        $ctx->json(['ok' => false, 'error' => 'Forbidden'], 403);
        return;
    }
    $input = $ctx->input();

    try {
        $result = dl_processProductionMovement($user, 'reverse', $input);
        $ctx->json(['ok' => true, 'result' => $result]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

function apiProductionSyncBatch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $outputEnabled = dl_isFeatureEnabled('production_output_enabled');
    $input = $ctx->input();
    $operations = $input['operations'] ?? [];
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        $cachedResponse = dl_loadIdempotentResponse('production_sync_batch', $idempotencyKey);
        if (is_array($cachedResponse)) {
            $ctx->json($cachedResponse);
            return;
        }
    }
    if (!is_array($operations) || count($operations) === 0) {
        $ctx->json(['ok' => false, 'error' => 'operations[] is required'], 422);
        return;
    }

    $results = [];
    foreach ($operations as $idx => $op) {
        if (!is_array($op)) {
            $results[] = ['index' => $idx, 'ok' => false, 'error' => 'Invalid operation payload'];
            continue;
        }
        $type = (string)($op['type'] ?? '');
        if (!in_array($type, ['withdrawal', 'output', 'reverse'], true)) {
            $results[] = ['index' => $idx, 'ok' => false, 'error' => 'Invalid type'];
            continue;
        }

        if ($type === 'output' && !$outputEnabled) {
            $results[] = ['index' => $idx, 'ok' => false, 'error' => 'Production output feature is disabled. Ask Kernel Admin to enable it.'];
            continue;
        }

        try {
            $results[] = ['index' => $idx, 'ok' => true, 'result' => dl_processProductionMovement($user, $type, $op)];
        } catch (\Throwable $e) {
            $results[] = ['index' => $idx, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    $okCount = 0;
    foreach ($results as $r) {
        if (!empty($r['ok'])) {
            $okCount++;
        }
    }

    $response = [
        'ok' => true,
        'summary' => [
            'total' => count($results),
            'succeeded' => $okCount,
            'failed' => count($results) - $okCount,
        ],
        'results' => $results,
    ];
    dl_storeIdempotentResponse('production_sync_batch', $idempotencyKey, $response);
    $ctx->json($response);
}

function apiCloseDay(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $date     = (string)($input['date'] ?? dl_businessDate());
    $userId = dl_getActorUserId($user);
    if ($userId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required'], 401);
        return;
    }

    if (!$branchId) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'No branch', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'No branch'], 422);
        return;
    }

    if ((string)($user['role'] ?? '') === 'cashier' && $date !== dl_businessDate()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Reference only', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }

    try {
        // POS days have extra close requirements (open carts, variance ack).
        $posBlock = dl_pos_dayClosePrecheck($ctx->db(), $branchId, $date, $input);
        if (is_array($posBlock)) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => (string)$posBlock['error'], 'type' => 'error']]));
            $ctx->json($posBlock, 422);
            return;
        }

        $stmt = $ctx->db()->prepare(
            'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
             VALUES (:bid, :d, \'closed\', :uid, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE status = \'closed\', closed_by = :uid2, closed_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':bid' => $branchId, ':d' => $date, ':uid' => $userId, ':uid2' => $userId]);

        dl_pos_markModeClosed($ctx->db(), $branchId, $date, $userId);

        dl_auditLog('close_day', $branchId, 'dl_ledger_day_status', "{$branchId}-{$date}", null, ['status' => 'closed']);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day closed', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'day_status' => 'closed']);
    } catch (\Throwable $e) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to close day', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to close day'], 500);
    }
}

function apiReopenDay(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor']);
    $role = (string)($user['role'] ?? '');
    if (!dl_roleHasPermission($role, 'ledger.override')) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Permission denied', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Forbidden'], 403);
        return;
    }

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $date     = (string)($input['date'] ?? '');
    $userId = dl_getActorUserId($user);
    if ($userId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required'], 401);
        return;
    }

    if (!$branchId || !$date) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Missing branch or date', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Missing branch_id or date'], 422);
        return;
    }

    try {
        $stmt = $ctx->db()->prepare(
            'UPDATE dl_ledger_day_status SET status = \'open\', reopened_by = :uid, reopened_at = CURRENT_TIMESTAMP
             WHERE branch_id = :bid AND ledger_date = :d'
        );
        $stmt->execute([':uid' => $userId, ':bid' => $branchId, ':d' => $date]);

        dl_auditLog('reopen_day', $branchId, 'dl_ledger_day_status', "{$branchId}-{$date}", ['status' => 'closed'], ['status' => 'open']);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day reopened', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'day_status' => 'open']);
    } catch (\Throwable $e) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to reopen', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to reopen'], 500);
    }
}

// ─── Admin Page Handlers ───────────────────────────────────────────────

function handleAdminDashboard(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'auditor']);
    $role = (string)($user['role'] ?? '');
    $input = $ctx->input();

    $today    = dl_businessDate();
    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) {
        $accessibleBranchIds = [0]; // Ensure empty result
    }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];
    dl_maybeAutoCloseBranches(array_column($branches, 'id'), dl_getActorUserId($user));

    $salesFilterDateFrom = $today;
    $salesFilterDateTo = $today;
    if (!empty($input['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['date_from'])) {
        $salesFilterDateFrom = (string)$input['date_from'];
    }
    if (!empty($input['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['date_to'])) {
        $salesFilterDateTo = (string)$input['date_to'];
    }
    if ($salesFilterDateFrom > $salesFilterDateTo) {
        [$salesFilterDateFrom, $salesFilterDateTo] = [$salesFilterDateTo, $salesFilterDateFrom];
    }

    $salesFilterBranchId = isset($input['branch_id']) ? (int)$input['branch_id'] : 0;
    if ($salesFilterBranchId > 0 && !in_array($salesFilterBranchId, $accessibleBranchIds, true) && $role !== 'admin') {
        $salesFilterBranchId = 0;
    }
    $salesFilterPeriodLabel = $salesFilterDateFrom === $salesFilterDateTo
        ? $salesFilterDateFrom
        : $salesFilterDateFrom . ' to ' . $salesFilterDateTo;

    $salesScopeBranches = $branches;
    if ($salesFilterBranchId > 0) {
        $salesScopeBranches = array_values(array_filter($branches, static function (array $branch) use ($salesFilterBranchId): bool {
            return (int)($branch['id'] ?? 0) === $salesFilterBranchId;
        }));
    }

    // Today's sales per branch — computed: sales = beg_bal + addtl - withdraw - bal_end
    $salesStmt = $ctx->db()->prepare(
        'SELECT dl.branch_id, b.name AS branch_name,
                SUM(GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end)) AS total_units,
                SUM(GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end) * dl.price_snapshot) AS total_amount,
                COUNT(DISTINCT dl.product_id) AS product_count
         FROM dl_daily_ledger dl
         INNER JOIN dl_branches b ON b.id = dl.branch_id
         WHERE dl.ledger_date = ? AND dl.branch_id IN (' . $branchPlaceholders . ')
         GROUP BY dl.branch_id
         ORDER BY b.name'
    );
    $salesStmt->execute(array_merge([$today], $accessibleBranchIds));
    $todaySales = $salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $filteredSalesSql =
        'SELECT dl.branch_id, b.name AS branch_name,
                SUM(GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end)) AS total_units,
                SUM(GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end) * dl.price_snapshot) AS total_amount,
                COUNT(DISTINCT dl.product_id) AS product_count
         FROM dl_daily_ledger dl
         INNER JOIN dl_branches b ON b.id = dl.branch_id
         WHERE dl.ledger_date BETWEEN ? AND ? AND dl.branch_id IN (' . $branchPlaceholders . ')';
    $filteredSalesBind = array_merge([$salesFilterDateFrom, $salesFilterDateTo], $accessibleBranchIds);
    if ($salesFilterBranchId > 0) {
        $filteredSalesSql .= ' AND dl.branch_id = ?';
        $filteredSalesBind[] = $salesFilterBranchId;
    }
    $filteredSalesSql .= ' GROUP BY dl.branch_id ORDER BY b.name';
    $filteredSalesStmt = $ctx->db()->prepare($filteredSalesSql);
    $filteredSalesStmt->execute($filteredSalesBind);
    $filteredSalesRows = $filteredSalesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Day status per branch — filtered to accessible branches
    $statusStmt = $ctx->db()->prepare(
        "SELECT branch_id, status FROM dl_ledger_day_status WHERE ledger_date = ? AND branch_id IN ({$branchPlaceholders})"
    );
    $statusStmt->execute(array_merge([$salesFilterDateTo], $accessibleBranchIds));
    $dayStatuses = [];
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
        $dayStatuses[(int)$s['branch_id']] = $s['status'];
    }

    // Unreviewed variance count
    $varStmt = $ctx->db()->query('SELECT COUNT(*) FROM dl_variance_flags WHERE is_reviewed = 0');
    $unreviewedVariances = (int)$varStmt->fetchColumn();

    // Recent encoder activity (last 20) — human-readable + branch-scoped for non-admins.
    $hasActorModuleUserId = dlAuditLogHasColumn('actor_module_user_id');
    $hasActorSource = dlAuditLogHasColumn('actor_source');
    $activitySql = 'SELECT a.action, a.created_at, a.branch_id,
                           b.name AS branch_name,
                           ' . ($hasActorSource ? 'a.actor_source' : 'NULL') . ' AS actor_source,
                           a.actor_user_id,
                           ' . ($hasActorModuleUserId ? 'a.actor_module_user_id' : 'NULL') . ' AS actor_module_user_id,
                           ku.full_name AS kernel_actor_name,
                           ' . ($hasActorModuleUserId ? 'du.full_name' : 'NULL') . ' AS module_actor_name
                    FROM audit_logs a
                    LEFT JOIN dl_branches b ON b.id = a.branch_id
                    LEFT JOIN users ku ON ku.id = a.actor_user_id
                    ' . ($hasActorModuleUserId ? 'LEFT JOIN dl_users du ON du.id = a.actor_module_user_id' : 'LEFT JOIN dl_users du ON 1 = 0') . '
                    WHERE a.module = \'daily-ledger\'';
    $activityBind = [];
    if ($role !== 'admin') {
        $activityBranchPlaceholders = [];
        foreach (array_values($accessibleBranchIds) as $index => $accessibleBranchId) {
            $placeholder = ':dash_branch_' . $index;
            $activityBranchPlaceholders[] = $placeholder;
            $activityBind[$placeholder] = (int)$accessibleBranchId;
        }
        $activitySql .= ' AND (a.branch_id IS NULL OR a.branch_id IN (' . implode(',', $activityBranchPlaceholders) . '))';
    }
    $activitySql .= ' ORDER BY a.created_at DESC LIMIT 20';
    $activityStmt = $ctx->db()->prepare($activitySql);
    $activityStmt->execute($activityBind);
    $recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dashboardActionLabels = [
        'field_update' => 'Updated ledger field',
        'row_update' => 'Updated ledger row',
        'close_day' => 'Closed the day',
        'reopen_day' => 'Reopened the day',
        'create_product' => 'Added product',
        'update_product' => 'Updated product',
        'create_user' => 'Created user',
        'update_user' => 'Updated user',
        'delete_user' => 'Deleted user',
        'restore_user' => 'Restored user',
        'production_output' => 'Recorded production output',
        'production_withdrawal' => 'Recorded production withdrawal',
        'create_delivery' => 'Created delivery',
        'delivery_posted' => 'Posted delivery',
        'delivery_voided' => 'Voided delivery',
        'create_receiving' => 'Created receiving',
        'receiving_posted' => 'Posted receiving',
        'receiving_voided' => 'Voided receiving',
        'review_delivery_provenance' => 'Reviewed paper DR',
        'variance_status' => 'Updated variance status',
        'create_commissary_run' => 'Created commissary run',
        'update_commissary_run' => 'Updated commissary run',
        'delete_commissary_run' => 'Deleted commissary run',
        'save_commissary_material' => 'Saved material count',
    ];
    foreach ($recentActivity as &$activityRow) {
        $actorName = trim((string)($activityRow['module_actor_name'] ?? ''));
        if ($actorName === '') {
            $actorName = trim((string)($activityRow['kernel_actor_name'] ?? ''));
        }
        if ($actorName === '') {
            $source = strtolower(trim((string)($activityRow['actor_source'] ?? '')));
            if ($source === 'daily-ledger') {
                $actorName = 'Daily Ledger';
            } elseif ($source === 'kernel') {
                $actorName = 'Kernel User';
            } else {
                $actorName = 'System';
            }
        }
        $action = (string)($activityRow['action'] ?? '');
        $activityRow['actor_name'] = $actorName;
        $activityRow['activity_label'] = $dashboardActionLabels[$action] ?? ucwords(str_replace('_', ' ', $action));
    }
    unset($activityRow);

    // Join branches + sales + day-statuses into card data.
    // Pass raw numeric values — let DiSyL handle formatting (currency, number_format).
    $salesByBranch = [];
    foreach ($filteredSalesRows as $ts) {
        $salesByBranch[(int)$ts['branch_id']] = $ts;
    }

    $scopeUnits = 0;
    $scopeAmount = 0.0;
    $branchCards = [];
    foreach ($salesScopeBranches as $br) {
        $bid = (int)$br['id'];
        $ts = $salesByBranch[$bid] ?? null;
        $units  = $ts ? (int)$ts['total_units'] : 0;
        $amount = $ts ? (float)$ts['total_amount'] : 0.0;
        $status = $dayStatuses[$bid] ?? 'none';
        $scopeUnits  += $units;
        $scopeAmount += $amount;
        $branchCards[] = [
            'branch_id' => $bid,
            'name'   => $br['name'],
            'units'  => $units,
            'amount' => $amount,
            'status' => $status,
        ];
    }

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');

    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/admin/dashboard.disyl', [
        'page_title'            => 'Dashboard',
        'user_name'             => $userName,
        'user_role'             => $role,
        'current_page'          => 'dashboard',
        'base_url' => dlGetBaseUrl(),
        'dl_token'              => (string)kernelCookie(dlCookieName(), ''),
        'today'                 => $today,
        'branches'              => $branches,
        'branch_cards'          => $branchCards,
        'sales_filter_date_from' => $salesFilterDateFrom,
        'sales_filter_date_to'   => $salesFilterDateTo,
        'sales_filter_branch_id' => $salesFilterBranchId,
        'sales_filter_period_label' => $salesFilterPeriodLabel,
        'branch_sales_units'    => $scopeUnits,
        'branch_sales_amount'   => $scopeAmount,
        'unreviewed_variances'  => $unreviewedVariances,
        'recent_activity'       => $recentActivity,
        'total_units_today'     => array_reduce($todaySales, static fn(int $carry, array $row): int => $carry + (int)($row['total_units'] ?? 0), 0),
        'total_amount_today'    => array_reduce($todaySales, static fn(float $carry, array $row): float => $carry + (float)($row['total_amount'] ?? 0), 0.0),
        'business_date_label'   => $clockLabel['business_date'],
        'close_of_day_time'     => $clockLabel['close_of_day_time'],
        'auto_close_enabled'    => $clockLabel['auto_close_enabled'],
        'operating_timezone'    => $clockLabel['operating_timezone'],
        'operating_region'      => $clockLabel['operating_region'],
    ]);
}

function handleAdminOverview(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    // Read-only business overview: sales data + top saleable products.
    // Accessible to admins, supervisors, auditors, and viewers (business owners).
    $user = dlCurrentUser(['admin', 'supervisor', 'auditor', 'viewer']);
    $role = (string)($user['role'] ?? '');
    $input = $ctx->input();

    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['date_from'])
        ? (string)$input['date_from'] : $today;
    $dateTo = !empty($input['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['date_to'])
        ? (string)$input['date_to'] : $today;
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : 0;

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) {
        $accessibleBranchIds = [0];
    }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Top saleable products (ranked by units sold, then amount) for the period.
    $topProductsSql =
        'SELECT p.id, p.name, p.sku, p.product_category,
                SUM(' . dl_ledgerSalesQuantitySql('dl') . ') AS units,
                SUM(' . dl_ledgerSalesAmountSql('dl') . ') AS amount,
                COUNT(DISTINCT dl.branch_id) AS branch_count
         FROM dl_daily_ledger dl
         INNER JOIN dl_products p ON p.id = dl.product_id
         WHERE dl.ledger_date BETWEEN ? AND ? AND dl.branch_id IN (' . $branchPlaceholders . ')';
    $topProductsBind = array_merge([$dateFrom, $dateTo], $accessibleBranchIds);
    if ($branchId > 0) {
        $topProductsSql .= ' AND dl.branch_id = ?';
        $topProductsBind[] = $branchId;
    }
    $topProductsSql .= ' GROUP BY p.id, p.name, p.sku, p.product_category
         ORDER BY units DESC, amount DESC
         LIMIT 10';
    $topStmt = $ctx->db()->prepare($topProductsSql);
    $topStmt->execute($topProductsBind);
    $topProducts = $topStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Sales per branch for the period.
    $salesSql =
        'SELECT dl.branch_id, b.name AS branch_name,
                SUM(' . dl_ledgerSalesQuantitySql('dl') . ') AS total_units,
                SUM(' . dl_ledgerSalesAmountSql('dl') . ') AS total_amount,
                COUNT(DISTINCT dl.product_id) AS product_count
         FROM dl_daily_ledger dl
         INNER JOIN dl_branches b ON b.id = dl.branch_id
         WHERE dl.ledger_date BETWEEN ? AND ? AND dl.branch_id IN (' . $branchPlaceholders . ')';
    $salesBind = array_merge([$dateFrom, $dateTo], $accessibleBranchIds);
    if ($branchId > 0) {
        $salesSql .= ' AND dl.branch_id = ?';
        $salesBind[] = $branchId;
    }
    $salesSql .= ' GROUP BY dl.branch_id ORDER BY b.name';
    $salesStmt = $ctx->db()->prepare($salesSql);
    $salesStmt->execute($salesBind);
    $branchSales = $salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Day status per branch for the end date.
    $statusStmt = $ctx->db()->prepare("SELECT branch_id, status FROM dl_ledger_day_status WHERE ledger_date = ? AND branch_id IN ({$branchPlaceholders})");
    $statusStmt->execute(array_merge([$dateTo], $accessibleBranchIds));
    $dayStatuses = [];
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
        $dayStatuses[(int)$s['branch_id']] = $s['status'];
    }

    $branchSalesMap = [];
    foreach ($branchSales as $row) {
        $branchSalesMap[(int)$row['branch_id']] = $row;
    }

    $grandUnits = 0;
    $grandAmount = 0.0;
    $cards = [];
    foreach ($branches as $br) {
        $bid = (int)$br['id'];
        $s = $branchSalesMap[$bid] ?? null;
        $units = $s ? (int)$s['total_units'] : 0;
        $amount = $s ? (float)$s['total_amount'] : 0.0;
        $grandUnits += $units;
        $grandAmount += $amount;
        $cards[] = [
            'branch_id' => $bid,
            'name' => $br['name'],
            'units' => $units,
            'amount' => $amount,
            'status' => $dayStatuses[$bid] ?? 'none',
        ];
    }

    $periodLabel = $dateFrom === $dateTo ? $dateFrom : $dateFrom . ' to ' . $dateTo;
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/admin/overview.disyl', [
        'page_title' => 'Business Overview',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'overview',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'branch_id' => $branchId,
        'branches' => $branches,
        'branch_cards' => $cards,
        'top_products' => $topProducts,
        'grand_units' => $grandUnits,
        'grand_amount' => $grandAmount,
        'period_label' => $periodLabel,
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
    ]);
}

function handleAdminSales(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlRequireAuth(['admin', 'supervisor', 'auditor']);

    $input = $ctx->input();
    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : $today;
    $dateTo   = !empty($input['date_to']) ? (string)$input['date_to'] : $today;
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $actionFilter = trim((string)($input['action_filter'] ?? ''));
    $search   = trim((string)($input['q'] ?? ''));
    $shiftFilter = strtoupper(trim((string)($input['shift'] ?? '')));
    if (!in_array($shiftFilter, ['AM', 'PM'], true)) {
        $shiftFilter = '';
    }

    $actionFilterMap = [
        'output' => ['production_output'],
        'withdrawal' => ['production_withdrawal'],
        'delivery' => [
            'create_delivery',
            'delivery_created',
            'update_delivery',
            'delivery_posted',
            'delivery_voided',
            'create_receiving',
            'receiving_created',
            'receiving_posted',
            'receiving_voided',
            'review_delivery_provenance',
        ],
        'product' => ['create_product', 'update_product'],
        'user' => ['create_user', 'update_user', 'delete_user', 'restore_user'],
        'commissary' => ['create_commissary_run', 'update_commissary_run', 'delete_commissary_run', 'save_commissary_material'],
        'ledger' => ['field_update', 'row_update', 'close_day', 'reopen_day'],
        'variance' => ['variance_status'],
    ];
    if ($actionFilter !== '' && !isset($actionFilterMap[$actionFilter])) {
        $actionFilter = '';
    }
    $actionFilterOptions = [
        ['value' => '', 'label' => 'All Activities'],
        ['value' => 'output', 'label' => 'Output'],
        ['value' => 'withdrawal', 'label' => 'Withdrawal'],
        ['value' => 'delivery', 'label' => 'Delivery'],
        ['value' => 'ledger', 'label' => 'Ledger'],
        ['value' => 'commissary', 'label' => 'Commissary'],
        ['value' => 'product', 'label' => 'Product'],
        ['value' => 'user', 'label' => 'User'],
        ['value' => 'variance', 'label' => 'Variance'],
    ];

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Sales data with computed sales and amount (admin can see these)
    $salesExpr = dl_ledgerSalesQuantitySql('dl');
    $amountExpr = dl_ledgerSalesAmountSql('dl');
    $sql = 'SELECT dl.ledger_date, dl.shift, p.name AS product_name, p.sku, b.name AS branch_name,
                   dl.beg_bal, dl.addtl, dl.withdraw, dl.bal_end,
                   ' . $salesExpr . ' AS sales,
                   dl.price_snapshot,
                   (' . $amountExpr . ') AS amount
             FROM dl_daily_ledger dl
             INNER JOIN dl_products p ON p.id = dl.product_id
             INNER JOIN dl_branches b ON b.id = dl.branch_id
            WHERE dl.branch_id IN (' . $branchPlaceholders . ') AND dl.ledger_date BETWEEN ? AND ?';
    $bind = array_merge($accessibleBranchIds, [$dateFrom, $dateTo]);

    if ($branchId) {
        $sql .= ' AND dl.branch_id = ?';
        $bind[] = $branchId;
    }
    if ($search !== '') {
        $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR b.name LIKE ?)';
        $like = "%{$search}%";
        $bind[] = $like;
        $bind[] = $like;
        $bind[] = $like;
    }
    if ($shiftFilter !== '') {
        $sql .= ' AND dl.shift = ?';
        $bind[] = $shiftFilter;
    }
    $sql .= ' ORDER BY dl.ledger_date DESC, b.name, p.name';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $salesRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Grand totals
    $grandUnits  = 0;
    $grandAmount = 0.0;
    foreach ($salesRows as $r) {
        $grandUnits  += (int)$r['sales'];
        $grandAmount += (float)$r['amount'];
    }

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $stmtAll = $ctx->db()->prepare("SELECT id, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $stmtAll->execute($accessibleBranchIds);
    $allBranches = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $clockLabel = dl_operatingClockLabel();

    // POS reconciliation: per-branch sales mode + POS-vs-calculated summary
    // for the filtered range. Never additive — labels the official source.
    $posEnabled = dl_isPosEnabled();
    $posBranchModes = [];
    $posReconciliation = null;
    if ($posEnabled && $dateFrom === $dateTo) {
        foreach ($branches as $b) {
            $bid = (int)($b['id'] ?? 0);
            if ($bid > 0) {
                $posMode = dl_pos_dayMode($ctx->db(), $bid, $dateFrom);
                $posBranchModes[$bid] = $posMode['mode'];
            }
        }
        if ($branchId > 0) {
            $posReconciliation = dl_pos_salesSummary($ctx->db(), $branchId, $dateFrom);
        }
    }

    // The table below always shows stock-derived rows; when a single branch-day
    // reconciliation exists, label the OFFICIAL source (which may be POS/fallback).
    $salesSourceLabel = 'Stock-derived (manual ledger)';
    if (is_array($posReconciliation)) {
        $salesSourceLabel = match ($posReconciliation['sales_source'] ?? '') {
            'pos' => 'POS (completed sales, net of refunds)',
            'fallback' => 'POS before checkpoint + stock-derived after',
            default => 'Stock-derived (manual ledger)',
        };
    }

    echo dlRender('modules/daily-ledger/admin/sales.disyl', [
        'page_title'   => 'Sales Summary',
        'user_name'    => $userName,
        'user_role'    => $role,
        'current_page' => 'sales',
        'base_url' => dlGetBaseUrl(),
        'dl_token'     => (string)kernelCookie(dlCookieName(), ''),
        'date_from'    => $dateFrom,
        'date_to'      => $dateTo,
        'branch_id'    => $branchId,
        'branches'     => $branches,
        'sales_rows'   => $salesRows,
        'grand_units'  => $grandUnits,
        'grand_amount' => $grandAmount,
        'search'       => $search,
        'filter_shift' => $shiftFilter,
        'pos_enabled'  => $posEnabled,
        'pos_branch_modes' => $posBranchModes,
        'pos_reconciliation' => $posReconciliation,
        'sales_source_label' => $salesSourceLabel,
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
        'all_branches' => $allBranches,
    ]);
}

function handleAdminProductionOutput(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();

    $today = dl_businessDate();
    $ledgerDate = !empty($input['ledger_date']) ? (string)$input['ledger_date'] : $today;
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : date('Y-m-d', strtotime($today . ' -7 days'));
    $dateTo = !empty($input['date_to']) ? (string)$input['date_to'] : $today;

    $allowedBranchIds = dl_accessibleBranchIds($user);
    dl_maybeAutoCloseBranches($allowedBranchIds, dl_getActorUserId($user));
    $branches = [];
    $products = [];
    $productRowsBread = [];
    $productRowsCake  = [];
    $movementRows = [];

    if (count($allowedBranchIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));

        $branchStmt = $ctx->db()->prepare(
            "SELECT id, code, name, COALESCE(area, '') AS area
             FROM dl_branches
             WHERE is_active = 1 AND is_commissary = 1 AND id IN ({$placeholders})
             ORDER BY area, name"
        );
        $branchStmt->execute($allowedBranchIds);
        $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $products = dl_fetchActiveProductsForProduction($ctx->db());


        foreach ($products as $p) {
            if (($p['product_category'] ?? '') === 'cake') {
                $productRowsCake[] = $p;
            } else {
                $productRowsBread[] = $p;
            }
        }
                $moveSql =
                        "SELECT CONCAT('movement:', pm.id) AS row_key,
                                        pm.id, pm.destination_branch_id, pm.product_id,
                                        pm.movement_type, pm.flow_mode, pm.ledger_date, pm.quantity,
                                        pm.dr_number,
                                        pm.override_reason, pm.created_at,
                                        b.name AS destination_name, b.code AS destination_code,
                                        COALESCE(b.area, '') AS destination_area,
                                        p.name AS product_name, p.sku,
                                        pm.created_by_role,
                                        EXISTS(
                                                SELECT 1
                                                FROM dl_production_movements r
                                                WHERE r.reference_movement_id = pm.id AND r.movement_type = 'reverse'
                                        ) AS has_reverse,
                                        0 AS is_paper_dr_capture
                         FROM dl_production_movements pm
                         INNER JOIN dl_branches b ON b.id = pm.destination_branch_id
                         INNER JOIN dl_products p ON p.id = pm.product_id
                         WHERE pm.destination_branch_id IN ({$placeholders})
                             AND pm.ledger_date BETWEEN ? AND ?
                             AND (pm.movement_type = 'output'
                                        OR (pm.movement_type = 'reverse' AND pm.reference_movement_id IN (
                                                SELECT id FROM dl_production_movements WHERE movement_type = 'output'
                                        )))

                         UNION ALL

                         SELECT CONCAT('paper-dr:', d.id, ':', di.id) AS row_key,
                                        d.id, d.destination_id AS destination_branch_id, di.product_id,
                                        'paper_dr_capture' AS movement_type,
                                        'commissary' AS flow_mode,
                                        d.delivery_date AS ledger_date,
                                        di.quantity,
                                        d.dr_number,
                                        'Captured from paper DR' AS override_reason,
                                        d.created_at,
                                        b.name AS destination_name, b.code AS destination_code,
                                        COALESCE(b.area, '') AS destination_area,
                                        p.name AS product_name, p.sku,
                                        COALESCE(u.role, 'paper_dr') AS created_by_role,
                                        0 AS has_reverse,
                                        1 AS is_paper_dr_capture
                         FROM dl_deliveries d
                         INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                         INNER JOIN dl_branches b ON b.id = d.destination_id
                         INNER JOIN dl_products p ON p.id = di.product_id
                         LEFT JOIN dl_users u ON u.id = d.created_by
                         WHERE d.origin_type = 'commissary'
                             AND d.destination_type = 'branch'
                             AND d.destination_id IN ({$placeholders})
                             AND d.delivery_date BETWEEN ? AND ?
                             AND d.remarks = ?
                             AND d.status <> 'voided'

                         ORDER BY created_at DESC
                         LIMIT 200";
        $bind = $allowedBranchIds;
        $bind[] = $dateFrom;
        $bind[] = $dateTo;
        foreach ($allowedBranchIds as $branchId) {
            $bind[] = $branchId;
        }
        $bind[] = $dateFrom;
        $bind[] = $dateTo;
        $bind[] = dl_paperDrCaptureRemark();
        $moveStmt = $ctx->db()->prepare($moveSql);
        $moveStmt->execute($bind);
        $movementRows = $moveStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($movementRows as &$movementRow) {
            $typeMeta = dlProductionMovementTypeMeta((string)($movementRow['movement_type'] ?? ''));
            $movementRow['movement_type_label'] = $typeMeta['label'];
            $movementRow['movement_type_badge_classes'] = $typeMeta['badge_classes'];
            $movementRow['flow_mode_label'] = dlProductionFlowModeLabel((string)($movementRow['flow_mode'] ?? ''));
            $movementRow['actor_role_label'] = dlHumanizeToken((string)($movementRow['created_by_role'] ?? ''));
            $movementRow['reason_label'] = trim((string)($movementRow['override_reason'] ?? '')) !== ''
                ? (string)$movementRow['override_reason']
                : 'None';
            $movementRow['branch_label'] = trim((string)($movementRow['destination_name'] ?? ''));
            $code = trim((string)($movementRow['destination_code'] ?? ''));
            if ($movementRow['branch_label'] !== '' && $code !== '') {
                $movementRow['branch_label'] .= ' (' . $code . ')';
            }
            $movementRow['area_label'] = trim((string)($movementRow['destination_area'] ?? '')) !== ''
                ? (string)$movementRow['destination_area']
                : '—';
            $movementRow['dr_number_label'] = trim((string)($movementRow['dr_number'] ?? '')) !== ''
                ? (string)$movementRow['dr_number']
                : '—';
        }
        unset($movementRow);
    }

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $actorId = dl_getActorUserId($user);
    $tenantScope = (string)(app()->tenant()->current() ?? '');
    $featureSettings = dl_featureSettings();
    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $stmtAll = $ctx->db()->prepare("SELECT id, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $stmtAll->execute($accessibleBranchIds);
    $allBranches = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/admin/production-output.disyl', [
        'page_title' => 'Production Output',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'production_output',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'dl_user_id' => $actorId > 0 ? $actorId : '',
        'tenant_scope' => $tenantScope,
        'ledger_date' => $ledgerDate,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'branches' => $branches,
        'products' => $products,
        'product_rows_bread' => $productRowsBread,
        'product_rows_cake'  => $productRowsCake,
        'movement_rows' => $movementRows,
        'can_production_output' => $featureSettings['production_output_enabled'],
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
        'all_branches' => $allBranches,
    ]);
}

function handleAdminSettings(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin']);
    $canManageFeatureActivation = dl_canManageFeatureActivation($user);
    $permissions = dl_rolePermissions();
    $closeOfDaySettings = dl_closeOfDaySettings();
    $featureSettings = dl_featureSettings();
    $backupSettings = dl_backupSettings();
    $resetSafeguardSettings = dl_resetSafeguardSettings();

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/settings.disyl', [
        'page_title' => 'Daily Ledger Settings',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'settings',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'perm_supervisor_ledger_override' => in_array('ledger.override', $permissions['supervisor'] ?? [], true),
        'perm_supervisor_production_override' => in_array('production.override', $permissions['supervisor'] ?? [], true),
        'perm_supervisor_delivery_edit' => in_array('delivery.edit', $permissions['supervisor'] ?? [], true),
        'perm_cashier_delivery_edit' => in_array('delivery.edit', $permissions['cashier'] ?? [], true),
        'perm_prod_ledger_override' => in_array('ledger.override', $permissions['production_in_charge'] ?? [], true),
        'perm_prod_production_override' => in_array('production.override', $permissions['production_in_charge'] ?? [], true),
        'auto_close_enabled' => $closeOfDaySettings['auto_close_enabled'],
        'close_of_day_time' => $closeOfDaySettings['close_of_day_time'],
        'am_shift_cutoff' => dl_amShiftCutoff(),
        'operating_timezone' => $closeOfDaySettings['operating_timezone'],
        'operating_region' => $closeOfDaySettings['operating_region'],
        'operating_timezone_choices' => dl_operatingTimezoneChoices($closeOfDaySettings['operating_timezone']),
        'operating_region_choices' => dl_operatingRegionChoices($closeOfDaySettings['operating_region']),
        'can_manage_feature_activation' => $canManageFeatureActivation,
        'production_output_enabled' => $featureSettings['production_output_enabled'],
        'formal_delivery_workflow_enabled' => $featureSettings['formal_delivery_workflow_enabled'],
        'price_groups_enabled' => $featureSettings['price_groups_enabled'],
        'pos_enabled' => $featureSettings['pos_enabled'],
        'pos_sort_by_sales' => $featureSettings['pos_sort_by_sales'],
        'app_name' => trim((string)(dlModuleSettings()['app_name'] ?? 'Daily Ledger')),
        'logo_url' => dlLogoUrl(),
        'favicon_url' => dlFaviconUrl(),
        'backup_before_reset_enabled' => $backupSettings['backup_before_reset_enabled'],
        'backup_include_users' => $backupSettings['backup_include_users'],
        'backup_retention_days' => $backupSettings['backup_retention_days'],
        'reset_second_phrase_enabled' => $resetSafeguardSettings['reset_second_phrase_enabled'],
        'reset_second_phrase' => $resetSafeguardSettings['reset_second_phrase'],
    ]);
}

function handleAdminBackupDownload(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    dlCurrentUser(['admin']);

    $file = trim((string)($_GET['file'] ?? ''));
    if ($file === '' || !preg_match('/^dl-db-backup-[0-9]{8}-[0-9]{6}\.sql$/', $file)) {
        http_response_code(400);
        echo 'Invalid backup file name.';
        return;
    }

    $dir = dl_backupDirectoryPath();
    $path = $dir . '/' . $file;
    $realDir = realpath($dir);
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false || strpos($realPath, $realDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
        http_response_code(404);
        echo 'Backup file not found.';
        return;
    }

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . (string)filesize($realPath));
    readfile($realPath);
}

function apiUploadBrandingAsset(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    $user = dlCurrentUser(['admin']);

    $assetType = strtolower(trim((string)($_POST['asset_type'] ?? '')));
    $file = kernelUploadedFile('asset_file');
    if (!is_array($file)) {
        $ctx->json(['ok' => false, 'error' => 'Upload a branding image first.'], 422);
        return;
    }

    try {
        $upload = dlUploadBrandAsset($assetType, $file);
    } catch (InvalidArgumentException $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
        return;
    } catch (Throwable $e) {
        write_log('daily-ledger branding upload failed', 'error', [
            'asset_type' => $assetType,
            'message' => $e->getMessage(),
        ]);
        $ctx->json(['ok' => false, 'error' => 'Failed to upload branding asset.'], 500);
        return;
    }

    $settingKey = $assetType === 'favicon' ? 'favicon_url' : 'logo_url';
    if (!dlPersistModuleSettings([$settingKey => $upload['asset_url']])) {
        $ctx->json(['ok' => false, 'error' => 'Branding asset uploaded but could not be persisted to settings.'], 500);
        return;
    }

    dl_auditLog('upload_branding_asset', null, 'module_settings', 'daily-ledger', null, [
        'asset_type' => $assetType,
        'asset_url' => $upload['asset_url'],
        'uploaded_by_role' => (string)($user['role'] ?? ''),
    ]);

    $ctx->json([
        'ok' => true,
        'asset_type' => $assetType,
        'asset_url' => $upload['asset_url'],
        'message' => ucfirst($assetType) . ' uploaded.',
    ]);
}

function apiSaveRolePermissions(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);
    $canManageFeatureActivation = dl_canManageFeatureActivation($user);
    $isKernelAdmin = dl_isKernelAdmin($user);
    $input = $ctx->input();

    $toBool = static function ($v): bool {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    };

    if ($toBool($input['db_backup_generate'] ?? false)) {
        $includeUsers = array_key_exists('backup_include_users', $input)
            ? $toBool($input['backup_include_users'])
            : null;
        try {
            $backupResult = dl_generateDatabaseBackup($user, 'manual_settings_backup', $includeUsers);
        } catch (Throwable $e) {
            write_log('daily-ledger database backup failed', 'error', [
                'message' => $e->getMessage(),
                'actor_role' => (string)($user['role'] ?? ''),
                'actor_source' => (string)($user['source'] ?? ''),
            ]);
            $ctx->json([
                'ok' => false,
                'error' => 'Database backup failed: ' . $e->getMessage(),
            ], 500);
            return;
        }

        header('HX-Trigger: ' . json_encode(['showToast' => [
            'message' => 'Daily Ledger database backup created.',
            'type' => 'success',
        ]]));
        $ctx->json([
            'ok' => true,
            'backup' => $backupResult,
        ]);
        return;
    }

    if ($toBool($input['deployment_reset'] ?? false)) {
        $confirmPhrase = trim((string)($input['deployment_reset_confirm'] ?? ''));
        if ($confirmPhrase !== 'RESET DAILY LEDGER DATA') {
            $ctx->json([
                'ok' => false,
                'error' => 'Type RESET DAILY LEDGER DATA to continue.',
            ], 422);
            return;
        }

        $dryRun = $toBool($input['deployment_reset_dry_run'] ?? false);
        $resetSafeguardSettings = dl_resetSafeguardSettings();
        if (!$dryRun && $resetSafeguardSettings['reset_second_phrase_enabled']) {
            $secondConfirm = trim((string)($input['deployment_reset_second_confirm'] ?? ''));
            if ($secondConfirm !== (string)$resetSafeguardSettings['reset_second_phrase']) {
                $ctx->json([
                    'ok' => false,
                    'error' => 'Type the second safeguard phrase exactly before running full reset.',
                ], 422);
                return;
            }
        }

        try {
            $resetResult = dl_runDeploymentDataReset($user, $dryRun);
        } catch (Throwable $e) {
            write_log('daily-ledger deployment reset failed', 'error', [
                'message' => $e->getMessage(),
                'actor_role' => (string)($user['role'] ?? ''),
                'actor_source' => (string)($user['source'] ?? ''),
                'dry_run' => $dryRun,
            ]);
            $ctx->json([
                'ok' => false,
                'error' => 'Deployment reset failed: ' . $e->getMessage(),
            ], 500);
            return;
        }

        header('HX-Trigger: ' . json_encode(['showToast' => [
            'message' => $dryRun
                ? 'Reset preview ready. Review row counts before execution.'
                : 'Full reset completed. Only the currently logged-in admin account was preserved.',
            'type' => 'success',
        ]]));
        $ctx->json([
            'ok' => true,
            'deployment_reset' => $resetResult,
        ]);
        return;
    }

    if ($toBool($input['sales_data_reset'] ?? false)) {
        $confirmPhrase = trim((string)($input['sales_data_reset_confirm'] ?? ''));
        if ($confirmPhrase !== 'RESET SALES DATA') {
            $ctx->json([
                'ok' => false,
                'error' => 'Type RESET SALES DATA to continue.',
            ], 422);
            return;
        }

        $dryRun = $toBool($input['sales_data_reset_dry_run'] ?? false);

        try {
            $resetResult = dl_runSalesDataReset($user, $dryRun);
        } catch (Throwable $e) {
            write_log('daily-ledger sales data reset failed', 'error', [
                'message' => $e->getMessage(),
                'actor_role' => (string)($user['role'] ?? ''),
                'actor_source' => (string)($user['source'] ?? ''),
                'dry_run' => $dryRun,
            ]);
            $ctx->json([
                'ok' => false,
                'error' => 'Sales data reset failed: ' . $e->getMessage(),
            ], 500);
            return;
        }

        header('HX-Trigger: ' . json_encode(['showToast' => [
            'message' => $dryRun
                ? 'Sales data reset preview ready. Review row counts before execution.'
                : 'Sales data reset completed. Master data (users, branches, products, settings) was preserved.',
            'type' => 'success',
        ]]));
        $ctx->json([
            'ok' => true,
            'sales_data_reset' => $resetResult,
        ]);
        return;
    }

    // Seed each role with its default grants so an unrelated settings save never
    // silently strips permissions. dl_rolePermissions() REPLACES a role's stored
    // permissions (it does not merge), so any grant omitted here (e.g. the POS
    // permissions, which have no settings checkboxes) is lost on save. POS grants
    // mirror dl_defaultRolePermissions(); delivery.edit for supervisor/cashier is
    // still controlled by the checkboxes below.
    $permissions = [
        'admin' => ['ledger.override', 'production.override', 'pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report', 'delivery.edit'],
        'supervisor' => ['pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report'],
        'production_in_charge' => [],
        'cashier' => ['pos.sell'],
        'auditor' => [],
        'viewer' => [],
    ];

    if ($toBool($input['supervisor_ledger_override'] ?? false)) {
        $permissions['supervisor'][] = 'ledger.override';
    }
    if ($toBool($input['supervisor_production_override'] ?? false)) {
        $permissions['supervisor'][] = 'production.override';
    }
    if ($toBool($input['supervisor_delivery_edit'] ?? false)) {
        $permissions['supervisor'][] = 'delivery.edit';
    }
    if ($toBool($input['cashier_delivery_edit'] ?? false)) {
        $permissions['cashier'][] = 'delivery.edit';
    }
    if ($toBool($input['prod_ledger_override'] ?? false)) {
        $permissions['production_in_charge'][] = 'ledger.override';
    }
    if ($toBool($input['prod_production_override'] ?? false)) {
        $permissions['production_in_charge'][] = 'production.override';
    }

    $autoCloseEnabled = $toBool($input['auto_close_enabled'] ?? false);
    $closeOfDayTime = dl_normalizeCloseOfDayTime($input['close_of_day_time'] ?? '00:00');
    // AM→PM shift cutoff: valid HH:MM required; falls back to 14:00 otherwise.
    $amShiftCutoff = dl_normalizeCloseOfDayTime($input['am_shift_cutoff'] ?? '14:00');
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim((string)($input['am_shift_cutoff'] ?? '')))) {
        $amShiftCutoff = '14:00';
    }
    $operatingTimezone = dl_normalizeTimezone($input['operating_timezone'] ?? config('app.timezone', 'Asia/Manila'));
    $operatingRegion = dl_normalizeRegion($input['operating_region'] ?? '');
    $featureSettings = dl_featureSettings();
    $backupSettings = dl_backupSettings();
    $resetSafeguardSettings = dl_resetSafeguardSettings();
    $productionOutputEnabled = $featureSettings['production_output_enabled'];
    $formalDeliveryEnabled = $featureSettings['formal_delivery_workflow_enabled'];
    $priceGroupsEnabled = $featureSettings['price_groups_enabled'];
    $posEnabled = $featureSettings['pos_enabled'];
    $posSortBySales = $featureSettings['pos_sort_by_sales'];
    $backupBeforeResetEnabled = $backupSettings['backup_before_reset_enabled'];
    $backupIncludeUsers = $backupSettings['backup_include_users'];
    $backupRetentionDays = $backupSettings['backup_retention_days'];
    $resetSecondPhraseEnabled = $resetSafeguardSettings['reset_second_phrase_enabled'];

    if (array_key_exists('production_output_enabled', $input)) {
        if (!$canManageFeatureActivation) {
            $ctx->json([
                'ok' => false,
                'error' => 'Only Admin or Superadmin can change feature activation.',
            ], 403);
            return;
        }
        $productionOutputEnabled = $toBool($input['production_output_enabled']);
    }
    foreach ([
        'formal_delivery_workflow_enabled' => &$formalDeliveryEnabled,
        'price_groups_enabled' => &$priceGroupsEnabled,
        'pos_enabled' => &$posEnabled,
        'pos_sort_by_sales' => &$posSortBySales,
    ] as $key => &$ref) {
        if (array_key_exists($key, $input)) {
            if (!$canManageFeatureActivation) {
                $ctx->json([
                    'ok' => false,
                    'error' => 'Only Admin or Superadmin can change feature activation.',
                ], 403);
                return;
            }
            $ref = $toBool($input[$key]);
        }
    }
    unset($ref);

    if (array_key_exists('backup_before_reset_enabled', $input)) {
        $backupBeforeResetEnabled = $toBool($input['backup_before_reset_enabled']);
    }
    if (array_key_exists('backup_include_users', $input)) {
        $backupIncludeUsers = $toBool($input['backup_include_users']);
    }
    if (array_key_exists('backup_retention_days', $input)) {
        $backupRetentionDays = (int)$input['backup_retention_days'];
    }
    if (array_key_exists('reset_second_phrase_enabled', $input)) {
        $resetSecondPhraseEnabled = $toBool($input['reset_second_phrase_enabled']);
    }
    if ($backupRetentionDays < 1 || $backupRetentionDays > 90) {
        $ctx->json([
            'ok' => false,
            'error' => 'Backup retention days must be between 1 and 90.',
        ], 422);
        return;
    }

    if ($autoCloseEnabled && !dl_isAllowedAutoCloseTime($closeOfDayTime)) {
        $ctx->json([
            'ok' => false,
            'error' => 'Auto close cutoff must be a valid time (00:00 - 23:59).',
        ], 422);
        return;
    }

    $appNameInput = trim((string)($input['app_name'] ?? ''));
    $appName = $appNameInput !== '' ? mb_substr($appNameInput, 0, 80) : 'Daily Ledger';
    try {
        $logoUrl = dlNormalizeBrandAssetUrl($input['logo_url'] ?? '', 'Logo URL');
        $faviconUrl = dlNormalizeBrandAssetUrl($input['favicon_url'] ?? '', 'Favicon URL');
    } catch (InvalidArgumentException $e) {
        $ctx->json([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 422);
        return;
    }

    $settingsToSave = [
        'app_name' => $appName,
        'logo_url' => $logoUrl,
        'favicon_url' => $faviconUrl,
        'role_permissions' => $permissions,
        'auto_close_enabled' => $autoCloseEnabled ? '1' : '0',
        'close_of_day_time' => $closeOfDayTime,
        'am_shift_cutoff' => $amShiftCutoff,
        'operating_timezone' => $operatingTimezone,
        'operating_region' => $operatingRegion,
        'production_output_enabled' => $productionOutputEnabled ? '1' : '0',
        'formal_delivery_workflow_enabled' => $formalDeliveryEnabled ? '1' : '0',
        'price_groups_enabled' => $priceGroupsEnabled ? '1' : '0',
        'pos_enabled' => $posEnabled ? '1' : '0',
        'pos_sort_by_sales' => $posSortBySales ? '1' : '0',
        'backup_before_reset_enabled' => $backupBeforeResetEnabled ? '1' : '0',
        'backup_include_users' => $backupIncludeUsers ? '1' : '0',
        'backup_retention_days' => (string)$backupRetentionDays,
        'reset_second_phrase_enabled' => $resetSecondPhraseEnabled ? '1' : '0',
    ];

    if (!dlPersistModuleSettings($settingsToSave)) {
        $ctx->json([
            'ok' => false,
            'error' => 'Failed to persist Daily Ledger settings.',
        ], 500);
        return;
    }

    dl_auditLog('update_role_permissions', null, 'module_settings', 'daily-ledger', null, [
        'role_permissions' => $permissions,
        'auto_close_enabled' => $autoCloseEnabled,
        'close_of_day_time' => $closeOfDayTime,
        'am_shift_cutoff' => $amShiftCutoff,
        'operating_timezone' => $operatingTimezone,
        'operating_region' => $operatingRegion,
        'logo_url' => $logoUrl,
        'favicon_url' => $faviconUrl,
        'production_output_enabled' => $productionOutputEnabled,
        'formal_delivery_workflow_enabled' => $formalDeliveryEnabled,
        'price_groups_enabled' => $priceGroupsEnabled,
        'pos_enabled' => $posEnabled,
        'pos_sort_by_sales' => $posSortBySales,
        'backup_before_reset_enabled' => $backupBeforeResetEnabled,
        'backup_include_users' => $backupIncludeUsers,
        'backup_retention_days' => $backupRetentionDays,
        'reset_second_phrase_enabled' => $resetSecondPhraseEnabled,
        'is_kernel_admin' => $isKernelAdmin,
        'updated_by_role' => (string)($user['role'] ?? ''),
    ]);

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Settings updated', 'type' => 'success']]));
    $ctx->json([
        'ok' => true,
        'app_name' => $appName,
        'logo_url' => $logoUrl,
        'favicon_url' => $faviconUrl,
        'role_permissions' => $permissions,
        'auto_close_enabled' => $autoCloseEnabled,
        'backup_before_reset_enabled' => $backupBeforeResetEnabled,
        'backup_include_users' => $backupIncludeUsers,
        'backup_retention_days' => $backupRetentionDays,
        'reset_second_phrase_enabled' => $resetSecondPhraseEnabled,
        'close_of_day_time' => $closeOfDayTime,
        'am_shift_cutoff' => $amShiftCutoff,
        'operating_timezone' => $operatingTimezone,
        'operating_region' => $operatingRegion,
        'production_output_enabled' => $productionOutputEnabled,
        'formal_delivery_workflow_enabled' => $formalDeliveryEnabled,
        'price_groups_enabled' => $priceGroupsEnabled,
        'pos_enabled' => $posEnabled,
        'pos_sort_by_sales' => $posSortBySales,
    ]);
}

function handleAdminVariances(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'auditor']);
    $role = (string)($user['role'] ?? '');
    $isSupervisor = ($role === 'supervisor');

    $input = $ctx->input();
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $statusFilter = (string)($input['status'] ?? '');
    $dateFilter = (string)($input['date'] ?? dl_businessDate());
    $search   = trim((string)($input['q'] ?? ''));
    $viewMode = $input['view'] ?? ($isSupervisor ? 'grouped' : 'list');

    // For supervisors, restrict to their assigned branches if no explicit filter
    $supervisorBranchIds = [];
    if ($isSupervisor) {
        $userId = dl_getActorUserId($user);
        $sbStmt = $ctx->db()->prepare(
            'SELECT branch_id FROM dl_user_branches WHERE user_id = :uid'
        );
        $sbStmt->execute([':uid' => $userId]);
        $supervisorBranchIds = array_map('intval', $sbStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Build the variance query
    $sql = 'SELECT vf.*, p.name AS product_name, p.sku AS product_sku, b.name AS branch_name, b.code AS branch_code,
                   COALESCE(reviewer.full_name, \'Unknown\') AS reviewer_name
            FROM dl_variance_flags vf
            INNER JOIN dl_products p ON p.id = vf.product_id
            INNER JOIN dl_branches b ON b.id = vf.branch_id
            LEFT JOIN dl_users reviewer ON reviewer.id = vf.reviewed_by
            WHERE 1=1';
    $bind = [];

    if ($branchId) {
        $sql .= ' AND vf.branch_id = :bid';
        $bind[':bid'] = $branchId;
    } elseif ($isSupervisor && !empty($supervisorBranchIds)) {
        // Auto-scope to supervisor branches when no explicit filter
        $placeholders = [];
        foreach ($supervisorBranchIds as $i => $sbId) {
            $key = ":sb_{$i}";
            $placeholders[] = $key;
            $bind[$key] = $sbId;
        }
        $sql .= ' AND vf.branch_id IN (' . implode(',', $placeholders) . ')';
    }

    if ($statusFilter !== '' && in_array($statusFilter, ['unreviewed', 'investigated', 'corrected'], true)) {
        $sql .= ' AND vf.resolution_status = :st';
        $bind[':st'] = $statusFilter;
    }
    if ($search !== '') {
        $sql .= ' AND (p.name LIKE :q OR p.sku LIKE :q2 OR b.name LIKE :q3 OR b.code LIKE :q4)';
        $bind[':q'] = "%{$search}%";
        $bind[':q2'] = "%{$search}%";
        $bind[':q3'] = "%{$search}%";
        $bind[':q4'] = "%{$search}%";
    }

    $sql .= ' ORDER BY vf.ledger_date DESC, b.name, p.name';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $variances = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Aggregate stats ──────────────────────────────────────────────
    $statsTotal = count($variances);
    $statsUnreviewed = 0;
    $statsInvestigated = 0;
    $statsCorrected = 0;
    $statsTotalVariance = 0;
    foreach ($variances as $v) {
        $st = (string)($v['resolution_status'] ?? '');
        if ($st === 'unreviewed') { $statsUnreviewed++; }
        elseif ($st === 'investigated') { $statsInvestigated++; }
        elseif ($st === 'corrected') { $statsCorrected++; }
        $statsTotalVariance += (int)($v['variance'] ?? 0);
    }

    // ── Per-branch breakdown ─────────────────────────────────────────
    $branchSummary = [];
    foreach ($variances as $v) {
        $bid = (int)($v['branch_id'] ?? 0);
        $bname = (string)($v['branch_name'] ?? 'Unknown');
        if (!isset($branchSummary[$bid])) {
            $branchSummary[$bid] = [
                'branch_id'   => $bid,
                'branch_name' => $bname,
                'branch_code'  => (string)($v['branch_code'] ?? ''),
                'total'       => 0,
                'unreviewed'  => 0,
                'investigated'=> 0,
                'corrected'   => 0,
                'net_variance'=> 0,
                'items'       => [],
            ];
        }
        $branchSummary[$bid]['total']++;
        $st = (string)($v['resolution_status'] ?? '');
        if ($st === 'unreviewed') { $branchSummary[$bid]['unreviewed']++; }
        elseif ($st === 'investigated') { $branchSummary[$bid]['investigated']++; }
        elseif ($st === 'corrected') { $branchSummary[$bid]['corrected']++; }
        $branchSummary[$bid]['net_variance'] += (int)($v['variance'] ?? 0);
        $branchSummary[$bid]['items'][] = $v;
    }
    // Sort branches by unreviewed count desc, then name
    uasort($branchSummary, function($a, $b) {
        if ($a['unreviewed'] !== $b['unreviewed']) {
            return $b['unreviewed'] - $a['unreviewed'];
        }
        return strcmp($a['branch_name'], $b['branch_name']);
    });
    $branchSummary = array_values($branchSummary);

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/variances.disyl', [
        'page_title'    => 'Variance Dashboard',
        'user_name'     => $userName,
        'user_role'     => $role,
        'current_page'  => 'variances',
        'base_url'      => dlGetBaseUrl(),
        'dl_token'      => (string)kernelCookie(dlCookieName(), ''),
        'date'          => $dateFilter,
        'branch_id'     => $branchId,
        'status_filter' => $statusFilter,
        'branches'      => $branches,
        'variances'     => $variances,
        'search'        => $search,
        'view_mode'     => $viewMode,
        'is_supervisor' => $isSupervisor,
        'supervisor_branch_ids' => $supervisorBranchIds,
        'can_manage_variances' => in_array($role, ['admin', 'supervisor'], true),
        // Aggregate stats
        'stats_total'        => $statsTotal,
        'stats_unreviewed'   => $statsUnreviewed,
        'stats_investigated' => $statsInvestigated,
        'stats_corrected'    => $statsCorrected,
        'stats_net_variance' => $statsTotalVariance,
        // Branch breakdown
        'branch_summary' => $branchSummary,
    ]);
}

function handleAdminActivity(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'auditor']);

    $input = $ctx->input();
    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : $today;
    $dateTo   = !empty($input['date_to']) ? (string)$input['date_to'] : $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = $today;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = $today;
    }
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $actionFilter = trim((string)($input['action_filter'] ?? ''));
    $search   = trim((string)($input['q'] ?? ''));
    $drNumber = trim((string)($input['dr_number'] ?? ''));

    $actionFilterMap = [
        'output' => ['production_output'],
        'withdrawal' => ['production_withdrawal'],
        'product' => ['create_product', 'update_product'],
        'user' => ['create_user', 'update_user', 'delete_user', 'restore_user'],
        'commissary' => ['create_commissary_run', 'update_commissary_run', 'delete_commissary_run', 'save_commissary_material'],
        'ledger' => ['field_update', 'row_update', 'close_day', 'reopen_day'],
        'variance' => ['variance_status'],
    ];
    if ($actionFilter !== '' && !isset($actionFilterMap[$actionFilter])) {
        $actionFilter = '';
    }
    $actionFilterOptions = [
        ['value' => '', 'label' => 'All Activities'],
        ['value' => 'output', 'label' => 'Output'],
        ['value' => 'withdrawal', 'label' => 'Withdrawal'],
        ['value' => 'ledger', 'label' => 'Ledger'],
        ['value' => 'commissary', 'label' => 'Commissary'],
        ['value' => 'product', 'label' => 'Product'],
        ['value' => 'user', 'label' => 'User'],
        ['value' => 'variance', 'label' => 'Variance'],
    ];

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $productLookup = [];
    foreach ($ctx->db()->query('SELECT id, name FROM dl_products')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $productRow) {
        $productLookup[(int)$productRow['id']] = (string)$productRow['name'];
    }

    $materialLookup = [];
    foreach ($ctx->db()->query('SELECT id, name FROM dl_raw_materials')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $materialRow) {
        $materialLookup[(int)$materialRow['id']] = (string)$materialRow['name'];
    }

    $branchLookup = [];
    foreach ($branches as $branchRow) {
        $branchLookup[(int)$branchRow['id']] = (string)$branchRow['name'];
    }

    // NOTE: Only select stable columns (id, full_name, username). The optional
    // `email` column exists only on newer migrations (dl_users:035, users:020)
    // and is NOT present on older/shared-host databases — selecting it there
    // throws SQLSTATE[42S22]. Labels degrade gracefully without email.
    $moduleUserLookup = [];
    foreach ($ctx->db()->query('SELECT id, full_name, username FROM dl_users')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $moduleUserRow) {
        $moduleUserLookup[(int)$moduleUserRow['id']] = [
            'full_name' => (string)($moduleUserRow['full_name'] ?? ''),
            'username' => (string)($moduleUserRow['username'] ?? ''),
            'email' => '',
        ];
    }

    $kernelUserLookup = [];
    foreach ($ctx->db()->query('SELECT id, full_name, username FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $kernelUserRow) {
        $kernelUserLookup[(int)$kernelUserRow['id']] = [
            'full_name' => (string)($kernelUserRow['full_name'] ?? ''),
            'username' => (string)($kernelUserRow['username'] ?? ''),
            'email' => '',
        ];
    }

    $formatUserLabel = static function (array $userRow, int $userId): string {
        $fullName = trim((string)($userRow['full_name'] ?? ''));
        $username = trim((string)($userRow['username'] ?? ''));
        $email = trim((string)($userRow['email'] ?? ''));
        if ($fullName !== '') {
            return $username !== ''
                ? $fullName . ' (@' . $username . ', User #' . $userId . ')'
                : $fullName . ' (User #' . $userId . ')';
        }
        if ($username !== '') {
            return '@' . $username . ' (User #' . $userId . ')';
        }
        if ($email !== '') {
            return $email . ' (User #' . $userId . ')';
        }
        return 'User #' . $userId;
    };

    $resolveUserById = static function (int $userId, string $preferredSource = 'daily-ledger') use ($moduleUserLookup, $kernelUserLookup, $formatUserLabel): string {
        if ($userId <= 0) {
            return '';
        }

        $source = strtolower(trim($preferredSource));
        if ($source === 'kernel') {
            if (isset($kernelUserLookup[$userId])) {
                return $formatUserLabel($kernelUserLookup[$userId], $userId);
            }
            if (isset($moduleUserLookup[$userId])) {
                return $formatUserLabel($moduleUserLookup[$userId], $userId);
            }
            return 'Kernel User #' . $userId;
        }

        if (isset($moduleUserLookup[$userId])) {
            return $formatUserLabel($moduleUserLookup[$userId], $userId);
        }
        if (isset($kernelUserLookup[$userId])) {
            return $formatUserLabel($kernelUserLookup[$userId], $userId);
        }
        return 'User #' . $userId;
    };

    // Resolve just the actor's username for the Actor column (@handle).
    $resolveActorUsername = static function (int $moduleUserId, int $kernelUserId, string $actorSource) use ($moduleUserLookup, $kernelUserLookup): string {
        $pick = static function (array $lookup, int $id): string {
            if ($id <= 0 || !isset($lookup[$id])) {
                return '';
            }
            return trim((string)($lookup[$id]['username'] ?? ''));
        };

        if ($moduleUserId > 0) {
            $u = $pick($moduleUserLookup, $moduleUserId);
            if ($u !== '') {
                return $u;
            }
        }

        if ($kernelUserId > 0) {
            $u = $pick($kernelUserLookup, $kernelUserId);
            if ($u === '') {
                $u = $pick($moduleUserLookup, $kernelUserId);
            }
            return $u;
        }

        return '';
    };

    $resolveUserFromPayload = static function (array $newPayload, array $oldPayload): string {
        $sourcePayload = $newPayload !== [] ? $newPayload : $oldPayload;
        if ($sourcePayload === []) {
            return '';
        }

        $fullName = trim((string)($sourcePayload['full_name'] ?? $sourcePayload['name'] ?? ''));
        $username = trim((string)($sourcePayload['username'] ?? ''));
        $email = trim((string)($sourcePayload['email'] ?? ''));
        $id = 0;
        if (isset($sourcePayload['id']) && is_numeric($sourcePayload['id'])) {
            $id = (int)$sourcePayload['id'];
        } elseif (isset($sourcePayload['user_id']) && is_numeric($sourcePayload['user_id'])) {
            $id = (int)$sourcePayload['user_id'];
        }

        $suffix = $id > 0 ? ' (User #' . $id . ')' : '';
        if ($fullName !== '') {
            if ($username !== '') {
                return $fullName . ' (@' . $username . ')' . $suffix;
            }
            return $fullName . $suffix;
        }
        if ($username !== '') {
            return '@' . $username . $suffix;
        }
        if ($email !== '') {
            return $email . $suffix;
        }

        return $id > 0 ? 'User #' . $id : '';
    };

    $hasActorModuleUserId = dlAuditLogHasColumn('actor_module_user_id');
    $hasActorSource = dlAuditLogHasColumn('actor_source');

    $sql = 'SELECT a.action, a.created_at, a.old_data, a.new_data,
                   a.entity_type, a.entity_id, '
        . ($hasActorSource ? 'a.actor_source' : 'NULL') . ' AS actor_source,
                   a.actor_user_id, '
        . ($hasActorModuleUserId ? 'a.actor_module_user_id' : 'NULL') . ' AS actor_module_user_id,
                   b.name AS branch_name,
                   ku.full_name AS kernel_actor_name,
                   ' . ($hasActorModuleUserId ? 'du.full_name' : 'NULL') . ' AS module_actor_name
            FROM audit_logs a
            LEFT JOIN dl_branches b ON b.id = a.branch_id
            LEFT JOIN users ku ON ku.id = a.actor_user_id
            ' . ($hasActorModuleUserId ? 'LEFT JOIN dl_users du ON du.id = a.actor_module_user_id' : 'LEFT JOIN dl_users du ON 1 = 0') . '
            WHERE a.module = \'daily-ledger\'
              AND DATE(a.created_at) BETWEEN :df AND :dt';
    $bind = [':df' => $dateFrom, ':dt' => $dateTo];

    if ($branchId) {
        $sql .= ' AND a.branch_id = :bid';
        $bind[':bid'] = $branchId;
    }
    $filterActions = [];
    if ($actionFilter !== '') {
        $filterActions = array_values(array_filter($actionFilterMap[$actionFilter] ?? [], static function ($value): bool {
            return is_string($value) && trim($value) !== '';
        }));
    }
    if ($filterActions !== []) {
        $placeholders = [];
        foreach ($filterActions as $index => $filterAction) {
            $placeholder = ':af' . $index;
            $placeholders[] = $placeholder;
            $bind[$placeholder] = $filterAction;
        }
        $sql .= ' AND a.action IN (' . implode(', ', $placeholders) . ')';
    }
    if ($search !== '') {
        $sql .= ' AND (a.action LIKE :q OR b.name LIKE :q2)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%";
    }
    if ($drNumber !== '') {
        $sql .= ' AND (a.new_data LIKE :drq OR a.old_data LIKE :drq2)';
        $bind[':drq'] = "%{$drNumber}%";
        $bind[':drq2'] = "%{$drNumber}%";
    }

    $sql .= ' ORDER BY a.created_at DESC LIMIT 500';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $activityRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $fieldLabels = [
        'full_name' => 'Full Name',
        'username' => 'Username',
        'product_id' => 'Product',
        'material_id' => 'Material',
        'raw_material_id' => 'Material',
        'destination_branch_id' => 'Destination Branch',
        'branch_id' => 'Branch',
        'dr_number' => 'DR Number',
        'ledger_date' => 'Ledger Date',
        'flow_mode' => 'Flow',
        'review_action' => 'Check Action',
        'reviewed_by_role' => 'Checked By Role',
        'provenance_status' => 'Paper DR Check Status',
        'provenance_review_note' => 'Check Note',
        'yield_qty' => 'Yield',
        'kilo_qty' => 'Kilo',
        'egg_qty' => 'Egg',
        'primary_input_qty' => 'Primary Input',
        'primary_input_type' => 'Primary Input Type',
        'resulting_addtl' => 'Resulting Stock',
        'is_active' => 'Active',
        'sort_order' => 'Sort Order',
        'output_pieces_per_batch' => 'Pieces Per Batch',
        'output_unit_label' => 'Output Unit',
        'batch_input_qty' => 'Batch Kilo Qty',
        'batch_egg_qty' => 'Batch Egg Qty',
    ];
    $skipKeys = ['movement_uuid', 'reference_movement_id', 'client_op_id', 'source_payload', 'role_permissions'];
    $priorityKeys = ['name', 'full_name', 'username', 'product_id', 'material_id', 'raw_material_id', 'destination_branch_id', 'branch_id', 'dr_number', 'ledger_date', 'quantity', 'yield_qty', 'kilo_qty', 'egg_qty', 'flow_mode', 'status', 'role', 'reason', 'resulting_addtl'];

    $formatFieldLabel = static function (string $key) use ($fieldLabels): string {
        return $fieldLabels[$key] ?? ucwords(str_replace('_', ' ', $key));
    };

    $formatRolePermissions = static function ($value): string {
        if (!is_array($value) || $value === []) {
            return 'None';
        }
        $parts = [];
        foreach ($value as $roleName => $caps) {
            $label = ucwords(str_replace('_', ' ', (string)$roleName));
            $capList = is_array($caps) && count($caps) > 0
                ? implode(', ', array_map(static fn($c) => str_replace('.', ': ', (string)$c), $caps))
                : 'no permissions';
            $parts[] = $label . ' — ' . $capList;
        }
        return implode('; ', $parts);
    };

    $formatValue = static function (string $key, $value) use ($productLookup, $materialLookup, $branchLookup): string {
        if (is_array($value)) {
            // Format items arrays with product names
            if ($key === 'items' || (array_keys($value) === range(0, count($value) - 1) && count($value) > 0 && isset($value[0]['product_id']))) {
                $parts = [];
                foreach ($value as $item) {
                    if (!is_array($item)) {
                        $parts[] = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        continue;
                    }
                    $pid = (int)($item['product_id'] ?? 0);
                    $pname = $pid > 0 ? ($productLookup[$pid] ?? 'Product #' . $pid) : '?';
                    $qty = $item['quantity'] ?? $item['qty'] ?? '?';
                    $parts[] = $pname . ' ×' . $qty;
                }
                return implode('; ', $parts);
            }
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        if ($value === null) {
            return 'None';
        }

        if (in_array($key, ['product_id'], true)) {
            $id = (int)$value;
            $name = $productLookup[$id] ?? '';
            return $name !== '' ? $name . ' (#' . $id . ')' : ('#' . $id);
        }
        if (in_array($key, ['material_id', 'raw_material_id'], true)) {
            $id = (int)$value;
            $name = $materialLookup[$id] ?? '';
            return $name !== '' ? $name . ' (#' . $id . ')' : ('#' . $id);
        }
        if (in_array($key, ['destination_branch_id', 'branch_id'], true)) {
            $id = (int)$value;
            $name = $branchLookup[$id] ?? '';
            return $name !== '' ? $name . ' (#' . $id . ')' : ($id > 0 ? ('#' . $id) : 'Commissary');
        }
        if ($key === 'is_active') {
            return (int)$value === 1 ? 'Yes' : 'No';
        }
        if ($key === 'provenance_status') {
            return match (trim((string)$value)) {
                'paper_dr_pending' => 'Needs Check',
                'accepted' => 'Verified',
                'discrepant' => 'Discrepancy',
                default => trim((string)$value) === '' ? 'None' : ucwords(str_replace('_', ' ', trim((string)$value))),
            };
        }
        if ($key === 'review_action') {
            return match (trim((string)$value)) {
                'accepted' => 'Verified',
                'discrepant' => 'Flagged Discrepancy',
                'reopen' => 'Reopened Check',
                default => trim((string)$value) === '' ? 'None' : ucwords(str_replace('_', ' ', trim((string)$value))),
            };
        }
        if (in_array($key, ['role', 'flow_mode', 'status', 'primary_input_type', 'reviewed_by_role'], true)) {
            $text = trim((string)$value);
            return $text === '' ? 'None' : ucwords(str_replace('_', ' ', $text));
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_numeric($value)) {
            $number = (float)$value;
            if ((float)(int)$number === $number) {
                return (string)(int)$number;
            }
            return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
        }

        $text = trim((string)$value);
        return $text === '' ? 'None' : $text;
    };

    $decodeJson = static function ($payload): array {
        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    };

    $formatRelativeTime = static function (string $createdAt): string {
        try {
            $timezone = new \DateTimeZone((string)config('app.timezone', 'UTC'));
            $now = new \DateTimeImmutable('now', $timezone);
            $then = new \DateTimeImmutable($createdAt, $timezone);
            $delta = $now->getTimestamp() - $then->getTimestamp();
            if ($delta < 5) {
                return 'just now';
            }
            if ($delta < 60) {
                return $delta . ' seconds ago';
            }
            $minutes = (int) floor($delta / 60);
            if ($minutes < 60) {
                return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
            }
            $hours = (int) floor($delta / 3600);
            if ($hours < 24) {
                return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
            }
            $days = (int) floor($delta / 86400);
            if ($days < 30) {
                return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    };

    $buildDetailItems = static function (array $payload) use ($priorityKeys, $skipKeys, $formatFieldLabel, $formatValue, $formatRolePermissions): array {
        $items = [];
        $seen = [];

        // Expand role_permissions into per-role detail rows before the generic loop
        if (isset($payload['role_permissions']) && is_array($payload['role_permissions'])) {
            foreach ($payload['role_permissions'] as $roleName => $caps) {
                $label = ucwords(str_replace('_', ' ', (string)$roleName));
                $capList = is_array($caps) && count($caps) > 0
                    ? implode(', ', array_map(static fn($c) => str_replace('.', ': ', (string)$c), $caps))
                    : 'no permissions';
                $items[] = ['label' => $label, 'value' => $capList];
            }
            $seen['role_permissions'] = true;
        }

        foreach ($priorityKeys as $key) {
            if (!array_key_exists($key, $payload) || in_array($key, $skipKeys, true)) {
                continue;
            }
            $formatted = $formatValue($key, $payload[$key]);
            if ($formatted === 'None') {
                continue;
            }
            $items[] = ['label' => $formatFieldLabel($key), 'value' => $formatted];
            $seen[$key] = true;
        }

        foreach ($payload as $key => $value) {
            if (isset($seen[$key]) || in_array($key, $skipKeys, true)) {
                continue;
            }
            $formatted = $formatValue((string)$key, $value);
            if ($formatted === 'None') {
                continue;
            }
            $items[] = ['label' => $formatFieldLabel((string)$key), 'value' => $formatted];
        }

        return array_slice($items, 0, 8);
    };

    $buildChangeItems = static function (array $oldPayload, array $newPayload) use ($priorityKeys, $skipKeys, $formatFieldLabel, $formatValue, $formatRolePermissions): array {
        if ($oldPayload === []) {
            return [];
        }

        $keys = array_values(array_unique(array_merge(array_keys($oldPayload), array_keys($newPayload))));
        usort($keys, static function (string $left, string $right) use ($priorityKeys): int {
            $leftPos = array_search($left, $priorityKeys, true);
            $rightPos = array_search($right, $priorityKeys, true);
            $leftRank = $leftPos === false ? 999 : $leftPos;
            $rightRank = $rightPos === false ? 999 : $rightPos;
            if ($leftRank === $rightRank) {
                return strcmp($left, $right);
            }
            return $leftRank <=> $rightRank;
        });

        $items = [];
        foreach ($keys as $key) {
            if (in_array($key, $skipKeys, true)) {
                // role_permissions skipped from generic loop — handle separately so from/to is readable
                if ($key === 'role_permissions') {
                    $oldEncoded = json_encode($oldPayload[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $newEncoded = json_encode($newPayload[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($oldEncoded !== $newEncoded) {
                        $items[] = [
                            'label' => 'Role Permissions',
                            'from' => array_key_exists($key, $oldPayload) ? $formatRolePermissions($oldPayload[$key]) : 'None',
                            'to'   => array_key_exists($key, $newPayload) ? $formatRolePermissions($newPayload[$key]) : 'None',
                        ];
                    }
                }
                continue;
            }
            $oldEncoded = json_encode($oldPayload[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $newEncoded = json_encode($newPayload[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($oldEncoded === $newEncoded) {
                continue;
            }
            $items[] = [
                'label' => $formatFieldLabel($key),
                'from' => array_key_exists($key, $oldPayload) ? $formatValue($key, $oldPayload[$key]) : 'None',
                'to' => array_key_exists($key, $newPayload) ? $formatValue($key, $newPayload[$key]) : 'None',
            ];
        }

        return array_slice($items, 0, 8);
    };

    $entityLabels = [
        'product' => 'product',
        'user' => 'user',
        'branch' => 'branch',
        'dl_deliveries' => 'delivery',
        'dl_branch_receivings' => 'receiving',
        'dl_production_movements' => 'production movement',
        'dl_production_runs' => 'production run',
        'dl_commissary_ledger' => 'commissary material',
        'dl_commissary_product_ledger' => 'commissary product inventory',
        'dl_ledger_day_status' => 'day status',
        'module_settings' => 'settings',
    ];

    $actionMeta = static function (string $action, ?string $entityType) use ($entityLabels): array {
        $entityLabel = $entityLabels[$entityType ?? ''] ?? str_replace('_', ' ', (string)$entityType);
        $summary = ucwords(str_replace('_', ' ', $action));
        $badgeLabel = 'Activity';
        $badgeClasses = 'bg-slate-100 text-slate-800 ring-slate-300';

        switch ($action) {
            case 'production_output':
                $summary = 'Recorded production output';
                $badgeLabel = 'Output';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'production_withdrawal':
                $summary = 'Recorded production withdrawal';
                $badgeLabel = 'Withdrawal';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'field_update':
                $summary = 'Updated ledger field';
                $badgeLabel = 'Field';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'row_update':
                $summary = 'Updated ledger row';
                $badgeLabel = 'Row';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'close_day':
                $summary = 'Closed the day';
                $badgeLabel = 'Close';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'reopen_day':
                $summary = 'Reopened the day';
                $badgeLabel = 'Reopen';
                $badgeClasses = 'bg-amber-50 text-amber-800 ring-amber-200';
                break;
            case 'create_commissary_run':
                $summary = 'Created commissary run';
                $badgeLabel = 'Commissary';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'update_commissary_run':
                $summary = 'Updated commissary run';
                $badgeLabel = 'Commissary';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'delete_commissary_run':
                $summary = 'Deleted commissary run';
                $badgeLabel = 'Commissary';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'save_commissary_material':
                $summary = 'Saved commissary material count';
                $badgeLabel = 'Material';
                $badgeClasses = 'bg-sky-50 text-sky-800 ring-sky-200';
                break;
            case 'commissary_production':
                $summary = 'Recorded commissary production';
                $badgeLabel = 'Production';
                $badgeClasses = 'bg-amber-50 text-amber-800 ring-amber-200';
                break;
            case 'commissary_dispatch':
                $summary = 'Dispatched from commissary to branch';
                $badgeLabel = 'Dispatch';
                $badgeClasses = 'bg-violet-50 text-violet-800 ring-violet-200';
                break;
            case 'create_delivery':
            case 'delivery_created':
                $summary = 'Created delivery';
                $badgeLabel = 'Delivery';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'update_delivery':
                $summary = 'Updated delivery';
                $badgeLabel = 'Delivery';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'delivery_posted':
                $summary = 'Posted delivery';
                $badgeLabel = 'Delivery';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'delivery_voided':
                $summary = 'Voided delivery';
                $badgeLabel = 'Delivery';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'create_receiving':
            case 'receiving_created':
                $summary = 'Created receiving';
                $badgeLabel = 'Receiving';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'receiving_posted':
                $summary = 'Posted receiving';
                $badgeLabel = 'Receiving';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'receiving_voided':
                $summary = 'Voided receiving';
                $badgeLabel = 'Receiving';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'review_delivery_provenance':
                $summary = 'Updated paper DR check';
                $badgeLabel = 'Paper DR';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'variance_status':
                $summary = 'Updated variance status';
                $badgeLabel = 'Variance';
                $badgeClasses = 'bg-amber-50 text-amber-800 ring-amber-200';
                break;
            default:
                if (str_starts_with($action, 'create_')) {
                    $summary = 'Created ' . ($entityLabel !== '' ? $entityLabel : 'record');
                    $badgeLabel = 'Create';
                    $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                } elseif (str_starts_with($action, 'update_')) {
                    $summary = 'Updated ' . ($entityLabel !== '' ? $entityLabel : 'record');
                    $badgeLabel = 'Update';
                    $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                } elseif (str_starts_with($action, 'delete_')) {
                    $summary = 'Deleted ' . ($entityLabel !== '' ? $entityLabel : 'record');
                    $badgeLabel = 'Delete';
                    $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                } elseif (str_starts_with($action, 'restore_')) {
                    $summary = 'Restored ' . ($entityLabel !== '' ? $entityLabel : 'record');
                    $badgeLabel = 'Restore';
                    $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                }
                break;
        }

        return ['summary' => $summary, 'badge_label' => $badgeLabel, 'badge_classes' => $badgeClasses];
    };

    $pickTarget = static function (array $newPayload, array $oldPayload) use ($formatValue): string {
        $source = $newPayload !== [] ? $newPayload : $oldPayload;
        foreach (['name', 'full_name', 'username', 'product_id', 'material_id', 'raw_material_id'] as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $formatted = $formatValue($key, $source[$key]);
            if ($formatted !== 'None') {
                return $formatted;
            }
        }
        return '';
    };

    // Build a human-readable label for the edited record (Source / Record column):
    // users resolve to username/full name, other entities to their identifying
    // data (DR number, product/material name, branch name, etc.).
    $resolveRecordLabel = static function (string $entityType, int $entityId, array $newPayload, array $oldPayload) use ($formatValue, $resolveUserById, $resolveUserFromPayload): string {
        $lowerEntity = strtolower(trim($entityType));
        $isUserEntity = $lowerEntity !== '' && (str_contains($lowerEntity, 'user') || in_array($lowerEntity, ['users', 'user', 'dl_users'], true));
        if ($isUserEntity) {
            $userLabel = $resolveUserFromPayload($newPayload, $oldPayload);
            if ($userLabel !== '') {
                return $userLabel;
            }
            if ($entityId > 0) {
                return $resolveUserById($entityId, 'daily-ledger');
            }
        }

        $sourcePayload = $newPayload !== [] ? $newPayload : $oldPayload;
        foreach (['dr_number', 'name', 'full_name', 'username', 'product_id', 'material_id', 'raw_material_id', 'destination_branch_id', 'branch_id'] as $key) {
            if (!array_key_exists($key, $sourcePayload)) {
                continue;
            }
            $formatted = $formatValue($key, $sourcePayload[$key]);
            if ($formatted !== '' && $formatted !== 'None') {
                return $formatted;
            }
        }

        if ($entityId > 0) {
            $label = $lowerEntity !== '' ? ucwords(str_replace(['dl_', '_'], ['', ' '], $lowerEntity)) : 'Record';
            return $label . ' #' . $entityId;
        }

        return '';
    };

    $buildActivityEntry = static function (array $row, array $oldPayload, array $newPayload, array $overrides = []) use ($actionMeta, $pickTarget, $buildDetailItems, $buildChangeItems, $formatRelativeTime, $resolveActorUsername, $resolveRecordLabel, $resolveUserById): array {
        $meta = $actionMeta((string)$row['action'], $row['entity_type'] ?? null);
        $target = $pickTarget($newPayload, $oldPayload);
        $recordLabel = $resolveRecordLabel((string)($row['entity_type'] ?? ''), (int)($row['entity_id'] ?? 0), $newPayload, $oldPayload);
        $summary = $meta['summary'];
        if ($target !== '') {
            $summary .= ' - ' . $target;
        } elseif ($recordLabel !== '') {
            // Fall back to the resolved record (user name, DR number, product,
            // etc.) so the action is never a generic "Updated user #N".
            $summary .= ' - ' . $recordLabel;
        }

        $actorSource = strtolower(trim((string)($row['actor_source'] ?? '')));
        $actorModuleUserId = (int)($row['actor_module_user_id'] ?? 0);
        $actorKernelUserId = (int)($row['actor_user_id'] ?? 0);
        $actorIdentity = '';
        if ($actorModuleUserId > 0) {
            $actorIdentity = $resolveUserById($actorModuleUserId, 'daily-ledger');
        } elseif ($actorKernelUserId > 0) {
            $actorIdentity = $resolveUserById($actorKernelUserId, $actorSource !== '' ? $actorSource : 'kernel');
        }
        $actorUsername = $resolveActorUsername($actorModuleUserId, $actorKernelUserId, $actorSource);

        $actorName = trim((string)($row['module_actor_name'] ?? ''));
        if ($actorName === '') {
            $actorName = trim((string)($row['kernel_actor_name'] ?? ''));
        }
        if ($actorName === '' && $actorIdentity !== '') {
            $actorName = $actorIdentity;
        }
        if ($actorName === '') {
            if ($actorSource === 'daily-ledger') {
                $actorName = 'Daily Ledger';
            } elseif ($actorSource === 'kernel') {
                $actorName = 'Kernel User';
            } else {
                $actorName = 'System';
            }
        }

        $detailSource = $newPayload !== [] ? $newPayload : $oldPayload;
        $entry = [
            'action' => (string)$row['action'],
            'actor_name' => $actorName,
            'actor_username' => $actorUsername,
            'actor_identity_label' => $actorIdentity,
            'actor_source_label' => ucwords(str_replace('-', ' ', (string)($row['actor_source'] ?? 'system'))),
            'created_at' => (string)$row['created_at'],
            'relative_time' => $formatRelativeTime((string)$row['created_at']),
            'branch_name' => (string)($row['branch_name'] ?? ''),
            'entity_type' => (string)($row['entity_type'] ?? ''),
            'entity_id' => (string)($row['entity_id'] ?? ''),
            'record_label' => $recordLabel,
            'summary' => $summary,
            'badge_label' => $meta['badge_label'],
            'badge_classes' => $meta['badge_classes'],
            'detail_items' => $buildDetailItems($detailSource),
            'change_items' => $buildChangeItems($oldPayload, $newPayload),
            'grouped_items' => [],
            'is_grouped' => false,
        ];

        foreach ($overrides as $key => $value) {
            $entry[$key] = $value;
        }

        return $entry;
    };

    $summarizeDetailItems = static function (array $items): string {
        if ($items === []) {
            return 'None';
        }
        $parts = [];
        foreach (array_slice($items, 0, 4) as $item) {
            $label = trim((string)($item['label'] ?? ''));
            $value = trim((string)($item['value'] ?? ''));
            if ($label === '' || $value === '' || $value === 'None') {
                continue;
            }
            $parts[] = $label . ': ' . $value;
        }
        return $parts !== [] ? implode(' | ', $parts) : 'None';
    };

    $summarizeChangeItems = static function (array $items): string {
        if ($items === []) {
            return 'None';
        }
        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $label = trim((string)($item['label'] ?? ''));
            $from = trim((string)($item['from'] ?? ''));
            $to = trim((string)($item['to'] ?? ''));
            if ($label === '') {
                continue;
            }
            $parts[] = $label . ': ' . $from . ' -> ' . $to;
        }
        return $parts !== [] ? implode(' | ', $parts) : 'None';
    };

    $humanizeEntityType = static function (string $entityType): string {
        $text = trim($entityType);
        if ($text === '') {
            return 'Activity';
        }
        return ucwords(str_replace(['dl_', '_'], ['', ' '], $text));
    };

    $activities = [];
    $productionGroup = null;
    $flushProductionGroup = static function (?array $group) use (&$activities, $buildActivityEntry, $buildDetailItems): void {
        if ($group === null) {
            return;
        }

        if (count($group['rows']) <= 1) {
            $single = $group['rows'][0] ?? null;
            if ($single !== null) {
                $activities[] = $buildActivityEntry($single['row'], $single['old_payload'], $single['new_payload']);
            }
            return;
        }

        $first = $group['rows'][0];
        $summaryPayload = $group['summary_payload'];
        $summaryPayload['products'] = count($group['rows']);
        $activities[] = $buildActivityEntry($first['row'], [], $summaryPayload, [
            'summary' => $group['summary_text'],
            'entity_id' => '',
            'is_grouped' => true,
            'detail_items' => $buildDetailItems($summaryPayload),
            'change_items' => [],
            'grouped_items' => $group['grouped_items'],
        ]);
    };

    foreach ($activityRows as $row) {
        $oldPayload = $decodeJson($row['old_data'] ?? null);
        $newPayload = $decodeJson($row['new_data'] ?? null);
        $isGroupedProductionAction = in_array((string)$row['action'], ['production_output', 'production_withdrawal'], true)
            && $newPayload !== []
            && isset($newPayload['product_id']);

        if ($isGroupedProductionAction) {
            $groupKey = implode('|', [
                (string)$row['action'],
                (string)($row['branch_name'] ?? ''),
                (string)$row['created_at'],
                (string)($newPayload['dr_number'] ?? ''),
                (string)($newPayload['ledger_date'] ?? ''),
            ]);

            if ($productionGroup !== null && $productionGroup['key'] !== $groupKey) {
                $flushProductionGroup($productionGroup);
                $productionGroup = null;
            }

            if ($productionGroup === null) {
                $summaryPayload = [
                    'ledger_date' => $newPayload['ledger_date'] ?? null,
                    'destination_branch_id' => $newPayload['destination_branch_id'] ?? null,
                    'dr_number' => $newPayload['dr_number'] ?? null,
                    'flow_mode' => $newPayload['flow_mode'] ?? null,
                    'quantity' => 0,
                    'products' => 0,
                ];
                if (isset($newPayload['reason']) && trim((string)$newPayload['reason']) !== '') {
                    $summaryPayload['reason'] = $newPayload['reason'];
                }
                $productionGroup = [
                    'key' => $groupKey,
                    'rows' => [],
                    'summary_payload' => $summaryPayload,
                    'summary_text' => '',
                    'grouped_items' => [],
                ];
            }

            $productionGroup['rows'][] = [
                'row' => $row,
                'old_payload' => $oldPayload,
                'new_payload' => $newPayload,
            ];
            $productionGroup['summary_payload']['quantity'] += (float)($newPayload['quantity'] ?? 0);
            $productionGroup['summary_payload']['products'] += 1;
            $productionGroup['grouped_items'][] = [
                'product' => $formatValue('product_id', $newPayload['product_id']),
                'quantity' => $formatValue('quantity', $newPayload['quantity'] ?? 0),
            ];
            $productionGroup['summary_text'] = ((string)$row['action'] === 'production_withdrawal'
                ? 'Recorded production withdrawal'
                : 'Recorded production output') . ' - ' . count($productionGroup['rows']) . ' products';
            continue;
        }

        if ($productionGroup !== null) {
            $flushProductionGroup($productionGroup);
            $productionGroup = null;
        }

        $activities[] = $buildActivityEntry($row, $oldPayload, $newPayload);
    }
    $flushProductionGroup($productionGroup);

    foreach ($activities as &$activity) {
        $activity['entity_type_label'] = $humanizeEntityType((string)($activity['entity_type'] ?? ''));
        $activity['detail_summary'] = $summarizeDetailItems(is_array($activity['detail_items'] ?? null) ? $activity['detail_items'] : []);
        $activity['change_summary'] = $summarizeChangeItems(is_array($activity['change_items'] ?? null) ? $activity['change_items'] : []);
        $resolvedRecordLabel = trim((string)($activity['record_label'] ?? ''));
        if ($resolvedRecordLabel === '') {
            $resolvedRecordLabel = (string)$activity['entity_type_label'];
            if (!empty($activity['entity_id'])) {
                $resolvedRecordLabel .= ' #' . (string)$activity['entity_id'];
            }
        }
        $activity['record_label'] = $resolvedRecordLabel;
        $activity['grouped_summary'] = 'None';
        if (!empty($activity['grouped_items']) && is_array($activity['grouped_items'])) {
            $parts = [];
            foreach (array_slice($activity['grouped_items'], 0, 3) as $groupedItem) {
                $product = trim((string)($groupedItem['product'] ?? ''));
                $quantity = trim((string)($groupedItem['quantity'] ?? ''));
                if ($product === '' || $quantity === '') {
                    continue;
                }
                $parts[] = $product . ' x' . $quantity;
            }
            if ($parts !== []) {
                $activity['grouped_summary'] = implode(' | ', $parts);
                if (count($activity['grouped_items']) > 3) {
                    $activity['grouped_summary'] .= ' | +' . (count($activity['grouped_items']) - 3) . ' more';
                }
            }
        }
    }
    unset($activity);

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/activity.disyl', [
        'page_title' => 'Encoder Activity',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'activity',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'activities' => $activities,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'branch_id' => $branchId,
        'action_filter' => $actionFilter,
        'action_filter_options' => $actionFilterOptions,
        'branches' => $branches,
        'search' => $search,
        'dr_number' => $drNumber,
    ]);
}

// ─── Admin: Products ───────────────────────────────────────────────────

function handleAdminProducts(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin']);
    $input = $ctx->input();
    $search = trim((string)($input['q'] ?? ''));

    $sql = 'SELECT p.*, (SELECT COUNT(*) FROM dl_branch_products bp WHERE bp.product_id = p.id AND bp.is_active = 1) AS branch_count
            FROM dl_products p WHERE 1=1';
    $bind = [];
    if ($search !== '') {
        $sql .= ' AND (p.name LIKE :q OR p.sku LIKE :q2)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%";
    }
    $sql .= ' ORDER BY p.sort_order, p.name';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $branches = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/products.disyl', [
        'page_title' => 'Products',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'products',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'products' => $products,
        'branches' => $branches,
        'search' => $search,
    ]);
}

function apiUpdateVarianceStatus(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor']);

    $input = $ctx->input();
    $varianceId = (int)($input['variance_id'] ?? 0);
    $status = (string)($input['status'] ?? '');

    if ($varianceId <= 0 || !in_array($status, ['unreviewed', 'investigated', 'corrected'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid variance/status', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid variance_id or status'], 422);
        return;
    }

    $reviewerId = 0;
    if (isset($user['id']) && is_numeric($user['id'])) {
        $reviewerId = (int)$user['id'];
        if ($reviewerId <= 0) $reviewerId = 0;
    }
    if ($reviewerId <= 0) {
        $sub = (string)($user['sub'] ?? '');
        if ($sub !== '' && preg_match('/^(?:admin|supervisor|cashier):(\d+)$/', $sub, $m)) {
            $reviewerId = (int)$m[1];
        } elseif (is_numeric($sub)) {
            $reviewerId = (int)$sub;
        }
    }

    // reviewed_by stores the actor id.
    // Prefer daily-ledger user ids when the request is coming from the daily-ledger auth source.
    // If the actor is kernel admin (opt-in allowed), store kernel users.id.
    $reviewedBy = null;
    if ($reviewerId > 0) {
        if (($user['source'] ?? '') === 'daily-ledger') {
            $st = $ctx->db()->prepare(
                'SELECT id FROM dl_users WHERE id = :id AND deleted_at IS NULL LIMIT 1'
            );
            $st->execute([':id' => $reviewerId]);
            $exists = (int)($st->fetchColumn() ?: 0);
            if ($exists > 0) {
                $reviewedBy = $reviewerId;
            }
        } elseif (($user['source'] ?? '') === 'kernel') {
            $reviewedBy = $reviewerId;
        }
    }

    try {
        $stmt = $ctx->db()->prepare(
            'UPDATE dl_variance_flags
             SET resolution_status = :st,
                 reviewed_by = :rb,
                 reviewed_at = CURRENT_TIMESTAMP,
                 is_reviewed = CASE WHEN :st2 = \'unreviewed\' THEN 0 ELSE 1 END
             WHERE id = :id'
        );
        $stmt->execute([
            ':st' => $status,
            ':st2' => $status,
            ':rb' => $reviewedBy,
            ':id' => $varianceId,
        ]);

        if ($stmt->rowCount() <= 0) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Variance not found', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Variance not found'], 404);
            return;
        }

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Variance updated', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
        return;
    } catch (\Throwable $e) {
        write_log('daily-ledger apiUpdateVarianceStatus failed', 'error', [
            'error' => $e->getMessage(),
            'variance_id' => $varianceId,
            'status' => $status,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Server error', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Server error'], 500);
        return;
    }
}

function apiCreateProduct(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input = $ctx->input();
    $name  = trim((string)($input['name'] ?? ''));
    $category  = strtolower(trim((string)($input['product_category'] ?? 'bread')));
    if (!in_array($category, ['bread', 'cake', 'other'])) $category = 'bread';
    $price = (float)($input['price'] ?? 0);
    $sort  = (int)($input['sort_order'] ?? 0);
    $outputPiecesPerBatch = dl_normalizePiecesPerBatch($input['output_pieces_per_batch'] ?? null);
    $outputUnitLabel = dl_normalizeOutputUnitLabel($input['output_unit_label'] ?? 'pcs');
    $batchInputQty = isset($input['batch_input_qty']) && $input['batch_input_qty'] !== '' && $input['batch_input_qty'] !== null
        ? round((float)$input['batch_input_qty'], 3) : null;
    if ($batchInputQty !== null && $batchInputQty <= 0) $batchInputQty = null;
    $batchEggQty = isset($input['batch_egg_qty']) && $input['batch_egg_qty'] !== '' && $input['batch_egg_qty'] !== null
        ? round((float)$input['batch_egg_qty'], 3) : null;
    if ($batchEggQty !== null && $batchEggQty <= 0) $batchEggQty = null;

    if ($name === '' || $price <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Name and price are required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Name and price are required'], 422);
        return;
    }

    $sku = dl_generateSku();
    $userId = dl_getActorUserId($user);

    // dl_product_price_history.changed_by has an FK to kernel users.id.
    // Daily-ledger JWTs intentionally use id=0; use NULL when we don't have a kernel actor id.
    $kernelActorUserId = null;
    if (($user['source'] ?? '') === 'kernel' && isset($user['id']) && is_numeric($user['id']) && (int)$user['id'] > 0) {
        $kernelActorUserId = (int)$user['id'];
    }

    try {
        $ctx->db()->prepare(
            'INSERT INTO dl_products (sku, name, product_category, current_price, sort_order, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label) VALUES (:sku, :name, :cat, :price, :sort, :oppb, :biq, :beq, :unit)'
        )->execute([':sku' => $sku, ':name' => $name, ':cat' => $category, ':price' => $price, ':sort' => $sort, ':oppb' => $outputPiecesPerBatch, ':biq' => $batchInputQty, ':beq' => $batchEggQty, ':unit' => $outputUnitLabel]);

        $productId = (int)$ctx->db()->lastInsertId();

        // Record price history
        $ctx->db()->prepare(
            'INSERT INTO dl_product_price_history (product_id, price, changed_by) VALUES (:pid, :price, :uid)'
        )->execute([':pid' => $productId, ':price' => $price, ':uid' => $kernelActorUserId]);

        // Assign to all active branches by default
        $branches = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($branches !== []) {
            $values = [];
            $params = [];
            foreach ($branches as $index => $br) {
                // Unique named placeholders per row: PDO native prepared
                // statements cannot reuse a named marker more than once.
                $values[] = "(:bid_{$index}, :pid_{$index})";
                $params[":bid_{$index}"] = (int)$br['id'];
                $params[":pid_{$index}"] = $productId;
            }
            $ctx->db()->prepare(
                'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES ' . implode(', ', $values)
            )->execute($params);
        }

        dl_auditLog('create_product', null, 'product', (string)$productId, null, [
            'sku' => $sku,
            'name' => $name,
            'price' => $price,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
        ]);

        app()->cache()->clearByTags('daily-ledger', ['dl_products']);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Product created', 'type' => 'success']]));
        $ctx->json([
            'ok' => true,
            'product_id' => $productId,
            'sku' => $sku,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
        ]);
    } catch (\Throwable $e) {
        write_log('apiCreateProduct error: ' . $e->getMessage(), 'error', ['trace' => substr((string)$e->getTraceAsString(), 0, 800)]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create product', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to create product'], 500);
    }
}

function apiUpdateProduct(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input     = $ctx->input();
    $productId = (int)($input['product_id'] ?? 0);
    $name      = trim((string)($input['name'] ?? ''));
    $category  = strtolower(trim((string)($input['product_category'] ?? 'bread')));
    if (!in_array($category, ['bread', 'cake', 'other'])) $category = 'bread';
    $price     = (float)($input['price'] ?? 0);
    $sort      = (int)($input['sort_order'] ?? 0);
    $isActive  = (int)($input['is_active'] ?? 1);
    $outputPiecesPerBatch = dl_normalizePiecesPerBatch($input['output_pieces_per_batch'] ?? null);
    $outputUnitLabel = dl_normalizeOutputUnitLabel($input['output_unit_label'] ?? 'pcs');
    $batchInputQty = isset($input['batch_input_qty']) && $input['batch_input_qty'] !== '' && $input['batch_input_qty'] !== null
        ? round((float)$input['batch_input_qty'], 3) : null;
    if ($batchInputQty !== null && $batchInputQty <= 0) $batchInputQty = null;
    $batchEggQty = isset($input['batch_egg_qty']) && $input['batch_egg_qty'] !== '' && $input['batch_egg_qty'] !== null
        ? round((float)$input['batch_egg_qty'], 3) : null;
    if ($batchEggQty !== null && $batchEggQty <= 0) $batchEggQty = null;
    $userId = 0;
    if (isset($user['id']) && is_numeric($user['id'])) {
        $userId = (int)$user['id'];
        if ($userId <= 0) {
            $userId = 0;
        }
    }
    if ($userId <= 0) {
        $sub = (string)($user['sub'] ?? '');
        if ($sub !== '' && preg_match('/^(?:admin|supervisor|cashier):(\d+)$/', $sub, $m)) {
            $userId = (int)$m[1];
        } elseif (is_numeric($sub)) {
            $userId = (int)$sub;
        }
    }

    if (!$productId || $name === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    // dl_product_price_history.changed_by has an FK to kernel users.id.
    // Daily-ledger JWTs intentionally use id=0; use NULL when we don't have a kernel actor id.
    $kernelActorUserId = null;
    if (($user['source'] ?? '') === 'kernel' && isset($user['id']) && is_numeric($user['id']) && (int)$user['id'] > 0) {
        $kernelActorUserId = (int)$user['id'];
    }

    try {
        // Get old data
        $oldStmt = $ctx->db()->prepare('SELECT name, current_price, sort_order, is_active, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label FROM dl_products WHERE id = :id');
        $oldStmt->execute([':id' => $productId]);
        $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

        if (!$old) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Product not found', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Product not found'], 404);
            return;
        }

        // If price changed, record in price history (immutable snapshot)
        if ((float)$old['current_price'] !== $price) {
            $ctx->db()->prepare(
                'INSERT INTO dl_product_price_history (product_id, price, changed_by) VALUES (:pid, :price, :uid)'
            )->execute([':pid' => $productId, ':price' => $price, ':uid' => $kernelActorUserId]);
        }

        $ctx->db()->prepare(
            'UPDATE dl_products SET name = :name, product_category = :cat, current_price = :price, sort_order = :sort, is_active = :active, output_pieces_per_batch = :oppb, batch_input_qty = :biq, batch_egg_qty = :beq, output_unit_label = :unit WHERE id = :id'
        )->execute([':name' => $name, ':cat' => $category, ':price' => $price, ':sort' => $sort, ':active' => $isActive, ':oppb' => $outputPiecesPerBatch, ':biq' => $batchInputQty, ':beq' => $batchEggQty, ':unit' => $outputUnitLabel, ':id' => $productId]);

        dl_auditLog('update_product', null, 'product', (string)$productId, $old, [
            'name' => $name,
            'price' => $price,
            'sort_order' => $sort,
            'is_active' => $isActive,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
        ]);

        app()->cache()->clearByTags('daily-ledger', ['dl_products']);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Product updated', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        write_log('daily-ledger apiUpdateProduct failed', 'error', [
            'message' => $e->getMessage(),
            'product_id' => $productId,
            'name' => $name,
            'price' => $price,
            'sort_order' => $sort,
            'is_active' => $isActive,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
            'user_id' => $userId,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update product', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to update product'], 500);
    }
}

// ─── Admin: Branches ───────────────────────────────────────────────────

function handleAdminBranches(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin']);
    $input = $ctx->input();
    $search = trim((string)($input['q'] ?? ''));
    $selectedPriceGroupId = isset($input['price_group_id']) && $input['price_group_id'] !== ''
        ? (int)$input['price_group_id'] : 0;

    $sql = 'SELECT b.*, pg.name AS price_group_name,
                ac.code AS assigned_commissary_code,
                ac.name AS assigned_commissary_name,
                (SELECT COUNT(*) FROM dl_user_branches ub INNER JOIN dl_users u ON u.id = ub.user_id WHERE ub.branch_id = b.id AND u.role = \'cashier\' AND u.is_active = 1 AND u.deleted_at IS NULL) AS user_count,
                (SELECT COUNT(*) FROM dl_branch_products bp WHERE bp.branch_id = b.id AND bp.is_active = 1) AS product_count
            FROM dl_branches b
            LEFT JOIN dl_price_groups pg ON pg.id = b.price_group_id
            LEFT JOIN dl_branches ac ON ac.id = b.assigned_commissary_id
            WHERE 1=1';
    $bind = [];
    if ($selectedPriceGroupId > 0) {
        $sql .= ' AND b.price_group_id = :price_group_id';
        $bind[':price_group_id'] = $selectedPriceGroupId;
    }
    if ($search !== '') {
        $sql .= ' AND (b.name LIKE :q OR b.code LIKE :q2 OR b.address LIKE :q3)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%"; $bind[':q3'] = "%{$search}%";
    }
    $sql .= ' ORDER BY b.name';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Commissary candidates for the supply-mode picker (any active branch flagged as commissary).
    $commStmt = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY name');
    $commissaries = $commStmt ? ($commStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $priceGroupsStmt = $ctx->db()->query('SELECT id, name, type, is_default FROM dl_price_groups WHERE is_active = 1 ORDER BY is_default DESC, name');
    $priceGroups = $priceGroupsStmt ? ($priceGroupsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $selectedPriceGroupName = null;
    foreach ($priceGroups as $priceGroup) {
        if ((int)($priceGroup['id'] ?? 0) === $selectedPriceGroupId) {
            $selectedPriceGroupName = (string)($priceGroup['name'] ?? '');
            break;
        }
    }

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/branches.disyl', [
        'page_title' => 'Branches',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'branches',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'branches' => $branches,
        'commissaries' => $commissaries,
        'price_groups' => $priceGroups,
        'search' => $search,
        'selected_price_group_id' => $selectedPriceGroupId,
        'selected_price_group_name' => $selectedPriceGroupName,
    ]);
}

function apiCreateBranch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input   = $ctx->input();
    $code    = strtoupper(trim((string)($input['code'] ?? '')));
    $name    = trim((string)($input['name'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $area    = trim((string)($input['area'] ?? ''));
    $supplyMode = (string)($input['default_supply_mode'] ?? 'self_managed');
    if (!in_array($supplyMode, ['commissary_supplied','self_managed','hybrid'], true)) {
        $supplyMode = 'self_managed';
    }
    $assignedCommissaryId = isset($input['assigned_commissary_id']) && $input['assigned_commissary_id'] !== ''
        ? (int)$input['assigned_commissary_id'] : null;
    $priceGroupId = isset($input['price_group_id']) && $input['price_group_id'] !== ''
        ? (int)$input['price_group_id'] : null;
    $isCommissary = !empty($input['is_commissary']) ? 1 : 0;

    if ($code === '' || $name === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Code and name are required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Code and name are required'], 422);
        return;
    }

    if ($assignedCommissaryId !== null) {
        $commStmt = $ctx->db()->prepare(
            'SELECT id FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1'
        );
        $commStmt->execute([':id' => $assignedCommissaryId]);
        if (!$commStmt->fetchColumn()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Assigned commissary must be an active branch marked as a commissary', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Assigned commissary must be an active branch marked as a commissary'], 422);
            return;
        }
    }

    try {
        $ctx->db()->prepare(
            'INSERT INTO dl_branches (code, name, address, area, default_supply_mode, assigned_commissary_id, price_group_id, is_commissary)
             VALUES (:code, :name, :addr, :area, :mode, :ac, :pg, :ic)'
        )->execute([
            ':code' => $code, ':name' => $name, ':addr' => $address,
            ':area' => $area !== '' ? $area : null,
            ':mode' => $supplyMode, ':ac' => $assignedCommissaryId, ':pg' => $priceGroupId, ':ic' => $isCommissary,
        ]);

        $branchId = (int)$ctx->db()->lastInsertId();

        // Assign all active products to new branch
        $pStmt = $ctx->db()->query('SELECT id FROM dl_products WHERE is_active = 1');
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $ctx->db()->prepare(
                'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES (:bid, :pid)'
            )->execute([':bid' => $branchId, ':pid' => (int)$p['id']]);
        }

        dl_auditLog('create_branch', $branchId, 'branch', (string)$branchId, null, [
            'code' => $code, 'name' => $name, 'area' => $area,
            'default_supply_mode' => $supplyMode,
            'assigned_commissary_id' => $assignedCommissaryId,
            'price_group_id' => $priceGroupId,
            'is_commissary' => $isCommissary,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Branch created', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'branch_id' => $branchId]);
    } catch (\Throwable $e) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create branch', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to create branch'], 500);
    }
}

function apiUpdateBranch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input    = $ctx->input();
    $branchId = (int)($input['branch_id'] ?? 0);
    $name     = trim((string)($input['name'] ?? ''));
    $address  = trim((string)($input['address'] ?? ''));
    $area     = trim((string)($input['area'] ?? ''));
    $isActive = (int)($input['is_active'] ?? 1);
    $supplyMode = (string)($input['default_supply_mode'] ?? '');
    $assignedCommissaryId = isset($input['assigned_commissary_id']) && $input['assigned_commissary_id'] !== ''
        ? (int)$input['assigned_commissary_id'] : null;
    $priceGroupId = isset($input['price_group_id']) && $input['price_group_id'] !== ''
        ? (int)$input['price_group_id'] : null;
    $isCommissary = array_key_exists('is_commissary', $input)
        ? (!empty($input['is_commissary']) ? 1 : 0)
        : null;

    if (!$branchId || $name === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    if ($assignedCommissaryId !== null && $assignedCommissaryId === $branchId) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'A branch cannot be assigned to itself as a commissary', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'A branch cannot be assigned to itself as a commissary'], 422);
        return;
    }

    if ($assignedCommissaryId !== null) {
        $commStmt = $ctx->db()->prepare(
            'SELECT id FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1'
        );
        $commStmt->execute([':id' => $assignedCommissaryId]);
        if (!$commStmt->fetchColumn()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Assigned commissary must be an active branch marked as a commissary', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Assigned commissary must be an active branch marked as a commissary'], 422);
            return;
        }
    }

    try {
        $beforeStmt = $ctx->db()->prepare('SELECT default_supply_mode, assigned_commissary_id, price_group_id, is_commissary FROM dl_branches WHERE id = :id');
        $beforeStmt->execute([':id' => $branchId]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sets = ['name = :name', 'address = :addr', 'area = :area', 'is_active = :active'];
        $bind = [':name' => $name, ':addr' => $address, ':area' => $area !== '' ? $area : null, ':active' => $isActive, ':id' => $branchId];
        if (in_array($supplyMode, ['commissary_supplied','self_managed','hybrid'], true)) {
            $sets[] = 'default_supply_mode = :mode';
            $bind[':mode'] = $supplyMode;
        }
        if ($assignedCommissaryId !== null || array_key_exists('assigned_commissary_id', $input)) {
            $sets[] = 'assigned_commissary_id = :ac';
            $bind[':ac'] = $assignedCommissaryId;
        }
        if ($priceGroupId !== null || array_key_exists('price_group_id', $input)) {
            $sets[] = 'price_group_id = :pg';
            $bind[':pg'] = $priceGroupId;
        }
        if ($isCommissary !== null) {
            $sets[] = 'is_commissary = :ic';
            $bind[':ic'] = $isCommissary;
        }
        $ctx->db()->prepare('UPDATE dl_branches SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($bind);

        $afterStmt = $ctx->db()->prepare('SELECT default_supply_mode, assigned_commissary_id, price_group_id, is_commissary FROM dl_branches WHERE id = :id');
        $afterStmt->execute([':id' => $branchId]);
        $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (($before['default_supply_mode'] ?? null) !== ($after['default_supply_mode'] ?? null)
            || ($before['assigned_commissary_id'] ?? null) !== ($after['assigned_commissary_id'] ?? null)
            || ($before['price_group_id'] ?? null) !== ($after['price_group_id'] ?? null)) {
            dl_auditLog('branch_supply_mode_changed', $branchId, 'dl_branches', (string)$branchId, $before, $after);
        }
        dl_auditLog('update_branch', $branchId, 'branch', (string)$branchId);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Branch updated', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update branch', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to update branch'], 500);
    }
}

// ─── Admin: Users ──────────────────────────────────────────────────────

function handleAdminUsers(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin']);
    $input = $ctx->input();
    $search = trim((string)($input['q'] ?? ''));
    $tab = strtolower(trim((string)($input['tab'] ?? 'active')));
    if (!in_array($tab, ['active', 'inactive', 'deleted'], true)) {
        $tab = 'active';
    }

    $statusSql = match ($tab) {
        'inactive' => ' AND u.deleted_at IS NULL AND u.is_active = 0',
        'deleted' => ' AND u.deleted_at IS NOT NULL',
        default => ' AND u.deleted_at IS NULL AND u.is_active = 1',
    };

    $usersHaveEmail = dlTableHasColumn('dl_users', 'email');
    $userEmailSelect = $usersHaveEmail ? 'u.email, ' : '';
    $sql = "SELECT u.id, u.username, {$userEmailSelect}u.full_name, u.role, u.shift,
                   u.is_active, u.deleted_at,
                   CASE WHEN u.role = 'cashier'
                        THEN (SELECT MIN(ub.branch_id) FROM dl_user_branches ub WHERE ub.user_id = u.id)
                        ELSE NULL END AS branch_id,
                   (SELECT GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ')
                      FROM dl_user_branches ub
                      INNER JOIN dl_branches b ON b.id = ub.branch_id
                      WHERE ub.user_id = u.id) AS branch_names,
                   (SELECT GROUP_CONCAT(ub.branch_id ORDER BY ub.branch_id SEPARATOR ',')
                      FROM dl_user_branches ub
                      WHERE ub.user_id = u.id) AS branch_ids_csv
            FROM dl_users u
            WHERE 1=1" . $statusSql;
    $bind = [];
    if ($search !== '') {
        $emailSearch = $usersHaveEmail ? ' OR u.email LIKE :q2' : '';
        $sql .= ' AND (u.username LIKE :q' . $emailSearch . ' OR u.full_name LIKE :q3 OR u.role LIKE :q4)';
        $bind[':q'] = "%{$search}%";
        if ($usersHaveEmail) {
            $bind[':q2'] = "%{$search}%";
        }
        $bind[':q3'] = "%{$search}%";
        $bind[':q4'] = "%{$search}%";
    }
    $sql .= ' ORDER BY u.full_name';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($users as &$userRow) {
        $userRow['email'] = (string)($userRow['email'] ?? '');
        $userRow['branch_names'] = (string)($userRow['branch_names'] ?? '');
        $userRow['branch_ids_csv'] = (string)($userRow['branch_ids_csv'] ?? '');
        $userRow['branch_id'] = (int)($userRow['branch_id'] ?? 0);
        $userRow['shift'] = (string)($userRow['shift'] ?? '');
    }
    unset($userRow);

    // Per-tab counts for the tab badges (single query over dl_users).
    $countRow = $ctx->db()->query(
        "SELECT
            SUM(CASE WHEN deleted_at IS NULL AND is_active = 1 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN deleted_at IS NULL AND is_active = 0 THEN 1 ELSE 0 END) AS inactive_count,
            SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS deleted_count
         FROM dl_users"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $counts = [
        'active_count' => (int)($countRow['active_count'] ?? 0),
        'inactive_count' => (int)($countRow['inactive_count'] ?? 0),
        'deleted_count' => (int)($countRow['deleted_count'] ?? 0),
    ];

    $branches = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/users.disyl', [
        'page_title' => 'Users',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'users',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'users' => $users,
        'tab' => $tab,
        'active_count' => (int)($counts['active_count'] ?? 0),
        'inactive_count' => (int)($counts['inactive_count'] ?? 0),
        'deleted_count' => (int)($counts['deleted_count'] ?? 0),
        'branches' => $branches,
        'search' => $search,
    ]);
}

function apiCreateUser(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input    = $ctx->input();
    $username = trim((string)($input['username'] ?? ''));
    $email    = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $fullName = trim((string)($input['full_name'] ?? ''));
    $role     = (string)($input['role'] ?? 'cashier');
    $branchId = (int)($input['branch_id'] ?? 0);
    $shift    = (string)($input['shift'] ?? '');

    if ($username === '' || $password === '' || $fullName === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'All fields required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'All fields required'], 422);
        return;
    }

    if (!in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid role', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid role'], 422);
        return;
    }

    if ($shift !== '' && !in_array($shift, ['AM', 'PM'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid shift', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid shift'], 422);
        return;
    }

    // Convenience: when creating a cashier without an explicit shift, auto-bind
    // AM/PM from a username ending in "am"/"pm" (e.g. cashier-miputakAM).
    if ($shift === '' && $role === 'cashier' && preg_match('/^(am|pm)$/i', substr($username, -2))) {
        $shift = strtoupper(substr($username, -2));
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Valid email required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Valid email required'], 422);
        return;
    }

    $identityConflict = dlUserIdentityConflict(0, $username, $email !== '' ? $email : null);
    if ($identityConflict !== null) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $identityConflict, 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => $identityConflict], 409);
        return;
    }

    $branchIds = $input['branch_ids'] ?? [];
    if (!is_array($branchIds)) {
        $branchIds = [];
    }
    $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static function ($v) {
        return $v > 0;
    })));

    // Cashiers must be assigned to a branch
    if ($role === 'cashier' && $branchId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Cashiers must be assigned to a branch', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Cashiers must be assigned to a branch'], 422);
        return;
    }

    if (in_array($role, ['supervisor', 'production_in_charge'], true) && count($branchIds) === 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'At least one branch is required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'At least one branch is required'], 422);
        return;
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Optional `email` column may be missing on shared-host DBs that have
        // not run migration 035. Only write it when the column exists.
        $usersHaveEmail = dlTableHasColumn('dl_users', 'email');
        $emailColumn = $usersHaveEmail ? ', email' : '';
        $emailPlaceholder = $usersHaveEmail ? ', :e' : '';
        $createBind = [':u' => $username, ':p' => $hash, ':n' => $fullName, ':r' => $role, ':s' => $shift !== '' ? $shift : null];
        if ($usersHaveEmail) {
            $createBind[':e'] = $email !== '' ? $email : null;
        }
        $ctx->db()->prepare(
            'INSERT INTO dl_users (username' . $emailColumn . ', password_hash, full_name, role, shift, is_active)
             VALUES (:u' . $emailPlaceholder . ', :p, :n, :r, :s, 1)'
        )->execute($createBind);
        $newUserId = (int)$ctx->db()->lastInsertId();

        if ($role === 'cashier') {
            $ctx->db()->prepare(
                'INSERT IGNORE INTO dl_user_branches (user_id, branch_id) VALUES (:uid, :bid)'
            )->execute([':uid' => $newUserId, ':bid' => $branchId]);
        } elseif (in_array($role, ['supervisor', 'production_in_charge'], true)) {
            foreach ($branchIds as $bid) {
                $ctx->db()->prepare(
                    'INSERT IGNORE INTO dl_user_branches (user_id, branch_id) VALUES (:uid, :bid)'
                )->execute([':uid' => $newUserId, ':bid' => $bid]);
            }
        }

        dl_auditLog('create_user', null, 'user', (string)$newUserId, null, [
            'id' => $newUserId,
            'username' => $username,
            'full_name' => $fullName,
            'email' => $email !== '' ? $email : null,
            'role' => $role,
            'shift' => $shift !== '' ? $shift : null,
            'branch_id' => $role === 'cashier' ? $branchId : null,
            'branch_ids' => in_array($role, ['supervisor', 'production_in_charge'], true) ? $branchIds : null,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User created', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'user_id' => $newUserId]);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Username or email already exists', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Username or email already exists'], 409);
        } else {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create user', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Failed to create user'], 500);
        }
    }
}

function dlUserIdentityConflict(int $excludeUserId, string $username, ?string $email): ?string
{
    $ctx = module();
    if (!$ctx) {
        return 'Module context unavailable';
    }

    $usersHaveEmail = dlTableHasColumn('dl_users', 'email');
    $emailClause = $usersHaveEmail
        ? ' OR (:check_email <> "" AND email IS NOT NULL AND email = :check_email2)'
        : '';
    $stmt = $ctx->db()->prepare(
        'SELECT id
         FROM dl_users
         WHERE id <> :exclude_id
                     AND ((:check_username <> "" AND username = :check_username2)'
                     . $emailClause . ')
         LIMIT 1'
    );
    $params = [
        ':exclude_id' => max(0, $excludeUserId),
        ':check_username' => $username,
        ':check_username2' => $username,
    ];
    if ($usersHaveEmail) {
        $params[':check_email'] = $email ?? '';
        $params[':check_email2'] = $email ?? '';
    }
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC) ? 'Username or email conflicts with another account.' : null;
}

function apiUpdateUser(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input    = $ctx->input();
    $editId   = (int)($input['user_id'] ?? 0);
    $fullName = trim((string)($input['full_name'] ?? ''));
    $email    = strtolower(trim((string)($input['email'] ?? '')));
    $role     = (string)($input['role'] ?? '');
    $isActive = (int)($input['is_active'] ?? 1);
    $password = (string)($input['password'] ?? '');
    $branchId = (int)($input['branch_id'] ?? 0);
    $shift    = (string)($input['shift'] ?? '');

    if (!$editId || $fullName === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    if ($shift !== '' && !in_array($shift, ['AM', 'PM'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid shift', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid shift'], 422);
        return;
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Valid email required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Valid email required'], 422);
        return;
    }

    try {
        if (!in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'], true)) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid role', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Invalid role'], 422);
            return;
        }

        $st = $ctx->db()->prepare('SELECT role, deleted_at, username, full_name, email, is_active FROM dl_users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $editId]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if (!is_array($existing)) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User not found', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'User not found'], 404);
            return;
        }

        if (!empty($existing['deleted_at'])) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Restore the user before editing', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'User is deleted; restore first'], 409);
            return;
        }

        $identityConflict = dlUserIdentityConflict($editId, '', $email !== '' ? $email : null);
        if ($identityConflict !== null) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $identityConflict, 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => $identityConflict], 409);
            return;
        }

        $currentRole = (string)($existing['role'] ?? '');
        if ($role !== $currentRole) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Role changes require new account', 'type' => 'error']]));
            $ctx->json([
                'ok' => false,
                'error' => 'Role changes create a new account instead. Create the new account, then deactivate the old one.',
            ], 422);
            return;
        }

        $branchIds = $input['branch_ids'] ?? [];
        if (!is_array($branchIds)) {
            $branchIds = [];
        }
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static function ($v) {
            return $v > 0;
        })));

        if ($role === 'cashier' && $branchId <= 0) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Cashiers must be assigned to a branch', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Cashiers must be assigned to a branch'], 422);
            return;
        }

        if (in_array($role, ['supervisor', 'production_in_charge'], true) && count($branchIds) === 0) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'At least one branch is required', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'At least one branch is required'], 422);
            return;
        }

        // Optional `email` column may be missing on shared-host DBs that have
        // not run migration 035. Only write it when the column exists.
        $usersHaveEmail = dlTableHasColumn('dl_users', 'email');
        $sql = 'UPDATE dl_users SET full_name = :name, is_active = :active';
        $bind = [':name' => $fullName, ':active' => $isActive, ':id' => $editId];
        // Only write `shift` when the payload carries it, so callers that
        // predate the per-user shift feature cannot accidentally clear one.
        if (array_key_exists('shift', $input)) {
            $sql .= ', shift = :shift';
            $bind[':shift'] = $shift !== '' ? $shift : null;
        }
        if ($usersHaveEmail) {
            $sql .= ', email = :email';
            $bind[':email'] = $email !== '' ? $email : null;
        }
        if ($password !== '') {
            $sql .= ', password_hash = :pass';
            $bind[':pass'] = password_hash($password, PASSWORD_BCRYPT);
        }
        $sql .= ' WHERE id = :id';
        $ctx->db()->prepare($sql)->execute($bind);

        if (in_array($role, ['cashier', 'supervisor', 'production_in_charge'], true)) {
            // Reset branch assignments
            $ctx->db()->prepare('DELETE FROM dl_user_branches WHERE user_id = :uid')->execute([':uid' => $editId]);

            $assignments = $role === 'cashier' ? [$branchId] : $branchIds;
            foreach ($assignments as $bid) {
                $bid = (int)$bid;
                if ($bid <= 0) continue;
                $ctx->db()->prepare(
                    'INSERT IGNORE INTO dl_user_branches (user_id, branch_id) VALUES (:uid, :bid)'
                )->execute([':uid' => $editId, ':bid' => $bid]);
            }
        }

        dl_auditLog('update_user', null, 'user', (string)$editId, [
            'id' => $editId,
            'username' => (string)($existing['username'] ?? ''),
            'full_name' => (string)($existing['full_name'] ?? ''),
            'email' => $existing['email'] ?? null,
            'role' => (string)($existing['role'] ?? ''),
            'is_active' => (int)($existing['is_active'] ?? 0),
        ], [
            'id' => $editId,
            'username' => (string)($existing['username'] ?? ''),
            'full_name' => $fullName,
            'email' => $email !== '' ? $email : null,
            'role' => $role,
            'shift' => $shift !== '' ? $shift : null,
            'is_active' => $isActive,
            'branch_id' => $role === 'cashier' ? $branchId : null,
            'branch_ids' => in_array($role, ['supervisor', 'production_in_charge'], true) ? $branchIds : null,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User updated', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update user', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to update user'], 500);
    }
}

/**
 * Soft-delete a daily-ledger user account. Sets deleted_at and is_active=0
 * across the four role tables. Self-delete is refused. All FK references
 * (encoded_by, updated_by, audit logs, price history) are preserved
 * because the row is not removed.
 */
function apiDeleteUser(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user   = dlCurrentUser(['admin']);
    $input  = $ctx->input();
    $userId = (int)($input['user_id'] ?? 0);
    $role   = (string)($input['role'] ?? '');

    if ($userId <= 0 || !in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    // Prevent self-delete: dlCurrentUser sub is "<role>:<id>" for module logins.
    $sub = (string)($user['sub'] ?? '');
    if ($sub === $role . ':' . $userId) {
        $ctx->json(['ok' => false, 'error' => 'You cannot delete your own account'], 403);
        return;
    }

    if ($role === 'admin') {
        $adminCount = dlActiveAdminCount();
        if ($adminCount <= 1) {
            $ctx->json(['ok' => false, 'error' => 'Cannot delete the last active admin account'], 422);
            return;
        }
    }

    try {
        $stmt = $ctx->db()->prepare(
            'UPDATE dl_users SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
             WHERE id = :id AND role = :role AND deleted_at IS NULL'
        );
        $stmt->execute([':id' => $userId, ':role' => $role]);
        if ($stmt->rowCount() === 0) {
            $ctx->json(['ok' => false, 'error' => 'User not found or already deleted'], 404);
            return;
        }

        $userInfoStmt = $ctx->db()->prepare('SELECT username, full_name FROM dl_users WHERE id = :id LIMIT 1');
        $userInfoStmt->execute([':id' => $userId]);
        $userInfo = $userInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        dl_auditLog('delete_user', null, 'user', (string)$userId, null, [
            'id' => $userId,
            'username' => (string)($userInfo['username'] ?? ''),
            'full_name' => (string)($userInfo['full_name'] ?? ''),
            'role' => $role,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User deleted', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Failed to delete user'], 500);
    }
}

/**
 * Restore a soft-deleted daily-ledger user account. The account is restored
 * as inactive so the admin must explicitly re-activate it before logins
 * are permitted again.
 */
function apiRestoreUser(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    dlCurrentUser(['admin']);
    $input  = $ctx->input();
    $userId = (int)($input['user_id'] ?? 0);
    $role   = (string)($input['role'] ?? '');

    if ($userId <= 0 || !in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    try {
        $stmt = $ctx->db()->prepare(
            'UPDATE dl_users SET deleted_at = NULL
             WHERE id = :id AND role = :role AND deleted_at IS NOT NULL'
        );
        $stmt->execute([':id' => $userId, ':role' => $role]);
        if ($stmt->rowCount() === 0) {
            $ctx->json(['ok' => false, 'error' => 'User not found or not deleted'], 404);
            return;
        }

        $userInfoStmt = $ctx->db()->prepare('SELECT username, full_name FROM dl_users WHERE id = :id LIMIT 1');
        $userInfoStmt->execute([':id' => $userId]);
        $userInfo = $userInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        dl_auditLog('restore_user', null, 'user', (string)$userId, null, [
            'id' => $userId,
            'username' => (string)($userInfo['username'] ?? ''),
            'full_name' => (string)($userInfo['full_name'] ?? ''),
            'role' => $role,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User restored (inactive)', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Failed to restore user'], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Daily Ledger — CSV Import / Export Handlers
// ─────────────────────────────────────────────────────────────────────────

function handleProductsCsvExport(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    
    $user = dlCurrentUser(['admin']);

    $stmt = $ctx->db()->query(
        "SELECT p.*, COUNT(bp.branch_id) as branch_count
         FROM dl_products p
         LEFT JOIN dl_branch_products bp ON p.id = bp.product_id
         GROUP BY p.id
         ORDER BY p.name ASC"
    );
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $headers = [
        'SKU',
        'Name',
        'Category',
        'Price',
        'Sort Order',
        'Active',
        'Output Pieces Per Batch',
        'Output Unit Label',
        'Batch Kilo Qty',
        'Batch Egg Qty',
    ];
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [
            'SKU'                    => (string)($item['sku'] ?? ''),
            'Name'                   => (string)($item['name'] ?? ''),
            'Category'               => ucfirst((string)($item['product_category'] ?? 'bread')),
            'Price'                  => (string)($item['current_price'] ?? '0.00'),
            'Sort Order'             => (string)($item['sort_order'] ?? '0'),
            'Active'                 => ((int)($item['is_active'] ?? 1) === 1) ? '1' : '0',
            'Output Pieces Per Batch'=> (string)($item['output_pieces_per_batch'] ?? '0'),
            'Output Unit Label'      => (string)($item['output_unit_label'] ?? 'pcs'),
            'Batch Kilo Qty'         => isset($item['batch_input_qty']) && (float)$item['batch_input_qty'] > 0 ? (string)$item['batch_input_qty'] : '',
            'Batch Egg Qty'          => isset($item['batch_egg_qty']) && (float)$item['batch_egg_qty'] > 0 ? (string)$item['batch_egg_qty'] : '',
        ];
    }

    dlCsvResponse('products-' . date('Y-m-d-His') . '.csv', $headers, $rows);
}

/**
 * Parse + normalize a single product CSV row (keys already header-normalized).
 *
 * Returns a flat field map ready for the insert/update statements, or throws
 * RuntimeException when a required cell is missing/malformed. The import loop
 * catches that and skips the row (counted), so one bad cell never aborts the
 * whole upload or leaves partial rows committed.
 */
function dl_normalizeProductCsvRow(array $normalizedRow): array
{
    $name = trim((string)($normalizedRow['name'] ?? ''));
    $price = dlCsvNullableFloat($normalizedRow['price'] ?? null);
    if ($name === '' || $price === null || $price < 0) {
        throw new \RuntimeException('Missing or invalid product name/price.');
    }

    $sku = trim((string)($normalizedRow['sku'] ?? ''));
    $sortOrder = dlCsvNullableInt($normalizedRow['sort_order'] ?? null) ?? 0;
    $isActive = isset($normalizedRow['active']) && in_array(strtolower(trim((string)$normalizedRow['active'])), ['0', 'false', 'no']) ? 0 : 1;
    $category = 'bread';
    if (isset($normalizedRow['category'])) {
        $val = strtolower(trim((string)$normalizedRow['category']));
        if (in_array($val, ['bread', 'cake', 'other'], true)) {
            $category = $val;
        }
    }

    $oppbSource = $normalizedRow['output_pieces_per_batch'] ?? ($normalizedRow['output_pieces'] ?? null);
    $oulSource = $normalizedRow['output_unit_label'] ?? ($normalizedRow['output_unit'] ?? 'pcs');
    $batchKiloSource = $normalizedRow['batch_kilo_qty'] ?? ($normalizedRow['batch_input_qty'] ?? ($normalizedRow['kilo_per_batch'] ?? null));
    $batchEggSource = $normalizedRow['batch_egg_qty'] ?? ($normalizedRow['eggs_per_batch'] ?? ($normalizedRow['egg_per_batch'] ?? null));

    $oppb = dl_normalizePiecesPerBatch(dlCsvNullableInt($oppbSource) ?? 0);
    $oul = dl_normalizeOutputUnitLabel($oulSource ?? 'pcs');
    $batchInputQty = dlCsvNullableFloat($batchKiloSource);
    if ($batchInputQty !== null) {
        $batchInputQty = round($batchInputQty, 3);
        if ($batchInputQty <= 0) {
            $batchInputQty = null;
        }
    }
    $batchEggQty = dlCsvNullableFloat($batchEggSource);
    if ($batchEggQty !== null) {
        $batchEggQty = round($batchEggQty, 3);
        if ($batchEggQty <= 0) {
            $batchEggQty = null;
        }
    }

    return [
        'name' => $name,
        'price' => $price,
        'sku' => $sku,
        'sort_order' => $sortOrder,
        'is_active' => $isActive,
        'category' => $category,
        'oppb' => $oppb,
        'oul' => $oul,
        'batch_input_qty' => $batchInputQty,
        'batch_egg_qty' => $batchEggQty,
    ];
}

function apiProductsImportCsv(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    
    $user = dlCurrentUser(['admin']);
    
    $userId = 0;
    if (isset($user['id']) && is_numeric($user['id'])) {
        $userId = (int)$user['id'];
        if ($userId <= 0) $userId = 0;
    }
    if ($userId <= 0) {
        $sub = (string)($user['sub'] ?? '');
        if ($sub !== '' && preg_match('/^(?:admin|supervisor|cashier):(\d+)$/', $sub, $m)) {
            $userId = (int)$m[1];
        } elseif (is_numeric($sub)) {
            $userId = (int)$sub;
        }
    }
    $kernelActorUserId = null;
    if (($user['source'] ?? '') === 'kernel' && isset($user['id']) && is_numeric($user['id']) && (int)$user['id'] > 0) {
        $kernelActorUserId = (int)$user['id'];
    }

    $upload = dlImportReadUploadedCsv('csv_file');
    if (empty($upload['ok'])) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => (string)($upload['error'] ?? 'CSV upload failed.'), 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => (string)($upload['error'] ?? 'CSV upload failed.')], 422);
        return;
    }

    try {
        $rows = dlCsvRowsFromString((string)$upload['raw']);
        if ($rows === []) {
             header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'CSV file is empty.', 'type' => 'error']]));
             $ctx->json(['ok' => false, 'error' => 'CSV file is empty.'], 422);
             return;
        }

        $firstRow = $rows[0] ?? [];
        $normalizedKeys = array_map('dlCsvNormalizeHeader', array_keys($firstRow));
        if (!in_array('name', $normalizedKeys, true) || !in_array('price', $normalizedKeys, true)) {
             header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Missing required columns: Name, Price', 'type' => 'error']]));
             $ctx->json(['ok' => false, 'error' => 'Missing required columns: Name, Price'], 422);
             return;
        }
        
        $updated = 0;
        $created = 0;
        $skipped = 0;
        
        foreach ($rows as $rowIndex => $row) {
            // A malformed cell must skip only its own row (counted in $skipped),
            // never abort the whole import and leave partial rows committed.
            try {
            $normalizedRow = [];
            foreach ($row as $k => $v) {
                $normalizedRow[dlCsvNormalizeHeader((string)$k)] = $v;
            }
            
            $parsed = dl_normalizeProductCsvRow($normalizedRow);
            $name = $parsed['name'];
            $price = $parsed['price'];
            $sku = $parsed['sku'];
            $sortOrder = $parsed['sort_order'];
            $isActive = $parsed['is_active'];
            $category = $parsed['category'];
            $oppb = $parsed['oppb'];
            $oul = $parsed['oul'];
            $batchInputQty = $parsed['batch_input_qty'];
            $batchEggQty = $parsed['batch_egg_qty'];
            
            if ($sku !== '') {
                $stmt = $ctx->db()->prepare('SELECT id, current_price FROM dl_products WHERE sku = :sku');
                $stmt->execute([':sku' => $sku]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $pid = (int)$existing['id'];
                    $oldPrice = (float)$existing['current_price'];
                    
                    $ctx->db()->prepare(
                        'UPDATE dl_products
                         SET name = :name,
                             product_category = :cat,
                             current_price = :price,
                             sort_order = :sort,
                             is_active = :act,
                             output_pieces_per_batch = :oppb,
                             batch_input_qty = :biq,
                             batch_egg_qty = :beq,
                             output_unit_label = :oul
                         WHERE id = :id'
                    )->execute([
                        ':name' => $name,
                        ':cat' => $category,
                        ':price' => $price,
                        ':sort' => $sortOrder,
                        ':act' => $isActive,
                        ':oppb' => $oppb,
                        ':biq' => $batchInputQty,
                        ':beq' => $batchEggQty,
                        ':oul' => $oul,
                        ':id' => $pid,
                    ]);
                    
                    if (abs($oldPrice - $price) > 0.001) {
                        $ctx->db()->prepare(
                            'INSERT INTO dl_product_price_history (product_id, price, changed_by) VALUES (:pid, :price, :uid)'
                        )->execute([':pid' => $pid, ':price' => $price, ':uid' => $kernelActorUserId]);
                    }
                    
                    // Assign active branches if not present
                    $brStmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1');
                    foreach ($brStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $br) {
                        $ctx->db()->prepare(
                            'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES (:bid, :pid)'
                        )->execute([':bid' => (int)$br['id'], ':pid' => $pid]);
                    }
                    
                    dl_auditLog('update_product', null, 'product', (string)$pid, null, [
                        'csv_import' => true,
                        'sku' => $sku,
                        'name' => $name,
                        'category' => $category,
                        'price' => $price,
                        'is_active' => $isActive,
                        'output_pieces_per_batch' => $oppb,
                        'output_unit_label' => $oul,
                        'batch_input_qty' => $batchInputQty,
                        'batch_egg_qty' => $batchEggQty,
                    ]);
                    $updated++;
                    continue;
                }
            }
            
            // Create New
            if ($sku === '') $sku = dl_generateSku();
            
            $ctx->db()->prepare(
                'INSERT INTO dl_products
                    (sku, name, product_category, current_price, sort_order, is_active, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label)
                 VALUES
                    (:sku, :name, :cat, :price, :sort, :act, :oppb, :biq, :beq, :oul)'
            )->execute([
                ':sku' => $sku,
                ':name' => $name,
                ':cat' => $category,
                ':price' => $price,
                ':sort' => $sortOrder,
                ':act' => $isActive,
                ':oppb' => $oppb,
                ':biq' => $batchInputQty,
                ':beq' => $batchEggQty,
                ':oul' => $oul,
            ]);
            $pid = (int)$ctx->db()->lastInsertId();
            
            $ctx->db()->prepare(
                'INSERT INTO dl_product_price_history (product_id, price, changed_by) VALUES (:pid, :price, :uid)'
            )->execute([':pid' => $pid, ':price' => $price, ':uid' => $kernelActorUserId]);
            
            $brStmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1');
            foreach ($brStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $br) {
                $ctx->db()->prepare(
                    'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES (:bid, :pid)'
                )->execute([':bid' => (int)$br['id'], ':pid' => $pid]);
            }
            
            dl_auditLog('create_product', null, 'product', (string)$pid, null, [
                'csv_import' => true,
                'sku' => $sku,
                'name' => $name,
                'category' => $category,
                'price' => $price,
                'is_active' => $isActive,
                'output_pieces_per_batch' => $oppb,
                'output_unit_label' => $oul,
                'batch_input_qty' => $batchInputQty,
                'batch_egg_qty' => $batchEggQty,
            ]);
            $created++;
            } catch (\Throwable $e) {
                $skipped++;
                write_log('dl products import row skipped (row ' . ($rowIndex + 2) . '): ' . $e->getMessage(), 'warning', ['module' => 'daily-ledger']);
            }
        }
        
        $msg = "Imported: $created created, $updated updated, $skipped skipped.";
        app()->cache()->clearByTags('daily-ledger', ['dl_products']);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $msg, 'type' => 'success'], 'reloadProducts' => true]));
        $ctx->json(['ok' => true, 'message' => $msg]);
    } catch (\Throwable $e) {
        write_log('dl products import error: ' . $e->getMessage(), 'error', ['module' => 'daily-ledger']);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Import failed: ' . $e->getMessage(), 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Import failed'], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Daily Ledger — Commissary / Production Runs
// ─────────────────────────────────────────────────────────────────────────

function dl_buildUsagePageData(\Ikabud\Kernel\Contracts\DatabaseContract $db, array $user, string $rawDate, int $requestedBranchId = 0): array
{
    $productsStmt = $db->query("SELECT id, name, product_category, is_active, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label FROM dl_products ORDER BY product_category ASC, sort_order ASC, name ASC");
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Load raw materials
    $materialsStmt = $db->query("SELECT * FROM dl_raw_materials WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
    $materials = $materialsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Load commissary ledger for the date
    $ledgerStmt = $db->prepare("SELECT * FROM dl_commissary_ledger WHERE ledger_date = :date");
    $ledgerStmt->execute([':date' => $rawDate]);
    $ledgerRows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    $ledgerMap = [];
    foreach ($ledgerRows as $r) {
        $ledgerMap[(int)$r['raw_material_id']] = $r;
    }

    $branchesStmt = $db->query("SELECT id, name FROM dl_branches WHERE is_active = 1 ORDER BY name ASC");
    $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $availableBranchIds = array_map('intval', array_column($branches, 'id'));

    $selectedBranchId = $requestedBranchId;
    if ($selectedBranchId > 0 && !in_array($selectedBranchId, $availableBranchIds, true)) {
        $selectedBranchId = 0;
    }

    if ($selectedBranchId <= 0) {
        $defaultBranchStmt = $db->prepare(
            "SELECT destination_branch_id
             FROM dl_production_runs
             WHERE ledger_date = :date AND destination_branch_id IS NOT NULL
             ORDER BY id ASC
             LIMIT 1"
        );
        $defaultBranchStmt->execute([':date' => $rawDate]);
        $defaultBranchId = (int)($defaultBranchStmt->fetchColumn() ?: 0);
        $paperBranchId = 0;
        if ($defaultBranchId <= 0 || !in_array($defaultBranchId, $availableBranchIds, true)) {
            $paperDefaultStmt = $db->prepare(
                "SELECT destination_id
                 FROM dl_deliveries
                 WHERE delivery_date = :date
                   AND origin_type = 'commissary'
                   AND destination_type = 'branch'
                   AND remarks = :remarks
                   AND status <> 'voided'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $paperDefaultStmt->execute([':date' => $rawDate, ':remarks' => dl_paperDrCaptureRemark()]);
            $paperBranchId = (int)($paperDefaultStmt->fetchColumn() ?: 0);
        }
        if ($defaultBranchId > 0 && in_array($defaultBranchId, $availableBranchIds, true)) {
            $selectedBranchId = $defaultBranchId;
        } elseif ($paperBranchId > 0 && in_array($paperBranchId, $availableBranchIds, true)) {
            $selectedBranchId = $paperBranchId;
        } elseif ($availableBranchIds !== []) {
            $selectedBranchId = $availableBranchIds[0];
        }
    }

    // Load production runs for the selected branch and date.
    if ($selectedBranchId > 0) {
        $runsStmt = $db->prepare(
            "SELECT pr.*, p.name as product_name
             FROM dl_production_runs pr
             JOIN dl_products p ON pr.product_id = p.id
             WHERE pr.ledger_date = :date AND pr.destination_branch_id = :branch"
        );
        $runsStmt->execute([':date' => $rawDate, ':branch' => $selectedBranchId]);
    } else {
        $runsStmt = $db->prepare(
            "SELECT pr.*, p.name as product_name
             FROM dl_production_runs pr
             JOIN dl_products p ON pr.product_id = p.id
             WHERE pr.ledger_date = :date AND pr.destination_branch_id IS NULL"
        );
        $runsStmt->execute([':date' => $rawDate]);
    }
    $runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $paperCaptureItems = [];
    if ($selectedBranchId > 0) {
        $paperStmt = $db->prepare(
            "SELECT d.id AS delivery_id, d.dr_number, d.delivery_date, d.created_at,
                    di.product_id, di.quantity,
                    COALESCE(u.username, '') AS created_by_name
             FROM dl_deliveries d
             INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
             LEFT JOIN dl_users u ON u.id = d.created_by
             WHERE d.delivery_date = :date
               AND d.origin_type = 'commissary'
               AND d.destination_type = 'branch'
               AND d.destination_id = :branch
               AND d.remarks = :remarks
               AND d.status <> 'voided'
             ORDER BY d.id DESC, di.id ASC"
        );
        $paperStmt->execute([
            ':date' => $rawDate,
            ':branch' => $selectedBranchId,
            ':remarks' => dl_paperDrCaptureRemark(),
        ]);
        $paperCaptureItems = $paperStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $paperCaptureMap = [];
    $paperDrNumber = '';
    foreach ($paperCaptureItems as $paperItem) {
        $productId = (int)($paperItem['product_id'] ?? 0);
        $quantity = (int)($paperItem['quantity'] ?? 0);
        if ($productId > 0 && $quantity > 0) {
            $paperCaptureMap[$productId] = ($paperCaptureMap[$productId] ?? 0) + $quantity;
        }
        if ($paperDrNumber === '' && trim((string)($paperItem['dr_number'] ?? '')) !== '') {
            $paperDrNumber = trim((string)$paperItem['dr_number']);
        }
    }

    // Map selected-branch runs by product_id so we can associate them 1:1 on the spreadsheet
    $runMap = [];
    foreach ($runs as $r) {
        $runMap[(int)$r['product_id']] = $r;
    }

    // Combine products + runs state
    $productRowsBread = [];
    $productRowsCake = [];
    foreach ($products as $p) {
        if (!$p['is_active']) continue;
        $pid = (int)$p['id'];
        $r = $runMap[$pid] ?? null;
        
        $iqty = $r && $r['primary_input_qty'] > 0 ? (float)$r['primary_input_qty'] : '';
        if ($iqty !== '') {
            $iqty = rtrim(rtrim(sprintf('%.3f', $iqty), '0'), '.');
        }
        $kilo_qty = ($r && ($r['primary_input_type'] ?? '') === 'kilo') ? $iqty : '';
        $egg_qty  = ($r && ($r['primary_input_type'] ?? '') === 'egg')  ? $iqty : '';

        $rowData = [
            'product_id'             => $pid,
            'name'                   => $p['name'],
            'baker_name'             => $r ? (string)$r['baker_name'] : '',
            'kilo_qty'               => $kilo_qty,
            'egg_qty'                => $egg_qty,
            'yield_qty'              => $r && $r['yield_qty'] > 0 ? (int)$r['yield_qty'] : '',
            'category'               => $p['product_category'],
            'output_pieces_per_batch'=> (int)($p['output_pieces_per_batch'] ?? 0),
            'batch_input_qty'        => isset($p['batch_input_qty']) && $p['batch_input_qty'] > 0 ? (float)$p['batch_input_qty'] : 0,
            'batch_egg_qty'          => isset($p['batch_egg_qty']) && $p['batch_egg_qty'] > 0 ? (float)$p['batch_egg_qty'] : 0,
            'output_unit_label'      => (string)($p['output_unit_label'] ?? 'pcs'),
        ];
        
        if ($p['product_category'] === 'cake') {
            $productRowsCake[] = $rowData;
        } else {
            $productRowsBread[] = $rowData;
        }
    }

    // Combine material + ledger state
    $commissaryRows = [];
    foreach ($materials as $m) {
        $mid = (int)$m['id'];
        $l = $ledgerMap[$mid] ?? [];
                        $commissaryRows[] = [
            'material_id'      => $mid,
            'name'             => (string)$m['name'],
            'unit'             => (string)$m['unit_of_measure'],
            'category'         => (string)$m['category'],
            'beg_bal'          => (float)($l['beg_bal'] ?? 0),
            'delivery_qty'     => (float)($l['delivery_qty'] ?? 0),
            'used_qty'         => (float)($l['used_qty'] ?? 0),
            'actual_end_bal'   => (float)($l['actual_end_bal'] ?? 0),
            'calc_variance'    => (float)($l['calc_variance'] ?? 0),
        ];
    }
    $globalBaker = '';
    $globalBranch = $selectedBranchId;
    $globalDrNumber = '';
    foreach ($runs as $r) {
        if ($r['baker_name'] !== '') {
            $globalBaker = (string)$r['baker_name'];
        }
        if ($globalDrNumber === '' && trim((string)($r['dr_number'] ?? '')) !== '') {
            $globalDrNumber = trim((string)$r['dr_number']);
        }
    }
    if ($globalDrNumber === '' && $paperDrNumber !== '') {
        $globalDrNumber = $paperDrNumber;
    }

    // Load net production output movements for the date (scoped to accessible branches).
    // Used on the commissary page to auto-populate yield fields when a branch is selected.
    // "Net" = output movements that have not been reversed.
    $accessibleBranchIds = dl_accessibleBranchIds($user);
    $outputByBranch = [];
    if (count($accessibleBranchIds) > 0) {
        $bPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
        $outStmt = $db->prepare(
            "SELECT pm.destination_branch_id, pm.product_id, SUM(pm.quantity) AS net_qty
             FROM dl_production_movements pm
             WHERE pm.ledger_date = ?
               AND pm.movement_type = 'output'
               AND pm.destination_branch_id IN ({$bPlaceholders})
               AND NOT EXISTS (
                   SELECT 1 FROM dl_production_movements r
                   WHERE r.reference_movement_id = pm.id AND r.movement_type = 'reverse'
               )
             GROUP BY pm.destination_branch_id, pm.product_id
             HAVING net_qty > 0"
        );
        $outStmt->execute(array_merge([$rawDate], $accessibleBranchIds));
        foreach ($outStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $bid = (int)$row['destination_branch_id'];
            $pid = (int)$row['product_id'];
            if (!isset($outputByBranch[$bid])) $outputByBranch[$bid] = [];
            $outputByBranch[$bid][$pid] = (int)$row['net_qty'];
        }
    }

    $paperPrefillStmt = null;
    if (count($accessibleBranchIds) > 0) {
        $paperPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
        $paperPrefillStmt = $db->prepare(
            "SELECT d.destination_id AS branch_id, di.product_id, SUM(di.quantity) AS net_qty
             FROM dl_deliveries d
             INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
             WHERE d.delivery_date = ?
               AND d.origin_type = 'commissary'
               AND d.destination_type = 'branch'
               AND d.destination_id IN ({$paperPlaceholders})
               AND d.remarks = ?
               AND d.status <> 'voided'
             GROUP BY d.destination_id, di.product_id
             HAVING net_qty > 0"
        );
        $paperPrefillStmt->execute(array_merge([$rawDate], $accessibleBranchIds, [dl_paperDrCaptureRemark()]));
        foreach ($paperPrefillStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $bid = (int)$row['branch_id'];
            $pid = (int)$row['product_id'];
            if (!isset($outputByBranch[$bid])) {
                $outputByBranch[$bid] = [];
            }
            $existingQty = (int)($outputByBranch[$bid][$pid] ?? 0);
            $paperQty = (int)$row['net_qty'];
            if ($paperQty > $existingQty) {
                $outputByBranch[$bid][$pid] = $paperQty;
            }
        }
    }

    // Same-location internal-release eligibility for the selected destination
    // branch (presentation-only metadata). The server re-validates every save.
    // Batch-resolved so the product grid render does not issue N×3 queries.
    $sameLocationRelease = ['branch_id' => 0, 'branch' => null, 'eligible' => false, 'products' => []];
    if ($selectedBranchId > 0) {
        $activeProductIds = [];
        foreach ($products as $p) {
            if (!empty($p['is_active'])) {
                $activeProductIds[] = (int)$p['id'];
            }
        }
        $sameLocationRelease = dl_buildSameLocationEligibilityMap($db, $selectedBranchId, $activeProductIds);
    }

    return [
        'date' => $rawDate,
        'products' => $products,
        'branches' => $branches,
        'global_baker_name' => $globalBaker,
        'global_branch_id' => $globalBranch,
        'global_dr_number' => $globalDrNumber,
        'product_rows_bread' => $productRowsBread,
        'product_rows_cake' => $productRowsCake,
        'materials' => $commissaryRows,
        'output_by_branch' => $outputByBranch,
        'paper_capture_product_map' => $paperCaptureMap,
        'paper_capture_dr_number' => $paperDrNumber,
        'same_location_release' => $sameLocationRelease,
        // The Usage page needs the formal-delivery flag to render the correct
        // DR label (required vs internal-release). dlRender injects
        // layout-level feature flags, but not this raw flag.
        'formal_delivery_enabled' => dl_isFormalDeliveryEnabled(),
        'feature_formal_delivery' => dl_isFormalDeliveryEnabled(),
    ];
}

function handleAdminUsage(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();
    $rawDate = (string)($input['date'] ?? '');
    if ($rawDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
        $rawDate = date('Y-m-d');
    }
    $requestedBranchId = (int)($input['branch_id'] ?? 0);

    $pageData = dl_buildUsagePageData($ctx->db(), $user, $rawDate, $requestedBranchId);

    echo dlRender('modules/daily-ledger/admin/usage.disyl', [
        'page_title' => 'Commissary Usage',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'csrf_token' => app()->csrfToken(),
        'current_page' => 'usage',
        'user' => $user,
        'user_name' => $user['full_name'] ?? $user['username'] ?? 'User',
        'user_role' => $user['role'] ?? 'unknown',
    ] + $pageData);
}

function handleAdminCommissary(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $db = $ctx->db();
    $input = $ctx->input();
    $rawDate = (string)($input['date'] ?? '');
    if ($rawDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
        $rawDate = date('Y-m-d');
    }

    $requestedBranchId = (int)($input['branch_id'] ?? 0);
    $requestedCommissaryId = (int)($input['commissary_id'] ?? 0);
    $commissariesStmt = $db->query("SELECT id, name FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY name ASC");
    $commissaries = $commissariesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $selectedCommissaryId = $requestedCommissaryId > 0 ? $requestedCommissaryId : 0;

    // Branches: when commissary selected, show only branches assigned to it OR with DR data from it
    if ($selectedCommissaryId > 0) {
        $branchesStmt = $db->prepare(
            "SELECT DISTINCT b.id, b.name, b.is_commissary
               FROM dl_branches b
               LEFT JOIN dl_deliveries d ON d.destination_id = b.id
                 AND d.destination_type = 'branch'
                 AND d.origin_id = :cid
                 AND d.origin_type = 'commissary'
                 AND d.status = 'posted'
                 AND d.delivery_date = :date
              WHERE b.is_active = 1
                AND (b.assigned_commissary_id = :cid2 OR d.id IS NOT NULL)
              ORDER BY b.name ASC"
        );
        $branchesStmt->execute([':cid' => $selectedCommissaryId, ':cid2' => $selectedCommissaryId, ':date' => $rawDate]);
        $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $branchesStmt = $db->query("SELECT id, name, is_commissary FROM dl_branches WHERE is_active = 1 ORDER BY name ASC");
        $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $availableBranchIds = array_map('intval', array_column($branches, 'id'));
    $selectedBranchId = $requestedBranchId > 0 && in_array($requestedBranchId, $availableBranchIds, true)
        ? $requestedBranchId
        : 0;

    // ── Tab 1: Inventory (commissary product ledger) ──
    $inventorySql = "SELECT cpl.commissary_branch_id,
                COALESCE(b.name, 'Commissary') AS commissary_name,
                cpl.product_id,
                p.name AS product_name,
                p.sku,
                cpl.produced_qty,
                cpl.dispatched_qty,
                cpl.wastage_qty,
                cpl.remaining_qty,
                COALESCE(cum.cumulative_remaining, cpl.remaining_qty) AS cumulative_remaining,
                cpl.updated_at
           FROM dl_commissary_product_ledger cpl
           INNER JOIN dl_products p ON p.id = cpl.product_id
           LEFT JOIN dl_branches b ON b.id = cpl.commissary_branch_id
           LEFT JOIN (
               SELECT commissary_branch_id, product_id, SUM(remaining_qty) AS cumulative_remaining
                 FROM dl_commissary_product_ledger
                GROUP BY commissary_branch_id, product_id
           ) cum ON cum.commissary_branch_id = cpl.commissary_branch_id AND cum.product_id = cpl.product_id
          WHERE cpl.ledger_date = :date
            AND (cpl.produced_qty > 0 OR cpl.dispatched_qty > 0 OR cpl.wastage_qty > 0)";
    $inventoryBind = [':date' => $rawDate];
    if ($selectedCommissaryId > 0) {
        $inventorySql .= ' AND cpl.commissary_branch_id = :cid';
        $inventoryBind[':cid'] = $selectedCommissaryId;
    }
    $inventorySql .= " ORDER BY COALESCE(b.name, 'Commissary') ASC, p.name ASC";
    $inventoryStmt = $db->prepare($inventorySql);
    $inventoryStmt->execute($inventoryBind);
    $inventoryRows = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Cumulative stock (all dates) for dispatch dropdown ──
    $cumulativeStockStmt = $db->prepare(
        "SELECT cpl.commissary_branch_id,
                COALESCE(b.name, 'Commissary') AS commissary_name,
                cpl.product_id,
                p.name AS product_name,
                p.sku,
                SUM(cpl.produced_qty) AS total_produced,
                SUM(cpl.dispatched_qty) AS total_dispatched,
                SUM(cpl.remaining_qty) AS cumulative_remaining
           FROM dl_commissary_product_ledger cpl
           INNER JOIN dl_products p ON p.id = cpl.product_id AND p.is_active = 1
           LEFT JOIN dl_branches b ON b.id = cpl.commissary_branch_id
          GROUP BY cpl.commissary_branch_id, cpl.product_id, b.name, p.name, p.sku
         HAVING cumulative_remaining > 0
          ORDER BY p.name ASC"
    );
    $cumulativeStockStmt->execute();
    $cumulativeStock = $cumulativeStockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Tab 2: Deliveries to branches ──
    $deliverySql = "SELECT d.id AS delivery_id,
                           d.delivery_date,
                           d.dr_number,
                           d.destination_id AS branch_id,
                           b.name AS branch_name,
                           di.product_id,
                           p.name AS product_name,
                           p.sku,
                           di.quantity,
                           d.status,
                           d.remarks,
                           d.created_at,
                           COALESCE(u.full_name, u.username, '') AS created_by_name
                    FROM dl_deliveries d
                    INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                    INNER JOIN dl_branches b ON b.id = d.destination_id
                    INNER JOIN dl_products p ON p.id = di.product_id
                    LEFT JOIN dl_users u ON u.id = d.created_by
                    WHERE d.origin_type = 'commissary'
                      AND d.destination_type = 'branch'
                      AND d.delivery_date = :date";
    $deliveryBind = [':date' => $rawDate];
    if ($selectedBranchId > 0) {
        $deliverySql .= ' AND d.destination_id = :branch';
        $deliveryBind[':branch'] = $selectedBranchId;
    }
    if ($selectedCommissaryId > 0) {
        $deliverySql .= ' AND d.origin_id = :cid';
        $deliveryBind[':cid'] = $selectedCommissaryId;
    }
    $deliverySql .= ' ORDER BY d.delivery_date DESC, b.name ASC, p.name ASC, d.id DESC';
    $deliveryStmt = $db->prepare($deliverySql);
    $deliveryStmt->execute($deliveryBind);
    $deliveryRows = $deliveryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Tab 3: Pullouts / Returns to commissary ──
    $pulloutSql = "SELECT d.id AS delivery_id,
                          d.delivery_date,
                          d.dr_number,
                          d.origin_id AS from_branch_id,
                          COALESCE(ob.name, 'Branch') AS from_branch_name,
                          d.destination_id AS commissary_branch_id,
                          COALESCE(cb.name, 'Commissary') AS commissary_branch_name,
                          di.product_id,
                          p.name AS product_name,
                          p.sku,
                          di.quantity,
                          d.status,
                          d.remarks,
                          d.created_at
                   FROM dl_deliveries d
                   INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                   INNER JOIN dl_products p ON p.id = di.product_id
                   LEFT JOIN dl_branches ob ON ob.id = d.origin_id
                   INNER JOIN dl_branches cb ON cb.id = d.destination_id AND cb.is_commissary = 1
                   WHERE d.destination_type = 'branch'
                     AND d.origin_type = 'branch'
                     AND d.status = 'posted'
                     AND d.delivery_date = :date";
    $pulloutBind = [':date' => $rawDate];
    if ($selectedBranchId > 0) {
        $pulloutSql .= ' AND d.origin_id = :branch';
        $pulloutBind[':branch'] = $selectedBranchId;
    }
    if ($selectedCommissaryId > 0) {
        $pulloutSql .= ' AND d.destination_id = :cid';
        $pulloutBind[':cid'] = $selectedCommissaryId;
    }
    $pulloutSql .= ' ORDER BY d.delivery_date DESC, ob.name ASC, p.name ASC';
    $pulloutStmt = $db->prepare($pulloutSql);
    $pulloutStmt->execute($pulloutBind);
    $pulloutRows = $pulloutStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Tab 4: Summary ──
    $summarySql = "SELECT p.id AS product_id,
                p.name AS product_name,
                p.sku,
                COALESCE(inv.produced_qty, 0) AS produced_qty,
                COALESCE(inv.dispatched_qty, 0) AS dispatched_qty,
                COALESCE(inv.wastage_qty, 0) AS wastage_qty,
                COALESCE(inv.remaining_qty, 0) AS remaining_qty,
                COALESCE(ret.returned_qty, 0) AS returned_qty,
                (COALESCE(inv.remaining_qty, 0) + COALESCE(ret.returned_qty, 0)) AS net_available
           FROM dl_products p
           LEFT JOIN (
               SELECT product_id,
                      SUM(produced_qty) AS produced_qty,
                      SUM(dispatched_qty) AS dispatched_qty,
                      SUM(wastage_qty) AS wastage_qty,
                      SUM(remaining_qty) AS remaining_qty
                 FROM dl_commissary_product_ledger
                WHERE ledger_date = :date1";
    $summaryBind = [':date1' => $rawDate];
    if ($selectedCommissaryId > 0) {
        $summarySql .= ' AND commissary_branch_id = :cid';
        $summaryBind[':cid'] = $selectedCommissaryId;
    }
    $summarySql .= "
                GROUP BY product_id
           ) inv ON inv.product_id = p.id
           LEFT JOIN (
               SELECT di.product_id, SUM(di.quantity) AS returned_qty
                 FROM dl_deliveries d
                 INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                 INNER JOIN dl_branches cb ON cb.id = d.destination_id AND cb.is_commissary = 1
                WHERE d.destination_type = 'branch'
                  AND d.origin_type = 'branch'
                  AND d.status = 'posted'
                  AND d.delivery_date = :date2";
    $summaryBind[':date2'] = $rawDate;
    if ($selectedBranchId > 0) {
        $summarySql .= ' AND d.origin_id = :branch';
        $summaryBind[':branch'] = $selectedBranchId;
    }
    if ($selectedCommissaryId > 0) {
        $summarySql .= ' AND d.destination_id = :cid2';
        $summaryBind[':cid2'] = $selectedCommissaryId;
    }
    $summarySql .= "
                GROUP BY di.product_id
           ) ret ON ret.product_id = p.id
          WHERE COALESCE(inv.produced_qty, 0) > 0
             OR COALESCE(inv.dispatched_qty, 0) > 0
             OR COALESCE(ret.returned_qty, 0) > 0
          ORDER BY p.name ASC";
    $summaryStmt = $db->prepare($summarySql);
    $summaryStmt->execute($summaryBind);
    $summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Aggregate totals
    $totals = [
        'total_produced' => 0,
        'total_dispatched' => 0,
        'total_wastage' => 0,
        'total_returned' => 0,
        'total_remaining' => 0,
        'total_deliveries' => count($deliveryRows),
        'total_pullouts' => count($pulloutRows),
    ];
    foreach ($inventoryRows as $row) {
        $totals['total_produced'] += (int)($row['produced_qty'] ?? 0);
        $totals['total_dispatched'] += (int)($row['dispatched_qty'] ?? 0);
        $totals['total_wastage'] += (int)($row['wastage_qty'] ?? 0);
        $totals['total_remaining'] += (int)($row['remaining_qty'] ?? 0);
    }
    foreach ($pulloutRows as $row) {
        $totals['total_returned'] += (int)($row['quantity'] ?? 0);
    }

    // Branch-level dispatch summary for deliveries tab
    $branchDispatchSummary = [];
    foreach ($deliveryRows as $row) {
        $bid = (int)($row['branch_id'] ?? 0);
        if (!isset($branchDispatchSummary[$bid])) {
            $branchDispatchSummary[$bid] = [
                'branch_id' => $bid,
                'branch_name' => (string)($row['branch_name'] ?? ''),
                'total_qty' => 0,
                'delivery_count' => 0,
                'dr_numbers' => [],
            ];
        }
        $branchDispatchSummary[$bid]['total_qty'] += (int)($row['quantity'] ?? 0);
        $dr = trim((string)($row['dr_number'] ?? ''));
        if ($dr !== '' && !in_array($dr, $branchDispatchSummary[$bid]['dr_numbers'], true)) {
            $branchDispatchSummary[$bid]['dr_numbers'][] = $dr;
        }
    }
    // Count unique delivery IDs per branch
    foreach ($branchDispatchSummary as $bid => &$bs) {
        $uniqueDeliveryIds = [];
        foreach ($deliveryRows as $row) {
            if ((int)($row['branch_id'] ?? 0) === $bid) {
                $uniqueDeliveryIds[(int)($row['delivery_id'] ?? 0)] = true;
            }
        }
        $bs['delivery_count'] = count($uniqueDeliveryIds);
    }
    unset($bs);

    echo dlRender('modules/daily-ledger/admin/commissary.disyl', [
        'page_title' => 'Commissary',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'csrf_token' => app()->csrfToken(),
        'current_page' => 'commissary',
        'user' => $user,
        'user_name' => $user['full_name'] ?? $user['username'] ?? 'User',
        'user_role' => $user['role'] ?? 'unknown',
        'date' => $rawDate,
        'branches' => $branches,
        'commissaries' => $commissaries,
        'branch_id' => $selectedBranchId,
        'commissary_id' => $selectedCommissaryId,
        'inventory_rows' => $inventoryRows,
        'delivery_rows' => $deliveryRows,
        'pullout_rows' => $pulloutRows,
        'summary_rows' => $summaryRows,
        'branch_dispatch_summary' => array_values($branchDispatchSummary),
        'cumulative_stock' => $cumulativeStock,
        'totals' => $totals,
    ]);
}

/**
 * Save a production run (commissary usage row).
 *
 * Extracted from apiSaveProductionRun() so the full transactional behavior can
 * be exercised directly in integration tests. Handles:
 *   - create / update / delete of dl_production_runs
 *   - the commissary → production-movement bridge (non-formal mode)
 *   - same-location internal releases when formal delivery is enabled (DR-less
 *     self-managed commissary/storefront output)
 *   - formal cross-location delivery synchronization when formal mode is on
 *
 * Returns a structured result; throws RuntimeException for controlled
 * validation failures and any other Throwable is a database error.
 */
function dl_saveProductionRun(array $user, array $input): array
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }
    $db = $ctx->db();

    $date          = (string)($input['date'] ?? '');
    $productId     = (int)($input['product_id'] ?? 0);
    $bakerName     = trim((string)($input['baker_name'] ?? ''));
    $type          = 'regular'; // default
    $kiloQty   = (float)($input['kilo_qty'] ?? 0);
    $eggQty    = (float)($input['egg_qty'] ?? 0);
    // Egg takes precedence when kilo is absent; kilo is the default
    if ($eggQty > 0 && $kiloQty <= 0) {
        $inputQty  = $eggQty;
        $inputType = 'egg';
    } else {
        $inputQty  = $kiloQty;
        $inputType = 'kilo';
    }
    $yieldQty      = (int)($input['yield_qty'] ?? 0);
    $destBranchId  = (int)($input['destination_branch_id'] ?? 0);
    $drNumber      = trim((string)($input['dr_number'] ?? ''));
    if ($drNumber !== '') {
        $drNumber = substr($drNumber, 0, 120);
    }

    $actorId = dl_getActorUserId($user);

    $db->beginTransaction();
    try {

        if ($destBranchId > 0) {
            $stmt = $db->prepare(
                "SELECT id, commissary_movement_id, destination_branch_id, dr_number
                 FROM dl_production_runs
                 WHERE ledger_date = :d AND product_id = :p AND destination_branch_id = :dest
                 LIMIT 1"
            );
            $stmt->execute([':d' => $date, ':p' => $productId, ':dest' => $destBranchId]);
        } else {
            $stmt = $db->prepare(
                "SELECT id, commissary_movement_id, destination_branch_id, dr_number
                 FROM dl_production_runs
                 WHERE ledger_date = :d AND product_id = :p AND destination_branch_id IS NULL
                 LIMIT 1"
            );
            $stmt->execute([':d' => $date, ':p' => $productId]);
        }
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $previousDestBranchId = $existing ? (int)($existing['destination_branch_id'] ?? 0) : 0;
        $previousDrNumber = $existing ? trim((string)($existing['dr_number'] ?? '')) : '';
        $formalDeliveryEnabled = dl_isFormalDeliveryEnabled();

        // ─── Same-location internal-release eligibility ──────────────────────
        // Derived ONLY from authoritative branch + product-supply configuration
        // (never from a browser-supplied boolean, name, or address). A DR may be
        // omitted only when the destination is an active commissary that produces
        // the product locally (self-managed / self-referencing / local override).
        $sameLocationDecision = null;
        if ($destBranchId > 0 && $productId > 0) {
            $sameLocationDecision = dl_resolveSameLocationEligibility($destBranchId, $productId);
        }
        $isSameLocationEligible = $sameLocationDecision !== null
            && !empty($sameLocationDecision['same_location']);
        $isSameLocationRelease = $formalDeliveryEnabled
            && $destBranchId > 0
            && $yieldQty > 0
            && $isSameLocationEligible;

        // Same-location releases must respect the same branch-authorization,
        // active-branch, and closed-day gates as production output. They must
        // never create a formal delivery from a branch to itself.
        if ($formalDeliveryEnabled && $destBranchId > 0 && $isSameLocationEligible) {
            $role = (string)($user['role'] ?? '');
            $allowedBranchIds = dl_accessibleBranchIds($user);
            if (!in_array($destBranchId, $allowedBranchIds, true)) {
                throw new RuntimeException('Destination branch is not allowed for this user.');
            }
            dl_maybeAutoCloseBranchDay($destBranchId, $actorId);
            $dayStatus = dl_getDayStatus($destBranchId, $date);
            if ($dayStatus === 'closed' && !dl_roleHasPermission($role, 'production.override')) {
                throw new RuntimeException('Day is closed for this branch.');
            }
        }

        if ($formalDeliveryEnabled && $destBranchId > 0 && $yieldQty > 0 && $drNumber === '' && !$isSameLocationEligible) {
            throw new RuntimeException('Delivery Receipt number is required for branch-directed commissary output.');
        }
        if ($formalDeliveryEnabled && $destBranchId > 0 && $yieldQty > 0 && $isSameLocationEligible && $drNumber !== '') {
            throw new RuntimeException('This branch is a co-located commissary — use Internal release (same location) and leave the DR blank instead of creating a delivery to itself.');
        }

        if ($bakerName === '' && $yieldQty <= 0 && $inputQty <= 0) {
            if ($existing) {
                $db->prepare("DELETE FROM dl_production_runs WHERE id = ?")->execute([$existing['id']]);
            }
        } elseif ($existing) {
            $stmt = $db->prepare(
                "UPDATE dl_production_runs
                 SET baker_name = :baker, primary_input_qty = :iqty, primary_input_type = :itype, yield_qty = :yqty, dr_number = :dr, destination_branch_id = :dest, recorded_by = :actor
                 WHERE id = :id"
            );
            $stmt->execute([
                ':baker' => $bakerName,
                ':iqty'  => $inputQty,
                ':itype' => $inputType,
                ':yqty'  => $yieldQty,
                ':dr'    => $drNumber !== '' ? $drNumber : null,
                ':dest'  => $destBranchId > 0 ? $destBranchId : null,
                ':actor' => $actorId > 0 ? $actorId : null,
                ':id'    => $existing['id'],
            ]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO dl_production_runs (ledger_date, product_id, baker_name, run_type, primary_input_qty, primary_input_type, yield_qty, dr_number, destination_branch_id, recorded_by)
                 VALUES (:date, :pid, :baker, :type, :iqty, :itype, :yqty, :dr, :dest, :actor)"
            );
            $stmt->execute([
                ':date'  => $date,
                ':pid'   => $productId,
                ':baker' => $bakerName,
                ':type'  => $type,
                ':iqty'  => $inputQty,
                ':itype' => $inputType,
                ':yqty'  => $yieldQty,
                ':dr'    => $drNumber !== '' ? $drNumber : null,
                ':dest'  => $destBranchId > 0 ? $destBranchId : null,
                ':actor' => $actorId > 0 ? $actorId : null,
            ]);
        }

        // Determine the saved run id (needed to update commissary_movement_id)
        $runId = $existing ? (int)$existing['id'] : (int)$db->lastInsertId();

        // ─── Commissary → production-movement bridge ───────────────────────────
        // When a destination branch and a yield qty are set, auto-create/update a
        // dl_production_movements 'output' record so the branch ledger (addtl) is
        // kept in sync without the production_in_charge having to encode it twice.
        //
        // Logic:
        //  - priorBridgeId: the movement this run created on the LAST save (if any)
        //  - If that movement was manually reversed (by supervisor/admin) we leave it
        //    alone and re-bridge from scratch for the new values.
        //  - If it was NOT reversed but values changed: reverse it and re-apply.
        //  - If values are identical to last save: no-op.
        //  - Deletion of the run: reverse any outstanding bridge movement.

        $priorBridgeId = $existing ? ((int)($existing['commissary_movement_id'] ?? 0) ?: null) : null;
        $isRunDeleted  = ($bakerName === '' && $yieldQty <= 0 && $inputQty <= 0);
        $newBridgeId   = null;
        $role          = (string)($user['role'] ?? '');

        // The ledger bridge (production movement + storefront addtl) is used when
        // formal delivery is disabled OR when this save is an eligible same-location
        // internal release (which must never route through dl_deliveries).
        $useLedgerBridge = !$formalDeliveryEnabled || $isSameLocationRelease;

        // Local closure: undo a prior bridge movement. Internal releases also
        // reverse the commissary produced/dispatched ledger so the same pieces are
        // not left available for a second dispatch.
        $reverseBridge = function (int $refBridgeId, array $prior, bool $priorInternal, string $overrideReason) use ($db, $productId, $actorId, $role): void {
            dl_applyLedgerDelta((int)$prior['branch'], $productId, (string)$prior['date'], -((int)$prior['qty']), $actorId, 'addtl');
            if ($priorInternal) {
                dl_applyCommissaryProductLedgerDelta($db, (int)$prior['branch'], $productId, (string)$prior['date'], -((int)$prior['qty']), -((int)$prior['qty']), $actorId);
            }
            $revUuid = dl_generateMovementUuid();
            $db->prepare(
                "INSERT INTO dl_production_movements
                    (movement_uuid, movement_type, flow_mode,
                     destination_branch_id, product_id, ledger_date, quantity, dr_number,
                     override_reason, reference_movement_id, source_payload,
                     created_by_id, created_by_role)
                 VALUES
                    (:uuid, 'reverse', 'commissary',
                     :bid, :pid, :ldate, :qty, :dr,
                     :reason, :refid, :payload,
                     :uid, :role)"
            )->execute([
                ':uuid'    => $revUuid,
                ':bid'     => (int)$prior['branch'],
                ':pid'     => $productId,
                ':ldate'   => (string)$prior['date'],
                ':qty'     => (int)$prior['qty'],
                ':dr'      => $priorInternal ? null : (($prior['dr'] ?? '') !== '' ? (string)$prior['dr'] : null),
                ':reason'  => $overrideReason,
                ':refid'   => $refBridgeId,
                ':payload' => json_encode([
                    'commissary_bridge' => true,
                    'auto_reverse' => true,
                    'same_location_internal_release' => $priorInternal,
                    'dr_number' => $priorInternal ? null : (($prior['dr'] ?? '') !== '' ? (string)$prior['dr'] : null),
                ], JSON_UNESCAPED_SLASHES),
                ':uid'     => $actorId > 0 ? $actorId : null,
                ':role'    => $role !== '' ? $role : 'unknown',
            ]);
            dl_auditLog('reverse_commissary_run', (int)$prior['branch'] ?: null, 'dl_production_movements', (string)$revUuid, null, [
                'source_branch_id' => (int)$prior['branch'],
                'product_id' => $productId,
                'ledger_date' => (string)$prior['date'],
                'old_quantity' => (int)$prior['qty'],
                'new_quantity' => 0,
                'same_location_internal_release' => $priorInternal,
                'reference_movement_id' => $refBridgeId,
                'dr_number' => $priorInternal ? null : (($prior['dr'] ?? '') !== '' ? (string)$prior['dr'] : null),
            ]);
        };

        if ($priorBridgeId !== null) {
            // Was the prior bridge movement already manually reversed?
            $revChk = $db->prepare(
                "SELECT id FROM dl_production_movements WHERE reference_movement_id = :rid AND movement_type = 'reverse' LIMIT 1"
            );
            $revChk->execute([':rid' => $priorBridgeId]);
            $alreadyReversed = (bool)$revChk->fetchColumn();

            if (!$alreadyReversed) {
                $priorMoveStmt = $db->prepare(
                    'SELECT destination_branch_id, quantity, ledger_date, dr_number, source_payload FROM dl_production_movements WHERE id = :id LIMIT 1'
                );
                $priorMoveStmt->execute([':id' => $priorBridgeId]);
                $priorMove = $priorMoveStmt->fetch(PDO::FETCH_ASSOC);

                if ($priorMove) {
                    $priorPayload = json_decode((string)($priorMove['source_payload'] ?? '{}'), true);
                    $priorInternal = !empty($priorPayload['same_location_internal_release']);
                    $prior = [
                        'branch' => (int)$priorMove['destination_branch_id'],
                        'qty'    => (int)$priorMove['quantity'],
                        'date'   => (string)$priorMove['ledger_date'],
                        'dr'     => trim((string)($priorMove['dr_number'] ?? '')),
                    ];

                    $sameQuantities = !$isRunDeleted
                        && $prior['branch'] === $destBranchId
                        && $prior['qty']    === $yieldQty
                        && $prior['date']   === $date
                        && $destBranchId > 0
                        && $yieldQty    > 0;

                    if ($formalDeliveryEnabled && !$isSameLocationRelease) {
                        // Current save is a formal cross-location output. Undo
                        // whatever the prior bridge created (a legacy non-formal
                        // bridge or a prior same-location internal release) before
                        // the formal delivery is (re)synchronized below.
                        $reverseBridge($priorBridgeId, $prior, $priorInternal, $priorInternal ? 'commissary-internal-release-reverse' : 'commissary-formal-delivery');
                        $newBridgeId = null;
                    } elseif ($isSameLocationRelease) {
                        // Current save is a same-location internal release.
                        if ($sameQuantities && $priorInternal && $prior['dr'] === '') {
                            // Identical to the previous internal release — idempotent no-op.
                            $newBridgeId = $priorBridgeId;
                        } else {
                            $reverseBridge($priorBridgeId, $prior, $priorInternal, $priorInternal ? 'commissary-internal-release-reverse' : 'commissary-bridge-update');
                            $newBridgeId = null;
                        }
                    } elseif ($sameQuantities && $prior['dr'] !== $drNumber) {
                        $db->prepare(
                            'UPDATE dl_production_movements SET dr_number = :dr, source_payload = :payload WHERE id = :id'
                        )->execute([
                            ':dr' => $drNumber !== '' ? $drNumber : null,
                            ':payload' => json_encode(['commissary_bridge' => true, 'dr_number' => $drNumber !== '' ? $drNumber : null], JSON_UNESCAPED_SLASHES),
                            ':id' => $priorBridgeId,
                        ]);
                        $newBridgeId = $priorBridgeId;
                    } elseif (!$sameQuantities) {
                        $reverseBridge($priorBridgeId, $prior, false, 'commissary-bridge-update');
                        $newBridgeId = null;
                    } else {
                        // Identical values — keep the existing bridge movement
                        $newBridgeId = $priorBridgeId;
                    }
                }
            }
            // If already manually reversed: fall through and re-bridge below if applicable
        }

        // Create a new bridge movement when the run has a destination + yield.
        // Applies for non-formal mode AND same-location internal releases.
        if ($useLedgerBridge && $newBridgeId === null && !$isRunDeleted && $destBranchId > 0 && $yieldQty > 0) {
            $ledgerState = dl_applyLedgerDelta($destBranchId, $productId, $date, $yieldQty, $actorId, 'addtl');

            if ($isSameLocationRelease) {
                // Record produced + dispatched so the same pieces are not left
                // available for a second dispatch from the commissary.
                dl_applyCommissaryProductLedgerDelta($db, $destBranchId, $productId, $date, $yieldQty, $yieldQty, $actorId);
            }

            $newMoveUuid = dl_generateMovementUuid();
            $newPayload = $isSameLocationRelease
                ? json_encode(['commissary_bridge' => true, 'same_location_internal_release' => true, 'dr_number' => null, 'source_branch_id' => $destBranchId], JSON_UNESCAPED_SLASHES)
                : json_encode(['commissary_bridge' => true, 'dr_number' => $drNumber !== '' ? $drNumber : null], JSON_UNESCAPED_SLASHES);
            $db->prepare(
                "INSERT INTO dl_production_movements
                    (movement_uuid, movement_type, flow_mode,
                     destination_branch_id, product_id, ledger_date, quantity, dr_number,
                     source_payload, created_by_id, created_by_role)
                 VALUES
                    (:uuid, 'output', 'commissary',
                     :bid, :pid, :ldate, :qty, :dr,
                     :payload, :uid, :role)"
            )->execute([
                ':uuid'    => $newMoveUuid,
                ':bid'     => $destBranchId,
                ':pid'     => $productId,
                ':ldate'   => $date,
                ':qty'     => $yieldQty,
                ':dr'      => $isSameLocationRelease ? null : ($drNumber !== '' ? $drNumber : null),
                ':payload' => $newPayload,
                ':uid'     => $actorId > 0 ? $actorId : null,
                ':role'    => $role !== '' ? $role : 'unknown',
            ]);
            $newBridgeId = (int)$db->lastInsertId();
        }

        // Persist the bridge movement id back onto the run (NULL when run deleted / keep-in-commissary)
        if ($formalDeliveryEnabled) {
            $syncKeys = [];
            if ($previousDestBranchId > 0 && $previousDrNumber !== '') {
                $syncKeys[] = $previousDestBranchId . '|' . $date . '|' . $previousDrNumber;
            }
            if (!$isRunDeleted && $destBranchId > 0 && $drNumber !== '' && !$isSameLocationRelease) {
                $syncKeys[] = $destBranchId . '|' . $date . '|' . $drNumber;
            }
            foreach (array_values(array_unique($syncKeys)) as $syncKey) {
                [$syncBranchId, $syncDate, $syncDrNumber] = explode('|', $syncKey, 3);
                dl_syncAutoCommissaryDeliveryFromRuns($db, $syncDate, (int)$syncBranchId, $syncDrNumber, $actorId);
            }
            // Same-location internal releases keep their bridge movement (never a
            // self-delivery); formal cross-location output never keeps a bridge.
            if (!$isSameLocationRelease) {
                $newBridgeId = null;
            }
        }

        if ($runId > 0 && !$isRunDeleted) {
            $db->prepare("UPDATE dl_production_runs SET commissary_movement_id = :mid WHERE id = :id")
               ->execute([':mid' => $newBridgeId, ':id' => $runId]);
        }

        $db->commit();

        // Post-commit audit is best-effort: a failure here must never turn an
        // already-committed save into a 500 response (false failure). The
        // transaction is already durable; only observability is lost.
        $resultingAddtl = null;
        try {
            // Resulting storefront addtl for the affected branch/date (audit evidence).
            // Aggregate across shift-period rows (day-level addtl = AM + PM).
            $auditAddtlBranch = $destBranchId > 0 ? $destBranchId : ($previousDestBranchId > 0 ? $previousDestBranchId : 0);
            if ($auditAddtlBranch > 0 && $productId > 0 && $date !== '') {
                $lStmt = $db->prepare('SELECT COALESCE(SUM(addtl), 0) FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d');
                $lStmt->execute([':b' => $auditAddtlBranch, ':p' => $productId, ':d' => $date]);
                $lVal = $lStmt->fetchColumn();
                $resultingAddtl = $lVal === false ? null : (int)$lVal;
            }

            $auditAction = $isRunDeleted ? 'delete_commissary_run' : ($existing ? 'update_commissary_run' : 'create_commissary_run');
            dl_auditLog($auditAction, $destBranchId > 0 ? $destBranchId : null, 'dl_production_runs', "{$date}-{$productId}-" . ($destBranchId > 0 ? $destBranchId : 'commissary'), null, [
                'date'        => $date,
                'product_id'  => $productId,
                'baker_name'  => $bakerName,
                'input_qty'   => $inputQty,
                'input_type'  => $inputType,
                'yield_qty'   => $yieldQty,
                'dr_number'   => $drNumber,
                'dest_branch' => $destBranchId,
                'source_branch_id' => $sameLocationDecision !== null ? ($sameLocationDecision['source_branch_id'] ?? null) : null,
                'same_location_internal_release' => $isSameLocationRelease,
                'movement_id' => $isRunDeleted ? null : $newBridgeId,
                'resulting_addtl' => $resultingAddtl,
            ]);
        } catch (Throwable $e) {
            write_log('apiSaveProductionRun post-commit audit error: ' . $e->getMessage(), 'warning');
        }

        return [
            'ok' => true,
            'run_id' => $runId,
            'movement_id' => $isRunDeleted ? null : $newBridgeId,
            'same_location_internal_release' => $isSameLocationRelease,
            'resulting_addtl' => $resultingAddtl,
        ];

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function apiSaveProductionRun(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();

    $date = (string)($input['date'] ?? '');
    $productId = (int)($input['product_id'] ?? 0);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $productId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Missing or invalid date or product'], 400);
        return;
    }

    try {
        dl_saveProductionRun($user, $input);
        $ctx->json(['ok' => true]);
    } catch (RuntimeException $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
        return;
    } catch (Throwable $e) {
        write_log("apiSaveProductionRun error: " . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error executing transaction'], 500);
    }
}

function apiCommissaryDispatch(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    if (!dl_isFormalDeliveryEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Formal delivery workflow is not enabled. Enable it in Settings first.'], 422);
        return;
    }

    $db = $ctx->db();
    $input = $ctx->input();
    $items = $input['items'] ?? [];
    $destinationBranchId = (int)($input['destination_branch_id'] ?? 0);
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    $ledgerDate = (string)($input['ledger_date'] ?? dl_businessDate());
    $actorId = dl_getActorUserId($user);

    if (!is_array($items) || count($items) === 0) {
        $ctx->json(['ok' => false, 'error' => 'items[] array is required with at least one item.'], 422);
        return;
    }
    if ($destinationBranchId <= 0 || $drNumber === '') {
        $ctx->json(['ok' => false, 'error' => 'destination_branch_id and dr_number are required.'], 422);
        return;
    }

    // Normalize and validate items
    $cleanItems = [];
    foreach ($items as $i) {
        if (!is_array($i)) continue;
        $cid = (int)($i['commissary_branch_id'] ?? 0);
        $pid = (int)($i['product_id'] ?? 0);
        $qty = (int)($i['quantity'] ?? 0);
        if ($cid <= 0 || $pid <= 0 || $qty <= 0) continue;
        if ($cid === $destinationBranchId) {
            $ctx->json(['ok' => false, 'error' => 'Cannot dispatch from a commissary to itself.'], 422);
            return;
        }
        $key = $cid . ':' . $pid;
        if (isset($cleanItems[$key])) {
            $cleanItems[$key]['quantity'] += $qty;
        } else {
            $cleanItems[$key] = ['commissary_branch_id' => $cid, 'product_id' => $pid, 'quantity' => $qty];
        }
    }
    if (count($cleanItems) === 0) {
        $ctx->json(['ok' => false, 'error' => 'No valid items with commissary_branch_id, product_id, and quantity > 0.'], 422);
        return;
    }

    try {
        $db->beginTransaction();

        // Verify destination branch
        $destStmt = $db->prepare('SELECT id, name FROM dl_branches WHERE id = :id AND is_active = 1 LIMIT 1');
        $destStmt->execute([':id' => $destinationBranchId]);
        $destBranch = $destStmt->fetch(PDO::FETCH_ASSOC);
        if (!$destBranch) {
            throw new RuntimeException('Destination branch is not active.');
        }

        // Verify DR not already used
        $existingDelivery = dl_findAutoCommissaryDelivery($db, $destinationBranchId, $ledgerDate, $drNumber);
        if ($existingDelivery) {
            throw new RuntimeException('A delivery with DR "' . $drNumber . '" already exists for this branch on ' . $ledgerDate . '.');
        }
        $existingPaper = dl_findPaperCapturedCommissaryDelivery($db, $destinationBranchId, $ledgerDate, $drNumber);
        if ($existingPaper) {
            throw new RuntimeException('A paper DR capture with DR "' . $drNumber . '" already exists for this branch on ' . $ledgerDate . '.');
        }

        // Validate cumulative stock for each item
        $commissaryNames = [];
        foreach ($cleanItems as $item) {
            $cid = $item['commissary_branch_id'];
            $pid = $item['product_id'];
            $qty = $item['quantity'];

            // Verify commissary
            if (!isset($commissaryNames[$cid])) {
                $commStmt = $db->prepare('SELECT id, name FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1');
                $commStmt->execute([':id' => $cid]);
                $comm = $commStmt->fetch(PDO::FETCH_ASSOC);
                if (!$comm) {
                    throw new RuntimeException('Branch #' . $cid . ' is not an active commissary.');
                }
                $commissaryNames[$cid] = $comm['name'];
            }

            // Check cumulative stock
            $cumulativeStmt = $db->prepare(
                'SELECT SUM(produced_qty - dispatched_qty) AS cumulative_remaining
                   FROM dl_commissary_product_ledger
                  WHERE commissary_branch_id = :cb AND product_id = :pid
                  HAVING cumulative_remaining > 0'
            );
            $cumulativeStmt->execute([':cb' => $cid, ':pid' => $pid]);
            $cumulativeRemaining = (int)($cumulativeStmt->fetchColumn() ?: 0);
            if ($cumulativeRemaining < $qty) {
                throw new RuntimeException('Insufficient commissary stock for product #' . $pid . '. Available: ' . $cumulativeRemaining . ', requested: ' . $qty . '.');
            }
        }

        // Create ONE delivery
        $primaryCommissaryId = array_key_first($cleanItems);
        $primaryCommissaryId = (int)explode(':', $primaryCommissaryId)[0];
        $delStmt = $db->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, status, created_by, posted_by, posted_at, remarks)
             VALUES (:origin_type, :origin_id, :destination_type, :destination_id, :dr_number,
                     :delivery_date, "posted", :created_by, :posted_by, NOW(), :remarks)'
        );
        $delStmt->execute([
            ':origin_type' => 'commissary',
            ':origin_id' => $primaryCommissaryId,
            ':destination_type' => 'branch',
            ':destination_id' => $destinationBranchId,
            ':dr_number' => $drNumber,
            ':delivery_date' => $ledgerDate,
            ':created_by' => $actorId > 0 ? $actorId : null,
            ':posted_by' => $actorId > 0 ? $actorId : null,
            ':remarks' => '[commissary-dispatch]',
        ]);
        $deliveryId = (int)$db->lastInsertId();

        // Add delivery items + update commissary ledger for each item
        $priceGroupId = dl_defaultPriceGroupId();
        $resultItems = [];
        $itemStmt = $db->prepare(
            'INSERT INTO dl_delivery_items
                (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
             VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
        );

        foreach ($cleanItems as $item) {
            $cid = $item['commissary_branch_id'];
            $pid = $item['product_id'];
            $qty = $item['quantity'];

            $itemStmt->execute([
                ':delivery_id' => $deliveryId,
                ':product_id' => $pid,
                ':quantity' => $qty,
                ':unit' => 'pcs',
                ':unit_cost_snapshot' => 0,
                ':price_snapshot' => dl_resolveProductPrice($pid, $priceGroupId, $ledgerDate),
                ':price_group_id' => $priceGroupId,
                ':remarks' => 'commissary_dispatch',
            ]);

            // Debit commissary dispatched_qty
            $stockState = dl_applyCommissaryProductLedgerDelta($db, $cid, $pid, $ledgerDate, 0, $qty, $actorId);

            $resultItems[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'commissary_branch_id' => $cid,
                'remaining_qty' => $stockState['remaining_qty'] ?? 0,
            ];
        }

        dl_auditLog('commissary_dispatch', $primaryCommissaryId, 'dl_deliveries', (string)$deliveryId, null, [
            'commissary_branch_id' => $primaryCommissaryId,
            'destination_branch_id' => $destinationBranchId,
            'dr_number' => $drNumber,
            'ledger_date' => $ledgerDate,
            'destination_name' => $destBranch['name'],
            'item_count' => count($resultItems),
            'total_quantity' => array_sum(array_column($resultItems, 'quantity')),
            'items' => $resultItems,
        ]);

        $db->commit();

        // Verify all items were actually persisted
        $verifyStmt = $db->prepare('SELECT COUNT(*) FROM dl_delivery_items WHERE delivery_id = :did');
        $verifyStmt->execute([':did' => $deliveryId]);
        $actualItemCount = (int)$verifyStmt->fetchColumn();

        $ctx->json([
            'ok' => true,
            'delivery_id' => $deliveryId,
            'dr_number' => $drNumber,
            'destination_branch_id' => $destinationBranchId,
            'expected_items' => count($resultItems),
            'actual_items' => $actualItemCount,
            'items' => $resultItems,
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        write_log("apiCommissaryDispatch error: " . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

function apiSaveCommissaryMaterial(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $db = $ctx->db();

    $input = $ctx->input();
    $date       = (string)($input['date'] ?? '');
    $materialId = (int)($input['material_id'] ?? 0);
    $field      = (string)($input['field'] ?? '');
    $val        = (float)($input['value'] ?? 0);
    $fieldMap = [
        'beg_bal' => 'beg_bal',
        'delivery_qty' => 'delivery_qty',
        'used_qty' => 'used_qty',
        'actual_end_bal' => 'actual_end_bal',
    ];
    $column = dl_allowedColumn($field, $fieldMap);

    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $materialId <= 0 || $column === null) {
        $ctx->json(['ok' => false, 'error' => 'Invalid data'], 400);
        return;
    }
    if ($val < 0 || $val > 999999.999) {
        $ctx->json(['ok' => false, 'error' => 'Value out of bounds'], 422);
        return;
    }

    $actorId = dl_getActorUserId($user);

    try {
        $stmt = $db->prepare(
            "INSERT INTO dl_commissary_ledger (ledger_date, raw_material_id, {$column}, recorded_by)
             VALUES (:date, :mid, :val, :actor)
             ON DUPLICATE KEY UPDATE {$column} = :val, recorded_by = :actor"
        );
        $stmt->execute([
            ':date' => $date,
            ':mid'  => $materialId,
            ':val'  => $val,
            ':actor'=> $actorId > 0 ? $actorId : null,
        ]);

        // Read back the full row so the UI can update variance inline (no page reload needed)
        $rowStmt = $db->prepare(
            "SELECT beg_bal, delivery_qty, used_qty, actual_end_bal, calc_variance
             FROM dl_commissary_ledger
             WHERE ledger_date = :date AND raw_material_id = :mid
             LIMIT 1"
        );
        $rowStmt->execute([':date' => $date, ':mid' => $materialId]);
        $updatedRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        dl_auditLog('save_commissary_material', null, 'dl_commissary_ledger', "{$date}-{$materialId}", null, [
            'date'        => $date,
            'material_id' => $materialId,
            'field'       => $field,
            'value'       => $val,
        ]);

        $ctx->json(['ok' => true, 'row' => $updatedRow]);
    } catch (Throwable $e) {
        write_log("apiSaveCommissaryMaterial error: " . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Save failed'], 500);
    }
}

function apiDailyLedgerMe(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin', 'production_in_charge']);
    $allowedBranchIds = dl_accessibleBranchIds($user);
    if (count($allowedBranchIds) === 0) { $allowedBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));
    $stmtAll = $ctx->db()->prepare("SELECT id, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $stmtAll->execute($allowedBranchIds);
    $allBranches = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $clockLabel = dl_operatingClockLabel();
    $branches = [];

    if (count($allowedBranchIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));
        $stmt = $ctx->db()->prepare(
            "SELECT id, code, name
             FROM dl_branches
             WHERE is_active = 1 AND id IN ({$placeholders})
             ORDER BY name"
        );
        $stmt->execute($allowedBranchIds);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $ctx->json([
        'ok' => true,
        'user' => [
            'id' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'name' => (string)($user['name'] ?? ''),
            'full_name' => (string)($user['full_name'] ?? $user['name'] ?? ''),
            'role' => (string)($user['role'] ?? '')
        ],
        'branches' => $branches,
        'clock' => [
            'business_date' => $clockLabel['business_date'],
            'close_of_day_time' => $clockLabel['close_of_day_time'],
            'operating_timezone' => $clockLabel['operating_timezone'],
            'operating_region' => $clockLabel['operating_region'],
        ],
        'all_branches' => $allBranches,
    ]);
}

function handleBranchSummaryRedirect(): void
{
    $ctx = module();
    if ($ctx) {
        $ctx->redirect(dlGetBaseUrl() . '/admin/sales');
    }
}

function handleAdminWithdrawals(): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor']);
    $db = $ctx->db();
    $input = $ctx->input();
    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : (!empty($input['date']) ? (string)$input['date'] : $today);
    $dateTo = !empty($input['date_to']) ? (string)$input['date_to'] : (!empty($input['date']) ? (string)$input['date'] : $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = $today;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = $today;
    }
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $branchId = (int)($input['branch_id'] ?? 0);
    $commissaryId = (int)($input['commissary_id'] ?? 0);
    $search = trim((string)($input['q'] ?? ''));

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) {
        $accessibleBranchIds = [0];
    }
    if ((string)($user['role'] ?? '') !== 'admin' && $branchId > 0 && !in_array($branchId, $accessibleBranchIds, true)) {
        $branchId = 0;
    }

    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));

    $sql = 'SELECT cw.id, cw.ledger_date, cw.created_at, cw.withdrawal_type, cw.reason_code,
                   cw.custom_reason, cw.quantity, cw.dr_number, cw.liable_user_id,
                   p.name AS product_name,
                   b.name AS branch_name,
                   b.area AS branch_area,
                   COALESCE(cb.name, \'—\') AS commissary_name,
                   COALESCE(
                       NULLIF(u.full_name, ""),
                       (SELECT NULLIF(uc.full_name, "")
                          FROM dl_user_branches ub
                          JOIN dl_users uc ON uc.id = ub.user_id AND uc.role = "cashier"
                         WHERE ub.branch_id = cw.branch_id
                         LIMIT 1),
                       NULLIF(u.username, ""),
                       "Unknown"
                   ) AS cashier_name,
                   NULLIF(lu.full_name, lu.username) AS liable_user_name
              FROM dl_cashier_withdrawals cw
              JOIN dl_products p ON p.id = cw.product_id
              JOIN dl_branches b ON b.id = cw.branch_id
              LEFT JOIN dl_branches cb ON cb.id = b.assigned_commissary_id AND cb.is_commissary = 1
              LEFT JOIN dl_users u ON u.id = cw.encoded_by AND cw.encoded_by > 0
              LEFT JOIN dl_users lu ON lu.id = cw.liable_user_id
             WHERE cw.branch_id IN (' . $branchPlaceholders . ')
               AND cw.ledger_date BETWEEN :date_from AND :date_to';
    $bind = [':date_from' => $dateFrom, ':date_to' => $dateTo];
    $executeBind = array_merge($accessibleBranchIds, $bind);
    if ($branchId > 0) {
        $sql .= ' AND cw.branch_id = :bid';
        $executeBind[':bid'] = $branchId;
    }
    if ($commissaryId > 0) {
        $sql .= ' AND b.assigned_commissary_id = :cid';
        $executeBind[':cid'] = $commissaryId;
    }
    if ($search !== '') {
        $sql .= ' AND (p.name LIKE :q OR b.name LIKE :q_branch OR COALESCE(cb.name, \'\') LIKE :q_commissary OR COALESCE(lu.full_name, \'\') LIKE :q_liable OR COALESCE(u.full_name, u.username, \'\') LIKE :q_cashier OR COALESCE(cw.reason_code, \'\') LIKE :q_reason OR COALESCE(cw.custom_reason, \'\') LIKE :q_custom_reason OR COALESCE(cw.dr_number, \'\') LIKE :q_dr)';
        $like = '%' . $search . '%';
        $executeBind[':q'] = $like;
        $executeBind[':q_branch'] = $like;
        $executeBind[':q_commissary'] = $like;
        $executeBind[':q_liable'] = $like;
        $executeBind[':q_cashier'] = $like;
        $executeBind[':q_reason'] = $like;
        $executeBind[':q_custom_reason'] = $like;
        $executeBind[':q_dr'] = $like;
    }
    $sql .= ' ORDER BY cw.created_at DESC LIMIT 500';
    $stmt = $db->prepare($sql);
    $stmt->execute($executeBind);
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Hide rows where cashier couldn't be resolved; format time for display
    $rows = [];
    $totalQuantity = 0;
    $typeCounts = [
        'charge' => 0,
        'pullout' => 0,
        'adjustment_add' => 0,
    ];
    foreach ($allRows as $row) {
        if (($row['cashier_name'] ?? 'Unknown') !== 'Unknown') {
            $row['created_time'] = !empty($row['created_at']) ? date('H:i', strtotime($row['created_at'])) : '';
            $type = (string)($row['withdrawal_type'] ?? '');
            $typeMeta = dlWithdrawalTypeMeta($type);
            $row['withdrawal_type_label'] = $typeMeta['label'];
            $row['withdrawal_type_badge_classes'] = $typeMeta['badge_classes'];
            $row['reason_code_label'] = trim((string)($row['reason_code'] ?? '')) !== ''
                ? dlHumanizeToken((string)$row['reason_code'])
                : '';
            $row['custom_reason'] = trim((string)($row['custom_reason'] ?? ''));
            $rows[] = $row;
            $totalQuantity += (int)($row['quantity'] ?? 0);
            if (isset($typeCounts[$type])) {
                $typeCounts[$type]++;
            }
        }
    }

    $branchesStmt = $db->prepare("SELECT id, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branchesStmt->execute($accessibleBranchIds);
    $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $commissarySql = "SELECT DISTINCT cb.id, cb.name, cb.area
                        FROM dl_branches cb
                        INNER JOIN dl_branches b ON b.assigned_commissary_id = cb.id
                       WHERE cb.is_commissary = 1 AND cb.is_active = 1 AND b.id IN ({$branchPlaceholders})
                       ORDER BY COALESCE(cb.area, ''), cb.name";
    $commissaryStmt = $db->prepare($commissarySql);
    $commissaryStmt->execute($accessibleBranchIds);
    $commissaries = $commissaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/withdrawals.disyl', [
        'page_title' => 'Stock Adjustments',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'stock-adjustments',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'withdrawals' => $rows,
        'branches' => $branches,
        'commissaries' => $commissaries,
        'branch_id' => $branchId,
        'commissary_id' => $commissaryId,
        'date' => $dateTo,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'search' => $search,
        'total_rows' => count($rows),
        'total_quantity' => $totalQuantity,
        'type_charge_count' => $typeCounts['charge'],
        'type_pullout_count' => $typeCounts['pullout'],
        'type_adjustment_add_count' => $typeCounts['adjustment_add'],
    ]);
}
