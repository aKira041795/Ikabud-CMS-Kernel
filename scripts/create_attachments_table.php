<?php
require __DIR__ . '/../bootstrap.php';
$db = app()->dbForTenant(502); // palsystem
try {
    $tables = $db->query("SHOW TABLES LIKE 'pal_attachments'")->fetchAll(PDO::FETCH_NUM);
    if (count($tables) > 0) {
        echo "TABLE EXISTS: pal_attachments\n";
        $cols = $db->query("DESCRIBE pal_attachments")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
    } else {
        echo "TABLE MISSING: pal_attachments — creating...\n";
        $db->exec("CREATE TABLE pal_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT UNSIGNED NOT NULL,
            filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) DEFAULT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_path VARCHAR(500) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            uploaded_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pal_att_tenant (tenant_id),
            INDEX idx_pal_att_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "TABLE CREATED\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
