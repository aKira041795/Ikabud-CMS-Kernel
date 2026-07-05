<?php
/**
 * ARK Safety Policy Tests
 *
 * Verifies that the published ARK safety policy exists, loads,
 * and produces no safety warnings against the live theme.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Services\ThemeManifestValidator;

$passed = 0;
$failed = 0;
$themeDir = dirname(__DIR__) . '/storage/cms-themes/ark';

function ast(string $label, mixed $condition, string $detail = ''): void
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

echo "=== ARK SAFETY POLICY ===\n";

$manifestPath = $themeDir . '/theme.manifest.json';
$policyPath = $themeDir . '/safety-policy.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true) ?: [];
$policy = json_decode((string)file_get_contents($policyPath), true) ?: [];
$policyBody = is_array($policy['policy'] ?? null) ? $policy['policy'] : [];
$rawOutput = is_array($policyBody['raw_output'] ?? null) ? $policyBody['raw_output'] : [];
$allowedKeys = is_array($rawOutput['allowed_keys'] ?? null) ? $rawOutput['allowed_keys'] : [];
$blockedPatterns = is_array($policyBody['blocked_patterns'] ?? null) ? $policyBody['blocked_patterns'] : [];

ast('theme manifest exists', is_file($manifestPath));
ast('safety policy exists', is_file($policyPath));
ast('safety policy loads', $policyBody !== []);
ast('raw output allowlist populated', $allowedKeys !== []);
ast('raw output capability requirement declared', in_array('cms.content.render_raw@1', $rawOutput['requires_capability'] ?? [], true));
ast('blocked patterns populated', $blockedPatterns !== []);
ast('onclick policy note present', str_contains((string)($policyBody['csp_note'] ?? ''), 'onclick'));

$result = ThemeManifestValidator::validate('ark', $manifest, $themeDir);
$safetyWarnings = array_values(array_filter(
    $result['warnings'] ?? [],
    static fn (string $warning): bool => str_contains($warning, 'safety-policy')
        || str_contains($warning, 'inline onclick')
        || str_contains($warning, 'blocked safety pattern')
        || str_contains($warning, 'uses |raw')
));
ast('safety policy warnings absent', $safetyWarnings === [], implode(" | ", $safetyWarnings));

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
