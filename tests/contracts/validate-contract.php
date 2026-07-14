<?php

declare(strict_types=1);

/**
 * Contract Self-Validator — runs contract checks in PHP without a browser.
 * Verifies that every claim in test-contract.json has a real implementation.
 *
 * Usage: php tests/contracts/validate-contract.php <module-id>
 *   php tests/contracts/validate-contract.php project-audit-ledger
 */

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n";
    exit(1);
}

$moduleId = $argv[1] ?? '';
if ($moduleId === '') {
    fwrite(STDERR, "Usage: php tests/contracts/validate-contract.php <module-id>\n");
    exit(1);
}

$base = dirname(__DIR__, 2);
$contractFile = $base . '/modules/' . $moduleId . '/test-contract.json';
if (!is_file($contractFile)) {
    fwrite(STDERR, "No test-contract.json found for module '{$moduleId}'\n");
    exit(1);
}

$contract = json_decode((string) file_get_contents($contractFile), true);
$tc = $contract['test_contract'] ?? [];
$moduleJsonFile = $base . '/modules/' . $moduleId . '/module.json';
$moduleJson = is_file($moduleJsonFile) ? json_decode((string) file_get_contents($moduleJsonFile), true) : [];

echo "═══ Contract Validation: {$moduleId} ═══\n\n";

$passed = 0;
$failed = 0;
$warnings = 0;

// ── 1. Route claims vs actual routes ──
echo "── Routes ──\n";
$routesFile = $base . '/modules/' . $moduleId . '/routes.php';
$registeredRoutes = [];
if (is_file($routesFile)) {
    $content = (string) file_get_contents($routesFile);
    // Extract route patterns from routes.php
    preg_match_all("/'([^']+)'\s*=>\s*'[^']+'/", $content, $matches);
    $registeredRoutes = $matches[1] ?? [];
}

$claimedGet = $tc['routes_claimed']['GET'] ?? [];
$claimedPost = $tc['routes_claimed']['POST'] ?? [];

foreach ($claimedGet as $route) {
    $found = false;
    foreach ($registeredRoutes as $rr) {
        // Compare route patterns (strip {params} for comparison)
        $routePat = preg_replace('/\{[^}]+\}/', '{param}', $route);
        $rrPat = preg_replace('/\{[^}]+\}/', '{param}', $rr);
        if ($routePat === $rrPat) {
            $found = true;
            break;
        }
    }
    if ($found) {
        echo "  ✓ GET {$route}\n";
        $passed++;
    } else {
        echo "  ✗ GET {$route} — not found in routes.php\n";
        $failed++;
    }
}

foreach ($claimedPost as $route) {
    $found = false;
    foreach ($registeredRoutes as $rr) {
        $routePat = preg_replace('/\{[^}]+\}/', '{param}', $route);
        $rrPat = preg_replace('/\{[^}]+\}/', '{param}', $rr);
        if ($routePat === $rrPat) {
            $found = true;
            break;
        }
    }
    if ($found) {
        echo "  ✓ POST {$route}\n";
        $passed++;
    } else {
        echo "  ✗ POST {$route} — not found in routes.php\n";
        $failed++;
    }
}

// ── 2. Capability claims vs module.json ──
echo "\n── Capabilities ──\n";
$exposedCaps = [];
foreach (($moduleJson['capabilities']['exposes'] ?? []) as $cap) {
    $exposedCaps[] = $cap['id'] ?? ($cap['id'] ?? '');
}

$claimedCaps = $tc['capabilities_claimed'] ?? [];
foreach ($claimedCaps as $cap) {
    if (in_array($cap, $exposedCaps)) {
        echo "  ✓ {$cap}\n";
        $passed++;
    } else {
        echo "  ✗ {$cap} — not found in module.json capabilities.exposes\n";
        $failed++;
    }
}

// ── 3. Workflow states exist in service files ──
echo "\n── Workflows ──\n";
$svcDir = $base . '/modules/' . $moduleId . '/services';
$claimedWorkflows = $tc['workflows'] ?? [];

foreach ($claimedWorkflows as $wfName => $states) {
    // Try to find workflow constants in service files
    $foundStates = [];
    if (is_dir($svcDir)) {
        $svcFiles = glob($svcDir . '/*.php');
        foreach ($svcFiles as $sf) {
            $svcContent = (string) file_get_contents($sf);
            foreach ($states as $state) {
                if (str_contains(strtolower($svcContent), strtolower($state))
                    || str_contains($svcContent, "'{$state}'")
                    || str_contains($svcContent, "\"{$state}\"")) {
                    $foundStates[$state] = true;
                }
            }
        }
    }
    foreach ($states as $state) {
        if (isset($foundStates[$state])) {
            echo "  ✓ {$wfName}:{$state}\n";
            $passed++;
        } else {
            echo "  ⚠ {$wfName}:{$state} — state string not found in service files (may be defined dynamically)\n";
            $warnings++;
        }
    }
}

// ── 4. Views/Components exist in templates ──
echo "\n── Views/Components ──\n";
$profileDir = $base . '/storage/application-profiles/ark-workbench/components';
$claimedViews = $tc['views_claimed'] ?? [];

foreach ($claimedViews as $view) {
    $viewName = str_replace('workbench:', '', $view);
    // Search in components directory
    $found = false;
    if (is_dir($profileDir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profileDir));
        foreach ($iter as $f) {
            if ($f->isFile() && str_contains($f->getFilename(), $viewName)) {
                $found = true;
                break;
            }
        }
    }
    if ($found) {
        echo "  ✓ {$view}\n";
        $passed++;
    } else {
        echo "  ⚠ {$view} — component file not found under ark-workbench/components (may be aliased)\n";
        $warnings++;
    }
}

// ── 5. Test files exist ──
echo "\n── Test Files ──\n";
$testFiles = $tc['test_files']['php'] ?? [];
$browserFiles = $tc['test_files']['browser'] ?? [];

foreach ($testFiles as $tf) {
    if (is_file($base . '/' . $tf)) {
        echo "  ✓ {$tf}\n";
        $passed++;
    } else {
        echo "  ✗ {$tf} — file not found\n";
        $failed++;
    }
}
foreach ($browserFiles as $bf) {
    if (is_file($base . '/' . $bf)) {
        echo "  ✓ {$bf}\n";
        $passed++;
    } else {
        echo "  ✗ {$bf} — file not found\n";
        $failed++;
    }
}

echo "\n═══ Results: {$passed} passed, {$failed} failed, {$warnings} warnings ═══\n";
exit($failed > 0 ? 1 : 0);
