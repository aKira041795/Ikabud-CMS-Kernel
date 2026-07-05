<?php
/**
 * ARK Renderer Contract Tests
 *
 * Verifies that the published ARK renderer registry is present,
 * semantically valid, and warning-clean against the live theme.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Services\ThemeManifestValidator;

$passed = 0;
$failed = 0;
$themeDir = dirname(__DIR__) . '/storage/cms-themes/ark';

function art(string $label, mixed $condition, string $detail = ''): void
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

@file_put_contents(dirname(__DIR__) . '/storage/logs/app.log', '');
@file_put_contents(dirname(__DIR__) . '/storage/logs/error.log', '');

echo "=== ARK RENDERER CONTRACT ===\n";

$manifestPath = $themeDir . '/theme.manifest.json';
$registryPath = $themeDir . '/renderer-registry.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true) ?: [];
$registry = json_decode((string)file_get_contents($registryPath), true) ?: [];
$renderers = is_array($registry['renderers'] ?? null) ? $registry['renderers'] : [];

art('theme manifest exists', is_file($manifestPath));
art('renderer registry exists', is_file($registryPath));
art('renderer registry loads', $renderers !== []);
art('entity_list uses governed component', ($renderers['entity_list']['renders_as_component'] ?? '') === 'ikb_entity_list');
art('entity_detail uses governed component', ($renderers['entity_detail']['renders_as_component'] ?? '') === 'ikb_entity_detail');

foreach ($renderers as $name => $definition) {
    $hasTemplate = trim((string)($definition['template'] ?? '')) !== '';
    $hasComponent = trim((string)($definition['renders_as_component'] ?? '')) !== '';
    art("renderer {$name} has render target", $hasTemplate || $hasComponent, json_encode($definition));
    art("renderer {$name} has controls", !empty($definition['controls']) && is_array($definition['controls']), json_encode($definition));
    art("renderer {$name} has context keys", !empty($definition['context_keys']) && is_array($definition['context_keys']), json_encode($definition));
}

$result = ThemeManifestValidator::validate('ark', $manifest, $themeDir);
$rendererWarnings = array_values(array_filter(
    $result['warnings'] ?? [],
    static fn (string $warning): bool => str_contains($warning, 'renderer-registry') || str_contains($warning, 'renderer template')
));
art('renderer contract warnings absent', $rendererWarnings === [], implode(" | ", $rendererWarnings));

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
