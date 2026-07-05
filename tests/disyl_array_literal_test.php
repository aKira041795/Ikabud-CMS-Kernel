<?php
/**
 * Quick test for TD-D3 array literal syntax — section 45 only.
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

echo "── Array Literal Syntax (TD-D3) ──────────────────────────────\n";

$tmpDir = sys_get_temp_dir() . '/disyl_arr_td3_tmp_' . getmypid();
@mkdir($tmpDir, 0755, true);

$e = new TemplateEngine($tmpDir, '/tmp/disyl_arr_td3_cache_' . getmypid(), false);
$e->enableCompiledMode(false); // interpreted path

check(
    'interpreted: for item in string array literal',
    'a,b,c,',
    $e->renderString("{for item in ['a','b','c']}{item},{/for}", [])
);

check(
    'interpreted: set array literal then iterate',
    'x,y,z,',
    $e->renderString("{set items = ['x','y','z']}{for item in items}{item},{/for}", [])
);

check(
    'interpreted: for item in numeric array literal',
    '1,2,3,',
    $e->renderString("{for item in [1,2,3]}{item},{/for}", [])
);

check(
    'interpreted: array literal with join filter',
    'hello, world',
    $e->renderString("{['hello','world'] | join:', '}", [])
);

check(
    'interpreted: empty array literal yields no output',
    '',
    $e->renderString("{for item in []}{item},{/for}", [])
);

check(
    'interpreted: set array then count',
    '3',
    $e->renderString("{set arr = ['a','b','c']}{arr | count}", [])
);

check(
    'interpreted: for iterates context array (baseline)',
    'd,e,f,',
    $e->renderString("{for item in items}{item},{/for}", ['items' => ['d','e','f']])
);

// Compiled mode tests
$cc = new \Ikabud\Kernel\DiSyL\Compiler\TemplateCache('/tmp/disyl_arr_td3_' . getmypid(), true);

check(
    'compiled: for item in string array literal',
    'a,b,c,',
    $cc->compileSource("{for item in ['a','b','c']}{item},{/for}", 'arr_for_str')->execute([])
);

check(
    'compiled: set array literal then iterate',
    'x,y,z,',
    $cc->compileSource("{set items = ['x','y','z']}{for item in items}{item},{/for}", 'arr_set_iter')->execute([])
);

check(
    'compiled: array literal with join filter',
    'hello, world',
    $cc->compileSource("{['hello','world'] | join:', '}", 'arr_join')->execute([])
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
