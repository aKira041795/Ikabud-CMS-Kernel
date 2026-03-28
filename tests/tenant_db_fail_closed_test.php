<?php
/**
 * Tenant DB fail-closed regression test.
 * Verifies the current request tenant does not silently fall back to the base DB
 * when control-plane credentials are invalid.
 * Run: php tests/tenant_db_fail_closed_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
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

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$app = app();
$resolver = $app->tenant();
$controlDb = $app->controlDb();

$originalAppConfig = getPrivateProperty($app, 'config');
$appConfig = $originalAppConfig;
$originalResolver = [
    'enabled' => getPrivateProperty($resolver, 'enabled'),
    'strategy' => getPrivateProperty($resolver, 'strategy'),
    'default' => getPrivateProperty($resolver, 'default'),
    'resolvedTenantId' => getPrivateProperty($resolver, 'resolvedTenantId'),
    'resolved' => getPrivateProperty($resolver, 'resolved'),
];

$tenantKey = 'fail-closed-test-' . bin2hex(random_bytes(4));
$tenantId = null;

try {
    $stmt = $controlDb->prepare('INSERT INTO kernel_tenants (tenant_key, status) VALUES (:tenant_key, :status)');
    $stmt->execute([
        ':tenant_key' => $tenantKey,
        ':status' => 'active',
    ]);
    $tenantId = (int)$controlDb->lastInsertId();

    $stmt = $controlDb->prepare(
        'INSERT INTO kernel_tenant_db_connections '
        . '(tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, db_pass_ciphertext, db_pass_iv, db_pass_tag) '
        . 'VALUES (:tenant_id, :db_driver, :db_host, :db_port, :db_name, :db_user, :db_pass, :db_charset, :cipher, :iv, :tag)'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':db_driver' => 'mysql',
        ':db_host' => 'localhost',
        ':db_port' => '3306',
        ':db_name' => 'tenant_fail_closed_test',
        ':db_user' => 'tenant_fail_closed_test',
        ':db_pass' => '',
        ':db_charset' => 'utf8mb4',
        ':cipher' => 'not-valid-base64',
        ':iv' => 'not-valid-base64',
        ':tag' => 'not-valid-base64',
    ]);

    $appConfig['app']['multi_tenant']['enabled'] = true;
    $appConfig['app']['multi_tenant']['strategy'] = 'control_host';
    setPrivateProperty($app, 'config', $appConfig);
    setPrivateProperty($resolver, 'enabled', true);
    setPrivateProperty($resolver, 'strategy', 'control_host');
    $resolver->setTenantId($tenantId);
    setPrivateProperty($app, 'db', null);

    $threw = false;
    $message = '';
    try {
        $app->db();
    } catch (RuntimeException $e) {
        $threw = true;
        $message = $e->getMessage();
    }

    t('Current-tenant DB resolution fails closed', $threw, $message);
    t(
        'Failure message references tenant DB configuration',
        $threw && str_contains($message, 'Tenant database configuration could not be resolved'),
        $message
    );

    $resolver->reset();
    setPrivateProperty($app, 'db', null);
    $baseDb = $app->db();
    t('Base DB still connects after tenant failure path is cleared', $baseDb instanceof PDO);

    $appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
    $errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
    t('app.log contains tenant DB failure entry', str_contains($appLog, 'Tenant DB resolution failed'));
    t('app.log includes tenant id context', str_contains($appLog, '"tenant_id":' . $tenantId), $appLog !== '' ? substr($appLog, 0, 300) : '');
    t('No PHP error output leaked to error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');
} finally {
    setPrivateProperty($app, 'config', $originalAppConfig);
    setPrivateProperty($resolver, 'enabled', $originalResolver['enabled']);
    setPrivateProperty($resolver, 'strategy', $originalResolver['strategy']);
    setPrivateProperty($resolver, 'default', $originalResolver['default']);
    setPrivateProperty($resolver, 'resolvedTenantId', $originalResolver['resolvedTenantId']);
    setPrivateProperty($resolver, 'resolved', $originalResolver['resolved']);
    setPrivateProperty($app, 'db', null);

    if ($tenantId !== null) {
        $stmt = $controlDb->prepare('DELETE FROM kernel_tenant_db_connections WHERE tenant_id = :tenant_id');
        $stmt->execute([':tenant_id' => $tenantId]);
        $stmt = $controlDb->prepare('DELETE FROM kernel_tenants WHERE id = :tenant_id');
        $stmt->execute([':tenant_id' => $tenantId]);
    }
}

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    ✗ {$error}\n";
    }
}
echo "════════════════════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);