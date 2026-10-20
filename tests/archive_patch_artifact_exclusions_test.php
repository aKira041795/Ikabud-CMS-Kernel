<?php
/**
 * Production archives must never ship patch or backup artifacts.
 *
 * Why this guard exists: kernel/Database/KernelPDO.php.orig and .rej were
 * left behind by an old patch. PHP never loaded them, but they were not
 * excluded by the Bluehost archive builder, so code history could ride in a
 * deploy package. The files are deleted and both archive builders now exclude
 * *.orig, *.rej and *.bak.
 *
 * The pattern assertion is intentionally a source check: these builders are
 * standalone scripts, and executing them would build a production zip. The
 * file-absence assertions are the outcome.
 *
 * Run: php tests/archive_patch_artifact_exclusions_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  PASS {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$scripts = [
    'create-bluehost-archive.php',
    'create-barebones-archive.php',
];

foreach ($scripts as $script) {
    $path = $root . '/' . $script;
    $source = is_file($path) ? (string)file_get_contents($path) : '';
    t("{$script} exists", $source !== '', $path . ' not found');
    t(
        "{$script} excludes .orig/.rej/.bak artifacts",
        preg_match('/orig\|rej\|bak/', $source) === 1,
        'no patch-artifact pattern in the exclusion list'
    );
    t(
        "{$script} still defines a filename exclusion list",
        str_contains($source, '$excludeFilePatterns'),
        'the archive builder no longer has a filename policy'
    );
}

t('kernel/Database/KernelPDO.php.orig is absent', !file_exists($root . '/kernel/Database/KernelPDO.php.orig'));
t('kernel/Database/KernelPDO.php.rej is absent', !file_exists($root . '/kernel/Database/KernelPDO.php.rej'));

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED') . ": {$pass} passed, {$fail} failed\n";
if ($fail !== 0) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}
exit(0);
