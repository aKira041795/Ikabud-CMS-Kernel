<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0;
$fail = 0;

function check(string $desc, bool $condition, string $details = ''): void
{
    global $pass, $fail;

    if ($condition) {
        echo "  ✓ {$desc}\n";
        $pass++;
        return;
    }

    echo "  ✗ {$desc}\n";
    if ($details !== '') {
        echo "    {$details}\n";
    }
    $fail++;
}

echo "DiSyL compiled component fallback test\n";
echo "=====================================\n\n";

$tmpDir = sys_get_temp_dir() . '/disyl_component_fallback_' . getmypid();
$cacheDir = $tmpDir . '/cache';
@mkdir($tmpDir, 0755, true);
@mkdir($cacheDir, 0755, true);

file_put_contents($tmpDir . '/layout.disyl', <<<'DISYL'
{ikb_section padding="none"}
<main>
    {block content}Default{/block}
</main>
{/ikb_section}
DISYL);

file_put_contents($tmpDir . '/status-badge.disyl', <<<'DISYL'
{ikb_badge variant="{case.status}"}{case.status | title}{/ikb_badge}
DISYL);

file_put_contents($tmpDir . '/page.disyl', <<<'DISYL'
{extends "layout"}

{block content}
Dashboard {include "status-badge"}
{/block}
DISYL);

$engine = new TemplateEngine($tmpDir, $cacheDir, false);
$engine->enableCompiledMode(true);

$html = $engine->render('page', [
    'case' => ['status' => 'open'],
]);

check(
    'compiled mode stays enabled in the engine',
    $engine->isCompiledMode(),
    'compiled mode was unavailable, so the regression path was not exercised'
);

check(
    'component-based template renders section markup instead of raw ikb_section text',
    str_contains($html, '<section') && !str_contains($html, 'ikb_section'),
    $html
);

check(
    'included component renders badge markup instead of raw ikb_badge text',
    str_contains($html, '<span') && str_contains($html, 'Open') && !str_contains($html, 'ikb_badge'),
    $html
);

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);