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
use Ikabud\Kernel\DiSyL\v4\AST\FunctionCallNode;
use Ikabud\Kernel\DiSyL\v4\AST\IdentifierNode;
use Ikabud\Kernel\DiSyL\v4\AST\IncludeNode;
use Ikabud\Kernel\DiSyL\v4\AST\LiteralNode;
use Ikabud\Kernel\DiSyL\v4\AST\PropertyAccessNode;
use Ikabud\Kernel\DiSyL\v4\AST\SlotNode;
use Ikabud\Kernel\DiSyL\v4\AST\TextNode;
use Ikabud\Kernel\DiSyL\v4\AST\UnaryOpNode;

final class Parser
{
    private string $source;
    private string $name;
    private int $pos;
    private int $len;

    /**
     * Maximum control-structure/expression nesting depth. Guards against
     * stack overflow from malicious or accidental deeply-nested templates
     * (e.g. 1000+ nested {if} blocks or ((((...)))) expression chains).
     */
    private const MAX_PARSE_DEPTH = 256;

    /**
     * Maximum template source size in bytes. Templates larger than this are
     * rejected before parsing to prevent memory exhaustion from a crafted or
     * runaway template file.
     */
    private const MAX_SOURCE_BYTES = 10 * 1024 * 1024; // 10 MB

    /** Current recursion depth (block + expression nesting combined). */
    private int $depth = 0;

    /** Filters that suppress auto-escaping */
    private const ESCAPE_FILTERS = [
        'raw', 'esc_html', 'esc_attr', 'esc_url', 'esc_js',
        'json', 'url_encode', 'base64', 'nl2br',
    ];

    // ── Public API ──────────────────────────────────────────────

    public function parse(string $source, string $name = 'Anonymous'): DocumentNode
    {
        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new \RuntimeException(
                sprintf(
                    'DiSyL template source exceeds maximum size (%d bytes): %s (%d bytes)',
                    self::MAX_SOURCE_BYTES,
                    $name,
                    strlen($source)
                )
            );
        }

        $this->source = $source;
        $this->name = $name;
        $this->pos = 0;
        $this->len = strlen($source);
        $this->depth = 0;

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
        if (++$this->depth > self::MAX_PARSE_DEPTH) {
            $this->depth--;
            throw new \RuntimeException(
                sprintf('DiSyL template nesting exceeds max parse depth (%d) in %s', self::MAX_PARSE_DEPTH, $this->name)
            );
        }

        try {
            return $this->parseChildrenInner($stopPatterns);
        } finally {
            $this->depth--;
        }
    }

    private function parseChildrenInner(array $stopPatterns): array
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
        // Comments  {!-- or {* or {#
        if ($next === '!' || $next === '*' || $next === '#') {
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
        // Parenthesized expressions {(a + b) * c}, array literals {[1, 2]}, and numeric literals {503}
        if ($next === '(' || $next === '[' || ctype_digit($next)) {
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
        if (isset($peek[0]) && $peek[0] === '#') {
            return $this->parseHashComment();
        }

        // Raw blocks
        if (str_starts_with($peek, 'verbatim}')) {
            return $this->parseVerbatimBlock();
        }
        if (str_starts_with($peek, 'literal}')) {
            return $this->parseLiteralBlock();
        }

        // Control structures (order matters: foreach before for)
        // Each control block is wrapped in try/catch for per-block error
        // recovery — a single malformed block won't kill the entire template.
        if (preg_match('/^foreach[\s}]/', $peek)) {
            return $this->recoverableParse($this->parseForeach(...), 'foreach', $savedPos);
        }
        if (preg_match('/^for[\s}]/', $peek)) {
            return $this->recoverableParse($this->parseFor(...), 'for', $savedPos);
        }
        if (preg_match('/^each[\s}]/', $peek)) {
            return $this->recoverableParse($this->parseEach(...), 'each', $savedPos);
        }
        if (preg_match('/^if[\s}]/', $peek)) {
            return $this->recoverableParse($this->parseIf(...), 'if', $savedPos);
        }
        if (preg_match('/^while[\s}]/', $peek)) {
            return $this->recoverableParse($this->parseWhile(...), 'while', $savedPos);
        }
        if (preg_match('/^break\s*}/', $peek)) {
            return $this->recoverableParse($this->parseBreak(...), 'break', $savedPos);
        }
        if (preg_match('/^continue\s*}/', $peek)) {
            return $this->recoverableParse($this->parseContinue(...), 'continue', $savedPos);
        }
        if (preg_match('/^match[\s}]/', $peek)) {
            return $this->recoverableParse($this->parseMatch(...), 'match', $savedPos);
        }
        if (preg_match('/^macro\s/', $peek)) {
            return $this->recoverableParse($this->parseMacro(...), 'macro', $savedPos);
        }
        if (preg_match('/^call\s/', $peek)) {
            return $this->recoverableParse($this->parseCall(...), 'call', $savedPos);
        }
        if (preg_match('/^set\s/', $peek)) {
            return $this->recoverableParse($this->parseSetTag(...), 'set', $savedPos);
        }
        if (preg_match('/^include\s/', $peek)) {
            return $this->recoverableParse($this->parseIncludeTag(...), 'include', $savedPos);
        }
        if (preg_match('/^extends\s/', $peek)) {
            return $this->recoverableParse($this->parseExtendsTag(...), 'extends', $savedPos);
        }
        if (preg_match('/^block\s/', $peek)) {
            return $this->recoverableParse($this->parseBlockTag(...), 'block', $savedPos);
        }
        if (preg_match('/^slot[\s}]/', $peek)) {
            return $this->recoverableParse($this->parseSlotTag(...), 'slot', $savedPos);
        }

        // Expression / variable
        $content = $this->readTagContent();
        if ($content !== null && trim($content) !== '') {
            $trimmed = trim($content);
            if ($this->isProcessableTemplateExpression($trimmed)) {
                return $this->buildExpressionNode($trimmed);
            }

            // Unsupported brace blocks such as Alpine object literals should
            // fall back to raw text so nested inner DiSyL tags can still parse.
            $this->pos = $savedPos + 1;
            return new TextNode([], '{');
        }

        // Can't parse — backtrack
        $this->pos = $savedPos;
        $this->pos++; // consume the `{` as text
        return new TextNode([], '{');
    }

    private function isProcessableTemplateExpression(string $expr): bool
    {
        if ($expr === '') {
            return false;
        }

        // Null-coalescing: {var ?? fallback}
        if (str_contains($expr, '??')) {
            return true;
        }

        $qPos = $this->findUnquotedChar($expr, '?');
        if ($qPos !== false) {
            $colonPos = $this->findUnquotedChar($expr, ':', $qPos + 1);
            $pipePos = $this->findUnquotedChar($expr, '|');
            if ($colonPos !== false && ($pipePos === false || $qPos < $pipePos)) {
                return true;
            }
        }

        if ($this->containsArithmeticOperator($expr)) {
            return true;
        }

        $parts = $this->splitByPipe($expr);
        $baseExpr = trim($parts[0] ?? '');

        // Array literal expression: {['a','b']} or {['a','b'] | filter}
        if ($baseExpr !== '' && $baseExpr[0] === '[') {
            return true;
        }

        // Bracket notation: {array[key]} or {array[key] | filter}
        if ($baseExpr !== '' && str_contains($baseExpr, '[') && str_ends_with($baseExpr, ']')) {
            return true;
        }

        // Function call: {funcname(args)} or {funcname(args) | filter}
        if (preg_match('/^[a-zA-Z_]\w*\s*\(/', $baseExpr)) {
            return true;
        }

        return preg_match('/^[a-zA-Z_][\w.]*$/', $baseExpr) === 1;
    }

    private function containsArithmeticOperator(string $expr): bool
    {
        if (!strpbrk($expr, '+-*/%()') && !str_contains($expr, '~')) {
            return false;
        }

        // String concatenation ~ has same precedence as +/-
        $tildeCheck = $this->findLastBinaryOp($expr, ['~'], true) !== false;

        return $tildeCheck
            || $this->findLastBinaryOp($expr, ['+', '-'], true) !== false
            || $this->findLastBinaryOp($expr, ['*', '/', '%']) !== false
            || ($expr[0] ?? '') === '('
            || (($expr[0] ?? '') === '-' && strlen($expr) > 1);
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

    /** {# comment #} (Twig/Jinja-style block comment) */
    private function parseHashComment(): CommentNode
    {
        $end = strpos($this->source, '#}', $this->pos + 2);
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

    /**
     * Per-block error recovery wrapper.
     *
     * Wraps a control-structure parse call so that a single malformed block
     * does not abort the entire template render. On failure the parser
     * advances past the offending block body and emits a comment node with
     * the error message.
     *
     * @param callable $parser      Bound parse* method (e.g. $this->parseIf(...))
     * @param string   $blockName   Human-readable block type for diagnostics
     * @param int      $savedPos    Position before the opening `{` was consumed
     */
    private function recoverableParse(callable $parser, string $blockName, int $savedPos): AbstractNode
    {
        try {
            return $parser();
        } catch (\Throwable $e) {
            // Log the parse error if write_log is available
            if (\function_exists('write_log')) {
                \write_log("DiSyL parse error in {$this->name}: {block}={$blockName} — {$e->getMessage()}", 'warning');
            }

            // Advance past the offending block: consume until a matching close
            // tag (e.g. {/if}, {/for}, {/foreach}, {/block}) or end-of-source.
            $closeTag = match ($blockName) {
                'if' => '{/if}',
                'while' => '{/while}',
                'match' => '{/match}',
                'macro' => '{/macro}',
                'for' => '{/for}',
                'cfor' => '{/for}',
                'foreach' => '{/foreach}',
                'each' => '{/each}',
                'block' => '{/block}',
                'slot' => '{/slot}',
                'set' => '{/set}',
                default => null,
            };

            if ($closeTag !== null) {
                $end = strpos($this->source, $closeTag, $savedPos);
                if ($end !== false) {
                    $this->pos = $end + strlen($closeTag);
                } else {
                    $this->pos = $this->len;
                }
            } else {
                // For set/include/extends — consume to end of tag
                $end = strpos($this->source, '}', $savedPos);
                if ($end !== false) {
                    $this->pos = $end + 1;
                } else {
                    $this->pos = $this->len;
                }
            }

            // Emit an HTML comment so developers can spot the failure
            $safeMsg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            return new CommentNode([], " DiSyL parse error ({$blockName}): {$safeMsg} ");
        }
    }

    /** {while condition}...{/while} */
    private function parseWhile(): ControlNode
    {
        $tag = $this->readTagContent();
        $condition = trim(substr($tag, 5));          // strip "while"

        $body = $this->parseChildren(['{/while}']);
        $this->consumeExact('{/while}');

        return new ControlNode(
            [],
            'while',
            ['condition' => $this->parseExprValue($condition)],
            new DocumentNode([], $body),
            null
        );
    }

    /** {break} */
    private function parseBreak(): ControlNode
    {
        $this->readTagContent();
        return new ControlNode([], 'break', []);
    }

    /** {continue} */
    private function parseContinue(): ControlNode
    {
        $this->readTagContent();
        return new ControlNode([], 'continue', []);
    }

    /** {if condition}...{elseif condition}...{else if condition}...{else}...{/if} */
    private function parseIf(): ControlNode
    {
        $tag = $this->readTagContent();            // "if condition"
        $condition = trim(substr($tag, 2));         // strip "if"

        $body = $this->parseChildren(['{/if}', '{elseif ', '{else if ', '{else}']);
        $elseDoc = null;

        if ($this->lookingAt('{elseif ') || $this->lookingAt('{else if ')) {
            // Desugar elseif / else if as nested if inside else
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

    /** Parse an {elseif ...} or {else if ...} as a nested ControlNode('if'). */
    private function parseElseIf(): ControlNode
    {
        $tag = $this->readTagContent();             // "elseif condition" or "else if condition"

        // Handle both {elseif cond} (6-char prefix) and {else if cond} (7-char prefix)
        if (str_starts_with($tag, 'else if ')) {
            $condition = trim(substr($tag, 7));      // strip "else if"
        } else {
            $condition = trim(substr($tag, 6));      // strip "elseif"
        }

        $body = $this->parseChildren(['{/if}', '{elseif ', '{else if ', '{else}']);
        $elseDoc = null;

        if ($this->lookingAt('{elseif ') || $this->lookingAt('{else if ')) {
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

    /** {match expr}{when "val"}...{/when}{else}...{/match} */
    private function parseMatch(): ControlNode
    {
        $tag = $this->readTagContent();              // "match expr"
        $expr = trim(substr($tag, 5));                // strip "match"

        $body = $this->parseChildren(['{/match}']);
        $this->consumeExact('{/match}');

        return new ControlNode(
            [],
            'match',
            ['expression' => $this->parseExprValue($expr)],
            new DocumentNode([], $body),
            null
        );
    }

    /** {macro name(param1, param2 = "default")}...{/macro} */
    private function parseMacro(): ControlNode
    {
        $tag = $this->readTagContent();
        $inner = trim(substr($tag, 5));               // strip "macro"

        // Parse "name(param1, param2 = default)"
        if (!preg_match('/^(\w+)\s*\((.*)\)\s*$/s', $inner, $m)) {
            throw new \RuntimeException("Invalid {macro} syntax in '{$inner}'");
        }
        $name = $m[1];
        $paramsRaw = $m[2];

        $params = $this->parseMacroParams($paramsRaw);

        $body = $this->parseChildren(['{/macro}']);
        $this->consumeExact('{/macro}');

        return new ControlNode(
            [],
            'macro',
            ['name' => $name, 'params' => $params],
            new DocumentNode([], $body),
            null
        );
    }

    /** Parse macro parameter list: "param1, param2 = default" */
    private function parseMacroParams(string $raw): array
    {
        $params = [];
        if (trim($raw) === '') {
            return $params;
        }
        $parts = $this->splitCommaTopLevel($raw);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            if (str_contains($part, '=')) {
                [$name, $default] = explode('=', $part, 2);
                $params[trim($name)] = trim($default);
            } else {
                $params[$part] = null; // required param (no default)
            }
        }
        return $params;
    }

    /** {call name(arg1, arg2, ...)} */
    private function parseCall(): ControlNode
    {
        $tag = $this->readTagContent();
        $inner = trim(substr($tag, 4));               // strip "call"

        // Parse "name(arg1, arg2, ...)"
        if (!preg_match('/^(\w+)\s*\((.*)\)\s*$/s', $inner, $m)) {
            // Simple call without parens: {call name}
            if (preg_match('/^(\w+)$/', $inner, $m2)) {
                return new ControlNode(
                    [],
                    'call',
                    ['name' => $m2[1], 'args' => []],
                    null, null
                );
            }
            throw new \RuntimeException("Invalid {call} syntax in '{$inner}'");
        }
        $name = $m[1];
        $argsRaw = $m[2];

        $args = $this->parseCallArgs($argsRaw);

        return new ControlNode(
            [],
            'call',
            ['name' => $name, 'args' => $args],
            null, null
        );
    }

    /** Parse call arguments: "arg1", expr, 42 */
    private function parseCallArgs(string $raw): array
    {
        $args = [];
        if (trim($raw) === '') {
            return $args;
        }
        $parts = $this->splitCommaTopLevel($raw);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            $args[] = $part;
        }
        return $args;
    }

    /** Split on commas not inside quotes or parens. */
    private function splitCommaTopLevel(string $input): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $ch = $input[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $buf .= $ch . $input[$i + 1];
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; $buf .= $ch; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; $buf .= $ch; continue; }
            if (!$inSingle && !$inDouble) {
                if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; }
                elseif ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; }
                elseif ($ch === ',' && $depth === 0) {
                    $parts[] = trim($buf);
                    $buf = '';
                    continue;
                }
            }
            $buf .= $ch;
        }
        $tail = trim($buf);
        if ($tail !== '') { $parts[] = $tail; }
        return $parts;
    }

    /** {for item in list}...{empty}...{/for}
     *  {for i = 0; i < 10; i++}...{/for} (C-style, semicolons) */
    private function parseFor(): ControlNode
    {
        $tag = $this->readTagContent();             // "for item in list" or "for i = 0; i < 10; i++"
        $expr = trim(substr($tag, 3));               // strip "for"

        // C-style for: {for init; condition; increment}
        if (substr_count($expr, ';') === 2) {
            $parts = explode(';', $expr);
            $initExpr = trim($parts[0]);
            $condExpr = trim($parts[1]);
            $incExpr  = trim($parts[2]);

            $body = $this->parseChildren(['{/for}']);
            $this->consumeExact('{/for}');

            return new ControlNode(
                [],
                'cfor',
                [
                    'init' => $this->parseExprValue($initExpr),
                    'condition' => $this->parseExprValue($condExpr),
                    'increment' => $this->parseExprValue($incExpr),
                ],
                new DocumentNode([], $body),
                null
            );
        }

        // Parse "key, value in iterable"
        if (preg_match('/^(\w+)\s*,\s*(\w+)\s+in\s+(.+)$/s', $expr, $m)) {
            $keyName = $m[1];
            $itemName = $m[2];
            $iterable = trim($m[3]);
        } elseif (preg_match('/^(\w+)\s+in\s+(.+)$/s', $expr, $m)) {
            $itemName = $m[1];
            $iterable = trim($m[2]);
            $keyName = null;
        } else {
            throw new \RuntimeException("Invalid {for} syntax: expected 'item in list' or 'init; condition; increment', got '{$expr}'");
        }

        $body = $this->parseChildren(['{/for}', '{empty}', '{forelse}']);
        $elseDoc = null;

        if ($this->lookingAt('{empty}') || $this->lookingAt('{forelse}')) {
            $this->consumeExact($this->lookingAt('{forelse}') ? '{forelse}' : '{empty}');
            $elseDoc = new DocumentNode([], $this->parseChildren(['{/for}']));
        }

        $this->consumeExact('{/for}');

        $attrs = ['item' => $itemName, 'iterable' => $this->parseExprValue($iterable)];
        if (isset($keyName)) {
            $attrs['key'] = $keyName;
        }

        return new ControlNode(
            [],
            'for',
            $attrs,
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
            throw new \RuntimeException("Invalid {foreach} syntax: expected 'list as value' or 'list as key => value', got '{$expr}'");
        }

        $body = $this->parseChildren(['{/foreach}', '{empty}', '{forelse}']);
        $elseDoc = null;

        if ($this->lookingAt('{empty}') || $this->lookingAt('{forelse}')) {
            $this->consumeExact($this->lookingAt('{forelse}') ? '{forelse}' : '{empty}');
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
            throw new \RuntimeException("Invalid {each} syntax: expected 'list as value' or 'list as key => value', got '{$expr}'");
        }

        $body = $this->parseChildren(['{/each}', '{empty}', '{forelse}']);
        $elseDoc = null;

        if ($this->lookingAt('{empty}') || $this->lookingAt('{forelse}')) {
            $this->consumeExact($this->lookingAt('{forelse}') ? '{forelse}' : '{empty}');
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
    /** {set name[: type] = expr} */
    private function parseSetTag(): ControlNode
    {
        $tag = $this->readTagContent();              // "set name = expr" or "set name: type = expr"
        $inner = trim(substr($tag, 3));               // strip "set"

        // Support compound assignment: +=, -=, *=, /=
        $compoundOp = null;
        $eqPos = false;
        foreach (['+=', '-=', '*=', '/='] as $op) {
            $pos = strpos($inner, $op);
            if ($pos !== false && ($pos + strlen($op) < strlen($inner))) {
                $compoundOp = $op;
                $eqPos = $pos + strlen($op) - 1; // position of =
                break;
            }
        }
        if ($eqPos === false) {
            $eqPos = strpos($inner, '=');
        }

        if ($eqPos === false) {
            throw new \RuntimeException("Invalid {set} syntax: missing '=' in '{$inner}'");
        }

        $namePart = trim(substr($inner, 0, $eqPos));
        if ($compoundOp !== null) {
            $namePart = rtrim($namePart, '+-*/'); // strip the operator
        }
        $value = trim(substr($inner, $eqPos + 1));

        // Parse optional type annotation: "name: type" or just "name"
        $varType = null;
        $colonPos = strpos($namePart, ':');
        if ($colonPos !== false) {
            $name = trim(substr($namePart, 0, $colonPos));
            $varType = trim(substr($namePart, $colonPos + 1));
        } else {
            $name = trim($namePart);
        }

        $attrs = [
            'name' => $name,
            'value' => $this->parseExprValue($value),
        ];
        if ($varType !== null) {
            $attrs['type'] = $varType;
        }
        if ($compoundOp !== null) {
            $attrs['compound'] = $compoundOp;
        }

        return new ControlNode([], 'set', $attrs);
    }

    /** {include "template" [key=val ...]}  —  supports key=value and with {k:v} syntax.
     *  Block form: {include "template" key=val} body {/include}
     *  Self-closing: {include "template" key=val /} or {include "template" key=val} */
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

            // Parse optional "with {key: value, ...}" (legacy syntax)
            if (preg_match('/^with\s+\{(.+)\}$/s', $rest, $wm)) {
                $variables = $this->parseInlineObject($wm[1]);
            } else {
                // Parse key=value pairs (preferred syntax)
                $variables = $this->parseIncludeKeyValueParams($rest);
            }
        }

        // Detect block include: {include "..." params} body {/include}
        // Body is everything between the opening } and {/include}, including
        // DiSyL tags that start with {.  Scan ahead for {/include} to decide.
        $body = null;
        if (!$this->lookingAt('/}')) {
            $savedPos = $this->pos;
            // Scan ahead for {/include} to determine if this is a block include
            $scanPos = $this->pos;
            $foundClose = false;
            while ($scanPos < $this->len) {
                if (substr($this->source, $scanPos, 10) === '{/include}') {
                    $foundClose = true;
                    break;
                }
                // Skip past nested {include} blocks to avoid false matches
                if (substr($this->source, $scanPos, 9) === '{include ') {
                    $nestedEnd = strpos($this->source, '{/include}', $scanPos + 9);
                    if ($nestedEnd === false) break;
                    $scanPos = $nestedEnd + 10;
                } else {
                    $scanPos++;
                }
            }
            if ($foundClose) {
                // Block include — parse body content up to {/include}
                $this->pos = $savedPos;
                $bodyChildren = $this->parseChildren(['{/include}']);
                $this->consumeExact('{/include}');
                if (!empty($bodyChildren)) {
                    $body = new DocumentNode([], $bodyChildren);
                }
            }
            // else: self-closing, no body — pos stays at savedPos
        }

        return new IncludeNode([], $template, $variables, $body);
    }

    /**
     * Parse key=value include parameters (e.g. shell=shell_ctx).
     * Values can be variable paths (foo.bar), quoted strings ('val'),
     * or numeric literals.
     *
     * @return array<string, AbstractNode>
     */
    private function parseIncludeKeyValueParams(string $str): array
    {
        $result = [];
        if (trim($str) === '') {
            return $result;
        }

        // Split on whitespace, respecting that the tag end } or /> is not part of params
        // Remove trailing /} or }
        $str = preg_replace('/\s*\/?\}\s*$/', '', $str);
        $str = trim($str);
        if ($str === '') {
            return $result;
        }

        // Match key=value pairs separated by whitespace
        // Handles: ident=ident, ident='quoted', ident="quoted", ident=123
        $pattern = '/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(
            "[^"]*" |
            \'[^\']*\' |
            [a-zA-Z_][a-zA-Z0-9_.]* |
            \d+\.?\d*
        )/x';

        if (preg_match_all($pattern, $str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $rawVal = $match[2];

                if (($rawVal[0] === '"' && $rawVal[-1] === '"') ||
                    ($rawVal[0] === "'" && $rawVal[-1] === "'")) {
                    // Quoted string literal
                    $val = substr($rawVal, 1, -1);
                    $result[$key] = new LiteralNode([], $val);
                } elseif (is_numeric($rawVal)) {
                    $result[$key] = new LiteralNode([], str_contains($rawVal, '.') ? (float)$rawVal : (int)$rawVal);
                } else {
                    // Variable path (identifier or dotted path)
                    $result[$key] = $this->parseVariablePath($rawVal);
                }
            }
        }

        return $result;
    }

    /**
     * Parse a dot-separated variable path into nested PropertyAccessNode / IdentifierNode.
     */
    private function parseVariablePath(string $path): AbstractNode
    {
        $parts = explode('.', $path);
        $node = new IdentifierNode([], $parts[0]);
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $node = new PropertyAccessNode([], $node, $parts[$i]);
        }
        return $node;
    }

    /**
     * Skip whitespace and newlines at current position.
     */
    private function skipWhitespaceAndNewlines(): void
    {
        while ($this->pos < $this->len &&
               ($this->source[$this->pos] === ' ' ||
                $this->source[$this->pos] === "\t" ||
                $this->source[$this->pos] === "\n" ||
                $this->source[$this->pos] === "\r")) {
            $this->pos++;
        }
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
        $name = trim($name, '"\' ');                  // strip optional surrounding quotes

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
        // ── null-coalescing: {var ?? fallback} ──
        // Transform to {var|default:fallback} before filter/ternary parsing
        // (must be before ternary check since ?? contains ?)
        if (str_contains($content, '??')) {
            $inQuote = null;
            $len = strlen($content);
            for ($i = 0; $i < $len - 1; $i++) {
                $c = $content[$i];
                if ($inQuote !== null) {
                    if ($c === '\\') { $i++; continue; }
                    if ($c === $inQuote) $inQuote = null;
                    continue;
                }
                if ($c === '"' || $c === "'") { $inQuote = $c; continue; }
                if ($c === '?' && $content[$i + 1] === '?') {
                    $left  = trim(substr($content, 0, $i));
                    $right = trim(substr($content, $i + 2));
                    // Recurse into the transformed expression (supports chained ??)
                    return $this->buildExpressionNode($left . '|default:' . $right);
                }
            }
        }

        // ── ternary? ──
        $qPos = $this->findUnquotedChar($content, '?');
        if ($qPos !== false) {
            $colonPos = $this->findTernaryColon($content, $qPos + 1);
            if ($colonPos !== false) {
                return $this->buildTernary($content, $qPos, $colonPos);
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

        // Shorthand ternary "a ?: b" → use condition as true value
        if ($trueExpr === '') {
            $trueExpr = $cond;
        }

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

        // Postfix ++ / -- : resolve before binary-op splitting so `i++` is not
        // mis-parsed as `i + '+'` (critical for C-style {for} increments used
        // by the compiled pipeline's loop guard).
        if (str_ends_with($expr, '++') || str_ends_with($expr, '--')) {
            $op = str_ends_with($expr, '++') ? 'postinc' : 'postdec';
            $base = trim(substr($expr, 0, -2));
            if (preg_match('/^[a-zA-Z_][\w.]*$/', $base)) {
                return new UnaryOpNode([], $op, $this->buildDotPath($base));
            }
        }

        if (++$this->depth > self::MAX_PARSE_DEPTH) {
            $this->depth--;
            throw new \RuntimeException(
                sprintf('DiSyL expression nesting exceeds max parse depth (%d) in %s', self::MAX_PARSE_DEPTH, $this->name)
            );
        }

        try {
            return $this->parseOrExpr($expr);
        } finally {
            $this->depth--;
        }
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
        // Try longest operators first. Match with or without surrounding spaces.
        $split = $this->findLastBinaryOp($expr, ['===', '!==', '==', '!=', '>=', '<=', '>', '<']);
        if ($split !== false) {
            return new BinaryOpNode(
                [],
                $this->parseBitwiseExpr(trim($split[1])),
                trim($split[0]),
                $this->parseBitwiseExpr(trim($split[2]))
            );
        }
        return $this->parseBitwiseExpr($expr);
    }

    private function parseBitwiseExpr(string $expr): AbstractNode
    {
        // Bitwise operators: &, ^, |, <<, >> — match from highest to lowest precedence
        // << and >> have highest precedence among bitwise
        $split = $this->findLastBinaryOp($expr, ['<<', '>>']);
        if ($split !== false && trim($split[1]) !== '') {
            return new BinaryOpNode(
                [],
                $this->parseBitwiseExpr(trim($split[1])),
                trim($split[0]),
                $this->parseAddExpr(trim($split[2]))
            );
        }
        // & then ^ then | (in order of precedence)
        $split = $this->findLastBinaryOp($expr, ['&']);
        if ($split !== false && trim($split[1]) !== '') {
            return new BinaryOpNode(
                [],
                $this->parseBitwiseExpr(trim($split[1])),
                trim($split[0]),
                $this->parseBitwiseExpr(trim($split[2]))
            );
        }
        $split = $this->findLastBinaryOp($expr, ['^']);
        if ($split !== false && trim($split[1]) !== '') {
            return new BinaryOpNode(
                [],
                $this->parseBitwiseExpr(trim($split[1])),
                trim($split[0]),
                $this->parseBitwiseExpr(trim($split[2]))
            );
        }
        // NOTE: single | is NOT a bitwise OR — it's a filter pipe handled by
        // parsePrimaryExpr() via findOuterSinglePipe().  Only || (double-pipe)
        // is a logical OR, handled by parseOrExpr().
        return $this->parseAddExpr($expr);
    }

    private function parseAddExpr(string $expr): AbstractNode
    {
        // ~ (string concat) has same precedence as +/-
        $split = $this->findLastBinaryOp($expr, ['~', '+', '-'], true);
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

        // Function call: funcname(arg1, arg2, ...)
        // Must come before dot-path check so "range(1, n)" is not treated as an identifier.
        if (preg_match('/^([a-zA-Z_]\w*)\s*\(/', $expr, $fcm)) {
            $nameLen    = strlen($fcm[1]);
            $parenStart = strpos($expr, '(', $nameLen);
            if ($parenStart !== false) {
                $parenEnd = $this->findMatchingParen($expr, $parenStart);
                if ($parenEnd === strlen($expr) - 1) {
                    $funcName = $fcm[1];
                    $argsStr  = trim(substr($expr, $parenStart + 1, $parenEnd - $parenStart - 1));
                    $argNodes = [];
                    if ($argsStr !== '') {
                        foreach ($this->parseCallArgList($argsStr) as $argExpr) {
                            $argNodes[] = $this->parseExprValue(trim($argExpr));
                        }
                    }
                    return new FunctionCallNode([], $funcName, $argNodes);
                }
            }
        }

        // Postfix ++/-- on identifier or property access
        if (str_ends_with($expr, '++') || str_ends_with($expr, '--')) {
            $op = str_ends_with($expr, '++') ? 'postinc' : 'postdec';
            $base = substr($expr, 0, -2);
            if (preg_match('/^[a-zA-Z_][\w.]*$/', $base)) {
                return new UnaryOpNode([], $op, $this->buildDotPath($base));
            }
        }

        // Dot-path variable (e.g. "user.profile.name")
        if (preg_match('/^[a-zA-Z_]\w*(?:\.[a-zA-Z_]\w*)*$/', $expr)) {
            return $this->buildDotPath($expr);
        }

        // Array literal: [element1, element2, ...]
        if ($expr[0] === '[') {
            $close = $this->findMatchingBracket($expr, 0);
            if ($close === strlen($expr) - 1) {
                $inner = trim(substr($expr, 1, -1));
                $elements = [];
                if ($inner !== '') {
                    foreach ($this->splitCommaTopLevel($inner) as $el) {
                        $elements[] = $this->parseExprValue(trim($el));
                    }
                }
                return new ArrayNode([], $elements);
            }
        }

        // Bracket notation for computed property access: expr[key]
        // Must come before bare identifier check so "items[key]" is not treated as literal.
        if (preg_match('/^(.+)\[([^\]]+)\]$/', $expr, $bm)) {
            $baseNode = $this->parsePrimaryExpr($bm[1]);
            $keyNode = $this->parseExprValue(trim($bm[2]));
            return new PropertyAccessNode([], $baseNode, $keyNode, true);
        }

        // Bracket notation for numeric-index access: expr[0]
        if (preg_match('/^(.+)\[(\d+)\]$/', $expr, $bnm)) {
            $baseNode = $this->parsePrimaryExpr($bnm[1]);
            return new PropertyAccessNode([], $baseNode, (int)$bnm[2], true);
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
     * Split comma-separated function call arguments, respecting nested
     * parentheses, brackets, and quoted strings.
     *
     * "1, 2, 3"           → ['1', '2', '3']
     * "1, range(2, 3)"    → ['1', 'range(2, 3)']
     */
    private function parseCallArgList(string $str): array
    {
        $parts     = [];
        $cur       = '';
        $inSingle  = false;
        $inDouble  = false;
        $depth     = 0;

        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '\\' && ($inSingle || $inDouble) && $i + 1 < $len) {
                $cur .= $ch . $str[++$i];
                continue;
            }
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; $cur .= $ch; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; $cur .= $ch; continue; }
            if ($inSingle || $inDouble)    { $cur .= $ch; continue; }
            if ($ch === '(' || $ch === '[') { $depth++; $cur .= $ch; continue; }
            if ($ch === ')' || $ch === ']') { $depth--; $cur .= $ch; continue; }
            if ($ch === ',' && $depth === 0) {
                $parts[] = trim($cur);
                $cur     = '';
                continue;
            }
            $cur .= $ch;
        }
        if (($trimmed = trim($cur)) !== '') {
            $parts[] = $trimmed;
        }
        return $parts;
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
                // Parse value as DiSyL expression (supports literals, variables, filters, etc.)
                $result[$key] = $this->parseExprValue($val);
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
                $this->pos = min($this->pos + 2, $this->len);
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
                    // When a longer operator (e.g. !==) matched earlier, skip
                    // the shorter operator (e.g. ==) embedded within its span.
                    if ($best !== false && $i < $bestPos + strlen($best[0])) {
                        break; // inside current best match — skip
                    }
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
     * Find the unquoted colon that separates true/false branches of a ternary.
     * Skips colons that are filter argument separators (preceded by a word char)
     * to avoid matching filter specs like |default:'val' instead of the ternary :.
     */
    private function findTernaryColon(string $str, int $start = 0): int|false
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
                if ($ch === ':' && $parenDepth === 0) {
                    // Skip : that is a filter arg separator (preceded by word char)
                    if ($i > 0 && preg_match('/[a-zA-Z0-9_]/', $str[$i - 1])) {
                        continue;
                    }
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
     * Find the position of the matching closing bracket.
     */
    private function findMatchingBracket(string $str, int $openPos): int
    {
        $depth = 0;
        for ($i = $openPos, $len = strlen($str); $i < $len; $i++) {
            if ($str[$i] === '[') {
                $depth++;
            } elseif ($str[$i] === ']') {
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
