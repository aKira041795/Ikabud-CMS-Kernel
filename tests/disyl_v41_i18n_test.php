<?php

declare(strict_types=1);

/**
 * DiSyL 4.1 — i18n ({trans}/{plural}/{context}) tests.
 *
 * Verifies the new {trans} control structure and the Catalog runtime
 * shipped in kernel 4.1.
 */

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\DiSyL\i18n\Catalog;

$tmpDir = sys_get_temp_dir() . '/disyl-v41-i18n-' . uniqid('', true);
@mkdir($tmpDir . '/cache', 0777, true);
@mkdir($tmpDir . '/i18n/tenant-a', 0777, true);

// Seed catalogs.
file_put_contents(
    $tmpDir . '/i18n/en.json',
    json_encode([
        'cart.empty' => ['value' => 'Your cart is empty.'],
        'cart.items' => [
            'plural' => [
                'one'   => '1 item',
                'other' => '%(count)s items',
            ],
        ],
        'product.title:shop_grid' => ['value' => 'Shop card: %(name)s'],
        'product.title:detail'    => ['value' => 'Detail: %(name)s'],
        'greet'                   => ['value' => 'Hello %(name)s!'],
    ], JSON_PRETTY_PRINT)
);
file_put_contents(
    $tmpDir . '/i18n/es.json',
    json_encode([
        'cart.empty' => ['value' => 'Tu carrito está vacío.'],
        'cart.items' => [
            'plural' => [
                'one'   => '1 artículo',
                'other' => '%(count)s artículos',
            ],
        ],
        'greet' => ['value' => '¡Hola %(name)s!'],
    ], JSON_PRETTY_PRINT)
);
file_put_contents(
    $tmpDir . '/i18n/tenant-a/en.json',
    json_encode([
        'cart.empty' => ['value' => 'Your basket is empty (tenant A).'],
    ], JSON_PRETTY_PRINT)
);

Catalog::resetForTests();

$engine = new TemplateEngine($tmpDir, $tmpDir . '/cache', false);

$pass = 0;
$fail = 0;
$failures = [];

function assert_render(
    TemplateEngine $engine,
    string $label,
    string $template,
    array $context,
    string $expected
): void {
    global $pass, $fail, $failures;
    $actual = trim($engine->renderString($template, $context));
    $expected = trim($expected);
    if ($actual === $expected) {
        $pass++;
        echo "  PASS: {$label}\n";
        return;
    }
    $fail++;
    $failures[] = $label;
    echo "  FAIL: {$label}\n";
    echo "    expected: " . var_export($expected, true) . "\n";
    echo "    actual:   " . var_export($actual, true) . "\n";
}

$baseCtx = ['_i18n_root' => $tmpDir . '/i18n'];

// 1. Static key resolves from catalog (en)
assert_render(
    $engine,
    '1. static key resolves',
    "{trans 'cart.empty'}Your cart is empty.{/trans}",
    $baseCtx + ['_locale' => 'en'],
    'Your cart is empty.'
);

// 2. Locale switch (en → es)
assert_render(
    $engine,
    '2. locale switch flips translation',
    "{trans 'cart.empty'}Your cart is empty.{/trans}",
    $baseCtx + ['_locale' => 'es'],
    'Tu carrito está vacío.'
);

// 3. Missing key falls back to inline body
assert_render(
    $engine,
    '3. missing key falls back to body',
    "{trans 'unknown.key'}Inline default.{/trans}",
    $baseCtx + ['_locale' => 'en'],
    'Inline default.'
);

// 4. Variable interpolation %(name)s from context
assert_render(
    $engine,
    '4. interpolation roundtrips %(name)s',
    "{trans 'greet'}Hello {name}!{/trans}",
    $baseCtx + ['_locale' => 'en', 'name' => 'Alice'],
    'Hello Alice!'
);

// 5. Plural: one
assert_render(
    $engine,
    '5a. plural one',
    "{trans 'cart.items' plural=count}{when one}1 item{when other}{count} items{/trans}",
    $baseCtx + ['_locale' => 'en', 'count' => 1],
    '1 item'
);
// 5b. Plural: other
assert_render(
    $engine,
    '5b. plural other',
    "{trans 'cart.items' plural=count}{when one}1 item{when other}{count} items{/trans}",
    $baseCtx + ['_locale' => 'en', 'count' => 5],
    '5 items'
);

// 6. Plural fallback to inline {when} body when key missing
assert_render(
    $engine,
    '6. plural fallback to inline arms',
    "{trans 'unknown.plural' plural=count}{when one}1 thing{when other}{count} things{/trans}",
    $baseCtx + ['_locale' => 'en', 'count' => 3],
    '3 things'
);

// 7. Context disambiguation: same key, different surfaces
assert_render(
    $engine,
    '7a. context shop_grid',
    "{trans 'product.title' context='shop_grid'}{name}{/trans}",
    $baseCtx + ['_locale' => 'en', 'name' => 'Widget'],
    'Shop card: Widget'
);
assert_render(
    $engine,
    '7b. context detail',
    "{trans 'product.title' context='detail'}{name}{/trans}",
    $baseCtx + ['_locale' => 'en', 'name' => 'Widget'],
    'Detail: Widget'
);

// 8. Tenant override beats global
assert_render(
    $engine,
    '8. tenant override beats global',
    "{trans 'cart.empty'}fallback{/trans}",
    $baseCtx + ['_locale' => 'en', '_tenant_id' => 'tenant-a'],
    'Your basket is empty (tenant A).'
);

// 9. Spanish locale + plural
assert_render(
    $engine,
    '9. plural in es locale',
    "{trans 'cart.items' plural=count}{when one}1 item{when other}{count} items{/trans}",
    $baseCtx + ['_locale' => 'es', 'count' => 7],
    '7 artículos'
);

// 10. Unknown locale falls back to body
assert_render(
    $engine,
    '10. unknown locale falls back to body',
    "{trans 'cart.empty'}Inline en text.{/trans}",
    $baseCtx + ['_locale' => 'fr'],
    'Inline en text.'
);

echo "\n  PASS: {$pass}  FAIL: {$fail}\n";

if ($fail > 0) {
    echo "  FAILURES:\n";
    foreach ($failures as $f) {
        echo "    - {$f}\n";
    }
    exit(1);
}
exit(0);
