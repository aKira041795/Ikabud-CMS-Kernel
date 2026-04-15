<?php
/**
 * R37 — Hydration End-to-End Test
 *
 * Verifies the Islands architecture: Island render → serialize manifest
 * → hydrate script generation → proper escaping.
 *
 * Run: php tests/disyl_hydration_e2e_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// Load hydration classes
require_once __DIR__ . '/../kernel/DiSyL/Hydration/HydrationStrategy.php';
require_once __DIR__ . '/../kernel/DiSyL/Hydration/Island.php';
require_once __DIR__ . '/../kernel/DiSyL/Hydration/IslandRegistry.php';
require_once __DIR__ . '/../kernel/DiSyL/Hydration/IslandManifest.php';
require_once __DIR__ . '/../kernel/DiSyL/Hydration/IslandRenderer.php';
require_once __DIR__ . '/../kernel/DiSyL/Hydration/ClientBundleGenerator.php';

use Ikabud\Kernel\DiSyL\Hydration\HydrationStrategy;
use Ikabud\Kernel\DiSyL\Hydration\Island;
use Ikabud\Kernel\DiSyL\Hydration\IslandRegistry;
use Ikabud\Kernel\DiSyL\Hydration\IslandManifest;
use Ikabud\Kernel\DiSyL\Hydration\IslandRenderer;
use Ikabud\Kernel\DiSyL\Hydration\ClientBundleGenerator;

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "=== Hydration End-to-End ===\n\n";

// ── Test 1: IslandManifest generation + JSON_HEX_TAG ──
echo "--- IslandManifest ---\n";
$registry = new IslandRegistry();

$counter = new Island('counter', '/components/counter.js', ['count' => 0], HydrationStrategy::IDLE);
$form = new Island('form', '/components/form.js', ['action' => '</script><xss>'], HydrationStrategy::VISIBLE);
$registry->register($counter);
$registry->register($form);
$registry->registerComponent('/components/counter.js', '/modules/counter/counter.js');
$registry->registerComponent('/components/form.js', '/modules/form/form.js');

$manifest = new IslandManifest($registry);
$script = $manifest->generateScriptTag();
t('manifest generates script tag', str_contains($script, '<script'));
t('manifest contains island data', str_contains($script, 'counter'));
t('JSON_HEX_TAG prevents script breakout', !str_contains($script, '</script><xss>'));
t('script tag properly closed', str_contains($script, '</script>'));

$jsonData = json_decode($manifest->generate(), true);
t('manifest contains both islands', count($jsonData['islands'] ?? []) === 2);

// ── Test 2: Island class ──
echo "\n--- Island ---\n";
$island = new Island('my-widget', '/js/widget.js', ['title' => 'Hello'], HydrationStrategy::MEDIA, '(min-width: 768px)');

t('island has correct id', $island->id === 'my-widget');
t('island has correct strategy', $island->strategy === HydrationStrategy::MEDIA);
t('island has media query', $island->mediaQuery === '(min-width: 768px)');

// ── Test 3: IslandRenderer HTML output ──
echo "\n--- IslandRenderer ---\n";
$reg2 = new IslandRegistry();
$renderer = new IslandRenderer($reg2);
$html = $renderer->render($island);
t('renderer produces HTML', is_string($html) && strlen($html) > 0);
t('renderer includes data-island attribute', str_contains($html, 'data-island'));
t('renderer includes component path', str_contains($html, 'widget.js'));

// Test XSS prevention in mediaQuery — quotes are escaped to &quot;
// so the attacker's payload stays inside the attribute value
$xssIsland = new Island('xss-test', '/js/test.js', [], HydrationStrategy::MEDIA, '" onload="alert(1)');
$xssHtml = $renderer->render($xssIsland);
t('mediaQuery XSS quotes are escaped', str_contains($xssHtml, '&quot;') && !str_contains($xssHtml, 'onload="alert'));

// ── Test 4: ClientBundleGenerator ──
echo "\n--- ClientBundleGenerator ---\n";
$reg3 = new IslandRegistry();
$c1 = new Island('c1', '/components/counter.js', [], HydrationStrategy::LOAD);
$c2 = new Island('c2', '/components/form.js', [], HydrationStrategy::IDLE);
$reg3->register($c1);
$reg3->register($c2);
$reg3->registerComponent('/components/counter.js', '/modules/counter.js');
$reg3->registerComponent('/components/form.js', '/modules/form.js');

$generator = new ClientBundleGenerator($reg3, '/runtime/hydration.js');
$scripts = $generator->generateScripts();
t('generator produces scripts', is_string($scripts) && strlen($scripts) > 0);
t('generator includes runtime path', str_contains($scripts, 'hydration.js'));

// Test XSS prevention in runtime path
$reg4 = new IslandRegistry();
$xssGen = new ClientBundleGenerator($reg4, '"><script>alert(1)</script>');
$xssScripts = $xssGen->generateScripts();
t('runtime path XSS is escaped', !str_contains($xssScripts, '<script>alert'));

// ── Test 5: HydrationStrategy values ──
echo "\n--- HydrationStrategy ---\n";
t('IDLE strategy exists', HydrationStrategy::IDLE->value === 'idle');
t('VISIBLE strategy exists', HydrationStrategy::VISIBLE->value === 'visible');
t('MEDIA strategy exists', HydrationStrategy::MEDIA->value === 'media');
t('INTERACTION strategy exists', HydrationStrategy::INTERACTION->value === 'interaction');
t('NEVER strategy exists', HydrationStrategy::NEVER->value === 'never');
t('LOAD strategy exists', HydrationStrategy::LOAD->value === 'load');
t('IMMEDIATE strategy exists', HydrationStrategy::IMMEDIATE->value === 'immediate');

// ── Summary ──
echo "\n{$pass} passed, {$fail} failed\n";
if (!empty($errors)) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
exit($fail > 0 ? 1 : 0);
