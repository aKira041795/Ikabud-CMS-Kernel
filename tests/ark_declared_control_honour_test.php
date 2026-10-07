<?php
/**
 * Ratchet for the controls ARK declares in customizer.schema.json.
 *
 * Structural coverage prevents accidental vocabulary drift; differential rendering prevents
 * satisfying that check with dead references which do not change rendered output.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = $root . '/kernel/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Ikabud\Kernel\DiSyL\TemplateEngine;

$passed = 0;
$failed = 0;

function honourAssert(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        ++$passed;
        echo "  ✓ {$label}\n";
        return;
    }
    ++$failed;
    echo "  ✗ {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

$schemaPath = $root . '/storage/cms-themes/ark/customizer.schema.json';
$schema = json_decode((string)file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
$gateRegions = ['header', 'footer', 'sidebar'];
$tokenDriven = ['colors', 'theme', 'typography'];

$allowedSidebarRemainder = [];

echo "ARK declared-control honour ratchet\n\n";
honourAssert(
    array_intersect($gateRegions, $tokenDriven) === [],
    'token-driven colors/theme/typography are excluded from region gates'
);

foreach ($gateRegions as $region) {
    $controls = $schema['sections'][$region]['controls'] ?? [];
    $templatePath = $root . "/storage/cms-themes/ark/templates/regions/{$region}.disyl";
    $template = is_file($templatePath) ? (string)file_get_contents($templatePath) : '';
    preg_match_all('/section_settings\.([a-z_0-9]+)/', $template, $matches);
    $read = array_flip($matches[1] ?? []);
    $missing = array_values(array_filter(
        array_keys($controls),
        static fn(string $key): bool => !isset($read[$key])
    ));
    sort($missing);

    if ($region === 'sidebar') {
        $expected = array_keys($allowedSidebarRemainder);
        sort($expected);
        honourAssert(
            $missing === $expected,
            'sidebar has zero declared-but-unhonoured controls',
            'expected [' . implode(', ', $expected) . '], got [' . implode(', ', $missing) . ']'
        );
    } else {
        honourAssert(
            $missing === [],
            "{$region} has zero declared-but-unhonoured controls",
            implode(', ', $missing)
        );
    }
}

$cachePath = sys_get_temp_dir() . '/ark_honour_guard_' . getmypid();
@mkdir($cachePath, 0777, true);
$engine = new TemplateEngine($root . '/templates', $cachePath, false);
$baseContext = [
    'theme' => 'ark',
    'current_year' => '2026',
    'site' => ['url' => 'http://cmsnew.test', 'title' => "Li'l Juanita", 'tagline' => 'the kitty'],
    'settings' => ['colors' => ['primary' => '#6366f1']],
    'entity_context' => ['kind' => 'post'],
    'navigation' => [
        'primary' => '<a href="/">Home</a><a href="/menu">Menu</a>'
            . '<ul class="sub-menu"><li><a href="/menu/cakes">Cakes</a></li></ul>',
        'footer' => '<a href="/">Home</a><a href="/contact">Contact</a>',
        'secondary' => '<a href="/about">About</a>',
        'menu-cakes' => '<a href="/cakes">Cakes</a>',
    ],
];
$regionPreconditions = [
    'sidebar' => ['enabled' => '1'],
];
$preconditions = [
    'cta_text' => ['show_cta_button' => '1'],
    'cta_url' => ['show_cta_button' => '1'],
    'cta_style' => ['show_cta_button' => '1'],
    'logo_max_height' => ['logo_image_url' => '/uploads/probe-logo.png'],
    'mobile_logo_max_height' => ['mobile_logo_url' => '/uploads/probe-mobile-logo.png'],
    'bar_bg_color' => ['show_footer_bar' => '1'],
    'bar_text_color' => ['show_footer_bar' => '1'],
    'bar_link_color' => ['show_footer_bar' => '1'],
    'bar_link_hover_color' => ['show_footer_bar' => '1'],
];

$probeValue = static function (string $key, array $definition): string {
    $type = (string)($definition['type'] ?? 'text');
    $default = $definition['default'] ?? '';
    return match ($type) {
        'color' => '#ff00ff',
        'toggle', 'boolean', 'checkbox' => ((string)$default === '1' || $default === true || $default === 1) ? '0' : '1',
        'number', 'range' => (string)((float)($default === '' ? 10 : $default) + 17),
        default => 'probe-' . str_replace('_', '-', $key),
    };
};

$probeCandidates = static function (string $key, array $definition) use ($probeValue): array {
    if ((string)($definition['type'] ?? 'text') !== 'select') {
        return [$probeValue($key, $definition)];
    }
    $default = (string)($definition['default'] ?? '');
    $candidates = [];
    foreach (($definition['options'] ?? []) as $optionKey => $optionDefinition) {
        $value = is_array($optionDefinition)
            ? (string)($optionDefinition['value'] ?? '')
            : (is_string($optionKey) ? $optionKey : (string)$optionDefinition);
        if ($value !== '' && $value !== $default && !in_array($value, $candidates, true)) {
            $candidates[] = $value;
        }
    }
    return $candidates;
};

foreach (['header', 'footer', 'sidebar'] as $region) {
    $controls = $schema['sections'][$region]['controls'] ?? [];
    $template = (string)file_get_contents($root . "/storage/cms-themes/ark/templates/regions/{$region}.disyl");
    $defaults = [];
    foreach ($controls as $key => $definition) {
        $defaults[$key] = (string)($definition['default'] ?? '');
    }

    $render = static function (array $settings) use ($engine, $template, $baseContext): ?string {
        $context = $baseContext;
        $context['section_settings'] = $settings;
        try {
            return $engine->renderString($template, $context);
        } catch (Throwable) {
            return null;
        }
    };

    foreach ($controls as $key => $definition) {
        $before = array_merge($defaults, $preconditions[$key] ?? []);
        foreach (($regionPreconditions[$region] ?? []) as $preconditionKey => $preconditionValue) {
            if ($preconditionKey !== $key) {
                $before[$preconditionKey] = $preconditionValue;
            }
        }
        $beforeOutput = $render($before);
        $honoured = false;
        foreach ($probeCandidates($key, $definition) as $candidate) {
            $after = $before;
            $after[$key] = $candidate;
            $afterOutput = $render($after);
            if ($beforeOutput !== null && $afterOutput !== null && $beforeOutput !== $afterOutput) {
                $honoured = true;
                break;
            }
        }
        honourAssert($honoured, "differential render honours {$region}.{$key}");
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
