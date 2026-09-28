<?php
/**
 * Regression coverage for the ARK production UX quality contract.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cssPath = $root . '/storage/cms-themes/ark/style.css';
$publicCssPath = $root . '/public/assets/cms/themes/ark/style.css';
$cmsPublicCssPath = $root . '/public/assets/cms/cms-public.css';
$css = (string) file_get_contents($cssPath);
$cmsPublicCss = (string) file_get_contents($cmsPublicCssPath);
$passed = 0;
$failed = 0;

function uxAssert(string $label, bool $condition, string $detail = ''): void
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

uxAssert('text-safe ARK primary uses primary-dark', str_contains($css, '--ark-primary: var(--color-primary-dark, #4f46e5)'));
uxAssert('skip link uses the compliant dark primary', preg_match('/\.ark-skip\s*\{[^}]*background:\s*var\(--ark-primary-dark\)/s', $css) === 1);
uxAssert('mobile toggle has an explicit focus indicator', preg_match('/\.ark-header__mobile-toggle:focus-visible\s*\{[^}]*(outline|box-shadow):/s', $css) === 1);
// Section 12 (builder output outranks the theme): builder-namespace styling is
// the builder's job and lives in cms-public.css, never in the ARK theme.
uxAssert('ARK theme no longer targets a builder namespace', preg_match('/cms-builder|cms-kb|cms-lightbox/', $css) !== 1);
uxAssert('slider caption headings inherit their dark-surface colour (builder stylesheet)', preg_match('/\.cms-builder-node--slideshow \.cms-builder-slide h3\s*\{[^}]*color:\s*inherit/s', $cmsPublicCss) === 1);
uxAssert('authored blue builder eyebrow gets a contrast-safe surface (builder stylesheet)', str_contains($cmsPublicCss, '.cms-builder-node--text[style*="--b-color:#3B82F6"]'));
uxAssert('storefront muted text declares secondary token #475569', str_contains($css, '.ark-commerce-muted { color: var(--color-text-secondary, #475569); }'));
uxAssert('storefront accent text uses ARK primary-dark', str_contains($css, '.ark-commerce-accent { color: var(--color-primary-dark, #4f46e5); }'));
uxAssert('storefront success text declares compliant #047857', str_contains($css, '.ark-commerce-success { color: #047857; }'));
uxAssert('storefront warning text declares compliant #92400e', str_contains($css, '.ark-commerce-warning { color: #92400e; }'));
uxAssert('storefront danger text declares compliant #b91c1c', str_contains($css, '.ark-commerce-danger { color: #b91c1c; }'));
uxAssert('storefront action background uses ARK primary-dark', preg_match('/\.ark-commerce-action\s*\{[^}]*var\(--color-primary-dark, #4f46e5\)/s', $css) === 1);
uxAssert('storefront links use ARK primary-dark', preg_match('/\.ark-commerce-link\s*\{[^}]*var\(--color-primary-dark, #4f46e5\)/s', $css) === 1);

$templateText = '';
$templateFiles = glob($root . '/templates/modules/ecommerce/public/*.disyl') ?: [];
$templateFiles = array_merge($templateFiles, glob($root . '/templates/modules/ecommerce/public/partials/*.disyl') ?: []);
foreach ($templateFiles as $templateFile) {
    $templateText .= "\n" . (string) file_get_contents($templateFile);
}
foreach (['text-gray-400', 'text-amber-600', 'text-red-500', 'text-emerald-600', 'text-orange-600', 'bg-orange-600'] as $knownFailure) {
    uxAssert("public ecommerce templates reject {$knownFailure}", !str_contains($templateText, $knownFailure));
}

uxAssert('off-scale 1.15rem declarations removed from ARK stylesheet', !str_contains($css, 'font-size: 1.15rem'));
uxAssert('no new important declarations', substr_count($css, '!important') === 8, 'found ' . substr_count($css, '!important'));
uxAssert('published and source stylesheets are byte-identical', hash_file('sha256', $cssPath) === hash_file('sha256', $publicCssPath));

printf("\nARK UX quality: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
