<?php
require __DIR__ . '/../bootstrap.php';
$db = app()->dbForTenant(502);
try {
    $db->exec("ALTER TABLE pal_purchases ADD COLUMN project_id INT UNSIGNED DEFAULT NULL AFTER supplier_id");
    echo "Added project_id column.\n";
} catch (Throwable $e) {
    echo "Note: " . $e->getMessage() . "\n";
}
try {
    $db->exec("ALTER TABLE pal_purchases ADD INDEX idx_pal_purch_project (project_id)");
    echo "Added index.\n";
} catch (Throwable $e) {
    echo "Note: " . $e->getMessage() . "\n";
}
