<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL;

/**
 * Normalizes DiSyL expression source before it is parsed.
 *
 * HTML entities in expressions
 * ----------------------------
 * Template authors write `&gt;` out of habit because the surrounding markup is
 * HTML, and until 2026-11-16 nothing objected: the parser read `a &gt; 1` as a
 * BITWISE `&` between `a` and the string `gt; 1`, compiled it, and the compiled
 * artifact then threw `Unsupported operand types: int & string` at RUN time.
 *
 * That failure was not local. The compiled pipeline catches a render error and
 * silently re-renders the whole page on the legacy interpreted pipeline
 * (TemplateEngine::render), so one `&gt;` in one `{if}` in
 * modules/daily-ledger/cashier/ledger.disyl turned the ledger into interpreted
 * rendering forever — where a separate boolean-precedence defect then hid the
 * Close PM button, which is how the client lost the ability to close a reopened
 * day. Two bugs, one inconspicuous `&gt;`.
 *
 * So entities are decoded here, once, for BOTH pipelines. Outside quotes only:
 * a literal `'A &amp; B'` keeps its entity text (quoted content is data, and the
 * renderer escapes it again), while an operator written as `&gt;`, `&lt;` or
 * `&amp;&amp;` becomes the operator the author meant.
 */
final class ExpressionSource
{
    /**
     * Named/numeric entities that can stand in for a DiSyL operator, longest
     * first so `&#39;` is not half-matched.
     *
     * @var array<string, string>
     */
    private const ENTITIES = [
        '&quot;' => '"',
        '&apos;' => "'",
        '&#39;'  => "'",
        '&#34;'  => '"',
        '&amp;'  => '&',
        '&lt;'   => '<',
        '&gt;'   => '>',
    ];

    /**
     * Decode operator entities that appear OUTSIDE quoted strings.
     *
     * Returns the input unchanged when it contains no `&` at all, which is the
     * overwhelmingly common case — this runs on every condition and expression.
     */
    public static function decodeEntities(string $expr): string
    {
        if (strpos($expr, '&') === false) {
            return $expr;
        }

        $out = '';
        $quote = null;
        $length = strlen($expr);

        for ($i = 0; $i < $length; $i++) {
            $ch = $expr[$i];

            if ($quote !== null) {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $length) {
                    $out .= $expr[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $out .= $ch;
                continue;
            }
            if ($ch === '&') {
                $matched = false;
                foreach (self::ENTITIES as $entity => $replacement) {
                    $entityLen = strlen($entity);
                    if (substr($expr, $i, $entityLen) === $entity) {
                        $out .= $replacement;
                        $i += $entityLen - 1;
                        $matched = true;
                        break;
                    }
                }
                if ($matched) {
                    continue;
                }
            }
            $out .= $ch;
        }

        return $out;
    }
}
