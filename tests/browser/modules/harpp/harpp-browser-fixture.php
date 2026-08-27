<?php

declare(strict_types=1);

/**
 * HARPP decision-inbox browser fixture.
 *
 * Creates (--up) or destroys (--down) a fully isolated HARPP tenant so the
 * Playwright journey never touches a shared/live tenant. The tenant is backed
 * by a dedicated disposable MySQL database, seeded with a uniquely keyed
 * ACKNOWLEDGED decision, and registered only for the duration of the run.
 *
 * State is persisted to the JSON file this script's directory. No secrets are
 * printed; the owner password is supplied via HARPP_BROWSER_OWNER_PASSWORD or
 * a throwaway test-only default.
 */

$root = dirname(__DIR__, 4);
chdir($root);

$mode = (string)($_SERVER['argv'][1] ?? 'up');
$stateFile = __DIR__ . '/harpp-browser-fixture.json';

if ($mode === 'down') {
    teardown($stateFile);
    exit(0);
}

setup($root, $stateFile);
exit(0);

function applyHarppMigrations(string $root, PDO $db): void
{
    $manifest = json_decode((string)file_get_contents($root . '/modules/harpp/module.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ((array)($manifest['migrations'] ?? []) as $migration) {
        $sql = (string)file_get_contents($root . '/modules/harpp/' . $migration);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = preg_split('/;\s*/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                $db->exec($statement);
            }
        }
    }
}

function clearHostCacheViaWeb(string $domain, string $root): void
{
    $tmpFile = $root . '/public/_harpp_clear_host_cache.php';
    $bootstrapPath = var_export($root . '/bootstrap.php', true);
    $code = '<?php require ' . $bootstrapPath . '; \\Ikabud\\Kernel\\TenantResolver::clearControlHostCache(); echo "cache-cleared";';
    file_put_contents($tmpFile, $code, LOCK_EX);
    try {
        $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
        $body = @file_get_contents('http://' . $domain . '/_harpp_clear_host_cache.php', false, $context);
        if ($body === false || !str_contains((string)$body, 'cache-cleared')) {
            fwrite(STDERR, "Host cache clear request did not confirm; falling back to TTL wait.\n");
            sleep(33);
        } else {
            echo "Control-host domain cache cleared.\n";
        }
    } finally {
        @unlink($tmpFile);
    }
}

function loadDotEnv(string $root): void
{
    $envFile = $root . '/.env';
    if (!is_file($envFile)) return;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) continue;
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                $value = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $value);
            }
        }
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

function setup(string $root, string $stateFile): void
{
    global $config;
    require_once $root . '/bootstrap.php';
    require_once $root . '/src/helpers/module-manager.php';
    require_once $root . '/modules/harpp/helpers.php';

    $dbConfig = require $root . '/config/database.php';
    $controlConfig = require $root . '/config/control_database.php';
    $host = (string)$dbConfig['host'];
    $port = (int)($dbConfig['port'] ?? 3306);
    $dbUser = (string)$dbConfig['username'];
    $dbPass = (string)$dbConfig['password'];

    $ownerPassword = (string)(getenv('HARPP_BROWSER_OWNER_PASSWORD') ?: 'HarppBrowser42!X');
    $ownerEmail = 'owner@harpp.local';
    $domain = (string)(getenv('HARPP_BROWSER_DOMAIN') ?: 'applicationos.test');
    if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,253}[a-z0-9]$/', $domain)) {
        throw new RuntimeException('Invalid HARPP_BROWSER_DOMAIN.');
    }

    $dbName = 'harpp_browser_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (!preg_match('/^harpp_browser_[a-z0-9_]+$/', $dbName)) {
        throw new RuntimeException('Unsafe generated database name.');
    }
    $tenantKey = 'harpp-browser-' . getmypid() . '-' . bin2hex(random_bytes(3));
    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,78}[a-z0-9]$/', $tenantKey)) {
        throw new RuntimeException('Unsafe generated tenant key.');
    }

    $pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    $admin = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $dbUser, $dbPass, $pdoOptions);

    $created = [];
    try {
        $admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $created[] = 'database';
        $db = new PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, $pdoOptions);

        applyHarppMigrations($root, $db);
        $created[] = 'migrations';

        $db->exec("CREATE TABLE IF NOT EXISTS `tenant_module_settings` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int unsigned NOT NULL,
            `module_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            `setting_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
            `setting_value` json DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_tenant_module_setting` (`tenant_id`,`module_id`,`setting_key`),
            KEY `idx_tenant_module` (`tenant_id`,`module_id`),
            KEY `idx_module_key` (`module_id`,`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $control = new PDO(
            "mysql:host=" . (string)$controlConfig['host'] . ";port=" . (int)($controlConfig['port'] ?? 3306) . ";dbname=" . (string)$controlConfig['database'] . ";charset=utf8mb4",
            (string)$controlConfig['username'],
            (string)$controlConfig['password'],
            $pdoOptions
        );

        $control->beginTransaction();
        try {
            $stmt = $control->prepare('INSERT INTO kernel_tenants (tenant_key, status, entry_module_id) VALUES (:k, :s, :e)');
            $stmt->execute([':k' => $tenantKey, ':s' => 'active', ':e' => 'harpp']);
            $tenantId = (int)$control->lastInsertId();
            if ($tenantId <= 0) throw new RuntimeException('Failed to create isolated tenant.');

            $stmt = $control->prepare('INSERT INTO kernel_tenant_domains (tenant_id, domain) VALUES (:tid, :d)');
            $stmt->execute([':tid' => $tenantId, ':d' => $domain]);

            $crypto = new \Ikabud\Kernel\Crypto();
            $enc = $crypto->encryptString($dbPass);
            $stmt = $control->prepare(
                'INSERT INTO kernel_tenant_db_connections (tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, db_pass_ciphertext, db_pass_iv, db_pass_tag) '
                . 'VALUES (:tid, :drv, :host, :port, :name, :user, NULL, :charset, :cipher, :iv, :tag)'
            );
            $stmt->execute([
                ':tid' => $tenantId,
                ':drv' => 'mysql',
                ':host' => $host,
                ':port' => (string)$port,
                ':name' => $dbName,
                ':user' => $dbUser,
                ':charset' => 'utf8mb4',
                ':cipher' => (string)$enc['ciphertext'],
                ':iv' => (string)$enc['iv'],
                ':tag' => (string)$enc['tag'],
            ]);
            $control->commit();
            $created[] = 'tenant';
        } catch (Throwable $e) {
            if ($control->inTransaction()) $control->rollBack();
            throw $e;
        }

        // Persist enough state for --down before seeding/registration completes.
        $state = [
            'tenant_id' => $tenantId,
            'tenant_key' => $tenantKey,
            'db_name' => $dbName,
            'domain' => $domain,
            'owner_email' => $ownerEmail,
            'decision_id' => 0,
            'decision_key' => '',
            'title' => '',
        ];
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($stateFile, 0600);
        $created[] = 'state';

        // Enable the HARPP module for the isolated tenant and give the seeded
        // owner a real password for the browser login.
        $db->prepare("INSERT INTO tenant_module_settings (tenant_id, module_id, setting_key, setting_value) VALUES (:tid, 'harpp', '_module_enabled', 'true')")
            ->execute([':tid' => $tenantId]);
        $stmt = $db->prepare('UPDATE harpp_users SET password_hash = :hash, updated_at = NOW() WHERE email = :email AND is_active = 1');
        $stmt->execute([':hash' => password_hash($ownerPassword, PASSWORD_BCRYPT), ':email' => $ownerEmail]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Unable to set the isolated owner password.');

        // Seed a uniquely keyed ACKNOWLEDGED decision through the real service so
        // the browser journey has a realistic, durable fixture with audit trail.
        $manifest = json_decode((string)file_get_contents($root . '/modules/harpp/module.json'), true, 512, JSON_THROW_ON_ERROR);
        $tenantPdo = app()->dbForTenant($tenantId);
        if (!$tenantPdo instanceof PDO) throw new RuntimeException('Unable to connect to the isolated tenant database.');
        $moduleDb = new \Ikabud\Kernel\Contracts\ModuleDB($tenantPdo, 'harpp', (array)$manifest['owns_tables'], (array)$manifest['reads_tables']);
        app()->tenant()->setTenantId($tenantId);

        $ownerRow = $moduleDb->query("SELECT id, email, full_name, role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ownerRow)) throw new RuntimeException('HARPP owner fixture missing after provisioning.');
        $owner = ['id' => (int)$ownerRow['id'], 'email' => (string)$ownerRow['email'], 'full_name' => (string)$ownerRow['full_name'], 'role' => (string)$ownerRow['role'], 'source' => 'harpp'];

        $decisionKey = 'BROWSER-' . strtoupper(bin2hex(random_bytes(6)));
        $title = 'Browser decision inbox fixture ' . $decisionKey;
        $service = new \Harpp\Services\HarppDecisionService($moduleDb);
        $createdDecision = $service->create($owner, [
            'title' => $title,
            'body' => 'Isolated Playwright decision-inbox journey fixture.',
            'context' => 'Browser acceptance coverage',
            'requested_decision' => 'Approve the isolated journey path',
            'priority' => 'normal',
            'source' => 'harness',
            'workbench_state' => 'ARCHITECTURE_DECISION_REQUIRED',
            'decision_key' => $decisionKey,
        ], $tenantId);
        if (empty($createdDecision['ok'])) throw new RuntimeException('Unable to seed decision fixture: ' . (string)($createdDecision['error'] ?? 'unknown'));
        $decisionId = (int)$createdDecision['data']['decision_id'];

        $version = 1;
        foreach (['NOTIFIED', 'VIEWED', 'DECIDED', 'ACKNOWLEDGED'] as $transitionState) {
            $changes = ['expected_version' => $version];
            if ($transitionState === 'DECIDED') $changes['decision'] = 'Approved in isolated browser fixture';
            $transitioned = $service->transition($owner, $decisionId, $transitionState, 'Browser fixture ' . $transitionState, $changes, $tenantId);
            if (empty($transitioned['ok'])) throw new RuntimeException("Unable to advance fixture to {$transitionState}: " . (string)($transitioned['error'] ?? 'unknown'));
            $version = (int)$transitioned['data']['version'];
        }

        $state['decision_id'] = $decisionId;
        $state['decision_key'] = $decisionKey;
        $state['title'] = $title;
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($stateFile, 0600);

        // Invalidate the control-host domain cache so the first browser request
        // resolves the new isolated tenant immediately (no 30s APCu TTL wait).
        echo "HARPP browser fixture created: tenant=#$tenantId domain=$domain db=$dbName decision=#$decisionId\n";
        clearHostCacheViaWeb($domain, $root);
        echo "HARPP browser fixture ready.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'HARPP browser fixture setup failed: ' . $e->getMessage() . "\n");
        // Best-effort teardown of anything already created, then report failure.
        teardown($stateFile);
        exit(1);
    }
}

function teardown(string $stateFile): void
{
    $root = dirname(__DIR__, 4);
    chdir($root);
    loadDotEnv($root);

    $state = [];
    if (is_file($stateFile)) {
        $decoded = json_decode((string)file_get_contents($stateFile), true);
        if (is_array($decoded)) $state = $decoded;
    }

    $dbConfig = require $root . '/config/database.php';
    $controlConfig = require $root . '/config/control_database.php';
    $pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];

    $control = new PDO(
        "mysql:host=" . (string)$controlConfig['host'] . ";port=" . (int)($controlConfig['port'] ?? 3306) . ";dbname=" . (string)$controlConfig['database'] . ";charset=utf8mb4",
        (string)$controlConfig['username'],
        (string)$controlConfig['password'],
        $pdoOptions
    );

    $tenantId = (int)($state['tenant_id'] ?? 0);
    $dbName = (string)($state['db_name'] ?? '');
    $domain = (string)($state['domain'] ?? '');

    // Clear the shared control-host cache while the tenant/DB still exist so a
    // subsequent request cannot resolve the soon-to-be-deleted tenant and 500.
    if ($tenantId > 0 && $domain !== '' && preg_match('/^[a-z0-9][a-z0-9.\-]{1,253}[a-z0-9]$/', $domain)) {
        clearHostCacheViaWeb($domain, $root);
    }

    if ($tenantId > 0) {
        try {
            $control->prepare('DELETE FROM kernel_tenant_domains WHERE tenant_id = :tid')->execute([':tid' => $tenantId]);
            $control->prepare('DELETE FROM kernel_tenant_db_connections WHERE tenant_id = :tid')->execute([':tid' => $tenantId]);
            $control->prepare('DELETE FROM kernel_tenants WHERE id = :tid')->execute([':tid' => $tenantId]);
            echo "Removed isolated tenant #$tenantId and domain mapping.\n";
        } catch (Throwable $e) {
            fwrite(STDERR, 'Control-plane teardown warning: ' . $e->getMessage() . "\n");
        }
    }

    if ($dbName !== '' && preg_match('/^harpp_browser_[a-z0-9_]+$/', $dbName)) {
        try {
            $admin = new PDO(
                "mysql:host=" . (string)$dbConfig['host'] . ";port=" . (int)($dbConfig['port'] ?? 3306) . ";charset=utf8mb4",
                (string)$dbConfig['username'],
                (string)$dbConfig['password'],
                $pdoOptions
            );
            $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
            echo "Dropped isolated database $dbName.\n";
        } catch (Throwable $e) {
            fwrite(STDERR, 'Database teardown warning: ' . $e->getMessage() . "\n");
        }
    }

    if (is_file($stateFile)) {
        @unlink($stateFile);
        echo "Removed fixture state file.\n";
    }
}
