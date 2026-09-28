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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
