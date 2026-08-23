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
const LSP_VALIDATOR_PATH = __DIR__ . '/../extensions/disyl-lsp/src/validator.ts';
const VALID_KINDS = [
    'declarative_core',
    'governed_extension',
    'compatibility_only',
    'prohibited_application_logic',
];
const VALID_RESOURCE_LIMITS = ['bounded', 'not-applicable'];
const LSP_BLOCK_OPEN_BY_ID = [
    'block-define' => '{block ',
    'foreach-basic' => '{foreach ',
    'foreach-else' => '{else}',
    'for-c-style' => '{for ',
    'for-in' => '{for ',
    'if-elseif-else' => '{if ',
    'literal-block' => '{literal}',
    'macro-def' => '{macro ',
    'verbatim-block' => '{verbatim}',
    'while-loop' => '{while ',
];
const LSP_KEYWORD_BY_ID = [
    'extends-stmt' => 'extends',
    'include-stmt' => 'include',
    'math-tag' => 'math',
    'set-basic' => 'set',
    'set-typed' => 'set',
];
const LSP_COMPONENT_BY_ID = [
    'component-tag' => 'ikb_text',
];
const PROHIBITED_LSP_TOKEN_BY_ID = [
    'prohibited-arbitrary-php' => '<?php',
    'prohibited-error-suppression' => '@',
    'prohibited-instanceof' => 'instanceof',
    'prohibited-nullsafe-property' => '?->',
    'prohibited-php-concat-dot' => '.',
    'prohibited-spaceship' => '<=>',
];

function fail(array &$disagreements, string $message): void
{
    $disagreements[] = $message;
}

function markSurface(array &$promotion, string $id, string $surface, string $state): void
{
    $promotion[$id]['surfaces'][$surface] = $state;
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

function parseLspSurface(): array
{
    $source = (string) file_get_contents(LSP_VALIDATOR_PATH);

    preg_match_all("/open:\s*'([^']+)'/", $source, $blockMatches);
    preg_match('/const GOV_COMPONENTS = \[(.*?)\];/s', $source, $componentMatch);
    $keywordLine = '';
    if (preg_match('/^\s*const keywordRe = .*$/m', $source, $keywordLineMatch) === 1) {
        $keywordLine = $keywordLineMatch[0];
    }

    $components = [];
    if (isset($componentMatch[1])) {
        preg_match_all("/'([^']+)'/", $componentMatch[1], $componentNames);
        $components = $componentNames[1] ?? [];
    }

    $keywords = [];
    $start = strpos($keywordLine, '{(');
    $end = strpos($keywordLine, ')([^\\s}])');
    if ($start !== false && $end !== false && $end > $start + 2) {
        $keywords = explode('|', substr($keywordLine, $start + 2, $end - ($start + 2)));
    }
    $blockOpens = $blockMatches[1] ?? [];

    return [
        'block_opens' => array_values(array_unique($blockOpens)),
        'block_tokens' => array_values(array_unique(array_map(static fn(string $open): string => trim($open, "{} "), $blockOpens))),
        'components' => array_values(array_unique($components)),
        'keywords' => array_values(array_unique($keywords)),
    ];
}

function hasLspJustification(array $entry): bool
{
    $notes = strtolower((string) ($entry['notes'] ?? ''));
    return str_contains($notes, 'not-applicable')
        || str_contains($notes, 'lsp not-applicable')
        || preg_match('/\blsp\s*:/', $notes) === 1;
}

function hasResourceLimitJustification(array $entry): bool
{
    return str_contains(strtolower((string) ($entry['notes'] ?? '')), 'not-applicable');
}

function expressionTokenForEntry(array $entry): string
{
    return match ((string) $entry['id']) {
        'arithmetic-basic' => '+',
        'bitwise-and' => '&',
        'bitwise-shift-left' => '<<',
        'bitwise-shift-right' => '>>',
        'bitwise-xor' => '^',
        'compound-assignment' => '+=',
        'expression-output' => 'name',
        'filter-chain' => 'upper',
        'json-decode' => 'json_decode',
        'json-encode' => 'json_encode',
        'null-coalesce' => '??',
        'postfix-stmt' => '++',
        'string-concat' => '~',
        'ternary-basic' => '?',
        'variable-path' => 'user',
        default => '',
    };
}

function checkLspSurface(array $inventory, array &$disagreements, array &$promotion): array
{
    $surface = parseLspSurface();
    $blockOpenSet = array_fill_keys($surface['block_opens'], true);
    $blockTokenSet = array_fill_keys($surface['block_tokens'], true);
    $keywordSet = array_fill_keys($surface['keywords'], true);
    $componentSet = array_fill_keys($surface['components'], true);

    $summary = [
        'block' => 0,
        'keyword' => 0,
        'component' => 0,
        'expression' => 0,
        'prohibited_not_blessed' => 0,
        'not_applicable' => 0,
        'disagreements' => 0,
    ];

    foreach ($inventory as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $id = (string) ($entry['id'] ?? '');
        $kind = (string) ($entry['kind'] ?? '');
        $lsp = (bool) ($entry['lsp'] ?? false);

        if (!$lsp) {
            $summary['not_applicable']++;
            if (!hasLspJustification($entry)) {
                markSurface($promotion, $id, 'lsp', 'fail');
                fail($disagreements, $id . ' lsp=false requires explicit not-applicable justification in notes');
            } else {
                markSurface($promotion, $id, 'lsp', 'not_applicable');
            }

            if ($kind === 'prohibited_application_logic') {
                $token = PROHIBITED_LSP_TOKEN_BY_ID[$id] ?? (string) ($entry['syntax'] ?? '');
                if (isset($blockOpenSet[$token]) || isset($blockTokenSet[$token]) || isset($keywordSet[$token]) || isset($componentSet[$token])) {
                    markSurface($promotion, $id, 'lsp', 'fail');
                    fail($disagreements, $id . ' prohibited token unexpectedly recognized by LSP surface: ' . $token);
                } else {
                    $summary['prohibited_not_blessed']++;
                }
            }
            continue;
        }

        if ($kind === 'prohibited_application_logic') {
            markSurface($promotion, $id, 'lsp', 'fail');
            fail($disagreements, $id . ' lsp=true prohibited entry is invalid');
            continue;
        }

        $resolved = true;
        if (isset(LSP_BLOCK_OPEN_BY_ID[$id])) {
            $summary['block']++;
            $open = LSP_BLOCK_OPEN_BY_ID[$id];
            if (!isset($blockOpenSet[$open])) {
                $resolved = false;
                fail($disagreements, $id . ' missing LSP block surface ' . $open);
            }
        } elseif (isset(LSP_KEYWORD_BY_ID[$id])) {
            $summary['keyword']++;
            $keyword = LSP_KEYWORD_BY_ID[$id];
            if (!isset($keywordSet[$keyword])) {
                $resolved = false;
                fail($disagreements, $id . ' missing LSP keyword surface ' . $keyword);
            }
        } elseif (isset(LSP_COMPONENT_BY_ID[$id])) {
            $summary['component']++;
            $component = LSP_COMPONENT_BY_ID[$id];
            if (!isset($componentSet[$component])) {
                $resolved = false;
                fail($disagreements, $id . ' missing LSP component surface ' . $component);
            }
        } else {
            $summary['expression']++;
            $token = expressionTokenForEntry($entry);
            if ($token === '') {
                $resolved = false;
                fail($disagreements, $id . ' lsp=true entry has no deterministic LSP surface resolution');
            } elseif (isset($blockOpenSet[$token]) || isset($blockTokenSet[$token]) || isset($keywordSet[$token]) || isset($componentSet[$token])) {
                $resolved = false;
                fail($disagreements, $id . ' expression token collides with structured LSP surface: ' . $token);
            }
        }

        markSurface($promotion, $id, 'lsp', $resolved ? 'pass' : 'fail');
    }

    $summary['disagreements'] = count($disagreements);
    return $summary;
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

function runDualMode(array $entry, array &$disagreements, array &$promotion): void
{
    $id = (string) $entry['id'];
    $tmpDir = sys_get_temp_dir() . '/disyl_conformance_' . getmypid() . '_' . preg_replace('/[^a-z0-9_\-]+/i', '_', $id);
    @mkdir($tmpDir, 0755, true);
    $cacheBase = sys_get_temp_dir() . '/disyl_conformance_cache_' . getmypid() . '_' . md5($id);
    @mkdir($cacheBase, 0755, true);

    $template = prepareTemplateFixture($tmpDir, $entry);
    $context = entryContext($id);
    $expected = trim((string) $entry['expected']);
    $ok = true;

    try {
        $interpreted = createEngine($tmpDir, $cacheBase . '_i', false);
        $actualInterpreted = trim($interpreted->render($template, $context));
        if ($actualInterpreted !== $expected) {
            $ok = false;
            fail($disagreements, $id . ' interpreted expected=' . json_encode($expected) . ' actual=' . json_encode($actualInterpreted));
        }
    } catch (Throwable $e) {
        $ok = false;
        fail($disagreements, $id . ' interpreted exception: ' . $e->getMessage());
    }

    try {
        $compiled = createEngine($tmpDir, $cacheBase . '_c', true);
        $actualCompiled = trim($compiled->render($template, $context));
        if ($actualCompiled !== $expected) {
            $ok = false;
            fail($disagreements, $id . ' compiled expected=' . json_encode($expected) . ' actual=' . json_encode($actualCompiled));
        }
    } catch (Throwable $e) {
        $ok = false;
        fail($disagreements, $id . ' compiled exception: ' . $e->getMessage());
    }

    markSurface($promotion, $id, 'dual_mode', $ok ? 'pass' : 'fail');
}

function validateResourceLimitEntry(array $entry, array &$disagreements, array &$promotion): void
{
    $id = (string) ($entry['id'] ?? '');
    $value = (string) ($entry['resource_limit'] ?? '');
    $notes = (string) ($entry['notes'] ?? '');

    if (!in_array($value, VALID_RESOURCE_LIMITS, true)) {
        markSurface($promotion, $id, 'resource_limit', 'fail');
        fail($disagreements, $id . ' invalid resource_limit=' . $value);
        return;
    }

    if ($value === 'not-applicable') {
        if (!hasResourceLimitJustification($entry)) {
            markSurface($promotion, $id, 'resource_limit', 'fail');
            fail($disagreements, $id . ' resource_limit not-applicable requires justification in notes');
            return;
        }
        markSurface($promotion, $id, 'resource_limit', 'not_applicable');
        return;
    }

    if ($id === 'while-loop' && !str_contains($notes, '10000') && !str_contains($notes, '100000')) {
        fail($disagreements, $id . ' bounded resource_limit note must mention interpreted/compiled loop guards');
        markSurface($promotion, $id, 'resource_limit', 'fail');
        return;
    }
    if ($id === 'for-c-style' && !str_contains($notes, '10000') && !str_contains($notes, '100000')) {
        fail($disagreements, $id . ' bounded resource_limit note must mention interpreted/compiled loop guards');
        markSurface($promotion, $id, 'resource_limit', 'fail');
        return;
    }
    if (in_array($id, ['foreach-basic', 'foreach-else', 'for-in'], true) && !str_contains(strtolower($notes), 'naturally bounded by iterable')) {
        fail($disagreements, $id . ' bounded resource_limit note must mention iterable bound');
        markSurface($promotion, $id, 'resource_limit', 'fail');
        return;
    }

    markSurface($promotion, $id, 'resource_limit', 'pass');
}

function checkSourceGuard(string $label, string $path, array $needles, array &$disagreements): bool
{
    $source = (string) file_get_contents($path);
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            fail($disagreements, $label . ' missing guard token ' . $needle);
            return false;
        }
    }
    return true;
}

function checkResourceLimitSources(string $root, array &$disagreements): array
{
    return [
        'template_while_guard' => checkSourceGuard(
            'TemplateEngine::evaluateWhileBody',
            $root . '/kernel/DiSyL/TemplateEngine.php',
            ['private function evaluateWhileBody', '$maxIterations = 10000;', 'DiSyL {while} loop exceeded max iterations'],
            $disagreements
        ),
        'template_for_guard' => checkSourceGuard(
            'TemplateEngine::evaluateForBody',
            $root . '/kernel/DiSyL/TemplateEngine.php',
            ['private function evaluateForBody', '$maxIterations = 10000;'],
            $disagreements
        ),
        'compiler_loop_guard' => checkSourceGuard(
            'TemplateCompiler loop guards',
            $root . '/kernel/DiSyL/Compiler/TemplateCompiler.php',
            ['public const MAX_LOOP_ITERATIONS = 100000;', 'DiSyL while-loop exceeded max iterations', 'DiSyL loop exceeded max iterations'],
            $disagreements
        ),
        'include_guard' => checkSourceGuard(
            'IncludeResolver include-pass guard',
            $root . '/kernel/DiSyL/Component/IncludeResolver.php',
            ['private const MAX_INCLUDE_ITERATIONS = 20;', 'while ($iteration < self::MAX_INCLUDE_ITERATIONS)'],
            $disagreements
        ),
        'parser_guard' => checkSourceGuard(
            'Parser depth guard',
            $root . '/kernel/DiSyL/v4/Parser.php',
            ['private const MAX_PARSE_DEPTH = 256;', 'template nesting exceeds max parse depth', 'expression nesting exceeds max parse depth'],
            $disagreements
        ),
        'extends_guard' => checkSourceGuard(
            'ExtendsProcessor chain-depth guard',
            $root . '/kernel/DiSyL/Component/ExtendsProcessor.php',
            ['private const EXTENDS_CHAIN_MAX = 20;', 'Extends chain depth exceeded maximum'],
            $disagreements
        ),
        'sandbox_guard' => checkSourceGuard(
            'Sandbox resource guard',
            $root . '/kernel/DiSyL/Security/Sandbox.php',
            ['private float $defaultCpuLimitS = 5.0;', 'private int $defaultMemLimitBytes = 16 * 1024 * 1024;', 'public function pop(): void', 'SANDBOX_CPU_LIMIT', 'SANDBOX_MEM_LIMIT'],
            $disagreements
        ),
    ];
}

function proveWhileLoopRuntimeGuards(array &$disagreements): array
{
    $tmpDir = sys_get_temp_dir() . '/disyl_resource_guard_' . getmypid();
    $cacheDir = $tmpDir . '/cache';
    @mkdir($tmpDir, 0755, true);
    @mkdir($cacheDir, 0755, true);
    file_put_contents($tmpDir . '/unbounded.disyl', '{while 1}{/while}');

    $interpretedCompleted = false;
    $compiledThrew = false;
    $compiledMessage = '';

    try {
        $engine = createEngine($tmpDir, $cacheDir . '_i', false);
        $engine->render('unbounded.disyl', []);
        $interpretedCompleted = true;
    } catch (Throwable $e) {
        fail($disagreements, 'resource runtime proof interpreted while-loop did not complete: ' . $e->getMessage());
    }

    try {
        $engine = createEngine($tmpDir, $cacheDir . '_c', true);
        $engine->render('unbounded.disyl', []);
        fail($disagreements, 'resource runtime proof compiled while-loop did not throw max-iterations RuntimeException');
    } catch (RuntimeException $e) {
        $compiledMessage = $e->getMessage();
        if (str_contains(strtolower($compiledMessage), 'max iterations')) {
            $compiledThrew = true;
        } else {
            fail($disagreements, 'resource runtime proof compiled while-loop threw unexpected message: ' . $compiledMessage);
        }
    } catch (Throwable $e) {
        fail($disagreements, 'resource runtime proof compiled while-loop threw unexpected exception: ' . $e->getMessage());
    }

    if (!$interpretedCompleted) {
        fail($disagreements, 'resource runtime proof interpreted while-loop failed to terminate');
    }
    if (!$compiledThrew) {
        fail($disagreements, 'resource runtime proof compiled while-loop failed to prove max-iterations throw');
    }

    return [
        'interpreted_completed' => $interpretedCompleted,
        'compiled_threw' => $compiledThrew,
        'compiled_message' => $compiledMessage,
    ];
}

function summarizePromotion(array &$promotion): array
{
    $promoted = 0;
    $partial = 0;

    foreach ($promotion as $id => &$row) {
        $states = $row['surfaces'];
        $row['promoted'] = !in_array('fail', $states, true);
        if ($row['promoted']) {
            $promoted++;
        } else {
            $partial++;
        }
    }
    unset($row);

    return ['promoted' => $promoted, 'partial' => $partial];
}

$inventory = inventoryData();
$counts = array_fill_keys(VALID_KINDS, 0);
$disagreements = [];
$previousId = null;
$promotion = [];

foreach ($inventory as $index => $entry) {
    if (!is_array($entry)) {
        fail($disagreements, 'entry[' . $index . '] is not an object');
        continue;
    }

    $id = (string) ($entry['id'] ?? '');
    if ($id !== '') {
        $promotion[$id] = ['surfaces' => []];
    }
}

foreach ($inventory as $index => $entry) {
    if (!is_array($entry)) {
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
        markSurface($promotion, $id, 'inventory', 'fail');
        continue;
    }
    $counts[$kind]++;
    markSurface($promotion, $id, 'inventory', 'pass');

    if ($kind === 'prohibited_application_logic') {
        if ($interpreted || $compiled) {
            fail($disagreements, $id . ' prohibited entry must not be executable');
            markSurface($promotion, $id, 'dual_mode', 'fail');
        } else {
            markSurface($promotion, $id, 'dual_mode', 'not_applicable');
        }
        if (isset($entry['test_template']) || isset($entry['expected'])) {
            fail($disagreements, $id . ' prohibited entry must not define test_template/expected');
        }
    } elseif (($interpreted || $compiled) && $renderable) {
        if (!isset($entry['test_template']) || !isset($entry['expected'])) {
            fail($disagreements, $id . ' executable renderable entry missing test_template/expected');
            markSurface($promotion, $id, 'dual_mode', 'fail');
        }
    } else {
        markSurface($promotion, $id, 'dual_mode', ($interpreted === $compiled) ? 'pass' : 'fail');
        if ($interpreted !== $compiled) {
            fail($disagreements, $id . ' dual-mode declaration mismatch interpreted=' . ($interpreted ? 'true' : 'false') . ' compiled=' . ($compiled ? 'true' : 'false'));
        }
    }

    [$ebnfPath, $ebnfRule] = splitRef((string) ($entry['ebnf_ref'] ?? ''));
    $ebnfOk = true;
    if ($ebnfPath !== null) {
        $fullEbnfPath = $root . '/' . ltrim($ebnfPath, '/');
        if (!is_file($fullEbnfPath)) {
            $ebnfOk = false;
            fail($disagreements, $id . ' missing ebnf file ' . $ebnfPath);
        } else {
            $contents = (string) file_get_contents($fullEbnfPath);
            if ($ebnfRule !== null && !preg_match('/^' . preg_quote($ebnfRule, '/') . '\s*=\s*/m', $contents)) {
                $ebnfOk = false;
                fail($disagreements, $id . ' missing ebnf rule ' . $ebnfRule);
            }
        }
    }
    markSurface($promotion, $id, 'ebnf', $ebnfOk ? 'pass' : 'fail');

    [$docsPath] = splitRef((string) ($entry['docs_ref'] ?? ''));
    $docsOk = true;
    if ($docsPath !== null) {
        $fullDocsPath = $root . '/' . ltrim($docsPath, '/');
        if (!is_file($fullDocsPath)) {
            $docsOk = false;
            fail($disagreements, $id . ' missing docs file ' . $docsPath);
        } else {
            $contents = (string) file_get_contents($fullDocsPath);
            if ($syntax !== '' && strpos($contents, $syntax) === false) {
                $docsOk = false;
                fail($disagreements, $id . ' docs token not found: ' . $syntax);
            }
        }
    }
    markSurface($promotion, $id, 'docs', $docsOk ? 'pass' : 'fail');

    validateResourceLimitEntry($entry, $disagreements, $promotion);

    if (($interpreted || $compiled) && $renderable && isset($entry['test_template'], $entry['expected'])) {
        runDualMode($entry, $disagreements, $promotion);
    }
}

$lspSummary = checkLspSurface($inventory, $disagreements, $promotion);
$sourceGuardSummary = checkResourceLimitSources($root, $disagreements);
$runtimeProof = proveWhileLoopRuntimeGuards($disagreements);
if (!($runtimeProof['interpreted_completed'] && $runtimeProof['compiled_threw'])) {
    markSurface($promotion, 'while-loop', 'resource_runtime', 'fail');
} else {
    markSurface($promotion, 'while-loop', 'resource_runtime', 'pass');
}

$promotionSummary = summarizePromotion($promotion);
$laneGreen = ($disagreements === [] && $promotionSummary['partial'] === 0) ? 'YES' : 'PARTIAL';

echo 'counts: '
    . 'declarative_core=' . $counts['declarative_core']
    . ' governed_extension=' . $counts['governed_extension']
    . ' compatibility_only=' . $counts['compatibility_only']
    . ' prohibited_application_logic=' . $counts['prohibited_application_logic']
    . PHP_EOL;

echo 'lsp: '
    . 'block=' . $lspSummary['block']
    . ' keyword=' . $lspSummary['keyword']
    . ' component=' . $lspSummary['component']
    . ' expression=' . $lspSummary['expression']
    . ' prohibited_not_blessed=' . $lspSummary['prohibited_not_blessed']
    . ' not_applicable=' . $lspSummary['not_applicable']
    . ' disagreements=' . $lspSummary['disagreements']
    . PHP_EOL;

foreach ($promotion as $id => &$row) {
    ksort($row['surfaces']);
    $parts = [];
    foreach ($row['surfaces'] as $surface => $state) {
        $parts[] = $surface . '=' . $state;
    }
    echo 'promotion_matrix: id=' . $id . ' ' . implode(' ', $parts) . ' promoted=' . ($row['promoted'] ? 'yes' : 'no') . PHP_EOL;
}
unset($row);

echo 'promotion: promoted=' . $promotionSummary['promoted'] . ' partial=' . $promotionSummary['partial'] . PHP_EOL;
echo 'poc5: lane_green=' . $laneGreen
    . ' source_guards=' . (count(array_filter($sourceGuardSummary)) === count($sourceGuardSummary) ? 'PASS' : 'FAIL')
    . ' runtime_proof_interpreted=' . ($runtimeProof['interpreted_completed'] ? 'PASS' : 'FAIL')
    . ' runtime_proof_compiled=' . ($runtimeProof['compiled_threw'] ? 'PASS' : 'FAIL')
    . PHP_EOL;

if ($disagreements !== []) {
    foreach ($disagreements as $line) {
        echo '- ' . $line . PHP_EOL;
    }
    exit(1);
}

echo 'disagreements: none' . PHP_EOL;
exit(0);
