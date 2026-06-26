<?php
/**
 * Apply workflow engine migration to the tenant database.
 * Run: php tests/apply_workflow_migration.php
 */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';

$sql = file_get_contents(__DIR__ . '/../migrations/009_kernel_workflow_runs.sql');
$parts = explode(';', $sql);
$count = 0;

foreach ($parts as $part) {
    $part = trim($part);
    if ($part !== '') {
        try {
            app()->db()->exec($part);
            $count++;
        } catch (\Throwable $e) {
            echo "Error on statement {$count}: " . $e->getMessage() . "\n";
        }
    }
}

$tables = app()->db()->query("SHOW TABLES LIKE 'workflow_%'")->fetchAll(PDO::FETCH_COLUMN);
echo "Applied {$count} statements.\n";
echo "Tables: " . json_encode($tables) . "\n";
