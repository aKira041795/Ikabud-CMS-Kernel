<?php
/**
 * Approve theme-studio in the platform catalog.
 */
require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Database\KernelPDO;

try {
    KernelPDO::kernelEscalationEnter();
    $db = app()->controlDb();

    // Ensure catalog table exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS `kernel_module_catalog` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `module_id` VARCHAR(100) NOT NULL,
            `module_name` VARCHAR(190) DEFAULT NULL,
            `approved_version` VARCHAR(60) DEFAULT NULL,
            `approval_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `commercial_mode` VARCHAR(20) NOT NULL DEFAULT 'free',
            `source` VARCHAR(40) NOT NULL DEFAULT 'admin_install',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_module_id` (`module_id`),
            KEY `idx_approval_status` (`approval_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Upsert: approve theme-studio
    $stmt = $db->prepare("
        INSERT INTO kernel_module_catalog (module_id, module_name, approved_version, approval_status, commercial_mode, source)
        VALUES (:id, :name, :ver, 'approved', 'free', 'admin_install')
        ON DUPLICATE KEY UPDATE
            module_name = VALUES(module_name),
            approved_version = VALUES(approved_version),
            approval_status = 'approved',
            commercial_mode = 'free'
    ");
    $stmt->execute([
        ':id' => 'theme-studio',
        ':name' => 'Theme Studio',
        ':ver' => '1.0.0',
    ]);

    echo "✓ theme-studio approved in platform catalog\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    KernelPDO::kernelEscalationLeave();
}

// Verify
if (function_exists('moduleCatalogIsApproved')) {
    echo "moduleCatalogIsApproved: " . (moduleCatalogIsApproved('theme-studio') ? 'YES' : 'NO') . "\n";
}
