<?php
/**
 * Contract checks for ARK's default component CSS.
 * Run: php tests/ark_component_defaults_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/storage/cms-themes/ark/style.css');
$published = (string) file_get_contents($root . '/public/assets/cms/themes/ark/style.css');
$pass = 0;
$fail = 0;

function check(string $label, bool $result): void
{
    global $pass, $fail;
    $result ? $pass++ : $fail++;
    echo '  ' . ($result ? "\u{2713}" : "\u{2717}") . " {$label}\n";
}

function declaration(string $css, string $selector, string $property): bool
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        $selectors = array_map('trim', explode(',', preg_replace('/\/\*.*?\*\//s', '', $rule[1]) ?? $rule[1]));
        if (in_array($selector, $selectors, true)
            && preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:/m', $rule[2]) === 1) {
            return true;
        }
    }
    return false;
}

$checks = [
    ['base button has background', '.ark-btn', 'background'],
    ['base button has padding', '.ark-btn', 'padding'],
    ['base button has radius', '.ark-btn', 'border-radius'],
    ['primary button has background', '.ark-btn--primary', 'background'],
    ['outline button has border', '.ark-btn--outline', 'border-color'],
    ['action group uses layout', '.ark-action-group', 'display'],
    ['badge has background', '.ark-badge', 'background'],
    ['breadcrumbs list uses layout', '.ark-breadcrumbs__list', 'display'],
    ['pagination has padding', '.ark-pagination', 'padding'],
    ['not-found code has font size', '.ark-not-found__code', 'font-size'],
    ['search inner has border', '.ark-search-form__inner', 'border'],
    ['card pricing variant has border', '.ark-card--featured-pricing', 'border-color'],
    ['progress track has background', '.ark-progress-track', 'background'],
    ['table wrapper has border', '.ark-table-wrap', 'border'],
    ['form has layout', '.ark-form', 'display'],
    ['filter summary has background', '.ark-filter-summary', 'background'],
    ['hero has padding', '.ark-hero-block', 'padding'],
    ['accordion has border', '.ark-accordion', 'border'],
    ['chart has padding', '.ark-chart', 'padding'],
    ['media gallery has layout', '.ark-media-gallery', 'display'],
    ['simple-list card has border', '.ark-simple-list__item--card', 'border'],
    ['split has layout', '.ark-split', 'display'],
    ['sidebar primary has border', '.ark-sidebar--primary', 'border'],
    ['cart summary has background', '.ark-cart-summary', 'background'],
    ['stock badge has padding', '.ark-stock-badge', 'padding'],
    ['product detail has padding', '.ark-product-detail', 'padding'],
    ['pricing CTA has background', '.ark-pricing-cta__button', 'background'],
    ['detail image has radius', '.ark-detail__image', 'border-radius'],
    ['ledger row has border', '.ark-ledger-row', 'border-bottom'],
    ['content table cells have border', '.ark-content th', 'border'],
];

foreach ($checks as [$label, $selector, $property]) {
    check($label, declaration($css, $selector, $property));
}
check('published stylesheet matches source', hash('sha256', $css) === hash('sha256', $published));
check('component CSS introduces no important declarations', !str_contains(substr($css, strpos($css, '/* ── ARK default component system')), '!important'));

echo "\nARK component defaults: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
