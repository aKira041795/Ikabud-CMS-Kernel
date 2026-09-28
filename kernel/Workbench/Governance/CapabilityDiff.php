<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Governance;

/**
 * Report-only capability diff (P4.3, the diff half of C6).
 *
 * Answers the question an operator asks BEFORE admitting an extension: what
 * authority would this install grant that the tenant does not already have, and
 * which of it writes?
 *
 * It grants nothing. There is no admission, approval, signature or trust logic
 * here: it is a function of two declaration maps and has no database, session,
 * filesystem or clock dependency.
 */
final class CapabilityDiff
{
    /**
     * Pure diff over two declaration maps.
     *
     * A declaration is the capability array as read from a module.json
     * `capabilities.exposes` entry, keyed by capability id.
     *
     * @param array<string, array<string, mixed>> $before active install
     * @param array<string, array<string, mixed>> $after candidate set
     * @return array{added: list<string>, removed: list<string>, unchanged: list<string>, widening: list<string>}
     */
    public static function diff(array $before, array $after): array
    {
        $added = array_values(array_diff(array_keys($after), array_keys($before)));
        $removed = array_values(array_diff(array_keys($before), array_keys($after)));
        $unchanged = array_values(array_intersect(array_keys($after), array_keys($before)));

        sort($added);
        sort($removed);
        sort($unchanged);

        $widening = [];
        foreach ($added as $id) {
            $declaration = $after[$id] ?? [];
            if (is_array($declaration) && self::writes($declaration)) {
                $widening[] = $id;
            }
        }
        sort($widening);

        return [
            'added' => $added,
            'removed' => $removed,
            'unchanged' => $unchanged,
            'widening' => $widening,
        ];
    }

    /**
     * Build the declaration map the diff consumes from already-discovered
     * module manifests.
     *
     * Discovery and manifest reading stay the module manager's job; this only
     * lifts the parsed `capabilities.exposes` entries into an id-keyed map.
     *
     * @param array<string, array<string, mixed>> $manifests
     * @return array<string, array<string, mixed>>
     */
    public static function declarationsFromManifests(array $manifests): array
    {
        $declarations = [];
        foreach ($manifests as $manifest) {
            if (!is_array($manifest)) {
                continue;
            }
            $exposes = $manifest['capabilities']['exposes'] ?? [];
            if (!is_array($exposes)) {
                continue;
            }
            foreach ($exposes as $expose) {
                if (is_string($expose)) {
                    $id = trim($expose);
                    $declaration = ['id' => $id];
                } elseif (is_array($expose)) {
                    $id = trim((string) ($expose['id'] ?? ''));
                    $declaration = $expose;
                } else {
                    continue;
                }
                if ($id !== '') {
                    $declarations[$id] = $declaration;
                }
            }
        }
        return $declarations;
    }

    /**
     * A capability is a write when it carries `requires_protocol: v2` or a
     * non-empty `effects.invalidates`. An added read is never widening: a flag
     * that fires on reads is noise, and a noisy flag gets ignored.
     *
     * @param array<string, mixed> $declaration
     */
    private static function writes(array $declaration): bool
    {
        if (($declaration['requires_protocol'] ?? null) === 'v2') {
            return true;
        }
        $invalidates = $declaration['effects']['invalidates'] ?? null;
        return is_array($invalidates) && $invalidates !== [];
    }
}
