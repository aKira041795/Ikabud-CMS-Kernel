<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Development;

/**
 * AssertionChange — telling DELETING a check apart from MOVING it.
 *
 * EXTRACTED from tools/harpp2/assertions.php when that harness was retired on 2026-09-19. It is the
 * single best thing the old machinery produced, and it is here rather than left behind because the new
 * harness needs it: a lane's probe can pass while the lane has quietly deleted or loosened the very
 * assertions that make the probe mean something.
 *
 * WHY IT EXISTS AT ALL (lesson L8, 2026-09-17): the original guard diffed LINES, so reindenting an
 * assertion, reordering them, or splitting one test in two looked exactly like deleting a check. It
 * refused a restructure that took a spec from 28 to 52 assertions with every threshold intact. A guard
 * that blocks the work which strengthens the suite is a defect: it teaches a reader to distrust it.
 *
 * What is compared, and why each part earns its place:
 *   SET    every assertion EXPRESSION present before must still be present, with whitespace normalised
 *          and quoted strings masked -- so reindenting or rewording a message is not a removal.
 *   BOUNDS where the same assertion appears in both, a numeric bound may not become weaker:
 *          toBeGreaterThan(8) -> toBeGreaterThan(2) is a weakening and is still caught.
 *   COUNT  reported, not enforced here: growth is fine, and shrinkage is caught by SET anyway.
 *
 * Deliberate trade-off, kept from the original: masking quoted strings means swapping a selector
 * (`getByText("Save")` -> `getByText("Delete")`) is NOT flagged. That is a behaviour change rather than
 * a weakening, and treating selector edits as violations would re-introduce the false positives that
 * made the first version unusable.
 */
final class AssertionChange
{
    /**
     * What a line must contain to count as an assertion: the idioms of jest/vitest/phpunit.
     *
     * NOT SUFFICIENT ON ITS OWN, and this was measured rather than assumed. Falsified against a real
     * test file in this repository on 2026-09-19, the default markers found NOTHING: the Star Swarm
     * tests assert with `$check(...)`, which contains none of these words, so deleting an assertion
     * from one of them was reported as a clean change. Fixtures passed; the repository's own idiom did
     * not. A caller therefore declares its markers -- see AssertionChange::REPOSITORY_MARKERS -- and the
     * vocabulary comes from the artifacts rather than from a guess about how tests are written.
     */
    public const DEFAULT_MARKERS = '\\b(?:assert|expect|fail|throw)[A-Za-z]*\\s*\\(|\\bthrow\\s+new\\b';

    /**
     * The idioms this repository's tests actually use: the word markers plus the call forms, including
     * its own `$check(...)`.
     *
     * The word boundaries are load-bearing and were nearly lost twice. Dropping the trailing `\b` made
     * the marker match inside ordinary words -- measured 2026-09-19, the classifier reported a COMMENT
     * saying "RETIRED ASSERTIONS" and the line `$fail = 0;` as assertions while returning null for the
     * real `$check(...)` on the next screenful. And a leading `\b` cannot match before `$`, because `$`
     * is not a word character, so `\$check\(` written inside a `\b...\b` wrapper is unreachable.
     *
     * So a marker must be a CALL: the keyword followed by an optional suffix and an opening parenthesis,
     * or an explicit `throw new`. A bare word is not enough, because `\bfail\b` matches inside the
     * variable `$fail` -- which every test in this repository uses as a control variable, so counting it
     * would report removals of assertions that never existed.
     */
    public const REPOSITORY_MARKERS = '\\b(?:assert|expect|fail|throw)[A-Za-z]*\\s*\\(|\\bthrow\\s+new\\b|\\$check\\(|->check\\(';
    /**
     * Compare two versions of a source file.
     *
     * @return array{ok:bool, removed:list<string>, loosened:list<string>, cosmetic:list<string>, counts:array{old:int,new:int}}
     */
    public static function analyse(string $oldSource, string $newSource, string $markers = self::DEFAULT_MARKERS): array
    {
        $old = self::set($oldSource, $markers);
        $new = self::set($newSource, $markers);

        $newByShape = [];
        foreach ($new as $assertion) {
            $newByShape[self::shape($assertion)][] = $assertion;
        }

        $removed = [];
        $loosened = [];
        $cosmetic = [];
        $consumed = [];

        foreach ($old as $assertion) {
            $shape = self::shape($assertion);
            $index = $consumed[$shape] ?? 0;

            if (!isset($newByShape[$shape][$index])) {
                $removed[] = $assertion;
                continue;
            }

            $candidate = $newByShape[$shape][$index];
            $consumed[$shape] = $index + 1;

            $oldBounds = self::bounds($assertion);
            $newBounds = self::bounds($candidate);
            foreach ($oldBounds as $position => [$operator, $value]) {
                if (isset($newBounds[$position]) && self::loosened($operator, $value, $newBounds[$position][1])) {
                    $loosened[] = "{$shape} — {$operator}({$value}) became {$operator}({$newBounds[$position][1]})";
                }
            }

            if ($candidate !== $assertion && $oldBounds === $newBounds) {
                $cosmetic[] = $assertion;
            }
        }

        return [
            'ok' => $removed === [] && $loosened === [],
            'removed' => $removed,
            'loosened' => $loosened,
            'cosmetic' => $cosmetic,
            'counts' => ['old' => count($old), 'new' => count($new)],
        ];
    }

    /** Canonical form of an assertion line: whitespace normalised, quoted strings masked. */
    public static function canonical(string $line, string $markers = self::DEFAULT_MARKERS): ?string
    {
        if (preg_match('/' . $markers . '/i', $line) !== 1) {
            return null;
        }
        $masked = preg_replace(['/"(?:[^"\\\\]|\\\\.)*"/', "/'(?:[^'\\\\]|\\\\.)*'/"], ['"S"', '"S"'], $line);
        $canonical = preg_replace('/\s+/', ' ', trim((string) $masked));

        return ($canonical === null || $canonical === '') ? null : $canonical;
    }

    /** @return list<string> canonical assertions, in file order */
    private static function set(string $source, string $markers): array
    {
        $out = [];
        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            $canonical = self::canonical((string) $line, $markers);
            if ($canonical !== null) {
                $out[] = $canonical;
            }
        }

        return $out;
    }

    /** @return list<array{0:string,1:float}> operator and bound for each numeric comparison on the line */
    private static function bounds(string $canonical): array
    {
        preg_match_all(
            '/to(?:Be)?(GreaterThanOrEqual|LessThanOrEqual|GreaterThan|LessThan)\(\s*([0-9]+(?:\.[0-9]+)?)\s*\)/',
            $canonical,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(static fn (array $m): array => [$m[1], (float) $m[2]], $matches);
    }

    /** The same assertion with its numbers masked, so the same check at a different bound groups together. */
    private static function shape(string $canonical): string
    {
        return preg_replace(
            '/to(?:Be)?(GreaterThanOrEqual|LessThanOrEqual|GreaterThan|LessThan)\(\s*[0-9]+(?:\.[0-9]+)?\s*\)/',
            'to$1(#)',
            $canonical,
        ) ?? $canonical;
    }

    /** Is the new bound weaker than the old one for this operator? */
    private static function loosened(string $operator, float $old, float $new): bool
    {
        return match ($operator) {
            'GreaterThan', 'GreaterThanOrEqual' => $new < $old,
            'LessThan', 'LessThanOrEqual' => $new > $old,
            default => false,
        };
    }
}
