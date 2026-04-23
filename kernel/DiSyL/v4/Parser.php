<?php
/**
 * DiSyL v4 Parser
 *
 * Parses DiSyL template source into an AST that the TemplateCompiler
 * can compile to PHP.  Single-pass recursive-descent over the source string.
 *
 * @package Ikabud\Kernel\DiSyL\v4
 */

namespace Ikabud\Kernel\DiSyL\v4;

use Ikabud\Kernel\DiSyL\v4\AST\AbstractNode;
use Ikabud\Kernel\DiSyL\v4\AST\ArrayNode;
use Ikabud\Kernel\DiSyL\v4\AST\BinaryOpNode;
use Ikabud\Kernel\DiSyL\v4\AST\CommentNode;
use Ikabud\Kernel\DiSyL\v4\AST\ControlNode;
use Ikabud\Kernel\DiSyL\v4\AST\DocumentNode;
use Ikabud\Kernel\DiSyL\v4\AST\ExpressionNode;
use Ikabud\Kernel\DiSyL\v4\AST\FilterChain;
use Ikabud\Kernel\DiSyL\v4\AST\FilterNode;
use Ikabud\Kernel\DiSyL\v4\AST\IdentifierNode;
use Ikabud\Kernel\DiSyL\v4\AST\IncludeNode;
use Ikabud\Kernel\DiSyL\v4\AST\LiteralNode;
use Ikabud\Kernel\DiSyL\v4\AST\PropertyAccessNode;
use Ikabud\Kernel\DiSyL\v4\AST\SlotNode;
use Ikabud\Kernel\DiSyL\v4\AST\TextNode;
use Ikabud\Kernel\DiSyL\v4\AST\UnaryOpNode;

class Parser
{
    private string $source;
    private string $name;
    private int $pos;
    private int $len;

    /** Filters that suppress auto-escaping */
    private const ESCAPE_FILTERS = [
        'raw', 'esc_html', 'esc_attr', 'esc_url', 'esc_js',
        'json', 'url_encode', 'base64', 'nl2br',
    ];

    // ── Public API ──────────────────────────────────────────────

    public function parse(string $source, string $name = 'Anonymous'): DocumentNode
    {
        $this->source = $source;
        $this->name = $name;
        $this->pos = 0;
        $this->len = strlen($source);

        $children = $this->parseChildren([]);
        return new DocumentNode([], $children);
    }

    // ── Block-level parsing ─────────────────────────────────────

    /**
     * Parse child nodes until one of the $stopPatterns is found at position.
     * Each stop pattern is a literal string that must appear at the current
     * position (e.g. "{/if}", "{elseif ", "{else}", "{empty}").
     *
     * When this method returns, $this->pos is at the first character of the
     * matched stop pattern (or at end-of-source).
     *
     * @return AbstractNode[]
     */
    private function parseChildren(array $stopPatterns): array
    {
        $children = [];

        while ($this->pos < $this->len) {
            // ── check stop patterns first ──
            if ($this->source[$this->pos] === '{' && $this->isAtStop($stopPatterns)) {
                return $children;
            }

            // ── try to parse a DiSyL construct ──
            if ($this->source[$this->pos] === '{' && $this->looksLikeDisyl()) {
                $node = $this->parseDisylTag();
                if ($node !== null) {
                    $children[] = $node;
                    continue;
                }
            }

            // ── consume plain text ──
            $text = $this->readPlainText($stopPatterns);
            if ($text !== '') {
                $children[] = new TextNode([], $text);
            }
        }

        return $children;
    }

    /**
     * Read plain text until the next DiSyL tag or stop pattern.
     */
    private function readPlainText(array $stopPatterns): string
    {
        $start = $this->pos;

        while ($this->pos < $this->len) {
            if ($this->source[$this->pos] === '{') {
                if ($this->isAtStop($stopPatterns)) {
                    break;
                }
                if ($this->looksLikeDisyl()) {
                    break;
                }
            }
            $this->pos++;
        }

        return substr($this->source, $start, $this->pos - $start);
    }

    // ── Tag dispatching ─────────────────────────────────────────

    /**
     * Check whether the current position sits on one of the stop patterns.
     */
    private function isAtStop(array $patterns): bool
    {
        foreach ($patterns as $p) {
            if ($this->lookingAt($p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Peek ahead and decide whether the `{` at $this->pos opens a DiSyL tag.
     */
    private function looksLikeDisyl(): bool
    {
        if ($this->pos + 1 >= $this->len) {
            return false;
        }
        // Skip JS template-literal ${...}
        if ($this->pos > 0 && $this->source[$this->pos - 1] === '$') {
            return false;
        }
        $next = $this->source[$this->pos + 1];
        // Comments  {!-- or {*
        if ($next === '!' || $next === '*') {
            return true;
        }
        // Closing tags {/...}
        if ($next === '/') {
            return true;
        }
        // Identifiers / keywords
        if (ctype_alpha($next) || $next === '_') {
            return true;
        }
        return false;
    }

    /**
     * Dispatch the DiSyL tag at the current position.
     */
    private function parseDisylTag(): ?AbstractNode
    {
        $savedPos = $this->pos;
        $peek = substr($this->source, $this->pos + 1, 20);

        // Comments
        if (str_starts_with($peek, '!--')) {
            return $this->parseDashComment();
        }
        if (isset($peek[0]) && $peek[0] === '*') {
            return $this->parseStarComment();
        }

        // Raw blocks
        if (str_starts_with($peek, 'verbatim}')) {
            return $this->parseVerbatimBlock();
        }
        if (str_starts_with($peek, 'literal}')) {
            return $this->parseLiteralBlock();
        }

        // Control structures (order matters: foreach before for)
        if (preg_match('/^foreach[\s}]/', $peek)) {
            return $this->parseForeach();
        }
        if (preg_match('/^for[\s}]/', $peek)) {
            return $this->parseFor();
        }
        if (preg_match('/^each[\s}]/', $peek)) {
            return $this->parseEach();
        }
        if (preg_match('/^if[\s}]/', $peek)) {
            return $this->parseIf();
        }
        if (preg_match('/^set\s/', $peek)) {
            return $this->parseSetTag();
        }
        if (preg_match('/^include\s/', $peek)) {
            return $this->parseIncludeTag();
        }
        if (preg_match('/^extends\s/', $peek)) {
            return $this->parseExtendsTag();
        }
        if (preg_match('/^block\s/', $peek)) {
            return $this->parseBlockTag();
        }
        if (preg_match('/^slot[\s}]/', $peek)) {
            return $this->parseSlotTag();
        }

        // Expression / variable
        $content = $this->readTagContent();
        if ($content !== null && trim($content) !== '') {
            return $this->buildExpressionNode(trim($content));
        }

        // Can't parse — backtrack
        $this->pos = $savedPos;
        $this->pos++; // consume the `{` as text
        return new TextNode([], '{');
    }

    // ── Specific tag parsers ────────────────────────────────────

    /** {!-- comment --} */
    private function parseDashComment(): CommentNode
    {
        $start = $this->pos;
        $end = strpos($this->source, '--}', $this->pos + 4);
        if ($end === false) {
            // Unterminated — consume to end
            $content = substr($this->source, $this->pos);
            $this->pos = $this->len;
            return new CommentNode([], $content);
        }
        $content = substr($this->source, $this->pos + 4, $end - $this->pos - 4);
        $this->pos = $end + 3;
        return new CommentNode([], trim($content));
    }

    /** {* comment *} */
    private function parseStarComment(): CommentNode
    {
        $end = strpos($this->source, '*}', $this->pos + 2);
        if ($end === false) {
            $content = substr($this->source, $this->pos);
            $this->pos = $this->len;
            return new CommentNode([], $content);
        }
        $content = substr($this->source, $this->pos + 2, $end - $this->pos - 2);
        $this->pos = $end + 2;
        return new CommentNode([], trim($content));
    }

    /** {verbatim}...{/verbatim} */
    private function parseVerbatimBlock(): TextNode
    {
        $this->pos += strlen('{verbatim}');
        $end = strpos($this->source, '{/verbatim}', $this->pos);
        if ($end === false) {
            $raw = substr($this->source, $this->pos);
            $this->pos = $this->len;
            return new TextNode([], $raw);
        }
        $raw = substr($this->source, $this->pos, $end - $this->pos);
        $this->pos = $end + strlen('{/verbatim}');
        return new TextNode([], $raw);
    }

    /** {literal}...{/literal} */
    private function parseLiteralBlock(): TextNode
    {
        $this->pos += strlen('{literal}');
        $end = strpos($this->source, '{/literal}', $this->pos);
        if ($end === false) {
            $raw = substr($this->source, $this->pos);
            $this->pos = $this->len;
            return new TextNode([], $raw);
        }
        $raw = substr($this->source, $this->pos, $end - $this->pos);
        $this->pos = $end + strlen('{/literal}');
        return new TextNode([], $raw);
    }

    /** {if condition}...{elseif condition}...{else}...{/if} */
    private function parseIf(): ControlNode
    {
        $tag = $this->readTagContent();            // "if condition"
        $condition = trim(substr($tag, 2));         // strip "if"

        $body = $this->parseChildren(['{/if}', '{elseif ', '{else}']);
        $elseDoc = null;

        if ($this->lookingAt('{elseif ')) {
            // Desugar elseif as nested if inside else
            $elseDoc = new DocumentNode([], [$this->parseElseIf()]);
        } elseif ($this->lookingAt('{else}')) {
            $this->consumeExact('{else}');
            $elseDoc = new DocumentNode([], $this->parseChildren(['{/if}']));
        }

        $this->consumeExact('{/if}');

        return new ControlNode(
            [],
            'if',
            ['condition' => $this->parseExprValue($condition)],
            new DocumentNode([], $body),
            $elseDoc
        );
    }

    /** Parse an {elseif ...} as a nested ControlNode('if'). */
    private function parseElseIf(): ControlNode
    {
        $tag = $this->readTagContent();             // "elseif condition"
        $condition = trim(substr($tag, 6));          // strip "elseif"

        $body = $this->parseChildren(['{/if}', '{elseif ', '{else}']);
        $elseDoc = null;

        if ($this->lookingAt('{elseif ')) {
            $elseDoc = new DocumentNode([], [$this->parseElseIf()]);
        } elseif ($this->lookingAt('{else}')) {
            $this->consumeExact('{else}');
            $elseDoc = new DocumentNode([], $this->parseChildren(['{/if}']));
        }

        // Don't consume {/if} here — the outer parseIf does that.

        return new ControlNode(
            [],
            'if',
            ['condition' => $this->parseExprValue($condition)],
            new DocumentNode([], $body),
            $elseDoc
        );
    }

    /** {for item in list}...{empty}...{/for} */
    private function parseFor(): ControlNode
    {
        $tag = $this->readTagContent();             // "for item in list"
        $expr = trim(substr($tag, 3));               // strip "for"

        // Parse "item in iterable"
        if (!preg_match('/^(\w+)\s+in\s+(.+)$/s', $expr, $m)) {
            return $this->makeTextFallback('{' . $tag . '}');
        }
        $itemName = $m[1];
        $iterable = trim($m[2]);

        $body = $this->parseChildren(['{/for}', '{empty}']);
        $elseDoc = null;

        if ($this->lookingAt('{empty}')) {
            $this->consumeExact('{empty}');
            $elseDoc = new DocumentNode([], $this->parseChildren(['{/for}']));
        }

        $this->consumeExact('{/for}');

        return new ControlNode(
            [],
            'for',
            ['item' => $itemName, 'iterable' => $this->parseExprValue($iterable)],
            new DocumentNode([], $body),
            $elseDoc
        );
    }

    /** {foreach list as [key =>] value}...{empty}...{/foreach} */
    private function parseForeach(): ControlNode
    {
        $tag = $this->readTagContent();
        $expr = trim(substr($tag, 7));               // strip "foreach"

        $itemName = null;
        $keyName = null;
        $iterable = null;

        if (preg_match('/^(.+)\s+as\s+(\w+)\s*=>\s*(\w+)$/s', $expr, $m)) {
            $iterable = trim($m[1]);
            $keyName = $m[2];
            $itemName = $m[3];
        } elseif (preg_match('/^(.+)\s+as\s+(\w+)$/s', $expr, $m)) {
            $iterable = trim($m[1]);
            $itemName = $m[2];
        } else {
            return $this->makeTextFallback('{' . $tag . '}');
        }

        $body = $this->parseChildren(['{/foreach}', '{empty}']);
        $elseDoc = null;

        if ($this->lookingAt('{empty}')) {
            $this->consumeExact('{empty}');
            $elseDoc = new DocumentNode([], $this->parseChildren(['{/foreach}']));
        }

        $this->consumeExact('{/foreach}');

        $attrs = ['item' => $itemName, 'iterable' => $this->parseExprValue($iterable)];
        if ($keyName !== null) {
            $attrs['key'] = $keyName;
        }

        return new ControlNode([], 'for', $attrs, new DocumentNode([], $body), $elseDoc);
    }

    /** {each list as [key =>] value}...{empty}...{/each} */
    private function parseEach(): ControlNode
    {
        $tag = $this->readTagContent();
        $expr = trim(substr($tag, 4));               // strip "each"

        $itemName = null;
        $keyName = null;
        $iterable = null;

        if (preg_match('/^(.+)\s+as\s+(\w+)\s*=>\s*(\w+)$/s', $expr, $m)) {
            $iterable = trim($m[1]);
            $keyName = $m[2];
            $itemName = $m[3];
        } elseif (preg_match('/^(.+)\s+as\s+(\w+)$/s', $expr, $m)) {
            $iterable = trim($m[1]);
            $itemName = $m[2];
        } else {
            return $this->makeTextFallback('{' . $tag . '}');
        }

        $body = $this->parseChildren(['{/each}', '{empty}']);
        $elseDoc = null;

        if ($this->lookingAt('{empty}')) {
            $this->consumeExact('{empty}');
            $elseDoc = new DocumentNode([], $this->parseChildren(['{/each}']));
        }

        $this->consumeExact('{/each}');

        $attrs = ['item' => $itemName, 'iterable' => $this->parseExprValue($iterable)];
        if ($keyName !== null) {
            $attrs['key'] = $keyName;
        }

        return new ControlNode([], 'for', $attrs, new DocumentNode([], $body), $elseDoc);
    }

    /** {set name = expr} */
    private function parseSetTag(): ControlNode
    {
        $tag = $this->readTagContent();              // "set name = expr"
        $inner = trim(substr($tag, 3));               // strip "set"

        $eqPos = strpos($inner, '=');
        if ($eqPos === false) {
            return $this->makeTextFallback('{' . $tag . '}');
        }

        $name = trim(substr($inner, 0, $eqPos));
        $value = trim(substr($inner, $eqPos + 1));

        return new ControlNode([], 'set', [
            'name' => $name,
            'value' => $this->parseExprValue($value),
        ]);
    }

    /** {include "template" [with {k: v}]} */
    private function parseIncludeTag(): IncludeNode
    {
        $tag = $this->readTagContent();
        $inner = trim(substr($tag, 7));               // strip "include"

        $template = '';
        $variables = [];

        // Extract quoted template path
        if (preg_match('/^["\']([^"\']+)["\']/', $inner, $m)) {
            $template = $m[1];
            $rest = trim(substr($inner, strlen($m[0])));

            // Parse optional "with {key: value, ...}"
            if (preg_match('/^with\s+\{(.+)\}$/s', $rest, $wm)) {
                $variables = $this->parseInlineObject($wm[1]);
            }
        }

        return new IncludeNode([], $template, $variables);
    }

    /** {extends "parent"} */
    private function parseExtendsTag(): ControlNode
    {
        $tag = $this->readTagContent();
        $inner = trim(substr($tag, 7));               // strip "extends"

        $template = '';
        if (preg_match('/^["\']([^"\']+)["\']/', $inner, $m)) {
            $template = $m[1];
        }

        return new ControlNode([], 'extends', ['template' => $template]);
    }

    /** {block name}...{/block} */
    private function parseBlockTag(): ControlNode
    {
        $tag = $this->readTagContent();
        $name = trim(substr($tag, 5));                // strip "block"

        $body = $this->parseChildren(['{/block}']);
        $this->consumeExact('{/block}');

        return new ControlNode([], 'block', ['name' => $name], new DocumentNode([], $body));
    }

    /** {slot name}...{/slot} */
    private function parseSlotTag(): SlotNode
    {
        $tag = $this->readTagContent();
        $name = trim(substr($tag, 4));                // strip "slot"

        // Self-closing: {slot name}  (no {/slot} follows)
        // Block: {slot name}default{/slot}
        if ($this->lookingAt('{/slot}')) {
            $this->consumeExact('{/slot}');
            return new SlotNode([], $name);
        }

        // Peek ahead for {/slot} to decide if it's a block slot
        $slotEnd = strpos($this->source, '{/slot}', $this->pos);
        if ($slotEnd !== false) {
            $body = $this->parseChildren(['{/slot}']);
            $this->consumeExact('{/slot}');
            return new SlotNode([], $name, new DocumentNode([], $body));
        }

        return new SlotNode([], $name);
    }

    // ── Expression building ─────────────────────────────────────

    /**
     * Build an ExpressionNode (or ControlNode for ternary) from the raw
     * content between { and }.
     */
    private function buildExpressionNode(string $content): AbstractNode
    {
        // ── ternary? ──
        $qPos = $this->findUnquotedChar($content, '?');
        if ($qPos !== false) {
            $colonPos = $this->findUnquotedChar($content, ':', $qPos + 1);
            if ($colonPos !== false) {
                // Only treat as ternary if ? comes before any |
                $pipePos = $this->findUnquotedChar($content, '|');
                if ($pipePos === false || $qPos < $pipePos) {
                    return $this->buildTernary($content, $qPos, $colonPos);
                }
            }
        }

        // ── split filters ──
        $parts = $this->splitByPipe($content);
        $baseExpr = trim($parts[0]);

        $filterChain = null;
        $autoEscape = true;

        if (count($parts) > 1) {
            $filters = [];
            for ($i = 1; $i < count($parts); $i++) {
                $filter = $this->parseFilterSpec(trim($parts[$i]));
                $filters[] = $filter;
                if (in_array($filter->getName(), self::ESCAPE_FILTERS, true)) {
                    $autoEscape = false;
                }
            }
            $filterChain = new FilterChain($filters);
        }

        return new ExpressionNode(
            [],
            $this->parseExprValue($baseExpr),
            $filterChain,
            $autoEscape
        );
    }

    /**
     * Desugar {cond ? trueExpr : falseExpr} into an if/else ControlNode.
     */
    private function buildTernary(string $content, int $qPos, int $colonPos): ControlNode
    {
        $cond = trim(substr($content, 0, $qPos));
        $trueExpr = trim(substr($content, $qPos + 1, $colonPos - $qPos - 1));
        $falseExpr = trim(substr($content, $colonPos + 1));

        $trueNode = new ExpressionNode([], $this->parseExprValue($trueExpr), null, true);
        $falseNode = new ExpressionNode([], $this->parseExprValue($falseExpr), null, true);

        return new ControlNode(
            [],
            'if',
            ['condition' => $this->parseExprValue($cond)],
            new DocumentNode([], [$trueNode]),
            new DocumentNode([], [$falseNode])
        );
    }

    // ── Expression parser (recursive descent on strings) ────────

    /**
     * Parse an expression string into an AST node.
     */
    private function parseExprValue(string $expr): AbstractNode
    {
        $expr = trim($expr);
        if ($expr === '') {
            return new LiteralNode([], null);
        }
        return $this->parseOrExpr($expr);
    }

    private function parseOrExpr(string $expr): AbstractNode
    {
        $split = $this->findLastBinaryOp($expr, [' or ', ' || ']);
        if ($split !== false) {
            return new BinaryOpNode(
                [],
                $this->parseOrExpr(trim($split[1])),
                'or',
                $this->parseAndExpr(trim($split[2]))
            );
        }
        return $this->parseAndExpr($expr);
    }

    private function parseAndExpr(string $expr): AbstractNode
    {
        $split = $this->findLastBinaryOp($expr, [' and ', ' && ']);
        if ($split !== false) {
            return new BinaryOpNode(
                [],
                $this->parseAndExpr(trim($split[1])),
                'and',
                $this->parseCompExpr(trim($split[2]))
            );
        }
        return $this->parseCompExpr($expr);
    }

    private function parseCompExpr(string $expr): AbstractNode
    {
        // Try longest operators first
        $split = $this->findLastBinaryOp($expr, [' === ', ' !== ', ' == ', ' != ', ' >= ', ' <= ', ' > ', ' < ']);
        if ($split !== false) {
            return new BinaryOpNode(
                [],
                $this->parseAddExpr(trim($split[1])),
                trim($split[0]),
                $this->parseAddExpr(trim($split[2]))
            );
        }
        return $this->parseAddExpr($expr);
    }

    private function parseAddExpr(string $expr): AbstractNode
    {
        $split = $this->findLastBinaryOp($expr, ['+', '-'], true);
        if ($split !== false && trim($split[1]) !== '') {
            return new BinaryOpNode(
                [],
                $this->parseAddExpr(trim($split[1])),
                trim($split[0]),
                $this->parseMulExpr(trim($split[2]))
            );
        }
        return $this->parseMulExpr($expr);
    }

    private function parseMulExpr(string $expr): AbstractNode
    {
        $split = $this->findLastBinaryOp($expr, ['*', '/', '%']);
        if ($split !== false && trim($split[1]) !== '') {
            return new BinaryOpNode(
                [],
                $this->parseMulExpr(trim($split[1])),
                trim($split[0]),
                $this->parseUnaryExpr(trim($split[2]))
            );
        }
        return $this->parseUnaryExpr($expr);
    }

    private function parseUnaryExpr(string $expr): AbstractNode
    {
        $expr = trim($expr);
        if ($expr === '') {
            return new LiteralNode([], null);
        }

        // not keyword
        if (preg_match('/^not\s+(.+)$/is', $expr, $m)) {
            return new UnaryOpNode([], 'not', $this->parseUnaryExpr($m[1]));
        }
        // ! operator
        if ($expr[0] === '!' && strlen($expr) > 1 && $expr[1] !== '=') {
            return new UnaryOpNode([], 'not', $this->parseUnaryExpr(ltrim(substr($expr, 1))));
        }
        // unary minus (only if followed by digit or identifier)
        if ($expr[0] === '-' && strlen($expr) > 1 && (ctype_alnum($expr[1]) || $expr[1] === '(')) {
            return new UnaryOpNode([], '-', $this->parsePrimaryExpr(ltrim(substr($expr, 1))));
        }

        return $this->parsePrimaryExpr($expr);
    }

    private function parsePrimaryExpr(string $expr): AbstractNode
    {
        $expr = trim($expr);
        if ($expr === '') {
            return new LiteralNode([], null);
        }

        // Parenthesized expression
        if ($expr[0] === '(') {
            $close = $this->findMatchingParen($expr, 0);
            if ($close === strlen($expr) - 1) {
                return $this->parseExprValue(substr($expr, 1, -1));
            }
        }

        // Quoted string
        if (($expr[0] === '"' || $expr[0] === "'") && strlen($expr) >= 2) {
            $quote = $expr[0];
            if ($expr[strlen($expr) - 1] === $quote) {
                return new LiteralNode([], substr($expr, 1, -1));
            }
        }

        // Boolean / null literals
        $lower = strtolower($expr);
        if ($lower === 'true') {
            return new LiteralNode([], true);
        }
        if ($lower === 'false') {
            return new LiteralNode([], false);
        }
        if ($lower === 'null' || $lower === 'none') {
            return new LiteralNode([], null);
        }

        // Numeric literal
        if (is_numeric($expr)) {
            return new LiteralNode(
                [],
                str_contains($expr, '.') ? (float)$expr : (int)$expr
            );
        }

        // Dot-path variable (e.g. "user.profile.name")
        if (preg_match('/^[a-zA-Z_]\w*(?:\.[a-zA-Z_]\w*)*$/', $expr)) {
            return $this->buildDotPath($expr);
        }

        // Fallback: bare identifier
        if (preg_match('/^[a-zA-Z_]\w*$/', $expr)) {
            return new IdentifierNode([], $expr);
        }

        // Filter chain: "base | filter1 | filter2" with a single pipe (not ||) at depth 0.
        // Handles sub-expressions like "items | count" in "items | count > 0".
        $pipePos = $this->findOuterSinglePipe($expr);
        if ($pipePos !== false) {
            $base = trim(substr($expr, 0, $pipePos));
            $filterStr = trim(substr($expr, $pipePos + 1));
            $filterParts = $this->splitByPipe($filterStr);
            $filters = array_map(fn($f) => $this->parseFilterSpec(trim($f)), $filterParts);
            $baseNode = $this->parsePrimaryExpr($base);
            return new ExpressionNode([], $baseNode, new FilterChain($filters), false);
        }

        // Last resort: treat as string literal
        return new LiteralNode([], $expr);
    }

    /**
     * Build a PropertyAccessNode chain from "a.b.c".
     */
    private function buildDotPath(string $path): AbstractNode
    {
        $parts = explode('.', $path);
        $node = new IdentifierNode([], $parts[0]);
        for ($i = 1, $c = count($parts); $i < $c; $i++) {
            $node = new PropertyAccessNode([], $node, $parts[$i], false);
        }
        return $node;
    }

    /**
     * Parse "filterName" or "filterName:arg1,arg2" into a FilterNode.
     */
    private function parseFilterSpec(string $spec): FilterNode
    {
        $colonPos = strpos($spec, ':');
        if ($colonPos === false) {
            return new FilterNode($spec);
        }

        $name = substr($spec, 0, $colonPos);
        $argsStr = substr($spec, $colonPos + 1);
        $args = $this->splitFilterArgs($argsStr);

        // Normalize args: strip quotes, convert numbers, resolve variable paths
        $normalized = [];
        foreach ($args as $arg) {
            $arg = trim($arg);
            if ($arg === '') {
                continue;
            }
            if (preg_match('/^["\'](.*)["\']\s*$/', $arg, $m)) {
                // Quoted string literal → plain string (stays a scalar, not a node)
                $normalized[] = $m[1];
            } elseif (is_numeric($arg)) {
                // Numeric literal → scalar
                $normalized[] = str_contains($arg, '.') ? (float)$arg : (int)$arg;
            } else {
                // Unquoted, non-numeric: parse as an expression so variable paths
                // (e.g. "entity.title", "user.name") become AbstractNode instances
                // that compileFilterChain() will compile to runtime $ctx->get() calls.
                $normalized[] = $this->parsePrimaryExpr($arg);
            }
        }

        return new FilterNode($name, $normalized);
    }

    /**
     * Split filter arguments by comma, respecting quotes.
     */
    private function splitFilterArgs(string $str): array
    {
        $parts = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;

        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '\\' && ($inSingle || $inDouble) && $i + 1 < $len) {
                $current .= $ch . $str[$i + 1];
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $current .= $ch;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $current .= $ch;
                continue;
            }
            if ($ch === ',' && !$inSingle && !$inDouble) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    // ── Inline object parsing ───────────────────────────────────

    /**
     * Parse "key: value, key2: value2" into an associative array of strings.
     */
    private function parseInlineObject(string $str): array
    {
        $result = [];
        $pairs = $this->splitFilterArgs($str); // reuse comma splitting
        foreach ($pairs as $pair) {
            $colonPos = strpos($pair, ':');
            if ($colonPos !== false) {
                $key = trim(substr($pair, 0, $colonPos));
                $val = trim(substr($pair, $colonPos + 1));
                // Strip quotes from value
                if (preg_match('/^["\'](.*)["\']\s*$/', $val, $m)) {
                    $val = $m[1];
                }
                $result[$key] = $val;
            }
        }
        return $result;
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Read the content between { and the matching }.
     * Handles nested braces and quoted strings.
     * Advances $this->pos past the closing }.
     *
     * @return string|null Content between braces, or null on failure.
     */
    private function readTagContent(): ?string
    {
        if ($this->pos >= $this->len || $this->source[$this->pos] !== '{') {
            return null;
        }

        $this->pos++; // skip {
        $start = $this->pos;
        $depth = 1;
        $inSingle = false;
        $inDouble = false;

        while ($this->pos < $this->len && $depth > 0) {
            $ch = $this->source[$this->pos];

            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $this->pos += 2;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble) {
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                }
            }

            if ($depth > 0) {
                $this->pos++;
            }
        }

        if ($depth !== 0) {
            return null;
        }

        $content = substr($this->source, $start, $this->pos - $start);
        $this->pos++; // skip closing }
        return $content;
    }

    /**
     * Consume an exact string at current position.
     */
    private function consumeExact(string $str): bool
    {
        if ($this->lookingAt($str)) {
            $this->pos += strlen($str);
            return true;
        }
        return false;
    }

    /**
     * Check if source at current position matches $str.
     */
    private function lookingAt(string $str): bool
    {
        return substr_compare($this->source, $str, $this->pos, strlen($str)) === 0;
    }

    /**
     * Split an expression string by | (pipe) at depth 0, outside quotes.
     *
     * @return string[]
     */
    /**
     * Find the position of the first single pipe (|) at depth 0, outside quotes.
     * Returns false if none exists or if the only pipes are double-pipes (||).
     */
    private function findOuterSinglePipe(string $expr): int|false
    {
        $len = strlen($expr);
        $inSingle = false; $inDouble = false; $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($ch === '\\' && ($inSingle || $inDouble) && $i + 1 < $len) { $i++; continue; }
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; continue; }
            if ($inSingle || $inDouble) continue;
            if ($ch === '(') { $depth++; continue; }
            if ($ch === ')') { $depth--; continue; }
            if ($depth === 0 && $ch === '|') {
                $prev = $i > 0 ? $expr[$i - 1] : '';
                $next = $i + 1 < $len ? $expr[$i + 1] : '';
                if ($prev !== '|' && $next !== '|') {
                    return $i;
                }
            }
        }
        return false;
    }

    private function splitByPipe(string $expr): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;

        for ($i = 0, $len = strlen($expr); $i < $len; $i++) {
            $ch = $expr[$i];

            if ($ch === '\\' && ($inSingle || $inDouble) && $i + 1 < $len) {
                $current .= $ch . $expr[$i + 1];
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $current .= $ch;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $current .= $ch;
                continue;
            }

            if (!$inSingle && !$inDouble) {
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                }

                if ($ch === '|' && $depth === 0) {
                    // Skip || (double-pipe used as logical-OR operator)
                    if ($i + 1 < $len && $expr[$i + 1] === '|') {
                        $current .= '||';
                        $i++; // consume both chars
                        continue;
                    }
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
            }

            $current .= $ch;
        }
        if ($current !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    /**
     * Find the last occurrence of any operator in $ops within $expr,
     * outside quotes and parentheses.
     *
     * @param bool $checkBinaryContext  When true (for +/-), only match
     *                                  when preceded by a value-like character.
     * @return array{0: string, 1: string, 2: string}|false  [op, left, right]
     */
    private function findLastBinaryOp(string $expr, array $ops, bool $checkBinaryContext = false): array|false
    {
        // Sort by length descending to prefer longer matches
        usort($ops, fn($a, $b) => strlen($b) - strlen($a));

        $best = false;
        $bestPos = -1;
        $inSingle = false;
        $inDouble = false;
        $parenDepth = 0;
        $len = strlen($expr);

        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];

            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }
            if ($inSingle || $inDouble) {
                continue;
            }
            if ($ch === '(') {
                $parenDepth++;
                continue;
            }
            if ($ch === ')') {
                $parenDepth--;
                continue;
            }
            if ($parenDepth > 0) {
                continue;
            }

            foreach ($ops as $op) {
                $opLen = strlen($op);
                if ($i + $opLen > $len) {
                    continue;
                }
                if (substr($expr, $i, $opLen) !== $op) {
                    continue;
                }

                // For +/- as binary: preceding non-space char must be value-like
                if ($checkBinaryContext) {
                    $prev = $this->lastNonSpaceChar($expr, $i);
                    if ($prev === false) {
                        continue;
                    }
                    if (!ctype_alnum($prev) && $prev !== ')' && $prev !== "'" && $prev !== '"' && $prev !== '_' && $prev !== '.') {
                        continue;
                    }
                }

                if ($i >= $bestPos) {
                    $bestPos = $i;
                    $best = [
                        $op,
                        substr($expr, 0, $i),
                        substr($expr, $i + $opLen),
                    ];
                }
                break; // take first (longest) match at this position
            }
        }

        return $best;
    }

    /**
     * Find first unquoted occurrence of $char in $str starting at $start.
     */
    private function findUnquotedChar(string $str, string $char, int $start = 0): int|false
    {
        $inSingle = false;
        $inDouble = false;
        $parenDepth = 0;

        for ($i = $start, $len = strlen($str); $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }
            if (!$inSingle && !$inDouble) {
                if ($ch === '(') {
                    $parenDepth++;
                } elseif ($ch === ')') {
                    $parenDepth--;
                }
                if ($ch === $char && $parenDepth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * Find the position of the matching closing parenthesis.
     */
    private function findMatchingParen(string $str, int $openPos): int
    {
        $depth = 0;
        for ($i = $openPos, $len = strlen($str); $i < $len; $i++) {
            if ($str[$i] === '(') {
                $depth++;
            } elseif ($str[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }

    /**
     * Return the last non-whitespace character before position $before,
     * or false if there is none.
     */
    private function lastNonSpaceChar(string $str, int $before): string|false
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            if (!ctype_space($str[$i])) {
                return $str[$i];
            }
        }
        return false;
    }

    /**
     * Fallback: emit the original tag text as a TextNode when a tag
     * cannot be parsed.
     */
    private function makeTextFallback(string $text): TextNode
    {
        return new TextNode([], $text);
    }
}
