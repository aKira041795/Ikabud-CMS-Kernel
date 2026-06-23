<?php
require __DIR__ . '/../bootstrap.php';
$db = app()->dbForTenant(502); // palsystem

// Find the actual UNIQUE constraint name
$row = $db->query("
    SELECT INDEX_NAME FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'pal_fabrication_allocations' 
      AND INDEX_NAME LIKE 'uq_%' 
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $name = $row['INDEX_NAME'];
    echo "Found constraint: {$name}\n";
    $db->exec("ALTER TABLE pal_fabrication_allocations DROP INDEX `{$name}`");
    echo "Dropped.\n";
} else {
    echo "No UNIQUE constraint found — already removed or never existed.\n";
}
