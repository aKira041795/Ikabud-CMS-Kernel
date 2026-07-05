<?php
/**
 * Focused tests for DiSyL loop-control tags {break} and {continue}.
 */
foreach (glob(__DIR__ . '/../kernel/DiSyL/Exceptions/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/Security/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/v4/AST/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/v4/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/CMS/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/Compiler/*.php') as $file) {
    require_once $file;
}
require_once __DIR__ . '/../kernel/DiSyL/Grammar.php';
require_once __DIR__ . '/../kernel/DiSyL/ExpressionEvaluator.php';
require_once __DIR__ . '/../kernel/DiSyL/ComponentRegistry.php';
require_once __DIR__ . '/../kernel/DiSyL/TemplateEngine.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0;
$fail = 0;

function check(string $desc, string $expected, string $actual): void
{
    global $pass, $fail;
    $expected = trim($expected);
    $actual = trim($actual);
    if ($expected === $actual) {
        echo "  ✓ {$desc}\n";
        $pass++;
    } else {
        echo "  ✗ {$desc}\n";
        echo "    Expected: " . json_encode($expected) . "\n";
        echo "    Actual:   " . json_encode($actual) . "\n";
        $fail++;
    }
}

echo "── Loop Control Tags (TD-D5) ───────────────────────────────\n";

$tmpDir = sys_get_temp_dir() . '/disyl_loop_ctrl_tmp_' . getmypid();
@mkdir($tmpDir, 0755, true);

$e = new TemplateEngine($tmpDir, '/tmp/disyl_loop_ctrl_cache_' . getmypid(), false);
$e->enableCompiledMode(false); // interpreted path

check(
    'interpreted: continue skips current iteration',
    'a,c,d,',
    $e->renderString('{for item in items}{if item == "b"}{continue}{/if}{item},{/for}', ['items' => ['a', 'b', 'c', 'd']])
);

check(
    'interpreted: break stops loop',
    'a,b,',
    $e->renderString('{for item in items}{if item == "c"}{break}{/if}{item},{/for}', ['items' => ['a', 'b', 'c', 'd']])
);

check(
    'interpreted: while + break works',
    'one',
    $e->renderString('{while true}one{break}two{/while}', [])
);

check(
    'interpreted: while + continue works',
    '',
    $e->renderString('{while false}x{continue}y{/while}', [])
);

$cc = new \Ikabud\Kernel\DiSyL\Compiler\TemplateCache('/tmp/disyl_loop_ctrl_compiled_' . getmypid(), true);

check(
    'compiled: continue skips current iteration',
    'a,c,d,',
    $cc->compileSource('{for item in items}{if item == "b"}{continue}{/if}{item},{/for}', 'loop_continue')->execute(['items' => ['a', 'b', 'c', 'd']])
);

check(
    'compiled: break stops loop',
    'a,b,',
    $cc->compileSource('{for item in items}{if item == "c"}{break}{/if}{item},{/for}', 'loop_break')->execute(['items' => ['a', 'b', 'c', 'd']])
);

echo "\n──────────────────────────────────────────────────────────────\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "  ✓ ALL {$pass} TESTS PASSED\n";
} else {
    echo "  {$pass} passed, {$fail} FAILED (out of {$total})\n";
}
echo "──────────────────────────────────────────────────────────────\n";
exit($fail > 0 ? 1 : 0);
