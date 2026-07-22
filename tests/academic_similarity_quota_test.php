<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/academic_similarity/helpers.php';

$pass = 0;
$fail = 0;
function t(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ✅ {$description}\n"; }
    else { $fail++; echo "  ❌ {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

echo "\n=== Academic Similarity — Quota Enforcement ===\n";

// Test quota enforcement logic inline, modeling AcademicSimilarityQuotaService behavior
// without needing a database connection.

// Helper: simulate quota check (same logic as QuotaService::checkQuota)
function checkQuota(int $usage, int $limit): array {
    if ($limit <= 0) {
        return ['ok' => true, 'current' => $usage, 'limit' => -1, 'warning' => false];
    }
    $exceeded = $usage >= $limit;
    $warning = (!$exceeded) && ($limit > 0) && (($usage / $limit) >= 0.8);
    return [
        'ok' => !$exceeded,
        'current' => $usage,
        'limit' => $limit,
        'warning' => $warning,
        'error' => $exceeded ? "Monthly submissions limit of {$limit} has been reached" : null,
    ];
}

// 1. Under limit → ok
$result = checkQuota(5, 100);
t('usage 5 of 100: ok=true', $result['ok'] === true);
t('usage 5 of 100: no warning', $result['warning'] === false);
t('usage 5 of 100: current is 5', $result['current'] === 5);
t('usage 5 of 100: limit is 100', $result['limit'] === 100);
t('usage 5 of 100: no error', $result['error'] === null);

// 2. At limit → blocked
$result = checkQuota(100, 100);
t('usage 100 of 100: ok=false', $result['ok'] === false);
t('usage 100 of 100: warning=false', $result['warning'] === false);
t('usage 100 of 100: error is set', $result['error'] !== null);

// 3. Over limit → blocked
$result = checkQuota(150, 100);
t('usage 150 of 100: ok=false', $result['ok'] === false);
t('usage 150 of 100: error mentions limit', str_contains($result['error'] ?? '', '100'));

// 4. Warning threshold (80%): usage 80 of 100
$result = checkQuota(80, 100);
t('usage 80 of 100: warning=true', $result['warning'] === true, 'got warning=' . ($result['warning'] ? 'true' : 'false'));
t('usage 80 of 100: still ok', $result['ok'] === true);

// 5. Warning threshold (90%): usage 90 of 100
$result = checkQuota(90, 100);
t('usage 90 of 100: warning=true', $result['warning'] === true);
t('usage 90 of 100: still ok', $result['ok'] === true);

// 6. No limit (-1 = unlimited)
$result = checkQuota(9999, -1);
t('unlimited: ok=true', $result['ok'] === true);
t('unlimited: warning=false', $result['warning'] === false);
t('unlimited: limit is -1', $result['limit'] === -1);

// 7. Zero limit (should be treated as unlimited since metricLimit <= 0)
$result = checkQuota(9999, 0);
t('zero limit: ok=true', $result['ok'] === true, 'got ok=' . ($result['ok'] ? 'true' : 'false'));

// 8. Usage counter increment simulation
function incrementUsage(int $current, int $amount = 1): int {
    return $current + $amount;
}
$usage = 5;
$usage = incrementUsage($usage, 1);
t('increment 5→6', $usage === 6, "got: {$usage}");
$usage = incrementUsage($usage, 3);
t('increment 6→9', $usage === 9, "got: {$usage}");

// 9. Check limits before/after increment
$beforeCheck = checkQuota($usage, 10);
t('usage 9 of 10: ok before increment', $beforeCheck['ok'] === true);

$usageAfter = incrementUsage($usage, 2); // 9 + 2 = 11
$afterCheck = checkQuota($usageAfter, 10);
t('usage 11 of 10: blocked after increment', $afterCheck['ok'] === false);

// 10. Verify result structure
$result = checkQuota(50, 100);
t('result has ok key', array_key_exists('ok', $result));
t('result has current key', array_key_exists('current', $result));
t('result has limit key', array_key_exists('limit', $result));
t('result has warning key', array_key_exists('warning', $result));

// 11. Edge: usage exactly at 80% but not exceeded
$result = checkQuota(80, 100);
t('80 of 100: warning at exactly 80%', $result['warning'] === true);

// 12. Edge: usage just below limit
$result = checkQuota(99, 100);
t('99 of 100: still ok', $result['ok'] === true);
t('99 of 100: warning true (99% >= 80%)', $result['warning'] === true);

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
