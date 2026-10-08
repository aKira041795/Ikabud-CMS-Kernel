<?php
/**
 * Slice 4 acceptance probe (DRIVER) — Activity must read as English, not JSON.
 *
 * The owner, 2026-10-08: "at activity, take note of the details and changes, it must be human readable
 * and not code/json texts".
 *
 * A GATE: it must FAIL on the unchanged tree. Measured there, the rendered Details cell contains
 *   Ledger Reversal: {"status":"legacy_no_effect","reversed":0}
 *
 * IMPORTANT: the page's OWN inline JavaScript contains '{' everywhere, so scanning the raw HTML for JSON
 * would false-positive on a correct fix. The scan strips <script> and <style> first and then looks only
 * at real markup — that is the region an operator actually reads.
 */
declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);

$results = [];
function probe(string $label, bool $ok, string $detail = ''): void {
    global $results;
    $results[] = [$label, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

echo "== slice 4 acceptance gate (activity readability) ==\n";

$cmd = sprintf('php %s 20 admin 2>/dev/null', escapeshellarg($basePath . '/tools/lane-consignee-slice4-child.php'));
$html = (string)shell_exec($cmd);

probe('page renders at all (non-trivial HTML returned)', strlen($html) > 2000, 'bytes=' . strlen($html));
if (strlen($html) <= 2000) {
    echo "FAILED: the Activity page did not render — cannot judge readability\n";
    exit(1);
}

// Strip the page's own script/style so its JavaScript braces are not mistaken for data.
$markup = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
$markup = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $markup) ?? $markup;

// DiSyL ESCAPES rendered values, so the JSON that reaches the operator's screen as
// {"reversed":0} sits in the source as {&quot;reversed&quot;:0}. Scanning the raw markup for '"{' is
// blind to it (measured 2026-10-08: 46 occurrences of "reversed", zero literal matches).
// Decode first, THEN look.
$markup = html_entity_decode($markup, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// A JSON object literal left in the readable region: {"key":   /   "key":{"
$jsonObjects = preg_match_all('/\{\s*"[A-Za-z_][A-Za-z0-9_]*"\s*:/', $markup, $m) ?: 0;
$sample = $jsonObjects > 0 ? trim((string)($m[0][0] ?? '')) : '';
probe('no raw JSON object literal in the readable markup', $jsonObjects === 0,
      "found={$jsonObjects}" . ($sample !== '' ? " first={$sample}" : ''));

// The specific offender measured on the base tree.
$offender = preg_match('/"reversed"\s*:/', $markup) ?: 0;
probe('the ledger-reversal payload is not printed raw', $offender === 0, 'found=' . $offender);

// A record label printed twice inside one cell ("... Deliveries #2000005955 Deliveries #2000005955").
// Replace tags with a SPACE, not nothing: strip_tags() glues adjacent text nodes together
// ("#2000005955Deliveries") and the duplication then cannot be matched (measured 2026-10-08).
//
// TIGHTENED to the actual defect shape: the SAME label phrase repeated adjacently. The earlier, broader
// "any record-like token recurring nearby" rule was measured to FALSE-POSITIVE on fixture data — a
// product_id legitimately appearing twice in one row was reported as a duplicated label (6 hits on a
// correct tree, product ids up to 189 vs a synthetic 99002). A wrong guard is worse than none.
// Validated both ways: base render 75 adjacent repeats, fixed render 0.
$text = html_entity_decode((string)preg_replace('/<[^>]+>/', ' ', $markup), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$text = (string)preg_replace('/\s+/', ' ', $text);
$dup = preg_match_all('/(\w+ #\d{4,})\s+\1/', $text, $dm) ?: 0;
probe('no record label duplicated inside one cell', $dup === 0,
      "found={$dup}" . ($dup > 0 ? ' e.g. ' . trim((string)($dm[0][0] ?? '')) : ''));

// The filter must offer consignees, not only branches (owner, 2026-10-08).
$hasGroup = (bool)preg_match('/<optgroup[^>]*label="Consignees"/i', $markup);
$hasOption = (bool)preg_match('/<option[^>]*>[^<]*LEE-PLAZA/i', $markup);
probe('the filter offers consignees as selectable options', $hasGroup || $hasOption,
      'optgroup=' . (int)$hasGroup . ' option=' . (int)$hasOption);

// PIN: the audit trail must not be emptied to satisfy the above.
$dataRows = preg_match_all('/<tr\b/i', $markup) ?: 0;
probe('pin: the activity table still lists rows', $dataRows > 5, "tr={$dataRows}");

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
if ($failed === []) {
    echo "PASS: Activity details and changes read as human text, with no raw JSON\n";
    exit(0);
}
echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
exit(1);
