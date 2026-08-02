<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/tenants';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';
require_once __DIR__ . '/../src/http/admin-view-cache.php';
require_once __DIR__ . '/../src/http/admin-handlers.php';

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function btTenantSeed(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function setPrivateProperty(object $object, string $property, mixed $value): void
{
    $ref = new ReflectionProperty($object, $property);
    $ref->setAccessible(true);
    $ref->setValue($object, $value);
}

function getPrivateProperty(object $object, string $property): mixed
{
    $ref = new ReflectionProperty($object, $property);
    $ref->setAccessible(true);
    return $ref->getValue($object);
}

function runTenantSeedJsonRequest(string $rawBody, array $user, string $csrfToken): array
{
    $patchedAppPath = sys_get_temp_dir() . '/ikabud-app-json-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $runnerPath = sys_get_temp_dir() . '/ikabud-tenant-seed-json-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';

    $appSource = (string)file_get_contents(__DIR__ . '/../kernel/App.php');
    $replacement = "file_get_contents('data://text/plain;base64," . base64_encode($rawBody) . "')";
    $appSource = str_replace("file_get_contents('php://input')", $replacement, $appSource);
    file_put_contents($patchedAppPath, $appSource);

    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $moduleManager = var_export(__DIR__ . '/../src/helpers/module-manager.php', true);
    $moduleMigrations = var_export(__DIR__ . '/../src/helpers/module-migrations.php', true);
    $adminViewCache = var_export(__DIR__ . '/../src/http/admin-view-cache.php', true);
    $adminHandlers = var_export(__DIR__ . '/../src/http/admin-handlers.php', true);
    $patchedApp = var_export($patchedAppPath, true);
    $userExport = var_export($user, true);
    $csrfTokenExport = var_export($csrfToken, true);
    $rawBodyExport = var_export($rawBody, true);

    $runner = <<<PHP
<?php
require {$patchedApp};
require {$bootstrap};
require_once {$moduleManager};
require_once {$moduleMigrations};
require_once {$adminViewCache};
require_once {$adminHandlers};


















\$_SERVER['HTTP_HOST'] = 'applicationos.test';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['REQUEST_URI'] = '/api/v1/admin/tenants/seed-data';
\$_SERVER['CONTENT_TYPE'] = 'application/json';
\$_GET = [];
\$_POST = [];
\Ikabud\Kernel\Http\Input::setRawInputForTesting({$rawBodyExport});
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
\$_SESSION['_csrf_token'] = {$csrfTokenExport};
app()->setUser({$userExport});
http_response_code(200);
ob_start();
kernelHandleApiTenantSeedData();
\$body = (string)ob_get_clean();
echo json_encode(['status' => (int)(http_response_code() ?: 200), 'body' => \$body], JSON_UNESCAPED_SLASHES);
PHP;

    file_put_contents($runnerPath, $runner);

    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);

    @unlink($runnerPath);
    @unlink($patchedAppPath);

    $decoded = json_decode(implode("\n", $output), true);
    if (!is_array($decoded)) {
        return [
            'status' => $exitCode === 0 ? 0 : $exitCode,
            'body' => implode("\n", $output),
            'exit_code' => $exitCode,
        ];
    }

    $decoded['exit_code'] = $exitCode;
    return $decoded;
}

function runTenantSeedEntrypointRequest(array $post, array $user, string $csrfToken): array
{
    $runnerPath = sys_get_temp_dir() . '/ikabud-tenant-seed-entrypoint-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $entrypoint = var_export(__DIR__ . '/../public/index.php', true);
    $postExport = var_export($post, true);
    $userExport = var_export($user, true);
    $csrfTokenExport = var_export($csrfToken, true);

    $runner = <<<PHP
<?php
require {$bootstrap};






\$_SERVER['HTTP_HOST'] = 'applicationos.test';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['REQUEST_URI'] = '/api/v1/admin/tenants/seed-data';
\$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
\$_GET = [];
\$_POST = {$postExport};
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
\$_SESSION['_csrf_token'] = {$csrfTokenExport};
app()->setUser({$userExport});
register_shutdown_function(static function (): void {
    echo "\n__BODY_END__\n";
});
require {$entrypoint};
PHP;

    file_put_contents($runnerPath, $runner);

    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);

    return [
        'body' => implode("\n", $output),
        'exit_code' => $exitCode,
    ];
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP TENANT SEED DATA TEST ===\n\n";

$app = app();
$controlDb = $app->controlDb();
$originalServer = $_SERVER;
$originalGet = $_GET;
$originalPost = $_POST;
$originalCurrentUser = getPrivateProperty($app, 'currentUser');
$originalResolvingCurrentUser = getPrivateProperty($app, 'resolvingCurrentUser');
$originalTenantId = $app->tenant()->current();

$tenantKey = 'bakeshop-seed-' . bin2hex(random_bytes(4));
$tenantId = null;
$tenantDbName = 'bakeshop_seed_' . bin2hex(random_bytes(4));

try {
    $dbConfig = require BASE_PATH . '/config/database.php';
    $rootDsn = 'mysql:host=' . ($dbConfig['host'] ?? '127.0.0.1')
        . ';port=' . ($dbConfig['port'] ?? '3306')
        . ';charset=' . ($dbConfig['charset'] ?? 'utf8mb4');
    $rootPdo = new PDO(
        $rootDsn,
        (string)($dbConfig['username'] ?? ''),
        (string)($dbConfig['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $safeTenantDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $tenantDbName) ?? $tenantDbName;
    $rootPdo->exec('DROP DATABASE IF EXISTS `' . $safeTenantDbName . '`');
    $rootPdo->exec('CREATE DATABASE `' . $safeTenantDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $tenantInsertStmt = $controlDb->prepare(
        'INSERT INTO kernel_tenants (tenant_key, status, entry_module_id) VALUES (:tenant_key, :status, :entry_module_id)'
    );
    $tenantInsertStmt->execute([
        ':tenant_key' => $tenantKey,
        ':status' => 'active',
        ':entry_module_id' => 'bakeshop',
    ]);
    $tenantId = (int)$controlDb->lastInsertId();

    $encryptedDbPass = (new \Ikabud\Kernel\Crypto())->encryptString(
        (string)($dbConfig['password'] ?? '')
    );

    $connectionInsertStmt = $controlDb->prepare(
        'INSERT INTO kernel_tenant_db_connections '
        . '(tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, db_pass_ciphertext, db_pass_iv, db_pass_tag) '
        . 'VALUES (:tenant_id, :db_driver, :db_host, :db_port, :db_name, :db_user, :db_pass, :db_charset, :cipher, :iv, :tag)'
    );
    $connectionInsertStmt->execute([
        ':tenant_id' => $tenantId,
        ':db_driver' => 'mysql',
        ':db_host' => (string)($dbConfig['host'] ?? '127.0.0.1'),
        ':db_port' => (string)($dbConfig['port'] ?? '3306'),
        ':db_name' => $tenantDbName,
        ':db_user' => (string)($dbConfig['username'] ?? ''),
        ':db_pass' => null,
        ':db_charset' => (string)($dbConfig['charset'] ?? 'utf8mb4'),
        ':cipher' => (string)($encryptedDbPass['ciphertext'] ?? ''),
        ':iv' => (string)($encryptedDbPass['iv'] ?? ''),
        ':tag' => (string)($encryptedDbPass['tag'] ?? ''),
    ]);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/api/v1/admin/tenants/seed-data';
    $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    $_GET = [];
    $_POST = [
        'tenant_id' => (string)$tenantId,
        'seed_id' => 'bakeshop_julies_bread_pastry',
        '_token' => $app->csrfToken(),
    ];
    $app->setUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'kernel',
    ]);
    http_response_code(200);

    ob_start();
    kernelHandleApiTenantSeedData();
    $body = (string)ob_get_clean();
    $status = (int)(http_response_code() ?: 200);
    $decoded = json_decode($body, true);

    btTenantSeed('tenant seed endpoint returns HTTP 200', $status === 200, 'status=' . $status . ' body=' . $body);
    btTenantSeed('tenant seed endpoint returns JSON payload', is_array($decoded), $body);
    btTenantSeed('tenant seed endpoint payload ok=true', is_array($decoded) && !empty($decoded['ok']), $body);
    btTenantSeed('tenant seed endpoint echoes the requested seed id', is_array($decoded) && ($decoded['seed_id'] ?? '') === 'bakeshop_julies_bread_pastry', $body);
    btTenantSeed('tenant seed endpoint reports 10 Julie branches', is_array($decoded) && (int)(($decoded['counts']['branches'] ?? 0)) === 10, $body);
    btTenantSeed('tenant seed endpoint reports 81 Julie products', is_array($decoded) && (int)(($decoded['counts']['products'] ?? 0)) === 81, $body);
    btTenantSeed('tenant seed endpoint reports 30 Julie ingredients', is_array($decoded) && (int)(($decoded['counts']['ingredients'] ?? 0)) === 30, $body);
    btTenantSeed('tenant seed endpoint omits Julie recipe counts', is_array($decoded) && is_array($decoded['counts'] ?? null) && !array_key_exists('recipes', $decoded['counts']), $body);

    $entrypointResponse = runTenantSeedEntrypointRequest([
        'tenant_id' => (string)$tenantId,
        'seed_id' => 'bakeshop_julies_bread_pastry',
        '_token' => $app->csrfToken(),
    ], [
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'kernel',
    ], $app->csrfToken());
    btTenantSeed('tenant seed route does not fall through to unknown handler', !str_contains((string)($entrypointResponse['body'] ?? ''), 'Unknown handler'), (string)($entrypointResponse['body'] ?? ''));

    $jsonPayload = json_encode([
        'tenant_id' => $tenantId,
        'seed_id' => 'bakeshop_julies_bread_pastry',
        '_token' => $app->csrfToken(),
    ], JSON_UNESCAPED_SLASHES);
    $jsonResponse = runTenantSeedJsonRequest(
        $jsonPayload === false ? '{}' : $jsonPayload,
        [
            'id' => 1,
            'username' => 'admin',
            'role' => 'admin',
            'source' => 'kernel',
        ],
        $app->csrfToken()
    );
    $jsonDecoded = json_decode((string)($jsonResponse['body'] ?? ''), true);
    btTenantSeed('tenant seed JSON request returns HTTP 200', (int)($jsonResponse['status'] ?? 0) === 200, json_encode($jsonResponse, JSON_UNESCAPED_SLASHES));
    btTenantSeed('tenant seed JSON request returns JSON payload', is_array($jsonDecoded), (string)($jsonResponse['body'] ?? ''));
    btTenantSeed('tenant seed JSON request payload ok=true', is_array($jsonDecoded) && !empty($jsonDecoded['ok']), (string)($jsonResponse['body'] ?? ''));

    $invalidJsonResponse = runTenantSeedJsonRequest(
        '{"tenant_id":',
        [
            'id' => 1,
            'username' => 'admin',
            'role' => 'admin',
            'source' => 'kernel',
        ],
        $app->csrfToken()
    );
    $invalidJsonDecoded = json_decode((string)($invalidJsonResponse['body'] ?? ''), true);
    btTenantSeed('tenant seed invalid JSON request returns HTTP 422', (int)($invalidJsonResponse['status'] ?? 0) === 422, json_encode($invalidJsonResponse, JSON_UNESCAPED_SLASHES));
    btTenantSeed('tenant seed invalid JSON request returns explicit error', is_array($invalidJsonDecoded) && ($invalidJsonDecoded['error'] ?? '') === 'Invalid JSON request body', (string)($invalidJsonResponse['body'] ?? ''));

    $tenantDb = $app->reconnectDbForTenant($tenantId);
    btTenantSeed('tenant DB reconnects after seeding', $tenantDb instanceof PDO);

    if ($tenantDb instanceof PDO) {
        $seededBranches = (int)($tenantDb->query("SELECT COUNT(*) FROM bakeshop_branches WHERE external_store_id IS NOT NULL AND code IN ('JB01', 'JES01', 'JL01', 'JMA01', 'JMIP01', 'JMN01', 'JP01', 'JPI01', 'JPO01', 'JTUR01')")->fetchColumn() ?: 0);
        $seededProducts = (int)($tenantDb->query("SELECT COUNT(*) FROM bakeshop_products WHERE sku LIKE 'JBS-PRD-%'")->fetchColumn() ?: 0);
        $seededIngredients = (int)($tenantDb->query("SELECT COUNT(*) FROM bakeshop_ingredients WHERE sku LIKE 'JBS-ING-%'")->fetchColumn() ?: 0);
        $seededRecipes = (int)($tenantDb->query("SELECT COUNT(*) FROM bakeshop_product_recipe WHERE notes LIKE 'Imported from Julie''s live bakery seed.%'")->fetchColumn() ?: 0);

        btTenantSeed('seeded tenant DB contains 10 Julie branches', $seededBranches === 10, (string)$seededBranches);
        btTenantSeed('seeded tenant DB contains 81 Julie products', $seededProducts === 81, (string)$seededProducts);
        btTenantSeed('seeded tenant DB contains 30 Julie ingredients', $seededIngredients === 30, (string)$seededIngredients);
        btTenantSeed('seeded tenant DB contains no Julie recipes', $seededRecipes === 0, (string)$seededRecipes);
    }

    $appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
    $errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
    btTenantSeed('no app.log errors after tenant seed run', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
    btTenantSeed('no error.log errors after tenant seed run', $errorLog === '', $errorLog);
} finally {
    $_SERVER = $originalServer;
    $_GET = $originalGet;
    $_POST = $originalPost;
    $app->tenant()->setTenantId($originalTenantId);
    $app->reconnectDb();
    setPrivateProperty($app, 'currentUser', $originalCurrentUser);
    setPrivateProperty($app, 'resolvingCurrentUser', $originalResolvingCurrentUser);

    if ($tenantDbName !== '') {
        try {
            $dbConfig = require BASE_PATH . '/config/database.php';
            $rootDsn = 'mysql:host=' . ($dbConfig['host'] ?? '127.0.0.1')
                . ';port=' . ($dbConfig['port'] ?? '3306')
                . ';charset=' . ($dbConfig['charset'] ?? 'utf8mb4');
            $rootPdo = new PDO(
                $rootDsn,
                (string)($dbConfig['username'] ?? ''),
                (string)($dbConfig['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $safeTenantDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $tenantDbName) ?? $tenantDbName;
            $rootPdo->exec('DROP DATABASE IF EXISTS `' . $safeTenantDbName . '`');
        } catch (Throwable $e) {
        }
    }

    if ($tenantId !== null) {
        $deleteStmt = $controlDb->prepare('DELETE FROM kernel_tenant_db_connections WHERE tenant_id = :tenant_id');
        $deleteStmt->execute([':tenant_id' => $tenantId]);
        $deleteStmt = $controlDb->prepare('DELETE FROM kernel_tenant_domains WHERE tenant_id = :tenant_id');
        $deleteStmt->execute([':tenant_id' => $tenantId]);
        $deleteStmt = $controlDb->prepare('DELETE FROM kernel_tenants WHERE id = :tenant_id');
        $deleteStmt->execute([':tenant_id' => $tenantId]);
    }
}

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";
