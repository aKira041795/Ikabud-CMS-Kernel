<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/helpers/architecture-check-fingerprint.php';

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? " — {$detail}" : '');
    echo "✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$baselineSameConstruct = [
    'rule' => 'cross-module-table-access',
    'module' => 'orders',
    'path' => './modules/orders/handlers.php',
    'line' => 12,
    'evidence' => 'wms_returns|wms|SELECT * FROM wms_returns',
];
$currentSameConstruct = [
    'rule' => 'cross-module-table-access',
    'module' => 'orders',
    'path' => 'modules/orders/handlers.php',
    'line' => 77,
    'evidence' => 'wms_returns|wms|SELECT id FROM wms_returns WHERE status = 1',
];

$fingerprintA = boundaryFindingFingerprint($baselineSameConstruct);
$fingerprintB = boundaryFindingFingerprint($currentSameConstruct);

t('same construct ignores line/snippet churn', $fingerprintA === $fingerprintB, $fingerprintA . ' !== ' . $fingerprintB);
t('same construct is not reported as new', boundaryNewFindings([
    $currentSameConstruct + ['fingerprint' => $fingerprintB],
], [
    $baselineSameConstruct + ['fingerprint' => $fingerprintA],
]) === []);

$changedConstruct = [
    'rule' => 'cross-module-table-access',
    'module' => 'orders',
    'path' => 'modules/orders/handlers.php',
    'line' => 77,
    'evidence' => 'wms_shipments|wms|SELECT * FROM wms_shipments',
];
$fingerprintC = boundaryFindingFingerprint($changedConstruct);
$newFindings = boundaryNewFindings([$changedConstruct], [$baselineSameConstruct + ['fingerprint' => $fingerprintA]]);

t('different construct changes fingerprint', $fingerprintA !== $fingerprintC, $fingerprintA . ' === ' . $fingerprintC);
t('different construct is reported as new', count($newFindings) === 1, 'count=' . count($newFindings));

$legacyBaseline = [[
    'rule' => 'undeclared-capability-call',
    'module' => 'billing',
    'path' => 'modules/billing/helpers.php',
    'line' => 5,
    'evidence' => 'inventory.reserve@1',
]];
$currentLegacyMatch = [[
    'rule' => 'undeclared-capability-call',
    'module' => 'billing',
    'path' => 'modules/billing/helpers.php',
    'line' => 200,
    'evidence' => 'inventory.reserve@1',
]];
$currentLegacyNew = [[
    'rule' => 'undeclared-capability-call',
    'module' => 'billing',
    'path' => 'modules/billing/helpers.php',
    'line' => 200,
    'evidence' => 'inventory.release@1',
]];

t('legacy baseline without fingerprint still protects same construct', boundaryNewFindings($currentLegacyMatch, $legacyBaseline) === []);
t('legacy baseline still detects different capability as new', count(boundaryNewFindings($currentLegacyNew, $legacyBaseline)) === 1);

$authBase = [
    'rule' => 'auth-route-contract',
    'module' => 'authmod',
    'path' => 'modules/authmod/module.json',
    'line' => 0,
    'evidence' => 'logout_path,login_page,auth_login',
];
$authCurrent = [
    'rule' => 'auth-route-contract',
    'module' => 'authmod',
    'path' => 'modules/authmod/module.json',
    'line' => 0,
    'evidence' => 'auth_login,login_page,logout_path',
];

t('auth-route missing list is order-stable', boundaryFindingFingerprint($authBase) === boundaryFindingFingerprint($authCurrent));

echo "\nPass: {$pass}\nFail: {$fail}\n";
if ($fail > 0) {
    echo "\nFailures:\n- " . implode("\n- ", $errors) . "\n";
    exit(1);
}
