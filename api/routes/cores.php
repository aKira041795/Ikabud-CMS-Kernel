<?php
/**
 * Shared Cores Management API Routes
 * Handles listing, checking updates, and updating shared CMS cores
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Official CMS version URLs
$cmsVersionUrls = [
    'wordpress' => 'https://api.wordpress.org/core/version-check/1.7/',
    'drupal' => 'https://updates.drupal.org/release-history/drupal/current',
    'drupal11' => 'https://updates.drupal.org/release-history/drupal/current',
    'joomla' => 'https://downloads.joomla.org/api/v1/latest/cms',
    'joomla5' => 'https://downloads.joomla.org/api/v1/latest/cms'
];

// Major version mapping for filtering
$cmsMajorVersions = [
    'drupal' => '10',
    'drupal11' => '11',
    'joomla' => '4',
    'joomla5' => '5'
];

/**
 * Get installed version from core files
 */
function getInstalledVersion(string $corePath, string $cmsType): ?string {
    switch ($cmsType) {
        case 'wordpress':
            $versionFile = $corePath . '/wp-includes/version.php';
            if (file_exists($versionFile)) {
                $content = file_get_contents($versionFile);
                if (preg_match("/\\\$wp_version\s*=\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                    return $matches[1];
                }
            }
            break;
            
        case 'drupal':
        case 'drupal11':
            // Check composer.json for Drupal version
            $composerFile = $corePath . '/core/lib/Drupal.php';
            if (file_exists($composerFile)) {
                $content = file_get_contents($composerFile);
                if (preg_match("/const\s+VERSION\s*=\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                    return $matches[1];
                }
            }
            break;
            
        case 'joomla':
        case 'joomla5':
            $versionFile = $corePath . '/libraries/src/Version.php';
            if (file_exists($versionFile)) {
                $content = file_get_contents($versionFile);
                if (preg_match("/MAJOR_VERSION\s*=\s*(\d+)/", $content, $major) &&
                    preg_match("/MINOR_VERSION\s*=\s*(\d+)/", $content, $minor) &&
                    preg_match("/PATCH_VERSION\s*=\s*(\d+)/", $content, $patch)) {
                    return $major[1] . '.' . $minor[1] . '.' . $patch[1];
                }
            }
            break;
    }
    return null;
}

/**
 * Get latest version from official API
 */
function getLatestVersion(string $cmsType): ?array {
    global $cmsVersionUrls, $cmsMajorVersions;
    
    $url = $cmsVersionUrls[$cmsType] ?? null;
    if (!$url) return null;
    
    $majorVersion = $cmsMajorVersions[$cmsType] ?? null;
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'Ikabud-Kernel/1.0'
        ]
    ]);
    
    try {
        $response = @file_get_contents($url, false, $ctx);
        if (!$response) return null;
        
        switch ($cmsType) {
            case 'wordpress':
                $data = json_decode($response, true);
                if (isset($data['offers'][0]['version'])) {
                    return [
                        'version' => $data['offers'][0]['version'],
                        'download_url' => $data['offers'][0]['download'] ?? null,
                        'release_date' => null
                    ];
                }
                break;
                
            case 'drupal':
            case 'drupal11':
                $xml = @simplexml_load_string($response);
                if ($xml && isset($xml->releases->release)) {
                    // Find the latest STABLE release for the correct major version
                    foreach ($xml->releases->release as $release) {
                        $version = (string)$release->version;
                        // Skip dev, alpha, beta, rc versions
                        if (preg_match('/(dev|alpha|beta|rc)/i', $version)) {
                            continue;
                        }
                        // Check major version matches (e.g., "11.x" for drupal11)
                        if ($majorVersion && !str_starts_with($version, $majorVersion . '.')) {
                            continue;
                        }
                        return [
                            'version' => $version,
                            'download_url' => (string)$release->download_link,
                            'release_date' => date('Y-m-d', (int)$release->date)
                        ];
                    }
                }
                break;
                
            case 'joomla':
            case 'joomla5':
                $data = json_decode($response, true);
                // Joomla API returns branches array
                if (isset($data['branches']) && is_array($data['branches'])) {
                    $branchName = $majorVersion === '4' ? 'Joomla! 4' : 'Joomla! 5';
                    foreach ($data['branches'] as $branch) {
                        if (isset($branch['branch']) && $branch['branch'] === $branchName) {
                            $version = $branch['version'];
                            // Construct download URL - version in path uses dashes (5-4-1), filename uses dots (5.4.1)
                            $versionPath = str_replace('.', '-', $version);
                            $downloadUrl = "https://downloads.joomla.org/cms/joomla{$majorVersion}/{$versionPath}/Joomla_{$version}-Stable-Full_Package.zip";
                            return [
                                'version' => $version,
                                'download_url' => $downloadUrl,
                                'release_date' => null
                            ];
                        }
                    }
                }
                // Fallback to old format
                if (isset($data['version'])) {
                    $version = $data['version'];
                    if ($majorVersion && !str_starts_with($version, $majorVersion . '.')) {
                        return null;
                    }
                    return [
                        'version' => $version,
                        'download_url' => $data['downloadUrl'] ?? null,
                        'release_date' => $data['releaseDate'] ?? null
                    ];
                }
                break;
        }
    } catch (Exception $e) {
        error_log("Failed to fetch latest version for {$cmsType}: " . $e->getMessage());
    }
    
    return null;
}

/**
 * Compare versions to check if update is available
 */
function isUpdateAvailable(?string $installed, ?string $latest): bool {
    if (!$installed || !$latest) return false;
    return version_compare($latest, $installed, '>');
}

/**
 * Get core display name
 */
function getCoreDisplayName(string $cmsType): string {
    $names = [
        'wordpress' => 'WordPress',
        'drupal' => 'Drupal 10',
        'drupal11' => 'Drupal 11',
        'joomla' => 'Joomla 4',
        'joomla5' => 'Joomla 5'
    ];
    return $names[$cmsType] ?? ucfirst($cmsType);
}

/**
 * Get core icon/color
 */
function getCoreIcon(string $cmsType): string {
    $icons = [
        'wordpress' => 'wordpress',
        'drupal' => 'drupal',
        'drupal11' => 'drupal',
        'joomla' => 'joomla',
        'joomla5' => 'joomla'
    ];
    return $icons[$cmsType] ?? 'default';
}

// List all shared cores with version info
$app->get('/api/v1/cores', function (Request $request, Response $response) {
    $coresPath = dirname(__DIR__, 2) . '/shared-cores';
    $cores = [];
    
    if (is_dir($coresPath)) {
        $dirs = scandir($coresPath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === '.gitkeep' || !is_dir($coresPath . '/' . $dir)) {
                continue;
            }
            
            // Skip wp-config.php file
            if (strpos($dir, '.php') !== false) continue;
            
            $corePath = $coresPath . '/' . $dir;
            $installedVersion = getInstalledVersion($corePath, $dir);
            
            // Get directory size (skip symlinks and handle errors)
            $size = 0;
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($corePath, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                $iterator->setMaxDepth(10); // Limit depth to avoid infinite loops
                foreach ($iterator as $file) {
                    try {
                        if ($file->isFile() && !$file->isLink()) {
                            $size += $file->getSize();
                        }
                    } catch (Exception $e) {
                        // Skip files that can't be read
                        continue;
                    }
                }
            } catch (Exception $e) {
                // If iteration fails, just use 0
                $size = 0;
            }
            
            $cores[] = [
                'id' => $dir,
                'name' => getCoreDisplayName($dir),
                'icon' => getCoreIcon($dir),
                'path' => $corePath,
                'installed_version' => $installedVersion,
                'size' => $size,
                'size_formatted' => formatBytes($size),
                'last_modified' => date('Y-m-d H:i:s', filemtime($corePath))
            ];
        }
    }
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'cores' => $cores,
        'total' => count($cores)
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Check for updates on all cores
$app->get('/api/v1/cores/check-updates', function (Request $request, Response $response) {
    $coresPath = dirname(__DIR__, 2) . '/shared-cores';
    $updates = [];
    
    if (is_dir($coresPath)) {
        $dirs = scandir($coresPath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === '.gitkeep' || !is_dir($coresPath . '/' . $dir)) {
                continue;
            }
            if (strpos($dir, '.php') !== false) continue;
            
            $corePath = $coresPath . '/' . $dir;
            $installedVersion = getInstalledVersion($corePath, $dir);
            $latestInfo = getLatestVersion($dir);
            
            $hasUpdate = isUpdateAvailable($installedVersion, $latestInfo['version'] ?? null);
            
            $updates[] = [
                'id' => $dir,
                'name' => getCoreDisplayName($dir),
                'installed_version' => $installedVersion,
                'latest_version' => $latestInfo['version'] ?? null,
                'download_url' => $latestInfo['download_url'] ?? null,
                'release_date' => $latestInfo['release_date'] ?? null,
                'update_available' => $hasUpdate
            ];
        }
    }
    
    $hasAnyUpdates = count(array_filter($updates, fn($u) => $u['update_available'])) > 0;
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'updates' => $updates,
        'has_updates' => $hasAnyUpdates,
        'checked_at' => date('Y-m-d H:i:s')
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get single core details
$app->get('/api/v1/cores/{coreId}', function (Request $request, Response $response, array $args) {
    $coreId = $args['coreId'];
    $coresPath = dirname(__DIR__, 2) . '/shared-cores';
    $corePath = $coresPath . '/' . $coreId;
    
    if (!is_dir($corePath)) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Core not found'
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $installedVersion = getInstalledVersion($corePath, $coreId);
    $latestInfo = getLatestVersion($coreId);
    
    // Count files (skip symlinks and handle errors)
    $fileCount = 0;
    $size = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($corePath, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $iterator->setMaxDepth(10);
        foreach ($iterator as $file) {
            try {
                if ($file->isFile() && !$file->isLink()) {
                    $fileCount++;
                    $size += $file->getSize();
                }
            } catch (Exception $e) {
                continue;
            }
        }
    } catch (Exception $e) {
        // If iteration fails, use defaults
    }
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'core' => [
            'id' => $coreId,
            'name' => getCoreDisplayName($coreId),
            'icon' => getCoreIcon($coreId),
            'path' => $corePath,
            'installed_version' => $installedVersion,
            'latest_version' => $latestInfo['version'] ?? null,
            'download_url' => $latestInfo['download_url'] ?? null,
            'release_date' => $latestInfo['release_date'] ?? null,
            'update_available' => isUpdateAvailable($installedVersion, $latestInfo['version'] ?? null),
            'size' => $size,
            'size_formatted' => formatBytes($size),
            'file_count' => $fileCount,
            'last_modified' => date('Y-m-d H:i:s', filemtime($corePath))
        ]
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Update a specific core
$app->post('/api/v1/cores/{coreId}/update', function (Request $request, Response $response, array $args) {
    $coreId = $args['coreId'];
    $coresPath = dirname(__DIR__, 2) . '/shared-cores';
    $corePath = $coresPath . '/' . $coreId;
    
    if (!is_dir($corePath)) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Core not found'
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $latestInfo = getLatestVersion($coreId);
    
    if (!$latestInfo || !$latestInfo['download_url']) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Could not fetch download URL for update'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $installedVersion = getInstalledVersion($corePath, $coreId);
    
    if (!isUpdateAvailable($installedVersion, $latestInfo['version'])) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'No update available - already at latest version'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Create backup directory
    $backupPath = dirname(__DIR__, 2) . '/storage/core-backups';
    if (!is_dir($backupPath)) {
        mkdir($backupPath, 0755, true);
    }
    
    $backupFile = $backupPath . '/' . $coreId . '-' . $installedVersion . '-' . date('Ymd-His') . '.tar.gz';
    
    // Create backup
    $backupCmd = "cd " . escapeshellarg($coresPath) . " && tar -czf " . escapeshellarg($backupFile) . " " . escapeshellarg($coreId);
    exec($backupCmd, $output, $returnCode);
    
    if ($returnCode !== 0) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to create backup before update'
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    // Download new version
    $tempFile = sys_get_temp_dir() . '/' . $coreId . '-' . $latestInfo['version'] . '.zip';
    $downloadUrl = $latestInfo['download_url'];
    
    // Use curl for better download handling
    $ch = curl_init($downloadUrl);
    $fp = fopen($tempFile, 'w');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Ikabud-Kernel/1.0');
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    
    if (!$success || $httpCode !== 200) {
        @unlink($tempFile);
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to download update package'
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    // Extract to temp directory first
    $tempExtract = sys_get_temp_dir() . '/ikabud-core-update-' . $coreId;
    if (is_dir($tempExtract)) {
        exec("rm -rf " . escapeshellarg($tempExtract));
    }
    mkdir($tempExtract, 0755, true);
    
    // Determine archive type and extract
    $extractCmd = '';
    if (strpos($downloadUrl, '.tar.gz') !== false || strpos($downloadUrl, '.tgz') !== false) {
        $extractCmd = "tar -xzf " . escapeshellarg($tempFile) . " -C " . escapeshellarg($tempExtract);
    } else {
        $extractCmd = "unzip -q " . escapeshellarg($tempFile) . " -d " . escapeshellarg($tempExtract);
    }
    
    exec($extractCmd, $output, $returnCode);
    @unlink($tempFile);
    
    if ($returnCode !== 0) {
        exec("rm -rf " . escapeshellarg($tempExtract));
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to extract update package'
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    // Find the extracted directory (usually one level deep for Drupal/WP, flat for Joomla)
    $extractedDirs = glob($tempExtract . '/*', GLOB_ONLYDIR);
    $extractedFiles = glob($tempExtract . '/*');
    
    // Determine source directory
    // If there's exactly one directory and few/no files at root, use that directory
    // Otherwise use the temp extract directory itself (flat extraction like Joomla)
    $rootFiles = array_filter($extractedFiles, fn($f) => is_file($f));
    if (count($extractedDirs) === 1 && count($rootFiles) <= 2) {
        $sourceDir = $extractedDirs[0];
    } else {
        $sourceDir = $tempExtract;
    }
    
    // Verify extraction worked - check for expected CMS files
    $verifyFiles = [
        'wordpress' => ['wp-includes/version.php', 'wp-admin', 'wp-content'],
        'drupal' => ['core/lib/Drupal.php', 'autoload.php'],
        'drupal11' => ['core/lib/Drupal.php', 'autoload.php'],
        'joomla' => ['libraries/src/Version.php', 'administrator'],
        'joomla5' => ['libraries/src/Version.php', 'administrator']
    ];
    
    $filesToCheck = $verifyFiles[$coreId] ?? [];
    $extractionValid = true;
    foreach ($filesToCheck as $checkFile) {
        if (!file_exists($sourceDir . '/' . $checkFile) && !is_dir($sourceDir . '/' . $checkFile)) {
            $extractionValid = false;
            break;
        }
    }
    
    if (!$extractionValid) {
        exec("rm -rf " . escapeshellarg($tempExtract));
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Extraction verification failed - expected CMS files not found'
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    // For Drupal, preserve the sites directory (contains site-specific configs)
    $preservedSites = null;
    if (($coreId === 'drupal' || $coreId === 'drupal11') && is_dir($corePath . '/sites')) {
        $preservedSites = sys_get_temp_dir() . '/ikabud-preserved-sites-' . $coreId;
        exec("rm -rf " . escapeshellarg($preservedSites));
        exec("cp -a " . escapeshellarg($corePath . '/sites') . " " . escapeshellarg($preservedSites));
    }
    
    // Remove old core and move new one
    $rmResult = 0;
    $mvResult = 0;
    
    // Try removing old core
    exec("rm -rf " . escapeshellarg($corePath) . " 2>&1", $rmOutput, $rmResult);
    
    if ($rmResult !== 0 && is_dir($corePath)) {
        exec("rm -rf " . escapeshellarg($tempExtract));
        if ($preservedSites) exec("rm -rf " . escapeshellarg($preservedSites));
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to remove old core directory. Permission denied. Run: sudo chown -R www-data:www-data ' . $coresPath
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    // Move new core
    exec("mv " . escapeshellarg($sourceDir) . " " . escapeshellarg($corePath) . " 2>&1", $mvOutput, $mvResult);
    
    exec("rm -rf " . escapeshellarg($tempExtract));
    
    if ($mvResult !== 0 || !is_dir($corePath)) {
        // Restore from backup
        exec("cd " . escapeshellarg($coresPath) . " && tar -xzf " . escapeshellarg($backupFile));
        if ($preservedSites) exec("rm -rf " . escapeshellarg($preservedSites));
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to move new core. Restored from backup.'
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    // Restore preserved sites directory for Drupal
    if ($preservedSites && is_dir($preservedSites)) {
        exec("rm -rf " . escapeshellarg($corePath . '/sites'));
        exec("mv " . escapeshellarg($preservedSites) . " " . escapeshellarg($corePath . '/sites'));
    }
    
    // Verify update
    $newVersion = getInstalledVersion($corePath, $coreId);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Core updated successfully',
        'previous_version' => $installedVersion,
        'new_version' => $newVersion,
        'backup_file' => basename($backupFile)
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Restore from backup
$app->post('/api/v1/cores/{coreId}/restore', function (Request $request, Response $response, array $args) {
    $coreId = $args['coreId'];
    $body = json_decode($request->getBody()->getContents(), true);
    $backupFile = $body['backup_file'] ?? null;
    
    if (!$backupFile) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Backup file not specified'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $backupPath = dirname(__DIR__, 2) . '/storage/core-backups/' . basename($backupFile);
    $coresPath = dirname(__DIR__, 2) . '/shared-cores';
    $corePath = $coresPath . '/' . $coreId;
    
    if (!file_exists($backupPath)) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Backup file not found'
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    // Remove current core
    exec("rm -rf " . escapeshellarg($corePath));
    
    // Restore from backup
    $restoreCmd = "cd " . escapeshellarg($coresPath) . " && tar -xzf " . escapeshellarg($backupPath);
    exec($restoreCmd, $output, $returnCode);
    
    if ($returnCode !== 0) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to restore from backup'
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    $restoredVersion = getInstalledVersion($corePath, $coreId);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Core restored successfully',
        'restored_version' => $restoredVersion
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// List available backups
$app->get('/api/v1/cores/{coreId}/backups', function (Request $request, Response $response, array $args) {
    $coreId = $args['coreId'];
    $backupPath = dirname(__DIR__, 2) . '/storage/core-backups';
    $backups = [];
    
    if (is_dir($backupPath)) {
        $files = glob($backupPath . '/' . $coreId . '-*.tar.gz');
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'size_formatted' => formatBytes(filesize($file)),
                'created_at' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
        // Sort by date descending
        usort($backups, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    }
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'backups' => $backups,
        'total' => count($backups)
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Helper function for formatting bytes
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
