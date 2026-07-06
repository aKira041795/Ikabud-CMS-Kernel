<?php
require_once __DIR__ . '/../bootstrap.php';

$engine = new Ikabud\Kernel\DiSyL\TemplateEngine(__DIR__ . '/../templates', '/tmp/disyl_debug3', false);
$engine->enableCompiledMode(true);

echo "=== Understanding the engine behavior ===\n\n";

// Test 1: Simple variable access with a missing
echo "1. a alone (missing): " . json_encode($engine->renderString('{a}', [])) . "\n";

// Test 2: a missing in addition
echo "2. a+b (a missing): " . json_encode($engine->renderString('{a + b}', ['b' => 5])) . "\n";

// Test 3: b alone before pipe
echo "3. b|default:0: " . json_encode($engine->renderString('{b|default:0}', ['b' => 5])) . "\n";

// Test 4: Default filter on a when missing
echo "4. a|default:0: " . json_encode($engine->renderString('{a|default:0}', [])) . "\n";

// Test 5: Both numeric - does pipe detection work?
echo "5. Both numeric a+b|default: " . json_encode($engine->renderString('{a + b|default:0}', ['a' => 5, 'b' => 3])) . "\n";

// Test 6: Compare with parens
echo "6. (a+b)|default:0: " . json_encode($engine->renderString('{(a + b)|default:0}', ['a' => null, 'b' => 5])) . "\n";

// Test 7: a + (b|default:0) - pipe applied to b only
echo "7. a + (b|default:0): " . json_encode($engine->renderString('{a + (b|default:0)}', ['a' => null, 'b' => 5])) . "\n";

echo "\n=== Now testing the NUMBER_FILTER test ===\n";
echo "8. (a+b)|number_format:2: " . json_encode($engine->renderString('{(a + b)|number_format:2}', ['a' => 5, 'b' => 3.25])) . "\n";
