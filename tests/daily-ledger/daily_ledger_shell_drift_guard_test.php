<?php

declare(strict_types=1);

/**
 * Daily Ledger — shell drift guard.
 *
 * The two admin templates admin/commissary.disyl and admin/variances.disyl used to
 * inherit templates/modules/daily-ledger/layouts/app.disyl with `{extends }`. Because
 * TemplateEngine::templateGraphUsesComponentTags() sends every template containing
 * '{extends ' down the INTERPRETED renderer, both pages cost ~1.5 s of render on top of
 * the framework floor. Inlining the 713-line layout into each template moved them onto
 * the COMPILED path (commissary 2.221 s -> 0.475 s, variances 2.356 s -> 1.000 s).
 *
 * The cost of that fix: those two templates now hold independent COPIES of the shell. If
 * layouts/app.disyl later changes — a nav item, the favicon/manifest links, the
 * theme-color, the DL_WRITE_TIMEOUT_MS wiring, the branding — the two converted pages keep
 * the old shell silently. This suite fails the moment they drift.
 *
 * Design: the LAYOUT is the single source of truth. For every shared marker the expected
 * value is derived from layouts/app.disyl at run time and the two converted templates must
 * carry the identical line. Markers that legitimately differ are stated as an explicit
 * exception (the layout's {block} placeholders are inlined in the converted templates),
 * never by weakening the comparison. The suite also asserts the conversion's contract:
 * neither converted template contains '{extends ' or '{block ', which is what keeps them
 * compiled.
 *
 * Scope: Daily Ledger only. Pure source check — no bootstrap, no DB, no network.
 */

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-shell-drift-guard');

$base = $h->basePath();

$layoutRelative = 'templates/modules/daily-ledger/layouts/app.disyl';
$convertedRelative = [
    'commissary.disyl' => 'templates/modules/daily-ledger/admin/commissary.disyl',
    'variances.disyl'  => 'templates/modules/daily-ledger/admin/variances.disyl',
];

foreach (array_merge([$layoutRelative], array_values($convertedRelative)) as $relative) {
    $h->fingerprint($relative);
}

/**
 * Read a template or fail the whole suite loudly rather than comparing empty strings.
 */
function sgd_read(string $path): string
{
    $content = @file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Cannot read {$path}");
    }
    return $content;
}

/**
 * The trimmed first line containing $needle, or null when the marker is absent.
 */
function sgd_line(string $content, string $needle): ?string
{
    foreach (explode("\n", $content) as $line) {
        if (str_contains($line, $needle)) {
            return trim($line);
        }
    }
    return null;
}

/**
 * The substring from $start through the first $end at or after it, or null.
 */
function sgd_block(string $content, string $start, string $end): ?string
{
    $from = strpos($content, $start);
    if ($from === false) {
        return null;
    }
    $to = strpos($content, $end, $from);
    if ($to === false) {
        return null;
    }
    return substr($content, $from, ($to - $from) + strlen($end));
}

/**
 * A short human-readable first differing line, for a failed byte comparison.
 */
function sgd_first_diff(string $a, string $b): string
{
    $left = explode("\n", $a);
    $right = explode("\n", $b);
    $max = max(count($left), count($right));
    for ($i = 0; $i < $max; $i++) {
        $x = $left[$i] ?? null;
        $y = $right[$i] ?? null;
        if ($x !== $y) {
            return 'first diff at line ' . ($i + 1)
                . ': layout=' . substr((string) $x, 0, 110)
                . ' | template=' . substr((string) $y, 0, 110);
        }
    }
    return 'no line differs (whitespace/byte length only)';
}

$layout = sgd_read($base . '/' . $layoutRelative);
$converted = [];
foreach ($convertedRelative as $name => $relative) {
    $converted[$name] = sgd_read($base . '/' . $relative);
}

// ─── Shared shell markers ────────────────────────────────────────────────
//
// Every needle below was derived by reading the three real files (see the probe in the
// contract lane). Expected values are taken from the layout, so an edit to the layout
// turns the corresponding assertion red until both converted templates are updated too.
$lineMarkers = [
    // Document head essentials.
    'charset meta'                  => '<meta charset="UTF-8">',
    'viewport meta'                 => 'name="viewport"',
    'document title'                => '<title>',
    'favicon link'                  => 'rel="icon"',
    'web-app manifest link'         => 'rel="manifest"',
    'theme-color meta'              => 'name="theme-color"',
    // Head assets that shape every ledger page.
    'tailwind asset'                => '/daily-ledger/assets/tailwindcss.js',
    'fontawesome asset'             => '/daily-ledger/assets/fontawesome/all.min.css',
    'htmx asset'                    => '/daily-ledger/assets/htmx-1.9.10.min.js',
    'alpine asset'                  => '/daily-ledger/assets/alpine-3.min.js',
    // PWA wiring.
    'service-worker registration'   => "navigator.serviceWorker.register('/daily-ledger/sw.js')",
    'service-worker control ping'   => "type: 'DL_CONTROLLED'",
    // Shell globals.
    'DL_BASE global'                => "window.DL_BASE = '/daily-ledger';",
    'showToast global'              => 'window.showToast = function(message, type) {',
    // DL_WRITE_TIMEOUT_MS wiring (assignment + abort timer + message wording).
    'write-timeout value'           => 'window.DL_WRITE_TIMEOUT_MS =',
    'write-timeout abort wiring'    => 'controller.abort(); }, window.DL_WRITE_TIMEOUT_MS);',
    'write-timeout message wiring'  => 'window.DL_WRITE_TIMEOUT_MS / 1000',
    'bounded-write helper'          => 'window.dlWriteTimeout = function',
    // Shell nav / branding.
    'app-shell body marker'         => 'data-wb-component="app-shell"',
    'sidebar element'               => '<aside id="wb-sidebar"',
    'sidebar branding'              => 'tracking-tight" x-show="sidebarOpen" x-cloak>{app_name}</span>',
    'nav container'                 => '<nav class="mt-4 px-2 flex-1 overflow-y-auto">',
    'header logout link'            => 'href="/daily-ledger/logout"',
];

$h->section('Shared shell markers (layout is the source of truth)');
$h->detail('Comparing ' . count($lineMarkers) . ' shared markers against ' . $layoutRelative);
foreach ($lineMarkers as $label => $needle) {
    $expected = sgd_line($layout, $needle);
    $h->test("layout defines {$label}", $expected !== null, $expected ?? 'not found in layout');

    foreach ($converted as $name => $content) {
        $actual = sgd_line($content, $needle);
        $agrees = $expected !== null && $actual !== null && $actual === $expected;
        $detail = $agrees
            ? "matches layout: {$expected}"
            : 'layout=' . var_export($expected, true) . " vs {$name}=" . var_export($actual, true);
        $h->test("{$name} agrees with layout on {$label}", $agrees, $detail);
    }
}

// ─── Write-timeout wiring uses the marker everywhere ─────────────────────
$h->section('Write-timeout wiring consistency');
$layoutRefs = substr_count($layout, 'DL_WRITE_TIMEOUT_MS');
foreach ($converted as $name => $content) {
    $refs = substr_count($content, 'DL_WRITE_TIMEOUT_MS');
    $h->test(
        "{$name} references DL_WRITE_TIMEOUT_MS as many times as the layout",
        $refs === $layoutRefs,
        "layout={$layoutRefs} {$name}={$refs}"
    );
}

// ─── Whole shell blocks byte-for-byte ────────────────────────────────────
//
// The single-line markers above are readable but shallow. The sidebar and its nav are
// one contiguous copy in each converted template, so compare them whole: any edited nav
// item, label, href or icon changes the block and fails here.
$h->section('Shell structure (sidebar and nav compared byte-for-byte)');
$layoutAside = sgd_block($layout, '<aside id="wb-sidebar"', '</aside>');
$layoutNav = sgd_block($layout, '<nav class="mt-4', '</nav>');
$h->test('layout contains the sidebar shell block', $layoutAside !== null, $layoutAside === null ? 'not found' : 'found');
$h->test('layout contains the nav shell block', $layoutNav !== null, $layoutNav === null ? 'not found' : 'found');

foreach ($converted as $name => $content) {
    $aside = sgd_block($content, '<aside id="wb-sidebar"', '</aside>');
    $h->test(
        "{$name} sidebar shell is byte-identical to the layout",
        $aside !== null && $layoutAside !== null && $aside === $layoutAside,
        ($aside !== null && $layoutAside !== null) ? sgd_first_diff($layoutAside, $aside) : 'block not found'
    );

    $nav = sgd_block($content, '<nav class="mt-4', '</nav>');
    $h->test(
        "{$name} nav shell is byte-identical to the layout",
        $nav !== null && $layoutNav !== null && $nav === $layoutNav,
        ($nav !== null && $layoutNav !== null) ? sgd_first_diff($layoutNav, $nav) : 'block not found'
    );
}

// ─── Documented inlining exception + compiled-path contract ──────────────
//
// Exception: the layout keeps {block} placeholders for the page body/head/scripts; the
// two converted templates INLINE that body instead, because inheriting is exactly what
// put them on the interpreted renderer. So the placeholders must exist in the layout and
// must be ABSENT from the converted templates — that absence is the conversion, not drift.
$h->section('Documented inlining exception and compiled-path contract');
$blockPlaceholders = ['{block head}', '{block header_shift}', '{block content}', '{block scripts}'];
foreach ($blockPlaceholders as $placeholder) {
    $h->test("layout still declares {$placeholder}", str_contains($layout, $placeholder));
    foreach ($converted as $name => $content) {
        $h->test("{$name} inlines the shell (no {$placeholder})", !str_contains($content, $placeholder));
    }
}

foreach ($converted as $name => $content) {
    $h->test("{$name} contains no '{block ' placeholder", !str_contains($content, '{block '));
    $h->test(
        "{$name} contains no '{extends ' (stays on the compiled renderer)",
        !str_contains($content, '{extends ')
    );
}

// ─── Coupling comment ────────────────────────────────────────────────────
$h->section('Coupling comment names the layout');
foreach ($converted as $name => $content) {
    $firstLine = strtok($content, "\n") ?: '';
    $h->test(
        "{$name} first line is a coupling comment naming layouts/app.disyl",
        str_contains($firstLine, 'layouts/app.disyl') && str_contains($firstLine, 'COUPLING'),
        $firstLine
    );
}

$h->done();
