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

        if ($enforceCsrf) {
            app()->csrfEnforce();
        }

        return true;
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
                    $check = $tDb->prepare('SELECT id, email FROM cms_users WHERE role = :r ORDER BY id ASC LIMIT 1');
                    $check->execute([':r' => 'administrator']);
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