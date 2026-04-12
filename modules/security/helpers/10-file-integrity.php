<?php
/**
 * Security Module — File Integrity Monitoring
 */

declare(strict_types=1);

/**
 * Build or rebuild the file integrity baseline.
 * Scans configured paths and stores SHA-256 hashes.
 *
 * @return array{scanned: int, stored: int}
 */
function securityBuildFileBaseline(): array
{
    $db = securityDb();
    $settings = securityGetSettings();
    $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

    $paths = json_decode($settings['file_integrity_paths'] ?? '[]', true);
    if (!is_array($paths) || empty($paths)) {
        $paths = ['kernel', 'src', 'modules', 'public/index.php', 'bootstrap.php', 'config'];
    }

    // Collect all files to hash.
    $files = [];
    foreach ($paths as $relPath) {
        $absPath = $basePath . '/' . ltrim($relPath, '/');
        if (is_file($absPath)) {
            $files[] = $absPath;
        } elseif (is_dir($absPath)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $ext = strtolower($file->getExtension());
                    // Only hash code/config files, skip binary assets and caches.
                    if (in_array($ext, ['php', 'json', 'sql', 'disyl', 'html', 'js', 'ts', 'tsx', 'css', 'env', 'md', 'yml', 'yaml', 'xml'], true)) {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }
    }

    // Clear existing baseline and rebuild.
    $db->exec('DELETE FROM security_file_baselines');

    $stmt = $db->prepare(
        'INSERT INTO security_file_baselines (file_path, sha256_hash, size_bytes, last_checked_at) VALUES (?, ?, ?, NOW())'
    );

    $stored = 0;
    foreach ($files as $filePath) {
        $relFile = str_replace($basePath . '/', '', $filePath);
        $hash = hash_file('sha256', $filePath);
        $size = filesize($filePath);
        if ($hash !== false && $size !== false) {
            $stmt->execute([$relFile, $hash, $size]);
            $stored++;
        }
    }

    securityAuditLog('integrity_baseline_rebuilt', 'info', [
        'files_scanned' => count($files),
        'files_stored'  => $stored,
    ]);

    return ['scanned' => count($files), 'stored' => $stored];
}

/**
 * Check file integrity against the stored baseline.
 *
 * @return array{ok: bool, modified: array, added: array, deleted: array}
 */
function securityCheckFileIntegrity(): array
{
    $db = securityDb();
    $settings = securityGetSettings();
    $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

    // Load baseline.
    $rows = $db->query('SELECT file_path, sha256_hash, size_bytes FROM security_file_baselines')
        ->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return ['ok' => true, 'modified' => [], 'added' => [], 'deleted' => [], 'message' => 'No baseline exists. Build one first.'];
    }

    $baseline = [];
    foreach ($rows as $row) {
        $baseline[$row['file_path']] = $row;
    }

    // Scan current files.
    $paths = json_decode($settings['file_integrity_paths'] ?? '[]', true);
    if (!is_array($paths) || empty($paths)) {
        $paths = ['kernel', 'src', 'modules', 'public/index.php', 'bootstrap.php', 'config'];
    }

    $currentFiles = [];
    foreach ($paths as $relPath) {
        $absPath = $basePath . '/' . ltrim($relPath, '/');
        if (is_file($absPath)) {
            $currentFiles[] = $absPath;
        } elseif (is_dir($absPath)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $ext = strtolower($file->getExtension());
                    if (in_array($ext, ['php', 'json', 'sql', 'disyl', 'html', 'js', 'ts', 'tsx', 'css', 'env', 'md', 'yml', 'yaml', 'xml'], true)) {
                        $currentFiles[] = $file->getPathname();
                    }
                }
            }
        }
    }

    $modified = [];
    $added    = [];
    $seen     = [];

    foreach ($currentFiles as $filePath) {
        $relFile = str_replace($basePath . '/', '', $filePath);
        $seen[$relFile] = true;
        $hash = hash_file('sha256', $filePath);

        if (!isset($baseline[$relFile])) {
            $added[] = $relFile;
        } elseif ($hash !== $baseline[$relFile]['sha256_hash']) {
            $modified[] = $relFile;
        }
    }

    // Detect deleted files.
    $deleted = [];
    foreach ($baseline as $relFile => $row) {
        if (!isset($seen[$relFile])) {
            $deleted[] = $relFile;
        }
    }

    // Mark mismatches in DB.
    if (!empty($modified) || !empty($deleted)) {
        $updateStmt = $db->prepare(
            'UPDATE security_file_baselines SET mismatch_detected_at = NOW(), last_checked_at = NOW() WHERE file_path = ?'
        );
        foreach (array_merge($modified, $deleted) as $f) {
            $updateStmt->execute([$f]);
        }

        securityAuditLog('integrity_mismatch', 'critical', [
            'modified' => $modified,
            'added'    => $added,
            'deleted'  => $deleted,
        ]);

        // Fire event.
        if (function_exists('kernelEmitEvent')) {
            try {
                kernelEmitEvent('security.integrity_mismatch', [
                    'modified_count' => count($modified) + count($deleted) + count($added),
                    'files'          => array_merge($modified, $added, $deleted),
                ]);
            } catch (\Throwable $ignored) {
            }
        }
    } else {
        // Update last_checked_at for all.
        $db->exec('UPDATE security_file_baselines SET last_checked_at = NOW()');
    }

    $ok = empty($modified) && empty($added) && empty($deleted);
    return compact('ok', 'modified', 'added', 'deleted');
}

/**
 * Get a summary of the file integrity status.
 */
function securityGetIntegrityReport(): array
{
    $db = securityDb();

    $totalStmt = $db->query('SELECT COUNT(*) FROM security_file_baselines');
    $total = (int)$totalStmt->fetchColumn();

    $mismatchStmt = $db->query('SELECT COUNT(*) FROM security_file_baselines WHERE mismatch_detected_at IS NOT NULL');
    $mismatches = (int)$mismatchStmt->fetchColumn();

    $lastCheck = $db->query('SELECT MAX(last_checked_at) FROM security_file_baselines')->fetchColumn();

    return [
        'total_files'   => $total,
        'mismatches'    => $mismatches,
        'last_check'    => $lastCheck ?: 'Never',
        'has_baseline'  => $total > 0,
    ];
}
