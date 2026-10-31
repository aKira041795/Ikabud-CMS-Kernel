<?php
/**
 * The extension update FEED contract must fail closed.
 *
 * A creator-published feed is the only route by which third-party code reaches a
 * tenant without an operator picking a file. The rules that make that acceptable
 * are all refusals, and every one of them is asserted here:
 *
 *   - the feed and the package must be https, never loopback / private network,
 *     never carrying credentials;
 *   - a version without a sha256 digest is NOT an eligible update;
 *   - a version whose `requires_kernel` this kernel cannot satisfy is not offered;
 *   - a channel mismatch is not offered;
 *   - a module that declares no feed cannot be checked, and cannot be opted into
 *     auto-update;
 *   - auto-update is OFF until someone turns it on.
 *
 * Run: php tests/module_extension_update_feed_test.php
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
function mfxAssert(string $label, bool $condition, string $detail = ''): void
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

$digest = str_repeat('a', 64);

// ── Feed URL acceptance ────────────────────────────────────────────────────
mfxAssert(
    'https feed is accepted',
    moduleExtensionUpdateFeedUrl(['update_url' => 'https://example.com/ext/update.json'])
        === 'https://example.com/ext/update.json'
);
mfxAssert('http feed is refused without the dev override', moduleExtensionUpdateFeedUrl(['update_url' => 'http://example.com/u.json']) === '');
mfxAssert('missing feed is refused', moduleExtensionUpdateFeedUrl([]) === '');
mfxAssert('non-string feed is refused', moduleExtensionUpdateFeedUrl(['update_url' => ['x']]) === '');
mfxAssert('loopback host is refused', moduleExtensionUpdateFeedUrl(['update_url' => 'https://127.0.0.1/u.json']) === '');
mfxAssert('localhost is refused', moduleExtensionUpdateFeedUrl(['update_url' => 'https://localhost/u.json']) === '');
mfxAssert('private /8 host is refused', moduleExtensionUpdateFeedUrl(['update_url' => 'https://10.1.2.3/u.json']) === '');
mfxAssert('private /16 host is refused', moduleExtensionUpdateFeedUrl(['update_url' => 'https://192.168.1.5/u.json']) === '');
mfxAssert('link-local host is refused', moduleExtensionUpdateFeedUrl(['update_url' => 'https://169.254.1.1/u.json']) === '');
mfxAssert('private /12 host is refused', moduleExtensionUpdateFeedUrl(['update_url' => 'https://172.20.0.9/u.json']) === '');

// ── Package URL acceptance ─────────────────────────────────────────────────
mfxAssert('https package url is accepted', moduleExtensionUpdatePackageUrl('https://example.com/ext-1.2.0.zip') === 'https://example.com/ext-1.2.0.zip');
mfxAssert('http package url is refused', moduleExtensionUpdatePackageUrl('http://example.com/ext.zip') === '');
mfxAssert('credentialed package url is refused', moduleExtensionUpdatePackageUrl('https://user:pass@example.com/ext.zip') === '');
mfxAssert('newline-injected package url is refused', moduleExtensionUpdatePackageUrl("https://example.com/e.zip\r\nHost: evil") === '');

// ── Version selection ──────────────────────────────────────────────────────
$versions = [
    ['version' => '1.1.0', 'package_url' => 'https://example.com/a.zip', 'sha256' => $digest],
    ['version' => '1.3.0', 'package_url' => 'https://example.com/b.zip', 'sha256' => $digest],
    // Higher, but no digest: an update nobody can verify is not an update.
    ['version' => '2.0.0', 'package_url' => 'https://example.com/c.zip'],
    // Higher, but it needs a kernel this one is not.
    ['version' => '3.0.0', 'package_url' => 'https://example.com/d.zip', 'sha256' => $digest, 'requires_kernel' => '>=99.0'],
    // Higher, but on another channel.
    ['version' => '4.0.0', 'package_url' => 'https://example.com/e.zip', 'sha256' => $digest, 'channel' => 'beta'],
];
$selected = moduleExtensionSelectVersion($versions, 'stable');
mfxAssert('newest eligible version is selected', is_array($selected) && (string) $selected['version'] === '1.3.0', json_encode($selected['version'] ?? null));
mfxAssert('a version without a digest is never selected', is_array($selected) && (string) $selected['version'] !== '2.0.0');
mfxAssert('a version needing a newer kernel is never selected', is_array($selected) && (string) $selected['version'] !== '3.0.0');
mfxAssert('an off-channel version is never selected', is_array($selected) && (string) $selected['version'] !== '4.0.0');

mfxAssert('a malformed digest is rejected', moduleExtensionSelectVersion([['version' => '9.0.0', 'sha256' => 'nope']], 'stable') === null);
mfxAssert('an empty version list selects nothing', moduleExtensionSelectVersion([], 'stable') === null);
mfxAssert(
    'a satisfiable requires_kernel is accepted',
    (string) (moduleExtensionSelectVersion([
        ['version' => '1.5.0', 'sha256' => $digest, 'requires_kernel' => '>=6.0'],
    ], 'stable')['version'] ?? '') === '1.5.0'
);

// ── Module-level behaviour ─────────────────────────────────────────────────
$noFeed = moduleExtensionUpdateCheck('cms');
mfxAssert('a module without a feed reports no_update_feed', empty($noFeed['ok']) && (string) ($noFeed['error_code'] ?? '') === 'no_update_feed');

$unknown = moduleExtensionUpdateCheck('no-such-module-xyz');
mfxAssert('an uninstalled module reports module_not_found', empty($unknown['ok']) && (string) ($unknown['error_code'] ?? '') === 'module_not_found');

$state = moduleExtensionUpdateState('cms');
mfxAssert('unchecked state defaults to no update', $state['update_available'] === false && $state['latest_version'] === '');
mfxAssert('auto-update defaults to off', $state['auto_update'] === false);
mfxAssert('auto-update is off unless recorded', moduleExtensionAutoUpdateEnabled('cms') === false);
mfxAssert('opting into auto-update is refused for a module with no feed', moduleExtensionSetAutoUpdate('cms', true) === false);
mfxAssert('the refused opt-in did not stick', moduleExtensionAutoUpdateEnabled('cms') === false);

// ── Download refuses before it can be tricked ──────────────────────────────
$noDigest = moduleExtensionDownloadPackage(['package_url' => 'https://example.com/a.zip', 'sha256' => '']);
mfxAssert('download refuses a missing digest', empty($noDigest['ok']) && (string) ($noDigest['error_code'] ?? '') === 'package_digest_missing');

$noUrl = moduleExtensionDownloadPackage(['package_url' => '', 'sha256' => $digest]);
mfxAssert('download refuses an unusable url', empty($noUrl['ok']) && (string) ($noUrl['error_code'] ?? '') === 'package_url_unusable');

$applyNoUpdate = moduleExtensionApplyUpdate('cms');
mfxAssert('apply refuses when no update is cached', empty($applyNoUpdate['ok']) && (string) ($applyNoUpdate['error_code'] ?? '') === 'no_update_available');

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
