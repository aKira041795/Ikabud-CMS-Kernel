<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Immutable value object representing a fully resolved entity view context.
 *
 * Once constructed, all properties are readonly and the object cannot be
 * mutated. Factory methods provide domain-specific merge rules rather than
 * generic array_replace_recursive behaviour.
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class ResolvedEntityContext
{
    /**
     * @param string               $entityType    Canonical entity type
     * @param string               $view          View name (compact, detailed, table, etc.)
     * @param array<int, string>|string $fields   Visible fields ('*' = all, or field-name list)
     * @param array<int, string>   $actions       Allowed action names
     * @param int                  $limit         Default row limit
     * @param array{field: string, direction: string} $sort Default sort
     * @param string               $emptyState    Message when no data
     * @param string               $errorState    Message on fetch failure
     * @param bool                 $exportable    Can this view be exported?
     * @param string|null          $capability    Capability required to view
     * @param string               $provider      Provider ID that registered this contract
     * @param int                  $timeoutMs     Per-source capability timeout
     * @param array<string, string> $sortableFields  field_name => sort_key
     * @param array<string, array<string, mixed>> $fieldContracts Per-field metadata
     * @param array<string, string> $renderers    field_name => renderer spec
     * @param array<string, string> $actionUrls   action_name => URL template
     * @param array<string, string> $actionMethods action_name => HTTP method
     * @param array<string, string> $actionConfirm action_name => confirmation message
     * @param array<string, string> $actionShowIf action_name => condition expression
     * @param array<string, string> $actionLabels action_name => display label
     * @param string|null          $keyField      Primary key field name
     * @param array|null           $provenance    Provenance metadata (P1.4)
     */
    public function __construct(
        public readonly string $entityType,
        public readonly string $view,
        public readonly array|string $fields,
        public readonly array $actions,
        public readonly int $limit,
        public readonly array $sort,
        public readonly string $emptyState,
        public readonly string $errorState,
        public readonly bool $exportable,
        public readonly ?string $capability,
        public readonly string $provider,
        public readonly int $timeoutMs,
        public readonly array $sortableFields,
        public readonly array $fieldContracts,
        public readonly array $renderers,
        public readonly array $actionUrls,
        public readonly array $actionMethods,
        public readonly array $actionConfirm,
        public readonly array $actionShowIf,
        public readonly array $actionLabels,
        public readonly ?string $keyField,
        public readonly ?array $provenance = null,
    ) {}

    /**
     * Factory: build from a contract array (as returned by registerView / builtinDefaults).
     *
     * Normalises keys (snake_case→camelCase mapping) and applies defaults.
     */
    public static function fromContract(string $entityType, string $view, array $contract, ?array $provenance = null): self
    {
        return new self(
            entityType:  $entityType,
            view:        $view,
            fields:      $contract['fields'] ?? '*',
            actions:     $contract['actions'] ?? [],
            limit:       (int)($contract['limit'] ?? 25),
            sort:        $contract['sort'] ?? ['field' => 'created_at', 'direction' => 'desc'],
            emptyState:  (string)($contract['empty_state'] ?? 'No records found.'),
            errorState:  (string)($contract['error_state'] ?? 'Unable to load data.'),
            exportable:  (bool)($contract['exportable'] ?? false),
            capability:  $contract['capability'] ?? null,
            provider:    (string)($contract['provider'] ?? 'kernel'),
            timeoutMs:   (int)($contract['timeout_ms'] ?? 10000),
            sortableFields: $contract['sortable_fields'] ?? [],
            fieldContracts: $contract['field_contracts'] ?? [],
            renderers:      $contract['renderers'] ?? [],
            actionUrls:     $contract['action_urls'] ?? [],
            actionMethods:  $contract['action_methods'] ?? [],
            actionConfirm:  $contract['action_confirm'] ?? [],
            actionShowIf:   $contract['action_show_if'] ?? [],
            actionLabels:   $contract['action_labels'] ?? [],
            keyField:       $contract['key_field'] ?? null,
            provenance:     $provenance,
        );
    }

    /**
     * Merge two resolved contexts using domain-specific rules (P1.5).
     *
     * - fields:   array union; '*' absorbs all
     * - actions:  array union
     * - sort:     $override wins entirely
     * - renderers, field_contracts, action_*:  array merge ($override wins)
     * - scalars:  $override wins if non-null
     *
     * $this acts as the base (lower priority), $override takes precedence.
     */
    public function merge(self $override): self
    {
        // Fields: union if both are arrays; '*' absorbs
        $fields = $this->fields;
        if ($override->fields === '*') {
            $fields = '*';
        } elseif (is_array($override->fields) && is_array($fields)) {
            $fields = array_values(array_unique(array_merge($fields, $override->fields)));
        } elseif (is_array($override->fields)) {
            $fields = $override->fields;
        }

        // Actions: union
        $actions = array_values(array_unique(array_merge($this->actions, $override->actions)));

        // Sort: override wins
        $sort = $override->sort;

        // Renderers, field_contracts, action maps: override wins per key
        $renderers      = array_merge($this->renderers, $override->renderers);
        $fieldContracts = array_merge($this->fieldContracts, $override->fieldContracts);
        $actionUrls     = array_merge($this->actionUrls, $override->actionUrls);
        $actionMethods  = array_merge($this->actionMethods, $override->actionMethods);
        $actionConfirm  = array_merge($this->actionConfirm, $override->actionConfirm);
        $actionShowIf   = array_merge($this->actionShowIf, $override->actionShowIf);
        $actionLabels   = array_merge($this->actionLabels, $override->actionLabels);
        $sortableFields = array_merge($this->sortableFields, $override->sortableFields);

        // Scalars: override wins
        $limit      = $override->limit;
        $emptyState = $override->emptyState;
        $errorState = $override->errorState;
        $exportable = $override->exportable;
        $capability = $override->capability ?? $this->capability;
        $timeoutMs  = $override->timeoutMs;
        $keyField   = $override->keyField ?? $this->keyField;

        // Provenance: concatenate
        $provenance = $this->collectProvenance($override);

        return new self(
            entityType:     $this->entityType,
            view:           $this->view,
            fields:         $fields,
            actions:        $actions,
            limit:          $limit,
            sort:           $sort,
            emptyState:     $emptyState,
            errorState:     $errorState,
            exportable:     $exportable,
            capability:     $capability,
            provider:       $override->provider,
            timeoutMs:      $timeoutMs,
            sortableFields: $sortableFields,
            fieldContracts: $fieldContracts,
            renderers:      $renderers,
            actionUrls:     $actionUrls,
            actionMethods:  $actionMethods,
            actionConfirm:  $actionConfirm,
            actionShowIf:   $actionShowIf,
            actionLabels:   $actionLabels,
            keyField:       $keyField,
            provenance:     $provenance,
        );
    }

    /**
     * Convert back to a plain array for backward compatibility with
     * existing callers that expect array access.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entity_type'     => $this->entityType,
            'view'            => $this->view,
            'fields'          => $this->fields,
            'actions'         => $this->actions,
            'limit'           => $this->limit,
            'sort'            => $this->sort,
            'empty_state'     => $this->emptyState,
            'error_state'     => $this->errorState,
            'exportable'      => $this->exportable,
            'capability'      => $this->capability,
            'provider'        => $this->provider,
            'timeout_ms'      => $this->timeoutMs,
            'sortable_fields' => $this->sortableFields,
            'field_contracts' => $this->fieldContracts,
            'renderers'       => $this->renderers,
            'action_urls'     => $this->actionUrls,
            'action_methods'  => $this->actionMethods,
            'action_confirm'  => $this->actionConfirm,
            'action_show_if'  => $this->actionShowIf,
            'action_labels'   => $this->actionLabels,
            'key_field'       => $this->keyField,
            '_provenance'     => $this->provenance,
        ];
    }

    /**
     * Collect provenance from base and override.
     *
     * @return array<int, array{provider: string, timestamp: string}>
     */
    private function collectProvenance(self $override): array
    {
        $entries = [];

        // Add base provenance
        if ($this->provenance !== null) {
            foreach ($this->provenance as $entry) {
                if (isset($entry['provider'])) {
                    $entries[] = $entry;
                }
            }
        }

        // Add override provenance (newer)
        if ($override->provenance !== null) {
            foreach ($override->provenance as $entry) {
                if (isset($entry['provider'])) {
                    $entries[] = $entry;
                }
            }
        }

        // Deduplicate by provider (last wins)
        $seen = [];
        $deduped = [];
        foreach ($entries as $entry) {
            $provider = $entry['provider'];
            if (isset($seen[$provider])) {
                // Replaces previous entry for this provider
                $deduped[$seen[$provider]] = $entry;
            } else {
                $seen[$provider] = count($deduped);
                $deduped[] = $entry;
            }
        }

        return array_values($deduped);
    }
}
