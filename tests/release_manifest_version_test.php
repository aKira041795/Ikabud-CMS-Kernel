<?php

/**
 * Release manifest version identity test.
 *
 * Asserts that release-manifest.json derives its kernel and DiSyL versions
 * from the code declarations, and that the generator carries no hardcoded
 * version literal. This locks the "one declaration per component" rule.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;
$fail = static function (string $msg): void {
    echo "FAIL: {$msg}\n";
    exit(1);
};

// 1. DiSyL single declaration exists and is a three-part semver.
$versionFile = $root . '/kernel/DiSyL/Version.php';
if (!is_file($versionFile)) {
    $fail('kernel/DiSyL/Version.php is missing');
}
$src = (string) file_get_contents($versionFile);
if (!preg_match("/DISYL_VERSION\s*=\s*'([^']+)'/", $src, $m)) {
    $fail('DiSyL declaration not found in kernel/DiSyL/Version.php');
}
$disylDeclared = $m[1];
$assertions++;
if (!preg_match('/^\d+\.\d+\.\d+$/', $disylDeclared)) {
    $fail("DiSyL declaration '{$disylDeclared}' is not a three-part semver");
}
$assertions++;

// 2. Kernel declaration exists.
$appSrc = (string) file_get_contents($root . '/kernel/App.php');
if (!preg_match("/KERNEL_VERSION\s*=\s*'([^']+)'/", $appSrc, $km)) {
    $fail('KERNEL_VERSION not found in kernel/App.php');
}
$kernelDeclared = $km[1];
$assertions++;

// 3. Manifest agrees with the code declarations.
$manifestPath = $root . '/release-manifest.json';
if (!is_file($manifestPath)) {
    $fail('release-manifest.json is missing; run php scripts/generate-release-manifest.php');
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    $fail('release-manifest.json is not valid JSON');
}
$assertions++;
if (($manifest['disyl_version'] ?? null) !== $disylDeclared) {
    $fail('manifest disyl_version ' . var_export($manifest['disyl_version'] ?? null, true) . " != declared '{$disylDeclared}'");
}
$assertions++;
if (($manifest['kernel_version'] ?? null) !== $kernelDeclared) {
    $fail('manifest kernel_version ' . var_export($manifest['kernel_version'] ?? null, true) . " != declared '{$kernelDeclared}'");
}
$assertions++;

// 4. Generator must not hardcode any version literal.
$generator = (string) file_get_contents($root . '/scripts/generate-release-manifest.php');
if (preg_match("/'[0-9]+\.[0-9]+\.[0-9]+'/", $generator)) {
    $fail('generator hardcodes a version literal');
}
$assertions++;

echo "PASS: manifest versions derive from the single code declarations.\n";
echo "Assertions: {$assertions}\n";
exit(0);
