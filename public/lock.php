<?php

declare(strict_types=1);

/**
 * Ikabud Kernel APP OS — Web Installer
 *
 * Creates database, runs schema migration, seeds admin user + branch,
 * generates secure .env, and locks itself.
 *
 * SECURITY: Delete this file after installation.
 */

// ── Guard: already installed ────────────────────────────────────────────
$installLock = __DIR__ . '/../storage/.installed';
if (is_file($installLock) && !is_link($installLock)) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ikabud — Already Installed</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:32px;max-width:420px;width:100%;text-align:center}
        h1{font-size:20px;font-weight:700;color:#1a202c;margin-bottom:8px}
        h1 span{color:#2563eb}
        p{color:#718096;font-size:14px;margin-bottom:20px}
        .btn{display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;font-weight:600;font-size:14px;text-decoration:none;margin:4px}
        .btn-ghost{background:#f1f5f9;color:#374151}
        .small{font-size:11px;color:#a0aec0;margin-top:16px}
    </style>
</head>
<body>
<div class="card">
    <h1><span>Ikabud</span> Kernel APP OS</h1>
    <p>The application is already installed and running.</p>
    <a class="btn" href="/">Open App &rarr;</a>
    <a class="btn btn-ghost" href="/login">Login</a>
    <p class="small">To reinstall, remove <code>storage/.installed</code> first.</p>
</div>
</body>
</html>
<?php
    exit;
}

$errors  = [];
$success = false;
$envPath = __DIR__ . '/../.env';
$templateEnv = installerReadExistingEnv(__DIR__ . '/../.env.example');
$existingEnv = installerReadExistingEnv($envPath);

/**
 * Remove control characters/newlines that can break .env structure.
 */
function installerEnvSanitizeValue(string $value): string
{
    $value = str_replace(["\r", "\n", "\0"], '', $value);
    return trim($value);
}

/**
 * Keep only host[:port] characters to avoid header injection in APP_URL.
 */
function installerSanitizeHost(string $host): string
{
    $host = trim($host);
    if ($host === '') {
        return 'localhost';
    }
    if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
        return 'localhost';
    }
    return $host;
}

/**
 * Parse existing .env file into key/value map (best-effort).
 */
function installerReadExistingEnv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $env = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if ($k === '') {
            continue;
        }
        $env[$k] = installerEnvSanitizeValue((string)$v);
    }

    return $env;
}

function installerStorageBootstrapDirs(): void
{
    $dirs = [
        __DIR__ . '/../storage',
        __DIR__ . '/../storage/backups',
        __DIR__ . '/../storage/cache',
        __DIR__ . '/../storage/cache/disyl',
        __DIR__ . '/../storage/logs',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}

function installerShouldCarryForwardEnvKey(string $key): bool
{
    $upper = strtoupper(trim($key));
    if ($upper === '') {
        return false;
    }

    if (in_array($upper, [
        'APP_COOKIE_NAME',
        'OPENAI_API_KEY',
        'OPENAI_MODEL',
        'GROQ_API_KEY',
        'OLLAMA_BASE_URL',
        'SMS_PROVIDER',
        'SMS_API_KEY',
        'SMS_API_SECRET',
        'SMS_SENDER_NAME',
    ], true)) {
        return false;
    }

    foreach (['SMTP_', 'MAIL_'] as $prefix) {
        if (str_starts_with($upper, $prefix)) {
            return false;
        }
    }

    if (preg_match('/(?:PASSWORD|PASS|SECRET|TOKEN|API_KEY|API_SECRET|PRIVATE_KEY)$/', $upper) === 1) {
        return false;
    }

    return true;
}

function installerBuildEnvLines(array $existingEnv, array $templateEnv, array $managed): array
{
    $orderedKeys = array_values(array_unique(array_merge(
        array_keys($templateEnv),
        array_keys($managed),
        array_keys($existingEnv)
    )));

    $lines = [];
    foreach ($orderedKeys as $key) {
        if (array_key_exists($key, $managed)) {
            $lines[] = $key . '=' . installerEnvSanitizeValue((string)$managed[$key]);
            continue;
        }

        if (array_key_exists($key, $existingEnv) && installerShouldCarryForwardEnvKey($key)) {
            $lines[] = $key . '=' . installerEnvSanitizeValue((string)$existingEnv[$key]);
            continue;
        }

        if (array_key_exists($key, $templateEnv)) {
            $lines[] = $key . '=' . installerEnvSanitizeValue((string)$templateEnv[$key]);
        }
    }

    return $lines;
}

function installerApplyRuntimeEnv(array $env): void
{
    foreach ($env as $key => $value) {
        $key = trim((string)$key);
        if ($key === '') {
            continue;
        }

        $value = installerEnvSanitizeValue((string)$value);
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function installerBootstrapApplication(array $runtimeEnv): void
{
    installerStorageBootstrapDirs();
    installerApplyRuntimeEnv($runtimeEnv);

    $bootstrappedConfig = require __DIR__ . '/../bootstrap.php';
    if (is_array($bootstrappedConfig)) {
        $GLOBALS['config'] = $bootstrappedConfig;
    }
    require_once __DIR__ . '/../src/helpers/module-manager.php';
}

function installerTableExists(PDO $db, string $tableName): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
    );
    $stmt->execute([':table' => $tableName]);
    return (bool)$stmt->fetchColumn();
}

function installerSeedModuleAdmins(PDO $db, string $adminUsername, string $adminEmail, string $adminName, string $passwordHash): void
{
    if (installerTableExists($db, 'dl_admins')) {
        $stmt = $db->prepare(
            'INSERT INTO dl_admins (username, password_hash, full_name, is_active) '
            . 'VALUES (:username, :password_hash, :full_name, 1) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'password_hash = VALUES(password_hash), '
            . 'full_name = VALUES(full_name), '
            . 'is_active = 1, '
            . 'updated_at = NOW()'
        );
        $stmt->execute([
            ':username' => $adminUsername,
            ':password_hash' => $passwordHash,
            ':full_name' => $adminName,
        ]);
    }

    if (installerTableExists($db, 'cms_users')) {
        if ($adminUsername !== 'cmsadmin' || $adminEmail !== 'admin@cms.local') {
            $db->prepare("DELETE FROM cms_users WHERE username = 'cmsadmin' AND email = 'admin@cms.local' AND role = 'superadmin'")
                ->execute();
        }

        $stmt = $db->prepare(
            'INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active) '
            . 'VALUES (:username, :email, :password_hash, :display_name, :role, 1) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'email = VALUES(email), '
            . 'password_hash = VALUES(password_hash), '
            . 'display_name = VALUES(display_name), '
            . 'role = VALUES(role), '
            . 'is_active = 1, '
            . 'updated_at = NOW()'
        );
        $stmt->execute([
            ':username' => $adminUsername,
            ':email' => $adminEmail,
            ':password_hash' => $passwordHash,
            ':display_name' => $adminName,
            ':role' => 'administrator',
        ]);
    }

    if (installerTableExists($db, 'gm_users')) {
        $nameParts = preg_split('/\s+/', trim($adminName), 2) ?: [];
        $firstName = trim((string)($nameParts[0] ?? $adminName));
        $lastName = trim((string)($nameParts[1] ?? ''));

        $stmt = $db->prepare(
            'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active) '
            . 'VALUES (:email, :password, :first_name, :last_name, :role, 1) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'password = VALUES(password), '
            . 'first_name = VALUES(first_name), '
            . 'last_name = VALUES(last_name), '
            . 'role = VALUES(role), '
            . 'is_active = 1, '
            . 'updated_at = NOW()'
        );
        $stmt->execute([
            ':email' => $adminEmail,
            ':password' => $passwordHash,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':role' => 'admin',
        ]);
    }
}

function installerRunCurrentMigrationsAndSeeds(PDO $db, PDO $controlDb): array
{
    $results = [
        '_kernel' => [],
        '_control' => [],
        'modules' => [],
    ];

    $appRunner = new \Ikabud\Kernel\Database\MigrationRunner($db);
    $controlRunner = new \Ikabud\Kernel\Database\MigrationRunner($controlDb);

    $results['_kernel'] = $appRunner->migrate('_kernel');
    $results['_control'] = $controlRunner->migrate('_control');

    foreach (getEnabledModules() as $moduleId => $manifest) {
        $migrations = $appRunner->migrate((string)$moduleId);
        $seeds = tenantSyncModuleSeeds($db, (string)$moduleId, is_array($manifest) ? $manifest : null);
        $applied = array_merge($migrations, $seeds);
        if ($applied !== []) {
            $results['modules'][(string)$moduleId] = $applied;
        }
    }

    return $results;
}

// ── AJAX: Test database connection ─────────────────────────────────────────
// Responds to step=test_db POST with JSON {ok, db_exists, message|error}.
// No migrations are run. Blocked automatically when already installed (guard above).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'test_db') {
    header('Content-Type: application/json; charset=utf-8');
    $tHost = installerSanitizeHost((string)($_POST['db_host'] ?? 'localhost'));
    $tPort = max(1, min(65535, (int)($_POST['db_port'] ?? 3306)));
    $tName = trim((string)($_POST['db_name'] ?? ''));
    $tUser = trim((string)($_POST['db_user'] ?? ''));
    $tPass = (string)($_POST['db_pass'] ?? '');
    if ($tUser === '' || $tName === '') {
        echo json_encode(['ok' => false, 'error' => 'Database username and name are required.']);
        exit;
    }
    try {
        $dsnTest = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $tHost, $tPort);
        $testPdo = new PDO($dsnTest, $tUser, $tPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $chkStmt = $testPdo->prepare(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1'
        );
        $chkStmt->execute([$tName]);
        $dbExists = (bool)$chkStmt->fetchColumn();
        echo json_encode([
            'ok'       => true,
            'db_exists' => $dbExists,
            'message'  => $dbExists
                ? 'Connected successfully. Database exists.'
                : 'Connected successfully. Database will be created automatically.',
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'install') {

    // ── Collect & validate input ────────────────────────────────────────
    $dbHost = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $dbPort = trim((string) ($_POST['db_port'] ?? '3306'));
    $dbName = trim((string) ($_POST['db_name'] ?? 'ikabud'));
    $dbUser = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass = (string) ($_POST['db_pass'] ?? '');

    $adminUsername    = trim((string) ($_POST['admin_username'] ?? ''));
    $adminEmail       = trim((string) ($_POST['admin_email'] ?? ''));
    $adminName        = trim((string) ($_POST['admin_name'] ?? ''));
    $adminPass        = (string) ($_POST['admin_pass'] ?? '');
    $adminPassConfirm = (string) ($_POST['admin_pass_confirm'] ?? '');
    $multiTenantEnabled = !empty($_POST['app_multi_tenant_enabled']);
    $controlDbHost = trim((string) ($_POST['control_db_host'] ?? ($existingEnv['CONTROL_DB_HOST'] ?? $dbHost)));
    $controlDbPort = trim((string) ($_POST['control_db_port'] ?? ($existingEnv['CONTROL_DB_PORT'] ?? $dbPort)));
    $controlDbName = trim((string) ($_POST['control_db_name'] ?? ($existingEnv['CONTROL_DB_DATABASE'] ?? $dbName)));
    $controlDbUser = trim((string) ($_POST['control_db_user'] ?? ($existingEnv['CONTROL_DB_USERNAME'] ?? $dbUser)));
    $controlDbPassInput = (string) ($_POST['control_db_pass'] ?? '');
    $controlDbPass = $controlDbPassInput !== ''
        ? $controlDbPassInput
        : (string) ($existingEnv['CONTROL_DB_PASSWORD'] ?? '');

    // When multi-tenant is disabled, force control DB params to mirror primary DB
    if (!$multiTenantEnabled) {
        $controlDbHost = $dbHost;
        $controlDbPort = $dbPort;
        $controlDbName = $dbName;
        $controlDbUser = $dbUser;
        $controlDbPass = $dbPass;
    }
    $controlDbEncKeyInput = trim((string) ($_POST['control_db_enc_key'] ?? ''));
    $controlDbEncKey = $controlDbEncKeyInput !== ''
        ? $controlDbEncKeyInput
        : (string) ($existingEnv['CONTROL_DB_ENC_KEY'] ?? ($templateEnv['CONTROL_DB_ENC_KEY'] ?? ''));

    if ($dbName === '')                $errors[] = 'Database name is required.';
    if ($dbUser === '')                $errors[] = 'Database username is required.';
    if ($adminUsername === '')          $errors[] = 'Admin username is required.';
    if (strlen($adminUsername) < 3)    $errors[] = 'Admin username must be at least 3 characters.';
    if ($adminEmail === '')            $errors[] = 'Admin email is required.';
    if ($adminName === '')             $errors[] = 'Admin full name is required.';
    if (strlen($adminPass) < 8)        $errors[] = 'Admin password must be at least 8 characters.';
    if ($adminPass !== $adminPassConfirm)
                                       $errors[] = 'Passwords do not match.';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $adminUsername))
                                       $errors[] = 'Admin username may only contain letters, numbers, underscore.';
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false)
                                       $errors[] = 'Admin email must be a valid email address.';
    if ($multiTenantEnabled && $controlDbName === '')
                                       $errors[] = 'Control-plane database name is required when multi-tenant mode is enabled.';
    if ($multiTenantEnabled && $controlDbUser === '')
                                       $errors[] = 'Control-plane database username is required when multi-tenant mode is enabled.';
    if ($multiTenantEnabled && $controlDbEncKey === '')
                                       $errors[] = 'CONTROL_DB_ENC_KEY is required when multi-tenant mode is enabled.';

    if ($errors === []) {
        try {
            // ── Connect to MySQL, create DB if needed, then select it ────────────────
            // Connect without dbname first so a missing DB gives a clear error and can
            // be created automatically — no manual cPanel step required.
            $dsnRoot = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbHost, $dbPort);
            $pdo = new PDO($dsnRoot, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                str_replace('`', '', $dbName)
            ));
            $pdo->exec(sprintf('USE `%s`', str_replace('`', '', $dbName)));

            // ── Run schema migration (statement-by-statement for shared hosts) ──
            $schemaFile = __DIR__ . '/../database/migrations/001_full_schema.sql';
            if (!is_file($schemaFile)) {
                throw new RuntimeException('Schema file not found: database/migrations/001_full_schema.sql');
            }

            $schemaSql = file_get_contents($schemaFile);
            // Strip comments and split by semicolons
            $schemaSql = preg_replace('/--.*$/m', '', $schemaSql);
            $statements = array_filter(array_map('trim', explode(';', $schemaSql)));
            foreach ($statements as $stmt) {
                if ($stmt !== '') {
                    try {
                        $pdo->exec($stmt);
                    } catch (Throwable $stmtErr) {
                        // Skip "table already exists" (SQLSTATE 42S01) so re-runs are idempotent.
                        // Any other error is a genuine failure — re-throw with context.
                        if (!str_contains($stmtErr->getMessage(), 'already exists')) {
                            throw new RuntimeException(
                                'Schema migration failed: ' . $stmtErr->getMessage(), 0, $stmtErr
                            );
                        }
                    }
                }
            }

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = installerSanitizeHost((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            $basePath = rtrim(dirname($scriptDir), '/');
            if ($basePath === '.' || $basePath === '/' || $basePath === '') {
                $basePath = '';
            }
            $appUrl = installerEnvSanitizeValue("{$scheme}://{$host}{$basePath}");
            $jwtSecret = bin2hex(random_bytes(32));

            $managedEnv = [
                'APP_ENV' => 'production',
                'APP_DEBUG' => '0',
                'APP_URL' => $appUrl,
                'APP_TIMEZONE' => (string)($existingEnv['APP_TIMEZONE'] ?? $templateEnv['APP_TIMEZONE'] ?? 'Asia/Manila'),
                'APP_COOKIE_SAMESITE' => (string)($existingEnv['APP_COOKIE_SAMESITE'] ?? $templateEnv['APP_COOKIE_SAMESITE'] ?? 'Lax'),
                'DB_HOST' => installerEnvSanitizeValue($dbHost),
                'DB_PORT' => installerEnvSanitizeValue($dbPort),
                'DB_DATABASE' => installerEnvSanitizeValue($dbName),
                'DB_USERNAME' => installerEnvSanitizeValue($dbUser),
                'DB_PASSWORD' => installerEnvSanitizeValue($dbPass),
                'DB_COLLATION' => (string)($existingEnv['DB_COLLATION'] ?? $templateEnv['DB_COLLATION'] ?? 'utf8mb4_unicode_ci'),
                'APP_MULTI_TENANT_ENABLED' => $multiTenantEnabled ? '1' : '0',
                'CONTROL_DB_HOST' => installerEnvSanitizeValue($controlDbHost),
                'CONTROL_DB_PORT' => installerEnvSanitizeValue($controlDbPort),
                'CONTROL_DB_DATABASE' => installerEnvSanitizeValue($controlDbName),
                'CONTROL_DB_USERNAME' => installerEnvSanitizeValue($controlDbUser),
                'CONTROL_DB_PASSWORD' => installerEnvSanitizeValue($controlDbPass),
                'CONTROL_DB_COLLATION' => (string)($existingEnv['CONTROL_DB_COLLATION'] ?? $templateEnv['CONTROL_DB_COLLATION'] ?? 'utf8mb4_unicode_ci'),
                'CONTROL_DB_ENC_KEY' => installerEnvSanitizeValue($controlDbEncKey),
                'JWT_SECRET' => $jwtSecret,
                'JWT_EXPIRATION' => (string)($existingEnv['JWT_EXPIRATION'] ?? $templateEnv['JWT_EXPIRATION'] ?? '86400'),
            ];

            installerBootstrapApplication($managedEnv + $existingEnv + $templateEnv);
            installerRunCurrentMigrationsAndSeeds(app()->db(), app()->controlDb());

            // ── Seed admin user + default branch ────────────────────────
            $pdo->beginTransaction();
            try {
                $passwordHash = password_hash($adminPass, PASSWORD_DEFAULT, ['cost' => 12]);

                if (installerTableExists($pdo, 'dl_branches')) {
                    $pdo->exec("INSERT INTO dl_branches (code, name, is_active) VALUES ('MAIN', 'Main Branch', 1)
                                ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1");
                } elseif (installerTableExists($pdo, 'branches')) {
                    $pdo->exec("INSERT INTO branches (code, name, is_active) VALUES ('MAIN', 'Main Branch', 1)
                                ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1");
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, full_name, role, is_active)
                     VALUES (:username, :password_hash, :full_name, :role, 1)
                     ON DUPLICATE KEY UPDATE
                        password_hash = VALUES(password_hash),
                        full_name     = VALUES(full_name),
                        role          = VALUES(role),
                        is_active     = 1'
                );
                $stmt->execute([
                    ':username'      => $adminUsername,
                    ':password_hash' => $passwordHash,
                    ':full_name'     => $adminName,
                    ':role'          => 'admin',
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            installerSeedModuleAdmins($pdo, $adminUsername, $adminEmail, $adminName, $passwordHash);

            // ── Generate .env with current safe defaults + fresh secrets ─────────────
            $envLines = installerBuildEnvLines($existingEnv, $templateEnv, $managedEnv);
            $env = implode("\n", $envLines) . "\n";

            // Optional backup of previous env before replacing.
            if (is_file($envPath)) {
                $backupDir = __DIR__ . '/../storage/backups';
                if (!is_dir($backupDir)) {
                    @mkdir($backupDir, 0755, true);
                }
                // Protect backup dir from HTTP access on any server configuration
                $htaccessPath = $backupDir . '/.htaccess';
                if (!is_file($htaccessPath)) {
                    @file_put_contents($htaccessPath, "Require all denied\nDeny from all\n");
                    @chmod($htaccessPath, 0644);
                }
                $backupPath = $backupDir . '/env-' . date('Ymd-His') . '.bak';
                @copy($envPath, $backupPath);
                @chmod($backupPath, 0640);
            }

            // Atomic write: temp file + rename prevents partial writes.
            $tmpPath = $envPath . '.tmp.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmpPath, $env, LOCK_EX) === false) {
                throw new RuntimeException('Failed writing temporary .env file');
            }
            @chmod($tmpPath, 0640);
            if (!@rename($tmpPath, $envPath)) {
                @unlink($tmpPath);
                throw new RuntimeException('Failed replacing .env file');
            }
            // Pre-rename @chmod($tmpPath, 0640) is preserved through rename() on POSIX.
            // Do NOT re-chmod to 0644 — that widens permissions and exposes secrets on shared hosting.

            // ── Create storage dirs ─────────────────────────────────────
            installerStorageBootstrapDirs();

            // ── Write install lock ──────────────────────────────────────
            file_put_contents($installLock, date('Y-m-d H:i:s') . "\n");

            // Flush stat cache so the fresh install is picked up immediately.
            // opcache_reset() is intentionally omitted: on shared hosting it resets
            // the opcode cache for ALL other sites under the same user account.
            clearstatcache(true);

            $success = true;

        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ikabud Kernel APP OS — Installer</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',system-ui,sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);width:100%;max-width:440px;padding:32px}
        h1{font-size:20px;font-weight:700;color:#1a202c}
        h1 span{color:#2563eb}
        .sub{font-size:13px;color:#718096;margin-top:2px;margin-bottom:24px}
        .form-group{margin-bottom:14px}
        .form-label{display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px}
        .form-input{width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit}
        .form-input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px #dbeafe}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        hr{border:none;border-top:1px solid #e2e8f0;margin:18px 0}
        .btn{width:100%;padding:11px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
        .btn:hover{background:#1d4ed8}
        .alert{padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
        .alert-error{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
        .alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0}
        .alert ul{margin:0;padding-left:18px}
        .warn{font-size:11px;color:#d97706;margin-top:12px;padding:8px 10px;background:#fffbeb;border-radius:6px;border:1px solid #fef3c7}
        a.open{display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;font-weight:600;font-size:14px;text-decoration:none;margin-top:12px}
    </style>
</head>
<body>
<div class="card">
    <h1><span>Ikabud</span> Kernel APP OS</h1>
    <p class="sub">Application Kernel — Installer</p>

    <?php if ($success): ?>
        <div class="alert alert-success">
            Installation complete!
        </div>
        <div class="warn">
            <strong>Security:</strong> Delete <code>public/lock.php</code> immediately after verifying the app works.
        </div>
        <br>
        <a class="open" href="/login">Go to Login &rarr;</a>
        <br><br>
        <small style="color:#718096;font-size:12px">
            <a href="/" style="color:#2563eb">Open App</a>
            &middot;
            <a href="/superadmin/settings" style="color:#2563eb">Superadmin Settings</a>
        </small>
    <?php else: ?>
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="step" value="install">

            <div class="form-group">
                <label class="form-label">Database Host</label>
                <div class="row">
                    <input name="db_host" class="form-input" placeholder="localhost" value="<?= htmlspecialchars($_POST['db_host'] ?? ($existingEnv['DB_HOST'] ?? 'localhost'), ENT_QUOTES, 'UTF-8') ?>">
                    <input name="db_port" class="form-input" placeholder="3306" value="<?= htmlspecialchars($_POST['db_port'] ?? ($existingEnv['DB_PORT'] ?? '3306'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Database Name</label>
                <input name="db_name" class="form-input" placeholder="ikabud" value="<?= htmlspecialchars($_POST['db_name'] ?? ($existingEnv['DB_DATABASE'] ?? 'ikabud'), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Database Username</label>
                <input name="db_user" class="form-input" value="<?= htmlspecialchars($_POST['db_user'] ?? ($existingEnv['DB_USERNAME'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Database Password</label>
                <input type="password" name="db_pass" class="form-input" id="db_pass">
            </div>
            <div id="db-test-result" style="display:none;font-size:12px;padding:7px 10px;border-radius:6px;margin-bottom:8px"></div>
            <button type="button" class="btn" id="btn-test-db" style="background:#0891b2;margin-bottom:10px" onclick="installerTestDb()">Test Connection</button>

            <hr>

            <div class="form-group">
                <label class="form-label" for="app_multi_tenant_enabled">Multi-Tenant Mode</label>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#4a5568">
                    <input
                        id="app_multi_tenant_enabled"
                        type="checkbox"
                        name="app_multi_tenant_enabled"
                        value="1"
                        <?= !empty($_POST['app_multi_tenant_enabled']) || (empty($_POST) && !empty($existingEnv['APP_MULTI_TENANT_ENABLED'])) ? 'checked' : '' ?>
                    >
                    Enable separate control-plane database settings
                </label>
            </div>
            <div class="form-group">
                <label class="form-label">Control DB Host</label>
                <div class="row">
                    <input name="control_db_host" class="form-input" placeholder="localhost" value="<?= htmlspecialchars($_POST['control_db_host'] ?? ($existingEnv['CONTROL_DB_HOST'] ?? ($existingEnv['DB_HOST'] ?? 'localhost')), ENT_QUOTES, 'UTF-8') ?>">
                    <input name="control_db_port" class="form-input" placeholder="3306" value="<?= htmlspecialchars($_POST['control_db_port'] ?? ($existingEnv['CONTROL_DB_PORT'] ?? ($existingEnv['DB_PORT'] ?? '3306')), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Control DB Name</label>
                <input name="control_db_name" class="form-input" placeholder="control_db" value="<?= htmlspecialchars($_POST['control_db_name'] ?? ($existingEnv['CONTROL_DB_DATABASE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Control DB Username</label>
                <input name="control_db_user" class="form-input" value="<?= htmlspecialchars($_POST['control_db_user'] ?? ($existingEnv['CONTROL_DB_USERNAME'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Control DB Password</label>
                <input type="password" name="control_db_pass" class="form-input">
            </div>
            <div class="form-group">
                <label class="form-label">Control DB Encryption Key</label>
                <input name="control_db_enc_key" class="form-input" placeholder="Required for encrypted tenant DB passwords">
            </div>
            <div class="warn">
                Leave control DB password and encryption key blank to reuse the current `.env` values during reinstall.
            </div>

            <hr>

            <div class="form-group">
                <label class="form-label">Admin Username <span style="color:#9ca3af;font-weight:400">(min 3 chars, letters/numbers/_)</span></label>
                <input name="admin_username" class="form-input" placeholder="e.g. admin" autocomplete="username" value="<?= htmlspecialchars($_POST['admin_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Admin Email</label>
                <input type="email" name="admin_email" class="form-input" autocomplete="email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Admin Full Name</label>
                <input name="admin_name" class="form-input" placeholder="e.g. Jane Smith" autocomplete="name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Admin Password <span style="color:#9ca3af;font-weight:400">(min 8 chars)</span></label>
                <div style="position:relative">
                    <input type="password" name="admin_pass" id="admin_pass" class="form-input" autocomplete="new-password" style="padding-right:40px">
                    <button type="button" onclick="ikTogglePw('admin_pass','eye1')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;font-size:16px" title="Show/hide password" id="eye1">👁️</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <div style="position:relative">
                    <input type="password" name="admin_pass_confirm" id="admin_pass_confirm" class="form-input" autocomplete="new-password" style="padding-right:40px">
                    <button type="button" onclick="ikTogglePw('admin_pass_confirm','eye2')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;font-size:16px" title="Show/hide password" id="eye2">👁️</button>
                </div>
                <div id="pw-match-hint" style="font-size:11px;margin-top:4px"></div>
            </div>

            <button type="submit" class="btn">Install</button>
            <div class="warn">
                <strong>Bluehost / cPanel:</strong> Use <code>localhost</code> as DB host. Create the database via cPanel first if CREATE DATABASE fails.
            </div>
        </form>
    <?php endif; ?>
</div>
<script>
function ikTogglePw(inputId, btnId) {
    var el = document.getElementById(inputId);
    var btn = document.getElementById(btnId);
    if (!el) return;
    if (el.type === 'password') {
        el.type = 'text';
        if (btn) btn.style.opacity = '1';
    } else {
        el.type = 'password';
        if (btn) btn.style.opacity = '0.5';
    }
}
(function () {
    var p = document.getElementById('admin_pass');
    var c = document.getElementById('admin_pass_confirm');
    var hint = document.getElementById('pw-match-hint');
    if (!p || !c || !hint) return;
    function check() {
        if (c.value === '') { hint.textContent = ''; return; }
        if (p.value === c.value) {
            hint.style.color = '#16a34a';
            hint.textContent = '\u2713 Passwords match';
        } else {
            hint.style.color = '#dc2626';
            hint.textContent = '\u2717 Passwords do not match';
        }
    }
    p.addEventListener('input', check);
    c.addEventListener('input', check);
})();
function installerTestDb() {
    var btn = document.getElementById('btn-test-db');
    var res = document.getElementById('db-test-result');
    if (!btn || !res) return;
    btn.disabled = true;
    btn.textContent = 'Testing\u2026';
    res.style.display = 'none';
    var form = btn.closest('form');
    var data = new FormData();
    data.append('step', 'test_db');
    data.append('db_host', (form.querySelector('[name=db_host]') || {value:''}).value);
    data.append('db_port', (form.querySelector('[name=db_port]') || {value:'3306'}).value);
    data.append('db_name', (form.querySelector('[name=db_name]') || {value:''}).value);
    data.append('db_user', (form.querySelector('[name=db_user]') || {value:''}).value);
    data.append('db_pass', (document.getElementById('db_pass') || {value:''}).value);
    fetch(location.pathname, {method: 'POST', body: data})
        .then(function(r) { return r.json(); })
        .then(function(j) {
            res.style.display = 'block';
            if (j.ok) {
                res.style.cssText = 'display:block;font-size:12px;padding:7px 10px;border-radius:6px;margin-bottom:8px;background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0';
                res.textContent = '\u2713 ' + (j.message || 'Connection successful');
            } else {
                res.style.cssText = 'display:block;font-size:12px;padding:7px 10px;border-radius:6px;margin-bottom:8px;background:#fee2e2;color:#dc2626;border:1px solid #fecaca';
                res.textContent = '\u2717 ' + (j.error || 'Connection failed');
            }
        })
        .catch(function(err) {
            res.style.cssText = 'display:block;font-size:12px;padding:7px 10px;border-radius:6px;margin-bottom:8px;background:#fee2e2;color:#dc2626;border:1px solid #fecaca';
            res.textContent = '\u2717 Request failed: ' + err.message;
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = 'Test Connection';
        });
}
</script>
</body>
</html>
