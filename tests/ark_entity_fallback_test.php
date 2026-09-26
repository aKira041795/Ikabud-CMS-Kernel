<?php
/**
 * Can ARK present an entity type it has never seen?
 *
 * This is the theme half of the substrate claim. A theme must render any
 * entity the kernel hands it, without knowing that entity's type in advance -
 * otherwise every new content type needs a new template, and "no bespoke PHP
 * per content type" is false.
 *
 * ARK declares itself as the fallback for exactly this:
 *   "Generic fallback card for unknown entity types. Renders when no
 *    module-specific view contract is registered. Theme presents governed
 *    fields - module owns truth."
 *
 * Measured 2026-09-26: ARK's default-card renders a synthetic declared-type row
 * set (course-shaped) with ark-card markup and no hardcoded colours. This test
 * pins that, so a theme edit cannot silently break presentation of unknown
 * entities.
 *
 * Run: php tests/ark_entity_fallback_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  \u{2713} {$label}\n";
    } else {
        $fail++;
        echo "  \u{2717} {$label}" . ($detail !== '' ? "  -- {$detail}" : '') . "\n";
    }
}

$root      = dirname(__DIR__);
$viewsDir  = $root . '/storage/cms-themes/ark/entity-views';
$tmp       = sys_get_temp_dir() . '/ark-fallback-' . getmypid();
@mkdir($tmp, 0777, true);
$engine    = new TemplateEngine($root . '/templates', $tmp, false, false);

echo "\nARK presents entity types it has never seen\n\n";

t('the ARK theme is present', is_dir($viewsDir), $viewsDir);

$fallbacks = ['card' => 'default-card.disyl', 'detail' => 'default-detail.disyl',
              'compact' => 'default-compact.disyl', 'table' => 'default-table.disyl'];
foreach ($fallbacks as $name => $file) {
    t("fallback template exists: {$name}", is_file($viewsDir . '/' . $file), $file);
}

/** Render a fallback body, stripping the active-theme layout wrapper. */
function renderFallback(TemplateEngine $e, string $file, array $ctx): string
{
    $src = (string) @file_get_contents($file);
    if (preg_match('/\{block content\}(.*?)\{\/block\}/s', $src, $m)) {
        $src = $m[1];
    }
    return $e->renderString($src, $ctx);
}

// ── An entity shape ARK has never seen: `course` ────────────────────────────
$rows = [
    ['id' => 1, 'title' => 'Intro to Ikabud', 'excerpt' => 'A declared course.', 'status' => 'published'],
    ['id' => 2, 'name' => 'Governed DiSyL', 'description' => 'Second course.', 'status' => 'published'],
    ['id' => 3, 'label' => 'Sparse row', 'status' => 'draft'],
    ['id' => 4, 'status' => 'published'],                       // no title, name or label at all
];

$ctx  = ['rows' => $rows, 'entity_list_title' => 'Course', 'entity' => ['type' => 'course']];
$card = renderFallback($engine, $viewsDir . '/default-card.disyl', $ctx);

t('the card fallback produces output', $card !== '', strlen($card) . ' bytes');
t('it uses its own markup, not an empty shell', str_contains($card, 'ark-card'), 'no ark-card class found');
t('one element per row', substr_count($card, 'ark-card__title') === count($rows),
    substr_count($card, 'ark-card__title') . ' titles for ' . count($rows) . ' rows');
t('a row with only `name` still renders', str_contains($card, 'Governed DiSyL'));
t('a row with only `label` still renders', str_contains($card, 'Sparse row'));
t('a row with no title-like field falls back to a placeholder',
    str_contains($card, 'Untitled'), 'no placeholder emitted');
t('the list title is rendered', str_contains($card, 'Course'));

// ── Theme discipline: presentation comes from tokens, not literals ─────────
t('no hardcoded hex colours in the rendered card',
    preg_match('/#[0-9a-fA-F]{3,6}\b/', $card) !== 1,
    'found a literal colour; ARK declares token-driven styling');

// ── The other fallbacks must also render, not just the card ────────────────
foreach (['compact', 'table', 'detail'] as $kind) {
    $ctx2 = $ctx + ['row' => $rows[0], 'entity' => $rows[0]];
    $out  = renderFallback($engine, $viewsDir . '/default-' . $kind . '.disyl', $ctx2);
    t("the {$kind} fallback renders", $out !== '', strlen($out) . ' bytes');
}

echo "\n  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
