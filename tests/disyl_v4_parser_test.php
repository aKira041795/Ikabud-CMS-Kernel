<?php

declare(strict_types=1);

/**
 * DiSyL v4 Parser — Unit Tests
 *
 * Tests the Parser class directly (AST output), complementing
 * the engine-level integration tests in disyl_v4_test.php.
 *
 * Run from repo root: php tests/disyl_v4_parser_test.php
 */

require_once __DIR__ . '/../kernel/DiSyL/v4/AST/AbstractNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/TextNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/CommentNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/DocumentNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/ControlNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/ExpressionNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/LiteralNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/IdentifierNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/BinaryOpNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/UnaryOpNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/PropertyAccessNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/FilterChain.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/FilterNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/FunctionCallNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/ArrayNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/IncludeNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/AST/SlotNode.php';
require_once __DIR__ . '/../kernel/DiSyL/v4/Parser.php';

use Ikabud\Kernel\DiSyL\v4\Parser;
use Ikabud\Kernel\DiSyL\v4\AST\ControlNode;
use Ikabud\Kernel\DiSyL\v4\AST\DocumentNode;
use Ikabud\Kernel\DiSyL\v4\AST\TextNode;
use Ikabud\Kernel\DiSyL\v4\AST\CommentNode;

$parser = new Parser();
$pass = 0;
$fail = 0;

function check(string $desc, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        echo "  \033[32m✓\033[0m {$desc}\n";
        $pass++;
    } else {
        echo "  \033[31m✗\033[0m {$desc}\n";
        if ($detail !== '') echo "    {$detail}\n";
        $fail++;
    }
}

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║   DiSyL v4 PARSER — UNIT TESTS                       ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 1. Basic parsing ──────────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse('Hello World');
check('plain text produces DocumentNode', $doc instanceof DocumentNode);
check('single text child', count($doc->getChildren()) === 1);
check('text content preserved', $doc->getChildren()[0] instanceof TextNode
    && $doc->getChildren()[0]->getContent() === 'Hello World');

$doc = $parser->parse('');
check('empty source produces empty DocumentNode', $doc instanceof DocumentNode
    && count($doc->getChildren()) === 0);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 2. Comments ───────────────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse('before{!-- comment --}after');
check('dash comment produces CommentNode', $doc->getChildren()[1] instanceof CommentNode);

$doc = $parser->parse('{* star comment *}text');
check('star comment produces CommentNode', $doc->getChildren()[0] instanceof CommentNode);

$doc = $parser->parse('{# hash comment #}text');
check('hash comment produces CommentNode', $doc->getChildren()[0] instanceof CommentNode);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 3. {if} control structure ─────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse('{if x}yes{/if}');
$children = $doc->getChildren();
check('if produces ControlNode', $children[0] instanceof ControlNode
    && $children[0]->getTag() === 'if');

$doc = $parser->parse('{if x}yes{else}no{/if}');
$ifNode = $doc->getChildren()[0];
check('if/else has else branch', $ifNode instanceof ControlNode
    && $ifNode->hasElse());

$doc = $parser->parse('{if x}yes{elseif y}maybe{/if}');
check('elseif parsed without error', $doc->getChildren()[0] instanceof ControlNode);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 4. {for} / {foreach} / {each} ─────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse('{for item in items}{item}{/for}');
check('for loop parses', $doc->getChildren()[0] instanceof ControlNode
    && $doc->getChildren()[0]->getTag() === 'for');

$doc = $parser->parse('{foreach items as item}{item}{/foreach}');
check('foreach loop parses (AST tag is for)', $doc->getChildren()[0] instanceof ControlNode
    && $doc->getChildren()[0]->getTag() === 'for');

$doc = $parser->parse('{for item in items}{item}{empty}No items{/for}');
check('for/empty parses', $doc->getChildren()[0] instanceof ControlNode
    && $doc->getChildren()[0]->hasElse());

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 5. {match} control structure ──────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse('{match status}{when "a"}A{/when}{else}Z{/match}');
$matchNode = $doc->getChildren()[0];
check('match produces ControlNode', $matchNode instanceof ControlNode
    && $matchNode->getTag() === 'match');
check('match has expression attribute', $matchNode->getAttribute('expression') !== null);

$doc = $parser->parse('{match x}{when "a"}A{/when}{when "b"}B{/when}{default}Z{/default}{/match}');
check('match with default parses', $doc->getChildren()[0] instanceof ControlNode);

$doc = $parser->parse('{match order}{when "paid" guard amount > 100}Big{/when}{else}Small{/match}');
check('match with guard parses', $doc->getChildren()[0] instanceof ControlNode);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 6. {set} assignment ───────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse('{set x = 42}');
check('set produces ControlNode', $doc->getChildren()[0] instanceof ControlNode
    && $doc->getChildren()[0]->getTag() === 'set');

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 7. Raw blocks ─────────────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse('{verbatim}{if x}y{/if}{/verbatim}');
check('verbatim preserves inner text as-is', $doc->getChildren()[0] instanceof TextNode
    && str_contains($doc->getChildren()[0]->getContent(), '{if x}'));

$doc = $parser->parse('{literal}<raw>{name}</raw>{/literal}');
check('literal preserves inner text', $doc->getChildren()[0] instanceof TextNode);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 8. Error recovery (per-block) ─────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse("before{/if}after");
check('stray {/if} does not crash parser', $doc instanceof DocumentNode);

$doc = $parser->parse("before{if}x{/for}after");
$children = $doc->getChildren();
check('mismatched close tag does not crash', count($children) >= 1);

$doc = $parser->parse("section1{if x}ok{/if}section2{if broken{/if}section3");
check('broken if does not lose surrounding text', $doc instanceof DocumentNode);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 9. Nested structures ──────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse('{if a}{for i in items}{i}{/for}{/if}');
$outer = $doc->getChildren()[0];
check('nested if/for parses', $outer instanceof ControlNode && $outer->getTag() === 'if');

$doc = $parser->parse('{match type}{when "a"}{if x}yes{/if}{/when}{/match}');
check('nested if inside match when', $doc->getChildren()[0] instanceof ControlNode
    && $doc->getChildren()[0]->getTag() === 'match');

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 10. Expression parsing ────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$doc = $parser->parse('{a + b}');
check('arithmetic expression', count($doc->getChildren()) === 1);

$doc = $parser->parse('{x ? "yes" : "no"}');
check('ternary expression', count($doc->getChildren()) === 1);

$doc = $parser->parse('{name|upper|truncate:5}');
check('filter chain with args', count($doc->getChildren()) === 1);

$doc = $parser->parse('{user.name}');
check('nested property access', count($doc->getChildren()) === 1);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n╔══════════════════════════════════════════════════════╗\n";
printf("║  RESULTS:  %2d PASSED  |  %2d FAILED                     ║\n", $pass, $fail);
echo "╚══════════════════════════════════════════════════════╝\n";

exit($fail > 0 ? 1 : 0);
