<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Override exception handler to show errors in CLI
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}\n");
    exit(1);
});

require __DIR__ . '/../bootstrap.php';

// Restore CLI exception handler after bootstrap sets its own
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}\n");
    exit(1);
});

$sql = "ALTER TABLE cms_ai_content_plans ADD COLUMN search_grounding_enabled TINYINT(1) NULL DEFAULT NULL AFTER seo_enabled";

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
            $cols = $tdb->query("SHOW COLUMNS FROM cms_ai_content_plans LIKE 'search_grounding_enabled'")->fetchAll();
            if (count($cols) === 0) {
                echo "  -> MISSING column, applying migration...\n";
                $tdb->exec($sql);
                echo "  -> Applied!\n";
            } else {
                echo "  -> Column already exists\n";
            }
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
    $cols = $db->query("SHOW COLUMNS FROM cms_ai_content_plans LIKE 'search_grounding_enabled'")->fetchAll();
    if (count($cols) === 0) {
        echo "MISSING column, applying migration...\n";
        $db->exec($sql);
        echo "Applied!\n";
    } else {
        echo "Column already exists\n";
    }
}

echo "Done.\n";
