<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

foreach (glob($root . '/kernel/DiSyL/Exceptions/*.php') as $file) {
    require_once $file;
}
foreach (glob($root . '/kernel/DiSyL/Security/*.php') as $file) {
    require_once $file;
}
foreach (glob($root . '/kernel/DiSyL/v4/AST/*.php') as $file) {
    require_once $file;
}
foreach (glob($root . '/kernel/DiSyL/v4/*.php') as $file) {
    require_once $file;
}
foreach (glob($root . '/kernel/DiSyL/CMS/*.php') as $file) {
    require_once $file;
}
foreach (glob($root . '/kernel/DiSyL/Compiler/*.php') as $file) {
    require_once $file;
}
require_once $root . '/kernel/DiSyL/Grammar.php';
require_once $root . '/kernel/DiSyL/ExpressionEvaluator.php';
require_once $root . '/kernel/DiSyL/ComponentRegistry.php';
require_once $root . '/kernel/DiSyL/TemplateEngine.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

if (!function_exists('write_log')) {
    function write_log(string $message, string $level = 'error', array $context = []): void
    {
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        file_put_contents($logDir . '/app.log', $line . PHP_EOL, FILE_APPEND);
    }
}

const INVENTORY_PATH = __DIR__ . '/../config/disyl-feature-inventory.json';
const VALID_KINDS = [
    'declarative_core',
    'governed_extension',
    'compatibility_only',
    'prohibited_application_logic',
];

function fail(array &$disagreements, string $message): void
{
    $disagreements[] = $message;
}

function splitRef(?string $ref): array
{
    if ($ref === null || $ref === '') {
        return [null, null];
    }
    $parts = explode('#', $ref, 2);
    return [$parts[0], $parts[1] ?? null];
}

function inventoryData(): array
{
    $decoded = json_decode((string) file_get_contents(INVENTORY_PATH), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Inventory JSON must decode to an array.');
    }
    return $decoded;
}

function createEngine(string $templateDir, string $cacheDir, bool $compiled): TemplateEngine
{
    $engine = new TemplateEngine($templateDir, $cacheDir, true);
    $engine->enableCompiledMode($compiled);
    return $engine;
}

function entryContext(string $id): array
{
    return match ($id) {
        'arithmetic-basic' => ['a' => 2, 'b' => 3],
        'expression-output' => ['name' => 'Alice'],
        'filter-chain' => ['name' => 'alice'],
        'for-in', 'foreach-basic' => ['items' => ['a', 'b']],
        'if-elseif-else' => ['a' => false, 'b' => true],
        'json-decode' => ['payload' => '{"a":1}'],
        'json-encode' => ['data' => ['a' => 1]],
        'string-concat' => ['a' => 'A', 'b' => 'B'],
        'ternary-basic' => ['active' => true],
        'variable-path' => ['user' => ['name' => 'Alice']],
        default => [],
    };
}

function prepareTemplateFixture(string $tmpDir, array $entry): string
{
    $id = (string) $entry['id'];
    $main = $id . '.disyl';

    if ($id === 'extends-stmt') {
        $layoutDir = $tmpDir . '/layouts';
        if (!is_dir($layoutDir)) {
            mkdir($layoutDir, 0755, true);
        }
        file_put_contents($layoutDir . '/admin.disyl', '<html>{block content}Base{/block}</html>');
    }

    if ($id === 'include-stmt') {
        $partialDir = $tmpDir . '/partials';
        if (!is_dir($partialDir)) {
            mkdir($partialDir, 0755, true);
        }
        file_put_contents($partialDir . '/header.disyl', '<p>Hi</p>');
    }

    file_put_contents($tmpDir . '/' . $main, (string) ($entry['test_template'] ?? ''));
    return $main;
}

function runDualMode(array $entry, array &$disagreements): void
{
    $id = (string) $entry['id'];
    $tmpDir = sys_get_temp_dir() . '/disyl_conformance_' . getmypid() . '_' . preg_replace('/[^a-z0-9_\-]+/i', '_', $id);
    @mkdir($tmpDir, 0755, true);
    $cacheBase = sys_get_temp_dir() . '/disyl_conformance_cache_' . getmypid() . '_' . md5($id);
    @mkdir($cacheBase, 0755, true);

    $template = prepareTemplateFixture($tmpDir, $entry);
    $context = entryContext($id);
    $expected = trim((string) $entry['expected']);

    try {
        $interpreted = createEngine($tmpDir, $cacheBase . '_i', false);
        $actualInterpreted = trim($interpreted->render($template, $context));
        if ($actualInterpreted !== $expected) {
            fail($disagreements, $id . ' interpreted expected=' . json_encode($expected) . ' actual=' . json_encode($actualInterpreted));
        }
    } catch (Throwable $e) {
        fail($disagreements, $id . ' interpreted exception: ' . $e->getMessage());
    }

    try {
        $compiled = createEngine($tmpDir, $cacheBase . '_c', true);
        $actualCompiled = trim($compiled->render($template, $context));
        if ($actualCompiled !== $expected) {
            fail($disagreements, $id . ' compiled expected=' . json_encode($expected) . ' actual=' . json_encode($actualCompiled));
        }
    } catch (Throwable $e) {
        fail($disagreements, $id . ' compiled exception: ' . $e->getMessage());
    }
}

$inventory = inventoryData();
$counts = array_fill_keys(VALID_KINDS, 0);
$disagreements = [];
$previousId = null;

foreach ($inventory as $index => $entry) {
    if (!is_array($entry)) {
        fail($disagreements, 'entry[' . $index . '] is not an object');
        continue;
    }

    $id = (string) ($entry['id'] ?? '');
    $kind = (string) ($entry['kind'] ?? '');
    $renderable = array_key_exists('renderable', $entry) ? (bool) $entry['renderable'] : true;
    $interpreted = (bool) ($entry['interpreted'] ?? false);
    $compiled = (bool) ($entry['compiled'] ?? false);
    $syntax = (string) ($entry['syntax'] ?? '');

    if ($id === '') {
        fail($disagreements, 'entry[' . $index . '] missing id');
        continue;
    }
    if ($previousId !== null && strcmp($previousId, $id) > 0) {
        fail($disagreements, 'inventory not sorted by id at ' . $id);
    }
    $previousId = $id;

    if (!in_array($kind, VALID_KINDS, true)) {
        fail($disagreements, $id . ' invalid kind=' . $kind);
        continue;
    }
    $counts[$kind]++;

    if ($kind === 'prohibited_application_logic') {
        if ($interpreted || $compiled) {
            fail($disagreements, $id . ' prohibited entry must not be executable');
        }
        if (isset($entry['test_template']) || isset($entry['expected'])) {
            fail($disagreements, $id . ' prohibited entry must not define test_template/expected');
        }
    } elseif (($interpreted || $compiled) && $renderable) {
        if (!isset($entry['test_template']) || !isset($entry['expected'])) {
            fail($disagreements, $id . ' executable renderable entry missing test_template/expected');
        }
    }

    [$ebnfPath, $ebnfRule] = splitRef((string) ($entry['ebnf_ref'] ?? ''));
    if ($ebnfPath !== null) {
        $fullEbnfPath = $root . '/' . ltrim($ebnfPath, '/');
        if (!is_file($fullEbnfPath)) {
            fail($disagreements, $id . ' missing ebnf file ' . $ebnfPath);
        } else {
            $contents = (string) file_get_contents($fullEbnfPath);
            if ($ebnfRule !== null && !preg_match('/^' . preg_quote($ebnfRule, '/') . '\s*=\s*/m', $contents)) {
                fail($disagreements, $id . ' missing ebnf rule ' . $ebnfRule);
            }
        }
    }

    [$docsPath] = splitRef((string) ($entry['docs_ref'] ?? ''));
    if ($docsPath !== null) {
        $fullDocsPath = $root . '/' . ltrim($docsPath, '/');
        if (!is_file($fullDocsPath)) {
            fail($disagreements, $id . ' missing docs file ' . $docsPath);
        } else {
            $contents = (string) file_get_contents($fullDocsPath);
            if ($syntax !== '' && strpos($contents, $syntax) === false) {
                fail($disagreements, $id . ' docs token not found: ' . $syntax);
            }
        }
    }

    if (($interpreted || $compiled) && $renderable && isset($entry['test_template'], $entry['expected'])) {
        runDualMode($entry, $disagreements);
    }
}

echo 'counts: '
    . 'declarative_core=' . $counts['declarative_core']
    . ' governed_extension=' . $counts['governed_extension']
    . ' compatibility_only=' . $counts['compatibility_only']
    . ' prohibited_application_logic=' . $counts['prohibited_application_logic']
    . PHP_EOL;

if ($disagreements !== []) {
    foreach ($disagreements as $line) {
        echo '- ' . $line . PHP_EOL;
    }
    exit(1);
}

echo 'disagreements: none' . PHP_EOL;
exit(0);
