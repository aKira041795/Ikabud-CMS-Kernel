<?php
/** Run: php tests/ecommerce_theme_layout_override_test.php */
declare(strict_types=1);

$root = dirname(__DIR__);
$shared = (string) file_get_contents($root . '/templates/modules/ecommerce/layouts/public.disyl');
$overridePath = $root . '/storage/cms-themes/entity-commerce-poc/modules/ecommerce/layouts/public.disyl';
$override = (string) @file_get_contents($overridePath);
$pass = 0;
$fail = 0;
function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " {$label}" . (!$ok && $detail !== '' ? " -- {$detail}" : '') . "\n";
}

echo "\nEcommerce theme layout override contract\n\n";
t('shared layout does not name the POC theme', !str_contains($shared, 'entity-commerce-poc'));
t('shared layout does not own POC shell classes', !str_contains($shared, 'poc-shell') && !str_contains($shared, 'poc-main'));
t('POC module-layout override exists', is_file($overridePath));
t('POC override owns its shell', str_contains($override, 'class="poc-shell"') && str_contains($override, 'class="poc-main"'));

$publicTemplates = glob($root . '/templates/modules/ecommerce/public/*.disyl') ?: [];
$bad = [];
$alias = '{extends "_cms_active_theme/modules/ecommerce/layouts/public.disyl"}';
foreach ($publicTemplates as $template) {
    $source = (string) file_get_contents($template);
    if (str_contains($source, '{extends ') && !str_starts_with($source, $alias)) {
        $bad[] = basename($template);
    }
}
t('ecommerce pages resolve their layout through the active-theme alias', $bad === [], implode(', ', $bad));

$helper = (string) file_get_contents($root . '/modules/cms/helpers/40-theme-settings.php');
t('alias resolver has an additive module-origin fallback',
    str_contains($helper, "str_starts_with(\$relativePath, 'modules/')")
    && str_contains($helper, "'/templates/' . \$relativePath"));

echo "\n  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
