<?php

declare(strict_types=1);

if (!function_exists('kernelPrepareTenantAdminJsonRequest')) {
    function kernelPrepareTenantAdminJsonRequest(bool $enforceCsrf = true): bool
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());

        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            return false;
        }

        $input = app()->input();
        if (isset($input['_json_error'])) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error' => 'Invalid JSON request body',
                'details' => (string)$input['_json_error'],
                'request_id' => request_id(),
            ]);
            return false;
        }

        if ($enforceCsrf) {
            app()->csrfEnforce();
        }

        return true;
    }
}

if (!function_exists('kernelTenantSeedCatalog')) {
    function kernelTenantSeedCatalog(): array
    {
        return [
            'bakeshop_julies_bread_pastry' => [
                'label' => "Julie's Bakeshop Bread/Pastry",
                'entry_module_id' => 'bakeshop',
                'path' => BASE_PATH . '/database/seeds/002_bakeshop_julies_bread_pastry.sql',
                'counts' => [
                    'branches' => "SELECT COUNT(*) FROM bakeshop_branches WHERE external_store_id IS NOT NULL AND code IN ('JB01', 'JES01', 'JL01', 'JMA01', 'JMIP01', 'JMN01', 'JP01', 'JPI01', 'JPO01', 'JTUR01')",
                    'products' => "SELECT COUNT(*) FROM bakeshop_products WHERE sku LIKE 'JBS-PRD-%'",
                    'ingredients' => "SELECT COUNT(*) FROM bakeshop_ingredients WHERE sku LIKE 'JBS-ING-%'",
                ],
            ],
        ];
    }
}

if (!function_exists('kernelExecuteSqlStatements')) {
    function kernelExecuteSqlStatements(PDO $db, string $sql): void
    {
        $length = strlen($sql);
        $buffer = '';
        $delimiter = ';';
        $singleQuoted = false;
        $doubleQuoted = false;
        $lineComment = false;
        $blockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }

            if (!$singleQuoted && !$doubleQuoted) {
                if ($char === '-' && $next === '-') {
                    $lineComment = true;
                    $index++;
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $blockComment = true;
                    $index++;
                    continue;
                }
            }

            if ($char === "'" && !$doubleQuoted) {
                $escaped = $index > 0 && $sql[$index - 1] === '\\';
                if (!$escaped) {
                    $singleQuoted = !$singleQuoted;
                }
            } elseif ($char === '"' && !$singleQuoted) {
                $escaped = $index > 0 && $sql[$index - 1] === '\\';
                if (!$escaped) {
                    $doubleQuoted = !$doubleQuoted;
                }
            }

            if (!$singleQuoted && !$doubleQuoted && $char === $delimiter) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $db->exec($statement);
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $db->exec($statement);
        }
    }
}

if (!function_exists('kernelHandleApiTenantCreate')) {
    function kernelHandleApiTenantCreate(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantKey = strtolower(trim((string)($input['tenant_key'] ?? '')));
        $domain = strtolower(trim((string)($input['domain'] ?? '')));
        $adminEmail = trim((string)($input['admin_email'] ?? ''));
        $entryModuleNorm = normalizeTenantEntryModuleId($input['entry_module_id'] ?? '', true);
        $entryModuleId = $entryModuleNorm['value'];

        if ($tenantKey === '' || !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $tenantKey)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid tenant_key']);
            return;
        }
        if ($domain === '' || !preg_match('/^[a-z0-9\-\.]+$/', $domain)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid domain']);
            return;
        }
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid admin_email']);
            return;
        }
        if (empty($entryModuleNorm['ok'])) {
            http_response_code(422);
            $entryModuleError = ($entryModuleNorm['error'] ?? '') === 'entry_module_not_loadable'
                ? 'Entry module must be enabled and loadable'
                : 'Invalid entry_module_id';
            echo json_encode(['ok' => false, 'error' => $entryModuleError, 'error_code' => $entryModuleNorm['error']]);
            return;
        }

        $pdo = app()->controlDb();
        try {
            $pdo->beginTransaction();

            $adminEmailValue = $adminEmail !== '' ? $adminEmail : null;
            $stmt = $pdo->prepare('INSERT INTO kernel_tenants (tenant_key, status, entry_module_id, admin_email) VALUES (:k, :s, :e, :ae)');
            $stmt->execute([':k' => $tenantKey, ':s' => 'active', ':e' => $entryModuleId, ':ae' => $adminEmailValue]);
            $tenantId = (int)$pdo->lastInsertId();
            if ($tenantId <= 0) {
                throw new RuntimeException('Failed to create tenant');
            }

            $dStmt = $pdo->prepare('INSERT INTO kernel_tenant_domains (tenant_id, domain) VALUES (:tid, :d)');
            $dStmt->execute([':tid' => $tenantId, ':d' => $domain]);

            $pdo->commit();
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true, 'tenant_id' => $tenantId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to create tenant']);
        }
    }
}

if (!function_exists('kernelHandleApiTenantEntryModuleSet')) {
    function kernelHandleApiTenantEntryModuleSet(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $entryModuleNorm = normalizeTenantEntryModuleId($input['entry_module_id'] ?? '', true);
        $entryModuleId = $entryModuleNorm['value'];

        if ($tenantId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id is required']);
            return;
        }
        if (empty($entryModuleNorm['ok'])) {
            http_response_code(422);
            $entryModuleError = ($entryModuleNorm['error'] ?? '') === 'entry_module_not_loadable'
                ? 'Entry module must be enabled and loadable'
                : 'Invalid entry_module_id';
            echo json_encode(['ok' => false, 'error' => $entryModuleError, 'error_code' => $entryModuleNorm['error']]);
            return;
        }

        try {
            $stmt = app()->controlDb()->prepare('UPDATE kernel_tenants SET entry_module_id = :entry_module_id, updated_at = NOW() WHERE id = :tenant_id');
            $stmt->bindValue(':entry_module_id', $entryModuleId, $entryModuleId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                $existsStmt = app()->controlDb()->prepare('SELECT id FROM kernel_tenants WHERE id = :tenant_id LIMIT 1');
                $existsStmt->execute([':tenant_id' => $tenantId]);
                if (!$existsStmt->fetchColumn()) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'error' => 'Tenant not found']);
                    return;
                }
            }

            $sync = syncTenantMigrationsForTenant($tenantId, $entryModuleId);
            if (empty($sync['ok'])) {
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Tenant entry module updated, but tenant migrations failed to synchronize',
                    'details' => $sync['error'] ?? 'Unknown error',
                    'tenant_id' => $tenantId,
                ]);
                return;
            }

            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform', 'admin:view:modules']);
            echo json_encode(['ok' => true, 'tenant_id' => $tenantId, 'entry_module_id' => $entryModuleId, 'migration_sync' => $sync, 'request_id' => request_id()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to update tenant entry module']);
        }
    }
}

if (!function_exists('kernelHandleApiTenantDomainAdd')) {
    function kernelHandleApiTenantDomainAdd(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $domain = strtolower(trim((string)($input['domain'] ?? '')));
        if ($tenantId <= 0 || $domain === '' || !preg_match('/^[a-z0-9\-\.]+$/', $domain)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id and valid domain are required']);
            return;
        }

        try {
            $stmt = app()->controlDb()->prepare('INSERT INTO kernel_tenant_domains (tenant_id, domain) VALUES (:tid, :d)');
            $stmt->execute([':tid' => $tenantId, ':d' => $domain]);
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to add domain']);
        }
    }
}

if (!function_exists('kernelHandleApiTenantDomainRemove')) {
    function kernelHandleApiTenantDomainRemove(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $domain = strtolower(trim((string)($input['domain'] ?? '')));
        if ($tenantId <= 0 || $domain === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id and domain are required']);
            return;
        }

        try {
            $stmt = app()->controlDb()->prepare('DELETE FROM kernel_tenant_domains WHERE tenant_id = :tid AND domain = :d');
            $stmt->execute([':tid' => $tenantId, ':d' => $domain]);
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to remove domain']);
        }
    }
}

if (!function_exists('kernelHandleApiTenantCanonicalDomainSet')) {
    function kernelHandleApiTenantCanonicalDomainSet(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $domain = strtolower(trim((string)($input['domain'] ?? '')));
        if ($tenantId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id is required']);
            return;
        }
        if ($domain !== '' && !preg_match('/^[a-z0-9\-\.]+$/', $domain)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid domain format']);
            return;
        }
        if ($domain !== '') {
            $chkStmt = app()->controlDb()->prepare(
                'SELECT id FROM kernel_tenant_domains WHERE domain = :d AND tenant_id = :tid LIMIT 1'
            );
            $chkStmt->execute([':d' => $domain, ':tid' => $tenantId]);
            if (!$chkStmt->fetch()) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Domain is not registered to this tenant']);
                return;
            }
        }

        try {
            $setVal = $domain !== '' ? $domain : null;
            $stmt = app()->controlDb()->prepare(
                'UPDATE kernel_tenants SET canonical_domain = :cd, updated_at = NOW() WHERE id = :tid'
            );
            $stmt->execute([':cd' => $setVal, ':tid' => $tenantId]);
            \Ikabud\Kernel\TenantResolver::clearControlHostCache();
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to set canonical domain']);
        }
    }
}

if (!function_exists('kernelHandleApiTenantDbUpsert')) {
    function kernelHandleApiTenantDbUpsert(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $dbHost = trim((string)($input['db_host'] ?? ''));
        $dbPort = trim((string)($input['db_port'] ?? '3306'));
        $dbName = trim((string)($input['db_name'] ?? ''));
        $dbUser = trim((string)($input['db_user'] ?? ''));
        $dbPass = (string)($input['db_pass'] ?? '');

        if ($tenantId <= 0 || $dbHost === '' || $dbName === '' || $dbUser === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id, db_host, db_name, db_user are required']);
            return;
        }
        if ($dbPort === '' || !preg_match('/^[0-9]{2,5}$/', $dbPort)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid db_port']);
            return;
        }

        $pdo = app()->controlDb();
        try {
            $pdo->beginTransaction();

            $sel = $pdo->prepare('SELECT db_pass_ciphertext, db_pass_iv, db_pass_tag FROM kernel_tenant_db_connections WHERE tenant_id = :tid LIMIT 1');
            $sel->execute([':tid' => $tenantId]);
            $existing = $sel->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) {
                $existing = ['db_pass_ciphertext' => null, 'db_pass_iv' => null, 'db_pass_tag' => null];
            }

            $cipher = $existing['db_pass_ciphertext'] ?? null;
            $iv = $existing['db_pass_iv'] ?? null;
            $tag = $existing['db_pass_tag'] ?? null;

            if (trim($dbPass) !== '') {
                $crypto = new \Ikabud\Kernel\Crypto();
                $enc = $crypto->encryptString($dbPass);
                $cipher = $enc['ciphertext'] ?? null;
                $iv = $enc['iv'] ?? null;
                $tag = $enc['tag'] ?? null;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO kernel_tenant_db_connections '
                . '(tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, db_pass_ciphertext, db_pass_iv, db_pass_tag) '
                . 'VALUES (:tid, :drv, :host, :port, :name, :user, NULL, :charset, :cipher, :iv, :tag) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'db_driver = VALUES(db_driver), '
                . 'db_host = VALUES(db_host), '
                . 'db_port = VALUES(db_port), '
                . 'db_name = VALUES(db_name), '
                . 'db_user = VALUES(db_user), '
                . 'db_pass = NULL, '
                . 'db_charset = VALUES(db_charset), '
                . 'db_pass_ciphertext = :cipher_u, '
                . 'db_pass_iv = :iv_u, '
                . 'db_pass_tag = :tag_u'
            );

            $bind = [
                ':tid' => $tenantId,
                ':drv' => 'mysql',
                ':host' => $dbHost,
                ':port' => $dbPort,
                ':name' => $dbName,
                ':user' => $dbUser,
                ':charset' => 'utf8mb4',
                ':cipher' => $cipher,
                ':iv' => $iv,
                ':tag' => $tag,
                ':cipher_u' => $cipher,
                ':iv_u' => $iv,
                ':tag_u' => $tag,
            ];
            $stmt->execute($bind);

            $pdo->commit();

            $sync = syncTenantMigrationsForTenant($tenantId);
            if (empty($sync['ok'])) {
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Tenant DB connection saved, but tenant migrations failed to synchronize',
                    'details' => $sync['error'] ?? 'Unknown error',
                    'tenant_id' => $tenantId,
                ]);
                return;
            }

            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true, 'migration_sync' => $sync]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                if (function_exists('write_log')) {
                    write_log('error', 'apiTenantDbUpsert failed', [
                        'tenant_id' => $tenantId,
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                } else {
                    error_log('apiTenantDbUpsert failed: ' . $e->getMessage());
                }
            } catch (Throwable $ignored) {
            }
            http_response_code(500);
            $debug = !empty($_ENV['APP_DEBUG']) || !empty($GLOBALS['config']['app']['debug'] ?? null);
            echo json_encode([
                'ok' => false,
                'error' => $debug ? ('Failed to save DB connection: ' . $e->getMessage()) : 'Failed to save DB connection',
            ]);
        }
    }
}

if (!function_exists('kernelHandleApiTenantStatusSet')) {
    function kernelHandleApiTenantStatusSet(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if ($tenantId <= 0 || !in_array($status, ['active', 'suspended'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id and valid status are required']);
            return;
        }

        try {
            $stmt = app()->controlDb()->prepare('UPDATE kernel_tenants SET status = :s, updated_at = NOW() WHERE id = :tid');
            $stmt->execute([':s' => $status, ':tid' => $tenantId]);
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to update tenant status']);
        }
    }
}

if (!function_exists('kernelHandleApiTenantSeedData')) {
    function kernelHandleApiTenantSeedData(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $seedId = trim((string)($input['seed_id'] ?? ''));

        if ($tenantId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id is required', 'request_id' => request_id()]);
            return;
        }
        if ($seedId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'seed_id is required', 'request_id' => request_id()]);
            return;
        }

        $catalog = kernelTenantSeedCatalog();
        $seed = $catalog[$seedId] ?? null;
        if (!is_array($seed)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Unsupported seed_id', 'request_id' => request_id()]);
            return;
        }

        try {
            $tenantStmt = app()->controlDb()->prepare('SELECT id, tenant_key, entry_module_id FROM kernel_tenants WHERE id = :tenant_id LIMIT 1');
            $tenantStmt->execute([':tenant_id' => $tenantId]);
            $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($tenant)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Tenant not found', 'request_id' => request_id()]);
                return;
            }

            $expectedEntryModuleId = (string)($seed['entry_module_id'] ?? '');
            $tenantEntryModuleId = (string)($tenant['entry_module_id'] ?? '');
            if ($expectedEntryModuleId !== '' && $tenantEntryModuleId !== $expectedEntryModuleId) {
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Seed data is only available for matching tenant entry modules',
                    'expected_entry_module_id' => $expectedEntryModuleId,
                    'entry_module_id' => $tenantEntryModuleId,
                    'request_id' => request_id(),
                ]);
                return;
            }

            $seedPath = (string)($seed['path'] ?? '');
            if ($seedPath === '' || !is_file($seedPath)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Seed file is not available', 'request_id' => request_id()]);
                return;
            }

            $sync = syncTenantMigrationsForTenant($tenantId, $tenantEntryModuleId !== '' ? $tenantEntryModuleId : null);
            if (empty($sync['ok'])) {
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Tenant migrations failed to synchronize before seeding',
                    'details' => $sync['error'] ?? 'Unknown error',
                    'request_id' => request_id(),
                ]);
                return;
            }

            $tenantDb = app()->reconnectDbForTenant($tenantId);
            if (!$tenantDb instanceof PDO) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Tenant DB is not configured', 'request_id' => request_id()]);
                return;
            }

            $seedSql = (string)file_get_contents($seedPath);
            if (trim($seedSql) === '') {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Seed file is empty', 'request_id' => request_id()]);
                return;
            }

            kernelExecuteSqlStatements($tenantDb, $seedSql);

            $counts = [];
            foreach ((array)($seed['counts'] ?? []) as $key => $query) {
                $statement = $tenantDb->query((string)$query);
                if (!$statement instanceof PDOStatement) {
                    $errorInfo = $tenantDb->errorInfo();
                    throw new RuntimeException('Seed count query failed for ' . (string)$key . ': ' . (string)($errorInfo[2] ?? 'Unknown SQL error'));
                }
                $counts[(string)$key] = (int)($statement->fetchColumn() ?: 0);
            }

            write_log('Tenant seed data applied', 'info', [
                'tenant_id' => $tenantId,
                'tenant_key' => (string)($tenant['tenant_key'] ?? ''),
                'seed_id' => $seedId,
                'request_id' => request_id(),
                'counts' => $counts,
            ]);

            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode([
                'ok' => true,
                'tenant_id' => $tenantId,
                'seed_id' => $seedId,
                'label' => (string)($seed['label'] ?? $seedId),
                'counts' => $counts,
                'request_id' => request_id(),
            ]);
        } catch (Throwable $e) {
            write_log('apiTenantSeedData failed: ' . $e->getMessage(), 'error', [
                'tenant_id' => $tenantId,
                'seed_id' => $seedId,
                'request_id' => request_id(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'Failed to run tenant seed data',
                'details' => $e->getMessage(),
                'request_id' => request_id(),
            ]);
        }
    }
}

if (!function_exists('kernelHandleApiTenantAdminEmailPush')) {
    function kernelHandleApiTenantAdminEmailPush(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $adminEmail = trim((string)($input['admin_email'] ?? ''));
        if ($tenantId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id is required']);
            return;
        }
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'A valid admin_email is required']);
            return;
        }

        try {
            $ctrlStmt = app()->controlDb()->prepare(
                'UPDATE kernel_tenants SET admin_email = :e, updated_at = NOW() WHERE id = :tid'
            );
            $ctrlStmt->execute([':e' => $adminEmail, ':tid' => $tenantId]);

            $pushed = [];
            $skipped = [];
            $tDb = app()->dbForTenant($tenantId);
            if ($tDb !== null) {
                try {
                    $check = $tDb->prepare('SELECT id, email FROM cms_users WHERE role IN (:r1, :r2) ORDER BY id ASC LIMIT 1');
                    $check->execute([':r1' => 'superadmin', ':r2' => 'administrator']);
                    $admin = $check->fetch(PDO::FETCH_ASSOC);
                    if ($admin) {
                        if ($admin['email'] === $adminEmail) {
                            $pushed[] = 'cms_users';
                        } else {
                            $r = $tDb->prepare('UPDATE cms_users SET email = :e WHERE id = :id LIMIT 1');
                            $r->execute([':e' => $adminEmail, ':id' => $admin['id']]);
                            $pushed[] = 'cms_users';
                        }
                    } else {
                        $skipped[] = 'cms_users:no_matching_row';
                    }
                } catch (Throwable $ex) {
                    $msg = $ex->getMessage();
                    if (strpos($msg, '1146') !== false || stripos($msg, 'Base table or view not found') !== false) {
                        $skipped[] = 'cms_users';
                    } elseif (strpos($msg, '1062') !== false || stripos($msg, 'Duplicate entry') !== false) {
                        adminViewCacheInvalidate(['admin:view:tenants']);
                        echo json_encode(['ok' => false, 'error' => 'That email is already used by another account in this tenant\'s CMS. Choose a different email or update the existing user directly.']);
                        return;
                    } else {
                        write_log('apiTenantAdminEmailPush cms_users failed: ' . $msg, 'error', [
                            'tenant_id' => $tenantId, 'request_id' => request_id(),
                        ]);
                        $skipped[] = 'cms_users';
                    }
                }

                try {
                    $check = $tDb->prepare('SELECT id, email FROM gm_users WHERE role = :r AND deleted_at IS NULL ORDER BY id ASC LIMIT 1');
                    $check->execute([':r' => 'admin']);
                    $admin = $check->fetch(PDO::FETCH_ASSOC);
                    if ($admin) {
                        if ($admin['email'] === $adminEmail) {
                            $pushed[] = 'gm_users';
                        } else {
                            $r = $tDb->prepare('UPDATE gm_users SET email = :e WHERE id = :id LIMIT 1');
                            $r->execute([':e' => $adminEmail, ':id' => $admin['id']]);
                            $pushed[] = 'gm_users';
                        }
                    } else {
                        $skipped[] = 'gm_users:no_matching_row';
                    }
                } catch (Throwable $ex) {
                    $msg = $ex->getMessage();
                    if (strpos($msg, '1146') === false && stripos($msg, 'Base table or view not found') === false) {
                        write_log('apiTenantAdminEmailPush gm_users failed: ' . $msg, 'error', [
                            'tenant_id' => $tenantId, 'request_id' => request_id(),
                        ]);
                    }
                    $skipped[] = 'gm_users';
                }

                // Update wms_users
                try {
                    $check = $tDb->prepare('SELECT id, email FROM wms_users WHERE role = :r ORDER BY id ASC LIMIT 1');
                    $check->execute([':r' => 'admin']);
                    $admin = $check->fetch(PDO::FETCH_ASSOC);
                    if ($admin) {
                        if ($admin['email'] === $adminEmail) {
                            $pushed[] = 'wms_users';
                        } else {
                            $r = $tDb->prepare('UPDATE wms_users SET email = :e WHERE id = :id LIMIT 1');
                            $r->execute([':e' => $adminEmail, ':id' => $admin['id']]);
                            $pushed[] = 'wms_users';
                        }
                    } else {
                        $skipped[] = 'wms_users:no_matching_row';
                    }
                } catch (Throwable $ex) {
                    $msg = $ex->getMessage();
                    if (strpos($msg, '1146') === false && stripos($msg, 'Base table or view not found') === false) {
                        write_log('apiTenantAdminEmailPush wms_users failed: ' . $msg, 'error', [
                            'tenant_id' => $tenantId, 'request_id' => request_id(),
                        ]);
                    }
                    $skipped[] = 'wms_users';
                }

                // Update users table
                try {
                    $check = $tDb->prepare('SELECT id, email FROM users WHERE role IN (:r1, :r2) ORDER BY id ASC LIMIT 1');
                    $check->execute([':r1' => 'admin', ':r2' => 'superadmin']);
                    $admin = $check->fetch(PDO::FETCH_ASSOC);
                    if ($admin) {
                        if ($admin['email'] === $adminEmail) {
                            $pushed[] = 'users';
                        } else {
                            $r = $tDb->prepare('UPDATE users SET email = :e WHERE id = :id LIMIT 1');
                            $r->execute([':e' => $adminEmail, ':id' => $admin['id']]);
                            $pushed[] = 'users';
                        }
                    } else {
                        $skipped[] = 'users:no_matching_row';
                    }
                } catch (Throwable $ex) {
                    $msg = $ex->getMessage();
                    if (strpos($msg, '1146') === false && stripos($msg, 'Base table or view not found') === false) {
                        write_log('apiTenantAdminEmailPush users failed: ' . $msg, 'error', [
                            'tenant_id' => $tenantId, 'request_id' => request_id(),
                        ]);
                    }
                    $skipped[] = 'users';
                }
            } else {
                $skipped[] = 'tenant_db_not_configured';
            }

            adminViewCacheInvalidate(['admin:view:tenants']);
            echo json_encode([
                'ok' => true,
                'pushed' => $pushed,
                'skipped' => $skipped,
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to update admin email']);
        }
    }
}

if (!function_exists('kernelHandleApiTenantAdminPasswordPush')) {
    function kernelHandleApiTenantAdminPasswordPush(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $newPassword = (string)($input['admin_password'] ?? '');

        if ($tenantId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id is required']);
            return;
        }
        if (strlen($newPassword) < 6) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Password must be at least 6 characters']);
            return;
        }

        try {
            $pushed = [];
            $skipped = [];
            $tDb = app()->dbForTenant($tenantId);
            if ($tDb !== null) {
                $hashMsg = password_hash($newPassword, PASSWORD_BCRYPT);

                // Manifest-driven push: every enabled module that declares
                // auth_owned in its manifest gets the new password applied to
                // its declared admin role(s) in its declared users table.
                // This replaces the previous hardcoded per-module blocks.
                $authOwned = function_exists('kernelAuthOwnedModules')
                    ? kernelAuthOwnedModules()
                    : [];

                foreach ($authOwned as $moduleId => $spec) {
                    $table     = (string)$spec['users_table'];
                    $pwdCol    = (string)$spec['password_column'];
                    $activeCol = (string)$spec['active_column'];
                    $deletedCol = $spec['deleted_column'] ?? null;
                    $roles     = (array)$spec['admin_roles'];
                    $touch     = !empty($spec['touch_updated_at']);

                    try {
                        $setParts = ['`' . $pwdCol . '` = :p'];
                        if ($touch) {
                            $setParts[] = '`updated_at` = NOW()';
                        }

                        $whereParts = [];
                        $params = [':p' => $hashMsg];

                        // Build role IN (...) clause with named placeholders.
                        $rolePlaceholders = [];
                        foreach (array_values($roles) as $idx => $role) {
                            $ph = ':r' . $idx;
                            $rolePlaceholders[] = $ph;
                            $params[$ph] = $role;
                        }
                        $whereParts[] = '`role` IN (' . implode(', ', $rolePlaceholders) . ')';

                        if ($activeCol !== '') {
                            $whereParts[] = '`' . $activeCol . '` = 1';
                        }
                        if ($deletedCol !== null && $deletedCol !== '') {
                            $whereParts[] = '`' . $deletedCol . '` IS NULL';
                        }

                        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $setParts)
                            . ' WHERE ' . implode(' AND ', $whereParts);

                        $stmt = $tDb->prepare($sql);
                        $stmt->execute($params);

                        if ($stmt->rowCount() > 0) {
                            $pushed[] = $table;
                        } else {
                            $skipped[] = $table . ':no_matching_row';
                        }
                    } catch (Throwable $ex) {
                        $msg = $ex->getMessage();
                        if (strpos($msg, '1146') === false && stripos($msg, 'Base table or view not found') === false) {
                            write_log('apiTenantAdminPasswordPush ' . $table . ' failed: ' . $msg, 'error', [
                                'tenant_id' => $tenantId, 'request_id' => request_id(),
                                'module_id' => $moduleId,
                            ]);
                        }
                        $skipped[] = $table;
                    }
                }

                // Legacy `users` table fallback — kept outside the manifest
                // loop because the kernel installer table is not module-owned
                // and its column shape varies (`password_hash` vs `password`).
                try {
                    $r = $tDb->prepare('UPDATE users SET password_hash = :p WHERE role IN (:r1, :r2)');
                    $r->execute([':p' => $hashMsg, ':r1' => 'admin', ':r2' => 'superadmin']);
                    if ($r->rowCount() > 0) {
                        $pushed[] = 'users';
                    } else {
                        $skipped[] = 'users:no_matching_row';
                    }
                } catch (Throwable $ex) {
                    $msg = $ex->getMessage();
                    if (strpos($msg, '1146') === false && stripos($msg, 'Base table or view not found') === false) {
                        try {
                            $r = $tDb->prepare('UPDATE users SET password = :p WHERE role IN (:r1, :r2)');
                            $r->execute([':p' => $hashMsg, ':r1' => 'admin', ':r2' => 'superadmin']);
                            if ($r->rowCount() > 0) {
                                $pushed[] = 'users(password)';
                            } else {
                                $skipped[] = 'users:no_matching_row';
                            }
                        } catch (Throwable $e2) {
                            write_log('apiTenantAdminPasswordPush users fallback failed: ' . $e2->getMessage(), 'error', [
                                'tenant_id' => $tenantId, 'request_id' => request_id(),
                            ]);
                            $skipped[] = 'users';
                        }
                    } else {
                        $skipped[] = 'users';
                    }
                }
            } else {
                $skipped[] = 'tenant_db_not_configured';
            }

            adminViewCacheInvalidate(['admin:view:tenants']);
            echo json_encode([
                'ok' => true,
                'pushed' => array_values(array_unique($pushed)),
                'skipped' => array_values(array_unique($skipped)),
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to update admin password']);
        }
    }
}

if (!function_exists('kernelHandleApiTenantDelete')) {
    function kernelHandleApiTenantDelete(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest()) {
            return;
        }

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id is required']);
            return;
        }

        $pdo = app()->controlDb();
        try {
            $chk = $pdo->prepare('SELECT id FROM kernel_tenants WHERE id = :tid LIMIT 1');
            $chk->execute([':tid' => $tenantId]);
            if (!$chk->fetch()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Tenant not found']);
                return;
            }

            $pdo->beginTransaction();
            foreach ([
                'DELETE FROM kernel_tenant_module_access_requests WHERE tenant_id = :tid',
                'DELETE FROM kernel_tenant_module_entitlements WHERE tenant_id = :tid',
                'DELETE FROM kernel_tenant_db_connections WHERE tenant_id = :tid',
                'DELETE FROM kernel_tenant_domains WHERE tenant_id = :tid',
                'DELETE FROM kernel_tenants WHERE id = :tid',
            ] as $sql) {
                $pdo->prepare($sql)->execute([':tid' => $tenantId]);
            }
            $pdo->commit();
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to delete tenant']);
        }
    }
}

if (!function_exists('kernelHandleApiAiSettingsGet')) {
    function kernelHandleApiAiSettingsGet(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest(false)) {
            return;
        }

        $settings = getModuleSettings('ai');
        if (!is_array($settings)) {
            $settings = [];
        }

        $apiKey = trim((string)($settings['openai_api_key'] ?? ''));
        $maskedApiKey = $apiKey !== '' ? ('***' . substr($apiKey, -4)) : '';

        $groqKey = trim((string)($settings['groq_api_key'] ?? ''));
        $maskedGroqKey = $groqKey !== '' ? ('***' . substr($groqKey, -4)) : '';

        $searchKey = trim((string)($settings['search_grounding_api_key'] ?? ''));
        $maskedSearchKey = $searchKey !== '' ? ('***' . substr($searchKey, -4)) : '';

        echo json_encode([
            'ok' => true,
            'settings' => [
                'provider' => (string)($settings['provider'] ?? 'openai'),
                'tier' => (string)($settings['tier'] ?? 'free'),
                'openai_model_free' => (string)($settings['openai_model_free'] ?? 'gpt-4o-mini'),
                'openai_model_paid' => (string)($settings['openai_model_paid'] ?? 'gpt-4o'),
                'openai_model' => (string)($settings['openai_model'] ?? ''),
                'ollama_base_url' => (string)($settings['ollama_base_url'] ?? 'http://localhost:11434'),
                'ollama_model_free' => (string)($settings['ollama_model_free'] ?? 'llama3.2:3b'),
                'ollama_model_paid' => (string)($settings['ollama_model_paid'] ?? 'llama3.1:8b'),
                'ollama_model' => (string)($settings['ollama_model'] ?? ''),
                'groq_model_free' => (string)($settings['groq_model_free'] ?? 'llama-3.1-8b-instant'),
                'groq_model_paid' => (string)($settings['groq_model_paid'] ?? 'llama-3.3-70b-versatile'),
                'groq_model' => (string)($settings['groq_model'] ?? ''),
                'openai_api_key_masked' => $maskedApiKey,
                'openai_api_key_set' => $apiKey !== '',
                'groq_api_key_masked' => $maskedGroqKey,
                'groq_api_key_set' => $groqKey !== '',
                'search_grounding_provider' => (string)($settings['search_grounding_provider'] ?? ''),
                'search_grounding_key_masked' => $maskedSearchKey,
                'search_grounding_key_set' => $searchKey !== '',
                'search_grounding_max_results' => max(1, min(10, (int)($settings['search_grounding_max_results'] ?? 5))),
            ],
        ]);
    }
}

if (!function_exists('kernelHandleApiAiSettingsSave')) {
    function kernelHandleApiAiSettingsSave(): void
    {
        if (!kernelPrepareTenantAdminJsonRequest(true)) {
            return;
        }

        $input = app()->input();
        $settingsIn = $input['settings'] ?? null;
        if (!is_array($settingsIn)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'settings is required']);
            return;
        }

        $oldSettings = getModuleSettings('ai');
        if (!is_array($oldSettings)) {
            $oldSettings = [];
        }
        $newSettings = $oldSettings;

        if (array_key_exists('provider', $settingsIn)) {
            $provider = trim((string)$settingsIn['provider']);
            if (in_array($provider, ['openai', 'ollama', 'groq'], true)) {
                $newSettings['provider'] = $provider;
            }
        }
        if (array_key_exists('tier', $settingsIn)) {
            $tier = trim((string)$settingsIn['tier']);
            if (in_array($tier, ['free', 'paid', 'custom'], true)) {
                $newSettings['tier'] = $tier;
            }
        }

        foreach (['openai_model_free', 'openai_model_paid', 'openai_model', 'ollama_base_url', 'ollama_model_free', 'ollama_model_paid', 'ollama_model', 'groq_model_free', 'groq_model_paid', 'groq_model'] as $key) {
            if (array_key_exists($key, $settingsIn)) {
                $newSettings[$key] = trim((string)$settingsIn[$key]);
            }
        }

        if (array_key_exists('openai_api_key', $settingsIn)) {
            $openAiApiKey = trim((string)$settingsIn['openai_api_key']);
            if ($openAiApiKey !== '') {
                $newSettings['openai_api_key'] = $openAiApiKey;
            }
        }

        if (array_key_exists('groq_api_key', $settingsIn)) {
            $groqApiKey = trim((string)$settingsIn['groq_api_key']);
            if ($groqApiKey !== '') {
                $newSettings['groq_api_key'] = $groqApiKey;
            }
        }

        if (array_key_exists('search_grounding_provider', $settingsIn)) {
            $searchProvider = trim((string)$settingsIn['search_grounding_provider']);
            if (in_array($searchProvider, ['', 'brave', 'tavily', 'serper'], true)) {
                $newSettings['search_grounding_provider'] = $searchProvider;
            }
        }
        if (array_key_exists('search_grounding_api_key', $settingsIn)) {
            $searchKey = trim((string)$settingsIn['search_grounding_api_key']);
            if ($searchKey !== '') {
                $newSettings['search_grounding_api_key'] = $searchKey;
            }
        }
        if (array_key_exists('search_grounding_max_results', $settingsIn)) {
            $newSettings['search_grounding_max_results'] = max(1, min(10, (int)$settingsIn['search_grounding_max_results']));
        }

        saveModuleSettings('ai', $newSettings);
        adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform']);

        echo json_encode(['ok' => true]);
    }
}
if (!function_exists('kernelHandleApiListModules')) {
function kernelHandleApiListModules(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $cacheKey = 'api:list-modules:v1';
    $cached = adminViewCacheGet($cacheKey, $user);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }

    $all = discoverModules();
    $list = [];
    foreach ($all as $m) {
        $capsBlock = is_array($m['capabilities'] ?? null) ? $m['capabilities'] : [];
        $dependsList = [];
        if (!empty($capsBlock)) {
            $capCheck = validateModuleCapabilities($m);
            if (!empty($capCheck['ok'])) {
                $dependsList = array_values($capCheck['depends'] ?? []);
            }
        }
        $modSettings = getModuleSettings((string)($m['id'] ?? ''));
        $list[] = [
            'id' => $m['id'],
            'name' => $m['name'] ?? $m['id'],
            'version' => $m['version'] ?? '0.0.0',
            'description' => $m['description'] ?? '',
            'author' => $m['author'] ?? '',
            'enabled' => !empty($m['_enabled']),
            'depends' => $dependsList,
            'settings_fields' => is_array($m['settings_fields'] ?? null) ? array_values($m['settings_fields']) : [],
            'settings' => is_array($modSettings) ? $modSettings : [],
        ];
    }

    $payload = ['ok' => true, 'modules' => $list];
    adminViewCacheSet($cacheKey, $payload, ['admin:view:modules', 'admin:view:platform'], $user);
    echo json_encode($payload);
    exit;
}
}

if (!function_exists('kernelHandleApiModulesHealth')) {
function kernelHandleApiModulesHealth(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $cacheKey = 'api:modules-health:v1';
    $cached = adminViewCacheGet($cacheKey, $user);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }

    $all = discoverModules();
    $enabled = getEnabledModules();
    $skipped = getSkippedModules();
    $out = [];
    foreach ($all as $m) {
        if (!is_array($m)) {
            continue;
        }
        $moduleId = (string)($m['id'] ?? '');
        if ($moduleId === '') {
            continue;
        }

        $capCheck = validateModuleCapabilities($m);
        $capOk = !empty($capCheck['ok']);
        $capError = $capOk ? null : (string)($capCheck['error'] ?? 'Invalid capability manifest');
        $depends = ($capOk && is_array($capCheck['depends'] ?? null)) ? array_values($capCheck['depends']) : [];
        $missing = [];
        if ($capOk && !empty($depends)) {
            foreach ($depends as $capId) {
                if (is_string($capId) && $capId !== '' && !app()->capabilities()->has($capId)) {
                    $missing[] = $capId;
                }
            }
        }

        $ownsTables = is_array($m['owns_tables'] ?? null) ? array_values($m['owns_tables']) : [];
        $readsTables = is_array($m['reads_tables'] ?? null) ? array_values($m['reads_tables']) : [];
        $requiresTables = is_array($m['requires_tables'] ?? null) ? array_values($m['requires_tables']) : [];
        $usesLegacyRequiresTables = empty($ownsTables) && !empty($requiresTables);

        $settings = getModuleSettings($moduleId);
        $allowKernelAdmin = (bool)(is_array($settings) ? ($settings['allow_kernel_admin'] ?? false) : false);
        $editableSettingsFields = moduleEditableSettingsFields($m);
        $settingsContextNotice = null;

        // Compute entity authority UI indicators
        $entitiesOwned = [];
        if (!empty($m['entities']) && is_array($m['entities'])) {
            foreach ($m['entities'] as $eType => $eDef) {
                if (!empty($eDef['authority']) && $eDef['authority'] === true) {
                    $entitiesOwned[] = $eType;
                }
            }
        }
        if (empty($editableSettingsFields) && !empty($m['settings_fields']) && moduleTenantSettingsModeEnabled()) {
            $settingsContextNotice = 'Feature settings are managed by the Superadmin on the tenant domain.';
        }

        $out[] = [
            'id' => $moduleId,
            'enabled' => !empty($m['_enabled']),
            'loadable' => isset($enabled[$moduleId]),
            'skip_reason' => $skipped[$moduleId]['reason'] ?? null,
            'skip_context' => $skipped[$moduleId]['context'] ?? null,
            'version' => (string)($m['version'] ?? '0.0.0'),
            'capability_manifest_ok' => $capOk,
            'capability_manifest_error' => $capError,
            'capability_depends' => $depends,
            'capability_missing_depends' => $missing,
            'uses_legacy_requires_tables' => $usesLegacyRequiresTables,
            'owns_tables_count' => count($ownsTables),
            'reads_tables_count' => count($readsTables),
            'allow_kernel_admin' => $allowKernelAdmin,
            'settings_fields' => $editableSettingsFields,
            'settings_context_notice' => $settingsContextNotice,
            'settings' => is_array($settings) ? $settings : [],
        ];
    }

    $payload = [
        'ok' => true,
        'modules' => $out,
        'skipped_modules' => array_values($skipped),
        'request_id' => request_id(),
    ];
    adminViewCacheSet($cacheKey, $payload, ['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities'], $user);
    echo json_encode($payload);
    exit;
}
}

if (!function_exists('kernelHandleApiTenantsList')) {
function kernelHandleApiTenantsList(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $cacheKey = 'api:tenants-list:v1';
    $cached = adminViewCacheGet($cacheKey, $user);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }

    try {
        $tStmt = app()->controlDb()->query(
            'SELECT id, tenant_key, status, entry_module_id, admin_email, created_at, updated_at '
            . 'FROM kernel_tenants '
            . 'ORDER BY id DESC'
        );
        $tenants = $tStmt ? ($tStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $dStmt = app()->controlDb()->query(
            'SELECT tenant_id, domain FROM kernel_tenant_domains ORDER BY tenant_id ASC, domain ASC'
        );
        $domainsRows = $dStmt ? ($dStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $domainsByTenant = [];
        foreach ($domainsRows as $dr) {
            if (!is_array($dr)) continue;
            $tid = (int)($dr['tenant_id'] ?? 0);
            $dom = strtolower(trim((string)($dr['domain'] ?? '')));
            if ($tid <= 0 || $dom === '') continue;
            if (!isset($domainsByTenant[$tid])) $domainsByTenant[$tid] = [];
            $domainsByTenant[$tid][] = $dom;
        }

        $cStmt = app()->controlDb()->query(
            'SELECT tenant_id, db_host, db_name, db_user, db_pass, db_pass_ciphertext, db_pass_iv, db_pass_tag '
            . 'FROM kernel_tenant_db_connections'
        );
        $connRows = $cStmt ? ($cStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $connByTenant = [];
        foreach ($connRows as $cr) {
            if (!is_array($cr)) continue;
            $tid = (int)($cr['tenant_id'] ?? 0);
            if ($tid <= 0) continue;
            $connByTenant[$tid] = $cr;
        }

        $entryModuleOptions = listTenantEntryModuleOptions();
        $out = [];
        foreach ($tenants as $t) {
            if (!is_array($t)) continue;
            $tid = (int)($t['id'] ?? 0);
            $conn = $connByTenant[$tid] ?? null;
            $dbConfigured = false;
            $dbInfo = null;
            if (is_array($conn)) {
                $dbConfigured = !empty($conn['db_host']) && !empty($conn['db_name']) && !empty($conn['db_user'])
                    && (
                        !empty($conn['db_pass_ciphertext'])
                        || !empty($conn['db_pass'])
                        || (!empty($conn['db_pass_iv']) && !empty($conn['db_pass_tag']))
                    );

                if (!empty($conn['db_host']) || !empty($conn['db_name']) || !empty($conn['db_user'])) {
                    $dbInfo = [
                        'db_host' => (string)($conn['db_host'] ?? ''),
                        'db_name' => (string)($conn['db_name'] ?? ''),
                        'db_user' => (string)($conn['db_user'] ?? ''),
                    ];
                }
            }

            $out[] = [
                'id' => $tid,
                'tenant_key' => (string)($t['tenant_key'] ?? ''),
                'status' => (string)($t['status'] ?? 'active'),
                'entry_module_id' => $t['entry_module_id'] !== null ? (string)$t['entry_module_id'] : null,
                'admin_email' => $t['admin_email'] !== null ? (string)$t['admin_email'] : null,
                'domains' => array_values(array_unique($domainsByTenant[$tid] ?? [])),
                'db_configured' => $dbConfigured,
                'db' => $dbInfo,
            ];
        }

        $payload = [
            'ok' => true,
            'tenants' => $out,
            'entry_module_options' => $entryModuleOptions,
            'request_id' => request_id(),
        ];
        adminViewCacheSet($cacheKey, $payload, ['admin:view:tenants', 'admin:view:platform'], $user);
        echo json_encode($payload);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to load tenants']);
    }
    exit;
}
}

if (!function_exists('kernelHandleApiListCapabilities')) {
function kernelHandleApiListCapabilities(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    $role = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
        exit;
    }

    $cacheKey = 'api:list-capabilities:v2';
    $cached = adminViewCacheGet($cacheKey, $user);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }

    $catalog = new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities());
    $payload = [
        'ok' => true,
        'summary' => $catalog->summary(),
        'modules' => $catalog->modules(),
        'events' => $catalog->events(),
        'capabilities' => $catalog->inspectAll(),
        'request_id' => request_id(),
    ];
    adminViewCacheSet($cacheKey, $payload, ['admin:view:capabilities', 'admin:view:platform'], $user);
    echo json_encode($payload);
    exit;
}
}

if (!function_exists('kernelHandleApiKernelEventsList')) {
function kernelHandleApiKernelEventsList(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    $role = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
        exit;
    }

    $catalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(
        app()->db(),
        new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities())
    );
    echo json_encode([
        'ok' => true,
        'summary' => $catalog->summary(),
        'events' => $catalog->events(),
        'request_id' => request_id(),
    ]);
    exit;
}
}

if (!function_exists('kernelHandleApiKernelTriggersList')) {
function kernelHandleApiKernelTriggersList(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    $role = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
        exit;
    }

    $catalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(
        app()->db(),
        new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities())
    );
    echo json_encode([
        'ok' => true,
        'summary' => $catalog->summary(),
        'triggers' => $catalog->triggers(),
        'request_id' => request_id(),
    ]);
    exit;
}
}

if (!function_exists('kernelHandleApiKernelTriggerExecutionsList')) {
function kernelHandleApiKernelTriggerExecutionsList(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    $role = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
        exit;
    }

    $catalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(
        app()->db(),
        new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities())
    );
    $filters = [
        'module' => $_GET['module'] ?? null,
        'event_key' => $_GET['event_key'] ?? null,
        'capability_id' => $_GET['capability_id'] ?? null,
        'status' => $_GET['status'] ?? null,
        'correlation_id' => $_GET['correlation_id'] ?? null,
        'request_id' => $_GET['request_id'] ?? null,
        'external_reference' => $_GET['external_reference'] ?? null,
        'trigger_id' => $_GET['trigger_id'] ?? null,
    ];
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

    echo json_encode([
        'ok' => true,
        'summary' => $catalog->summary(),
        'executions' => $catalog->executions($filters, $limit),
        'request_id' => request_id(),
    ]);
    exit;
}
}

if (!function_exists('kernelHandleApiKernelTriggerSave')) {
function kernelHandleApiKernelTriggerSave(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    $role = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
        exit;
    }

    app()->csrfEnforce();

    $input = app()->input();
    $module = trim((string)($input['module'] ?? ''));
    $eventKey = trim((string)($input['event_key'] ?? ''));
    $capId = trim((string)($input['capability_id'] ?? ''));
    $provider = isset($input['provider']) ? trim((string)$input['provider']) : null;
    $isEnabled = !empty($input['is_enabled']);

    $priority = isset($input['priority']) ? (int)$input['priority'] : null;
    $template = isset($input['template']) ? (string)$input['template'] : null;
    $maxPerMinute = ($input['max_per_minute'] ?? null);
    $maxPerMinute = ($maxPerMinute === '' || $maxPerMinute === null) ? null : (int)$maxPerMinute;
    $retryCount = isset($input['retry_count']) ? (int)$input['retry_count'] : null;
    $timeoutMs = isset($input['timeout_ms']) ? (int)$input['timeout_ms'] : null;

    $meta = $input['meta'] ?? null;
    if ($meta !== null && !is_array($meta)) {
        $meta = null;
    }

    if ($module === '' || $eventKey === '' || $capId === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'module, event_key, capability_id are required']);
        exit;
    }

    $updatedBy = (int)($user['id'] ?? 0);
    $ok = kernelTriggerSave(
        $module,
        $eventKey,
        $capId,
        $isEnabled,
        $template,
        $meta,
        $updatedBy,
        $priority,
        $maxPerMinute,
        $retryCount,
        $timeoutMs,
        $provider
    );

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save trigger']);
        exit;
    }

    adminViewCacheInvalidate(['admin:view:platform']);
    echo json_encode(['ok' => true]);
    exit;
}
}

if (!function_exists('kernelHandleApiKernelTriggerDelete')) {
function kernelHandleApiKernelTriggerDelete(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    $role = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
        exit;
    }

    app()->csrfEnforce();

    $input = app()->input();
    $triggerId = isset($input['id']) ? (int)$input['id'] : 0;
    if ($triggerId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'id is required']);
        exit;
    }

    try {
        $stmt = app()->db()->prepare('DELETE FROM kernel_event_triggers WHERE id = ?');
        $stmt->execute([$triggerId]);
        adminViewCacheInvalidate(['admin:view:platform']);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to delete trigger']);
    }
    exit;
}
}

if (!function_exists('kernelHandleApiKernelTriggersSuggest')) {
function kernelHandleApiKernelTriggersSuggest(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    $role = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
        exit;
    }

    // Review-first: suggestions are not saved; only returned to UI.
    app()->csrfEnforce();

    $input = app()->input();
    $module = trim((string)($input['module'] ?? ''));
    $eventKey = trim((string)($input['event_key'] ?? ''));
    if ($module === '' || $eventKey === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'module and event_key are required']);
        exit;
    }

    // Load event registry row (optional but preferred)
    $eventRow = [
        'module' => $module,
        'event_key' => $eventKey,
        'description' => '',
        'available_vars' => [],
    ];
    try {
        $stmt = app()->db()->prepare('SELECT module, event_key, description, available_vars FROM kernel_events WHERE module = ? AND event_key = ? LIMIT 1');
        $stmt->execute([$module, $eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $eventRow['description'] = (string)($row['description'] ?? '');
            $eventRow['available_vars'] = !empty($row['available_vars']) ? (json_decode((string)$row['available_vars'], true) ?: []) : [];
        }
    } catch (Throwable $e) {
        // non-fatal
    }

    // Load existing triggers for that event
    $existing = [];
    try {
        $stmt = app()->db()->prepare(
            'SELECT id, module, event_key, capability_id, provider, is_enabled, priority, template, max_per_minute, retry_count, timeout_ms, meta '
            . 'FROM kernel_event_triggers '
            . 'WHERE module = ? AND event_key = ? '
            . 'ORDER BY priority ASC, id ASC'
        );
        $stmt->execute([$module, $eventKey]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($existing as &$t) {
            if (!is_array($t)) continue;
            $t['is_enabled'] = (int)($t['is_enabled'] ?? 0);
            $t['priority'] = (int)($t['priority'] ?? 100);
            $t['max_per_minute'] = $t['max_per_minute'] !== null ? (int)$t['max_per_minute'] : null;
            $t['retry_count'] = (int)($t['retry_count'] ?? 0);
            $t['timeout_ms'] = (int)($t['timeout_ms'] ?? 5000);
            $t['meta'] = !empty($t['meta']) ? (json_decode((string)$t['meta'], true) ?: []) : [];
        }
        unset($t);
    } catch (Throwable $e) {
    }

    // Available capabilities: ids only (lightweight)
    // Guardrail: only pass trigger-safe capabilities to the model.
    // (Avoids nonsense suggestions like kernel.auth.require@1.)
    $availableCaps = [];
    $allowedCaps = [
        'sms.send@1',
        'email.send@1',
        'kernel.audit.record@1',
    ];
    try {
        $allCaps = app()->capabilities()->capabilityIds();
        $availableCaps = [];
        foreach ($allowedCaps as $c) {
            if (in_array($c, $allCaps, true)) {
                $availableCaps[] = $c;
            }
        }
    } catch (Throwable $e) {
    }

    // Call AI capability provider
    try {
        $res = app()->cap()->call('ai.capability.suggest@1', [
            'event' => $eventRow,
            'existing_triggers' => $existing,
            'available_capabilities' => $availableCaps,
        ], ['caller' => '_kernel']);

        if (!is_array($res)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'AI provider returned invalid result']);
            exit;
        }

        if (empty($res['ok'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'AI suggestion failed')]);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'suggestions' => $res['suggestions'] ?? [],
            'provider' => $res['provider'] ?? null,
            'allowed_capabilities' => $availableCaps,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'AI capability call failed']);
    }
    exit;
}
}

if (!function_exists('kernelHandleApiCapabilityMetrics')) {
function kernelHandleApiCapabilityMetrics(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    $metrics = load_capability_cache('capability_metrics.json');
    echo json_encode(['ok' => true, 'metrics' => $metrics, 'request_id' => request_id()]);
    exit;
}
}

if (!function_exists('kernelHandleApiCapabilityBreakers')) {
function kernelHandleApiCapabilityBreakers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    $breakers = load_capability_cache('capability_breakers.json');
    echo json_encode(['ok' => true, 'breakers' => $breakers, 'request_id' => request_id()]);
    exit;
}
}

if (!function_exists('kernelHandleApiCapabilityBreakersReset')) {
function kernelHandleApiCapabilityBreakersReset(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    $input = app()->input();
    $capabilityId = trim((string)($input['capability_id'] ?? ''));
    $providerId = trim((string)($input['provider_id'] ?? ''));
    $breakers = load_capability_cache('capability_breakers.json');
    $cleared = 0;
    if ($capabilityId !== '' && $providerId !== '') {
        $key = $capabilityId . '|' . $providerId;
        if (isset($breakers[$key])) {
            unset($breakers[$key]);
            $cleared = 1;
        }
    } else {
        $cleared = is_array($breakers) ? count($breakers) : 0;
        $breakers = [];
    }
    save_capability_cache('capability_breakers.json', $breakers);
    echo json_encode(['ok' => true, 'cleared' => $cleared, 'request_id' => request_id()]);
    exit;
}
}

if (!function_exists('kernelHandleApiCacheHealth')) {
function kernelHandleApiCacheHealth(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $tenantInfo = \Ikabud\Kernel\TenantResolver::controlHostCacheMetrics();
    $cacheStats = app()->cache()->getStats();

    echo json_encode([
        'ok' => true,
        'cache' => $cacheStats,
        'tenant_host_lookup_cache' => $tenantInfo,
        'request_id' => request_id(),
        'generated_at' => gmdate('c'),
    ]);
    exit;
}
}

if (!function_exists('kernelHandleApiCacheClear')) {
function kernelHandleApiCacheClear(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    app()->csrfEnforce();

    $result = app()->cache()->clearAll();
    $tenantHostCache = ['memory_cleared' => 0, 'apcu_cleared' => 0];
    if (class_exists('Ikabud\\Kernel\\TenantResolver') && method_exists('Ikabud\\Kernel\\TenantResolver', 'clearControlHostCache')) {
        $tenantHostCache = \Ikabud\Kernel\TenantResolver::clearControlHostCache();
    }

    echo json_encode([
        'ok' => true,
        'cleared' => (int)($result['cleared'] ?? 0),
        'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
        'tenant_host_lookup_cache' => $tenantHostCache,
        'request_id' => request_id(),
        'generated_at' => gmdate('c'),
    ]);
    exit;
}
}

if (!function_exists('kernelHandleApiUpdateCapabilityPolicy')) {
function kernelHandleApiUpdateCapabilityPolicy(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

        app()->csrfEnforce();

    $input = app()->input();
    $providerId = trim((string)($input['provider_module_id'] ?? ''));
    $capabilityId = trim((string)($input['capability_id'] ?? ''));
    $allowCallers = $input['allow_callers'] ?? [];

    if ($providerId === '' || $capabilityId === '' || !is_array($allowCallers)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'provider_module_id, capability_id and allow_callers[] are required']);
        exit;
    }

    $result = updateModuleCapabilityPolicy($providerId, $capabilityId, $allowCallers);
    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Failed to update capability policy']);
        exit;
    }

    adminViewCacheInvalidate(['admin:view:capabilities', 'admin:view:platform', 'admin:view:modules']);
    echo json_encode(['ok' => true] + $result + ['request_id' => request_id()]);
    exit;
}
}

if (!function_exists('kernelHandleApiUpdateModuleDepends')) {
function kernelHandleApiUpdateModuleDepends(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

        app()->csrfEnforce();

    $input = app()->input();
    $moduleId = trim((string)($input['module_id'] ?? ''));
    $depends = $input['depends'] ?? [];

    if ($moduleId === '' || !is_array($depends)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'module_id and depends[] are required']);
        exit;
    }

    $result = updateModuleCapabilityDepends($moduleId, $depends);
    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Failed to update capability dependencies']);
        exit;
    }

    adminViewCacheInvalidate(['admin:view:capabilities', 'admin:view:platform', 'admin:view:modules']);
    echo json_encode(['ok' => true] + $result + ['request_id' => request_id()]);
    exit;
}
}

if (!function_exists('kernelHandleApiInstallModule')) {
function kernelHandleApiInstallModule(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

        app()->csrfEnforce();

    $upload = $_FILES['module_zip'] ?? null;
    if (!is_array($upload)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error_code' => 'upload_missing', 'error' => 'Upload a zip file as module_zip', 'request_id' => request_id()]);
        exit;
    }

    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds server upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds form MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by a PHP extension',
        ];
        $msg = $uploadErrors[$uploadError] ?? 'Upload failed';
        http_response_code(422);
        echo json_encode(['ok' => false, 'error_code' => 'upload_failed', 'error' => $msg, 'request_id' => request_id()]);
        exit;
    }

    $tmpPath = (string)($upload['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        write_log('Module install rejected', 'warning', [
            'source' => 'apiInstallModule',
            'error_code' => 'upload_tmp_missing',
            'actor_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? null,
        ]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error_code' => 'upload_tmp_missing', 'error' => 'Uploaded file is not available on disk', 'request_id' => request_id()]);
        exit;
    }

    if (function_exists('is_uploaded_file') && !is_uploaded_file($tmpPath)) {
        write_log('Module install rejected', 'warning', [
            'source' => 'apiInstallModule',
            'error_code' => 'upload_not_http_upload',
            'actor_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? null,
        ]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error_code' => 'upload_not_http_upload', 'error' => 'Upload did not arrive through the HTTP upload pipeline', 'request_id' => request_id()]);
        exit;
    }

    $uploadName = (string)($upload['name'] ?? 'unknown.zip');
    $uploadSize = (int)($upload['size'] ?? 0);
    if ($uploadSize <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error_code' => 'upload_empty', 'error' => 'Uploaded zip is empty', 'request_id' => request_id()]);
        exit;
    }

    $ext = strtolower((string)pathinfo($uploadName, PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        write_log('Module install rejected', 'warning', [
            'source' => 'apiInstallModule',
            'error_code' => 'upload_invalid_extension',
            'upload_name' => $uploadName,
            'upload_size' => $uploadSize,
            'actor_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? null,
        ]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error_code' => 'upload_invalid_extension', 'error' => 'Only .zip module packages are supported', 'request_id' => request_id()]);
        exit;
    }

    $uploadMime = '';
    if (class_exists('finfo')) {
        try {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $uploadMime = (string)$finfo->file($tmpPath);
        } catch (Throwable $ignored) {
            $uploadMime = '';
        }
    }

    $allowedZipMimes = [
        'application/zip',
        'application/x-zip',
        'application/x-zip-compressed',
        'application/octet-stream',
    ];
    if ($uploadMime !== '' && !in_array($uploadMime, $allowedZipMimes, true)) {
        write_log('Module install rejected', 'warning', [
            'source' => 'apiInstallModule',
            'error_code' => 'upload_invalid_mime',
            'upload_name' => $uploadName,
            'upload_size' => $uploadSize,
            'upload_mime' => $uploadMime,
            'actor_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? null,
        ]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error_code' => 'upload_invalid_mime', 'error' => 'Uploaded file MIME type is not a ZIP archive', 'request_id' => request_id()]);
        exit;
    }

    write_log('Module install requested', 'info', [
        'source' => 'apiInstallModule',
        'upload_name' => $uploadName,
        'upload_size' => $uploadSize,
        'upload_mime' => $uploadMime,
        'actor_id' => $user['id'] ?? null,
        'actor_role' => $user['role'] ?? null,
    ]);

    $result = installModuleFromZip($tmpPath);

    if (!empty($result['ok']) && is_array($result['manifest'] ?? null)) {
        $moduleInstallPath = modulesPath() . '/' . trim((string)($result['module_id'] ?? ''));
        $catalogOk = registerApprovedModuleCatalogInstall(
            $result['manifest'],
            $moduleInstallPath,
            $tmpPath,
            [
                'source' => 'admin_install',
                'approved_by_user_id' => (int)($user['id'] ?? 0),
            ]
        );
        if (!$catalogOk) {
            write_log('Module catalog registration failed after install', 'warning', [
                'source' => 'apiInstallModule',
                'module_id' => $result['module_id'] ?? null,
                'actor_id' => $user['id'] ?? null,
            ]);
        }
    }

    write_log(
        'Module install completed',
        !empty($result['ok']) ? 'info' : 'warning',
        [
            'source' => 'apiInstallModule',
            'upload_name' => $uploadName,
            'upload_size' => $uploadSize,
            'module_id' => $result['module_id'] ?? null,
            'enabled' => $result['enabled'] ?? null,
            'ok' => !empty($result['ok']),
            'error' => $result['error'] ?? null,
            'actor_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? null,
        ]
    );

    if (!empty($result['ok'])) {
        adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
    }
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result + ['request_id' => request_id()]);
    exit;
}
}

if (!function_exists('kernelHandleApiEnableModule')) {
function kernelHandleApiEnableModule(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

        app()->csrfEnforce();

    $modInput = app()->input();
    $modId = trim((string)($modInput['module_id'] ?? ''));
    $allMods = discoverModules();
    if (!isset($allMods[$modId])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Module not found']);
        exit;
    }

    // Enable-time capability validation: refuse enabling modules with unsatisfied required capabilities.
    $capCheck = validateModuleCapabilities($allMods[$modId]);
    if (empty($capCheck['ok'])) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error_code' => 'manifest_invalid_capabilities',
            'error' => $capCheck['error'] ?? 'Invalid capability manifest',
            'request_id' => request_id(),
        ]);
        exit;
    }
    $missing = [];
    foreach (($capCheck['depends'] ?? []) as $capId) {
        if (!app()->capabilities()->has((string)$capId)) {
            $missing[] = (string)$capId;
        }
    }
    if (!empty($missing)) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error_code' => 'missing_capability_providers',
            'error' => 'Missing required capability providers',
            'missing' => $missing,
            'request_id' => request_id(),
        ]);
        exit;
    }

    if (moduleTenantSettingsModeEnabled()) {
        $eTenantId = moduleTenantSettingsTenantId();
        if ($eTenantId !== null) {
            enableModuleForTenant($modId, $eTenantId);
        } else {
            // No tenant resolved (main domain): write directly to global registry.
            $eReg = readModuleRegistry();
            $eReg[$modId] = array_merge($eReg[$modId] ?? [], ['enabled' => true, 'enabled_at' => date('Y-m-d H:i:s')]);
            writeModuleRegistry($eReg);
            kernelFlushCodeCaches();
        }
    } else {
        enableModule($modId);
    }
    adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
    echo json_encode(['ok' => true, 'module_id' => $modId, 'enabled' => true, 'request_id' => request_id()]);
    exit;
}
}

if (!function_exists('kernelHandleApiDisableModule')) {
function kernelHandleApiDisableModule(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

        app()->csrfEnforce();

    $modInput = app()->input();
    $modId = trim((string)($modInput['module_id'] ?? ''));
    $allMods = discoverModules();
    if (!isset($allMods[$modId])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Module not found']);
        exit;
    }
    if (moduleTenantSettingsModeEnabled()) {
        $dTenantId = moduleTenantSettingsTenantId();
        if ($dTenantId !== null) {
            disableModuleForTenant($modId, $dTenantId);
        } else {
            // No tenant resolved (main domain): write directly to global registry.
            $dReg = readModuleRegistry();
            $dReg[$modId] = array_merge($dReg[$modId] ?? [], ['enabled' => false, 'disabled_at' => date('Y-m-d H:i:s')]);
            writeModuleRegistry($dReg);
            kernelFlushCodeCaches();
        }
    } else {
        disableModule($modId);
    }
    adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
    echo json_encode(['ok' => true, 'module_id' => $modId, 'enabled' => false]);
    exit;
}
}

if (!function_exists('kernelHandleApiUpdateModuleSettings')) {
function kernelHandleApiUpdateModuleSettings(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    // Kernel-enforced CSRF (this endpoint is called from the browser)
    app()->csrfEnforce();

    $input = app()->input();
    $modId = trim((string)($input['module_id'] ?? ''));
    $settingsIn = $input['settings'] ?? null;
    if ($modId === '' || !is_array($settingsIn)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'module_id and settings are required']);
        exit;
    }

    $allMods = discoverModules();
    if (!isset($allMods[$modId])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Module not found']);
        exit;
    }

    $oldSettings = getModuleSettings($modId);
    $newSettings = $oldSettings;

    $manifest = $allMods[$modId] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
    $allowedKeys = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $key = trim((string)($field['key'] ?? ''));
        if ($key !== '') {
            $allowedKeys[$key] = $field;
        }
    }

    if (array_key_exists('allow_kernel_admin', $settingsIn)) {
        $newSettings['allow_kernel_admin'] = (bool)$settingsIn['allow_kernel_admin'];
    }

    foreach ($allowedKeys as $key => $field) {
        if (!array_key_exists($key, $settingsIn)) {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $raw = $settingsIn[$key];

        if ($type === 'checkbox' || $type === 'bool' || $type === 'boolean') {
            $newSettings[$key] = (bool)$raw;
            continue;
        }

        if ($type === 'number' || $type === 'int' || $type === 'integer') {
            $newSettings[$key] = (string)(0 + (float)$raw);
            continue;
        }

        if ($type === 'select' && is_array($field['options'] ?? null)) {
            $allowedValues = [];
            foreach ($field['options'] as $opt) {
                if (is_string($opt)) {
                    $allowedValues[$opt] = true;
                } elseif (is_array($opt) && array_key_exists('value', $opt)) {
                    $allowedValues[(string)$opt['value']] = true;
                }
            }
            $val = (string)$raw;
            if (!empty($allowedValues) && !isset($allowedValues[$val])) {
                continue;
            }
            $newSettings[$key] = $val;
            continue;
        }

        $newSettings[$key] = trim((string)$raw);
    }

    $tenantScopedKeys = array_values(array_filter(array_keys($settingsIn), static function ($key) {
        return $key !== 'allow_kernel_admin';
    }));

    if (moduleTenantSettingsModeEnabled() && moduleTenantSettingsTenantId() === null && !empty($tenantScopedKeys)) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'This module\'s settings are tenant-scoped. Open the tenant domain and configure them there.',
            'module_id' => $modId,
            'keys' => $tenantScopedKeys,
        ]);
        exit;
    }

    // allow_kernel_admin is a kernel-lifecycle key — always write to global registry
    // regardless of tenant mode so it is never silently dropped.
    if (array_key_exists('allow_kernel_admin', $settingsIn)) {
        $akaReg = readModuleRegistry();
        $akaReg[$modId]['settings'] = array_merge($akaReg[$modId]['settings'] ?? [], [
            'allow_kernel_admin' => (bool)$settingsIn['allow_kernel_admin'],
        ]);
        writeModuleRegistry($akaReg);
        // Remove from $newSettings so saveModuleSettings() doesn't attempt it again.
        unset($newSettings['allow_kernel_admin']);
    }

    // Only call saveModuleSettings for remaining (tenant-scoped) keys.
    if (!empty(array_diff_key($newSettings, $oldSettings)) || !empty($tenantScopedKeys)) {
        saveModuleSettings($modId, $newSettings);
    }

    // Best-effort audit log
    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module' => '_kernel',
            'action' => 'module.settings.update',
            'entity_type' => 'module',
            'entity_id' => $modId,
            'old_data' => ['settings' => $oldSettings],
            'new_data' => ['settings' => $newSettings],
        ], ['mode' => 'first']);
    } catch (Throwable $e) {
        // ignore
    }

    adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform']);
    echo json_encode(['ok' => true, 'module_id' => $modId, 'settings' => $newSettings]);
    exit;
}
}

if (!function_exists('kernelHandleApiAdminCreateUser')) {
function kernelHandleApiAdminCreateUser(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $input    = app()->input();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $fullName = trim((string)($input['full_name'] ?? ''));
    $role     = (string)($input['role'] ?? 'viewer');
    $branchId = (int)($input['branch_id'] ?? 0);

    if ($username === '' || $password === '' || $fullName === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'All fields required']);
        exit;
    }

    if (!in_array($role, ['admin', 'superadmin', 'manager', 'viewer'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid role']);
        exit;
    }

    // Kernel OS users are limited to kernel-managed roles only.

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        app()->db()->prepare(
            'INSERT INTO users (username, password_hash, full_name, role) VALUES (:u, :p, :n, :r)'
        )->execute([':u' => $username, ':p' => $hash, ':n' => $fullName, ':r' => $role]);

        $newUserId = (int)app()->db()->lastInsertId();

        echo json_encode(['ok' => true, 'user_id' => $newUserId]);
    } catch (Throwable $e) {
        write_log('kernel admin create user failed: ' . $e->getMessage(), 'error', [
            'username' => $username,
            'role' => $role,
        ]);
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Username already exists']);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to create user']);
        }
    }
    exit;
}
}

if (!function_exists('kernelHandleApiAdminUpdateUser')) {
function kernelHandleApiAdminUpdateUser(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $input    = app()->input();
    $editId   = (int)($input['user_id'] ?? 0);
    $fullName = trim((string)($input['full_name'] ?? ''));
    $role     = (string)($input['role'] ?? '');
    $isActive = (int)($input['is_active'] ?? 1);
    $password = (string)($input['password'] ?? '');
    $branchId = (int)($input['branch_id'] ?? 0);

    if (!$editId || $fullName === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid input']);
        exit;
    }

    try {
        // Prevent role changes for the currently logged-in admin.
        $currentUserId = (int) ($user['id'] ?? 0);
        if ($currentUserId === $editId) {
            $dbRoleStmt = app()->db()->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
            $dbRoleStmt->execute([':id' => $editId]);
            $dbRole = (string) ($dbRoleStmt->fetchColumn() ?: '');
            if ($dbRole !== '') {
                $role = $dbRole;
            }
        }

        if (!in_array($role, ['admin', 'superadmin', 'manager', 'viewer'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid role']);
            exit;
        }

        $sql = 'UPDATE users SET full_name = :name, role = :role, is_active = :active';
        $bind = [':name' => $fullName, ':role' => $role, ':active' => $isActive, ':id' => $editId];

        if ($password !== '') {
            $sql .= ', password_hash = :pass, token_version = COALESCE(token_version, 0) + 1';
            $bind[':pass'] = password_hash($password, PASSWORD_BCRYPT);
        }
        $sql .= ' WHERE id = :id';

        app()->db()->prepare($sql)->execute($bind);

        // user_branches is no longer kernel-managed (daily-ledger owns branch assignments)

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to update user']);
    }
    exit;
}
}

if (!function_exists('kernelHandleApiAdminUpdateProfile')) {
function kernelHandleApiAdminUpdateProfile(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    try {
        $user = app()->user();
        if (!$user || !in_array($user['role'] ?? '', ['admin', 'superadmin'], true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        if (isset($input['csrf_token']) && !isset($input['_token'])) {
            $_POST['_token'] = (string)$input['csrf_token'];
        }
        app()->csrfEnforce();

        $fullName = trim((string)($input['full_name'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $editId   = (int)($user['id'] ?? 0);

        if ($editId <= 0 || $fullName === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid input']);
            exit;
        }

        $sql = 'UPDATE users SET full_name = :name';
        $bind = [':name' => $fullName, ':id' => $editId];
        if ($password !== '') {
            $sql .= ', password_hash = :pass, token_version = COALESCE(token_version, 0) + 1';
            $bind[':pass'] = password_hash($password, PASSWORD_BCRYPT);
        }
        $sql .= ' WHERE id = :id AND role = \'admin\'';
        $stmt = app()->db()->prepare($sql);
        $stmt->execute($bind);
        $affected = (int)$stmt->rowCount();

        if ($affected <= 0) {
            app()->log('apiAdminUpdateProfile updated 0 rows', 'warning', [
                'user_id' => $editId,
            ]);
        }

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['full_name'] = $fullName;
            $_SESSION['user']['name'] = $fullName;
        }

        // Refresh kernel cached user (App caches currentUser per request)
        app()->setUser(array_merge((array)$user, [
            'full_name' => $fullName,
            'name' => $fullName,
        ]));
        app()->csrfRotate(true);

        // Re-issue auth cookie JWT so subsequent page loads show updated name.
        $newPayload = (array)$user;
        $newPayload['name'] = $fullName;
        $newPayload['full_name'] = $fullName;

        // Preserve tenant_id binding in re-issued token
        $resolvedTid = app()->tenant()->current();
        if ($resolvedTid !== null && !isset($newPayload['tenant_id'])) {
            $newPayload['tenant_id'] = $resolvedTid;
        }

        $newToken = app()->jwt()->generate($newPayload);
        $cookieName = config('app.cookie_name', 'app_token');
        $expiry = time() + (int) config('app.jwt.expiration', 86400);
        setcookie($cookieName, $newToken, [
            'expires' => $expiry,
            'path' => '/',
            'httponly' => true,
            'secure' => is_https(),
            'samesite' => config('cookie.samesite', 'Strict'),
        ]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        app()->log('apiAdminUpdateProfile failed: ' . $e->getMessage(), 'error', [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to update profile']);
    }
    exit;
}
}

if (!function_exists('kernelHandleApiPlatform')) {
function kernelHandleApiPlatform(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $cacheKey = 'api:platform:v2';
    $cached = adminViewCacheGet($cacheKey, $user);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }

    if (kernelUpdatesAutoSyncOnPlatformEnabled()) {
        kernelUpdatesMaybeAutoSync($user);
    }

    $platformId = app()->platformIdentity();
    $skippedModules = array_values(getSkippedModules());
    $routeAmbiguityMode = (string) config('app.modules.route_ambiguity_mode', 'warn');

    // Modules
    $allModules = discoverModules();
    $enabledModules = [];
    $disabledModules = [];
    foreach ($allModules as $m) {
        $entry = [
            'id' => (string)($m['id'] ?? ''),
            'name' => (string)($m['name'] ?? $m['id'] ?? ''),
            'version' => (string)($m['version'] ?? '0.0.0'),
            'status' => $m['status'] ?? null,
            'requires_kernel' => $m['requires_kernel'] ?? null,
        ];
        if (!empty($m['_enabled'])) {
            $enabledModules[] = $entry;
        } else {
            $disabledModules[] = $entry;
        }
    }

    $capabilityCatalog = new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities(), $allModules);
    $integrationCatalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(app()->db(), $capabilityCatalog);

    // Capabilities (count only for summary — full list via /api/v1/admin/capabilities)
    $capSummary = [];
    foreach ($capabilityCatalog->inspectAll() as $capability) {
        $capSummary[] = [
            'id' => (string)($capability['id'] ?? ''),
            'provider_count' => (int)($capability['provider_count'] ?? 0),
            'declared_provider_count' => (int)($capability['declared_provider_count'] ?? 0),
            'runtime_registered' => !empty($capability['runtime_registered']),
            'effective_schema_mode' => $capability['effective_schema_mode'] ?? null,
        ];
    }
    $integrationSummary = $integrationCatalog->summary();
    $eventsCount = (int)($integrationSummary['event_count'] ?? 0);
    $triggersTotal = (int)($integrationSummary['trigger_count'] ?? 0);
    $triggersEnabled = (int)($integrationSummary['active_trigger_count'] ?? 0);

    // Health summary + per-capability health
    $health = app()->cap()->healthAll();
    $healthSummary = [
        'total_calls' => 0,
        'total_errors' => 0,
        'breakers_open' => 0,
    ];
    $healthByCapability = [];
    foreach ($health as $h) {
        $healthSummary['total_calls'] += (int)($h['count'] ?? 0);
        $healthSummary['total_errors'] += (int)($h['errors'] ?? 0);
        if (!empty($h['breaker_open'])) {
            $healthSummary['breakers_open']++;
        }
        $healthByCapability[] = $h;
    }

    // Glossary — plain-English labels for capabilities/events/terms
    $glossary = app()->glossary();

    $recentExecutions = $integrationCatalog->executions([], 20);
    $traces = array_map(static function (array $execution): array {
        $status = trim((string)($execution['status'] ?? 'unknown'));

        return [
            '_timestamp' => $execution['created_at'] ?? '',
            'ok' => $status === 'success',
            'status' => $status,
            'event' => $execution['event_key'] ?? '',
            'capability' => $execution['resolved_capability'] ?? ($execution['capability_id'] ?? ''),
            'capability_id' => $execution['capability_id'] ?? '',
            'trigger_id' => $execution['trigger_id'] ?? null,
            'correlation_id' => $execution['correlation_id'] ?? null,
            'request_id' => $execution['request_id'] ?? null,
            'external_reference' => $execution['external_reference'] ?? null,
            'duration_ms' => $execution['duration_ms'] ?? 0,
            'module' => $execution['module'] ?? '',
            'error' => $execution['error_message'] ?? null,
        ];
    }, $recentExecutions);
    $traceTimelines = $integrationCatalog->timelines([], 8, 80);

    $payload = [
        'ok' => true,
        'platform' => $platformId,
        'updates' => kernelUpdatesBuildSummary(),
        'modules' => [
            'enabled_count' => count($enabledModules),
            'disabled_count' => count($disabledModules),
            'skipped_count' => count($skippedModules),
            'enabled' => $enabledModules,
            'disabled' => $disabledModules,
            'skipped' => $skippedModules,
        ],
        'capabilities' => [
            'count' => count($capSummary),
            'entries' => $capSummary,
        ],
        'events' => ['count' => $eventsCount],
        'triggers' => [
            'total' => $triggersTotal,
            'enabled' => $triggersEnabled,
            'executions' => (int)($integrationSummary['trigger_execution_count'] ?? 0),
            'timelines' => count($traceTimelines),
        ],
        'traces' => $traces,
        'trace_timelines' => $traceTimelines,
        'glossary' => $glossary,
        'health' => $healthSummary,
        'runtime' => [
            'route_ambiguity_mode' => $routeAmbiguityMode,
            'skipped_modules_count' => count($skippedModules),
        ],
        'request_id' => request_id(),
        'generated_at' => gmdate('c'),
    ];
    adminViewCacheSet($cacheKey, $payload, ['admin:view:platform', 'admin:view:modules', 'admin:view:capabilities'], $user);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
}
