<?php
/**
 * Debug: test render() with compiled mode disabled
 */
require_once __DIR__ . '/../bootstrap.php';

$tmpRoot = sys_get_temp_dir() . '/disyl_debug3_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot . '/tpl', 0777, true);
@mkdir($tmpRoot . '/cache', 0777, true);

// With compiled mode disabled
$engine = new Ikabud\Kernel\DiSyL\TemplateEngine($tmpRoot . '/tpl', $tmpRoot . '/cache', false);
$engine->enableCompiledMode(false);

file_put_contents($tmpRoot . '/tpl/awmiss.disyl', "{await src='x'}body{catch let=e}NOLET{/await}");
$out = $engine->render('awmiss', []);
echo "compiled OFF: [" . $out . "]\n";

// With compiled mode enabled (default)
$engine2 = new Ikabud\Kernel\DiSyL\TemplateEngine($tmpRoot . '/tpl2', $tmpRoot . '/cache2', false);
file_put_contents($tmpRoot . '/tpl2/awmiss.disyl', "{await src='x'}body{catch let=e}NOLET{/await}");
$out2 = $engine2->render('awmiss', []);
echo "compiled ON:  [" . $out2 . "]\n";
