<?php
/**
 * Fleet-level dependency gate.
 *
 * Two invariants, both about honesty rather than features:
 *
 * 1. NO MODULE MAY HAVE A BLOCKED REQUIRED DEPENDENCY. A declared dependency
 *    that cannot be satisfied means dispatch would throw for that call. Eight
 *    modules were in this state when readiness was first wired up; seven were
 *    modules genuinely calling a capability their provider did not permit (fixed
 *    by adding them to allow_callers) and two were stale declarations nobody
 *    called (removed). This gate keeps it at zero.
 *
 * 2. A MODULE MUST NOT GAIN A NEW UNDECLARED CAPABILITY. Ten modules already
 *    call capabilities they do not declare - config/module-dependency-baseline.json
 *    freezes that debt so it cannot grow while it is burned down deliberately.
 *    The fix is to declare the dependency, never to widen the baseline file.
 *
 * Both checks read the manifests and the module sources; neither changes
 * anything. Run: php tests/module_dependency_fleet_gate_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
require_once BASE_PATH . '/src/helpers/module-routes.php';
// Readiness asks the live registry for providers, so the runtime has to be
// loaded the way a request loads it; otherwise every dependency looks missing
// and this gate would report a fleet that is entirely broken.
loadModuleRoutes([]);
ob_end_clean();

$passed = 0;
$failed = 0;
function mfgAssert(string $label, bool $condition, string $detail = ''): void
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

$modules = discoverModules();
mfgAssert('the fleet is discovered', $modules !== [], (string) count($modules));

// ── 1. Nothing may be blocked ──────────────────────────────────────────────
$blocked = [];
$degraded = [];
foreach ($modules as $moduleId => $manifest) {
    if (moduleCapabilityRequirements($manifest) === []) {
        continue;
    }
    $readiness = moduleDependencyReadiness($manifest);
    $state = (string) ($readiness['state'] ?? 'ready');
    if ($state === 'blocked') {
        $problems = [];
        foreach (($readiness['requirements'] ?? []) as $requirement) {
            if ((string) ($requirement['state'] ?? '') === 'ready') {
                continue;
            }
            $problems[] = (string) ($requirement['id'] ?? '') . '=' . (string) ($requirement['reason_code'] ?? '');
        }
        $blocked[(string) $moduleId] = implode(', ', $problems);
    } elseif ($state === 'degraded') {
        $degraded[] = (string) $moduleId;
    }
}

mfgAssert(
    'no installed module has a blocked required dependency',
    $blocked === [],
    implode('; ', array_map(static fn($id, $why) => $id . ' [' . $why . ']', array_keys($blocked), $blocked))
);
if ($degraded !== []) {
    echo 'NOTE: degraded (optional dependency unmet): ' . implode(', ', $degraded) . "\n";
}

// ── 2. Undeclared capability usage must not grow ──────────────────────────
$baselinePath = BASE_PATH . '/config/module-dependency-baseline.json';
mfgAssert('the undeclared-usage baseline exists', is_file($baselinePath));
$baseline = [];
if (is_file($baselinePath)) {
    $decoded = json_decode((string) file_get_contents($baselinePath), true);
    $baseline = is_array($decoded['modules'] ?? null) ? $decoded['modules'] : [];
}

$current = [];
foreach ($modules as $moduleId => $manifest) {
    $dir = (string) ($manifest['_path'] ?? '');
    if ($dir === '' || !is_dir($dir)) {
        continue;
    }

    $known = [];
    foreach (moduleCapabilityRequirements($manifest) as $requirement) {
        $known[(string) $requirement['id']] = true;
    }
    foreach (($manifest['capabilities']['exposes'] ?? []) as $exposed) {
        $known[(string) ($exposed['id'] ?? '')] = true;
    }

    $used = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $file): bool => !($file->isDir() && $file->getFilename() === 'tests')
        )
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        // Skip fixture trees: a capability id quoted in a test is not a call.
        if (preg_match('#/tests/#', $file->getPathname()) === 1) {
            continue;
        }
        foreach (file($file->getPathname()) ?: [] as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            if (preg_match_all("/'([a-z][a-z0-9_.]*@\\d+)'/", $line, $matches) > 0) {
                foreach ($matches[1] as $capabilityId) {
                    $used[$capabilityId] = true;
                }
            }
        }
    }

    $undeclared = array_values(array_diff(array_keys($used), array_keys($known)));
    sort($undeclared);
    if ($undeclared !== []) {
        $current[(string) $moduleId] = $undeclared;
    }
}

$newOffenders = [];
foreach ($current as $moduleId => $capabilities) {
    $allowed = is_array($baseline[$moduleId] ?? null) ? $baseline[$moduleId] : [];
    $added = array_values(array_diff($capabilities, $allowed));
    if ($added !== []) {
        $newOffenders[] = $moduleId . ' calls undeclared ' . implode(', ', $added);
    }
}

mfgAssert(
    'no module gained an undeclared capability',
    $newOffenders === [],
    implode(' | ', $newOffenders) . ' — declare it in capabilities.depends (required or optional); do not widen config/module-dependency-baseline.json'
);

$burned = 0;
foreach ($baseline as $moduleId => $capabilities) {
    $still = $current[$moduleId] ?? [];
    $burned += count(array_diff($capabilities, $still));
}
if ($burned > 0) {
    echo 'NOTE: ' . $burned . " baseline entr(ies) no longer reproduce - shrink config/module-dependency-baseline.json to keep the ratchet tight.\n";
}

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
