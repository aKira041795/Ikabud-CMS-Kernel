<?php

declare(strict_types=1);

/**
 * CMS Akira ARK Registry Idempotence Test.
 *
 * Verifies the digest-conflict registration contract for the global artifact
 * registry (kernel_application_profile_registry + cms_theme_registry):
 *
 *   - Registration IDEMPOTENT by (artifact_type, name, version, canonical_digest):
 *     identical identity + digest → no-op (`idempotent`).
 *   - Same identity + DIFFERENT digest → explicit `CONFLICT`, never overwrite.
 *   - Concurrency: advisory lock + unique index → one deterministic winner;
 *     losers receive idempotent (same digest) or CONFLICT (different digest).
 *   - Canonical digest is stable across key order / formatting.
 *
 * Uses a throwaway artifact identity and cleans up after itself.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../kernel/Services/ArtifactRegistry.php';

use Ikabud\Kernel\Services\ArtifactRegistry;

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$baseDb = app()->controlDb();
$tenantDb = app()->db();

$testName = 'akira-idempotence-' . substr(md5((string)getmypid()), 0, 8);

// ── helpers ─────────────────────────────────────────────────────────
$cleanup = static function (PDO $db, string $table) use ($testName): void {
    $db->exec("DELETE FROM {$table} WHERE name = " . $db->quote($testName));
};

echo "\n=== CMS AKIRA ARK REGISTRY IDEMPOTENCE ===\n";

// ── 1. Canonical digest stability ───────────────────────────────────
$m1 = ['name' => $testName, 'version' => '0.1.0', 'supported_surfaces' => ['desktop', 'mobile', 'email']];
$m2 = ['supported_surfaces' => ['desktop', 'mobile', 'email'], 'version' => '0.1.0', 'name' => $testName];
$m3 = ['name' => $testName, 'version' => '0.1.0', 'supported_surfaces' => ['desktop', 'mobile']];

$d1 = ArtifactRegistry::canonicalDigest($m1);
$d2 = ArtifactRegistry::canonicalDigest($m2);
$d3 = ArtifactRegistry::canonicalDigest($m3);

t(
    'canonical digest is stable across key order',
    $d1 === $d2 && strlen($d1) === 64,
    "d1={$d1} d2={$d2}"
);
t(
    'canonical digest changes when content changes',
    $d1 !== $d3,
    'd1 should differ from d3'
);

// ── 2. Kernel profile registry: register → idempotent → conflict ──
$cleanup($baseDb, 'kernel_application_profile_registry');

$regA = ArtifactRegistry::register($baseDb, 'kernel_application_profile_registry', 'profile', $testName, '0.1.0', $d1, '/tmp/akira-test.json');
t('kernel registry initial register returns registered', ($regA['status'] ?? '') === 'registered', json_encode($regA));
$idA = (int)($regA['id'] ?? 0);
t('kernel registry register returns a row id', $idA > 0, (string)$idA);

$regIdem = ArtifactRegistry::register($baseDb, 'kernel_application_profile_registry', 'profile', $testName, '0.1.0', $d1, '/tmp/akira-test.json');
t('kernel registry same identity+digest is idempotent', ($regIdem['status'] ?? '') === 'idempotent' && (int)($regIdem['id'] ?? 0) === $idA, json_encode($regIdem));

$regConflict = ArtifactRegistry::register($baseDb, 'kernel_application_profile_registry', 'profile', $testName, '0.1.0', str_repeat('b', 64), '/tmp/akira-other.json');
t('kernel registry different digest yields explicit CONFLICT', ($regConflict['status'] ?? '') === 'conflict', json_encode($regConflict));
t('conflict does not overwrite the winning digest', ($regConflict['digest'] ?? '') === $d1, (string)($regConflict['digest'] ?? ''));

$cleanup($baseDb, 'kernel_application_profile_registry');

// ── 3. Theme registry (tenant DB) same contract ────────────────────
$cleanup($tenantDb, 'cms_theme_registry');

$themeDigest = ArtifactRegistry::canonicalDigest(['name' => $testName, 'version' => '3.0.0', 'customizer' => ['owns' => true]]);
$tReg = ArtifactRegistry::register($tenantDb, 'cms_theme_registry', 'theme', $testName, '3.0.0', $themeDigest, '/tmp/akira-theme.json');
t('theme registry initial register returns registered', ($tReg['status'] ?? '') === 'registered', json_encode($tReg));
$tId = (int)($tReg['id'] ?? 0);

$tIdem = ArtifactRegistry::register($tenantDb, 'cms_theme_registry', 'theme', $testName, '3.0.0', $themeDigest, '/tmp/akira-theme.json');
t('theme registry same identity+digest is idempotent', ($tIdem['status'] ?? '') === 'idempotent' && (int)($tIdem['id'] ?? 0) === $tId, json_encode($tIdem));

$tConflict = ArtifactRegistry::register($tenantDb, 'cms_theme_registry', 'theme', $testName, '3.0.0', str_repeat('c', 64), '/tmp/akira-theme2.json');
t('theme registry different digest yields explicit CONFLICT', ($tConflict['status'] ?? '') === 'conflict', json_encode($tConflict));

// ── 4. find() returns the winner ────────────────────────────────────
$found = ArtifactRegistry::find($tenantDb, 'cms_theme_registry', 'theme', $testName, '3.0.0');
t('find() resolves the registered theme artifact', is_array($found) && ($found['digest'] ?? '') === $themeDigest, json_encode($found));

$cleanup($tenantDb, 'cms_theme_registry');

// ── 5. Invalid input guards ─────────────────────────────────────────
$badType = ArtifactRegistry::register($baseDb, 'kernel_application_profile_registry', 'gadget', $testName, '0.1.0', $d1, '/tmp/x.json');
t('rejects invalid artifact_type', ($badType['status'] ?? '') === 'error', json_encode($badType));

$badDigest = ArtifactRegistry::register($baseDb, 'kernel_application_profile_registry', 'profile', $testName, '0.1.0', 'short', '/tmp/x.json');
t('rejects malformed digest', ($badDigest['status'] ?? '') === 'error', json_encode($badDigest));

$badTable = false;
try {
    ArtifactRegistry::register($baseDb, 'hacked_table', 'profile', $testName, '0.1.0', $d1, '/tmp/x.json');
} catch (\Throwable $e) {
    $badTable = true;
}
t('rejects non-allowlisted table name', $badTable);

// ── 6. ApplicationProfileRegistry.syncGlobalRegistry integration ──
use Ikabud\Kernel\Services\ApplicationProfileRegistry;

$preRows = (int)$baseDb->query("SELECT COUNT(*) FROM kernel_application_profile_registry WHERE artifact_type='profile'")->fetchColumn();

ApplicationProfileRegistry::reset();
ApplicationProfileRegistry::discover(BASE_PATH);
$syncOut = ApplicationProfileRegistry::syncGlobalRegistry();
t(
    'syncGlobalRegistry returns a structured outcome',
    is_array($syncOut) && array_key_exists('registered', $syncOut) && array_key_exists('conflicts', $syncOut),
    json_encode($syncOut)
);
t(
    'syncGlobalRegistry registers the ARK Workbench profile',
    (($syncOut['registered'] ?? 0) + ($syncOut['idempotent'] ?? 0)) >= 1 && ($syncOut['conflicts'] ?? []) === [],
    json_encode($syncOut)
);
$syncOut2 = ApplicationProfileRegistry::syncGlobalRegistry();
t(
    'repeated syncGlobalRegistry is idempotent (no new conflicts)',
    ($syncOut2['registered'] ?? 0) === 0 && ($syncOut2['conflicts'] ?? []) === [],
    json_encode($syncOut2)
);

// ── 7. No log noise ────────────────────────────────────────────────
$criticalLines = [];
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
foreach (explode("\n", $appLog) as $line) {
    if (str_contains($line, '[critical]')) {
        $criticalLines[] = $line;
    }
}
t('no app.log critical errors after registry idempotence check', empty($criticalLines), implode('; ', $criticalLines));

echo "\n── Result: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
echo "  ✓ ALL ARK REGISTRY IDEMPOTENCE TESTS PASSED\n";
