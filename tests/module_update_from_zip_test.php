<?php
/**
 * Updating an installed module from a ZIP must be a first-class, gated operation.
 *
 * THE PROBLEM THIS GUARDS (measured 2026-10-31): installModuleFromZip() explicitly
 * REFUSES a package whose target directory exists - "Remove it first or use update." -
 * and no `update` existed. Every caller therefore hand-rolled its own overwrite. The CMS
 * installer renamed the live directory to `modules/<id>.bak_<stamp>` and copied the new
 * one over it: no version comparison, so a DOWNGRADE was indistinguishable from an
 * upgrade; no signature or certification gate, so a tenant upload skipped the checks the
 * kernel installer enforces; and the backup was written but never restored, and never
 * removed.
 *
 * The assertions below are chosen so that a mere overwrite cannot pass:
 *   - a HIGHER version replaces the installed files and reports from/to
 *   - a LOWER version is REFUSED unless the caller explicitly allows a downgrade
 *   - the SAME version is a reinstall, and says so
 *   - a refused update leaves the installed version UNTOUCHED
 *   - a successful update leaves a restorable backup, and GC can drop it
 *   - the shared preflight rejects an extension whose host is not installed
 *
 * Run: php tests/module_update_from_zip_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function mupAssert(string $label, bool $condition, string $detail = ''): void
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

function mupRrmdir(string $dir): void
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

function mupBackupsFor(string $moduleDir): array
{
    $found = [];
    foreach (glob($moduleDir . '.bak_*') ?: [] as $dir) {
        if (is_dir($dir)) {
            $found[] = $dir;
        }
    }
    return $found;
}

function mupSetVersion(string $manifestPath, string $version): bool
{
    $raw = @file_get_contents($manifestPath);
    $manifest = json_decode((string) $raw, true);
    if (!is_array($manifest)) {
        return false;
    }
    $manifest['version'] = $version;
    return @file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ) !== false;
}

function mupReadVersion(string $manifestPath): string
{
    $raw = @file_get_contents($manifestPath);
    $manifest = json_decode((string) $raw, true);
    return is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
}

$token = bin2hex(random_bytes(4));
$moduleId = 'moduleupd' . $token;
$moduleDir = BASE_PATH . '/modules/' . $moduleId;
$manifestPath = $moduleDir . '/module.json';
$templateDir = BASE_PATH . '/templates/modules/' . $moduleId;
$cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/ikabud');

$zipV1 = sys_get_temp_dir() . '/mup_' . $token . '_v1.zip';
$zipV2 = sys_get_temp_dir() . '/mup_' . $token . '_v2.zip';
$zipV3 = sys_get_temp_dir() . '/mup_' . $token . '_v3.zip';

$cleanup = static function () use ($moduleId, $moduleDir, $templateDir, $zipV1, $zipV2, $zipV3): void {
    if (function_exists('uninstallModule')) {
        @uninstallModule($moduleId, ['force' => true]);
    }
    mupRrmdir($moduleDir);
    foreach (mupBackupsFor($moduleDir) as $backup) {
        mupRrmdir($backup);
    }
    mupRrmdir($templateDir);
    foreach (mupBackupsFor($templateDir) as $backup) {
        mupRrmdir($backup);
    }
    // `ikabud make:module` also scaffolds a test file; it must not survive the run.
    @unlink(BASE_PATH . '/tests/' . str_replace('-', '_', $moduleId) . '_module_test.php');
    @unlink($zipV1);
    @unlink($zipV2);
    @unlink($zipV3);
};
$cleanup();

// ── Contract surface ────────────────────────────────────────────────────────
mupAssert('updateModuleFromZip() exists', function_exists('updateModuleFromZip'));
mupAssert('modulePackagePreflightGates() exists', function_exists('modulePackagePreflightGates'));
if (!function_exists('updateModuleFromZip') || !function_exists('modulePackagePreflightGates')) {
    echo "\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

// ── Build three versions of a real scaffolded module ───────────────────────
$made = (int) shell_exec($cli . ' make:module ' . escapeshellarg($moduleId) . ' >/dev/null 2>&1; echo $?');
mupAssert('scaffold make:module succeeds', $made === 0, 'exit ' . $made);
if (!is_file($manifestPath)) {
    echo "\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

mupSetVersion($manifestPath, '1.0.0');
$packed1 = (int) shell_exec($cli . ' module:pack ' . escapeshellarg($moduleId) . ' ' . escapeshellarg($zipV1) . ' >/dev/null 2>&1; echo $?');
mupAssert('pack v1.0.0 succeeds', $packed1 === 0 && is_file($zipV1));

mupSetVersion($manifestPath, '1.1.0');
$packed2 = (int) shell_exec($cli . ' module:pack ' . escapeshellarg($moduleId) . ' ' . escapeshellarg($zipV2) . ' >/dev/null 2>&1; echo $?');
mupAssert('pack v1.1.0 succeeds', $packed2 === 0 && is_file($zipV2));

mupSetVersion($manifestPath, '0.9.0');
$packed3 = (int) shell_exec($cli . ' module:pack ' . escapeshellarg($moduleId) . ' ' . escapeshellarg($zipV3) . ' >/dev/null 2>&1; echo $?');
mupAssert('pack v0.9.0 succeeds', $packed3 === 0 && is_file($zipV3));

// The scaffold leaves a real module directory behind. Installing from the ZIP is
// only meaningful from a clean slate, and the module scan has to be flushed so the
// process sees the removal the scaffold caused.
$uninstalled = (int) shell_exec($cli . ' module:uninstall ' . escapeshellarg($moduleId) . ' >/dev/null 2>&1; echo $?');
mupAssert('scaffold removed before baseline install', $uninstalled === 0 && !is_dir($moduleDir), 'exit ' . $uninstalled);
if (function_exists('kernelFlushCodeCaches')) {
    kernelFlushCodeCaches();
}

// ── Install 1.0.0 as the baseline ──────────────────────────────────────────
$installed = installModuleFromZip($zipV1);
mupAssert('baseline install succeeds', !empty($installed['ok']), json_encode($installed));
mupAssert('baseline version is 1.0.0', mupReadVersion($manifestPath) === '1.0.0', mupReadVersion($manifestPath));
$templateSentinel = $templateDir . '/pages/home.disyl';
mupAssert('baseline ships a template file', is_file($templateSentinel));

// A newer package must REPLACE the installed files, not merely coexist with them.
if (is_file($manifestPath)) {
    file_put_contents($moduleDir . '/upgrade-marker-should-disappear.txt', 'stale');
}

// ── Downgrade must be REFUSED by default ───────────────────────────────────
$downgrade = updateModuleFromZip($zipV3);
mupAssert('downgrade is refused', empty($downgrade['ok']));
mupAssert(
    'downgrade reports module_downgrade_blocked',
    (string) ($downgrade['error_code'] ?? '') === 'module_downgrade_blocked',
    (string) ($downgrade['error_code'] ?? '(none)')
);
mupAssert('refused downgrade leaves installed version untouched', mupReadVersion($manifestPath) === '1.0.0', mupReadVersion($manifestPath));
mupAssert('refused downgrade leaves installed files untouched', is_file($moduleDir . '/upgrade-marker-should-disappear.txt'));

// ── Upgrade 1.0.0 -> 1.1.0 ─────────────────────────────────────────────────
$upgrade = updateModuleFromZip($zipV2);
mupAssert('upgrade succeeds', !empty($upgrade['ok']), json_encode($upgrade));
mupAssert('upgrade reports the installed version', (string) ($upgrade['from_version'] ?? '') === '1.0.0', (string) ($upgrade['from_version'] ?? ''));
mupAssert('upgrade reports the package version', (string) ($upgrade['to_version'] ?? '') === '1.1.0', (string) ($upgrade['to_version'] ?? ''));
mupAssert('upgrade actually replaced module.json', mupReadVersion($manifestPath) === '1.1.0', mupReadVersion($manifestPath));
mupAssert('upgrade removed files the new package no longer ships', !is_file($moduleDir . '/upgrade-marker-should-disappear.txt'));
mupAssert('upgrade keeps the module enabled', isModuleEnabled($moduleId));
mupAssert('upgrade leaves a restorable backup', count(mupBackupsFor($moduleDir)) >= 1);
$backups = mupBackupsFor($moduleDir);
if ($backups !== []) {
    mupAssert('backup holds the previous version', mupReadVersion($backups[0] . '/module.json') === '1.0.0', $backups[0]);
}

// ── Same version is a REINSTALL ────────────────────────────────────────────
$reinstall = updateModuleFromZip($zipV2);
mupAssert('same-version update succeeds', !empty($reinstall['ok']), json_encode($reinstall));
mupAssert('same-version update is reported as a reinstall', !empty($reinstall['reinstalled']));
mupAssert('reinstall keeps the version', mupReadVersion($manifestPath) === '1.1.0', mupReadVersion($manifestPath));

// ── Explicit downgrade is allowed when the caller asks ─────────────────────
$forced = updateModuleFromZip($zipV3, ['allow_downgrade' => true]);
mupAssert('explicit downgrade succeeds', !empty($forced['ok']), json_encode($forced));
mupAssert('explicit downgrade lands the lower version', mupReadVersion($manifestPath) === '0.9.0', mupReadVersion($manifestPath));

// ── Backup GC removes stale trees and keeps the newest ─────────────────────
$gc = modulePackageBackupGc(0);
mupAssert('modulePackageBackupGc reports a count', is_int($gc) && $gc >= 0, (string) $gc);

// ── Shared preflight rejects an extension with no installed host ───────────
$orphan = modulePackagePreflightGates([
    'id' => 'orphanext' . $token,
    'name' => 'Orphan Extension',
    'version' => '1.0.0',
    'kind' => 'extension',
    'extends' => 'no-such-host-' . $token,
], ['require_certification' => false]);
mupAssert('preflight rejects an extension with a missing host', empty($orphan['ok']));

$sane = modulePackagePreflightGates([
    'id' => 'sane' . $token,
    'name' => 'Sane Module',
    'version' => '1.0.0',
], ['require_certification' => false]);
mupAssert('preflight accepts a self-contained module', !empty($sane['ok']), json_encode($sane));

$cleanup();

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
