<?php
declare(strict_types=1);

/**
 * Unit tests for EntityConditionEvaluator, EntityQueryState, and EntityQueryStateResolver.
 *
 * Tests:
 *   1. EntityQueryState — page/limit/offset, sort toggling, query params, total pages
 *   2. EntityQueryStateResolver — reading from $_GET, defaults
 *   3. EntityConditionEvaluator — compile and evaluate various condition types
 *   4. EntityConditionEvaluator — compile-once caching
 *   5. EntityConditionEvaluator — error handling
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

use Ikabud\Kernel\EntityContext\EntityConditionEvaluator;
use Ikabud\Kernel\EntityContext\EntityQueryState;
use Ikabud\Kernel\EntityContext\EntityQueryStateResolver;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \xE2\x9C\x93 {$label}\n"; }
    else { $fail++; echo "  \xE2\x9C\x97 {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "── Entity Query State & Condition Evaluator Tests ──\n\n";

// ════════════════════════════════════════════
// 1. EntityQueryState
// ════════════════════════════════════════════

echo "  ── 1. EntityQueryState ──\n";

$state = new EntityQueryState(page: 1, limit: 15);
t('default page is 1', $state->page === 1);
t('default limit is 15', $state->limit === 15);
t('offset is 0 for page 1', $state->offset() === 0);
t('totalPages 30 rows = 2 pages', $state->totalPages(30) === 2);
t('totalPages 0 rows = 1 page', $state->totalPages(0) === 1);
t('totalPages 15 rows = 1 page', $state->totalPages(15) === 1);
t('totalPages 16 rows = 2 pages', $state->totalPages(16) === 2);
t('no query params for page 1', $state->toQueryParams() === []);

$state2 = new EntityQueryState(page: 3, limit: 10, sort: 'name', direction: 'asc', listId: 'cases');
t('page 3 offset is 20', $state2->offset() === 20);
$params = $state2->toQueryParams();
t('query params include page', isset($params['cases_page']) && $params['cases_page'] === '3');
t('query params include sort', isset($params['cases_sort']) && $params['cases_sort'] === 'name');
t('query params include dir', isset($params['cases_dir']) && $params['cases_dir'] === 'asc');

$toggled = $state2->withSort('name');
t('withSort flips asc→desc', $toggled->direction === 'desc');
t('withSort resets page to 1', $toggled->page === 1);

$toggled2 = $state2->withSort('status');
t('withSort new field sets asc', $toggled2->direction === 'asc');
t('withSort new field changes sort', $toggled2->sort === 'status');

$paged = $state->withPage(5);
t('withPage sets page', $paged->page === 5);
t('withPage clamps at 1', $state->withPage(0)->page === 1);

// ════════════════════════════════════════════
// 2. EntityQueryStateResolver
// ════════════════════════════════════════════

echo "\n  ── 2. EntityQueryStateResolver ──\n";

$resolver = new EntityQueryStateResolver();

$resolved = $resolver->resolve('cases', [
    'cases_page' => '3',
    'cases_sort' => 'name',
    'cases_dir' => 'asc',
]);
t('resolved page from namespaced param', $resolved->page === 3);
t('resolved sort from namespaced param', $resolved->sort === 'name');
t('resolved dir from namespaced param', $resolved->direction === 'asc');
t('resolved listId', $resolved->listId === 'cases');

$resolved2 = $resolver->resolve('orders', ['orders_page' => '2', 'orders_limit' => '50']);
t('resolves limit from param', $resolved2->limit === 50);
t('limit capped at 100', ($capped = $resolver->resolve('test', ['test_limit' => '999'])) && $capped->limit === 100);

$resolved3 = $resolver->resolve('empty', []);
t('empty resolve defaults page 1', $resolved3->page === 1);
t('empty resolve defaults sort null', $resolved3->sort === null);
t('empty resolve defaults desc', $resolved3->direction === 'desc');

$resolved4 = $resolver->resolve('x', ['x_page' => '0']);
t('page 0 clamped to 1', $resolved4->page === 1);

$resolved5 = $resolver->resolve('x', ['sort' => 'name', 'dir' => 'asc']);
t('non-namespaced sort fallback', $resolved5->sort === 'name');

// ════════════════════════════════════════════
// 3. EntityConditionEvaluator — conditions
// ════════════════════════════════════════════

echo "\n  ── 3. EntityConditionEvaluator — conditions ──\n";

$evaluator = new EntityConditionEvaluator();
EntityConditionEvaluator::resetCache();

// Equality
$ast = $evaluator->compile('status == "pending"');
t('equals string matches', $evaluator->evaluate($ast, ['status' => 'pending']));
t('equals string mismatches', !$evaluator->evaluate($ast, ['status' => 'closed']));

// Inequality
$ast = $evaluator->compile('status != "closed"');
t('not-equals matches', $evaluator->evaluate($ast, ['status' => 'open']));
t('not-equals mismatches', !$evaluator->evaluate($ast, ['status' => 'closed']));

// Numeric comparison
$ast = $evaluator->compile('priority > 0');
t('greater-than true', $evaluator->evaluate($ast, ['priority' => 5]));
t('greater-than false', !$evaluator->evaluate($ast, ['priority' => 0]));

$ast = $evaluator->compile('amount >= 100');
t('gte true', $evaluator->evaluate($ast, ['amount' => 100]));
t('gte true over', $evaluator->evaluate($ast, ['amount' => 150]));
t('gte false', !$evaluator->evaluate($ast, ['amount' => 50]));

$ast = $evaluator->compile('stock < 10');
t('lt true', $evaluator->evaluate($ast, ['stock' => 5]));
t('lt false', !$evaluator->evaluate($ast, ['stock' => 10]));

$ast = $evaluator->compile('count <= 3');
t('lte true', $evaluator->evaluate($ast, ['count' => 3]));
t('lte false', !$evaluator->evaluate($ast, ['count' => 4]));

// AND / OR
$ast = $evaluator->compile('status == "pending" && priority > 0');
t('and both true', $evaluator->evaluate($ast, ['status' => 'pending', 'priority' => 1]));
t('and one false', !$evaluator->evaluate($ast, ['status' => 'pending', 'priority' => 0]));
t('and both false', !$evaluator->evaluate($ast, ['status' => 'closed', 'priority' => 0]));

$ast = $evaluator->compile('status == "urgent" || priority == "high"');
t('or first true', $evaluator->evaluate($ast, ['status' => 'urgent', 'priority' => 'low']));
t('or second true', $evaluator->evaluate($ast, ['status' => 'normal', 'priority' => 'high']));
t('or both false', !$evaluator->evaluate($ast, ['status' => 'normal', 'priority' => 'low']));

// NOT
$ast = $evaluator->compile('!is_archived');
t('not true when field falsy', $evaluator->evaluate($ast, ['is_archived' => false]));
t('not false when field truthy', !$evaluator->evaluate($ast, ['is_archived' => true]));

// IN
$ast = $evaluator->compile('priority in ["high", "urgent"]');
t('in matches first', $evaluator->evaluate($ast, ['priority' => 'high']));
t('in matches second', $evaluator->evaluate($ast, ['priority' => 'urgent']));
t('in not matches', !$evaluator->evaluate($ast, ['priority' => 'low']));

// Null comparison
$ast = $evaluator->compile('deleted_at == null');
t('null equality matches null', $evaluator->evaluate($ast, ['deleted_at' => null]));
t('null equality mismatches value', !$evaluator->evaluate($ast, ['deleted_at' => '2026-01-01']));

$ast = $evaluator->compile('deleted_at != null');
t('not-null matches value', $evaluator->evaluate($ast, ['deleted_at' => '2026-01-01']));
t('not-null mismatches null', !$evaluator->evaluate($ast, ['deleted_at' => null]));

// Parentheses
$ast = $evaluator->compile('(status == "active" && role == "admin") || is_superadmin');
t('parentheses grouped', $evaluator->evaluate($ast, ['status' => 'active', 'role' => 'admin', 'is_superadmin' => false]));
t('parentheses or branch', $evaluator->evaluate($ast, ['status' => 'inactive', 'role' => 'user', 'is_superadmin' => true]));
t('parentheses none true', !$evaluator->evaluate($ast, ['status' => 'inactive', 'role' => 'user', 'is_superadmin' => false]));

// Bare field (truthy check)
$ast = $evaluator->compile('is_active');
t('bare field truthy', $evaluator->evaluate($ast, ['is_active' => true]));
t('bare field falsy', !$evaluator->evaluate($ast, ['is_active' => false]));

// Mixed types: numeric string comparison
$ast = $evaluator->compile('score > 10');
t('numeric string vs int', $evaluator->evaluate($ast, ['score' => '15']));

// Missing field
$ast = $evaluator->compile('status == "active"');
t('missing field as empty string', !$evaluator->evaluate($ast, ['not_status' => 'active']));

// ════════════════════════════════════════════
// 4. EntityConditionEvaluator — compile-once caching
// ════════════════════════════════════════════

echo "\n  ── 4. EntityConditionEvaluator — caching ──\n";

EntityConditionEvaluator::resetCache();

// Compile the same condition twice — second call should use cache
$ast1 = $evaluator->compile('status == "pending" && priority == "high"');
$ast2 = $evaluator->compile('status == "pending" && priority == "high"');
t('compile returns equivalent AST', $ast1 === $ast2);

// ════════════════════════════════════════════
// 5. EntityConditionEvaluator — error handling
// ════════════════════════════════════════════

echo "\n  ── 5. EntityConditionEvaluator — errors ──\n";

$gotException = false;
try { $evaluator->compile(''); } catch (\InvalidArgumentException $e) { $gotException = true; }
t('rejects empty condition', $gotException);

$gotException2 = false;
try { $evaluator->compile('status =='); } catch (\InvalidArgumentException $e) { $gotException2 = true; }
t('rejects incomplete expression', $gotException2);

$gotException3 = false;
try { $evaluator->compile('status == "unterminated'); } catch (\InvalidArgumentException $e) { $gotException3 = true; }
t('rejects unterminated string', $gotException3);

// ════════════════════════════════════════════
// 6. EntityConditionEvaluator — evaluateString (convenience)
// ════════════════════════════════════════════

echo "\n  ── 6. EntityConditionEvaluator — evaluateString ──\n";

EntityConditionEvaluator::resetCache();
t('evaluateString one-shot', $evaluator->evaluateString('status == "active"', ['status' => 'active']));
t('evaluateString one-shot false', !$evaluator->evaluateString('status == "active"', ['status' => 'inactive']));
t('evaluateString with &&', $evaluator->evaluateString('a > 0 && b == "yes"', ['a' => 1, 'b' => 'yes']));

// ════════════════════════════════════════════
// Summary
// ════════════════════════════════════════════

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
