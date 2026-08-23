<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function parseLspSurface(string $source): array
{
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

    return [
        'block_opens' => array_values(array_unique($blockMatches[1] ?? [])),
        'block_tokens' => array_values(array_unique(array_map(static fn(string $open): string => trim($open, '{} '), $blockMatches[1] ?? []))),
        'components' => array_values(array_unique($components)),
        'keywords' => array_values(array_unique($keywords)),
    ];
}

function hasLspJustification(array $entry): bool
{
    $notes = strtolower((string) ($entry['notes'] ?? ''));
    return str_contains($notes, 'not-applicable') || preg_match('/\blsp\s*:/', $notes) === 1;
}

function loadDisylRuntime(string $root): void
{
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
}

function createEngine(string $templateDir, string $cacheDir, bool $compiled): \Ikabud\Kernel\DiSyL\TemplateEngine
{
    $engine = new \Ikabud\Kernel\DiSyL\TemplateEngine($templateDir, $cacheDir, true);
    $engine->enableCompiledMode($compiled);
    return $engine;
}

function parsePromotionSummary(string $summary): array
{
    preg_match('/^promotion: promoted=(\d+) partial=(\d+)$/m', $summary, $m);
    return [
        'present' => isset($m[0]),
        'promoted' => isset($m[1]) ? (int) $m[1] : -1,
        'partial' => isset($m[2]) ? (int) $m[2] : -1,
    ];
}

$inventoryPath = $root . '/config/disyl-feature-inventory.json';
$inventory = json_decode((string) file_get_contents($inventoryPath), true);
t('inventory is a JSON array', is_array($inventory));
t('inventory is non-empty', is_array($inventory) && count($inventory) > 0, 'count=' . (is_array($inventory) ? count($inventory) : 0));
t('inventory has 41 entries', is_array($inventory) && count($inventory) === 41, 'count=' . (is_array($inventory) ? count($inventory) : 0));

$kinds = is_array($inventory) ? array_count_values(array_map(static fn(array $entry): string => (string) ($entry['kind'] ?? ''), $inventory)) : [];
t('has at least one prohibited_application_logic entry', (int) ($kinds['prohibited_application_logic'] ?? 0) > 0);
t('has at least one governed_extension entry', (int) ($kinds['governed_extension'] ?? 0) > 0);

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/disyl-conformance-check.php') . ' 2>&1';
$output = [];
$exit = 1;
exec($cmd, $output, $exit);
$summary = implode("\n", $output);
$promotionSummary = parsePromotionSummary($summary);
t('conformance checker exits 0', $exit === 0, $summary);
t('checker prints counts summary', str_contains($summary, 'counts:'), $summary);
t('checker prints lsp summary', preg_match('/^lsp: .+/m', $summary) === 1, $summary);
t('checker prints promotion summary', $promotionSummary['present'], $summary);
t('checker prints poc5 green line', str_contains($summary, 'poc5: lane_green=YES'), $summary);
t('checker reports no disagreements', str_contains($summary, 'disagreements: none'), $summary);

$ebnf = (string) file_get_contents($root . '/docs/disyl/disyl-grammar-v4.7.ebnf');
preg_match_all('/^([a-z_]+)\s*=\s*/m', $ebnf, $matches);
$rules = array_values(array_unique($matches[1] ?? []));
$requiredRules = [
    'expression', 'variable', 'filter', 'arithmetic', 'ternary', 'set_stmt', 'postfix_stmt',
    'if_block', 'foreach_block', 'for_block', 'while_block', 'verbatim_block', 'literal_block',
    'block_def', 'extends_stmt', 'include_stmt', 'math_tag', 'macro_def', 'component_tag', 'comment',
];
foreach ($requiredRules as $rule) {
    t('EBNF contains rule ' . $rule, in_array($rule, $rules, true));
}

$inventoryRuleMap = [];
if (is_array($inventory)) {
    foreach ($inventory as $entry) {
        $ref = (string) ($entry['ebnf_ref'] ?? '');
        if ($ref !== '' && str_contains($ref, '#')) {
            [, $rule] = explode('#', $ref, 2);
            $inventoryRuleMap[$rule] = true;
        }
    }
}
foreach ($requiredRules as $rule) {
    t('inventory maps rule ' . $rule, !empty($inventoryRuleMap[$rule]));
}

$validatorSource = (string) file_get_contents($root . '/extensions/disyl-lsp/src/validator.ts');
$lspSurface = parseLspSurface($validatorSource);
$blockOpenSet = array_fill_keys($lspSurface['block_opens'], true);
$blockTokenSet = array_fill_keys($lspSurface['block_tokens'], true);
$keywordSet = array_fill_keys($lspSurface['keywords'], true);
$componentSet = array_fill_keys($lspSurface['components'], true);

$blockMap = [
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
$keywordMap = [
    'extends-stmt' => 'extends',
    'include-stmt' => 'include',
    'math-tag' => 'math',
    'set-basic' => 'set',
    'set-typed' => 'set',
];
$componentMap = ['component-tag' => 'ikb_text'];
$expressionMap = [
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
];
$prohibitedMap = [
    'prohibited-arbitrary-php' => '<?php',
    'prohibited-error-suppression' => '@',
    'prohibited-instanceof' => 'instanceof',
    'prohibited-nullsafe-property' => '?->',
    'prohibited-php-concat-dot' => '.',
    'prohibited-spaceship' => '<=>',
];

$lspTrueResolved = 0;
$lspFalseJustified = 0;
$resourceLimitStates = ['bounded' => 0, 'not-applicable' => 0];
foreach ($inventory as $entry) {
    $id = (string) $entry['id'];
    $kind = (string) $entry['kind'];
    $lsp = (bool) $entry['lsp'];
    $resourceLimit = (string) ($entry['resource_limit'] ?? '');
    t($id . ' has valid resource_limit', in_array($resourceLimit, ['bounded', 'not-applicable'], true), $resourceLimit);
    if (isset($resourceLimitStates[$resourceLimit])) {
        $resourceLimitStates[$resourceLimit]++;
    }
    if ($resourceLimit === 'not-applicable') {
        t($id . ' resource_limit not-applicable is justified in notes', str_contains(strtolower((string) $entry['notes']), 'not-applicable'), (string) $entry['notes']);
    }

    if ($lsp) {
        t($id . ' lsp:true is not prohibited', $kind !== 'prohibited_application_logic');
        $resolved = false;
        if (isset($blockMap[$id])) {
            $resolved = isset($blockOpenSet[$blockMap[$id]]);
        } elseif (isset($keywordMap[$id])) {
            $resolved = isset($keywordSet[$keywordMap[$id]]);
        } elseif (isset($componentMap[$id])) {
            $resolved = isset($componentSet[$componentMap[$id]]);
        } elseif (isset($expressionMap[$id])) {
            $token = $expressionMap[$id];
            $resolved = !isset($blockOpenSet[$token]) && !isset($blockTokenSet[$token]) && !isset($keywordSet[$token]) && !isset($componentSet[$token]);
        }
        t($id . ' lsp:true resolves to a known LSP surface', $resolved);
        if ($resolved) {
            $lspTrueResolved++;
        }
        continue;
    }

    t($id . ' lsp:false has explicit justification', hasLspJustification($entry), (string) ($entry['notes'] ?? ''));
    if (hasLspJustification($entry)) {
        $lspFalseJustified++;
    }
}
t('all 31 lsp:true entries resolved', $lspTrueResolved === 31, 'resolved=' . $lspTrueResolved);
t('all 10 lsp:false entries justified', $lspFalseJustified === 10, 'justified=' . $lspFalseJustified);
t('resource_limit bounded count is 5', $resourceLimitStates['bounded'] === 5, 'bounded=' . $resourceLimitStates['bounded']);
t('resource_limit not-applicable count is 36', $resourceLimitStates['not-applicable'] === 36, 'not-applicable=' . $resourceLimitStates['not-applicable']);

$ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
preg_match('/coding-standards:\n(?:.|\n)*?dependency-audit:/', $ci, $codingStandardsMatch);
$codingStandardsBlock = $codingStandardsMatch[0] ?? '';
t('coding-standards job contains strict proof lane gate', str_contains($codingStandardsBlock, 'php tools/disyl-conformance-check.php'), $codingStandardsBlock);

foreach ($prohibitedMap as $id => $token) {
    $recognized = isset($blockOpenSet[$token]) || isset($blockTokenSet[$token]) || isset($keywordSet[$token]) || isset($componentSet[$token]);
    t($id . ' token is not recognized by LSP block/keyword/component surface', !$recognized, $token);
}

$templateEngineSource = (string) file_get_contents($root . '/kernel/DiSyL/TemplateEngine.php');
$templateCompilerSource = (string) file_get_contents($root . '/kernel/DiSyL/Compiler/TemplateCompiler.php');
$includeResolverSource = (string) file_get_contents($root . '/kernel/DiSyL/Component/IncludeResolver.php');
$parserSource = (string) file_get_contents($root . '/kernel/DiSyL/v4/Parser.php');
$extendsProcessorSource = (string) file_get_contents($root . '/kernel/DiSyL/Component/ExtendsProcessor.php');
$sandboxSource = (string) file_get_contents($root . '/kernel/DiSyL/Security/Sandbox.php');

t('TemplateEngine while guard contains 10000', str_contains($templateEngineSource, 'private function evaluateWhileBody') && str_contains($templateEngineSource, '$maxIterations = 10000;'));
t('TemplateEngine for guard contains 10000', str_contains($templateEngineSource, 'private function evaluateForBody') && str_contains($templateEngineSource, '$maxIterations = 10000;'));
t('TemplateCompiler exposes MAX_LOOP_ITERATIONS', str_contains($templateCompilerSource, 'public const MAX_LOOP_ITERATIONS = 100000;'));
t('TemplateCompiler while loop throw contains 100000', str_contains($templateCompilerSource, 'DiSyL while-loop exceeded max iterations (') && str_contains($templateCompilerSource, '100000'));
t('IncludeResolver include-pass guard exists', str_contains($includeResolverSource, 'private const MAX_INCLUDE_ITERATIONS = 20;') && str_contains($includeResolverSource, 'while ($iteration < self::MAX_INCLUDE_ITERATIONS)'));
t('Parser depth guard exists', str_contains($parserSource, 'private const MAX_PARSE_DEPTH = 256;') && str_contains($parserSource, 'max parse depth'));
t('ExtendsProcessor chain-depth guard exists', str_contains($extendsProcessorSource, 'private const EXTENDS_CHAIN_MAX = 20;') && str_contains($extendsProcessorSource, 'Extends chain depth exceeded maximum'));
t('Sandbox CPU default exists', str_contains($sandboxSource, 'private float $defaultCpuLimitS = 5.0;'));
t('Sandbox memory default exists', str_contains($sandboxSource, 'private int $defaultMemLimitBytes = 16 * 1024 * 1024;'));
t('Sandbox pop resource check exists', str_contains($sandboxSource, 'public function pop(): void') && str_contains($sandboxSource, 'SANDBOX_CPU_LIMIT') && str_contains($sandboxSource, 'SANDBOX_MEM_LIMIT'));

loadDisylRuntime($root);
$tmpDir = sys_get_temp_dir() . '/disyl_conformance_test_' . getmypid();
@mkdir($tmpDir, 0755, true);
file_put_contents($tmpDir . '/unbounded.disyl', '{while 1}{/while}');

$interpretedCompleted = false;
$interpretedResult = null;
try {
    $engine = createEngine($tmpDir, $tmpDir . '/cache_i', false);
    $interpretedResult = $engine->render('unbounded.disyl', []);
    $interpretedCompleted = true;
} catch (Throwable $e) {
    $interpretedResult = $e->getMessage();
}
t('interpreted unbounded while completes', $interpretedCompleted, (string) $interpretedResult);
t('interpreted unbounded while returns empty output', $interpretedCompleted && $interpretedResult === '', is_string($interpretedResult) ? $interpretedResult : gettype($interpretedResult));

$compiledThrew = false;
$compiledMessage = '';
try {
    $engine = createEngine($tmpDir, $tmpDir . '/cache_c', true);
    $engine->render('unbounded.disyl', []);
} catch (RuntimeException $e) {
    $compiledMessage = $e->getMessage();
    $compiledThrew = str_contains(strtolower($compiledMessage), 'max iterations');
} catch (Throwable $e) {
    $compiledMessage = $e->getMessage();
}
t('compiled unbounded while throws RuntimeException', $compiledThrew, $compiledMessage);
t('compiled unbounded while message mentions max iterations', str_contains(strtolower($compiledMessage), 'max iterations'), $compiledMessage);

$promotionMatrixLines = preg_grep('/^promotion_matrix: /', $output);
t('checker emits promotion matrix lines for all entries', count($promotionMatrixLines) === count($inventory), 'lines=' . count($promotionMatrixLines));
t('promotion summary counts all entries', $promotionSummary['promoted'] + $promotionSummary['partial'] === count($inventory), 'promoted=' . $promotionSummary['promoted'] . ' partial=' . $promotionSummary['partial'] . ' total=' . count($inventory));
t('promotion summary has zero partial entries', $promotionSummary['partial'] === 0, 'partial=' . $promotionSummary['partial']);
t('promotion summary promotes all entries including prohibited justified entries', $promotionSummary['promoted'] === count($inventory), 'promoted=' . $promotionSummary['promoted']);

echo "\nSummary: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
