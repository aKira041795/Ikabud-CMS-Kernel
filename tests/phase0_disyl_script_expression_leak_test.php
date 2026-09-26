<?php
/**
 * Phase 0 — interpreted <script>/<style> unresolved-expression handling.
 *
 * The interpreted script/style path used to emit an unresolved single-word
 * {token} verbatim into JavaScript. The compiled path emits nothing for an
 * unresolved expression; the interpreter must not leak the raw template token.
 *
 * Also pins the safety valve for JS that merely looks like a template
 * arithmetic expression (a hyphen inside a string, e.g. 'sel-exp-cat'):
 * such code must survive untouched.
 *
 * Run: php tests/phase0_disyl_script_expression_leak_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}\n";
    }
}

$tmp = sys_get_temp_dir() . '/phase0_disyl_leak_' . getmypid();
@mkdir($tmp . '/templates', 0755, true);
@mkdir($tmp . '/cache', 0755, true);
$engine = new TemplateEngine($tmp . '/templates', $tmp . '/cache');

echo "=== Phase 0: script/style expression leak ===\n";

$missing = $engine->renderString('<script>var x = {missing_var};</script>', []);
t('bare unresolved script token is not emitted verbatim', !str_contains($missing, '{missing_var}'));
t('bare unresolved script token becomes empty', $missing === '<script>var x = ;</script>');

$missingFiltered = $engine->renderString('<script>var x = "{missing_var|upper}";</script>', []);
t('filtered unresolved script token is not emitted verbatim', !str_contains($missingFiltered, '{missing_var'));

$missingStyle = $engine->renderString('<style>.x{content:"{missing_var}"}</style>', []);
t('unresolved style token is not emitted verbatim', !str_contains($missingStyle, '{missing_var}'));

$ordinary = $engine->renderString("<script>var n='{name}';</script>", ['name' => "O'Brien"]);
t('ordinary apostrophe value preserved', $ordinary === "<script>var n='O'Brien';</script>");

$ordinary2 = $engine->renderString("<script>var n='{name}';</script>", ['name' => 'Juanita']);
t('ordinary value preserved', $ordinary2 === "<script>var n='Juanita';</script>");

$jsDash = $engine->renderString(
    "<script>document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('sel-exp-cat');if(e)makeCreatable(e);});</script>",
    []
);
t('JS containing a hyphen is preserved (arithmetic safety valve)', str_contains($jsDash, "sel-exp-cat"));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
