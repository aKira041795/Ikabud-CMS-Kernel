<?php
/**
 * Theme template alias resolution must be deterministic, tenant-aware, and
 * independent of the global `_cms_active_theme` symlink.
 *
 * THE DEFECTS THIS GUARDS (measured 2026-10-10):
 *
 * 1. `cmsResolveThemeTemplateAliasPath()` ended with
 *    `return CMS_THEME_SYMLINK . '/' . $relativePath;`. That link is a SINGLE
 *    GLOBAL path, so when the active theme lacked a referenced file the fallback
 *    could supply ANOTHER theme's markup - and in a multi-tenant install, another
 *    tenant's, since one tenant activating a theme repoints the link for everyone.
 *
 * 2. Because that link was on the render path, a stale target silently changed what
 *    rendered. It was stale in practice: the link pointed at `entity-commerce-poc`
 *    while `active_theme` was `ark`, because `tests/cms_theme_test.php` activates
 *    themes as part of its own assertions and leaves the link where it stopped.
 *
 * 3. The public page-cache fingerprint read the template mtime THROUGH the alias,
 *    so editing the active theme's template never changed the cache key and the
 *    cached page was served indefinitely.
 *
 * The CMS now owns the semantic presentation (`content_presentation`:
 * composition | article) and the theme owns how each presentation looks. The theme
 * must branch on the semantic property, not on `builder_enabled` truthiness, and a
 * comparison is used deliberately because a BARE DiSyL test on an absent variable is
 * truthy - which would make every page a composition.
 *
 * Run: php tests/theme_alias_resolution_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
require_once BASE_PATH . '/modules/cms/helpers.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function tarAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? "\n      {$detail}" : '') . "\n";
    $failed++;
}

echo "=== THEME TEMPLATE ALIAS RESOLUTION ===\n\n";

$active = function_exists('cmsActiveTheme') ? (string)cmsActiveTheme() : '';
$themesPath = function_exists('cmsThemesPath') ? (string)cmsThemesPath() : BASE_PATH . '/storage/cms-themes';
$moduleDefaultDir = BASE_PATH . '/templates/modules/cms';

tarAssert('an active theme is configured', $active !== '', "active theme: '{$active}'");
tarAssert('active theme directory exists', is_dir($themesPath . '/' . $active), $themesPath . '/' . $active);
tarAssert('cmsResolveThemeTemplateAliasPath() is available', function_exists('cmsResolveThemeTemplateAliasPath'));

// ── Gate 1: never resolve through another theme ────────────────────────────────
echo "\n--- GATE 1: an unresolvable alias fails, it does not borrow another theme ---\n";

$missing = cmsResolveThemeTemplateAliasPath('_cms_active_theme/public/__no_such_template__.disyl');
tarAssert('unresolvable alias returns empty', $missing === '', "got: '{$missing}'");
tarAssert(
    'unresolvable alias does not return a path inside any theme directory',
    $missing === '' || !str_contains($missing, $themesPath),
    "got: '{$missing}'"
);

// Sweep every alias reference made by EVERY installed theme. Resolution is asserted
// against the theme that is active right now, so the invariant is: a reference resolves
// to the ACTIVE theme's own file, to the CMS module default, or to nothing - never to a
// different theme's file.
//
// NOTE: with no tenant context (CLI) cmsRuntimeTenantId() is 0 and cmsActiveTheme() is a
// default rather than the tenant's choice, which is why this sweep is written to be
// correct for whichever theme is active instead of assuming one.
$refsByTheme = [];
foreach (scandir($themesPath) ?: [] as $themeEntry) {
    if ($themeEntry === '.' || $themeEntry === '..' || !is_dir($themesPath . '/' . $themeEntry)) {
        continue;
    }
    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($themesPath . '/' . $themeEntry, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($dir as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.disyl')) {
            continue;
        }
        $src = (string)file_get_contents($file->getPathname());
        if (preg_match_all('/_cms_active_theme\/([A-Za-z0-9_\-.\\/]+\.disyl)/', $src, $m)) {
            foreach ($m[1] as $rel) {
                $refsByTheme[$themeEntry][$rel] = true;
            }
        }
    }
}
tarAssert('installed themes make alias references to sweep', $refsByTheme !== [], 'no alias refs found');

$foreign = [];
$swept = 0;
foreach ($refsByTheme as $themeEntry => $themeRefs) {
    foreach (array_keys($themeRefs) as $rel) {
        $swept++;
        $resolved = cmsResolveThemeTemplateAliasPath('_cms_active_theme/' . $rel);
        if ($resolved === '') {
            continue;
        }
        $ownTheme = str_starts_with($resolved, $themesPath . '/' . $active . '/');
        $cmsDefault = str_starts_with($resolved, $moduleDefaultDir . '/');
        if (!$ownTheme && !$cmsDefault) {
            $foreign[] = $themeEntry . ':' . $rel . ' -> ' . $resolved;
        }
    }
}
tarAssert(
    'no alias reference from any theme resolves into another theme',
    $foreign === [],
    implode('; ', array_slice($foreign, 0, 3))
);
echo "      (swept {$swept} alias references across " . count($refsByTheme) . " themes)\n";

// The ARK theme is the one we ship with a composition presentation, so it must be
// self-complete or module-backed: every reference it makes has to resolve to a file
// that exists in ARK or as a CMS module default. This is checked WITHOUT assuming ARK
// is the active theme, because in CLI there is no tenant context and the active theme
// is a default rather than the tenant's choice.
$arkTemplate = $themesPath . '/ark/public/entity.view.disyl';
if (is_file($arkTemplate)) {
    $arkUnresolved = [];
    foreach (array_keys($refsByTheme['ark'] ?? []) as $rel) {
        $inArk = is_file($themesPath . '/ark/' . $rel);
        $inModuleDefault = is_file($moduleDefaultDir . '/' . $rel);
        if (!$inArk && !$inModuleDefault) {
            $arkUnresolved[] = $rel;
        }
    }
    tarAssert(
        'every ARK alias reference exists in ARK or a CMS module default',
        $arkUnresolved === [],
        'unresolved: ' . implode(', ', array_slice($arkUnresolved, 0, 3))
    );
} else {
    tarAssert('the ARK theme template exists', false, $arkTemplate);
}

// ── Gate 2: the global symlink cannot change what resolves ─────────────────────
echo "\n--- GATE 2: resolution is independent of the global symlink ---\n";

$link = defined('CMS_THEME_SYMLINK') ? (string)CMS_THEME_SYMLINK : '';
$originalTarget = ($link !== '' && is_link($link)) ? (string)readlink($link) : null;
$mostUsed = 'public/entity.view.disyl';
$before = cmsResolveThemeTemplateAliasPath('_cms_active_theme/' . $mostUsed);

if ($link === '' || !function_exists('cmsActivateThemeSymlink')) {
    tarAssert('symlink repoint probe is available', false, 'CMS_THEME_SYMLINK or activation helper missing');
} else {
    // Pick a theme that is NOT the active one, and repoint the symlink at it.
    $otherTheme = '';
    foreach (scandir($themesPath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === $active) {
            continue;
        }
        if (is_dir($themesPath . '/' . $entry) && is_file($themesPath . '/' . $entry . '/' . $mostUsed)) {
            $otherTheme = $entry;
            break;
        }
    }
    if ($otherTheme === '') {
        tarAssert('a foreign theme with the same template exists to repoint to', false);
    } else {
        try {
            cmsActivateThemeSymlink($otherTheme);
            $targetNow = is_link($link) ? (string)readlink($link) : '';
            tarAssert(
                'symlink now points at the foreign theme',
                str_contains($targetNow, '/' . $otherTheme),
                "target: '{$targetNow}'"
            );

            $after = cmsResolveThemeTemplateAliasPath('_cms_active_theme/' . $mostUsed);
            tarAssert(
                'resolution is unchanged while the symlink points elsewhere',
                $after === $before,
                "before: '{$before}' after: '{$after}'"
            );
            tarAssert(
                'resolution never returns the symlinked foreign theme',
                !str_contains($after, '/' . $otherTheme . '/'),
                "resolved: '{$after}'"
            );
        } finally {
            // Restore the link exactly as found, so this test leaves no trace.
            if (is_link($link)) {
                @unlink($link);
            }
            if ($originalTarget !== null) {
                @symlink($originalTarget, $link);
            }
            clearstatcache();
        }

        tarAssert(
            'symlink restored to its pre-test target',
            ($originalTarget === null && !is_link($link))
                || ($originalTarget !== null && is_link($link) && readlink($link) === $originalTarget),
            'restore failed for ' . $link
        );
    }
}

// ── Gate 3: resolution is read-only ───────────────────────────────────────────
echo "\n--- GATE 3: resolution does not write to the filesystem ---\n";

if ($link !== '' && is_link($link)) {
    $targetBefore = (string)readlink($link);
    $statBefore = @lstat($link);
    $mtimeBefore = is_array($statBefore) ? (int)($statBefore['mtime'] ?? 0) : 0;
    cmsResolveThemeTemplateAliasPath('_cms_active_theme/' . $mostUsed);
    cmsResolveThemeTemplateAliasPath('_cms_active_theme/public/made-up.disyl');
    clearstatcache();
    $statAfter = @lstat($link);
    tarAssert('symlink target unchanged by resolution', is_link($link) && readlink($link) === $targetBefore);
    tarAssert(
        'symlink mtime unchanged by resolution',
        is_array($statAfter) && (int)($statAfter['mtime'] ?? 0) === $mtimeBefore
    );
} else {
    tarAssert('symlink exists to observe read-only behaviour', false, "no link at '{$link}'");
}

// ── Gate 4: the selector is theme-scoped, not link-scoped ─────────────────────
echo "\n--- GATE 4: theme selection is explicit and theme-scoped ---\n";

if (function_exists('cmsThemeTemplatePathForSlug')) {
    $activePath = cmsThemeTemplatePathForSlug($active, $mostUsed);
    tarAssert(
        'the active theme resolves to its own directory',
        str_starts_with($activePath, $themesPath . '/' . $active . '/'),
        "got: '{$activePath}'"
    );
    tarAssert(
        'the active theme path is not routed through the alias symlink',
        !str_contains($activePath, '_cms_active_theme'),
        "got: '{$activePath}'"
    );
} else {
    tarAssert('cmsThemeTemplatePathForSlug() is available', false);
}

// ── Gate 5: the CMS owns the semantic presentation ────────────────────────────
echo "\n--- GATE 5: CMS resolves content_presentation; the theme only styles it ---\n";

if (function_exists('cmsPublicContext')) {
    $composition = cmsPublicContext(['builder_enabled' => true]);
    $article = cmsPublicContext(['builder_enabled' => false]);

    tarAssert(
        'builder content resolves to composition',
        ($composition['content_presentation'] ?? null) === 'composition',
        'got: ' . var_export($composition['content_presentation'] ?? null, true)
    );
    tarAssert(
        'non-builder content resolves to article',
        ($article['content_presentation'] ?? null) === 'article',
        'got: ' . var_export($article['content_presentation'] ?? null, true)
    );
    tarAssert(
        'the key is present even when builder_enabled is absent',
        isset(cmsPublicContext([])['content_presentation']),
        'content_presentation missing from the default context'
    );

    $overridden = cmsPublicContext(['builder_enabled' => true, 'content_presentation' => 'article']);
    tarAssert(
        'an explicit content_presentation overrides the derived value',
        ($overridden['content_presentation'] ?? null) === 'article',
        'got: ' . var_export($overridden['content_presentation'] ?? null, true)
    );

    $unrecognised = cmsPublicContext(['builder_enabled' => false, 'content_presentation' => 'nonsense']);
    tarAssert(
        'an unrecognised content_presentation falls back to the derived value',
        ($unrecognised['content_presentation'] ?? null) === 'article',
        'got: ' . var_export($unrecognised['content_presentation'] ?? null, true)
    );
} else {
    tarAssert('cmsPublicContext() is available', false);
}

// ── Gate 6: the theme branches on the semantic property ───────────────────────
// ARK is named explicitly: it is the theme that declares a composition presentation,
// and this contract must not silently disappear if another theme is active.
echo "\n--- GATE 6: the theme branches on content_presentation, not on builder truthiness ---\n";

$themeTemplate = $themesPath . '/ark/public/entity.view.disyl';
if (is_file($themeTemplate)) {
    $tpl = (string)file_get_contents($themeTemplate);
    tarAssert(
        'theme branches on content_presentation',
        str_contains($tpl, "content_presentation == 'composition'"),
        'no content_presentation comparison found'
    );
    tarAssert(
        'theme does not branch on a bare builder_enabled test',
        !preg_match('/\{if\s+builder_enabled\s*\}/', $tpl),
        'found a bare {if builder_enabled} - an absent variable is truthy in DiSyL'
    );
    tarAssert(
        'both presentations are named in the markup',
        str_contains($tpl, 'data-ark-presentation="composition"') && str_contains($tpl, 'data-ark-presentation="article"')
    );
} else {
    tarAssert('the ARK theme exposes a public entity view template', false, $themeTemplate);
}

// No theme anywhere may branch on bare builder_enabled truthiness.
$bareTruthiness = [];
foreach (scandir($themesPath) ?: [] as $themeEntry) {
    if ($themeEntry === '.' || $themeEntry === '..' || !is_dir($themesPath . '/' . $themeEntry)) {
        continue;
    }
    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($themesPath . '/' . $themeEntry, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($dir as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.disyl')) {
            continue;
        }
        if (preg_match('/\{if\s+builder_enabled\s*\}/', (string)file_get_contents($file->getPathname()))) {
            $bareTruthiness[] = $themeEntry . '/' . basename($file->getPathname());
        }
    }
}
tarAssert(
    'no theme branches on a bare builder_enabled test',
    $bareTruthiness === [],
    implode(', ', array_slice($bareTruthiness, 0, 3))
);

echo "\n============================================================\n";
echo "Theme alias resolution tests: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed === 0 ? 0 : 1);
