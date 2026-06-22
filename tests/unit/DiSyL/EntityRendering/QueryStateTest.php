<?php
declare(strict_types=1);

/**
 * Unit tests: EntityQueryState and EntityQueryStateResolver.
 *
 * @see tests/entity_query_state_condition_test.php (canonical)
 */

require_once __DIR__ . '/bootstrap.php';

use Ikabud\Kernel\EntityContext\EntityQueryState;
use Ikabud\Kernel\EntityContext\EntityQueryStateResolver;

echo "── EntityQueryState ──\n";

$s = new EntityQueryState(page: 1, limit: 15);
test_ok('page 1 offset 0', $s->offset() === 0);
test_ok('totalPages 30 = 2', $s->totalPages(30) === 2);
test_ok('totalPages 0 = 1', $s->totalPages(0) === 1);
test_ok('no params for page 1', $s->toQueryParams() === []);

$s2 = new EntityQueryState(page: 3, limit: 10, sort: 'name', direction: 'asc', listId: 'cases');
test_ok('page 3 offset 20', $s2->offset() === 20);
$p = $s2->toQueryParams();
test_ok('namespaced page', isset($p['cases_page']) && $p['cases_page'] === '3');
test_ok('namespaced sort', isset($p['cases_sort']) && $p['cases_sort'] === 'name');

$t = $s2->withSort('name');
test_ok('withSort flips desc', $t->direction === 'desc');
test_ok('withSort resets page', $t->page === 1);

$t2 = $s2->withPage(5);
test_ok('withPage', $t2->page === 5);
test_ok('withPage clamps', $s->withPage(0)->page === 1);

test_summary('EntityQueryState');

// ── Resolver ──
echo "\n── EntityQueryStateResolver ──\n";

$res = new EntityQueryStateResolver();

$r = $res->resolve('cases', ['cases_page' => '3', 'cases_sort' => 'name', 'cases_dir' => 'asc']);
test_ok('resolved page', $r->page === 3);
test_ok('resolved sort', $r->sort === 'name');
test_ok('resolved dir', $r->direction === 'asc');

$r2 = $res->resolve('empty', []);
test_ok('defaults page 1', $r2->page === 1);
test_ok('defaults sort null', $r2->sort === null);

$r3 = $res->resolve('x', ['x_limit' => '999']);
test_ok('limit capped at 100', $r3->limit === 100);

$r4 = $res->resolve('x', ['sort' => 'name', 'dir' => 'asc']);
test_ok('non-namespaced fallback', $r4->sort === 'name');

test_summary('EntityQueryStateResolver');
