<?php
/**
 * Footer bottom-bar contrast regression test.
 *
 * Run: php tests/footer_bar_contrast_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$passed = 0;
$failed = 0;

function footerContrastAssert(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  PASS: {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
        return;
    }

    $failed++;
    echo "  FAIL: {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

/** @return array{0: float, 1: float, 2: float} */
function footerContrastRgb(string $hex): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        throw new InvalidArgumentException("Invalid RGB hex color: {$hex}");
    }

    return [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    ];
}

function footerContrastLuminance(string $hex): float
{
    $channels = array_map(
        static fn(float $channel): float => $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4,
        footerContrastRgb($hex)
    );

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function footerContrastRatio(string $foreground, string $background): float
{
    $foregroundLuminance = footerContrastLuminance($foreground);
    $backgroundLuminance = footerContrastLuminance($background);
    $lighter = max($foregroundLuminance, $backgroundLuminance);
    $darker = min($foregroundLuminance, $backgroundLuminance);

    return ($lighter + 0.05) / ($darker + 0.05);
}

$cmsDefaults = cmsFooterSettingsDefaults();
$ratio = footerContrastRatio(
    (string)$cmsDefaults['bar_text_color'],
    (string)$cmsDefaults['bar_bg_color']
);
$ratioLabel = number_format($ratio, 2) . ':1';

echo "Footer bottom-bar contrast\n";
echo "  Computed ratio: {$cmsDefaults['bar_text_color']} on {$cmsDefaults['bar_bg_color']} = {$ratioLabel}\n";
footerContrastAssert(
    $ratio >= 4.5,
    'CMS default clears the WCAG AA 4.5:1 floor',
    $ratioLabel
);

$themeDir = dirname(__DIR__) . '/storage/cms-themes/ark';
$schema = json_decode((string)file_get_contents($themeDir . '/customizer.schema.json'), true, 512, JSON_THROW_ON_ERROR);
$schemaDefault = $schema['sections']['footer']['controls']['bar_text_color']['default'] ?? null;
footerContrastAssert(
    $schemaDefault === $cmsDefaults['bar_text_color'],
    'ARK schema bar text default agrees with the CMS default',
    (string)$schemaDefault
);

$region = (string)file_get_contents($themeDir . '/templates/regions/footer.disyl');
$regionDefault = null;
if (preg_match("/section_settings\\.bar_text_color\\|default:'(#[0-9a-fA-F]{6})'/", $region, $matches) === 1) {
    $regionDefault = strtolower($matches[1]);
}
footerContrastAssert(
    $regionDefault === strtolower((string)$cmsDefaults['bar_text_color']),
    'ARK region bar text default agrees with the CMS default',
    (string)$regionDefault
);

$seedDefaults = cmsCustomizerSectionDefaults('footer');
$seedRatio = footerContrastRatio(
    (string)$seedDefaults['bar_text_color'],
    (string)$seedDefaults['bar_bg_color']
);
footerContrastAssert(
    $seedRatio >= 4.5,
    'footer section seed default clears the WCAG AA 4.5:1 floor',
    number_format($seedRatio, 2) . ':1'
);

// Every colour surface introduced by the governed ARK regions must meet the theme's 4.5:1 floor.
$headerDefaults = array_map(
    static fn(array $control): mixed => $control['default'] ?? null,
    $schema['sections']['header']['controls']
);
$footerDefaults = array_map(
    static fn(array $control): mixed => $control['default'] ?? null,
    $schema['sections']['footer']['controls']
);
$sidebarDefaults = array_map(
    static fn(array $control): mixed => $control['default'] ?? null,
    $schema['sections']['sidebar']['controls']
);
$regionPairs = [
    'top bar text' => [$headerDefaults['topbar_text_color'], $headerDefaults['topbar_bg_color']],
    'top bar link' => [$headerDefaults['topbar_link_color'], $headerDefaults['topbar_bg_color']],
    'top bar link hover' => [$headerDefaults['topbar_link_hover_color'], $headerDefaults['topbar_bg_color']],
    'dropdown text' => [$headerDefaults['dropdown_text_color'], $headerDefaults['dropdown_bg_color']],
    'dropdown hover text' => [$headerDefaults['dropdown_hover_text_color'], $headerDefaults['dropdown_hover_bg_color']],
    'mobile panel text' => [$headerDefaults['mobile_text_color'], $headerDefaults['mobile_bg_color']],
    'mobile hover text' => [$headerDefaults['mobile_text_color'], $headerDefaults['mobile_hover_bg_color']],
    'mobile active text' => [$headerDefaults['mobile_text_color'], $headerDefaults['mobile_active_bg_color']],
    // Transparent mode uses this dark scrim in header.disyl so white text has a known surface.
    'transparent header text' => [$headerDefaults['transparent_text_color'], '#0f172a'],
    'transparent logo text' => [$headerDefaults['transparent_logo_color'], '#0f172a'],
    'footer title' => [$footerDefaults['footer_title_color'], $footerDefaults['footer_bg_color']],
    'sidebar link' => [$sidebarDefaults['widget_link_color'], $sidebarDefaults['widget_bg_color']],
    'sidebar link hover' => [$sidebarDefaults['widget_link_hover_color'], $sidebarDefaults['widget_bg_color']],
];
foreach ($regionPairs as $label => [$foreground, $background]) {
    $pairRatio = footerContrastRatio((string)$foreground, (string)$background);
    footerContrastAssert(
        $pairRatio >= 4.5,
        "ARK {$label} defaults clear the WCAG AA 4.5:1 floor",
        number_format($pairRatio, 2) . ':1'
    );
}

// Region-template fallbacks are the final styling layer when settings are absent. Keep them in
// lockstep with customizer.schema.json rather than allowing a second, divergent default palette.
$headerRegion = (string)file_get_contents($themeDir . '/templates/regions/header.disyl');
$regionFallbackKeys = [
    'topbar_bg_color', 'topbar_text_color', 'topbar_link_color', 'topbar_link_hover_color',
    'dropdown_bg_color', 'dropdown_text_color', 'dropdown_hover_bg_color', 'dropdown_hover_text_color',
    'mobile_bg_color', 'mobile_text_color', 'mobile_hover_bg_color', 'mobile_active_bg_color',
    'transparent_text_color', 'transparent_logo_color',
];
foreach ($regionFallbackKeys as $key) {
    $expected = (string)$headerDefaults[$key];
    footerContrastAssert(
        str_contains($headerRegion, "section_settings.{$key}|default:'{$expected}'"),
        "ARK header fallback for {$key} matches its schema default",
        $expected
    );
}
footerContrastAssert(
    str_contains($region, "section_settings.footer_title_color|default:'{$footerDefaults['footer_title_color']}'"),
    'ARK footer title fallback matches its schema default',
    (string)$footerDefaults['footer_title_color']
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
