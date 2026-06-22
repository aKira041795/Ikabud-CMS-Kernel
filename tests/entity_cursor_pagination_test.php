<?php
declare(strict_types=1);

/**
 * Unit tests: cursor-based pagination support.
 *
 * Tests:
 *   1. EntityQueryState — cursor fields, isCursorBased, withCursor, toQueryParams with cursors
 *   2. EntityQueryStateResolver — reads cursor params
 *   3. EntityListResult — cursor-based fromCapabilityResult
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

use Ikabud\Kernel\EntityContext\EntityQueryState;
use Ikabud\Kernel\EntityContext\EntityQueryStateResolver;
use Ikabud\Kernel\EntityContext\EntityListResult;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \xE2\x9C\x93 {$label}\n"; }
    else { $fail++; echo "  \xE2\x9C\x97 {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "── Cursor Pagination Tests ──\n\n";

// ════════════════════════════════════════════
// 1. EntityQueryState cursor support
// ════════════════════════════════════════════

echo "  ── 1. EntityQueryState cursor fields ──\n";

$s = new EntityQueryState();
t('default cursor null', $s->cursor === null);
t('default hasMore false', !$s->hasMore);
t('default prevCursor null', $s->prevCursor === null);
t('default not cursor-based', !$s->isCursorBased());

$s2 = new EntityQueryState(page: 0, cursor: 'abc123', hasMore: true);
t('cursor set', $s2->cursor === 'abc123');
t('hasMore set', $s2->hasMore);
t('isCursorBased true', $s2->isCursorBased());
t('page is 0 for cursor-based', $s2->page === 0);

$s3 = new EntityQueryState(page: 0, hasMore: true);
t('hasMore alone is cursor-based', $s3->isCursorBased());

// withCursor
$withCursor = (new EntityQueryState(listId: 'cases'))->withCursor('xyz789', true);
t('withCursor sets cursor', $withCursor->cursor === 'xyz789');
t('withCursor sets hasMore', $withCursor->hasMore);
t('withCursor resets page to 0', $withCursor->page === 0);
t('withCursor preserves listId', $withCursor->listId === 'cases');

// toQueryParams with cursor
$params = $withCursor->toQueryParams();
t('cursor params include cursor key', isset($params['cases_cursor']));
t('cursor params has cursor value', $params['cases_cursor'] === 'xyz789');
t('cursor params no page', !isset($params['cases_page']));

// toQueryParams with page (no cursor)
$pageState = new EntityQueryState(page: 3, listId: 'cases');
$pageParams = $pageState->toQueryParams();
t('page-based has page param', isset($pageParams['cases_page']));
t('page-based no cursor', !isset($pageParams['cases_cursor']));

// withSort resets cursor
$sorted = (new EntityQueryState(cursor: 'abc', hasMore: true))->withSort('name');
t('withSort clears cursor', $sorted->cursor === null);
t('withSort clears hasMore', !$sorted->hasMore);

// ════════════════════════════════════════════
// 2. EntityQueryStateResolver cursor params
// ════════════════════════════════════════════

echo "\n  ── 2. Resolver cursor params ──\n";

$resolver = new EntityQueryStateResolver();

$r = $resolver->resolve('cases', ['cases_cursor' => '42', 'cases_sort' => 'name', 'cases_dir' => 'asc']);
t('resolves cursor param', $r->cursor === '42');
t('cursor mode sets page 0', $r->page === 0);
t('cursor mode is cursor-based', $r->isCursorBased());
t('cursor preserves sort', $r->sort === 'name');

$r2 = $resolver->resolve('cases', ['cases_prev' => '1', 'cases_cursor' => '99']);
t('resolves prev param', $r2->prevCursor === '1');
t('prev with cursor works', $r2->cursor === '99');

$r3 = $resolver->resolve('empty', []);
t('no cursor params defaults page 1', $r3->page === 1);
t('no cursor params cursor null', $r3->cursor === null);

// Non-namespaced cursor fallback
$r4 = $resolver->resolve('x', ['cursor' => '7']);
t('non-namespaced cursor fallback', $r4->cursor === '7');

// ════════════════════════════════════════════
// 3. EntityListResult cursor format
// ════════════════════════════════════════════

echo "\n  ── 3. EntityListResult cursor format ──\n";

$r1 = EntityListResult::fromCapabilityResult([
    'rows' => [['id' => 1], ['id' => 2]],
    'next_cursor' => '2',
    'has_more' => true,
]);
t('cursor result has nextCursor', $r1->nextCursor === '2');
t('cursor result hasMore', $r1->hasMore);
t('cursor result total null', $r1->total === null);
t('cursor result isCursorBased', $r1->isCursorBased());

$r2 = EntityListResult::fromCapabilityResult([
    'rows' => [['id' => 1]],
    'has_more' => false,
]);
t('cursor result no more rows', !$r2->hasMore);
t('cursor result nextCursor null', $r2->nextCursor === null);

// ════════════════════════════════════════════
// Summary
// ════════════════════════════════════════════

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
