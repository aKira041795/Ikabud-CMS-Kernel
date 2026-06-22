<?php
declare(strict_types=1);

/**
 * Security tests: entity rendering escaping and condition injection.
 */

require_once dirname(__DIR__) . '/unit/DiSyL/EntityRendering/bootstrap.php';

use Ikabud\Kernel\EntityContext\EntityConditionEvaluator;
use Ikabud\Kernel\EntityContext\Renderer\TextCellRenderer;
use Ikabud\Kernel\EntityContext\CellRenderContext;

echo "── Entity Renderer Escaping ──\n";

$text = new TextCellRenderer();
$res = $text->render(new CellRenderContext(value: '<script>alert("xss")</script>', field: 'name', row: []));
test_ok('script tags escaped', $res->html === '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');

$res2 = $text->render(new CellRenderContext(value: '"> <img onerror="alert(1)" src=x>', field: 'name', row: []));
test_ok('attribute injection escaped', !str_contains($res2->html, 'onerror'));
test_ok('safe output has no raw tags', !str_contains($res2->html, '<img'));

test_summary('Entity Renderer Escaping');

// ── Condition injection ──
echo "\n── Entity Action Condition Security ──\n";

$ev = new EntityConditionEvaluator();
EntityConditionEvaluator::resetCache();

// Condition with special chars — should not allow injection
$cond = $ev->compile('status == "active" && role == "admin"');
test_ok('normal condition compiles', $cond !== null);

// Malformed input should throw, not silently accept
$caught = false;
try {
    $ev->compile('1; DROP TABLE users; --');
} catch (\InvalidArgumentException) {
    $caught = true;
}
test_ok('sql injection attempt rejected', $caught);

// Path traversal-like identifiers
$caught2 = false;
try {
    $ev->compile('../../etc/passwd == "x"');
} catch (\InvalidArgumentException) {
    $caught2 = true;
}
test_ok('path traversal identifier rejected', $caught2);

test_summary('Entity Action Condition Security');
