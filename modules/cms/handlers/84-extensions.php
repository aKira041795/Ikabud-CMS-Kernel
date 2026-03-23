<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════════
// Theme & Sub-Module Installer — Admin Pages + API Handlers
// ═══════════════════════════════════════════════════════════════════════

/**
 * Admin page: Theme Manager — list installed themes, upload/install new ones.
 */
function cmsAdminThemes(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    $themes = cmsAvailableThemes();
    $active = cmsActiveTheme() ?? 'default';

    echo cmsRender('modules/cms/admin/themes.disyl', array_merge(cmsAdminContext($user, 'themes', [
        ['label' => 'Themes', 'url' => ''],
    ]), [
        'page_title'       => 'Themes',
        'themes_json'      => json_encode($themes),
        'active_theme'     => $active,
    ]));
}

/**
 * Admin page: Sub-Module Manager — list CMS-installed modules only.
 * Kernel/application modules are never shown here.
 */
function cmsAdminModules(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    $modules = _cmsDiscoverSubModules();

    echo cmsRender('modules/cms/admin/modules.disyl', array_merge(cmsAdminContext($user, 'modules', [
        ['label' => 'Modules', 'url' => ''],
    ]), [
        'page_title'    => 'CMS Modules',
        'modules_json'  => json_encode($modules),
    ]));
}

// ─── Theme API ────────────────────────────────────────────────────────

/**
 * API: List available themes with metadata.
 */
function cmsApiThemeList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');

    echo json_encode(['ok' => true, 'data' => cmsAvailableThemes()]);
    exit;
}

/**
 * API: Upload and install a theme from a .zip archive.
 * Expects: multipart form with 'theme' file field.
 * The zip must contain a theme.json at root (or inside a single top-level folder).
 */
function cmsApiThemeUpload(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');

    $file = kernelUploadedFile('theme');
    if (!$file || !empty($file['error']) || empty($file['tmp_name'])) {
        _cmsAuditInstaller('theme.upload', 'theme', 'unknown', 'failed', 'No theme file uploaded.');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No theme file uploaded.']);
        exit;
    }

    // Validate file type
    $name = $file['name'] ?? '';
    if (!preg_match('/\.zip$/i', $name)) {
        _cmsAuditInstaller('theme.upload', 'theme', 'unknown', 'failed', 'Theme upload rejected: only .zip files are accepted.', ['filename' => (string)$name]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Only .zip files are accepted.']);
        exit;
    }

    // Size limit: 50 MB
    if (($file['size'] ?? 0) > 50 * 1024 * 1024) {
        _cmsAuditInstaller('theme.upload', 'theme', 'unknown', 'failed', 'Theme package exceeds 50 MB limit.', ['filename' => (string)$name]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Theme package exceeds 50 MB limit.']);
        exit;
    }

    $tmpPath = $file['tmp_name'];

    // Extract to temp directory first for validation
    $extractDir = sys_get_temp_dir() . '/cms_theme_' . uniqid();
    @mkdir($extractDir, 0775, true);

    $zip = new \ZipArchive();
    $res = $zip->open($tmpPath);
    if ($res !== true) {
        @rmdir($extractDir);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Failed to open zip archive (code: ' . $res . ').']);
        exit;
    }

    $zipSafetyError = _cmsValidateZipArchiveSafe($zip);
    if ($zipSafetyError !== null) {
        $zip->close();
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('theme.upload', 'theme', 'unknown', 'failed', $zipSafetyError, ['filename' => (string)$name]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $zipSafetyError]);
        exit;
    }

    $zip->extractTo($extractDir);
    $zip->close();

    // Find theme.json — either at root or inside a single top-level folder
    $themeRoot = _cmsFindThemeRoot($extractDir);
    if ($themeRoot === null) {
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('theme.upload', 'theme', 'unknown', 'failed', 'Invalid theme package: no theme.json found.', ['filename' => (string)$name]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid theme package: no theme.json found.']);
        exit;
    }

    // Read and validate theme.json
    $metaRaw = @file_get_contents($themeRoot . '/theme.json');
    $meta = json_decode((string)$metaRaw, true);
    if (!is_array($meta)) {
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('theme.upload', 'theme', 'unknown', 'failed', 'Invalid theme.json: malformed JSON.', ['filename' => (string)$name]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid theme.json: malformed JSON.']);
        exit;
    }

    $manifestError = _cmsValidateThemeManifest($meta);
    if ($manifestError !== null) {
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('theme.upload', 'theme', 'unknown', 'failed', $manifestError, ['filename' => (string)($file['name'] ?? '')]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $manifestError]);
        exit;
    }

    // Determine slug from directory name or sanitize from name
    $slug = basename($themeRoot);
    // If the theme root IS the extract dir (theme.json at zip root), derive slug from name
    if (realpath($themeRoot) === realpath($extractDir)) {
        $slug = preg_replace('/[^a-z0-9_-]/', '-', strtolower(trim($meta['name'])));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
    }

    if ($slug === '' || $slug === 'default') {
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('theme.upload', 'theme', 'unknown', 'failed', 'Invalid theme slug.', ['theme_name' => (string)($meta['name'] ?? '')]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid theme slug.']);
        exit;
    }

    // Install to storage/cms-themes/{slug}
    $destDir = cmsThemesPath() . '/' . $slug;

    // If theme already exists, remove it first (upgrade)
    $isUpgrade = is_dir($destDir);
    if ($isUpgrade) {
        _cmsDeleteDirRecursive($destDir);
    }

    // Move/copy theme files
    if (!@rename($themeRoot, $destDir)) {
        // rename might fail across filesystems — fall back to recursive copy
        _cmsCopyDirRecursive($themeRoot, $destDir);
    }

    // Copy public assets to public/assets/cms/themes/{slug}/ if the theme has a style.css or css/js folders
    _cmsSyncThemePublicAssets($slug, $destDir);

    // Clean up temp
    _cmsDeleteDirRecursive($extractDir);

    $meta['slug'] = $slug;

    // If upgrading the active theme, clear caches
    $currentActive = cmsActiveTheme();
    if ($isUpgrade && $currentActive === $slug) {
        cmsCacheFlushAll();
        cmsTemplateCacheFlush();
    }

    kernelFlushCodeCaches();

    echo json_encode([
        'ok'       => true,
        'theme'    => $meta,
        'upgraded' => $isUpgrade,
        'message'  => $isUpgrade ? 'Theme "' . $meta['name'] . '" upgraded.' : 'Theme "' . $meta['name'] . '" installed.',
    ]);

    _cmsAuditInstaller('theme.upload', 'theme', $slug, 'success', $isUpgrade ? 'Theme upgraded.' : 'Theme installed.', [
        'theme_name' => (string)($meta['name'] ?? $slug),
        'upgraded'   => $isUpgrade,
    ]);
    exit;
}

/**
 * API: Activate a theme.
 */
function cmsApiThemeActivate(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');

    $input = cmsInput();
    $slug = trim((string)($input['slug'] ?? ''));

    if ($slug === '' || $slug === 'default') {
        // Deactivate — revert to module default
        cmsActivateThemeSymlink(null);
        saveModuleSettings('cms', ['active_theme' => 'default']);
        cmsResetSettingsCache();
        cmsResetThemeRuntimeCache();
        // Flush all cached pages since theme-dependent rendering has changed
        cmsCacheFlushAll();
        cmsTemplateCacheFlush();
        _cmsAuditInstaller('theme.activate', 'theme', 'default', 'success', 'Reverted to default theme.');
        echo json_encode(['ok' => true, 'message' => 'Reverted to default theme.']);
        exit;
    }

    $available = cmsAvailableThemes();
    $valid = array_column($available, 'slug');
    if (!in_array($slug, $valid, true)) {
        _cmsAuditInstaller('theme.activate', 'theme', $slug, 'failed', 'Theme not found.');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Theme not found.']);
        exit;
    }

    cmsActivateThemeSymlink($slug);
    saveModuleSettings('cms', ['active_theme' => $slug]);
    cmsResetSettingsCache();
    cmsResetThemeRuntimeCache();
    // Flush all cached pages since theme-dependent rendering has changed
    cmsCacheFlushAll();
    cmsTemplateCacheFlush();
    _cmsAuditInstaller('theme.activate', 'theme', $slug, 'success', 'Theme activated.');

    echo json_encode(['ok' => true, 'message' => 'Theme "' . $slug . '" activated.']);
    exit;
}

/**
 * API: Delete a theme (cannot delete the active theme).
 */
function cmsApiThemeDelete(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');

    $slug = trim((string)($params['slug'] ?? ''));
    if ($slug === '') {
        _cmsAuditInstaller('theme.delete', 'theme', 'unknown', 'failed', 'Missing theme slug.');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing theme slug.']);
        exit;
    }

    $active = cmsActiveTheme() ?? 'default';
    if ($slug === $active) {
        _cmsAuditInstaller('theme.delete', 'theme', $slug, 'failed', 'Cannot delete active theme.');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Cannot delete the active theme. Switch to another theme first.']);
        exit;
    }

    $dir = cmsThemesPath() . '/' . $slug;
    if (!is_dir($dir)) {
        _cmsAuditInstaller('theme.delete', 'theme', $slug, 'failed', 'Theme not found.');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Theme not found.']);
        exit;
    }

    _cmsDeleteDirRecursive($dir);

    // Also remove public assets if they exist
    $publicDir = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/') . '/public/assets/cms/themes/' . $slug;
    if (is_dir($publicDir)) {
        _cmsDeleteDirRecursive($publicDir);
    }

    echo json_encode(['ok' => true, 'message' => 'Theme "' . $slug . '" deleted.']);
    _cmsAuditInstaller('theme.delete', 'theme', $slug, 'success', 'Theme deleted.');
    exit;
}

// ─── Sub-Module API ───────────────────────────────────────────────────

/**
 * API: Upload and install a sub-module from a .zip archive.
 * The zip must contain a module.json at root (or inside a single top-level folder).
 */
function cmsApiModuleUpload(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');

    $file = kernelUploadedFile('module');
    if (!$file || !empty($file['error']) || empty($file['tmp_name'])) {
        _cmsAuditInstaller('module.upload', 'module', 'unknown', 'failed', 'No module file uploaded.');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No module file uploaded.']);
        exit;
    }

    if (!preg_match('/\.zip$/i', $file['name'] ?? '')) {
        _cmsAuditInstaller('module.upload', 'module', 'unknown', 'failed', 'Module upload rejected: only .zip files are accepted.', ['filename' => (string)($file['name'] ?? '')]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Only .zip files are accepted.']);
        exit;
    }

    if (($file['size'] ?? 0) > 50 * 1024 * 1024) {
        _cmsAuditInstaller('module.upload', 'module', 'unknown', 'failed', 'Module package exceeds 50 MB limit.', ['filename' => (string)($file['name'] ?? '')]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Module package exceeds 50 MB limit.']);
        exit;
    }

    $tmpPath = $file['tmp_name'];
    $extractDir = sys_get_temp_dir() . '/cms_module_' . uniqid();
    @mkdir($extractDir, 0775, true);

    $zip = new \ZipArchive();
    $res = $zip->open($tmpPath);
    if ($res !== true) {
        @rmdir($extractDir);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Failed to open zip archive (code: ' . $res . ').']);
        exit;
    }

    $zipSafetyError = _cmsValidateZipArchiveSafe($zip);
    if ($zipSafetyError !== null) {
        $zip->close();
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('module.upload', 'module', 'unknown', 'failed', $zipSafetyError, ['filename' => (string)($file['name'] ?? '')]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $zipSafetyError]);
        exit;
    }

    $zip->extractTo($extractDir);
    $zip->close();

    // Find module.json
    $moduleRoot = _cmsFindModuleRoot($extractDir);
    if ($moduleRoot === null) {
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('module.upload', 'module', 'unknown', 'failed', 'Invalid module package: no module.json found.', ['filename' => (string)($file['name'] ?? '')]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid module package: no module.json found.']);
        exit;
    }

    $metaRaw = @file_get_contents($moduleRoot . '/module.json');
    $meta = json_decode((string)$metaRaw, true);
    if (!is_array($meta)) {
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('module.upload', 'module', 'unknown', 'failed', 'Invalid module.json: malformed JSON.', ['filename' => (string)($file['name'] ?? '')]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid module.json: malformed JSON.']);
        exit;
    }

    $manifestError = _cmsValidateModuleManifest($meta);
    if ($manifestError !== null) {
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('module.upload', 'module', 'unknown', 'failed', $manifestError, ['filename' => (string)($file['name'] ?? '')]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $manifestError]);
        exit;
    }

    $moduleId = trim((string)$meta['id']);
    if (!preg_match('/^[a-z0-9_-]+$/i', $moduleId)) {
        _cmsDeleteDirRecursive($extractDir);
        _cmsAuditInstaller('module.upload', 'module', $moduleId, 'failed', 'Invalid module id.');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid module id. Use only letters, numbers, dash, and underscore.']);
        exit;
    }

    // Prevent overwriting kernel/core modules that were not installed via CMS
    $kernelModules = _cmsGetKernelModuleIds();
    if (in_array($moduleId, $kernelModules, true)) {
        // In multi-tenant mode a module installed by one tenant will appear on
        // disk but NOT in the current tenant's CMS registry, which causes
        // _cmsGetKernelModuleIds() to classify it as a kernel module.
        // Detect this case: module is on disk AND its directory was created by
        // the CMS installer (not a native bundled module) — indicated by the
        // absence of the module from the compile-time bundled set.
        // Heuristic: the module dir exists AND the global non-tenant CMS
        // registry (or any known CMS registry) lists it as a CMS module.
        $modulesDir = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/') . '/modules';
        $existsOnDisk = is_dir($modulesDir . '/' . $moduleId);
        $isCmsInstalledGlobally = _cmsIsInGlobalOrAnyTenantRegistry($moduleId);

        if (!$existsOnDisk || !$isCmsInstalledGlobally) {
            _cmsDeleteDirRecursive($extractDir);
            _cmsAuditInstaller('module.upload', 'module', $moduleId, 'failed', 'Cannot overwrite kernel module from CMS.');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Cannot overwrite kernel module "' . $moduleId . '". Kernel modules are managed at the application level.']);
            exit;
        }

        // Module already on disk from another tenant's CMS install.
        // Register it for this tenant without copying files (cross-tenant adopt).
        _cmsDeleteDirRecursive($extractDir);
        enableModule($moduleId);
        _cmsRegisterSubModule($moduleId);
        _cmsAuditInstaller('module.upload', 'module', $moduleId, 'success', 'Module adopted from shared disk (cross-tenant).', [
            'module_name' => (string)($meta['name'] ?? $moduleId),
            'upgraded'    => false,
        ]);
        echo json_encode([
            'ok'      => true,
            'module'  => $meta,
            'upgraded' => false,
            'message' => 'Module "' . ($meta['name'] ?? $moduleId) . '" installed and enabled.',
        ]);
        exit;
    }

    // Determine target directory
    $modulesDir = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/') . '/modules';
    $destDir = $modulesDir . '/' . $moduleId;

    $isUpgrade = is_dir($destDir);
    if ($isUpgrade) {
        // Backup existing before overwrite
        $backupDir = $destDir . '.bak_' . date('Ymd_His');
        @rename($destDir, $backupDir);
    }

    if (!@rename($moduleRoot, $destDir)) {
        _cmsCopyDirRecursive($moduleRoot, $destDir);
    }

    // Mark this module directory as CMS-owned so other tenants (multi-tenant)
    // can recognise it as a peer CMS module rather than a kernel module.
    @file_put_contents($destDir . '/.cms-owned', json_encode([
        'installed_at' => date('Y-m-d H:i:s'),
        'installed_by' => 'cms-installer',
    ]));

    // Enable the module by default
    enableModule($moduleId);

    // Register in the CMS sub-module registry so it appears in the CMS admin
    _cmsRegisterSubModule($moduleId);

    // Run migrations if module declares them
    _cmsRunModuleMigrations($moduleId, $meta, $destDir);

    // Flush code caches so the new module is picked up immediately
    kernelFlushCodeCaches();

    // Clean up temp
    _cmsDeleteDirRecursive($extractDir);

    echo json_encode([
        'ok'       => true,
        'module'   => $meta,
        'upgraded' => $isUpgrade,
        'message'  => $isUpgrade ? 'Module "' . ($meta['name'] ?? $moduleId) . '" upgraded. Reload to apply.' : 'Module "' . ($meta['name'] ?? $moduleId) . '" installed and enabled.',
    ]);
    _cmsAuditInstaller('module.upload', 'module', $moduleId, 'success', $isUpgrade ? 'Module upgraded.' : 'Module installed.', [
        'module_name' => (string)($meta['name'] ?? $moduleId),
        'upgraded'    => $isUpgrade,
    ]);
    exit;
}

/**
 * API: Enable or disable a CMS-installed module (toggle).
 * Only modules in the CMS sub-module registry can be toggled here.
 */
function cmsApiModuleToggle(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');

    $input = cmsInput();
    $moduleId = trim((string)($input['module_id'] ?? ''));
    $enable = !empty($input['enable']);

    if ($moduleId === '') {
        _cmsAuditInstaller('module.toggle', 'module', 'unknown', 'failed', 'Missing module_id.');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing module_id.']);
        exit;
    }

    // Only allow toggling modules that were installed via CMS
    if (!_cmsIsRegisteredSubModule($moduleId)) {
        _cmsAuditInstaller('module.toggle', 'module', $moduleId, 'failed', 'Blocked toggle attempt for kernel module.');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Module "' . $moduleId . '" is a kernel module and cannot be managed from the CMS.']);
        exit;
    }

    if ($enable) {
        enableModule($moduleId);
    } else {
        disableModule($moduleId);
    }

    echo json_encode([
        'ok'      => true,
        'module_id' => $moduleId,
        'enabled' => $enable,
        'message' => 'Module "' . $moduleId . '" ' . ($enable ? 'enabled' : 'disabled') . '. Reload to apply changes.',
    ]);
    _cmsAuditInstaller('module.toggle', 'module', $moduleId, 'success', $enable ? 'Module enabled.' : 'Module disabled.');
    exit;
}

/**
 * API: Delete a CMS-installed module (cannot delete kernel or enabled modules).
 * Only modules in the CMS sub-module registry can be deleted here.
 */
function cmsApiModuleDelete(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');

    $moduleId = trim((string)($params['module_id'] ?? ''));
    if ($moduleId === '') {
        _cmsAuditInstaller('module.delete', 'module', 'unknown', 'failed', 'Missing module_id.');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing module_id.']);
        exit;
    }

    // Only allow deleting modules that were installed via CMS
    if (!_cmsIsRegisteredSubModule($moduleId)) {
        _cmsAuditInstaller('module.delete', 'module', $moduleId, 'failed', 'Blocked delete attempt for kernel module.');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Module "' . $moduleId . '" is a kernel module and cannot be deleted from the CMS.']);
        exit;
    }

    if (isModuleEnabled($moduleId)) {
        _cmsAuditInstaller('module.delete', 'module', $moduleId, 'failed', 'Delete blocked: module is enabled.');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Disable the module before deleting it.']);
        exit;
    }

    $modulesDir = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/') . '/modules';
    $dir = $modulesDir . '/' . $moduleId;
    if (!is_dir($dir)) {
        _cmsAuditInstaller('module.delete', 'module', $moduleId, 'failed', 'Module directory not found.');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Module directory not found.']);
        exit;
    }

    // In multi-tenant mode, preserve the shared module directory on disk so
    // other tenants are not affected. Only unregister from the current tenant.
    // In single-tenant mode (or when the module has no CMS-owned marker),
    // delete the directory entirely.
    $multiTenantActive = function_exists('moduleTenantSettingsModeEnabled') && moduleTenantSettingsModeEnabled();
    if (!$multiTenantActive) {
        _cmsDeleteDirRecursive($dir);
    }

    // Remove from CMS sub-module registry
    _cmsUnregisterSubModule($moduleId);

    echo json_encode(['ok' => true, 'message' => 'Module "' . $moduleId . '" deleted.']);
    _cmsAuditInstaller('module.delete', 'module', $moduleId, 'success', 'Module deleted.');
    exit;
}

// ─── Internal Helpers ─────────────────────────────────────────────────

/**
 * Validate uploaded ZIP archive entries before extraction.
 * Blocks path traversal, absolute paths, null bytes, and symlink entries.
 */
function _cmsValidateZipArchiveSafe(\ZipArchive $zip): ?string
{
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = (string)$zip->getNameIndex($i);
        if ($entryName === '') {
            continue;
        }

        $normalized = str_replace('\\', '/', $entryName);
        if (
            str_starts_with($normalized, '/')
            || str_starts_with($normalized, '../')
            || str_contains($normalized, '/../')
            || str_contains($normalized, '/..')
            || preg_match('/^[a-zA-Z]:\//', $normalized)
            || str_contains($normalized, "\0")
        ) {
            return 'Invalid archive entry path: ' . $entryName;
        }

        // Block symlink entries
        $stat = $zip->statIndex($i);
        if (is_array($stat) && isset($stat['external_attributes'])) {
            $mode = (($stat['external_attributes'] >> 16) & 0xF000);
            if ($mode === 0xA000) {
                return 'Archive contains symbolic links, which are not allowed.';
            }
        }
    }

    return null;
}

/**
 * Validate theme.json schema (strict but backward-compatible).
 */
function _cmsValidateThemeManifest(array $meta): ?string
{
    $name = trim((string)($meta['name'] ?? ''));
    if ($name === '') {
        return 'Invalid theme.json: "name" is required.';
    }

    if (isset($meta['version']) && trim((string)$meta['version']) === '') {
        return 'Invalid theme.json: "version" cannot be empty when provided.';
    }

    if (isset($meta['author']) && !is_string($meta['author'])) {
        return 'Invalid theme.json: "author" must be a string.';
    }

    if (isset($meta['description']) && !is_string($meta['description'])) {
        return 'Invalid theme.json: "description" must be a string.';
    }

    if (isset($meta['templates']) && !is_array($meta['templates'])) {
        return 'Invalid theme.json: "templates" must be an array.';
    }

    if (isset($meta['pageTemplates']) && !is_array($meta['pageTemplates'])) {
        return 'Invalid theme.json: "pageTemplates" must be an array.';
    }

    return null;
}

/**
 * Validate module.json schema for CMS-installed sub-modules.
 */
function _cmsValidateModuleManifest(array $meta): ?string
{
    $moduleId = trim((string)($meta['id'] ?? ''));
    if ($moduleId === '') {
        return 'Invalid module.json: "id" is required.';
    }

    if (!preg_match('/^[a-z0-9_-]+$/i', $moduleId)) {
        return 'Invalid module.json: "id" may contain only letters, numbers, dash, and underscore.';
    }

    $name = trim((string)($meta['name'] ?? ''));
    if ($name === '') {
        return 'Invalid module.json: "name" is required.';
    }

    if (isset($meta['version']) && trim((string)$meta['version']) === '') {
        return 'Invalid module.json: "version" cannot be empty when provided.';
    }

    return null;
}

/**
 * Write installer audit entries to app logs.
 */
function _cmsAuditInstaller(string $action, string $entityType, string $entityId, string $status, string $message, array $extra = []): void
{
    $payload = array_merge([
        'action'      => $action,
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'status'      => $status,
        'message'     => $message,
    ], $extra);

    $level = $status === 'success' ? 'info' : 'warning';
    write_log('CMS installer audit: ' . json_encode($payload, JSON_UNESCAPED_SLASHES), $level);
}

/**
 * Find a theme.json file in the extracted directory.
 * Returns the directory containing theme.json, or null.
 */
function _cmsFindThemeRoot(string $dir): ?string
{
    // Check root level
    if (is_file($dir . '/theme.json')) {
        return $dir;
    }
    // Check one level deep (zip had a single folder wrapper)
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $sub = $dir . '/' . $entry;
        if (is_dir($sub) && is_file($sub . '/theme.json')) {
            return $sub;
        }
    }
    return null;
}

/**
 * Find a module.json file in the extracted directory.
 */
function _cmsFindModuleRoot(string $dir): ?string
{
    if (is_file($dir . '/module.json')) {
        return $dir;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $sub = $dir . '/' . $entry;
        if (is_dir($sub) && is_file($sub . '/module.json')) {
            return $sub;
        }
    }
    return null;
}

/**
 * Recursively delete a directory.
 */
function _cmsDeleteDirRecursive(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * Recursively copy a directory.
 */
function _cmsCopyDirRecursive(string $src, string $dst): void
{
    if (!is_dir($dst)) {
        @mkdir($dst, 0775, true);
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $target = $dst . '/' . $items->getSubPathname();
        if ($item->isDir()) {
            @mkdir($target, 0775, true);
        } else {
            @copy($item->getPathname(), $target);
        }
    }
}

/**
 * Sync theme's public-facing assets (style.css, script.js, css/, js/ directories)
 * to public/assets/cms/themes/{slug}/.
 */
function _cmsSyncThemePublicAssets(string $slug, string $themeDir): void
{
    $publicBase = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/') . '/public/assets/cms/themes/' . $slug;

    // Check if theme has assets worth copying
    $hasPublicAssets = is_file($themeDir . '/style.css')
        || is_dir($themeDir . '/css')
        || is_dir($themeDir . '/js')
        || is_file($themeDir . '/script.js');

    if (!$hasPublicAssets) {
        return;
    }

    if (!is_dir($publicBase)) {
        @mkdir($publicBase, 0775, true);
    }

    // Copy CSS files
    foreach (['style.css', 'script.js'] as $rootFile) {
        if (is_file($themeDir . '/' . $rootFile)) {
            @copy($themeDir . '/' . $rootFile, $publicBase . '/' . $rootFile);
        }
    }

    // Copy css/ and js/ directories
    foreach (['css', 'js'] as $subDir) {
        if (is_dir($themeDir . '/' . $subDir)) {
            _cmsCopyDirRecursive($themeDir . '/' . $subDir, $publicBase . '/' . $subDir);
        }
    }
}

/**
 * Discover CMS-installed sub-modules only (from the CMS registry).
 * Kernel/application modules are excluded — they are managed at the OS level.
 */
function _cmsDiscoverSubModules(): array
{
    $registered = _cmsGetRegisteredSubModules();
    if (empty($registered)) {
        return [];
    }

    $all = discoverModules();
    $result = [];
    foreach ($registered as $id) {
        if (!isset($all[$id])) continue; // Module dir was removed externally
        $m = $all[$id];
            // Merge saved settings with defaults from module.json
        $settingsFields   = is_array($m['settings_fields'] ?? null) ? $m['settings_fields'] : [];
        $savedSettings    = $m['_settings'] ?? [];
        $defaultValues    = [];
        foreach ($settingsFields as $f) {
            $key = (string)($f['key'] ?? '');
            if ($key !== '' && isset($f['default'])) {
                $defaultValues[$key] = $f['default'];
            }
        }
        $currentSettings  = array_merge($defaultValues, is_array($savedSettings) ? $savedSettings : []);

        $result[] = [
            'id'              => $id,
            'name'            => $m['name'] ?? ucfirst($id),
            'version'         => $m['version'] ?? '—',
            'author'          => $m['author'] ?? '',
            'description'     => $m['description'] ?? '',
            'enabled'         => !empty($m['_enabled']),
            'is_core'         => false,
            'has_routes'      => is_file(($m['_path'] ?? '') . '/routes.php'),
            'has_handlers'    => is_file(($m['_path'] ?? '') . '/handlers.php'),
            'capabilities_count' => count($m['capabilities']['exposes'] ?? []),
            'depends_count'   => count($m['capabilities']['depends'] ?? []),
            'tables'          => $m['owns_tables'] ?? [],
            'settings_fields' => $settingsFields,
            'settings'        => $currentSettings,
        ];
    }
    usort($result, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $result;
}

/**
 * API: Save configuration settings for a specific CMS-installed module.
 * PATCH /api/v1/cms/modules/{module_id}/settings
 */
function cmsApiModuleSettingsSave(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');
    app()->csrfEnforce();

    $moduleId = trim((string)($params['module_id'] ?? ''));
    if ($moduleId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'module_id required']);
        exit;
    }

    // Only CMS-installed modules may be configured here
    $registered = _cmsGetRegisteredSubModules();
    if (!in_array($moduleId, $registered, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Module not managed by CMS installer.']);
        exit;
    }

    $input    = cmsInput();
    $incoming = $input['settings'] ?? null;
    if (!is_array($incoming)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'settings object required']);
        exit;
    }

    // Restrict keys to those declared in settings_fields (safe allowlist)
    $all      = discoverModules();
    $manifest = $all[$moduleId] ?? [];
    $fields   = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
    $allowed  = array_column($fields, 'key');

    if (empty($allowed)) {
        // Module has no declared settings schema — accept any string keys
        $clean = array_map('strval', $incoming);
    } else {
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $incoming)) {
                $clean[$key] = trim((string)$incoming[$key]);
            }
        }
    }

    saveModuleSettings($moduleId, $clean);

    write_log('CMS module settings saved [' . $moduleId . ']', 'info', ['keys' => array_keys($clean)]);

    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

/**
 * Run pending migrations for a newly installed module.
 */
function _cmsRunModuleMigrations(string $moduleId, array $meta, string $moduleDir): void
{
    $migrations = $meta['migrations'] ?? [];
    if (empty($migrations) || !is_array($migrations)) {
        return;
    }

    try {
        $db = cmsDb();
        foreach ($migrations as $migrationPath) {
            $fullPath = $moduleDir . '/' . ltrim($migrationPath, '/');
            if (!is_file($fullPath)) continue;
            $sql = trim((string)file_get_contents($fullPath));
            if ($sql === '') continue;

            // Split on semicolons for multi-statement files
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if ($stmt !== '') {
                    $db->exec($stmt);
                }
            }
        }
    } catch (\Throwable $e) {
        write_log('Module migration error [' . $moduleId . ']: ' . $e->getMessage(), 'error');
    }
}

// ─── CMS Sub-Module Registry ─────────────────────────────────────────
// Only modules installed through the CMS admin UI are tracked here.
// Kernel/application modules (cms, users, media, ai, search, tinymce,
// workflow, sms, etc.) are never exposed to CMS tenant admins.

/**
 * Path to the CMS sub-module registry file.
 */
function _cmsSubModuleRegistryPath(): string
{
    return rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3)), '/') . '/storage/cms-installed-modules.json';
}

/**
 * Internal settings key used for tenant-scoped CMS sub-module registry.
 */
function _cmsSubModuleRegistryKey(): string
{
    return '_installed_submodules';
}

/**
 * Normalize a list of module IDs for storage/comparison.
 *
 * @param mixed $list
 * @return string[]
 */
function _cmsNormalizeSubModuleList($list): array
{
    if (!is_array($list)) {
        return [];
    }

    $normalized = [];
    foreach ($list as $item) {
        $id = trim((string)$item);
        if ($id === '') {
            continue;
        }
        $normalized[$id] = true;
    }

    return array_keys($normalized);
}

/**
 * Try reading tenant-scoped sub-module registry.
 * Returns null when tenant context is unavailable.
 *
 * @return string[]|null
 */
function _cmsGetTenantRegisteredSubModules(): ?array
{
    if (!function_exists('moduleTenantSettingsModeEnabled') || !moduleTenantSettingsModeEnabled()) {
        return null;
    }
    if (!function_exists('moduleTenantSettingsTenantId') || !function_exists('readTenantModuleSettings')) {
        return null;
    }

    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null) {
        return null;
    }

    $settings = readTenantModuleSettings('cms');
    return _cmsNormalizeSubModuleList($settings[_cmsSubModuleRegistryKey()] ?? []);
}

/**
 * Get the list of module IDs installed via the CMS admin.
 * @return string[]
 */
function _cmsGetRegisteredSubModules(): array
{
    $tenantList = _cmsGetTenantRegisteredSubModules();
    if ($tenantList !== null) {
        return $tenantList;
    }

    $path = _cmsSubModuleRegistryPath();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return _cmsNormalizeSubModuleList($data);
}

/**
 * Check whether a module was installed via the CMS admin.
 */
function _cmsIsRegisteredSubModule(string $moduleId): bool
{
    return in_array($moduleId, _cmsGetRegisteredSubModules(), true);
}

/**
 * Register a newly installed module in the CMS sub-module registry.
 */
function _cmsRegisterSubModule(string $moduleId): void
{
    if (function_exists('moduleTenantSettingsModeEnabled') && moduleTenantSettingsModeEnabled()) {
        if (function_exists('moduleTenantSettingsTenantId') && function_exists('saveTenantModuleSettings')) {
            $tenantId = moduleTenantSettingsTenantId();
            if ($tenantId !== null) {
                $list = _cmsGetRegisteredSubModules();
                if (!in_array($moduleId, $list, true)) {
                    $list[] = $moduleId;
                }
                saveTenantModuleSettings('cms', [_cmsSubModuleRegistryKey() => _cmsNormalizeSubModuleList($list)]);
                return;
            }
        }
        // Tenant mode active but tenant ID unresolved — refuse global file write.
        write_log(
            "_cmsRegisterSubModule: tenant mode active but tenant ID unresolved — refusing global fallback for '{$moduleId}'",
            'warning',
            ['module' => $moduleId]
        );
        return;
    }

    // Single-tenant fallback: write to global file registry.
    $list = _cmsGetRegisteredSubModules();
    if (!in_array($moduleId, $list, true)) {
        $list[] = $moduleId;
    }
    $path = _cmsSubModuleRegistryPath();
    @file_put_contents($path, json_encode(_cmsNormalizeSubModuleList($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Remove a module from the CMS sub-module registry.
 */
function _cmsUnregisterSubModule(string $moduleId): void
{
    if (function_exists('moduleTenantSettingsModeEnabled') && moduleTenantSettingsModeEnabled()) {
        if (function_exists('moduleTenantSettingsTenantId') && function_exists('saveTenantModuleSettings')) {
            $tenantId = moduleTenantSettingsTenantId();
            if ($tenantId !== null) {
                $list = _cmsGetRegisteredSubModules();
                $list = array_values(array_filter($list, fn(string $id) => $id !== $moduleId));
                saveTenantModuleSettings('cms', [_cmsSubModuleRegistryKey() => _cmsNormalizeSubModuleList($list)]);
                return;
            }
        }
        // Tenant mode active but tenant ID unresolved — refuse global file write.
        write_log(
            "_cmsUnregisterSubModule: tenant mode active but tenant ID unresolved — refusing global fallback for '{$moduleId}'",
            'warning',
            ['module' => $moduleId]
        );
        return;
    }

    // Single-tenant fallback: write to global file registry.
    $list = _cmsGetRegisteredSubModules();
    $list = array_values(array_filter($list, fn(string $id) => $id !== $moduleId));
    $path = _cmsSubModuleRegistryPath();
    @file_put_contents($path, json_encode(_cmsNormalizeSubModuleList($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Get the IDs of all kernel/application modules (everything currently
 * in modules/ that is NOT in the CMS sub-module registry).
 * These are off-limits to CMS tenant admins.
 * @return string[]
 */
function _cmsGetKernelModuleIds(): array
{
    $all = array_keys(discoverModules());
    $cmsInstalled = _cmsGetRegisteredSubModules();
    return array_values(array_diff($all, $cmsInstalled));
}

/**
 * Check whether a module ID appears in the global (file-based) CMS registry
 * or is marked as CMS-owned on disk.
 *
 * This is used by the upload handler to distinguish:
 *   - True kernel modules (bundled with the application, never CMS-installed)
 *   - CMS-installed modules that live on disk because another tenant installed them
 *
 * In the latter case, a second tenant should be allowed to adopt the module
 * into their own CMS registry without needing to copy the files again.
 */
function _cmsIsInGlobalOrAnyTenantRegistry(string $moduleId): bool
{
    // Check global file-based registry first (single-tenant or fallback)
    $globalList = _cmsNormalizeSubModuleList(
        is_file(_cmsSubModuleRegistryPath())
            ? (json_decode((string)file_get_contents(_cmsSubModuleRegistryPath()), true) ?? [])
            : []
    );
    if (in_array($moduleId, $globalList, true)) {
        return true;
    }

    // Check for the CMS-ownership marker file written by the installer on first install.
    // This avoids needing to query all tenant databases.
    $modulesDir = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/') . '/modules';
    if (is_file($modulesDir . '/' . $moduleId . '/.cms-owned')) {
        return true;
    }

    return false;
}
