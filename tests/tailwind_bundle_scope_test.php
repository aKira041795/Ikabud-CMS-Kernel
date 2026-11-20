<?php

declare(strict_types=1);

/**
 * Tailwind bundle scope — two configs, two artefacts, and they must not be confused.
 *
 * MEASURED MISTAKE THIS GUARDS (2026-11-20, mine): I added the daily-ledger module to
 * `tailwind.config.js` believing that missing content globs were why the module shipped a 407 KB
 * browser Tailwind compiler. Both halves were wrong:
 *   1. `tailwind.config.js` builds the ARK WORKBENCH bundles, and no daily-ledger template loads
 *      them — its layout loads the self-hosted compiler, a deliberate owner decision (2026-11-13).
 *   2. The compiler is not a symptom of a stale build at all.
 * The cost was measurable and pure loss: the workbench bundle grew 30,698 -> 54,872 bytes (+79%) for
 * pages that are not daily-ledger.
 *
 * The app/DiSyL bundle is a DIFFERENT artefact (`tailwind.app.config.js` -> public/assets/tailwind/
 * app.css) and its coverage is already module-wide by construction. That is the property worth
 * pinning: it is what makes "a module was silently left out of the build" impossible in the first
 * place, instead of something each module has to remember to register.
 *
 * Each assertion names what breaks if it regresses:
 * - a per-module glob reappearing in the workbench config: another module pays for CSS it never gets;
 * - module-wide globs disappearing from the app config: any module's utility classes vanish from the
 *   compiled DiSyL stylesheet, and pages render with unstyled spacing;
 * - a rebuilt bundle not being committed: the artefact drifts from the templates it is built from.
 */

ob_start();
require_once __DIR__ . '/harness/TestHarness.php';
$h = new TestHarness('tailwind-bundle-scope', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$root = dirname(__DIR__);

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed, $h): void {
    if ($ok) {
        $passed++;
        $h->test($label, true);
        return;
    }
    $failed++;
    $h->test($label, false, $detail);
};

// ── The workbench config must stay scoped to workbench consumers ─────
$workbenchConfigPath = $root . '/tailwind.config.js';
$check('workbench config exists', is_file($workbenchConfigPath));

$workbenchSource = is_file($workbenchConfigPath) ? (string)file_get_contents($workbenchConfigPath) : '';
// Read the content array through node so comments inside the array cannot confuse the assertion.
$workbenchGlobs = [];
if ($workbenchSource !== '') {
    $probe = @shell_exec('cd ' . escapeshellarg($root) . ' && node -e '
        . escapeshellarg('const c=require("./tailwind.config.js");process.stdout.write(JSON.stringify(c.content||[]))')
        . ' 2>/dev/null');
    if (is_string($probe) && $probe !== '') {
        $decoded = json_decode($probe, true);
        if (is_array($decoded)) {
            $workbenchGlobs = $decoded;
        }
    }
}
$check('workbench content globs are readable', $workbenchGlobs !== []);

$offenders = array_values(array_filter(
    $workbenchGlobs,
    static fn($glob) => is_string($glob) && str_contains($glob, 'daily-ledger')
));
$check(
    'the workbench bundle does not scan daily-ledger (it is never served that module)',
    $offenders === [],
    'found: ' . implode(', ', $offenders)
);

// ── The DiSyL/app config must stay module-wide ────────────────────────
$appConfigPath = $root . '/tailwind.app.config.js';
$check('app config exists', is_file($appConfigPath));

$appSource = is_file($appConfigPath) ? (string)file_get_contents($appConfigPath) : '';
// Coverage must be DERIVED (a ** wildcard over every module), not a per-module list.
$check(
    'app config scans every module template, not a hand-listed subset',
    str_contains($appSource, "'./modules/**/*.disyl'") || str_contains($appSource, '"./modules/**/*.disyl"')
);
$check(
    'app config scans every module PHP file, not a hand-listed subset',
    str_contains($appSource, "'./modules/**/*.php'") || str_contains($appSource, '"./modules/**/*.php"')
);
$check(
    'app config scans every DiSyL template directory',
    str_contains($appSource, "'./templates/**/*.disyl'") || str_contains($appSource, '"./templates/**/*.disyl"')
);

// ── Both artefacts must exist and be non-trivial ─────────────────────
$appBundle = $root . '/public/assets/tailwind/app.css';
$workbenchBundle = $root . '/public/assets/workbench/workbench-tailwind.css';
$check('the DiSyL bundle is committed', is_file($appBundle));
$check('the workbench bundle is committed', is_file($workbenchBundle));
if (is_file($appBundle)) {
    $size = (int)filesize($appBundle);
    $check('the DiSyL bundle is not empty', $size > 20000, $size . ' bytes');
    // Utilities the module templates actually use must survive a rebuild. Searched with CSS escaping:
    // minified CSS writes `.py-2\.5` and `.text-\[11px\]`, and grepping for the unescaped selector
    // produces a FALSE NEGATIVE - which is exactly how the "370 tokens missing" claim was born.
    $appCss = (string)file_get_contents($appBundle);
    $check('DiSyL bundle contains py-2.5', str_contains($appCss, 'py-2\\.5'));
    $check('DiSyL bundle contains text-[11px]', str_contains($appCss, 'text-\\[11px\\]'));
}
if (is_file($workbenchBundle)) {
    $size = (int)filesize($workbenchBundle);
    // 30-33 KB is the measured size of the workbench-scoped build; 55 KB was the daily-ledger-inflated one.
    $check('the workbench bundle stays at its scoped size', $size > 20000 && $size < 45000, $size . ' bytes');
}

$h->done();
echo "\n" . str_repeat('─', 60) . "\n";
echo ($failed === 0 ? 'PASS' : 'FAIL') . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
