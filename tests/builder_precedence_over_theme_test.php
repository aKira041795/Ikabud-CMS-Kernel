<?php
/**
 * Stable contract §12 — page-builder output outranks the theme.
 *
 * REWRITTEN 2026-10-10. The previous version encoded the mechanism the
 * 2026-10-07 ARK fix replaced: a literal `width:100vw` escape and a
 * `.ark-main:has([data-node-id]) { overflow-x: visible }` guard-lift. Six of
 * its assertions therefore failed against correct code, which is worse than no
 * guard — a red guard for a wrong reason trains people to ignore it.
 *
 * Per the chair consult of 2026-10-10, the acceptance contract is the
 * OWNERSHIP BOUNDARY and its observable consequences, not the presence of a
 * particular selector or declaration:
 *
 *   (a) the theme never restyles builder output — it may only DETECT that a
 *       builder document is present, from one of its own elements (§12 table:
 *       "the theme may style around them, never restyle them");
 *   (b) the section gutter belongs to the builder, and a theme expresses its
 *       gutter as a design token rather than by targeting a node class;
 *   (c) the full-bleed escape is theme-overridable and scrollbar-safe, the
 *       overflow guard is retained, and it is NOT disabled for builder pages
 *       (disabling it was what let off-canvas carousel tracks create a real
 *       horizontal scrollbar);
 *   (d) the builder's own stylesheet actually reaches the page, because a
 *       remedy that lives in a stylesheet nobody loads fixes nothing;
 *   (e) the eyebrow contrast remedy lives in the builder namespace and clears
 *       WCAG AA;
 *   (f) the ARK source and published copies stay byte-identical.
 *
 * Run: php tests/builder_precedence_over_theme_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$arkSourcePath  = $root . '/storage/cms-themes/ark/style.css';
$arkPublicPath  = $root . '/public/assets/cms/themes/ark/style.css';
$cmsPublicPath  = $root . '/public/assets/cms/cms-public.css';
$rendererPath   = $root . '/modules/cms/builder-renderers.php';
$helperPath     = $root . '/modules/cms/helpers/50-builder.php';
$arkLayoutPath  = $root . '/storage/cms-themes/ark/layouts/public.disyl';
$cmsLayoutPath  = $root . '/templates/modules/cms/layouts/public.disyl';

foreach ([
    $arkSourcePath, $arkPublicPath, $cmsPublicPath, $rendererPath,
    $helperPath, $arkLayoutPath, $cmsLayoutPath,
] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "MISSING REQUIRED FILE: " . str_replace($root . '/', '', $required) . "\n");
        exit(1);
    }
}

$arkSource = (string) file_get_contents($arkSourcePath);
$arkPublic = (string) file_get_contents($arkPublicPath);
$cmsPublic = (string) file_get_contents($cmsPublicPath);
$renderer  = (string) file_get_contents($rendererPath);
$helper    = (string) file_get_contents($helperPath);
$arkLayout = (string) file_get_contents($arkLayoutPath);
$cmsLayout = (string) file_get_contents($cmsLayoutPath);

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

/**
 * Every selector in a stylesheet, split on commas. Comments are stripped first.
 * At-rule preludes (`@media (…)`) are dropped; the rules INSIDE an at-rule are
 * still collected, because each has its own `{`.
 */
function bpSelectors(string $css): array
{
    $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? '';
    preg_match_all('/([^{}]+)\{/', $css, $m);
    $out = [];
    foreach ($m[1] as $prelude) {
        $prelude = trim($prelude);
        if ($prelude === '' || $prelude[0] === '@') {
            continue;
        }
        foreach (explode(',', $prelude) as $one) {
            $one = trim($one);
            if ($one !== '') {
                $out[] = $one;
            }
        }
    }
    return $out;
}

/** The declarations inside the first rule whose text starts at $needle. */
function bpRuleBody(string $css, string $needle): ?string
{
    $stripped = preg_replace('!/\*.*?\*/!s', '', $css) ?? '';
    $pos = strpos($stripped, $needle);
    if ($pos === false) {
        return null;
    }
    $open = strpos($stripped, '{', $pos);
    if ($open === false) {
        return null;
    }
    $close = strpos($stripped, '}', $open);
    if ($close === false) {
        return null;
    }
    return substr($stripped, $open + 1, $close - $open - 1);
}

$builderMarkers = '/cms-builder|cms-kb|cms-lightbox/';
$nodeClasses    = '/cms-builder-node|cms-kb|cms-lightbox/';

// ── (a) The theme styles AROUND builder output, never restyles it ────────
$arkSelectors = bpSelectors($arkSource);

$nodeTargets = array_values(array_filter(
    $arkSelectors,
    fn(string $sel): bool => preg_match($nodeClasses, $sel) === 1
));
bpAssert(
    'ARK does not target any builder node class',
    $nodeTargets === [],
    $nodeTargets === [] ? count($arkSelectors) . ' selectors swept, no node targets' : implode(' | ', $nodeTargets)
);

$builderRefs = array_values(array_filter(
    $arkSelectors,
    fn(string $sel): bool => preg_match($builderMarkers, $sel) === 1
));
$badRefs = array_values(array_filter($builderRefs, function (string $sel) use ($builderMarkers): bool {
    // Legitimate form: the theme detects builder presence from one of its OWN
    // elements — `.ark-…:has(.cms-builder-document)…` — and everything that
    // remains once the detector is removed is still theme-owned. Anything else
    // is the theme styling builder output.
    if (str_starts_with($sel, '.ark-') === false || str_contains($sel, ':has(') === false) {
        return true;
    }
    $outsideDetectors = trim((string) preg_replace('/:has\((?:[^()]|\([^()]*\))*\)/', '', $sel));
    if (preg_match($builderMarkers, $outsideDetectors) === 1) {
        return true; // a builder class survives outside the :has() group
    }
    // `.ark-main:has(.cms-builder-document) p` would otherwise pass: it detects
    // the document and then styles a bare descendant inside builder output.
    // Every compound left must still be theme-owned.
    foreach (preg_split('/[\s>+~]+/', $outsideDetectors) ?: [] as $compound) {
        if ($compound !== '' && str_starts_with($compound, '.ark-') === false) {
            return true;
        }
    }
    return false;
}));
bpAssert(
    'ARK refers to builder classes only as a :has() detector on its own elements',
    $badRefs === [],
    $badRefs === [] ? count($builderRefs) . ' references, all detector-shaped' : implode(' | ', $badRefs)
);

bpAssert(
    'ARK no longer carries the builder-authored colour selector',
    !str_contains($arkSource, '--b-color') && !str_contains($arkSource, 'cms-builder-node--text')
);

// The same rule across EVERY stylesheet the theme ships, not just the one file
// the assertions above read — a second ARK stylesheet must not escape the guard.
$themeCssFiles = [];
$arkThemeDir = dirname($arkSourcePath);
if (is_dir($arkThemeDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($arkThemeDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $candidate) {
        if ($candidate->isFile() && strtolower($candidate->getExtension()) === 'css') {
            $themeCssFiles[] = $candidate->getPathname();
        }
    }
    sort($themeCssFiles);
}
$sweepHits = [];
foreach ($themeCssFiles as $cssFile) {
    foreach (bpSelectors((string) file_get_contents($cssFile)) as $sel) {
        if (preg_match($nodeClasses, $sel) === 1) {
            $sweepHits[] = str_replace($root . '/', '', $cssFile) . ' :: ' . $sel;
        }
    }
}
bpAssert(
    'no ARK stylesheet targets a builder node class',
    $themeCssFiles !== [] && $sweepHits === [],
    $themeCssFiles === []
        ? 'no stylesheets found under ' . str_replace($root . '/', '', $arkThemeDir)
        : implode(' | ', $sweepHits)
);

// ── (b) The section gutter is builder-owned and token-driven ─────────────
bpAssert(
    'the builder owns the section gutter, resolved from a theme-declared token',
    preg_match('/\.cms-builder-document\s*>\s*\.cms-builder-node--section\s*\{[^}]*max-width:\s*var\(--ikb-content-max-width/s', $cmsPublic) === 1
);
bpAssert(
    'the builder releases the gutter for a section carrying a full-bleed node',
    preg_match('/\.cms-builder-document\s*>\s*\.cms-builder-node--section:has\(\[style\*="--ikb-fullbleed-width"\]\)/s', $cmsPublic) === 1
);
// The self form is the one that was missing: a section can be full-bleed ITSELF,
// which :has() cannot match, and matching only the descendant form capped an
// author's full-bleed band at the theme gutter (found by the browser parity gate).
bpAssert(
    'the builder releases the gutter for a section that is itself full-bleed',
    preg_match('/\.cms-builder-node--section\[style\*="--ikb-fullbleed-width"\]\s*\{[^}]*max-width:\s*none/s', $cmsPublic) === 1
);
$arkMainBody = bpRuleBody($arkSource, "\n.ark-main {") ?? '';
bpAssert(
    'ARK publishes its gutter as --ikb-content-max-width on its own shell element',
    preg_match('/--ikb-content-max-width:\s*var\(--ark-max-width/s', $arkMainBody) === 1,
    $arkMainBody === '' ? 'could not read the .ark-main rule' : 'declaration absent'
);

// ── (c) Full-bleed: theme-overridable, scrollbar-safe, guard retained ────
bpAssert(
    'the builder owns the full-bleed escape (cmsBuilderApplyFullWidth exists)',
    str_contains($renderer, 'function cmsBuilderApplyFullWidth(')
);
bpAssert(
    'the full-bleed escape is expressed as theme-overridable custom properties',
    str_contains($renderer, 'var(--ikb-fullbleed-width, 100vw)')
        && str_contains($renderer, 'var(--ikb-fullbleed-margin-left, calc(-50vw + 50%))')
);
bpAssert(
    'the full-bleed escape clips its own content so it cannot create a scrollbar',
    str_contains($renderer, "\$style['overflow']   = 'hidden';")
);
bpAssert(
    'ARK resolves the full-bleed properties on its own shell element',
    preg_match('/--ikb-fullbleed-width:\s*100%/s', $arkMainBody) === 1
        && preg_match('/--ikb-fullbleed-margin-left:\s*0/s', $arkMainBody) === 1
);
bpAssert(
    'ARK keeps the horizontal-overflow guard for ordinary content',
    preg_match('/\.ark-main\s*\{[^}]*overflow-x:\s*clip/s', $arkSource) === 1
);
// The superseded mechanism lifted this guard for builder pages. The guard is
// retained; a builder breakout is expressed in the builder's own units.
$guardLifts = array_values(array_filter($builderRefs, function (string $sel) use ($arkSource): bool {
    $body = bpRuleBody($arkSource, $sel);
    return $body !== null && preg_match('/overflow-x:\s*(?!clip)\w+/', $body) === 1;
}));
bpAssert(
    'ARK does not disable its overflow guard on builder pages',
    $guardLifts === [],
    $guardLifts === [] ? 'guard stays in force' : implode(' | ', $guardLifts)
);

// ── (d) The builder stylesheet actually reaches the page ─────────────────
// A real <link>, not a mention. Both layouts discuss cms_public_css_url in
// comments, so a plain str_contains() would still pass with the stylesheet tag
// deleted — a check that cannot fail is not a check.
$linksBuilderCss = static function (string $layout): bool {
    foreach (explode("\n", $layout) as $line) {
        if (str_contains($line, '<link') && str_contains($line, 'cms_public_css_url')) {
            return true;
        }
    }
    return false;
};
bpAssert('the ARK theme layout links the builder stylesheet', $linksBuilderCss($arkLayout));
bpAssert(
    'the fallback CMS public layout links the builder stylesheet',
    $linksBuilderCss($cmsLayout),
    'a remedy in a stylesheet nobody links fixes nothing'
);

// ── (e) The eyebrow contrast remedy lives in the builder, and clears AA ──
bpAssert(
    'cms-public.css carries the relocated eyebrow remedy',
    str_contains($cmsPublic, '.cms-builder-node--text[style*="--b-color:#3B82F6"]')
);
bpAssert(
    'cms-public.css keeps the relocated slide-caption inheritance rule',
    preg_match('/\.cms-builder-node--slideshow \.cms-builder-slide h3\s*\{[^}]*color:\s*inherit/s', $cmsPublic) === 1
);
bpAssert(
    'builder style helper still emits the authored colour as --b-color',
    str_contains($helper, "'--b-' . \$cssProp . ':' . \$val")
        && str_contains($helper, "\$cssProp . ':var(--b-' . \$cssProp . ')'")
);
bpAssert(
    'the builder emits the node-id marker the editor keys on',
    str_contains($helper, "'data-node-id' => (string)(\$node['id'] ?? '')")
);
$eyebrowRatio = bpContrast('#3B82F6', '#0f172a');
bpAssert(
    'authored blue on the builder surface clears WCAG AA',
    $eyebrowRatio >= 4.5,
    number_format($eyebrowRatio, 2) . ':1'
);
bpAssert(
    'the eyebrow surface stays in the builder namespace, not the theme',
    preg_match('/\.cms-builder-node--text\[style\*="--b-color:#3B82F6"\]\s*\{[^}]*background:/s', $cmsPublic) === 1
);

// ── (f) Source / published parity ────────────────────────────────────────
bpAssert(
    'ARK source and published stylesheets are byte-identical',
    hash_file('sha256', $arkSourcePath) === hash_file('sha256', $arkPublicPath)
);

echo "\nBuilder precedence over theme: {$passed} passed, {$failed} failed\n";
echo "Contract asserted: ownership (§12), token-driven gutter, theme-overridable\n";
echo "full-bleed with the overflow guard retained, stylesheet delivery, and\n";
echo "published-asset parity. Visual parity needs a browser harness (see\n";
echo "tests/browser/) and is deliberately not claimed here.\n";
exit($failed === 0 ? 0 : 1);
