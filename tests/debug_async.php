<?php
/**
 * Debug DiSyL async test failures
 */
require_once __DIR__ . '/../bootstrap.php';

$tmpRoot = sys_get_temp_dir() . '/disyl_debug_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot . '/tpl', 0777, true);
@mkdir($tmpRoot . '/cache', 0777, true);

$engine = new Ikabud\Kernel\DiSyL\TemplateEngine($tmpRoot . '/tpl', $tmpRoot . '/cache', false);

// Test 8: missing let
file_put_contents($tmpRoot . '/tpl/awmiss.disyl', "{await src='x'}body{catch let=e}NOLET{/await}");
$out = $engine->render('awmiss', []);
echo "Test 8 output: [" . bin2hex($out) . "]\n";
echo "Test 8 raw   : [" . $out . "]\n";
echo "Expected     : [NOLET]\n";
echo "Match: " . (trim($out) === 'NOLET' ? 'YES' : 'NO') . "\n";

// Get errors
$errors = $engine->getErrors();
if (!empty($errors)) {
    echo "Engine errors:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
