<?php

/** Regression guard for validation manifests losing their filesystem path. */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';

$fixtureDir = sys_get_temp_dir() . '/ikabud-manifest-path-' . bin2hex(random_bytes(6));
$fixtureManifest = $fixtureDir . '/module.json';
if (!mkdir($fixtureDir, 0775, true) && !is_dir($fixtureDir)) {
    throw new RuntimeException('Unable to create invalid-manifest fixture directory');
}
file_put_contents($fixtureManifest, json_encode([
    'id' => 'manifest-path-loader-invalid-fixture',
    'name' => 'Manifest Path Loader Invalid Fixture',
    'version' => '1.0.0',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

register_shutdown_function(static function () use ($fixtureManifest, $fixtureDir): void {
    @unlink($fixtureManifest);
    @rmdir($fixtureDir);
});

$passed = 0;
$failed = 0;
$test = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$manifest = loadModuleManifestForId('analytics-cds');
$expectedPath = $root . '/modules/healthcare/analytics-cds';
$test('MUST-ALLOW: nested module loads', is_array($manifest));
$test(
    'MUST-ALLOW: nested module has its real _path',
    ($manifest['_path'] ?? null) === $expectedPath,
    (string)($manifest['_path'] ?? 'missing')
);

$certification = is_array($manifest) ? validateModuleCertification($manifest) : ['checks' => []];
$c3b = null;
foreach ($certification['checks'] as $check) {
    if (($check['check'] ?? '') === 'C3b: Capability handlers') {
        $c3b = $check;
        break;
    }
}
$test('validation loads helpers.php from _path', function_exists('analytics_cds_cap_ehr_cds_rule_add_1'));
$test('C3b resolves convention handlers', ($c3b['passed'] ?? false) === true, (string)($c3b['detail'] ?? 'check missing'));
$test('missing module is refused with null', loadModuleManifestForId('manifest-path-loader-does-not-exist') === null);

// Inject a discovered path outside modules/ so the invalid-file refusal can be
// proved without exposing a transient module to concurrent discovery.
$fixtureId = 'manifest-path-loader-invalid-fixture';
$GLOBALS['_kernel_discovered_modules'][$fixtureId] = [
    'id' => $fixtureId,
    '_path' => $fixtureDir,
];
$test('valid fixture initially loads', is_array(loadModuleManifestForId($fixtureId)));
file_put_contents($fixtureManifest, "{ invalid json\n");
$test('MUST-REFUSE: invalid manifest is refused with null', loadModuleManifestForId($fixtureId) === null);

$cliSource = (string)file_get_contents($root . '/ikabud');
$test(
    'all direct CLI validation loads use the shared loader',
    substr_count($cliSource, 'loadModuleManifestForId($moduleId)') === 2
        && !str_contains($cliSource, '$packManifest[\'_path\'] =')
);

unset($GLOBALS['_kernel_discovered_modules'][$fixtureId]);
@unlink($fixtureManifest);
@rmdir($fixtureDir);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
