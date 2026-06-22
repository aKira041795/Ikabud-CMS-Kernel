<?php
declare(strict_types=1);

/**
 * Unit tests for EntityListResult and EntityViewResolver sort validation.
 *
 * Tests:
 *   1. EntityListResult — construction, fromCapabilityResult, cursor-based, error state
 *   2. EntityViewResolver::validateSort — allowlist enforcement, defaults
 *   3. EntityViewResolver::getSortableFields
 */

$_SERVER['HTTP_HOST'] = 'localhost';
$basePath = dirname(__DIR__);

require_once $basePath . '/vendor/autoload.php';

define('BASE_PATH', $basePath);
define('KERNEL_PATH', $basePath . '/kernel');

spl_autoload_register(static function (string $class): void {
    $kernelPrefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $kernelPrefix, strlen($kernelPrefix)) !== 0) return;
    $relative = substr($class, strlen($kernelPrefix));
    $path = KERNEL_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) { require_once $path; }
});

use Ikabud\Kernel\EntityContext\EntityListResult;
use Ikabud\Kernel\EntityContext\EntityViewResolver;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \xE2\x9C\x93 {$label}\n"; }
    else { $fail++; echo "  \xE2\x9C\x97 {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "── EntityListResult & Sort Validation Tests ──\n\n";

// ════════════════════════════════════════════
// 1. EntityListResult
// ════════════════════════════════════════════

echo "  ── 1. EntityListResult ──\n";

// Default (empty)
$r = new EntityListResult();
t('default rows empty', $r->rows === []);
t('default total null', $r->total === null);
t('default nextCursor null', $r->nextCursor === null);
t('default hasMore false', $r->hasMore === false);
t('default error null', $r->error === null);
t('default no error', !$r->hasError());
t('default is cursor-based', $r->isCursorBased());
t('default count 0', $r->count() === 0);

// Total-based
$r2 = new EntityListResult(
    rows: [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']],
    total: 10,
);
t('total-based rows count', $r2->count() === 2);
t('total-based total accessible', $r2->total === 10);
t('total-based not cursor-based', !$r2->isCursorBased());

// Cursor-based
$r3 = new EntityListResult(
    rows: [['id' => 3]],
    nextCursor: 'abc123',
    hasMore: true,
);
t('cursor-based nextCursor', $r3->nextCursor === 'abc123');
t('cursor-based hasMore', $r3->hasMore);
t('cursor-based total null', $r3->total === null);
t('cursor-based isCursorBased', $r3->isCursorBased());

// Error state
$r4 = new EntityListResult(error: 'Something broke');
t('error result has error', $r4->hasError());
t('error result error string', $r4->error === 'Something broke');
t('error result rows empty', $r4->rows === []);

// fromCapabilityResult
$capResult = EntityListResult::fromCapabilityResult([
    'rows' => [['id' => 1]],
    'total' => 5,
]);
t('fromCapabilityResult rows', $capResult->rows === [['id' => 1]]);
t('fromCapabilityResult total', $capResult->total === 5);
t('fromCapabilityResult no error', !$capResult->hasError());

$capResult2 = EntityListResult::fromCapabilityResult([
    'rows' => [['id' => 1]],
    'next_cursor' => 'xyz',
    'has_more' => true,
]);
t('fromCapabilityResult cursor', $capResult2->nextCursor === 'xyz');
t('fromCapabilityResult hasMore', $capResult2->hasMore);
t('fromCapabilityResult total null', $capResult2->total === null);

$capResult3 = EntityListResult::fromCapabilityResult(['error' => 'fail']);
t('fromCapabilityResult error', $capResult3->hasError());

// ════════════════════════════════════════════
// 2. EntityViewResolver::validateSort
// ════════════════════════════════════════════

echo "\n  ── 2. EntityViewResolver::validateSort ──\n";

$resolver = EntityViewResolver::getInstance();
$resolver->reset();

// Register a view contract with sortable fields allowlist
$resolver->registerView('test_entity', 'table', [
    'fields' => ['id', 'name', 'status', 'created_at'],
    'sort' => ['field' => 'created_at', 'direction' => 'desc'],
    'sortable_fields' => ['name' => 'name', 'status' => 'status', 'created_at' => 'created_at'],
]);

// Requested sort is allowlisted
$sort = $resolver->validateSort('test_entity', 'table', 'name');
t('allowlisted sort passes through', $sort['field'] === 'name');
t('allowlisted sort keeps direction', $sort['direction'] === 'desc');

// Not in allowlist → fallback to default
$sort2 = $resolver->validateSort('test_entity', 'table', 'id');
t('non-allowlisted sort falls to default', $sort2['field'] === 'created_at');

// Null requested → default
$sort3 = $resolver->validateSort('test_entity', 'table', null);
t('null sort uses default', $sort3['field'] === 'created_at');

// Empty string → default
$sort4 = $resolver->validateSort('test_entity', 'table', '');
t('empty sort uses default', $sort4['field'] === 'created_at');

// Direction validation
$sort5 = $resolver->validateSort('test_entity', 'table', 'status', 'asc');
t('valid direction passes', $sort5['direction'] === 'asc');

$sort6 = $resolver->validateSort('test_entity', 'table', 'status', 'invalid');
t('invalid direction falls to default', $sort6['direction'] === 'desc');

// No allowlist → any field allowed (backward compat)
$resolver->registerView('legacy_entity', 'table', [
    'fields' => ['id', 'name'],
    'sort' => ['field' => 'id', 'direction' => 'asc'],
    // No sortable_fields defined
]);
$sort7 = $resolver->validateSort('legacy_entity', 'table', 'name');
t('no allowlist allows any field', $sort7['field'] === 'name');

// ════════════════════════════════════════════
// 3. EntityViewResolver::getSortableFields
// ════════════════════════════════════════════

echo "\n  ── 3. EntityViewResolver::getSortableFields ──\n";

$fields = $resolver->getSortableFields('test_entity', 'table');
t('returns sortable fields map', isset($fields['name']) && $fields['name'] === 'name');
t('returns all declared fields', count($fields) === 3);

$empty = $resolver->getSortableFields('nonexistent', 'table');
t('unknown entity returns empty', $empty === []);

// ════════════════════════════════════════════
// Summary
// ════════════════════════════════════════════

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
