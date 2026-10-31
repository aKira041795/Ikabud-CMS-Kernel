<?php
/**
 * End-to-end proof of the extension auto-update loop.
 *
 * The unit-level test (module_extension_update_feed_test.php) proves every
 * REFUSAL. This one proves the loop actually closes: a real HTTP feed serves a
 * real ZIP, the digest is checked, the package is applied through
 * updateModuleFromZip(), and the installed manifest really changes version.
 *
 * A local fixture server is used, which the feed validator normally refuses
 * (loopback hosts are blocked on purpose). APP_EXTENSION_FEEDS_ALLOW_HTTP is the
 * single, deliberate dev escape hatch that relaxes scheme AND host together; this
 * test sets it for its own process only.
 *
 * Run: php tests/module_extension_update_end_to_end_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
require_once BASE_PATH . '/src/helpers/updates.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function meeAssert(string $label, bool $condition, string $detail = ''): void
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

function meeRrmdir(string $dir): void
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

function meeManifest(string $path): array
{
    $raw = @file_get_contents($path);
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function meeWriteManifest(string $path, array $manifest): void
{
    @file_put_contents(
        $path,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

$token = bin2hex(random_bytes(4));
$moduleId = 'modulee2e' . $token;
$moduleDir = BASE_PATH . '/modules/' . $moduleId;
$templateDir = BASE_PATH . '/templates/modules/' . $moduleId;
$manifestPath = $moduleDir . '/module.json';
$cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/ikabud');
$serveDir = sys_get_temp_dir() . '/meefeed_' . $token;
$zipV1 = sys_get_temp_dir() . '/mee_' . $token . '_v1.zip';
$zipV2 = sys_get_temp_dir() . '/mee_' . $token . '_v2.zip';
$server = null;

$teardown = static function () use ($moduleId, $moduleDir, $templateDir, $serveDir, $zipV1, $zipV2, &$server): void {
    if (is_resource($server)) {
        @proc_terminate($server);
        @proc_close($server);
        $server = null;
    }
    if (function_exists('uninstallModule')) {
        @uninstallModule($moduleId, ['force' => true]);
    }
    meeRrmdir($moduleDir);
    foreach (glob($moduleDir . '.bak_*') ?: [] as $backup) {
        meeRrmdir($backup);
    }
    meeRrmdir($templateDir);
    foreach (glob($templateDir . '.bak_*') ?: [] as $backup) {
        meeRrmdir($backup);
    }
    @unlink(BASE_PATH . '/tests/' . str_replace('-', '_', $moduleId) . '_module_test.php');
    meeRrmdir($serveDir);
    @unlink($zipV1);
    @unlink($zipV2);
};
$teardown();

register_shutdown_function($teardown);

// ── Build a real module at two versions ────────────────────────────────────
$made = (int) shell_exec($cli . ' make:module ' . escapeshellarg($moduleId) . ' >/dev/null 2>&1; echo $?');
meeAssert('scaffold succeeds', $made === 0 && is_file($manifestPath));
if (!is_file($manifestPath)) {
    echo "\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

meeWriteManifest($manifestPath, array_merge(meeManifest($manifestPath), ['version' => '1.0.0']));
$p1 = (int) shell_exec($cli . ' module:pack ' . escapeshellarg($moduleId) . ' ' . escapeshellarg($zipV1) . ' >/dev/null 2>&1; echo $?');
meeAssert('pack v1.0.0', $p1 === 0 && is_file($zipV1));

meeWriteManifest($manifestPath, array_merge(meeManifest($manifestPath), ['version' => '1.1.0']));
$p2 = (int) shell_exec($cli . ' module:pack ' . escapeshellarg($moduleId) . ' ' . escapeshellarg($zipV2) . ' >/dev/null 2>&1; echo $?');
meeAssert('pack v1.1.0', $p2 === 0 && is_file($zipV2));

$un = (int) shell_exec($cli . ' module:uninstall ' . escapeshellarg($moduleId) . ' >/dev/null 2>&1; echo $?');
meeAssert('scaffold removed', $un === 0 && !is_dir($moduleDir));
if (function_exists('kernelFlushCodeCaches')) {
    kernelFlushCodeCaches();
}

$installed = installModuleFromZip($zipV1);
meeAssert('baseline v1.0.0 installed', !empty($installed['ok']), json_encode($installed));
meeAssert('installed version is 1.0.0', (meeManifest($manifestPath)['version'] ?? '') === '1.0.0');

// ── Fixture server: a real feed and a real package over HTTP ──────────────
@mkdir($serveDir, 0775, true);
$servedZip = $serveDir . '/ext-1.1.0.zip';
copy($zipV2, $servedZip);
$digest = hash_file('sha256', $servedZip);

$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($socket === false) {
    meeAssert('fixture server port available', false, $errstr);
    echo "\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}
$name = stream_socket_get_name($socket, false);
$port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
fclose($socket);

$baseUrl = 'http://127.0.0.1:' . $port;
$feed = [
    'id' => $moduleId,
    'versions' => [
        [
            'version' => '1.1.0',
            'package_url' => $baseUrl . '/ext-1.1.0.zip',
            'sha256' => $digest,
            'channel' => 'stable',
            'notes' => 'End-to-end fixture release.',
        ],
    ],
];
file_put_contents($serveDir . '/update.json', json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$server = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($serveDir),
    $descriptors,
    $pipes,
    $serveDir
);
meeAssert('fixture server started', is_resource($server));

// Wait for the server to answer, but never sleep-poll blindly: try the feed.
$ready = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $probe = @file_get_contents($baseUrl . '/update.json', false, stream_context_create([
        'http' => ['timeout' => 1, 'ignore_errors' => true],
    ]));
    if (is_string($probe) && str_contains($probe, $moduleId)) {
        $ready = true;
        break;
    }
    usleep(100000);
}
meeAssert('fixture feed is reachable', $ready);

// ── Declare the feed on the installed module ──────────────────────────────
$manifest = meeManifest($manifestPath);
$manifest['update_url'] = $baseUrl . '/update.json';
meeWriteManifest($manifestPath, $manifest);
if (function_exists('kernelFlushCodeCaches')) {
    kernelFlushCodeCaches();
}

// Loopback is refused by default; this process opts in for the fixture only.
$_ENV['APP_EXTENSION_FEEDS_ALLOW_HTTP'] = '1';

// ── Digest must match, or nothing is downloaded into the updater ──────────
$lie = [
    'package_url' => $baseUrl . '/ext-1.1.0.zip',
    'sha256' => str_repeat('b', 64),
];
$badDownload = moduleExtensionDownloadPackage($lie);
meeAssert(
    'a package whose bytes do not match the advertised digest is refused',
    empty($badDownload['ok']) && (string) ($badDownload['error_code'] ?? '') === 'package_digest_mismatch',
    (string) ($badDownload['error_code'] ?? '(none)')
);

// ── The feed is read, the update is seen ──────────────────────────────────
$check = moduleExtensionUpdateCheck($moduleId, ['force' => true]);
meeAssert('feed check succeeds', !empty($check['ok']), json_encode($check));
meeAssert('an update is advertised', !empty($check['update_available']), json_encode($check['latest_version'] ?? null));
meeAssert('advertised version is 1.1.0', (string) ($check['latest_version'] ?? '') === '1.1.0', (string) ($check['latest_version'] ?? ''));
meeAssert('feed errors are clear', (string) ($check['error'] ?? '') === '', (string) ($check['error'] ?? ''));

$available = moduleExtensionUpdatesAvailable();
meeAssert('the module is listed as having an update', isset($available[$moduleId]));

// ── Applying it really updates the module ─────────────────────────────────
$applied = moduleExtensionApplyUpdate($moduleId, ['require_certification' => false]);
meeAssert('update applies', !empty($applied['ok']), json_encode($applied));
meeAssert('applied from 1.0.0', (string) ($applied['from_version'] ?? '') === '1.0.0', (string) ($applied['from_version'] ?? ''));
meeAssert('applied to 1.1.0', (string) ($applied['to_version'] ?? '') === '1.1.0', (string) ($applied['to_version'] ?? ''));
meeAssert('installed manifest is now 1.1.0', (meeManifest($manifestPath)['version'] ?? '') === '1.1.0', (string) (meeManifest($manifestPath)['version'] ?? ''));

$afterState = moduleExtensionUpdateState($moduleId);
meeAssert('cached state no longer advertises the update', empty($afterState['update_available']));
meeAssert('cached state records what was applied', (string) ($afterState['last_applied']['to'] ?? '') === '1.1.0', json_encode($afterState['last_applied'] ?? null));
meeAssert('nothing is left in the available list', !isset(moduleExtensionUpdatesAvailable()[$moduleId]));
meeAssert('the update kept the module enabled', isModuleEnabled($moduleId));

// ── A feed pointing at a different module is refused ──────────────────────
$impostor = $feed;
$impostor['id'] = 'someone-else';
file_put_contents($serveDir . '/update.json', json_encode($impostor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
// The update replaced the tree, so the feed declaration a creator ships in the
// PACKAGE is what survives — re-declare it here, as a real 1.1.0 package would.
$manifest = meeManifest($manifestPath);
meeWriteManifest($manifestPath, array_merge($manifest, [
    'version' => '1.0.5',
    'update_url' => $baseUrl . '/update.json',
]));
if (function_exists('kernelFlushCodeCaches')) {
    kernelFlushCodeCaches();
}
$mismatch = moduleExtensionUpdateCheck($moduleId, ['force' => true]);
meeAssert(
    'a feed published for another module id is refused',
    empty($mismatch['ok']) && (string) ($mismatch['error_code'] ?? '') === 'feed_identity_mismatch',
    (string) ($mismatch['error_code'] ?? '(none)')
);
meeAssert('a refused feed advertises no update', empty(moduleExtensionUpdateState($moduleId)['update_available']));

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
