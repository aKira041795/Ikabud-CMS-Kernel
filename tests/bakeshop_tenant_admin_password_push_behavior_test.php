<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/tenants';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/Services/TenantProvisioner.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';
require_once __DIR__ . '/../src/http/admin-view-cache.php';
require_once __DIR__ . '/../src/http/admin-handlers.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';

use Ikabud\Kernel\Services\TenantProvisioner;

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function btPasswordPush(string $label, bool $ok, string $detail = ''): void
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

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP TENANT ADMIN PASSWORD PUSH BEHAVIOR TEST ===\n\n";

$app = app();
$controlDb = $app->controlDb();
$originalServer = $_SERVER;
$originalGet = $_GET;
$originalPost = $_POST;
$originalCurrentUser = getPrivateProperty($app, 'currentUser');
$originalResolvingCurrentUser = getPrivateProperty($app, 'resolvingCurrentUser');
$originalTenantId = $app->tenant()->current();

$tenantKey = 'bakeshop-password-push-' . bin2hex(random_bytes(4));
$tenantId = null;
$tenantDbName = 'bakeshop_password_push_' . bin2hex(random_bytes(4));

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

    $tenantInsertStmt = $controlDb->prepare(
        'INSERT INTO kernel_tenants (tenant_key, status, entry_module_id) VALUES (:tenant_key, :status, :entry_module_id)'
    );
    $tenantInsertStmt->execute([
        ':tenant_key' => $tenantKey,
        ':status' => 'active',
        ':entry_module_id' => 'bakeshop',
    ]);
    $tenantId = (int)$controlDb->lastInsertId();

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
        ':db_pass' => (string)($dbConfig['password'] ?? ''),
        ':db_charset' => (string)($dbConfig['charset'] ?? 'utf8mb4'),
        ':cipher' => '',
        ':iv' => '',
        ':tag' => '',
    ]);

    $seededAdminUser = 'push-admin-' . bin2hex(random_bytes(3));
    $seededAdminPass = 'Seeded!' . bin2hex(random_bytes(4));
    $seededAdminName = 'Password Push Admin';
    $pushedAdminPass = 'Updated!' . bin2hex(random_bytes(4));

    $service = new TenantProvisioner($controlDb);
    $provisionResult = $service->provision($tenantId, [
        'admin_user' => $seededAdminUser,
        'admin_pass' => $seededAdminPass,
        'admin_name' => $seededAdminName,
    ]);
    btPasswordPush('tenant provisions cleanly for password push behavior test', ($provisionResult['ok'] ?? false) === true, json_encode($provisionResult, JSON_UNESCAPED_SLASHES));

    $tenantDb = $app->reconnectDbForTenant($tenantId);
    btPasswordPush('tenant DB reconnects through tenant DB factory', $tenantDb instanceof PDO);

    if ($tenantDb instanceof PDO) {
        $lookupStmt = $tenantDb->prepare('SELECT username, password_hash, full_name, role, is_active FROM bakeshop_users WHERE username = :username LIMIT 1');
        $lookupStmt->execute([':username' => $seededAdminUser]);
        $beforePush = $lookupStmt->fetch(PDO::FETCH_ASSOC);

        btPasswordPush('seeded bakeshop admin exists before password push', is_array($beforePush), json_encode($beforePush, JSON_UNESCAPED_SLASHES));
        btPasswordPush('seeded admin password verifies before password push', is_array($beforePush) && password_verify($seededAdminPass, (string)($beforePush['password_hash'] ?? '')), json_encode($beforePush, JSON_UNESCAPED_SLASHES));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/v1/admin/tenants/password-push';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_GET = [];
        $_POST = [
            'tenant_id' => (string)$tenantId,
            'admin_password' => $pushedAdminPass,
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
        kernelHandleApiTenantAdminPasswordPush();
        $body = (string)ob_get_clean();
        $status = (int)(http_response_code() ?: 200);
        $decoded = json_decode($body, true);

        btPasswordPush('password push returns HTTP 200', $status === 200, 'status=' . $status . ' body=' . $body);
        btPasswordPush('password push returns JSON payload', is_array($decoded), $body);
        btPasswordPush('password push payload ok=true', is_array($decoded) && !empty($decoded['ok']), $body);
        btPasswordPush('password push reports bakeshop_users as pushed', is_array($decoded) && in_array('bakeshop_users', $decoded['pushed'] ?? [], true), $body);
        btPasswordPush('password push includes skipped tables for absent schemas without failing', is_array($decoded) && is_array($decoded['skipped'] ?? null), $body);

        $lookupStmt->execute([':username' => $seededAdminUser]);
        $afterPush = $lookupStmt->fetch(PDO::FETCH_ASSOC);

        btPasswordPush('bakeshop admin still exists after password push', is_array($afterPush), json_encode($afterPush, JSON_UNESCAPED_SLASHES));
        btPasswordPush('password hash changes after password push', is_array($beforePush) && is_array($afterPush) && (string)($beforePush['password_hash'] ?? '') !== (string)($afterPush['password_hash'] ?? ''), json_encode($afterPush, JSON_UNESCAPED_SLASHES));
        btPasswordPush('new password verifies after password push', is_array($afterPush) && password_verify($pushedAdminPass, (string)($afterPush['password_hash'] ?? '')), json_encode($afterPush, JSON_UNESCAPED_SLASHES));
        btPasswordPush('old password no longer verifies after password push', is_array($afterPush) && !password_verify($seededAdminPass, (string)($afterPush['password_hash'] ?? '')), json_encode($afterPush, JSON_UNESCAPED_SLASHES));

        $app->tenant()->setTenantId($tenantId);
        $app->reconnectDb();
        invalidateModuleContextCache('bakeshop');

        $authWithOldPassword = bakeshop_cap_kernel_auth_authenticate_1([
            'username' => '@bakeshop:' . $seededAdminUser,
            'password' => $seededAdminPass,
        ]);
        $authWithPushedPassword = bakeshop_cap_kernel_auth_authenticate_1([
            'username' => '@bakeshop:' . $seededAdminUser,
            'password' => $pushedAdminPass,
        ]);

        btPasswordPush('auth provider rejects the old password after admin recovery push', $authWithOldPassword === null, json_encode($authWithOldPassword, JSON_UNESCAPED_SLASHES));
        btPasswordPush('auth provider immediately accepts the pushed password for the tenant admin', is_array($authWithPushedPassword) && ($authWithPushedPassword['source'] ?? '') === 'bakeshop', json_encode($authWithPushedPassword, JSON_UNESCAPED_SLASHES));
        btPasswordPush('auth provider returns the affected tenant admin identity after password push', is_array($authWithPushedPassword) && (($authWithPushedPassword['user']['username'] ?? '') === $seededAdminUser), json_encode($authWithPushedPassword, JSON_UNESCAPED_SLASHES));
    }

    $appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
    $errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
    btPasswordPush('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
    btPasswordPush('no error.log errors', $errorLog === '', $errorLog);
} finally {
    $_SERVER = $originalServer;
    $_GET = $originalGet;
    $_POST = $originalPost;
    $app->tenant()->setTenantId($originalTenantId);
    $app->reconnectDb();
    invalidateModuleContextCache('bakeshop');
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

exit($fail > 0 ? 1 : 0);