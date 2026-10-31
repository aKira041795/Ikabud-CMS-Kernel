<?php
/**
 * Install provenance must actually be written, resolved, preserved and cleared.
 *
 * The ownership contract has a fallback that only fires when a module declares
 * no `extends`: the kernel-written record of who installed it. That fallback is
 * worthless if nothing writes the record - a ZIP-installed extension would stay
 * a root and the kernel admin would grow one row per extension, which is the
 * whole reason the ownership graph exists.
 *
 * This test exercises the real filesystem loop end to end:
 *   scaffold -> pack -> install with installed_by -> resolver attaches it to the
 *   installer -> update keeps the record -> uninstall removes it.
 *
 * Run: php tests/module_install_provenance_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function mipAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    $failed++;
}

function mipRrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

function mipSetVersion(string $manifestPath, string $version): void
{
    $raw = @file_get_contents($manifestPath);
    $manifest = json_decode((string) $raw, true);
    if (!is_array($manifest)) {
        return;
    }
    $manifest['version'] = $version;
    @file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

foreach (['moduleOwnershipRecordInstall', 'moduleOwnershipForget', 'moduleOwnershipRoot', 'moduleOwnershipChildren'] as $fn) {
    mipAssert("{$fn}() exists", function_exists($fn));
}
if (!function_exists('moduleOwnershipRoot')) {
    echo "\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

$token = bin2hex(random_bytes(4));
$moduleId = 'modprov' . $token;
$moduleDir = BASE_PATH . '/modules/' . $moduleId;
$manifestPath = $moduleDir . '/module.json';
$templateDir = BASE_PATH . '/templates/modules/' . $moduleId;
$cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/ikabud');
$zipV1 = sys_get_temp_dir() . '/mip_' . $token . '_v1.zip';
$zipV2 = sys_get_temp_dir() . '/mip_' . $token . '_v2.zip';

$cleanup = static function () use ($moduleId, $moduleDir, $templateDir, $zipV1, $zipV2): void {
    if (function_exists('uninstallModule')) {
        @uninstallModule($moduleId, ['force' => true]);
    }
    mipRrmdir($moduleDir);
    foreach (glob($moduleDir . '.bak_*') ?: [] as $backup) {
        mipRrmdir($backup);
    }
    mipRrmdir($templateDir);
    @unlink(BASE_PATH . '/tests/' . str_replace('-', '_', $moduleId) . '_module_test.php');
    @unlink($zipV1);
    @unlink($zipV2);
};
$cleanup();
register_shutdown_function($cleanup);

// The installer this module will belong to must be a real, installed root.
$cmsRoot = moduleOwnershipRoot('cms');
mipAssert('cms resolves as a lifecycle root', $cmsRoot === 'cms', (string) $cmsRoot);

// ── Build the package ─────────────────────────────────────────────────────
$made = (int) shell_exec($cli . ' make:module ' . escapeshellarg($moduleId) . ' >/dev/null 2>&1; echo $?');
mipAssert('scaffold succeeds', $made === 0 && is_file($manifestPath));
if (!is_file($manifestPath)) {
    echo "\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

mipSetVersion($manifestPath, '1.0.0');
$p1 = (int) shell_exec($cli . ' module:pack ' . escapeshellarg($moduleId) . ' ' . escapeshellarg($zipV1) . ' >/dev/null 2>&1; echo $?');
mipAssert('pack v1.0.0', $p1 === 0 && is_file($zipV1));

mipSetVersion($manifestPath, '1.1.0');
$p2 = (int) shell_exec($cli . ' module:pack ' . escapeshellarg($moduleId) . ' ' . escapeshellarg($zipV2) . ' >/dev/null 2>&1; echo $?');
mipAssert('pack v1.1.0', $p2 === 0 && is_file($zipV2));

$un = (int) shell_exec($cli . ' module:uninstall ' . escapeshellarg($moduleId) . ' >/dev/null 2>&1; echo $?');
mipAssert('scaffold removed', $un === 0 && !is_dir($moduleDir));
if (function_exists('kernelFlushCodeCaches')) {
    kernelFlushCodeCaches();
}

// ── The module has no `extends`: without provenance it MUST be a root ─────
$beforeRoot = moduleOwnershipRoot($moduleId);
mipAssert('an uninstalled module has no root', $beforeRoot === null, (string) $beforeRoot);

$installed = installModuleFromZip($zipV1, ['installed_by' => 'cms']);
mipAssert('install with installed_by succeeds', !empty($installed['ok']), json_encode($installed));
if (function_exists('kernelFlushCodeCaches')) {
    kernelFlushCodeCaches();
}

$manifest = json_decode((string) @file_get_contents($manifestPath), true);
$declaresExtends = is_array($manifest) && (isset($manifest['extends']) || isset($manifest['parent']));
mipAssert('the package declares no extends (this is the ZIP case)', !$declaresExtends);

// ── Provenance is what makes the installer its root ──────────────────────
mipAssert('the module resolves to the installer as its root', moduleOwnershipRoot($moduleId) === 'cms', (string) moduleOwnershipRoot($moduleId));

$cmsChildren = array_column(moduleOwnershipChildren('cms'), 'id');
mipAssert('the installer lists the module as a descendant', in_array($moduleId, $cmsChildren, true));

$resolved = moduleOwnershipResolveAll();
mipAssert('it is NOT projected as a root', !isset($resolved['roots'][$moduleId]));
mipAssert('it is not unresolved', count(array_filter($resolved['unresolved'], static fn(array $u): bool => (string) $u['id'] === $moduleId)) === 0);
// moduleOwnershipProvenance($id) returns a single-key map keyed by module id
// (documented as array<string,array>), not a bare entry.
$provenanceEntry = moduleOwnershipProvenance($moduleId)[$moduleId] ?? [];
mipAssert('the recorded fact is attributable', ($provenanceEntry['installed_by'] ?? '') === 'cms', json_encode($provenanceEntry));

// ── An update must not orphan it ─────────────────────────────────────────
$updated = updateModuleFromZip($zipV2, ['require_certification' => false, 'installed_by' => 'cms']);
mipAssert('update succeeds', !empty($updated['ok']), json_encode($updated));
if (function_exists('kernelFlushCodeCaches')) {
    kernelFlushCodeCaches();
}
mipAssert('ownership survives an update', moduleOwnershipRoot($moduleId) === 'cms', (string) moduleOwnershipRoot($moduleId));

// ── Uninstall must leave no phantom ──────────────────────────────────────
$removed = uninstallModule($moduleId, ['force' => true]);
mipAssert('uninstall succeeds', !empty($removed['ok']), json_encode($removed));
mipAssert('no provenance entry survives uninstall', moduleOwnershipProvenance($moduleId) === []);
mipAssert('an uninstalled module has no root again', moduleOwnershipRoot($moduleId) === null, (string) moduleOwnershipRoot($moduleId));

// ── The real fleet is unaffected by an install/update/uninstall cycle ────
$real = moduleOwnershipResolveAll();
mipAssert('the real fleet still has no unresolved ownership', $real['unresolved'] === [], json_encode($real['unresolved']));

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
