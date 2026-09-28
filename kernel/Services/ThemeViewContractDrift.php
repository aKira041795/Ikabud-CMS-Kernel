<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * ThemeViewContractDrift — pure comparison of a theme's declared entity-view
 * fields against the field lists the module registration publishes.
 *
 * The module's registered contract is the source of truth. A theme declares in
 * `entity-view-map.json` which fields its views expect; this class reports where
 * that declaration and the registered contract disagree.
 *
 * Two reasons, and the distinction is deliberate:
 *
 *   - `contract_not_registered` — the declaration names an entity+view for which
 *     no contract exists at all. The caller treats this as a warning (fail-open),
 *     because a CLI bootstrap registers no module capabilities and a theme may be
 *     validated for a module whose views are not loaded.
 *   - `field_not_in_contract` — a contract exists and the declared field is not
 *     in it. The caller treats this as an error (fail-closed).
 *
 * `compare()` is a pure function of its arguments: no `app()`, no container, no
 * file I/O and no globals, so it can be tested without the application.
 *
 * @package Ikabud\Kernel\Services
 */
final class ThemeViewContractDrift
{
    public const REASON_CONTRACT_NOT_REGISTERED = 'contract_not_registered';

    public const REASON_FIELD_NOT_IN_CONTRACT = 'field_not_in_contract';

    /**
     * Emit a finding for every declared field a contract does not carry, and for
     * every declared entity+view that has no registered contract at all.
     *
     * A malformed declaration (`fields` not an array, or containing non-strings)
     * never throws; the offending entries are simply not compared.
     *
     * @param array<string,array<string,mixed>> $declaredViews entity => view => {fields: list<string>}
     * @param array<string,list<string>>        $registeredFields "entity.view" => list<string>
     * @return list<array{entity:string,view:string,field:string,reason:string}>
     */
    public static function compare(array $declaredViews, array $registeredFields): array
    {
        $findings = [];

        foreach ($declaredViews as $entity => $views) {
            $entity = (string) $entity;
            if (!is_array($views)) {
                continue;
            }

            foreach ($views as $view => $declaration) {
                $view = (string) $view;
                $key = $entity . '.' . $view;

                if (!array_key_exists($key, $registeredFields)) {
                    $findings[] = [
                        'entity' => $entity,
                        'view' => $view,
                        'field' => '*',
                        'reason' => self::REASON_CONTRACT_NOT_REGISTERED,
                    ];
                    continue;
                }

                if (!is_array($declaration)) {
                    continue;
                }

                $declaredFields = $declaration['fields'] ?? null;
                if (!is_array($declaredFields)) {
                    continue;
                }

                $contractFields = $registeredFields[$key];
                if (!is_array($contractFields)) {
                    $contractFields = [];
                }
                // A wildcard contract admits every declared field.
                if (in_array('*', $contractFields, true)) {
                    continue;
                }

                $known = [];
                foreach ($contractFields as $contractField) {
                    if (is_string($contractField)) {
                        $known[$contractField] = true;
                    }
                }

                foreach ($declaredFields as $field) {
                    if (!is_string($field)) {
                        continue;
                    }
                    if (!isset($known[$field])) {
                        $findings[] = [
                            'entity' => $entity,
                            'view' => $view,
                            'field' => $field,
                            'reason' => self::REASON_FIELD_NOT_IN_CONTRACT,
                        ];
                    }
                }
            }
        }

        return $findings;
    }
}
