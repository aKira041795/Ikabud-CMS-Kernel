<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * Data repair: membership tier list corruption
 *
 * Bug: ecMembershipNormalizeTierList("") returned ["member"] instead of []
 * because preg_split('/[\r\n,]+/', '') produces [''] and ecMembershipNormalizeTier('')
 * falls back to 'member'. Every product saved with an empty required_membership_tiers_text
 * field had '["member"]' written to cms_content_meta._required_membership_tiers, gating
 * all such products behind a membership check for unauthenticated users.
 *
 * Fix: reset _required_membership_tiers to '[]' where the value is exactly '["member"]'
 * (the only value this bug can produce from an empty-field save).
 *
 * Products that genuinely require a named 'member' tier will need to be re-saved via admin.
 */

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}\n");
    exit(1);
});

require __DIR__ . '/../bootstrap.php';

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}\n");
    exit(1);
});

$sql = "UPDATE cms_content_meta SET meta_value = '[]' WHERE meta_key = '_required_membership_tiers' AND meta_value = '[\"member\"]'";

$isMultiTenant = ($_ENV['APP_MULTI_TENANT_ENABLED'] ?? '') === 'true';

if ($isMultiTenant) {
    echo "Multi-tenant enabled\n";
    $app = app();
    $ctrl = $app->controlDb();
    $tenants = $ctrl->query("SELECT id, domain FROM tenants WHERE status = 'active'")->fetchAll();
    foreach ($tenants as $t) {
        echo "Tenant {$t['id']}: {$t['domain']}\n";
        try {
            $tdb = $app->dbForTenant((int)$t['id']);
            $affected = $tdb->exec($sql);
            echo "  -> Cleared $affected corrupted membership tier row(s)\n";
        } catch (\Exception $e) {
            echo "  -> Error: {$e->getMessage()}\n";
        }
    }
} else {
    echo "Single-tenant mode\n";
    $dbConfig = require CONFIG_PATH . '/database.php';
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
    $db = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $affected = $db->exec($sql);
    echo "Cleared $affected corrupted membership tier row(s)\n";
}

echo "Done.\n";
