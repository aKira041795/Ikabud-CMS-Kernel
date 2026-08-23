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

$inventoryPath = $root . '/config/disyl-feature-inventory.json';
$inventory = json_decode((string) file_get_contents($inventoryPath), true);
t('inventory is a JSON array', is_array($inventory));
t('inventory is non-empty', is_array($inventory) && count($inventory) > 0, 'count=' . (is_array($inventory) ? count($inventory) : 0));

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

echo "\nSummary: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
