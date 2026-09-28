<?php
/**
 * Can a theme define its own customizer sections?
 *
 * This is the difference between an ARK-style theme and a WordPress-style one.
 * A WordPress-convention theme (native-default) declares nothing and inherits the
 * CMS's built-in section vocabulary. An ARK-style theme ships
 * customizer.schema.json and declares its own sections and controls, which the
 * CMS then renders, validates and persists without any CMS-side hardcoding.
 *
 * Before this test, ARK's declared sections were parsed by the kernel but
 * unreachable: saving them returned 422 "Unknown customizer section" because
 * cmsKnownCustomizerSections() was a fixed 7-item list. This test pins both
 * halves - the theme's declaration AND the CMS honouring it.
 *
 * Run: php tests/cms_theme_declared_sections_test.php
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

/**
 * Run $fn with $slug as the active theme.
 *
 * cmsConfiguredActiveTheme() is the source of truth for "which theme is active"
 * (it reads the tenant's CMS settings). It caches into per-tenant globals, so a
 * test sets those directly and restores them afterwards. The definition caches
 * are keyed by slug, so switching themes re-derives instead of going stale.
 */
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

/** Sanity: the harness must actually be able to switch themes, or nothing below means anything. */
withTheme('ark', function (): void {
    t('harness resolves ARK as the active theme', cmsActiveTheme() === 'ark', (string)cmsActiveTheme());
});
withTheme('native-default', function (): void {
    t('harness resolves native-default as the active theme',
        cmsActiveTheme() === 'native-default', (string)cmsActiveTheme());
});

echo "\nTheme-declared customizer sections\n";
echo str_repeat('-', 62) . "\n";

// ── 1. The declaration itself is theme-owned ────────────────────────────────
$arkPath = cmsThemesPath() . '/ark';
$loosePath = cmsThemesPath() . '/native-default';

t('ARK ships its own customizer.schema.json', is_file($arkPath . '/customizer.schema.json'));
t('native-default ships no customizer.schema.json', !is_file($loosePath . '/customizer.schema.json'));

$arkDef = \Ikabud\Kernel\Services\ThemeDefinitionLoader::load('ark', $arkPath);
t('loader parses ARK into a definition', $arkDef !== null);
t('ARK declares more than the CMS base vocabulary',
    $arkDef !== null && count($arkDef->sectionNames()) >= 6,
    $arkDef ? implode(',', $arkDef->sectionNames()) : 'null');
t('ARK typography is NOT one of the CMS base sections',
    $arkDef !== null && in_array('typography', $arkDef->sectionNames(), true)
        && !in_array('typography', cmsCustomizerBaseSections(), true));

$typo = $arkDef?->section('typography');
t('typography declares 4 controls', $typo !== null && count($typo->controls) === 4);
$h1 = $typo?->controls['h1_size'] ?? null;
t('h1_size is a bounded number control',
    $h1 !== null && $h1->type === 'number'
        && ($h1->constraints['min'] ?? null) === 1.0
        && ($h1->constraints['max'] ?? null) === 4.0,
    $h1 ? json_encode([$h1->type, $h1->constraints]) : 'missing');

$looseDef = \Ikabud\Kernel\Services\ThemeDefinitionLoader::load('native-default', $loosePath);
t('native-default parses to zero sections (it declares none)',
    $looseDef === null || $looseDef->sectionNames() === [],
    $looseDef ? implode(',', $looseDef->sectionNames()) : 'null');

// ── 2. The CMS honours the theme's declaration ──────────────────────────────
withTheme('ark', function (): void {
    $known = cmsKnownCustomizerSections();
    t('ARK: typography is accepted as a known section',
        in_array('typography', $known, true), implode(',', $known));
    t('ARK: the CMS base sections survive alongside it',
        in_array('header', $known, true) && in_array('footer', $known, true));
    t('ARK: typography is recognised as theme-declared', cmsIsThemeDeclaredSection('typography'));
    t('ARK: header is NOT theme-declared (CMS still owns it)',
        !cmsIsThemeDeclaredSection('header'));

    $defaults = cmsThemeSectionControlDefaults('typography');
    t('ARK: defaults come from the theme schema',
        ($defaults['h1_size'] ?? null) == 2.5 && ($defaults['h2_size'] ?? null) == 2.0,
        json_encode($defaults));

    $payload = cmsThemeDeclaredSectionsPayload();
    t('ARK: payload exposes the section with typed controls',
        count($payload) === 1
            && $payload[0]['id'] === 'typography'
            && count($payload[0]['controls']) === 4
            && $payload[0]['controls'][0]['type'] === 'number',
        json_encode(array_map(fn($p) => [$p['id'], count($p['controls'])], $payload)));
});

withTheme('native-default', function (): void {
    t('native-default: no theme-declared sections', cmsThemeDeclaredSections() === []);
    t('native-default: vocabulary is exactly the CMS base list',
        cmsKnownCustomizerSections() === cmsCustomizerBaseSections(),
        implode(',', cmsKnownCustomizerSections()));
    t('native-default: typography is correctly unknown',
        !in_array('typography', cmsKnownCustomizerSections(), true));
});

// ── 3. Validation follows the theme's schema, not the CMS's ─────────────────
withTheme('ark', function (): void {
    $clean = cmsValidateThemeSectionSettings('typography', [
        'h1_size' => 3.0,
        'not_a_control' => 'ignored',
    ]);
    t('unknown keys are dropped', !array_key_exists('not_a_control', $clean));
    t('declared keys survive', ($clean['h1_size'] ?? null) == 3.0, json_encode($clean));

    $high = cmsValidateThemeSectionSettings('typography', ['h2_size' => 12345]);
    t('values above the schema max are clamped', ($high['h2_size'] ?? null) == 3.5, json_encode($high));

    $low = cmsValidateThemeSectionSettings('typography', ['h1_size' => 0.1]);
    t('values below the schema min are clamped', ($low['h1_size'] ?? null) == 1.0, json_encode($low));

    $bad = cmsValidateThemeSectionSettings('typography', ['h3_size' => 'not-a-number']);
    t('a wrong type falls back to the schema default', ($bad['h3_size'] ?? null) == 1.5, json_encode($bad));

    t('a CMS-owned section is not validated as theme-declared',
        cmsValidateThemeSectionSettings('header', ['layout' => 'x']) === []);
});

// ── 4. Panels: controls grouped by the theme's own `group` declaration ───────
withTheme('ark', function (): void {
    $panels = cmsThemeSectionPanels('typography');
    t('ARK declares 2 control groups for typography', count($panels) === 2,
        implode(' | ', array_map(fn($p) => $p['label'], $panels)));

    $labels = array_map(fn($p) => $p['label'], $panels);
    t('group labels come from the schema, in declaration order',
        $labels === ['Display Headings', 'Section Headings'], implode(',', $labels));

    $ids = array_map(fn($p) => array_map(fn($c) => $c['id'], $p['controls']), $panels);
    t('groups partition the controls without losing any',
        $ids === [['h1_size', 'h2_size'], ['h3_size', 'h4_size']], json_encode($ids));

    // Every control must land in exactly one panel.
    $flat = array_merge(...$ids);
    $unique = array_unique($flat);
    t('no control is dropped or duplicated across panels',
        count($flat) === count($unique) && count($flat) === count(cmsThemeSectionControlSchema('typography')),
        json_encode($flat));

    $payload = cmsThemeDeclaredSectionsPayload();
    t('the template payload carries panels, not just controls',
        isset($payload[0]['panels']) && count($payload[0]['panels']) === 2,
        json_encode(array_keys($payload[0] ?? [])));
});

// A section whose controls declare no group still renders, as one panel.
withTheme('ark', function (): void {
    $schema = cmsThemeCustomizerRawSchema();
    $headerGroups = [];
    foreach ((array)($schema['sections']['header']['controls'] ?? []) as $control) {
        if (is_array($control) && isset($control['group'])) {
            $headerGroups[] = $control['group'];
        }
    }
    // ARK used to declare header controls with no `group`, so panels were
    // opt-in. The schema now classifies its surface (user vs developer) and
    // groups it accordingly, so the correct assertion is now the inverse:
    // base-section controls DO carry groups.
    t('ARK header controls now carry groups (panels always on)',
        $headerGroups !== [], 'no groups found');
});

// ── 5. Falsification: the old behaviour would fail this test ────────────────
withTheme('ark', function (): void {
    $hardcoded = ['footer', 'header', 'sidebar', 'colors', 'custom_code', 'entity_presentation', 'theme'];
    $current = cmsKnownCustomizerSections();
    t('the list is no longer the fixed 7-item hardcoded set',
        $current !== $hardcoded,
        'still hardcoded: ' . implode(',', $current));
});

echo str_repeat('-', 62) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);

if ($pass === 0) {
    fwrite(STDERR, "refusing to report a vacuous pass\n");
    exit(1);
}

exit($fail === 0 ? 0 : 1);
