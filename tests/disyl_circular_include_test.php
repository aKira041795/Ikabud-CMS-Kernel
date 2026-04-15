<?php
/**
 * R33 — DiSyL Circular Include Detection Test
 *
 * Verifies that circular {include} references are caught and don't
 * cause infinite loops. Also tests max iteration limit.
 *
 * Run: php tests/disyl_circular_include_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

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
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "=== DiSyL Circular Include Detection ===\n";

// ── Setup temp template directory ──
$tmpDir = sys_get_temp_dir() . '/disyl_circular_test_' . getmypid();
@mkdir($tmpDir, 0755, true);
$cacheDir = $tmpDir . '/cache';
@mkdir($cacheDir, 0755, true);

$engine = new TemplateEngine($tmpDir, $cacheDir, false);

// ── Test 1: Direct circular (A includes B, B includes A) ──
file_put_contents("{$tmpDir}/a.disyl", 'A-start {include "b"} A-end');
file_put_contents("{$tmpDir}/b.disyl", 'B-start {include "a"} B-end');

$out = $engine->render('a');
t('direct circular does not hang (returns)', true); // If we get here, it didn't infinite loop
t('circular include produces output without crash', is_string($out) && strlen($out) > 0);
$engineErrors = $engine->getErrors();
t('circular include logs an error', !empty($engineErrors));

// ── Test 2: Indirect circular (A → B → C → A) ──
file_put_contents("{$tmpDir}/x.disyl", 'X {include "y"}');
file_put_contents("{$tmpDir}/y.disyl", 'Y {include "z"}');
file_put_contents("{$tmpDir}/z.disyl", 'Z {include "x"}');

$out2 = $engine->render('x');
t('indirect circular does not hang', true);
t('indirect circular produces output', is_string($out2) && strlen($out2) > 0);

// ── Test 3: Self-include (A includes A) ──
file_put_contents("{$tmpDir}/self.disyl", 'SELF {include "self"}');

$out3 = $engine->render('self');
t('self-include does not hang', true);
t('self-include contains SELF text', str_contains($out3, 'SELF'));

// ── Test 4: Non-circular include works normally ──
file_put_contents("{$tmpDir}/header.disyl", '<header>HEADER</header>');
file_put_contents("{$tmpDir}/main.disyl", '{include "header"}<main>MAIN</main>');

$out4 = $engine->render('main');
t('non-circular include resolves normally', str_contains($out4, '<header>HEADER</header>') && str_contains($out4, '<main>MAIN</main>'));

// ── Test 5: Missing include is graceful ──
file_put_contents("{$tmpDir}/with-missing.disyl", 'Before {include "nonexistent"} After');
$out5 = $engine->render('with-missing');
t('missing include is graceful', str_contains($out5, 'Before') && str_contains($out5, 'After'));

// ── Cleanup ──
$files = glob("{$tmpDir}/*");
foreach ($files as $f) {
    if (is_file($f)) @unlink($f);
}
$cacheFiles = glob("{$cacheDir}/*");
foreach ($cacheFiles as $f) {
    if (is_file($f)) @unlink($f);
}
@rmdir($cacheDir);
@rmdir($tmpDir);

// ── Summary ──
echo "\n{$pass} passed, {$fail} failed\n";
if (!empty($errors)) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
exit($fail > 0 ? 1 : 0);
