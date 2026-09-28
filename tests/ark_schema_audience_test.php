<?php
/**
 * Every ARK customizer control declares WHO it is for.
 *
 * ARK declares its own customizer surface in customizer.schema.json. Widening
 * that surface to cover the full CMS chrome (top bar, dropdown, mobile canvas,
 * transparent header, geometry, sidebar targeting) is only safe if the extra
 * knobs do not land in front of a site owner. So each control carries an
 * `audience` of `user` or `developer`:
 *
 *   user      - brand, content, visibility toggles, counts, layout choices an
 *               owner can judge by looking at the page.
 *   developer - CSS-level internals: pixel rhythm, width/height mode enums,
 *               breakpoints, opacity, radii, template scoping/rules.
 *
 * cmsThemeSectionPanels() turns that declaration into panels: ungrouped owner
 * controls stay in the section panel, ungrouped developer controls move to an
 * "<Section> — Advanced" panel, and a panel is only marked developer-facing
 * when EVERY control in it is. That last rule is the important one - a mixed
 * group must never be hidden from the owner.
 *
 * Run: php tests/ark_schema_audience_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

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

/** See cms_theme_declared_sections_test.php - sets the cached active theme. */
function withTheme(string $slug, callable $fn): mixed
{
    $tid = cmsRuntimeTenantId();
    $cachedKey = 'cms_active_theme_cached_t' . $tid;
    $valueKey = 'cms_active_theme_value_t' . $tid;
    $hadCached = array_key_exists($cachedKey, $GLOBALS);
    $hadValue = array_key_exists($valueKey, $GLOBALS);
    $prevCached = $GLOBALS[$cachedKey] ?? null;
    $prevValue = $GLOBALS[$valueKey] ?? null;

    $GLOBALS[$cachedKey] = true;
    $GLOBALS[$valueKey] = $slug === 'default' ? null : $slug;

    try {
        return $fn();
    } finally {
        if ($hadCached) {
            $GLOBALS[$cachedKey] = $prevCached;
        } else {
            unset($GLOBALS[$cachedKey]);
        }
        if ($hadValue) {
            $GLOBALS[$valueKey] = $prevValue;
        } else {
            unset($GLOBALS[$valueKey]);
        }
    }
}

// Sanity: if the harness cannot switch themes, nothing below means anything.
withTheme('ark', function (): void {
    t('harness resolves ARK as the active theme', cmsActiveTheme() === 'ark', (string)cmsActiveTheme());
});

echo "\nARK customizer schema — audience contract\n";
echo str_repeat('-', 62) . "\n";

// ── 1. Every declared control carries a valid audience ──────────────────────
$schema = json_decode((string)file_get_contents(cmsThemesPath() . '/ark/customizer.schema.json'), true);
t('schema parses', is_array($schema));

$allControls = [];
foreach (($schema['sections'] ?? []) as $secName => $sec) {
    foreach ((array)($sec['controls'] ?? []) as $cid => $c) {
        $allControls[$secName . '.' . $cid] = $c;
    }
}
t('schema declares controls', count($allControls) > 0, (string)count($allControls));

$bad = [];
foreach ($allControls as $key => $c) {
    if (!in_array($c['audience'] ?? null, ['user', 'developer'], true)) {
        $bad[] = $key;
    }
}
t(
    'every control declares audience user|developer',
    $bad === [],
    $bad === [] ? '' : implode(', ', array_slice($bad, 0, 5))
);

$userCount = count(array_filter($allControls, fn($c) => ($c['audience'] ?? '') === 'user'));
$devCount  = count(array_filter($allControls, fn($c) => ($c['audience'] ?? '') === 'developer'));
t('both audiences are actually used', $userCount > 0 && $devCount > 0, "user={$userCount} dev={$devCount}");

// ── 2. The widening actually closed the gap against the CMS chrome surface ──
$cmssurface = [
    'header'  => count(cmsHeaderSettingsDefaults()),
    'footer'  => count(cmsFooterSettingsDefaults()),
    'sidebar' => count(cmsSidebarSettingsDefaults()),
];
$declared = [];
foreach (($schema['sections'] ?? []) as $secName => $sec) {
    $declared[$secName] = count((array)($sec['controls'] ?? []));
}
foreach ($cmssurface as $secName => $cmsCount) {
    t(
        "ARK {$secName} coverage >= CMS surface ({$cmsCount} keys)",
        ($declared[$secName] ?? 0) >= $cmsCount,
        'ark=' . ($declared[$secName] ?? 0)
    );
}

// Developer-only concepts must never be presented as owner settings.
foreach (['header.topbar_inner_width_mode', 'sidebar.template_rules', 'sidebar.template_scope',
          'header.header_inner_width_mode', 'footer.widget_inner_width_mode'] as $key) {
    t(
        "{$key} is developer-facing",
        ($allControls[$key]['audience'] ?? null) === 'developer'
    );
}
// And owner-facing concepts must not be buried in Advanced.
foreach (['header.show_topbar', 'header.cta_text', 'header.favicon_url',
          'footer.copyright_text', 'sidebar.widget_link_color'] as $key) {
    t(
        "{$key} is owner-facing",
        ($allControls[$key]['audience'] ?? null) === 'user'
    );
}

// ── 3. Which schema actually reaches the user ───────────────────────────────
//
// This is the split that matters most, and it is NOT the same as user/developer:
//
//   * BASE sections (header, footer, sidebar, colors, theme) are CMS-owned.
//     The CMS renders them from hardcoded tabs in the theme-customizer template
//     and uses the theme's schema only for DEFAULTS and VALIDATION. More keys
//     there make the theme's declaration complete, but they do not add UI.
//
//   * THEME-DECLARED sections (anything outside the base list, e.g. `typography`)
//     render generically from the schema, so that IS the theme's own user-facing
//     surface.
//
// Both facts are pinned below, because a "widening" that only adds base-section
// keys would look like progress while changing nothing an owner can see.
echo "\nCMS-owned base sections vs theme-owned sections\n";
echo str_repeat('-', 62) . "\n";

withTheme('ark', function (): void {
    $base = cmsCustomizerBaseSections();
    $declared = array_keys(cmsThemeDeclaredSections());

    // Every base section still resolves to no theme-declared controls: the CMS
    // owns their UI.
    $baseWithControls = [];
    foreach ($base as $sec) {
        if (cmsThemeSectionControlSchema($sec) !== []) {
            $baseWithControls[] = $sec;
        }
    }
    t(
        'base sections are not rendered from the theme schema (CMS owns their UI)',
        $baseWithControls === [],
        implode(', ', $baseWithControls)
    );

    // ...yet the theme definition still carries them, for defaults + validation.
    $def = cmsThemeCustomizerDefinition();
    $defHeader = $def !== null && method_exists($def, 'section') ? $def->section('header') : null;
    $defHeaderCount = is_object($defHeader) ? count((array)$defHeader->controls) : 0;
    t(
        'base sections still reach the theme definition (defaults + validation)',
        $defHeaderCount >= count(cmsHeaderSettingsDefaults()),
        "definition={$defHeaderCount}"
    );

    // The theme's own surface is the non-base sections, and it renders.
    t('ARK declares at least one theme-owned section', $declared !== [], implode(', ', $declared));

    $ownedWithControls = [];
    foreach ($declared as $sec) {
        if (cmsThemeSectionControlSchema($sec) !== []) {
            $ownedWithControls[] = $sec;
        }
    }
    t(
        'theme-owned sections DO render from the theme schema',
        $ownedWithControls !== [],
        implode(', ', $ownedWithControls)
    );

    // And those render through panels that honour the audience split.
    $panelCounts = [];
    foreach ($ownedWithControls as $sec) {
        $panels = cmsThemeSectionPanels($sec);
        $panelCounts[$sec] = count($panels);
        t("{$sec} produces panels", $panels !== []);

        $untagged = 0;
        $mislabelled = [];
        foreach ($panels as $p) {
            $aud = (string)($p['audience'] ?? '');
            if (!in_array($aud, ['user', 'developer'], true)) {
                $untagged++;
                continue;
            }
            $userInPanel = 0;
            foreach ($p['controls'] as $c) {
                if (($c['audience'] ?? 'user') === 'user') {
                    $userInPanel++;
                }
            }
            // A panel marked developer-facing must contain no owner controls,
            // or an owner could lose access to something they need.
            if ($aud === 'developer' && $userInPanel > 0) {
                $mislabelled[] = (string)$p['key'];
            }
        }
        t("{$sec}: every panel carries a valid audience tag", $untagged === 0, (string)$untagged);
        t(
            "{$sec}: no developer panel hides an owner control",
            $mislabelled === [],
            implode(', ', $mislabelled)
        );

        // No control may be dropped by the split.
        $ids = [];
        foreach ($panels as $p) {
            foreach ($p['controls'] as $c) {
                $ids[] = (string)$c['id'];
            }
        }
        $expected = array_keys((array)($schema['sections'][$sec]['controls'] ?? []));
        $missing = array_diff($expected, $ids);
        t("{$sec}: split drops no control", $missing === [], implode(', ', array_slice($missing, 0, 4)));
    }
});

// ── 4. Backward compatibility: a theme that declares no audience ─────────────
echo "\nThemes without an audience declaration keep working\n";
echo str_repeat('-', 62) . "\n";

withTheme('native-default', function (): void {
    // native-default ships no schema at all, so there is nothing to split and
    // nothing may be wrongly hidden.
    t('schema-less theme yields no panels (not an error)', cmsThemeSectionPanels('header') === []);
    t('schema-less theme has an empty raw schema', cmsThemeCustomizerRawSchema() === []);
    t('schema-less theme still has a usable base vocabulary',
        count(cmsKnownCustomizerSections()) > 0, (string)count(cmsKnownCustomizerSections()));
});

echo "\n" . str_repeat('-', 62) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
