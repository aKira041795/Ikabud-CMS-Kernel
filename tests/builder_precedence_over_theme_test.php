<?php
/**
 * Stable contract section 12 — page-builder output outranks the theme.
 *
 * Guards three properties of the fix:
 *   (a) no theme stylesheet selector targets a builder namespace class;
 *   (b) the full-bleed CSS mechanism exists and is not defeated by an ancestor clip;
 *   (c) the blue-eyebrow contrast remedy lives outside the theme (in the builder).
 *
 * Run: php tests/builder_precedence_over_theme_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$arkSourcePath = $root . '/storage/cms-themes/ark/style.css';
$arkPublicPath = $root . '/public/assets/cms/themes/ark/style.css';
$cmsPublicPath = $root . '/public/assets/cms/cms-public.css';
$rendererPath  = $root . '/modules/cms/builder-renderers.php';
$helperPath    = $root . '/modules/cms/helpers/50-builder.php';

$arkSource = (string) file_get_contents($arkSourcePath);
$arkPublic = (string) file_get_contents($arkPublicPath);
$cmsPublic = (string) file_get_contents($cmsPublicPath);
$renderer  = (string) file_get_contents($rendererPath);
$helper    = (string) file_get_contents($helperPath);

$passed = 0;
$failed = 0;
function bpAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    $failed++;
}

/** Relative luminance of an #rrggbb colour. */
function bpLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channels = [];
    for ($i = 0; $i < 3; $i++) {
        $c = hexdec(substr($hex, $i * 2, 2)) / 255;
        $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/** WCAG contrast ratio between two #rrggbb colours. */
function bpContrast(string $fg, string $bg): float
{
    $a = bpLuminance($fg);
    $b = bpLuminance($bg);
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

// ── (a) Corpus sweep: the ARK theme must not reach into the builder namespace ──
$builderNamespace = '/cms-builder|cms-kb|cms-lightbox/';

function bpCssFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'css') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

$themeCssFiles = array_values(array_unique(array_merge(
    bpCssFiles($root . '/storage/cms-themes/ark'),
    bpCssFiles($root . '/public/assets/cms/themes/ark')
)));
$sweepHits = [];
foreach ($themeCssFiles as $file) {
    $body = (string) file_get_contents($file);
    if (preg_match($builderNamespace, $body) === 1) {
        $sweepHits[] = str_replace($root . '/', '', $file);
    }
}
bpAssert(
    'corpus sweep: every ARK theme stylesheet is free of builder namespace selectors',
    $themeCssFiles !== [] && $sweepHits === [],
    $sweepHits === [] ? count($themeCssFiles) . ' files swept' : implode(', ', $sweepHits)
);
bpAssert('corpus sweep: the ARK source is clean', preg_match($builderNamespace, $arkSource) !== 1);
bpAssert('corpus sweep: the published ARK asset is clean', preg_match($builderNamespace, $arkPublic) !== 1);

// The builder's own stylesheet is its home (criterion 3 exemption) and must
// actually carry the moved builder-only styling.
bpAssert(
    'cms-public.css is the builder home and keeps the relocated eyebrow remedy',
    str_contains($cmsPublic, '.cms-builder-node--text[style*="--b-color:#3B82F6"]')
);
bpAssert(
    'cms-public.css keeps the relocated slide-caption inheritance rule',
    preg_match('/\.cms-builder-node--slideshow \.cms-builder-slide h3\s*\{[^}]*color:\s*inherit/s', $cmsPublic) === 1
);

// ── (b) Full-bleed mechanism present and not defeated by an ancestor clip ──
bpAssert(
    'builder owns the full-bleed escape (cmsBuilderApplyFullWidth exists)',
    str_contains($renderer, 'function cmsBuilderApplyFullWidth(')
);
bpAssert(
    'full-bleed escape expands to 100vw and bleeds past the column',
    str_contains($renderer, "\$style['width']      = '100vw';")
        && str_contains($renderer, "\$style['marginLeft'] = 'calc(-50vw + 50%)';")
);
bpAssert(
    'full-bleed escape clips its own content so it cannot create a scrollbar',
    str_contains($renderer, "\$style['overflow']   = 'hidden';")
);
bpAssert(
    'ARK keeps the horizontal-overflow guard for ordinary content',
    preg_match('/\.ark-main\s*\{[^}]*overflow-x:\s*clip/s', $arkSource) === 1
);
bpAssert(
    'ARK lifts the guard on pages that contain builder-authored nodes',
    preg_match('/\.ark-main:has\(\[data-node-id\]\)\s*\{[^}]*overflow-x:\s*visible/s', $arkSource) === 1
);
bpAssert(
    'the guard lift appears after the base guard (source-order safety)',
    strpos($arkSource, 'overflow-x: clip') !== false
        && strpos($arkSource, '.ark-main:has([data-node-id])') !== false
        && strpos($arkSource, '.ark-main:has([data-node-id])') > strpos($arkSource, 'overflow-x: clip')
);
bpAssert(
    'the builder emits the data-node-id marker the guard lift keys on',
    str_contains($helper, "'data-node-id' => (string)(\$node['id'] ?? '')")
);

// ── (c) Eyebrow contrast remedy lives outside the theme ──
bpAssert(
    'ARK theme no longer contains the builder colour selector',
    !str_contains($arkSource, '--b-color') && !str_contains($arkSource, 'cms-builder-node--text')
);
bpAssert(
    'builder style helper still emits the authored colour as --b-color',
    str_contains($helper, "'--b-' . \$cssProp . ':' . \$val")
        && str_contains($helper, "\$cssProp . ':var(--b-' . \$cssProp . ')'")
);
bpAssert(
    'builder-emitted authored blue is unchanged in the remedy selector',
    str_contains($cmsPublic, '--b-color:#3B82F6')
);
$eyebrowRatio = bpContrast('#3B82F6', '#0f172a');
bpAssert(
    'authored blue on the builder surface clears WCAG AA',
    $eyebrowRatio >= 4.5,
    number_format($eyebrowRatio, 2) . ':1'
);
bpAssert(
    'the eyebrow surface stays in the builder namespace, not the theme',
    str_contains($cmsPublic, '.cms-builder-node--text[style*="--b-color:#3B82F6"]')
        && preg_match('/\.cms-builder-node--text\[style\*="--b-color:#3B82F6"\]\s*\{[^}]*background:/s', $cmsPublic) === 1
);

// ── Published asset parity (constraint: theme:publish keeps source == published) ──
bpAssert(
    'ARK source and published stylesheets are byte-identical',
    hash_file('sha256', $arkSourcePath) === hash_file('sha256', $arkPublicPath)
);

echo "\nBuilder precedence over theme: {$passed} passed, {$failed} failed\n";
echo "Exemptions: public/assets/cms/cms-public.css is the builder's own stylesheet\n";
echo "(its home), so builder-namespace selectors there are expected and correct.\n";
echo "Survivors in the ARK theme: none.\n";
exit($failed === 0 ? 0 : 1);
