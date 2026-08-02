<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/helpers/manifest-validation.php';

$tempDir = sys_get_temp_dir() . '/ikabud-manifest-parity-' . bin2hex(random_bytes(6));
$manifestPath = $tempDir . '/module.json';
mkdir($tempDir, 0777, true);
file_put_contents($manifestPath, json_encode([
    'id' => 'parity-fixture',
    'name' => 'Parity Fixture',
    'version' => '1.0.0',
    'routes' => ['GET' => ['/invalid' => 'handler']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

try {
    $guard = validateModuleManifestForGuardV1($manifestPath);
    $architecture = validateModuleManifestForArchitectureV1($manifestPath);
    $guardDiagnostic = $guard['diagnostics'][0] ?? null;
    $architectureDiagnostic = $architecture['diagnostics'][0] ?? null;

    $checks = [
        'guard rejects invalid fixture' => $guard['ok'] === false,
        'architecture rejects invalid fixture' => $architecture['ok'] === false,
        'diagnostic code parity' => ($guardDiagnostic['code'] ?? null) === ($architectureDiagnostic['code'] ?? null),
        'diagnostic rule parity' => ($guardDiagnostic['rule'] ?? null) === ($architectureDiagnostic['rule'] ?? null),
        'diagnostic severity parity' => ($guardDiagnostic['severity'] ?? null) === ($architectureDiagnostic['severity'] ?? null),
        'diagnostic correction parity' => ($guardDiagnostic['correction'] ?? null) === ($architectureDiagnostic['correction'] ?? null),
    ];

    $failed = 0;
    foreach ($checks as $label => $ok) {
        echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
        if (!$ok) {
            $failed++;
        }
    }
} finally {
    @unlink($manifestPath);
    @rmdir($tempDir);
}

exit($failed === 0 ? 0 : 1);
