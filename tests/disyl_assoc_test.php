<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$engine = new \Ikabud\Kernel\DiSyL\TemplateEngine();

// Test associative arrays in {set} expressions
$tests = [
    'simple assoc' => [
        '{set x = ["a" => "hello", "b" => "world"]}{x.a} {x.b}',
        'hello world',
    ],
    'arrow in string' => [
        '{set x = ["code" => "a => b"]}{x.code}',
        'a => b',
    ],
    'empty array' => [
        '{set x = []}OK',
        'OK',
    ],
    'simple indexed' => [
        '{set x = ["a", "b", "c"]}{x.0} {x.1} {x.2}',
        'a b c',
    ],
    'bool values' => [
        '{set x = ["active" => true, "gone" => false]}{if x.active}YES{/if} {if !x.gone}NO{/if}',
        'YES NO',
    ],
];

$passed = 0;
$failed = 0;

foreach ($tests as $name => [$template, $expected]) {
    try {
        $result = trim($engine->renderString($template, []));
        if ($result === $expected) {
            echo "  PASS: {$name}\n";
            $passed++;
        } else {
            echo "  FAIL: {$name} — got: {$result}\n";
            $failed++;
        }
    } catch (\Throwable $e) {
        echo "  ERROR: {$name} — {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
