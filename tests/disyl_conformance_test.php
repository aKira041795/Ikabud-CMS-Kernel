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
t('conformance checker exits 0', $exit === 0, $summary);
t('checker prints counts summary', str_contains($summary, 'counts:'), $summary);
t('checker prints lsp summary', preg_match('/^lsp: .+/m', $summary) === 1, $summary);
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
foreach ($inventory as $entry) {
    $id = (string) $entry['id'];
    $kind = (string) $entry['kind'];
    $lsp = (bool) $entry['lsp'];

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

$ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
preg_match('/coding-standards:\n(?:.|\n)*?dependency-audit:/', $ci, $codingStandardsMatch);
$codingStandardsBlock = $codingStandardsMatch[0] ?? '';
t('coding-standards job contains strict proof lane gate', str_contains($codingStandardsBlock, 'php tools/disyl-conformance-check.php'), $codingStandardsBlock);

foreach ($prohibitedMap as $id => $token) {
    $recognized = isset($blockOpenSet[$token]) || isset($blockTokenSet[$token]) || isset($keywordSet[$token]) || isset($componentSet[$token]);
    t($id . ' token is not recognized by LSP block/keyword/component surface', !$recognized, $token);
}

echo "\nSummary: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
