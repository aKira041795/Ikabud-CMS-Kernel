<?php
declare(strict_types=1);

/**
 * Unit tests: EntityConditionEvaluator.
 *
 * @see tests/entity_query_state_condition_test.php (canonical)
 */

require_once __DIR__ . '/bootstrap.php';

use Ikabud\Kernel\EntityContext\EntityConditionEvaluator;

echo "── EntityConditionEvaluator ──\n";

$ev = new EntityConditionEvaluator();
EntityConditionEvaluator::resetCache();

// Equality
$a = $ev->compile('status == "pending"');
test_ok('== matches', $ev->evaluate($a, ['status' => 'pending']));
test_ok('== mismatches', !$ev->evaluate($a, ['status' => 'closed']));

// Inequality
$a = $ev->compile('status != "closed"');
test_ok('!= matches', $ev->evaluate($a, ['status' => 'open']));
test_ok('!= mismatches', !$ev->evaluate($a, ['status' => 'closed']));

// Numeric
$a = $ev->compile('priority > 0');
test_ok('> true', $ev->evaluate($a, ['priority' => 5]));
test_ok('> false', !$ev->evaluate($a, ['priority' => 0]));

$a = $ev->compile('amount >= 100');
test_ok('>= true', $ev->evaluate($a, ['amount' => 100]));
test_ok('>= false', !$ev->evaluate($a, ['amount' => 50]));

$a = $ev->compile('stock < 10');
test_ok('< true', $ev->evaluate($a, ['stock' => 5]));
test_ok('< false', !$ev->evaluate($a, ['stock' => 10]));

// Boolean operators
$a = $ev->compile('status == "pending" && priority > 0');
test_ok('&& both true', $ev->evaluate($a, ['status' => 'pending', 'priority' => 1]));
test_ok('&& one false', !$ev->evaluate($a, ['status' => 'pending', 'priority' => 0]));

$a = $ev->compile('status == "urgent" || priority == "high"');
test_ok('|| first true', $ev->evaluate($a, ['status' => 'urgent', 'priority' => 'low']));
test_ok('|| both false', !$ev->evaluate($a, ['status' => 'normal', 'priority' => 'low']));

// NOT
$a = $ev->compile('!is_archived');
test_ok('! false', $ev->evaluate($a, ['is_archived' => false]));
test_ok('! true', !$ev->evaluate($a, ['is_archived' => true]));

// IN
$a = $ev->compile('priority in ["high", "urgent"]');
test_ok('in matches', $ev->evaluate($a, ['priority' => 'high']));
test_ok('in not matches', !$ev->evaluate($a, ['priority' => 'low']));

// Null
$a = $ev->compile('deleted_at == null');
test_ok('== null matches', $ev->evaluate($a, ['deleted_at' => null]));
test_ok('== null mismatches', !$ev->evaluate($a, ['deleted_at' => '2026-01-01']));

$a = $ev->compile('(status == "active" && role == "admin") || is_superadmin');
test_ok('() grouping', $ev->evaluate($a, ['status' => 'active', 'role' => 'admin']));
test_ok('() or branch', $ev->evaluate($a, ['is_superadmin' => true]));

// Bare field (truthy)
$a = $ev->compile('is_active');
test_ok('bare field truthy', $ev->evaluate($a, ['is_active' => true]));
test_ok('bare field falsy', !$ev->evaluate($a, ['is_active' => false]));

// Caching
EntityConditionEvaluator::resetCache();
$c1 = $ev->compile('a == "x" && b > 0');
$c2 = $ev->compile('a == "x" && b > 0');
test_ok('compile caching', $c1 === $c2);

// Errors
$got = false; try { $ev->compile(''); } catch (\InvalidArgumentException) { $got = true; }
test_ok('rejects empty', $got);
$got2 = false; try { $ev->compile('status =='); } catch (\InvalidArgumentException) { $got2 = true; }
test_ok('rejects incomplete', $got2);

// evaluateString
test_ok('evaluateString', $ev->evaluateString('x == "y"', ['x' => 'y']));
test_ok('evaluateString false', !$ev->evaluateString('x == "y"', ['x' => 'z']));

test_summary('EntityConditionEvaluator');
