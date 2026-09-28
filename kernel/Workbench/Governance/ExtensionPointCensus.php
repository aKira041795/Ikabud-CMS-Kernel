<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Governance;

/**
 * Extension point consumption census — a point is created by its first consumer.
 *
 * Declared extension points are published contract surface: a third party may
 * build against them. A point no manifest consumes has no evidence it works,
 * no test that would notice it breaking, and no way to distinguish "supported
 * extension point" from "a name in a manifest". This census makes that surface
 * visible without changing it.
 *
 * The comparison is a pure function of two arrays: it reads no manifests, opens
 * no database, writes nothing and mutates nothing. Reading the real sources
 * belongs to the command that feeds it.
 *
 * TWO invariants, and the distinction matters:
 *
 *   - an ORPHAN contribution — a module contributing to a point no manifest
 *     declares — is a defect in code and is counted as an orphan. It means a
 *     contribution that can never be invoked, or a typo in a point name.
 *   - an UNCONSUMED declared point is REPORTED, never fatal. A census that
 *     failed on it would be permanently red, and a guard that is always red is
 *     a guard nobody reads.
 *
 * An orphan consumes nothing, because the point it names does not exist, so it
 * never contributes to the consumed count.
 */
final class ExtensionPointCensus
{
    /**
     * Classify declared points against the contributions that consume them.
     *
     * Each entry is `['point' => string, 'module' => string]`. For declared
     * points the module is the declarer; for contributions it is the
     * contributor. Blank points are ignored. A declared point is consumed when
     * at least one contribution names it; otherwise it is unconsumed. A
     * contribution naming a point no declaration carries is an orphan.
     *
     * The `unconsumed` and `orphans` lists are ordered by point name (then by
     * module) so two runs over the same inputs are directly comparable.
     *
     * @param list<array<string,mixed>> $declaredPoints
     * @param list<array<string,mixed>> $contributions
     * @return array{
     *     counts: array{declared: int, consumed: int, unconsumed: int, orphan: int},
     *     unconsumed: list<array{point: string, declared_by: string}>,
     *     orphans: list<array{point: string, contributed_by: string}>
     * }
     */
    public static function report(array $declaredPoints, array $contributions): array
    {
        // First declaration wins for a repeated point, so the audit identity
        // `declared = consumed + unconsumed` holds even for a malformed manifest
        // that declares the same point twice.
        $declaredBy = [];
        foreach ($declaredPoints as $declared) {
            $point = trim((string) ($declared['point'] ?? ''));
            if ($point === '' || isset($declaredBy[$point])) {
                continue;
            }
            $declaredBy[$point] = trim((string) ($declared['module'] ?? ''));
        }

        $consumers = [];
        $orphans = [];
        foreach ($contributions as $contribution) {
            $point = trim((string) ($contribution['point'] ?? ''));
            if ($point === '') {
                continue;
            }
            if (!isset($declaredBy[$point])) {
                $orphans[] = [
                    'point' => $point,
                    'contributed_by' => trim((string) ($contribution['module'] ?? '')),
                ];
                continue;
            }
            $consumers[$point] = true;
        }

        $consumed = 0;
        $unconsumed = [];
        foreach ($declaredBy as $point => $declaredByModule) {
            if (isset($consumers[$point])) {
                ++$consumed;
                continue;
            }
            $unconsumed[] = ['point' => $point, 'declared_by' => $declaredByModule];
        }

        usort(
            $unconsumed,
            static fn (array $a, array $b): int => strcmp($a['point'], $b['point'])
        );
        usort(
            $orphans,
            static fn (array $a, array $b): int => strcmp($a['point'], $b['point'])
                ?: strcmp($a['contributed_by'], $b['contributed_by'])
        );

        return [
            'counts' => [
                'declared' => count($declaredBy),
                'consumed' => $consumed,
                'unconsumed' => count($unconsumed),
                'orphan' => count($orphans),
            ],
            'unconsumed' => $unconsumed,
            'orphans' => $orphans,
        ];
    }
}
