<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
};

// ── policy resolver defaults ─────────────────────────────────────────────
$defaults = moduleUninstallPolicyForManifest([]);
$assert($defaults['disable_safe'] === true, 'default disable_safe is true');
$assert($defaults['retain_data_by_default'] === true, 'default retain_data_by_default is true');
$assert($defaults['supports_data_export'] === false, 'default supports_data_export is false');
$assert($defaults['requires_confirmation_to_drop_data'] === true, 'default requires_confirmation_to_drop_data is true');

$declared = moduleUninstallPolicyForManifest([
    'uninstall' => [
        'disable_safe' => false,
        'retain_data_by_default' => false,
        'supports_data_export' => true,
        'requires_confirmation_to_drop_data' => false,
    ],
]);
$assert($declared['disable_safe'] === false, 'declared disable_safe honored');
$assert($declared['retain_data_by_default'] === false, 'declared retain_data_by_default honored');
$assert($declared['supports_data_export'] === true, 'declared supports_data_export honored');
$assert($declared['requires_confirmation_to_drop_data'] === false, 'declared requires_confirmation_to_drop_data honored');

// Partial declarations merge with defaults.
$partial = moduleUninstallPolicyForManifest(['uninstall' => ['disable_safe' => false]]);
$assert($partial['disable_safe'] === false && $partial['retain_data_by_default'] === true, 'partial uninstall policy merges with defaults');

// ── uninstallModule guards return before mutation ────────────────────────
// Use a real on-disk module so modulePathForId resolves; the guard branches
// return before disableModule()/file removal, so no state is changed.
$targetId = 'daily-ledger';
$assert(modulePathForId($targetId) !== null, 'daily-ledger module present for guard tests');

// Export requested but not supported → refused, no side effects.
$r = uninstallModule($targetId, ['export' => true]);
$assert(($r['error_code'] ?? '') === 'uninstall_export_unsupported', 'export without supports_data_export is refused');

// Purge requested without confirmation → refused.
$r = uninstallModule($targetId, ['purge' => true]);
$assert(($r['error_code'] ?? '') === 'uninstall_purge_requires_confirmation', 'purge without confirmation is refused');

// ── non-safe module requires force ───────────────────────────────────────
// A manifest declaring disable_safe=false must be refused unless force. We
// only exercise the refusal branch (non-destructive: it returns before
// disableModule/file removal) and restore the manifest immediately.
$nonSafeManifestPath = moduleManifestPathForId($targetId);
$originalManifest = $nonSafeManifestPath !== null ? file_get_contents($nonSafeManifestPath) : false;
if ($nonSafeManifestPath !== null && $originalManifest !== false) {
    $decoded = json_decode($originalManifest, true);
    $decoded['uninstall'] = ['disable_safe' => false];
    file_put_contents($nonSafeManifestPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    try {
        $r = uninstallModule($targetId, []);
        $assert(($r['error_code'] ?? '') === 'uninstall_not_disable_safe', 'disable_safe=false refused without force');
    } finally {
        file_put_contents($nonSafeManifestPath, $originalManifest);
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
